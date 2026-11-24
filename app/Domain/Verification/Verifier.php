<?php

namespace App\Domain\Verification;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Documents\Grounding;
use App\Domain\Documents\TextExtractor;
use App\Domain\Gmail\GmailClient;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Travel\TravelReader;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\JobCandidate;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZBateson\MailMimeParser\MailMimeParser;

/**
 * Double-checks AI findings against their source, separately from the read that produced them:
 *
 *  - documents: the stored file is checked against its fingerprint, its text is extracted again from the file
 *    (not the copy kept from the first read) and every quoted detail, PO line, total, receipt and proposed job
 *    is matched again; an AI job link must be backed by the job's dates with its client, venue or title, or by
 *    its PO number. Scans and photos, which can't be matched word for word, get an independent second read.
 *  - stays and journeys: the email is fetched again from Gmail (is it still there, unchanged?) and the dates,
 *    amount and reference are checked again in it, or the booking data re-read.
 *  - possible jobs and calendar links: each calendar entry is looked up again, each quote checked again.
 *  - AI suggestions on transactions: checked against the transaction itself and the job's dates.
 *
 * Each check is saved (latest per item), shown next to the finding, and recorded in the history.
 */
final class Verifier
{
    /** Each kind of check's subject as the audit trail names it (the model's name, as AuditLog records a model). */
    private const AUDIT_SUBJECTS = [
        'document' => 'Document', 'travel_item' => 'TravelItem', 'job_candidate' => 'JobCandidate',
        'calendar_link' => 'CalendarJobLink', 'transaction' => 'BankTransaction',
    ];

    /** "PO", "P.O.", "P/O" or "purchase order". */
    private const PO_WORD = '(?:P(?:\.\s?|\s?\/\s?)?O\.?|purchase\s+order)';

    /** "No", "number", "ref" or "reference", as said after "PO" or "order". */
    private const NUMBER_WORD = '(?:number|num|nr|no|ref(?:erence)?)\b\.?';

    /** A PO label: "PO" (and the rest) with an optional "no" or "ref", or "order" only with one (or "#"). */
    private const PO_LABEL = '(?:'.self::PO_WORD.'(?:\s*'.self::NUMBER_WORD.')?|order\s*(?:'.self::NUMBER_WORD.'|\#))';

    /** A heading for a PO number column: "PO Number", "P.O. No", "PO #", "Order ref"… (not "PO Date"). */
    private const PO_HEADING = '(?:'.self::PO_WORD.'|order)\s*(?:'.self::NUMBER_WORD.'|\#)';

