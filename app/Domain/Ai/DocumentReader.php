<?php

namespace App\Domain\Ai;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\Grounding;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\Opportunities;
use App\Domain\Verification\Verifier;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\Opportunity;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reads a document with Claude and records what it says: kind, summary, job details, travel,
 * accommodation, PO lines, and work it offers (an availability check, a hold, an offer, a booking or a
 * cancellation). Every value must come with a verbatim quote, and each quote is checked against the
 * document's own text before anything is marked verified. Nothing is applied to jobs, invoices or the
 * ledger automatically: work it offers is only proposed (Opportunities).
 */
final class DocumentReader
{
    public const FIELDS = [
        'venue' => 'Venue', 'address' => 'Address', 'postcode' => 'Postcode', 'start_date' => 'Start date', 'end_date' => 'End date',
        'call_time' => 'Call time', 'load_in' => 'Load-in', 'start_time' => 'Start time', 'finish_time' => 'Finish time', 'load_out' => 'Load-out',
        'travel' => 'Travel', 'accommodation' => 'Accommodation', 'contact' => 'Contact', 'parking' => 'Parking', 'dress_code' => 'Dress code',
        'catering' => 'Catering', 'po_number' => 'PO number', 'rate' => 'Rate or fee', 'payment_terms' => 'Payment terms',
        'invoice_to' => 'Send invoice to', 'deadline' => 'Deadline', 'other' => 'Other',
    ];

    /** Where work being offered stands, as the document puts it (Opportunity::STAGES, plus "went elsewhere"). */
    public const OPPORTUNITY_STATUSES = ['enquiry', 'held', 'offered', 'confirmed', 'lost'];

    public function __construct(private AiClient $ai) {}

