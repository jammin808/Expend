<?php

namespace App\Domain\Ai;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\Grounding;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Verification\Verifier;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads a document with Claude and records what it says: kind, summary, job details, travel,
 * accommodation, PO lines. Every value must come with a verbatim quote, and each quote is checked
 * against the document's own text before anything is marked verified. Nothing is applied to jobs,
 * invoices or the ledger automatically.
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

        $extraction = [
            'summary' => mb_substr((string) ($data['summary'] ?? ''), 0, 1000),
            'job_link_hint' => $linkHint,
            'job_link_reason' => $jobId ? ($data['job_link']['reason'] ?? null) : null,
            'new_job' => $jobId ? null : $this->newJob($data['new_job'] ?? null, $text, $status),
            'po' => $po,
            'receipt' => $this->receipt($data['receipt'] ?? null, $text, $status),
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
        ], null, 'ai');

        return $document->fresh(['facts']);
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
        ]);
    }
}