    private const MONTH = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sept?(?:ember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\b';

    public function __construct(private AiClient $ai) {}

    // ------------------------------------------------------------------ documents

    public function document(Document $doc, bool $secondRead = false): Verification
    {
        if (! $doc->extraction) {
            return $this->save('document', $doc->id, 'unverifiable', 'source_text', [], 'Not read yet, so there is nothing to check.');
        }
        if (! Storage::disk('local')->exists($doc->stored_path)) {
            return $this->save('document', $doc->id, 'source_missing', 'source_text', [], 'The stored file is missing, so nothing could be checked.');
        }
        $raw = (string) Storage::disk('local')->get($doc->stored_path);
        if (hash('sha256', $raw) !== $doc->sha256) {
            return $this->save('document', $doc->id, 'mismatch', 'source_text', [], 'The stored file has changed since it arrived. Upload the original again.');
        }

        [$text, $status] = self::freshText($doc, $raw);
        $checks = [];
        if ($status === 'extracted' && Grounding::normalise((string) $doc->text) !== Grounding::normalise($text)) {
            $checks[] = ['what' => 'Document text', 'ok' => true, 'note' => 'The text kept from the first read differed from the file; checked against the file and updated.'];
            $doc->update(['text' => $text, 'text_status' => $status]);
        }

        if ($status !== 'extracted') {
            return $this->imageOnly($doc, $secondRead);
        }

        foreach ($doc->facts as $fact) {
            $c = Grounding::check($text, 'extracted', $fact->quote);
            if ($c['verified'] && $fact->on_date && in_array($fact->field, ['start_date', 'end_date', 'deadline'], true) && ! Grounding::dateInQuote($fact->on_date->format('Y-m-d'), $fact->quote)) {
                $c = ['verified' => false, 'note' => 'The date isn’t in the quoted words. Check before using.'];
            }
            if ($fact->verified !== $c['verified'] || $fact->verification_note !== $c['note']) {
                $fact->update(['verified' => $c['verified'], 'verification_note' => $c['note']]);
            }
            $checks[] = ['what' => $fact->label.': '.mb_substr($fact->value, 0, 80), 'ok' => $c['verified'], 'note' => $c['note']];
        }

        $x = $doc->extraction;
        foreach ($x['po']['lines'] ?? [] as $i => $line) {
            $c = Grounding::check($text, 'extracted', (string) $line['quote'], $line['amount_minor']);
            $x['po']['lines'][$i]['verified'] = $c['verified'];
            $x['po']['lines'][$i]['note'] = $c['note'];
            $checks[] = ['what' => 'PO line: '.mb_substr($line['description'], 0, 60), 'ok' => $c['verified'], 'note' => $c['note']];
        }
        if (isset($x['po']) && is_int($x['po']['stated_total_minor'] ?? null)) {
            $quote = self::totalQuote($text, $x['po']['stated_total_minor']);
            $x['po']['total_verified'] = $quote !== null;
            $checks[] = ['what' => 'PO total', 'ok' => $quote !== null, 'note' => $quote ? null : 'The total wasn’t found in the document.'];
        }
        if (! empty($x['new_job'])) {
            $nj = $x['new_job'];
            $ok = ! empty($nj['quote']) && Grounding::quoteFound($text, $nj['quote']) && (! $nj['starts_on'] || Grounding::dateInQuote($nj['starts_on'], $nj['quote']) || self::dateInText($text, $nj['starts_on']));
            $x['new_job']['verified'] = $ok;
            $checks[] = ['what' => 'Proposed job: '.mb_substr((string) $nj['title'], 0, 60), 'ok' => $ok, 'note' => $ok ? null : 'Its words or start date weren’t found in the document.'];
        }
        if (! empty($x['receipt'])) {
            $c = Grounding::check($text, 'extracted', (string) ($x['receipt']['quote'] ?? ''), $x['receipt']['amount_minor']);
            $x['receipt']['verified'] = $c['verified'];
            $checks[] = ['what' => 'Receipt amount', 'ok' => $c['verified'], 'note' => $c['note']];
        }
        $x['unverified_count'] = $doc->facts()->where('verified', false)->count() + count(array_filter($x['po']['lines'] ?? [], fn ($l) => ! $l['verified']));

        if ($doc->work_job_id && $doc->job_linked_by !== 'you' && ($job = WorkJob::query()->with('client')->find($doc->work_job_id))) {
            $e = self::jobEvidence($text, $job, $x['po']['po_number'] ?? null);
            $checks[] = ['what' => 'Linked to '.$job->title, 'ok' => $e['ok'], 'note' => $e['ok'] ? ucfirst(implode(', ', $e['why'])) : 'The document doesn’t show this job’s dates with its client, venue or title, or its PO number. Check the link.'];
        }
        $doc->update(['extraction' => $x]);

        return $this->fromChecks('document', $doc->id, 'source_text', $checks, 'against the file');
    }

    /** Scans and photos: an independent second read of the same file, asked only to confirm or contradict each detail. */
    private function imageOnly(Document $doc, bool $secondRead): Verification
    {
        $claims = $this->claims($doc);
        if (! $claims) {
            return $this->save('document', $doc->id, 'unverifiable', 'source_text', [], 'A scan or photo with no details to check.');
        }
        if (! $secondRead || ! AiConfig::ready()) {
            $existing = Verification::for('document', $doc->id);
            if ($existing && str_starts_with($existing->status, 'second_read')) {
                return $existing; // keep the last second read
            }

            return $this->save('document', $doc->id, 'unverifiable', 'source_text', array_map(fn ($c) => ['what' => $c['label'], 'ok' => null, 'note' => 'A scan or photo: check against the document.'], $claims),
                'A scan or photo, so it can’t be matched word for word. Press Re-check for an independent second read.');
        }

        try {
            $result = $this->ai->send([
                'maxTokens' => 6000,
                'system' => 'You check another reader\'s notes against the original document. Be strict and literal. For each claim say "yes" only if the '
                    .'document clearly states that value, "no" if it states something different, "not_found" if it doesn\'t say. Give the value as the '
                    .'document states it, and the exact words. Don\'t guess.',
                'messages' => [['role' => 'user', 'content' => [
                    ...DocumentReader::content($doc),
                    ['type' => 'text', 'text' => "Claims to check:\n".json_encode(array_map(fn ($c) => ['id' => $c['id'], 'claim' => $c['label']], $claims), JSON_UNESCAPED_UNICODE)],
                ]]],
                'outputConfig' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['claims'],
                    'properties' => ['claims' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'verdict', 'document_says'],
                        'properties' => ['id' => ['type' => 'string'], 'verdict' => ['type' => 'string', 'enum' => ['yes', 'no', 'not_found']], 'document_says' => ['type' => ['string', 'null']]],
                    ]]],
                ]]],
            ]);
            $answers = collect(json_decode($result->text(), true)['claims'] ?? [])->keyBy('id');
        } catch (Throwable $e) {
            report($e);

            return $this->save('document', $doc->id, 'unverifiable', 'second_read', [], 'The second read couldn’t be done: '.mb_substr($e->getMessage(), 0, 150));
        }

        $checks = [];
        foreach ($claims as $claim) {
            $a = $answers[$claim['id']] ?? null;
            $ok = ($a['verdict'] ?? null) === 'yes';
            $checks[] = ['what' => $claim['label'], 'ok' => $ok, 'note' => $ok ? 'A second read agreed' : (($a['verdict'] ?? '') === 'no'
                ? 'A second read found something different: '.mb_substr((string) $a['document_says'], 0, 120) : 'A second read couldn’t find it')];
            if ($claim['fact_id'] ?? null) {
                $doc->facts()->whereKey($claim['fact_id'])->update(['verification_note' => $ok
                    ? 'Read from a scan or photo; a second, independent read agreed. Still worth a glance.'
                    : 'Read from a scan or photo, and a second read didn’t agree. Check against the document.']);
            }
        }
        $bad = count(array_filter($checks, fn ($c) => ! $c['ok']));

        return $this->save('document', $doc->id, $bad ? 'second_read_disagreed' : 'second_read_agreed', 'second_read', $checks,
            $bad ? "A second read didn’t agree on {$bad} of ".count($checks).' details. Check them against the document.'
                : 'A second, independent read agreed on all '.count($checks).' details (a scan or photo, so not matched word for word).');
    }

    /** @return list<array{id:string,label:string,fact_id?:int}> */
    private function claims(Document $doc): array
    {
        $claims = [];
        foreach ($doc->facts as $f) {
            $claims[] = ['id' => 'fact'.$f->id, 'label' => $f->label.': '.$f->value.($f->on_date ? ' ('.$f->on_date->format('j M Y').')' : ''), 'fact_id' => $f->id];
        }
        foreach ($doc->extraction['po']['lines'] ?? [] as $i => $l) {
            $claims[] = ['id' => 'line'.$i, 'label' => 'PO line "'.$l['description'].'" for '.number_format($l['amount_minor'] / 100, 2)];
        }
        if (is_int($doc->extraction['po']['stated_total_minor'] ?? null)) {
            $claims[] = ['id' => 'total', 'label' => 'PO total '.number_format($doc->extraction['po']['stated_total_minor'] / 100, 2)];
        }
        if ($r = $doc->extraction['receipt'] ?? null) {
            $claims[] = ['id' => 'receipt', 'label' => 'Amount paid '.number_format($r['amount_minor'] / 100, 2).' '.$r['currency'].($r['date'] ? ' on '.$r['date'] : '')];
        }

        return array_slice($claims, 0, 60);
    }

    /**
     * Is a job link backed by the document? Its PO number, or one of its dates together with its client, venue,
     * postcode or a distinctive word of its title.
     *
     * @return array{ok:bool, why:list<string>}
     */
    public static function jobEvidence(?string $text, WorkJob $job, ?string $po = null): array
    {
        $text = (string) $text;
        $why = [];
        // The PO number has to be in the document as a whole reference (not inside a phone number or an amount),
        // matched as the job has it written; a PO number the AI read only counts if it's really there too.
        $jobPo = JobFinder::normalisePo($job->po_number);
        if ($jobPo && strlen($jobPo) >= 4 && self::poInText($text, (string) $job->po_number)) {
            return ['ok' => true, 'why' => ['the job’s PO number is in it']];
        }
        unset($po);
        // Its own words: an email's subject and body, without its headers or the headers of emails it quotes. The
        // date an email was sent isn't the date of the job.
        $content = self::contentOf($text);
        $dated = false;
        foreach (array_slice($job->workDates(), 0, 31) as $date) {
            if (self::dateInText($content, $date)) {
                $dated = true;
                $why[] = 'it gives '.CarbonImmutable::parse($date)->format('j M Y');
                break;
            }
        }
        // Named outright: its client, exact venue (not one that could be anywhere, like "London"), postcode, or its
        // whole title. A single word of the title, or a vague venue, is only a mention: never enough to link it.
        $named = null;
        $strong = false;
        $norm = Grounding::normalise($text);
        foreach (array_filter([$job->client?->name, $job->location, $job->postcode]) as $name) {
            $n = Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b/i', '', $name));
            if (mb_strlen($n) >= 3 && str_contains($norm, $n)) {
                $vague = $name === $job->location && JobGatherer::vaguePlace($job->location);
                $named ??= $name;
                if (! $vague) {
                    [$named, $strong] = [$name, true];
                    break;
                }
            }
        }
        $title = Grounding::normalise((string) $job->title);
        if (! $strong && mb_strlen($title) >= 8 && preg_match('/\S\s+\S/u', trim((string) $job->title)) && str_contains($norm, $title)) {
            [$named, $strong] = [$job->title, true];
        }
        if (! $named) {
            preg_match_all('/[\p{L}\p{N}]{5,}/u', mb_strtolower($job->title), $m);
            foreach ($m[0] as $word) {
                if (str_contains($norm, Grounding::normalise($word))) {
                    $named = $word;
                    break;
                }
            }
        }
        if ($named) {
            $why[] = 'it mentions '.$named;
        }

        return ['ok' => $dated && $strong, 'why' => $why];
    }

    /**
     * A document's own words for the date test: an email's subject (what it's about, "Call sheet - Wed 14 May")
     * and its body, without the email's headers or the quoted headers of earlier emails (JobGatherer::withoutHeaders).
     */
    public static function contentOf(string $text): string
    {
        preg_match_all('/^[>\s]*Subject:[ \t]*(.+)$/mi', $text, $m);

        return trim(implode("\n", $m[1])."\n".JobGatherer::withoutHeaders($text));
    }

    /**
     * Is this PO number in the text as a reference of its own? Separators are only allowed where the PO number has
     * them (so "4500123" doesn't match the phone number "0161 450 0123"), and it's never the end of a longer run of
     * numbers. A leading "PO" (or "P.O.", "P/O") is optional, except for a number of fewer than six digits: on its
     * own that could be a time, a room, a quantity or part of a phone number, so it only counts straight after a PO
     * label ("PO", "P.O.", "P/O", "purchase order", or "order" with "no", "number", "ref" or "#") or under a PO
     * number column heading, and never where it reads as a time, a date or a decimal ("12:00", "12/03", "12 March").
     */
    public static function poInText(string $text, string $po): bool
    {
        $groups = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $po) ?: [], fn ($g) => $g !== ''));
        if (count($groups) > 2 && strtoupper($groups[0].$groups[1]) === 'PO') {
            $groups = ['PO', ...array_slice($groups, 2)]; // "P.O. 4500123" and "P/O 4500123" are "PO 4500123"
        }
        if (! $groups || strlen(implode('', $groups)) < 3) {
            return false;
        }
        if (preg_match('/^PO(\d\w*)$/i', $groups[0], $m)) {
            $groups = ['PO', $m[1], ...array_slice($groups, 1)]; // "PO7781" is written "PO-7781" or "PO 7781" too
        }
        if (strtoupper($groups[0]) === 'PO' && count($groups) > 1) {
            array_shift($groups);
        }
        $body = implode('[\s\-\/:#.]*', array_map(fn ($g) => preg_quote($g, '/'), $groups));
        $digits = implode('', $groups);
        $short = ctype_digit($digits) && strlen($digits) < 6;
        $label = self::PO_LABEL.'[\s\-\/:#.]*';
        $after = $short ? '(?![A-Z0-9]|[.,:\/\-]\d|\s+'.self::MONTH.')' : '(?![A-Z0-9]|[.,]\d)';

        if (preg_match('/(?<![A-Z0-9£$€.,])'.$label.$body.$after.'/i', $text)) {
            return true;
        }
        if ($short) {
            return self::underPoHeading($text, $body.$after);
        }

        $before = ctype_digit($groups[0][0]) ? '(?<![A-Z0-9£$€.,])(?<!\d[\s\-\/])' : '(?<![A-Z0-9£$€.,])';

        return (bool) preg_match('/'.$before.$body.$after.'/i', $text);
    }

    /**
     * A table whose first column is the PO number: a line starting "PO Number" (or "PO No", "P.O. Ref", "Order #"…)
     * with the other headings after it, and the number at the start of the next line ("10234 12/03/2025 …").
     */
    private static function underPoHeading(string $text, string $number): bool
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), fn ($l) => $l !== ''));
        foreach ($lines as $i => $line) {
            if (isset($lines[$i + 1]) && preg_match('/^'.self::PO_HEADING.'(?=[\s:#]|$)/i', $line) && preg_match('/^'.$number.'(?=\s|$)/i', $lines[$i + 1])) {
                return true;
            }
        }

        return false;
    }

    /** Does the text state this date anywhere (any common UK way of writing it)? */
    public static function dateInText(string $text, string $date): bool
    {
        // Check line by line, so a year elsewhere in a long document doesn't hide or fake a match.
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            if (trim($line) !== '' && Grounding::dateInQuote($date, mb_substr($line, 0, 500))) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ stays and journeys

    public function travelItem(TravelItem $item, bool $refetch = true): Verification
    {
        if ($item->source === 'manual') {
            return $this->save('travel_item', $item->id, 'verified', 'records', [], 'Entered by you.');
        }
        $doc = $item->document;
        if (! $doc || ! Storage::disk('local')->exists($doc->stored_path)) {
            return $this->save('travel_item', $item->id, 'source_missing', 'source_text', [], 'The booking email is no longer stored.');
        }
        $raw = (string) Storage::disk('local')->get($doc->stored_path);
        $checks = [];
        $method = 'source_text';

        if ($refetch && $doc->source === 'gmail' && GmailClient::connected() && ($message = GmailMessage::query()->where('document_id', $doc->id)->first())) {
            $method = 'gmail_refetch';
            try {
                $again = GmailClient::message($message->message_id)['raw'];
                $same = hash('sha256', $again) === $doc->sha256;
                $checks[] = ['what' => 'Email in Gmail', 'ok' => true, 'note' => $same ? 'Fetched again from Gmail: still there and unchanged' : 'Fetched again from Gmail: it differs from the copy first read, so it was checked as it is now'];
                $raw = $again;
            } catch (Throwable $e) {
                $gone = str_contains(mb_strtolower($e->getMessage()), 'not found');
                $checks[] = ['what' => 'Email in Gmail', 'ok' => $gone ? false : null, 'note' => $gone ? 'No longer in Gmail (deleted?). Checked against the copy kept here.' : 'Gmail couldn’t be reached; checked against the copy kept here.'];
            }
        }

        if ($item->source === 'structured_data') {
            $html = (string) (new MailMimeParser)->parse($raw, false)->getHtmlContent();
            $found = collect(TravelReader::fromStructuredData($html, TravelReader::providerFrom((string) $doc->email_from)))
                ->first(fn ($r) => ($item->reference && $r['reference'] === $item->reference) || $r['starts_on'] === $item->starts_on->format('Y-m-d'));
            if (! $found) {
                $checks[] = ['what' => 'Booking data', 'ok' => false, 'note' => 'The booking wasn’t found in the email’s booking data on a second read.'];
            } else {
                foreach (['starts_on' => 'Start', 'ends_on' => 'End', 'amount_minor' => 'Amount', 'reference' => 'Reference'] as $field => $label) {
                    $mine = $item->{$field} instanceof \DateTimeInterface ? $item->{$field}->format('Y-m-d') : $item->{$field};
                    if ($mine === null && $found[$field] === null) {
                        continue;
                    }
                    $ok = (string) $mine === (string) $found[$field];
                    $checks[] = ['what' => $label, 'ok' => $ok, 'note' => $ok ? null : 'The email’s booking data says '.($found[$field] ?? 'nothing')];
                }
            }
        } else {
            $text = DocumentIntake::emailText($raw);
            $evidence = $item->evidence ?? [];
            $values = ['starts_on' => $item->starts_on?->format('Y-m-d'), 'ends_on' => $item->ends_on?->format('Y-m-d'), 'amount' => $item->amount_minor, 'reference' => $item->reference];
            foreach ($evidence as $field => $e) {
                $quote = (string) ($e['quote'] ?? '');
                $ok = $quote !== '' && Grounding::quoteFound($text, $quote);
                if ($ok && in_array($field, ['starts_on', 'ends_on'], true) && $values[$field]) {
                    $ok = Grounding::dateInQuote($values[$field], $quote);
                } elseif ($ok && $field === 'amount' && $values['amount'] !== null) {
                    $ok = Grounding::amountInQuote($values['amount'], $quote) && Grounding::amountInText($text, $quote, $values['amount']);
                } elseif ($ok && $field === 'reference' && $values['reference']) {
                    $ok = Grounding::quoteFound($quote, $values['reference']);
                }
                $evidence[$field]['verified'] = $ok;
                $evidence[$field]['note'] = $ok ? null : 'Not found in the email on a second check.';
                $checks[] = ['what' => ucfirst(str_replace('_', ' ', $field)), 'ok' => $ok, 'note' => $evidence[$field]['note']];
            }
            $item->update(['evidence' => $evidence, 'unverified_count' => count(array_filter($evidence, fn ($e) => ! $e['verified']))]);
        }

        return $this->fromChecks('travel_item', $item->id, $method, $checks, $method === 'gmail_refetch' ? 'against the email in Gmail' : 'against the email');
    }

    // ------------------------------------------------------------------ job finder

    public function candidate(JobCandidate $candidate): Verification
    {
        $checks = [];
        $sources = $candidate->sources ?? [];
        foreach ($sources as $i => $s) {
            [$ok, $note] = match ($s['type']) {
                'calendar' => $this->calendarSource($s, $sources[$i]),
                'document' => ($d = Document::query()->find($s['id']))
                    ? (! $s['quote'] || Grounding::quoteFound($d->text, $s['quote']) ? [$s['verified'] ? true : null, $s['verified'] ? 'The quoted words are in the document' : 'Not checked against the document yet'] : [false, 'The quoted words aren’t in the document'])
                    : [false, 'The document was deleted'],
                'travel' => ($t = TravelItem::query()->find($s['id'])) ? ($t->status === 'cancelled' ? [false, 'The booking was cancelled since'] : [true, 'The booking is still there']) : [false, 'The booking was deleted'],
                default => [null, null],
            };
            $sources[$i]['verified'] = $ok === true;
            $sources[$i]['note'] = $note;
            $checks[] = ['what' => $s['label'], 'ok' => $ok, 'note' => $note];
        }
        $candidate->update(['sources' => $sources]);

        return $this->fromChecks('job_candidate', $candidate->id, 'calendar', $checks, 'against your calendar, documents and bookings');
    }

    /**
     * The entry is looked up by its key wherever it is now; if it has moved, the source's dates follow it.
     *
     * @return array{0:?bool,1:string}
     */
    private function calendarSource(array $s, array &$source): array
    {
        $event = JobFinder::event($s['id'], $s['date']);
        if (! $event) {
            return [false, 'No longer in your calendar'];
        }
        if (! empty($s['ai']) && $s['quote'] && ! Grounding::quoteFound($event->text(), (string) $s['quote'])) {
            return [false, 'The entry has changed since'];
        }
        if ($event->day() !== ($s['date'] ?? null)) {
            $source['date'] = $event->day();
            $source['to'] = JobDays::lastDay($event); // an entry ending in the small hours is one day

            return [true, 'Still in your calendar, moved to '.CarbonImmutable::parse($event->day())->format('D j M Y')];
        }

        return [true, 'Still in your calendar'];
    }

    public function calendarLink(CalendarJobLink $link): Verification
    {
        $event = CalendarEvent::query()->where('uid', $link->event_uid)->get()->first(fn (CalendarEvent $e) => $e->key() === $link->event_key);
        $job = $link->work_job_id ? WorkJob::query()->find($link->work_job_id) : null;
        $checks = [['what' => 'Calendar entry', 'ok' => (bool) $event, 'note' => $event ? 'Still in your calendar' : 'No longer in your calendar']];
        if ($event && $link->summary && Grounding::normalise($link->summary) !== Grounding::normalise((string) $event->summary)) {
            $checks[] = ['what' => 'Title', 'ok' => false, 'note' => 'The entry was renamed to “'.mb_substr((string) $event->summary, 0, 80).'”'];
        }
        if ($event && $job) {
            $ok = $job->starts_on->format('Y-m-d') <= CarbonImmutable::parse(JobDays::lastDay($event))->addDay()->format('Y-m-d') && $job->ends_on->format('Y-m-d') >= CarbonImmutable::parse($event->day())->subDay()->format('Y-m-d');
            $checks[] = ['what' => 'Dates', 'ok' => $ok, 'note' => $ok ? 'On the job’s dates' : 'The job’s dates no longer match the entry'];
        }

        return $this->fromChecks('calendar_link', $link->id, 'calendar', $checks, 'against your calendar');
    }

    // ------------------------------------------------------------------ AI suggestions on transactions

    public function transaction(BankTransaction $tx): ?Verification
    {
        $suggested = Allocation::query()->where('bank_transaction_id', $tx->id)->whereNull('confirmed_at')->whereIn('decided_by', ['ai', 'matcher'])->with(['category', 'job'])->get();
        if ($suggested->isEmpty()) {
            Verification::query()->where('subject_type', 'transaction')->where('subject_id', $tx->id)->delete();

            return null;
        }
        $checks = [];
        $date = $tx->effectiveDate();
        foreach ($suggested as $a) {
            $label = Allocation::KINDS[$a->kind] ?? $a->kind;
            if ($a->amount_pence !== 0 && ($a->amount_pence > 0) !== ($tx->amount_pence > 0)) {
                $checks[] = ['what' => $label, 'ok' => false, 'note' => 'The split goes the other way from the payment'];
            }
            if ($a->kind === 'business_income' && $tx->amount_pence < 0) {
                $checks[] = ['what' => $label, 'ok' => false, 'note' => 'Income, but money went out'];
            }
            if (in_array($a->kind, Allocation::NEEDS_CATEGORY, true)) {
                $want = $a->kind === 'business_income' ? 'income' : 'expense';
                $checks[] = ['what' => $label.($a->category ? ': '.$a->category->name : ''), 'ok' => $a->category?->kind === $want, 'note' => $a->category?->kind === $want ? null : 'The category doesn’t fit'];
            }
            if ($a->kind === 'tax_payment') {
                $ok = (bool) preg_match('/HMRC|HM REVENUE|INLAND REVENUE|SELF ASSESSMENT|\bNDDS\b|CUMBERNAULD|SHIPLEY/i', $tx->description.' '.$tx->counterparty);
                $checks[] = ['what' => $label, 'ok' => $ok, 'note' => $ok ? 'The payee is HMRC' : 'The payee doesn’t look like HMRC'];
            }
            if ($a->work_job_id) {
                $job = $a->job;
                $late = $a->kind === 'business_income' ? 120 : 5;
                $ok = $job && $job->status !== 'cancelled' && $date->gte($job->starts_on->copy()->subDays($a->kind === 'business_income' ? 30 : 3)) && $date->lte($job->ends_on->copy()->addDays($late));
                $checks[] = ['what' => 'Job: '.($job?->title ?? 'deleted'), 'ok' => $ok, 'note' => $ok ? 'The payment date fits the job' : ($job?->status === 'cancelled' ? 'The job is cancelled' : 'The payment date doesn’t fit the job’s dates')];
            }
        }
        if (! $checks) {
            $checks[] = ['what' => 'Suggestion', 'ok' => true, 'note' => 'Consistent with the payment'];
        }

        return $this->fromChecks('transaction', $tx->id, 'records', $checks, 'against the payment and your jobs');
    }

    // ------------------------------------------------------------------ scheduled re-checks

    /**
     * Re-check the AI findings checked longest ago (or never). Second reads of scans cost a little, so they are capped.
     *
     * @return array{checked:int, warnings:int}
     */
    public function recheck(int $limit = 80, int $secondReads = 5, bool $refetch = true): array
    {
        $checked = $warnings = 0;
        $stale = now()->subDays(6);
        $due = function (string $type, $query) use ($stale) {
            $fresh = Verification::query()->where('subject_type', $type)->where('checked_at', '>=', $stale)->pluck('subject_id');

            return $query->whereNotIn('id', $fresh)->orderBy('id')->limit(200)->get();
        };
        $per = max(5, intdiv($limit, 5));
        $run = function (callable $check) use (&$checked, &$warnings) {
            try {
                $v = $check();
                $checked += $v ? 1 : 0;
                $warnings += $v && in_array($v->status, Verification::WARN, true) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
            }
        };

        foreach ($due('document', Document::query()->where('status', 'extracted'))->take($per) as $doc) {
            $second = $secondReads > 0 && $doc->text_status !== 'extracted';
            $secondReads -= $second ? 1 : 0;
            $run(fn () => $this->document($doc, $second));
        }
        foreach ($due('travel_item', TravelItem::query()->whereIn('source', ['ai', 'structured_data']))->take($per) as $item) {
            $run(fn () => $this->travelItem($item, $refetch));
        }
        foreach ($due('job_candidate', JobCandidate::query()->where('status', 'open'))->take($per) as $c) {
            $run(fn () => $this->candidate($c));
        }
        foreach ($due('calendar_link', CalendarJobLink::query()->whereNotNull('work_job_id'))->take($per) as $l) {
            $run(fn () => $this->calendarLink($l));
        }
        $txIds = Allocation::query()->whereNull('confirmed_at')->whereIn('decided_by', ['ai', 'matcher'])->distinct()->pluck('bank_transaction_id')->filter();
        foreach ($due('transaction', BankTransaction::query()->whereIn('id', $txIds))->take($per) as $tx) {
            $run(fn () => $this->transaction($tx));
        }

        return ['checked' => $checked, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0:string,1:string} text and status, extracted again from the stored file */
    public static function freshText(Document $doc, string $raw): array
    {
        if ($doc->mime === 'message/rfc822' || str_ends_with(strtolower($doc->original_name), '.eml')) {
            return [DocumentIntake::emailText($raw), 'extracted'];
        }
        $e = TextExtractor::extract(Storage::disk('local')->path($doc->stored_path), $doc->mime, $doc->original_name);

        return [(string) $e['text'], $e['status']];
    }

    private static function totalQuote(string $text, int $minor): ?string
    {
        foreach (preg_split('/\n+/', $text) ?: [] as $line) {
            if (Grounding::amountInQuote($minor, $line)) {
                return $line;
            }
        }

        return null;
    }

    private function fromChecks(string $type, int $id, string $method, array $checks, string $against): Verification
    {
        $ok = count(array_filter($checks, fn ($c) => $c['ok'] === true));
        $bad = count(array_filter($checks, fn ($c) => $c['ok'] === false));
        $status = match (true) {
            ! $checks => 'verified',
            $bad === 0 && $ok > 0 => 'verified',
            $bad === 0 => 'unverifiable',
            $ok === 0 => 'mismatch',
            default => 'partly',
        };
        $summary = match ($status) {
            'verified' => $checks ? "All {$ok} checked {$against}." : "Checked {$against}.",
            'unverifiable' => 'Couldn’t be checked word for word.',
            default => "{$bad} of ".($ok + $bad).' didn’t match '.preg_replace('/^against /', '', $against).'. Look before relying on '.($bad === 1 ? 'it' : 'them').'.',
        };

        return $this->save($type, $id, $status, $method, $checks, $summary);
    }

    private function save(string $type, int $id, string $status, string $method, array $checks, string $summary): Verification
    {
        $previous = Verification::for($type, $id);
        $v = Verification::query()->updateOrCreate(['subject_type' => $type, 'subject_id' => $id], [
            'status' => $status, 'method' => $method, 'checks' => array_slice($checks, 0, 80), 'summary' => mb_substr($summary, 0, 300), 'checked_at' => now(),
        ]);
        if (! $previous || $previous->status !== $status) {
            // Recorded against the thing checked (as its model is named in the audit trail), so it shows in its history.
            AuditLog::record('verification.'.$status, self::AUDIT_SUBJECTS[$type] ?? $type, null,
                ['check' => $type, 'id' => $id, 'method' => $method, 'summary' => $v->summary], null, 'checker', $id);
        }

        return $v;
    }
}