    public function read(Document $document): Document
    {
        $content = self::content($document);
        $jobs = $this->candidateJobs($document);

        $result = $this->ai->send([
            'maxTokens' => 16000,
            'system' => $this->system(),
            'messages' => [['role' => 'user', 'content' => [
                ...$content,
                ['type' => 'text', 'text' => $this->instructions($document, $jobs)],
            ]]],
            'outputConfig' => ['effort' => AiConfig::effort(), 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ]);

        $data = json_decode($result->text(), true);
        if (! is_array($data)) {
            throw new RuntimeException('The AI reply could not be read.');
        }

        return $this->save($document, $data, $jobs, $result->model);
    }

    /** Validate everything against the document and the app's own records, then store it. */
    public function save(Document $document, array $data, array $jobs, string $model): Document
    {
        $text = $document->text;
        $status = $document->text_status;

        $kind = array_key_exists($data['kind'] ?? '', Document::KINDS) ? $data['kind'] : 'other';
        $jobIds = array_column($jobs, 'id');
        $jobId = in_array($data['job_link']['job_id'] ?? null, $jobIds, true) ? $data['job_link']['job_id'] : null;

        $facts = [];
        foreach ($data['facts'] ?? [] as $fact) {
            if (! isset(self::FIELDS[$fact['field'] ?? '']) || trim((string) ($fact['value'] ?? '')) === '' || trim((string) ($fact['quote'] ?? '')) === '') {
                continue;
            }
            $check = Grounding::check($text, $status, $fact['quote']);
            $facts[] = [
                'field' => $fact['field'],
                'label' => mb_substr($fact['label'] ?: self::FIELDS[$fact['field']], 0, 120),
                'value' => mb_substr((string) $fact['value'], 0, 2000),
                'on_date' => self::date($fact['date'] ?? null),
                'at_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($fact['time'] ?? '')) ? $fact['time'] : null,
                'quote' => mb_substr($fact['quote'], 0, 2000),
                'page' => is_int($fact['page'] ?? null) ? $fact['page'] : null,
                'verified' => $check['verified'],
                'verification_note' => $check['note'],
            ];
        }

        $po = null;
        if (is_array($data['po'] ?? null)) {
            $lines = [];
            foreach ($data['po']['lines'] ?? [] as $line) {
                if (! is_int($line['amount_minor'] ?? null) || trim((string) ($line['description'] ?? '')) === '') {
                    continue;
                }
                $check = Grounding::check($text, $status, (string) ($line['quote'] ?? ''), $line['amount_minor']);
                $lines[] = [
                    'description' => mb_substr($line['description'], 0, 500),
                    'quantity' => (string) (InvoiceService::quantity(is_numeric($line['quantity'] ?? null) ? (string) $line['quantity'] : '1') ?? '1.00'),
                    'unit_price_minor' => is_int($line['unit_price_minor'] ?? null) ? $line['unit_price_minor'] : $line['amount_minor'],
                    'amount_minor' => $line['amount_minor'],
                    'quote' => (string) ($line['quote'] ?? ''),
                    'verified' => $check['verified'],
                    'note' => $check['note'],
                ];
            }
            $stated = $data['po']['total']['amount_minor'] ?? null;
            $totalCheck = is_int($stated) ? Grounding::check($text, $status, (string) ($data['po']['total']['quote'] ?? ''), $stated) : ['verified' => false, 'note' => 'No total stated.'];
            $sum = array_sum(array_column($lines, 'amount_minor'));
            $po = [
                'po_number' => $data['po']['po_number'] ?? null,
                'bill_to_name' => $data['po']['bill_to_name'] ?? null,
                'bill_to_address' => $data['po']['bill_to_address'] ?? null,
                'bill_to_email' => $data['po']['bill_to_email'] ?? null,
                'currency' => preg_match('/^[A-Z]{3}$/', (string) ($data['po']['currency'] ?? '')) ? $data['po']['currency'] : 'GBP',
                'payment_terms_days' => is_int($data['po']['payment_terms_days'] ?? null) ? $data['po']['payment_terms_days'] : null,
                'lines' => $lines,
                'stated_total_minor' => is_int($stated) ? $stated : null,
                'total_verified' => $totalCheck['verified'],
                'lines_total_minor' => $sum,
                'lines_match_total' => is_int($stated) ? $sum === $stated : null,
            ];
        }

        // The AI's job link is double-checked against the document before it's used: the job's PO number, or one
        // of its dates together with its client, venue or title. Otherwise it's kept only as a hint for you.
        $linkHint = null;
        if ($jobId && ($document->job_linked_by === 'you' || in_array($jobId, $document->job_rejected ?? [], true))) {
            $jobId = null; // you decided this document's job (or that it isn't this one): a re-read never changes that
        }
        if ($jobId && ! $document->work_job_id) {
            $job = WorkJob::query()->with('client')->find($jobId);
            $evidence = $status === 'extracted' && $job ? Verifier::jobEvidence($text, $job, $po['po_number'] ?? null) : ['ok' => false, 'why' => []];
            if (! $evidence['ok']) {
                $linkHint = ['job_id' => $jobId, 'reason' => $data['job_link']['reason'] ?? null,
                    'note' => $status === 'extracted' ? 'The document doesn’t show this job’s dates with its client, venue or title, or its PO number, so it wasn’t linked.' : 'A scan or photo, so the link couldn’t be checked. Link it yourself if it’s right.'];
                $jobId = null;
            }
        }

        // Work asked about, held or offered (or called off) isn't a new job: it's proposed as an opportunity instead,
        // and becomes a job only when you accept it.
        $opportunity = self::opportunity($data['opportunity'] ?? null, $text, $status, Opportunities::newestOf($document));
        $unbooked = in_array($opportunity['status_hint']['value'] ?? null, ['enquiry', 'held', 'offered', 'lost'], true);

        $extraction = [
            'summary' => mb_substr((string) ($data['summary'] ?? ''), 0, 1000),
            'job_link_hint' => $linkHint,
            'job_link_reason' => $jobId ? ($data['job_link']['reason'] ?? null) : null,
            'new_job' => $jobId || $unbooked ? null : $this->newJob($data['new_job'] ?? null, $text, $status),
            'po' => $po,
            'receipt' => $this->receipt($data['receipt'] ?? null, $text, $status),
            'opportunity' => $opportunity,
            'unverified_count' => count(array_filter($facts, fn ($f) => ! $f['verified'])) + count(array_filter($po['lines'] ?? [], fn ($l) => ! $l['verified'])),
        ];

        DB::transaction(function () use ($document, $kind, $extraction, $facts, $jobId, $model) {
            $document->update([
                'kind' => $kind,
                'summary' => $extraction['summary'],
                'extraction' => $extraction,
                'extraction_model' => $model,
                'extracted_at' => now(),
                'status' => 'extracted',
                'error' => null,
                'work_job_id' => $document->work_job_id ?? $jobId,
                'job_linked_by' => $document->work_job_id || $document->job_linked_by === 'you' ? $document->job_linked_by : ($jobId ? 'ai' : null),
            ]);
            $document->facts()->delete();
            foreach ($facts as $fact) {
                $document->facts()->create($fact + ['work_job_id' => $document->work_job_id]);
            }
        });

        AuditLog::record('document.read', $document, null, [
            'kind' => $kind, 'facts' => count($facts), 'unverified' => $extraction['unverified_count'], 'model' => $model, 'job' => $document->work_job_id,
        ] + ($extraction['opportunity'] ? ['opportunity' => $extraction['opportunity']['status_hint']['value'] ?? 'unclear'] : []), null, 'ai');

        $document = $document->fresh(['facts']);
        // Work it offers (an availability check, a hold, an offer, a booking or a cancellation) is proposed, or the
        // opportunity it belongs to is updated, however the reading was started. Never made a job by itself.
        if ($extraction['opportunity']) {
            try {
                app(Opportunities::class)->fromDocument($document);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $document;
    }

    /**
     * The work a document offers, each detail with the words it came from and whether those words (and the value in
     * them) were found in the document. Shaped as Opportunities::fromDocument() reads it, which checks it all again.
     * Null when the document offers no work, or says nothing usable about it. For an email, $newest is its newest
     * message: the words for where the work stands must be there (not in the earlier messages it quotes), and must say
     * that stage ("pencil" for a hold, "confirmed" for a booking).
     *
     * @return ?array{status_hint: ?array, hold_rank: ?array, title: ?array, client: ?array, contact: ?array, role: ?array,
     *     venue: ?array, postcode: ?array, dates: list<array{date: string, kind: string, quote: ?string, verified: bool, note: ?string}>,
     *     rate: ?array, reply_by: ?array, po_number: ?array, unverified: list<string>}
     */
    public static function opportunity(mixed $o, ?string $text, string $status, ?string $newest = null): ?array
    {
        if (! is_array($o)) {
            return null;
        }
        $check = function (?string $quote, ?callable $inQuote = null, ?int $amount = null) use ($text, $status): array {
            if ($quote === null || trim($quote) === '') {
                return ['verified' => false, 'note' => 'No words were quoted for it. Check before using.'];
            }
            $c = Grounding::check($text, $status, $quote, $amount);
            if ($c['verified'] && $inQuote !== null && ! $inQuote($quote)) {
                return ['verified' => false, 'note' => 'It isn’t in the quoted words. Check before using.'];
            }

            return $c;
        };
        $quoteOf = fn (mixed $f) => is_array($f) && is_string($f['quote'] ?? null) && trim($f['quote']) !== '' ? mb_substr($f['quote'], 0, 1000) : null;
        $in = fn (string $value) => fn (string $quote) => mb_strlen(Grounding::normalise($value)) >= 2
            && str_contains(Grounding::normalise($quote), Grounding::normalise($value));
        $out = ['status_hint' => null, 'hold_rank' => null, 'title' => null, 'client' => null, 'contact' => null, 'role' => null, 'venue' => null,
            'postcode' => null, 'dates' => [], 'rate' => null, 'reply_by' => null, 'po_number' => null];

        $hint = is_array($o['status_hint'] ?? null) ? $o['status_hint']['value'] ?? null : null;
        if (in_array($hint, self::OPPORTUNITY_STATUSES, true)) {
            $out['status_hint'] = ['value' => $hint, 'quote' => $quoteOf($o['status_hint'])] + $check($quoteOf($o['status_hint']),
                fn (string $q) => Opportunities::stageInQuote($hint, $q) && ($newest === null || Grounding::quoteFound($newest, $q)));
        }
        $rank = is_array($o['hold_rank'] ?? null) ? $o['hold_rank']['value'] ?? null : null;
        if (is_int($rank) && $rank >= 1 && $rank <= 9) {
            $words = [1 => '1st|first', 2 => '2nd|second', 3 => '3rd|third'][$rank] ?? $rank.'th';
            $out['hold_rank'] = ['value' => $rank, 'quote' => $quoteOf($o['hold_rank'])]
                + $check($quoteOf($o['hold_rank']), fn (string $q) => (bool) preg_match('/\b('.$words.')\b/i', $q));
        }

        // The words: what and where the work is, and who it's for. The client, venue, postcode and PO number must be
        // in their quote; a title or role is the document's own wording, so only its quote is checked.
        foreach (['title' => [150, false], 'client' => [120, true], 'role' => [120, false], 'venue' => [200, true], 'postcode' => [10, true], 'po_number' => [100, true]] as $field => [$max, $inQuote]) {
            $value = is_array($o[$field] ?? null) ? $o[$field]['value'] ?? null : null;
            $value = is_scalar($value) ? trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '') : '';
            if ($value === '') {
                continue;
            }
            $value = mb_substr($field === 'postcode' ? strtoupper($value) : $value, 0, $max);
            $bare = $field === 'client' ? trim(preg_replace('/\b(ltd|limited|plc|llp|the)\b\.?/iu', ' ', $value) ?? $value) : $value;
            $out[$field] = ['value' => $value, 'quote' => $quoteOf($o[$field])] + $check($quoteOf($o[$field]), $inQuote ? $in($bare !== '' ? $bare : $value) : null);
        }

        // Who's asking: their name and email address must both be in the quoted words.
        if (is_array($o['contact'] ?? null)) {
            $name = is_string($o['contact']['name'] ?? null) && trim($o['contact']['name']) !== '' ? mb_substr(trim($o['contact']['name']), 0, 120) : null;
            $email = is_string($o['contact']['email'] ?? null) && preg_match('/^[^@\s<>]+@[^@\s<>]+\.[a-z]{2,}$/i', trim($o['contact']['email']))
                ? mb_substr(strtolower(trim($o['contact']['email'])), 0, 200) : null;
            if ($name !== null || $email !== null) {
                $out['contact'] = ['name' => $name, 'email' => $email, 'quote' => $quoteOf($o['contact'])]
                    + $check($quoteOf($o['contact']), fn (string $q) => ($name === null || $in($name)($q)) && ($email === null || $in($email)($q)));
            }
        }

        // Its days, each with its kind, and each checked in its own words ("12-14 March" gives the 13th too).
        $days = [];
        foreach (is_array($o['dates'] ?? null) ? $o['dates'] : [] as $d) {
            $date = is_array($d) ? self::date(is_string($d['date'] ?? null) ? $d['date'] : null) : null;
            if ($date === null || isset($days[$date]) || count($days) >= JobDays::MAX_DAYS) {
                continue;
            }
            $kind = in_array($d['kind'] ?? null, Opportunity::DAY_KINDS, true) ? $d['kind'] : 'work';
            $days[$date] = ['date' => $date, 'kind' => $kind, 'quote' => $quoteOf($d)] + $check($quoteOf($d), fn (string $q) => Opportunities::dateCovered($date, $q));
        }
        ksort($days);
        $out['dates'] = array_values($days);

        // The money: the amount must be in its quote, with its currency and unit (a day rate isn't a fee for the job).
        $rate = is_array($o['rate'] ?? null) ? $o['rate'] : null;
        if ($rate && is_int($rate['amount_minor'] ?? null) && $rate['amount_minor'] > 0) {
            $unit = in_array($rate['unit'] ?? null, Opportunity::RATE_UNITS, true) ? $rate['unit'] : 'job';
            $currency = preg_match('/^[A-Z]{3}$/', (string) ($rate['currency'] ?? '')) ? $rate['currency'] : 'GBP';
            $out['rate'] = ['amount_minor' => $rate['amount_minor'], 'currency' => $currency, 'unit' => $unit, 'quote' => $quoteOf($rate)]
                + $check($quoteOf($rate), fn (string $q) => Opportunities::rateInQuote($currency, $unit, $q), $rate['amount_minor']);
        }

        $replyBy = is_array($o['reply_by'] ?? null) ? self::date(is_string($o['reply_by']['value'] ?? null) ? $o['reply_by']['value'] : null) : null;
        if ($replyBy !== null) {
            $out['reply_by'] = ['value' => $replyBy, 'quote' => $quoteOf($o['reply_by'])] + $check($quoteOf($o['reply_by']), fn (string $q) => Grounding::dateInQuote($replyBy, $q));
        }

        if (! $out['status_hint'] && ! $out['dates'] && ! $out['client'] && ! $out['title']) {
            return null; // nothing to go on
        }
        $unverified = [];
        foreach ($out as $field => $fact) {
            if ($field === 'dates' ? collect($fact)->contains(fn ($d) => ! $d['verified']) : ($fact !== null && ! $fact['verified'])) {
                $unverified[] = $field;
            }
        }

        return $out + ['unverified' => $unverified];
    }

    /**
     * Link a document (and, unless told not to, the attachments that go with it) to a job, keeping its details in
     * step. When you unlink it, the job is remembered as "not this job". An attachment goes with its email only while
     * it's on the email's job (or on none): one on another job, or one you said isn't for this job, stays where it
     * is, whoever links the email, and unlinking the email leaves alone an attachment that isn't on the email's job.
     * Automatic links also leave alone any attachment you linked yourself.
     */
    public static function relink(Document $document, ?int $jobId, ?string $by = null, bool $attachments = true): void
    {
        $owner = $by === 'you';
        $was = $document->work_job_id;
        $apply = function (Document $d) use ($jobId, $by, $owner) {
            $fields = ['work_job_id' => $jobId];
            if ($by !== null) {
                $fields['job_linked_by'] = $jobId ? $by : null;
            }
            if ($owner && ! $jobId && $d->work_job_id) {
                $fields['job_rejected'] = array_values(array_unique([...($d->job_rejected ?? []), $d->work_job_id]));
            }
            $d->update($fields);
            DocumentFact::query()->where('document_id', $d->id)->update(['work_job_id' => $jobId]);
        };
        $apply($document);
        if (! $attachments) {
            return;
        }
        foreach ($document->children()->get() as $child) {
            // When you move the email to another job, what was on its old job moves with it; automation never moves
            // an attachment off a job.
            $onOther = $child->work_job_id && $child->work_job_id !== $jobId && (! $owner || $child->work_job_id !== $was);
            $stays = $jobId ? $onOther || in_array($jobId, $child->job_rejected ?? [], true) : $child->work_job_id !== $was;
            if ($stays || (! $owner && $child->job_linked_by === 'you')) {
                continue;
            }
            $apply($child);
        }
    }

    private function newJob(?array $job, ?string $text, string $status): ?array
    {
        if (! $job || empty($job['title']) || empty($job['starts_on'])) {
            return null;
        }

        return [
            'title' => mb_substr($job['title'], 0, 150),
            'client' => $job['client'] ?? null,
            'starts_on' => self::date($job['starts_on']),
            'ends_on' => self::date($job['ends_on'] ?? null) ?? self::date($job['starts_on']),
            'venue' => $job['venue'] ?? null,
            'postcode' => $job['postcode'] ?? null,
            'quote' => $job['quote'] ?? null,
            'verified' => ! empty($job['quote']) && Grounding::check($text, $status, $job['quote'])['verified'],
        ];
    }

    private function receipt(?array $receipt, ?string $text, string $status): ?array
    {
        if (! $receipt || ! is_int($receipt['amount_minor'] ?? null)) {
            return null;
        }
        $check = Grounding::check($text, $status, (string) ($receipt['quote'] ?? ''), $receipt['amount_minor']);

        return [
            'merchant' => $receipt['merchant'] ?? null,
            'date' => self::date($receipt['date'] ?? null),
            'amount_minor' => $receipt['amount_minor'],
            'currency' => preg_match('/^[A-Z]{3}$/', (string) ($receipt['currency'] ?? '')) ? $receipt['currency'] : 'GBP',
            'quote' => $receipt['quote'] ?? null,
            'verified' => $check['verified'],
        ];
    }

    /** The document as Claude receives it: the PDF or image itself, or its text. */
    public static function content(Document $document): array
    {
        $path = Storage::disk('local')->path($document->stored_path);

        if ($document->isPdf()) {
            if ($document->size > 30 * 1024 * 1024) {
                throw new RuntimeException('PDF is too large to read (over 30 MB).');
            }

            return [['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode(file_get_contents($path))]]];
        }
        if ($document->isImage()) {
            return [['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $document->mime, 'data' => base64_encode(file_get_contents($path))]]];
        }
        if ($document->text) {
            return [['type' => 'text', 'text' => "<document name=\"{$document->original_name}\">\n".mb_substr($document->text, 0, 150_000)."\n</document>"]];
        }

        throw new RuntimeException('This file type can’t be read. Save it as PDF, JPEG or PNG.');
    }

    private function candidateJobs(Document $document): array
    {
        $around = CarbonImmutable::instance($document->received_at ?? now());

        // Wide window: documents for past jobs are often added later while catching up.
        return WorkJob::query()->with('client')
            ->whereBetween('starts_on', [$around->subYears(2), $around->addMonths(18)])
            ->orderByDesc('starts_on')->limit(250)->get()
            ->map(fn (WorkJob $j) => [
                'id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name, 'starts_on' => $j->starts_on->format('Y-m-d'),
                'ends_on' => $j->ends_on->format('Y-m-d'), 'venue' => $j->location, 'postcode' => $j->postcode,
            ])->all();
    }

    private function system(): string
    {
        return <<<'TXT'
You read documents for a UK self-employed freelancer who works on events and productions. The details you extract go into their bookkeeping app and job sheets, so accuracy matters more than completeness.

Rules:
- Extract only what the document actually states. Never infer, assume or fill gaps. If something isn't in the document, leave it out (or use null).
- Every value needs a quote copied exactly, character for character, from the document: the shortest passage that contains the value. Don't paraphrase, correct spelling or reformat numbers inside quotes.
- Dates as YYYY-MM-DD. Times in 24-hour HH:MM. Money as integer minor units (pence or cents), in the currency the document uses.
- For a purchase order, list every line item with its own quote, and give the stated total with its quote.
- Travel and accommodation: say what is booked, for when, and who booked or pays for it, if the document says.
- Only link to a job from the list provided, and only when the document clearly refers to it (same dates and client or venue, or a PO or job reference). Otherwise leave job_id null.

Work being offered (the "opportunity" block):
- Fill it whenever the document asks about, holds, offers, confirms or calls off work for the freelancer, whatever its kind (a plain email or a job invite too): an availability check ("are you free 12-14 March?"), a request for a quote, a pencil or hold (1st or 2nd option, first refusal), a firm offer with a rate, a booking confirmation, or a cancellation, release or "we've gone with someone else". Otherwise set it to null. Newsletters, job-board alerts, adverts and mail sent to a whole list are not work being offered.
- status_hint: "enquiry" (asked if they're free, or for a quote), "held" (pencilled, on hold, an option), "offered" (a firm offer waiting for their yes), "confirmed" (booked), "lost" (cancelled, released, or gone to someone else). In an email thread, go by the newest message. Quote the words that show it.
- hold_rank: 1 for a 1st option or first refusal, 2 for a 2nd option, and so on; null when it isn't a ranked hold.
- client: the company booking them, who they'd invoice (a crewing agency if the agency books them), as written. contact: the person asking (never the freelancer themselves): their email address with the words that contain it (often a From: line), and their name only if those same words contain it.
- dates: one entry per day, each with the words that give it; a range such as "12-14 March" gives each day in it, each with that same quote. kind is "rig" for a build, get-in or load-in day, "derig" for a de-rig, get-out or load-out day, "travel" for a travel day, otherwise "work". A date with no year is the next one on or after the date the document was sent.
- rate: the fee or rate offered, as written, with unit "day", "hour" or "job" (a fee for the whole job). reply_by: the date they want an answer by, only if stated.
- Use kind "opportunity" for a document whose main purpose is an enquiry, hold or offer for work that isn't booked yet; a booking confirmation is "job_invite". Work that isn't booked yet is not a new job: leave new_job null unless the document confirms the booking.
TXT;
    }

    private function instructions(Document $document, array $jobs): string
    {
        $meta = array_filter([
            'file name' => $document->original_name,
            'email subject' => $document->email_subject,
            'from' => $document->email_from,
            'received' => $document->received_at?->format('Y-m-d'),
        ]);

        return "Read the document above.\n\nAbout it: ".json_encode($meta, JSON_UNESCAPED_SLASHES)
            ."\n\nKnown jobs you may link to (id, title, client, dates, venue):\n".json_encode($jobs, JSON_UNESCAPED_SLASHES)
            ."\n\nFact fields you can use: ".implode(', ', array_keys(self::FIELDS)).'.';
    }

    private static function date(?string $value): ?string
    {
        if (! $value || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }

    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $quoted = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];
        // One detail of the work offered: its value (null when the document doesn't say) and the words it came from.
        $fact = $quoted(['value' => $nullableString, 'quote' => $nullableString]);

        return $quoted([
            'kind' => ['type' => 'string', 'enum' => array_keys(Document::KINDS)],
            'summary' => ['type' => 'string', 'description' => 'One or two sentences: what this document is and what it means for the freelancer.'],
            'job_link' => $quoted(['job_id' => ['type' => ['integer', 'null']], 'reason' => $nullableString]),
            'new_job' => ['anyOf' => [['type' => 'null'], $quoted([
                'title' => $nullableString, 'client' => $nullableString, 'starts_on' => $nullableString, 'ends_on' => $nullableString,
                'venue' => $nullableString, 'postcode' => $nullableString, 'quote' => $nullableString,
            ])]],
            'facts' => ['type' => 'array', 'items' => $quoted([
                'field' => ['type' => 'string', 'enum' => array_keys(self::FIELDS)],
                'label' => ['type' => 'string'],
                'value' => ['type' => 'string'],
                'date' => $nullableString,
                'time' => $nullableString,
                'quote' => ['type' => 'string'],
                'page' => ['type' => ['integer', 'null']],
            ])],
            'po' => ['anyOf' => [['type' => 'null'], $quoted([
                'po_number' => $nullableString, 'bill_to_name' => $nullableString, 'bill_to_address' => $nullableString,
                'bill_to_email' => $nullableString, 'currency' => ['type' => 'string'], 'payment_terms_days' => ['type' => ['integer', 'null']],
                'total' => $quoted(['amount_minor' => ['type' => ['integer', 'null']], 'quote' => $nullableString]),
                'lines' => ['type' => 'array', 'items' => $quoted([
                    'description' => ['type' => 'string'], 'quantity' => ['type' => 'number'], 'unit_price_minor' => ['type' => 'integer'],
                    'amount_minor' => ['type' => 'integer'], 'quote' => ['type' => 'string'],
                ])],
            ])]],
            'receipt' => ['anyOf' => [['type' => 'null'], $quoted([
                'merchant' => $nullableString, 'date' => $nullableString, 'amount_minor' => ['type' => 'integer'],
                'currency' => ['type' => 'string'], 'quote' => $nullableString,
            ])]],
            'opportunity' => ['anyOf' => [['type' => 'null'], $quoted([
                'status_hint' => $quoted(['value' => ['type' => 'string', 'enum' => self::OPPORTUNITY_STATUSES], 'quote' => $nullableString]),
                'hold_rank' => $quoted(['value' => ['type' => ['integer', 'null']], 'quote' => $nullableString]),
                'title' => $fact, 'client' => $fact,
                'contact' => $quoted(['name' => $nullableString, 'email' => $nullableString, 'quote' => $nullableString]),
                'role' => $fact, 'venue' => $fact, 'postcode' => $fact,
                'dates' => ['type' => 'array', 'items' => $quoted([
                    'date' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => Opportunity::DAY_KINDS], 'quote' => ['type' => 'string'],
                ])],
                'rate' => ['anyOf' => [['type' => 'null'], $quoted([
                    'amount_minor' => ['type' => 'integer'], 'currency' => ['type' => 'string'],
                    'unit' => ['type' => 'string', 'enum' => Opportunity::RATE_UNITS], 'quote' => ['type' => 'string'],
                ])]],
                'reply_by' => $fact, 'po_number' => $fact,
            ])]],
        ]);
    }
}
