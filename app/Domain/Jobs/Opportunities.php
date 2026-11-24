<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Grounding;
use App\Domain\Gmail\GmailScanner;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\GmailMessage;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Work you might get, from the emails and documents the app reads: availability checks, holds and pencils, offers
 * and booking confirmations, before there's a job or a PO.
 *
 *  - fromDocument() turns what the document reader found in an email or document into an opportunity, or updates
 *    the one it belongs to (the same email thread, or the same client or venue on the same dates). A later email
 *    moves it forward (asked, held, offered, confirmed), or to "went elsewhere"; it never undoes what you decided.
 *  - refresh() (every night) matches open ones to jobs you've already added and closes those whose dates or
 *    reply-by date have passed with no answer.
 *  - Only you make one a job (accept()), decline it or dismiss it, and you can reopen it.
 *  - poChase() lists the jobs that still have no PO, with who to ask for it and where that came from.
 *
 * Every detail keeps the words it came from, and whether those words were found in the document (a scan can't be
 * checked word for word). Nothing here sends anything, replies to anyone or calls the AI: it works only from what
 * the document reader has already stored.
 *
 * What fromDocument() reads: $document->extraction['opportunity'], stored by DocumentReader beside 'po' and
 * 'new_job'. Each detail is a fact with the words it came from:
 *
 *     'opportunity' => [
 *         'status_hint' => ['value' => 'enquiry'|'held'|'offered'|'confirmed'|'lost', 'quote' => ?string, 'verified' => bool],
 *         'hold_rank'   => ['value' => ?int, 'quote' => ?string, 'verified' => bool],      // 1st or 2nd option
 *         'title'       => ['value' => ?string, 'quote' => ?string, 'verified' => bool],
 *         'client'      => ['value' => ?string, 'quote' => ?string, 'verified' => bool],   // who the work is for
 *         'contact'     => ['name' => ?string, 'email' => ?string, 'quote' => ?string, 'verified' => bool],
 *         'role'        => ['value' => ?string, 'quote' => ?string, 'verified' => bool],
 *         'venue'       => ['value' => ?string, 'quote' => ?string, 'verified' => bool],
 *         'postcode'    => ['value' => ?string, 'quote' => ?string, 'verified' => bool],
 *         'dates'       => [['date' => 'Y-m-d', 'kind' => 'work'|'rig'|'derig'|'travel', 'quote' => ?string, 'verified' => bool], ...],
 *         'rate'        => ['amount_minor' => ?int, 'currency' => ?string, 'unit' => 'day'|'job'|'hour'|null, 'quote' => ?string, 'verified' => bool],
 *         'reply_by'    => ['value' => ?'Y-m-d', 'quote' => ?string, 'verified' => bool],
 *         'po_number'   => ['value' => ?string, 'quote' => ?string, 'verified' => bool],
 *     ]
 *
 * Any detail may be null or left out. A plain value in place of a fact ('title' => 'Acme Awards') is taken as a
 * detail with no quote, so unchecked. Every quote is checked again here against the document's own text (Grounding),
 * and so is the value inside it (the date, amount, client, venue, postcode, PO number or email address must be in the
 * quoted words): a detail is checked only when both pass and the reader didn't say otherwise.
 */
final class Opportunities
{
    /** The details an update can change, as recorded in the history. */
    private const TRACKED = [
        'status', 'hold_rank', 'title', 'client_id', 'client_name', 'contact_name', 'contact_email', 'role', 'venue', 'postcode',
        'starts_on', 'ends_on', 'dates', 'rate_minor', 'rate_currency', 'rate_unit', 'reply_by', 'po_number', 'work_job_id',
        'confidence', 'thread_key', 'decided_at', 'decided_by', 'note', 'rejected_job_ids', 'found_by', 'owner_fields',
    ];

    /** Words that say nothing about which job an email is about, left out when comparing subjects and titles. */
    private const GENERIC = [
        're', 'fw', 'fwd', 'aw', 'wg', 'tr', 'the', 'and', 'for', 'with', 'from', 'your', 'you', 'are', 'availability', 'available',
        'avail', 'hold', 'pencil', 'option', 'booking', 'booked', 'confirmation', 'confirmed', 'confirm', 'offer', 'enquiry', 'inquiry',
        'job', 'work', 'crew', 'crewing', 'request', 'check', 'dates', 'date', 'query', 'update', 'free', 'question', 'quick',
        'urgent', 'external', 'please', 'thanks', 'event', 'events', 'show', 'day', 'days', 'ltd', 'limited',
    ];

    public function __construct(private JobCreator $creator) {}

    // ---------------------------------------------------------------- from documents

    /**
     * Create or update the opportunity a read document describes. Null when its reading found no opportunity, or
     * nothing to go on: a reply that only says where things stand ("we've gone with someone else") updates the
     * opportunity of its thread, but starts none without a date, client or title.
     */
    public function fromDocument(Document $document): ?Opportunity
    {
        $block = $document->extraction['opportunity'] ?? null;
        if (! is_array($block) || $document->status === 'held') {
            return null;
        }
        $top = $document->parent_id ? ($document->parent ?? $document) : $document;
        $facts = $this->facts($document, $top, $block);
        $usable = $facts['dates'] || $facts['client'] !== null || $facts['title'] !== null;
        if (! $usable && $facts['status'] === null) {
            return null;
        }
        $seenAt = CarbonImmutable::instance($top->received_at ?? $document->received_at ?? now());
        $keys = self::threadKeys($top);

        return DB::transaction(function () use ($document, $top, $facts, $seenAt, $keys, $usable) {
            $found = $this->match($document, $top, $facts, $keys);
            if (! $found && (! $usable || (! $facts['dates'] && $facts['po_number'] !== null))) {
                // Nothing to go on, or a PO or accounts email for work it can't place (no dates): that's for the PO
                // and the job, not a second opportunity for one booking.
                return null;
            }
            $opportunity = $found
                ? $this->merge($found, $facts, $document, $top, $seenAt, $keys)
                : $this->createFrom($facts, $document, $top, $seenAt, $keys);
            if ($opportunity->isOpen() || $opportunity->status === 'expired') {
                $this->linkToJob($opportunity);
            }

            return $opportunity->fresh();
        });
    }

    /**
     * Tell the app about work yourself ("Acme asked if I'm free on 12 March"): from the assistant, Telegram or the
     * opportunities page. Telling it the same thing twice gives the same opportunity.
     *
     * @param  array{title?: ?string, client_name?: ?string, client_id?: ?int, contact_name?: ?string, contact_email?: ?string,
     *     role?: ?string, venue?: ?string, postcode?: ?string, starts_on?: ?string, ends_on?: ?string, dates?: list<array{date: string, kind?: string}>,
     *     rate?: ?array{amount_minor: int, currency?: ?string, unit?: ?string}, reply_by?: ?string, po_number?: ?string,
     *     status?: ?string, hold_rank?: ?int, note?: ?string}  $fields
     * @param  string|null  $words  your words it came from (in the assistant or Telegram), kept in its history
     */
    public function record(array $fields, string $source = 'manual', string $by = 'you', ?string $words = null): Opportunity
    {
        self::owner($by);
        $source = in_array($source, Opportunity::SOURCES, true) ? $source : 'manual';
        $status = $fields['status'] ?? 'enquiry';
        if (! isset(Opportunity::STAGES[$status])) {
            throw new InvalidArgumentException('Say whether you were asked, held, offered or booked.');
        }
        $dates = self::cleanDates($fields['dates'] ?? null, $fields['starts_on'] ?? null, $fields['ends_on'] ?? null);
        $clientName = self::text($fields['client_name'] ?? null, 120);
        $clientId = isset($fields['client_id']) ? (int) $fields['client_id'] : null;
        if ($clientId) {
            $clientName = Client::query()->whereKey($clientId)->value('name') ?? throw new InvalidArgumentException('That client isn’t in your list.');
        }
        $title = self::text($fields['title'] ?? null, 150) ?? ($clientName ? 'Work for '.$clientName : null);
        if ($title === null && ! $dates) {
            throw new InvalidArgumentException('Give at least a title, a client or the dates.');
        }
        $title ??= 'Possible work';
        $rate = self::cleanRate($fields['rate'] ?? null);
        $replyBy = isset($fields['reply_by']) && $fields['reply_by'] ? self::cleanDates(null, $fields['reply_by'], null)[0]['date'] : null;
        $fingerprint = sha1('you|'.$source.'|'.JobCreator::clientKey($clientName).'|'.($dates[0]['date'] ?? '').'|'.Grounding::normalise($title));
        if ($existing = Opportunity::query()->where('fingerprint', $fingerprint)->first()) {
            return $existing;
        }

        $opportunity = Opportunity::query()->create([
            'status' => $status, 'hold_rank' => isset($fields['hold_rank']) ? max(1, min(9, (int) $fields['hold_rank'])) : null,
            'title' => $title, 'client_id' => $clientId ?: self::clientIdFor($clientName), 'client_name' => $clientName,
            'contact_name' => self::text($fields['contact_name'] ?? null, 120), 'contact_email' => self::email($fields['contact_email'] ?? null),
            'role' => self::text($fields['role'] ?? null, 120), 'venue' => self::text($fields['venue'] ?? null, 200),
            'postcode' => ($p = self::text($fields['postcode'] ?? null, 10)) ? strtoupper($p) : null,
            'starts_on' => $dates[0]['date'] ?? null, 'ends_on' => $dates ? $dates[count($dates) - 1]['date'] : null, 'dates' => $dates ?: null,
            'rate_minor' => $rate['amount_minor'] ?? null, 'rate_currency' => $rate['currency'] ?? null, 'rate_unit' => $rate['unit'] ?? null,
            'reply_by' => $replyBy, 'po_number' => self::text($fields['po_number'] ?? null, 100),
            'source' => $source, 'found_by' => 'you', 'confidence' => 100, 'evidence' => [],
            'fingerprint' => $fingerprint, 'first_seen_at' => now(), 'last_seen_at' => now(), 'note' => self::text($fields['note'] ?? null, 2000),
            // Every detail you gave is yours: a later email about it adds its words, never changes yours.
            'owner_fields' => array_values(array_keys(array_filter([
                'title' => $title, 'client' => $clientName, 'contact' => ($fields['contact_name'] ?? null) ?: ($fields['contact_email'] ?? null),
                'role' => $fields['role'] ?? null, 'venue' => $fields['venue'] ?? null, 'postcode' => $fields['postcode'] ?? null, 'dates' => $dates,
                'rate' => $rate, 'reply_by' => $replyBy, 'po_number' => $fields['po_number'] ?? null, 'hold_rank' => $fields['hold_rank'] ?? null,
            ]))),
        ]);
        AuditLog::record('opportunity.recorded', $opportunity, null, self::snapshot($opportunity),
            'You told the app about it'.($words !== null && trim($words) !== '' ? ': “'.mb_substr(trim($words), 0, 300).'”' : ''), $by);
        if ($opportunity->isOpen()) {
            $this->linkToJob($opportunity);
        }

        return $opportunity->fresh();
    }

    // ---------------------------------------------------------------- nightly

    /**
     * Every night: take in any read document whose opportunity wasn't taken in yet, match open opportunities to jobs
     * you've added since, and close the ones whose dates or reply-by date have passed with no answer. Opportunities
     * confirmed with no job are counted as needing you (Opportunity::needsJob()).
     *
     * @return array{read: int, linked: int, expired: int, needs_job: int, open: int}
     */
    public function refresh(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['read' => 0, 'linked' => 0, 'expired' => 0, 'needs_job' => 0, 'open' => 0];

        // 1. Documents read since the last run (a reading that finished while this was off, or a re-read).
        $since = Setting::get('opportunities_refreshed_at');
        $started = now();
        $lastAudit = (int) AuditEvent::query()->max('id');
        Document::query()->where('status', 'extracted')->whereNotNull('extraction')
            ->when($since, fn ($q) => $q->where('extracted_at', '>=', CarbonImmutable::parse((string) $since)->subHour()))
            ->select(['id'])->chunkById(200, function ($chunk) use (&$counts) {
                foreach (Document::query()->whereIn('id', $chunk->pluck('id'))->get() as $doc) {
                    if (! is_array($doc->extraction['opportunity'] ?? null)) {
                        continue;
                    }
                    try {
                        $this->fromDocument($doc);
                        $counts['read']++;
                    } catch (Throwable $e) {
                        report($e); // one bad document doesn't stop the rest
                    }
                }
            });
        // A document read in step 1 can match its opportunity to a job straight away: count those matches too.
        $counts['linked'] = AuditEvent::query()->where('id', '>', $lastAudit)->where('action', 'opportunity.linked_to_job')->count();

        // 2. Jobs you've added since: the opportunity was that job.
        foreach (Opportunity::query()->whereNull('work_job_id')->whereIn('status', [...Opportunity::OPEN, 'expired'])->get() as $o) {
            if ($this->linkToJob($o)) {
                $counts['linked']++;
            }
        }

        // 3. Passed with no answer: the dates or the reply-by date have gone, or nothing has been heard about an
        // undated one for 60 days. A booking confirmed with no job isn't closed: it needs you. One you reopened isn't
        // closed again for a date that had already passed when you reopened it.
        foreach (Opportunity::query()->whereNull('work_job_id')->whereIn('status', ['enquiry', 'held', 'offered'])->get() as $o) {
            $since = $o->reopened_at?->format('Y-m-d');
            $gone = fn (?\DateTimeInterface $d) => $d !== null && $d->format('Y-m-d') < $today->format('Y-m-d') && ($since === null || $d->format('Y-m-d') >= $since);
            $why = match (true) {
                $gone($o->ends_on) => 'Its dates have passed with no booking',
                $gone($o->reply_by) => 'The date to reply by has passed',
                $o->starts_on === null && $o->reply_by === null && $o->last_seen_at !== null && $o->last_seen_at->lt($today->subDays(60))
                    && ($o->reopened_at === null || $o->reopened_at->lt($today->subDays(60))) => 'Nothing heard for 60 days',
                default => null,
            };
            if ($why === null) {
                continue;
            }
            $before = self::snapshot($o);
            $o->update(['status' => 'expired']);
            AuditLog::record('opportunity.expired', $o, $before, self::snapshot($o), $why, 'matcher');
            $counts['expired']++;
        }

        $counts['needs_job'] = Opportunity::query()->needsJob()->count();
        $counts['open'] = Opportunity::query()->open()->count();
        Setting::put('opportunities_refreshed_at', $started->toIso8601String());
        Setting::put('opportunities_last_run', $counts + ['at' => now()->toIso8601String()]);
        AuditLog::record('opportunities.refreshed', 'Opportunities', null, $counts, null, 'matcher');

        return $counts;
    }

    // ---------------------------------------------------------------- your decisions

    /**
     * Make it a job: its details (with your changes in $fields, which win), its days with their kinds, and its emails
     * and documents linked to the new job. Then everything else that belongs to the job is searched for.
     *
     * @param  array  $fields  any of JobCreator::create()'s fields; a field given as null clears it
     * @param  array{linked: int, missed: list<int>}|null  $links  set to how many documents were linked, and those left
     *                                                             on another job
     *
     * @throws InvalidArgumentException when it's already a job, or a detail is missing or wrong
     */
    public function accept(Opportunity $opportunity, array $fields, string $by = 'you', ?array &$links = null): WorkJob
    {
        self::owner($by);

        return DB::transaction(function () use ($opportunity, $fields, $by, &$links) {
            $o = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            if ($o->work_job_id && ($job = WorkJob::query()->find($o->work_job_id))) {
                throw new InvalidArgumentException('This is already a job: '.$job->title.'.');
            }

            // A rate not found word for word (its amount, currency and unit) goes in the notes, never as the job's fee.
            $rateChecked = ! in_array('rate', $o->unverifiedFields(), true);
            $data = [
                'title' => $o->title, 'client_id' => $o->client_id, 'client_name' => $o->client_name,
                'dates' => $o->dates ?: null, 'starts_on' => $o->starts_on?->format('Y-m-d'), 'ends_on' => $o->ends_on?->format('Y-m-d'),
                'venue' => $o->venue, 'postcode' => $o->postcode, 'po_number' => $o->po_number,
                'rate' => $o->rate_minor && $rateChecked ? ['amount_minor' => $o->rate_minor, 'currency' => $o->rate_currency ?? 'GBP', 'unit' => $o->rate_unit ?? 'job'] : null,
                'note' => self::jobNote($o, ! $rateChecked),
            ];
            foreach (['venue' => 'location', 'note' => 'notes', 'client_name' => 'client'] as $ours => $alias) {
                if (array_key_exists($alias, $fields) && ! array_key_exists($ours, $fields)) {
                    $fields[$ours] = $fields[$alias];
                    unset($fields[$alias]);
                }
            }
            if ((array_key_exists('starts_on', $fields) || array_key_exists('ends_on', $fields)) && ! array_key_exists('dates', $fields)) {
                $data['dates'] = null; // you gave the first and last dates: every day between
            }
            if (array_key_exists('client_name', $fields) && ! array_key_exists('client_id', $fields)) {
                $data['client_id'] = null;
            }
            if (array_key_exists('fee_pence', $fields) && ! array_key_exists('rate', $fields)) {
                $data['rate'] = null;
            }
            $data = $fields + $data;
            $last = is_array($data['dates']) && $data['dates'] ? max(array_map(fn ($d) => is_array($d) ? (string) ($d['date'] ?? '') : (string) $d, $data['dates']))
                : (string) ($data['ends_on'] ?? $data['starts_on'] ?? '');
            $data['status'] ??= $last !== '' && $last < today()->format('Y-m-d') ? 'done' : 'booked'; // work you've already done

            $job = $this->creator->create($data, $by, $o->evidence ?? [], 'From an opportunity: '.mb_substr($o->title, 0, 120));
            $links = $this->linkDocuments($o, $job);

            $before = self::snapshot($o);
            $o->update(['status' => 'confirmed', 'work_job_id' => $job->id, 'decided_at' => now(), 'decided_by' => $by]);
            AuditLog::record('opportunity.accepted', $o, $before, self::snapshot($o) + ['documents_linked' => $links['linked']], null, $by);

            return $job;
        });
    }

    /** You said no (not free, or not interested). Never changed by a later email. */
    public function decline(Opportunity $opportunity, ?string $note = null, string $by = 'you'): Opportunity
    {
        return $this->decide($opportunity, 'declined', $note, $by);
    }

    /** It isn't work (a newsletter, someone else's job). Never changed by a later email. */
    public function dismiss(Opportunity $opportunity, ?string $note = null, string $by = 'you'): Opportunity
    {
        return $this->decide($opportunity, 'dismissed', $note, $by);
    }

    /**
     * Back to open: undoes a decline, a dismissal, or a close by the rules (passed, went elsewhere), at the furthest
     * stage its emails reached. One the rules matched to a job is taken off that job, which it's never matched to
     * again. One you made a job stays a job (cancel the job instead).
     */
    public function reopen(Opportunity $opportunity, string $by = 'you'): Opportunity
    {
        self::owner($by);

        return DB::transaction(function () use ($opportunity, $by) {
            $o = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            if ($o->isAccepted()) {
                throw new InvalidArgumentException('You’ve made this a job. Cancel the job if it isn’t going ahead.');
            }
            if ($o->isOpen() && ! $o->decided_at) {
                return $o;
            }
            $before = self::snapshot($o);
            $changes = ['status' => self::furthestStage($o), 'decided_at' => null, 'decided_by' => null, 'reopened_at' => now()];
            if ($o->work_job_id) {
                $changes['rejected_job_ids'] = array_values(array_unique([...($o->rejected_job_ids ?? []), $o->work_job_id]));
                $changes['work_job_id'] = null;
            }
            $o->update($changes);
            AuditLog::record('opportunity.reopened', $o, $before, self::snapshot($o), null, $by);

            return $o;
        });
    }

    /**
     * You say the job the rules matched it to is the one: it's confirmed as that job, as yours. Its emails and
     * documents are linked to the job.
     */
    public function confirmLink(Opportunity $opportunity, string $by = 'you'): Opportunity
    {
        self::owner($by);

        return DB::transaction(function () use ($opportunity, $by) {
            $o = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            $job = $o->work_job_id ? WorkJob::query()->find($o->work_job_id) : null;
            if (! $job) {
                throw new InvalidArgumentException('It isn’t matched to a job.');
            }
            if ($o->isAccepted()) {
                return $o;
            }
            $before = self::snapshot($o);
            $o->update(['status' => 'confirmed', 'decided_at' => now(), 'decided_by' => $by]);
            $links = $this->linkDocuments($o, $job);
            AuditLog::record('opportunity.link_confirmed', $o, $before, self::snapshot($o) + ['documents_linked' => $links['linked']], 'It’s '.$job->title, $by);

            return $o;
        });
    }

    // ---------------------------------------------------------------- PO chase

    /** Documents that come from the client, best first for who to ask: the offer, booking or PO, then the job's paperwork, then plain emails. */
    private const CLIENT_KINDS = ['po' => 0, 'job_invite' => 0, 'opportunity' => 0, 'production_pack' => 1, 'call_sheet' => 1, 'email' => 2];

    /**
     * Jobs with no PO yet that it's worth asking for one: not cancelled, not marked "no PO needed", and either
     * starting within $days days (or under way), or done and not invoiced (within the last two years). Each comes
     * with the best person to ask and where that came from: the opportunity it was made from, then the emails and
     * documents on the job that come from the client (never a hotel, a train company or a supplier), then the
     * client's other work. Soonest first. $jobIds narrows it to those jobs (a job's page, the to-do list).
     *
     * @param  list<int>|null  $jobIds
     * @return list<array{job_id: int, title: string, client: ?string, starts_on: string, ends_on: string, status: string,
     *     why: string, days_until: int, invoiced: bool, opportunity_id: ?int,
     *     contact: ?array{name: ?string, email: string, from: string, document_id: ?int, opportunity_id: ?int, verified: bool},
     *     evidence: list<array{type: string, id: int, field?: string, quote: ?string, verified: bool}>}>
     */
    public function poChase(?CarbonImmutable $today = null, int $days = 60, ?array $jobIds = null): array
    {
        $today ??= CarbonImmutable::today();
        $jobs = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')->where('po_not_required', false)
            ->when($jobIds !== null, fn ($q) => $q->whereIn('id', $jobIds ?: [0]))
            ->whereDate('starts_on', '<=', $today->addDays($days)->format('Y-m-d'))
            ->whereDate('ends_on', '>=', $today->subYears(2)->format('Y-m-d'))
            ->orderBy('starts_on')->orderBy('id')->get();
        $status = PoStatus::forJobs($jobs);
        $jobs = $jobs->filter(function (WorkJob $job) use ($status, $today) {
            $s = $status[$job->id];

            return $s['missing'] && ! ($job->ends_on->format('Y-m-d') < $today->format('Y-m-d') && $s['invoice']);
        })->values();
        if ($jobs->isEmpty()) {
            return [];
        }
        $contacts = $this->contactsFor($jobs);

        $out = [];
        foreach ($jobs as $job) {
            $s = $status[$job->id];
            $done = $job->ends_on->format('Y-m-d') < $today->format('Y-m-d');
            $until = (int) $today->diffInDays(CarbonImmutable::parse($job->starts_on->format('Y-m-d')), false);
            [$contact, $evidence, $opportunity] = $contacts[$job->id];
            if ($opportunity) {
                foreach (array_slice(array_merge($opportunity->evidenceFor('status'), $opportunity->evidenceFor('dates')), 0, 3) as $e) {
                    $evidence[] = $e;
                }
            }
            $out[] = [
                'job_id' => $job->id, 'title' => $job->title, 'client' => $job->client?->name,
                'starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d'), 'status' => $job->status,
                'why' => match (true) {
                    $done => 'Done and not invoiced, with no PO',
                    $until > 0 => 'Starts in '.$until.' '.($until === 1 ? 'day' : 'days').' with no PO',
                    default => 'Under way with no PO',
                },
                'days_until' => $until, 'invoiced' => $s['invoice'] !== null, 'opportunity_id' => $opportunity?->id,
                'contact' => $contact, 'evidence' => array_values($evidence),
            ];
        }

        return $out;
    }

    /**
     * Who to ask for each job's PO, worked out for all the jobs together in a few queries: the contact on the
     * opportunity it came from (one you made a job, or one for the same client); the sender of the job's emails and
     * documents that come from the client (an offer, booking, PO, crew pack or call sheet; a plain email only from
     * the client's own company), never a hotel or travel company, you, or a machine; a contact one of those names; then
     * whoever sent the client's other work. "verified" says whether the words it came from were found in the source.
     *
     * @param  Collection<int, WorkJob>  $jobs
     * @return array<int, array{0: ?array, 1: list<array>, 2: ?Opportunity}> by job id: the contact, its evidence, and the opportunity used
     */
    private function contactsFor(Collection $jobs): array
    {
        $own = self::ownAddresses();
        $ids = $jobs->pluck('id')->all();
        $clientIds = $jobs->pluck('client_id')->filter()->unique()->values()->all();
        $linked = Opportunity::query()->whereIn('work_job_id', $ids)->orderByDesc('last_seen_at')->get()->groupBy('work_job_id');
        $clientOpps = $clientIds ? Opportunity::query()->whereIn('client_id', $clientIds)->whereNotNull('contact_email')->orderByDesc('last_seen_at')->get()->groupBy('client_id') : collect();
        $clientOf = $clientIds ? WorkJob::query()->whereIn('client_id', $clientIds)->pluck('client_id', 'id') : collect();
        $docs = Document::query()->whereIn('work_job_id', array_values(array_unique([...$ids, ...$clientOf->keys()->all()])))->whereNull('parent_id')
            ->whereIn('kind', array_keys(self::CLIENT_KINDS))->whereNotNull('email_from')
            ->orderByDesc('received_at')->orderByDesc('id')->get(['id', 'work_job_id', 'kind', 'email_from', 'received_at', 'text_status']);
        $facts = DocumentFact::query()->whereIn('work_job_id', $ids)->whereIn('field', ['contact', 'invoice_to'])
            ->whereHas('document', fn ($q) => $q->whereIn('kind', array_keys(self::CLIENT_KINDS)))->orderByDesc('id')->get()->groupBy('work_job_id');

        $address = fn (?string $from) => preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $from, $m) ? strtolower($m[0]) : null;
        $domainOf = fn (?string $email) => $email && str_contains($email, '@') ? substr((string) strrchr($email, '@'), 1) : null;
        $usable = fn (?string $email) => $email !== null && ! isset($own[$email]) && ! self::machine($email) && GmailScanner::travelSearchFor($email) === null;
        // The client's own company: where its opportunities' contacts and its offers, bookings and POs come from.
        $known = [];
        foreach ($clientOpps as $clientId => $list) {
            foreach ($list as $o) {
                if (($d = $domainOf(mb_strtolower((string) $o->contact_email))) && ! in_array($d, OpportunityRules::FREE_MAIL, true)) {
                    $known[$clientId][$d] = true;
                }
            }
        }
        foreach ($docs as $doc) {
            $email = $address($doc->email_from);
            $clientId = $clientOf[$doc->work_job_id] ?? null;
            if ($clientId && self::CLIENT_KINDS[$doc->kind] === 0 && $usable($email) && ($d = $domainOf($email)) && ! in_array($d, OpportunityRules::FREE_MAIL, true)) {
                $known[$clientId][$d] = true;
            }
        }
        $docsByJob = $docs->groupBy('work_job_id');
        // The client's company: a domain its work came from, or one carrying its name (acme-events.example for Acme Events).
        $names = Client::query()->whereIn('id', $clientIds ?: [0])->pluck('name', 'id')->map(fn ($n) => JobCreator::clientKey($n));
        $theirs = function (?string $email, ?int $clientId) use ($known, $names, $domainOf): bool {
            $domain = $domainOf($email);
            if ($domain === null || $clientId === null) {
                return false;
            }
            $key = $names[$clientId] ?? '';

            return isset($known[$clientId][$domain])
                || (mb_strlen($key) >= 4 && ! in_array($domain, OpportunityRules::FREE_MAIL, true) && str_contains(Grounding::normalise($domain), $key));
        };
        // The documents that could say who to ask, best first: from the client's company, then by kind, then newest.
        $ranked = function (Collection $list, ?int $clientId) use ($address, $theirs, $own): Collection {
            return $list->map(function (Document $d) use ($address, $theirs, $own, $clientId) {
                $email = $address($d->email_from);

                return ['doc' => $d, 'theirs' => $theirs($email, $clientId), 'rank' => self::CLIENT_KINDS[$d->kind], 'yours' => $email === null || isset($own[$email])];
            })
                // A plain email counts only from the client's company; one you forwarded may hold the original sender.
                ->filter(fn ($c) => $c['rank'] < 2 || $c['theirs'] || $c['yours'])
                ->sortBy(fn ($c) => [$c['theirs'] ? 0 : 1, $c['rank'], $c['yours'] ? 1 : 0])->values()->take(3);
        };

        $plans = [];
        $wanted = [];
        foreach ($jobs as $job) {
            $plans[$job->id] = $ranked($docsByJob[$job->id] ?? collect(), $job->client_id);
            $wanted = [...$wanted, ...$plans[$job->id]->pluck('doc.id')->all()];
        }
        $byClient = [];
        foreach (array_unique($clientIds) as $clientId) {
            $others = $docs->filter(fn (Document $d) => ($clientOf[$d->work_job_id] ?? null) === $clientId && self::CLIENT_KINDS[$d->kind] < 2);
            $byClient[$clientId] = $ranked($others, $clientId);
            $wanted = [...$wanted, ...$byClient[$clientId]->pluck('doc.id')->all()];
        }
        // Only the documents that may be used are read, and only their start (where the sender's lines are).
        $texts = $wanted ? Document::query()->whereIn('id', array_values(array_unique($wanted)))
            ->selectRaw('id, SUBSTR(text, 1, 20000) AS text')->pluck('text', 'id') : collect();
        // The sender, read from the document's start: for one you forwarded, the original sender, who for a plain
        // email must be at the client's company too.
        $sender = function (array $c, ?int $clientId) use ($texts, $own, $usable, $theirs): ?array {
            $d = $c['doc'];
            $d->setAttribute('text', $texts[$d->id] ?? null);
            $s = self::senderOf($d, $own);
            if (! $s || ! $usable($s['email']) || ($c['rank'] === 2 && ! $theirs($s['email'], $clientId))) {
                return null;
            }

            return $s;
        };

        $out = [];
        foreach ($jobs as $job) {
            $clientKey = JobCreator::clientKey($job->client?->name);
            $opportunity = ($linked[$job->id] ?? collect())->first(fn (Opportunity $o) => $o->isAccepted()
                || ($job->client_id && $o->client_id === $job->client_id) || ($clientKey !== '' && JobCreator::clientKey($o->client_name) === $clientKey));
            if ($opportunity?->contact_email && $usable(mb_strtolower($opportunity->contact_email))) {
                $out[$job->id] = [self::contact($opportunity->contact_name, $opportunity->contact_email, 'opportunity', null, $opportunity->id,
                    $opportunity->ownerSet('contact') || ! in_array('contact', $opportunity->unverifiedFields(), true)), $opportunity->evidenceFor('contact'), $opportunity];

                continue;
            }
            foreach ($plans[$job->id] as $c) {
                if ($s = $sender($c, $job->client_id)) {
                    $out[$job->id] = [self::contact($s['name'], $s['email'], 'job_document', $c['doc']->id, null, $s['verified']),
                        [['type' => 'document', 'id' => $c['doc']->id, 'field' => 'contact', 'quote' => $s['quote'], 'verified' => $s['verified']]], $opportunity];

                    continue 2;
                }
            }
            foreach ($facts[$job->id] ?? [] as $fact) {
                $email = $address($fact->value);
                if ($usable($email)) {
                    $name = trim(preg_replace('/\s+/', ' ', str_ireplace($email, '', $fact->value)) ?? '', " \t,;:-()<>");
                    $out[$job->id] = [self::contact($name !== '' ? mb_substr($name, 0, 120) : null, $email, 'job_document', $fact->document_id, null, (bool) $fact->verified),
                        [['type' => 'document', 'id' => $fact->document_id, 'field' => 'contact', 'quote' => $fact->quote, 'verified' => (bool) $fact->verified]], $opportunity];

                    continue 2;
                }
            }
            if ($job->client_id) {
                $other = ($clientOpps[$job->client_id] ?? collect())->first(fn (Opportunity $o) => $usable(mb_strtolower((string) $o->contact_email)));
                if ($other) {
                    $out[$job->id] = [self::contact($other->contact_name, $other->contact_email, 'client', null, $other->id,
                        $other->ownerSet('contact') || ! in_array('contact', $other->unverifiedFields(), true)), $other->evidenceFor('contact'), $opportunity];

                    continue;
                }
                foreach ($byClient[$job->client_id] ?? [] as $c) {
                    if ($c['doc']->work_job_id !== $job->id && ($s = $sender($c, $job->client_id))) {
                        $out[$job->id] = [self::contact($s['name'], $s['email'], 'client', $c['doc']->id, null, $s['verified']),
                            [['type' => 'document', 'id' => $c['doc']->id, 'field' => 'contact', 'quote' => $s['quote'], 'verified' => $s['verified']]], $opportunity];

                        continue 2;
                    }
                }
            }
            $out[$job->id] = [null, [], $opportunity];
        }

        return $out;
    }

    /** @return array{name: ?string, email: string, from: string, document_id: ?int, opportunity_id: ?int, verified: bool} */
    private static function contact(?string $name, string $email, string $from, ?int $documentId, ?int $opportunityId, bool $verified): array
    {
        return ['name' => $name, 'email' => mb_strtolower($email), 'from' => $from, 'document_id' => $documentId, 'opportunity_id' => $opportunityId, 'verified' => $verified];
    }

    // ---------------------------------------------------------------- reading the document's block

    /**
     * The block's details, tidied and checked against the document's text, with an evidence entry for each.
     *
     * @return array{status: ?string, hold_rank: ?int, title: ?string, client: ?string, contact_name: ?string, contact_email: ?string,
     *     role: ?string, venue: ?string, postcode: ?string, reply_by: ?string, po_number: ?string,
     *     dates: list<array{date: string, kind: string}>, rate: ?array{amount_minor: int, currency: string, unit: string},
     *     verified: array<string, bool>, evidence: list<array>}
     */
    private function facts(Document $document, Document $top, array $block): array
    {
        $text = $document->text;
        $textStatus = (string) $document->text_status;
        $out = ['status' => null, 'hold_rank' => null, 'title' => null, 'client' => null, 'contact_name' => null, 'contact_email' => null,
            'role' => null, 'venue' => null, 'postcode' => null, 'reply_by' => null, 'po_number' => null, 'dates' => [], 'rate' => null,
            'verified' => [], 'evidence' => []];
        $note = function (string $field, string $value, ?string $quote, bool $ok) use (&$out, $document): void {
            $out['evidence'][] = [
                'type' => 'document', 'id' => $document->id, 'field' => $field, 'value' => mb_substr($value, 0, 200),
                'quote' => $quote !== null ? mb_substr($quote, 0, 400) : null, 'verified' => $ok,
            ];
        };
        // The quote must be in the document, the value in the quote, and the reader mustn't have said otherwise.
        $grounded = function (?string $quote, mixed $flag, ?callable $inQuote = null) use ($text, $textStatus): bool {
            if ($quote === null || trim($quote) === '' || $flag === false) {
                return false;
            }

            return Grounding::check($text, $textStatus, $quote)['verified'] && ($inQuote === null || $inQuote($quote));
        };
        $contains = fn (?string $value) => fn (string $quote) => self::inQuote($value, $quote);

        // What stage it's at: in an email, words from its newest message (not the earlier ones it quotes) that say
        // that stage ("pencil" for a hold, "confirmed" or "booked" for a booking, "cancelled" for one that's off).
        [$hint, $quote, $flag] = self::fact($block['status_hint'] ?? $block['status'] ?? null);
        $hint = is_string($hint) ? strtolower(trim($hint)) : null;
        $quote ??= is_string($block['status_quote'] ?? null) ? $block['status_quote'] : null;
        if ($hint !== null && (isset(Opportunity::STAGES[$hint]) || $hint === 'lost')) {
            $out['status'] = $hint;
            $newest = self::newestOf($document);
            $ok = $grounded($quote, $flag, fn (string $q) => self::stageInQuote($hint, $q) && ($newest === null || Grounding::quoteFound($newest, $q)));
            $out['verified']['status'] = $ok;
            $note('status', $hint, $quote, $ok);
        }
        [$rank, $quote, $flag] = self::fact($block['hold_rank'] ?? null);
        if (is_numeric($rank) && (int) $rank >= 1 && (int) $rank <= 9) {
            $out['hold_rank'] = (int) $rank;
            $ok = $grounded($quote, $flag, fn (string $q) => self::rankInQuote((int) $rank, $q));
            $out['verified']['hold_rank'] = $ok;
            $note('hold_rank', (string) $rank, $quote, $ok);
        }

        // The words that say what and where it is.
        foreach (['title' => [150, false], 'client' => [120, true], 'role' => [120, false], 'venue' => [200, true], 'postcode' => [10, true], 'po_number' => [100, true]] as $field => [$max, $check]) {
            [$value, $quote, $flag] = self::fact($block[$field] ?? null);
            $value = self::text(is_scalar($value) ? (string) $value : null, $max);
            if ($value === null) {
                continue;
            }
            if ($field === 'postcode') {
                $value = strtoupper($value);
            }
            $out[$field] = $value;
            $ok = $grounded($quote, $flag, $check ? $contains($field === 'client' ? preg_replace('/\b(ltd|limited|plc|llp|the)\b\.?/i', ' ', $value) : $value) : null);
            $out['verified'][$field] = $ok;
            $note($field, $value, $quote, $ok);
        }

        // Who's asking: the contact the reader found, or else the email's sender (not you).
        $contact = is_array($block['contact'] ?? null) ? $block['contact'] : null;
        $name = self::text(is_string($contact['name'] ?? null) ? $contact['name'] : (is_string($contact['value'] ?? null) ? $contact['value'] : null), 120);
        $email = self::email($contact['email'] ?? null);
        if ($email !== null && isset(self::ownAddresses()[$email])) {
            [$name, $email] = [null, null]; // that's you
        }
        if ($name !== null || $email !== null) {
            $quote = is_string($contact['quote'] ?? null) ? $contact['quote'] : null;
            $ok = $grounded($quote, $contact['verified'] ?? null, fn (string $q) => ($email === null || self::inQuote($email, $q)) && ($name === null || self::inQuote($name, $q)));
            [$out['contact_name'], $out['contact_email'], $out['verified']['contact']] = [$name, $email, $ok];
            $note('contact', trim(($name ?? '').($email ? ' <'.$email.'>' : '')), $quote, $ok);
        } elseif ($sender = self::senderOf($document->parent_id ? $top : $document, self::ownAddresses())) {
            [$out['contact_name'], $out['contact_email'], $out['verified']['contact']] = [$sender['name'], $sender['email'], $sender['verified']];
            $out['evidence'][] = ['type' => 'document', 'id' => $top->id, 'field' => 'contact', 'value' => trim(($sender['name'] ?? '').' <'.$sender['email'].'>'),
                'quote' => mb_substr($sender['quote'], 0, 400), 'verified' => $sender['verified']];
        }

        // Its days, each checked in its own quote ("12-14 March" covers the 13th).
        $all = true;
        foreach (is_array($block['dates'] ?? null) ? $block['dates'] : [] as $d) {
            $date = self::ymd(is_array($d) ? ($d['date'] ?? null) : $d);
            if ($date === null) {
                continue;
            }
            $kind = is_array($d) ? strtolower((string) ($d['kind'] ?? 'work')) : 'work';
            $kind = in_array($kind, Opportunity::DAY_KINDS, true) ? $kind : 'work';
            $quote = is_array($d) && is_string($d['quote'] ?? null) ? $d['quote'] : null;
            $ok = $grounded($quote, is_array($d) ? ($d['verified'] ?? null) : null, fn (string $q) => self::dateCovered($date, $q));
            $all = $all && $ok;
            $out['dates'][$date] = ['date' => $date, 'kind' => ($out['dates'][$date]['kind'] ?? 'work') !== 'work' ? $out['dates'][$date]['kind'] : $kind];
            $note('dates', $date.($kind !== 'work' ? ' ('.$kind.')' : ''), $quote, $ok);
        }
        ksort($out['dates']);
        $out['dates'] = array_values(array_slice($out['dates'], 0, JobDays::MAX_DAYS));
        if ($out['dates']) {
            $out['verified']['dates'] = $all;
        }

        // The money: the amount must be in the quote, with its currency and unit (a day rate isn't a fee for the job).
        $rate = is_array($block['rate'] ?? null) ? $block['rate'] : null;
        if ($clean = self::cleanRate($rate)) {
            $quote = is_string($rate['quote'] ?? null) ? $rate['quote'] : null;
            $ok = $quote !== null && ($rate['verified'] ?? null) !== false && Grounding::check($text, $textStatus, $quote, $clean['amount_minor'])['verified']
                && self::rateInQuote($clean['currency'], $clean['unit'], $quote);
            $out['rate'] = $clean;
            $out['verified']['rate'] = $ok;
            $note('rate', $clean['amount_minor'].' '.$clean['currency'].'/'.$clean['unit'], $quote, $ok);
        }

        [$value, $quote, $flag] = self::fact($block['reply_by'] ?? null);
        if ($date = self::ymd(is_string($value) ? $value : null)) {
            $out['reply_by'] = $date;
            $ok = $grounded($quote, $flag, fn (string $q) => Grounding::dateInQuote($date, $q));
            $out['verified']['reply_by'] = $ok;
            $note('reply_by', $date, $quote, $ok);
        }

        return $out;
    }

    /** @return array{0: mixed, 1: ?string, 2: ?bool} a detail's value, its quote and what the reader said of it */
    private static function fact(mixed $fact): array
    {
        if (is_array($fact)) {
            $value = $fact['value'] ?? $fact['name'] ?? $fact['date'] ?? null;

            return [$value, is_string($fact['quote'] ?? null) ? $fact['quote'] : null, isset($fact['verified']) ? (bool) $fact['verified'] : null];
        }

        return [$fact, null, null];
    }

    // ---------------------------------------------------------------- matching

    /** The opportunity a document belongs to, if any: the same document or email thread, or the same work. */
    private function match(Document $document, Document $top, array $facts, array $keys): ?Opportunity
    {
        // The same email read again (or another part of it).
        if ($o = Opportunity::query()->where('fingerprint', self::fingerprintFor($top))->first()) {
            return $o;
        }
        if ($o = $this->withDocuments([$document->id, $top->id])->first()) {
            return $o;
        }
        // Another email in the same Gmail conversation.
        $gmailKey = collect($keys)->first(fn ($k) => str_starts_with($k, 'gmail:'));
        if ($gmailKey) {
            $siblings = GmailMessage::query()->where('thread_id', substr($gmailKey, 6))->whereNotNull('document_id')->pluck('document_id')->all();
            if ($o = $this->withDocuments($siblings)->sortByDesc('last_seen_at')->first()) {
                return $o;
            }
            if ($o = Opportunity::query()->where('thread_key', $gmailKey)->orderByDesc('last_seen_at')->first()) {
                return $o;
            }
        }
        // The same subject (a forwarded copy, a reply), from the same client or sender, for dates that fit, and nothing
        // that says it's other work.
        $subjectKey = collect($keys)->first(fn ($k) => str_starts_with($k, 'subject:'));
        if ($subjectKey) {
            $o = Opportunity::query()->where('thread_key', $subjectKey)->orderByDesc('last_seen_at')->get()
                ->first(fn (Opportunity $o) => self::sameSender($o, $facts) && self::datesFit($o, $facts) && ! self::conflicts($o, $facts));
            if ($o) {
                return $o;
            }
        }

        if (! $facts['dates']) {
            return $this->undated($facts);
        }
        // The same work: overlapping dates, and at least two details that agree (the client and the venue, the
        // person asking, or its name), with none that differ.
        $dates = array_column($facts['dates'], 'date');
        $scored = Opportunity::query()->whereNotNull('starts_on')
            ->whereDate('starts_on', '<=', end($dates))->whereDate('ends_on', '>=', $dates[0])->get()
            ->filter(fn (Opportunity $o) => array_intersect($o->dayDates(), $dates) !== [])
            ->map(fn (Opportunity $o) => ['o' => $o, 'score' => self::sameWork($o, $facts)])
            ->filter(fn ($c) => $c['score'] >= 3)->sortByDesc('score')->values();
        if ($scored->isEmpty() || ($scored->count() > 1 && $scored[0]['score'] === $scored[1]['score'])) {
            return null; // nothing, or a close call between two: kept apart
        }

        return $scored[0]['o'];
    }

    /** @return Collection<int, Opportunity> opportunities whose evidence includes any of these documents */
    private function withDocuments(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return collect();
        }

        return Opportunity::query()->where(function ($q) use ($ids) {
            foreach (array_slice($ids, 0, 50) as $id) {
                $q->orWhere('evidence', 'like', '%'.$id.'%');
            }
        })->get()->filter(fn (Opportunity $o) => array_intersect($o->documentIds(), $ids) !== [])->values();
    }

    /**
     * A document with no dates (a PO or an accounts email naming the client and the job): the one open opportunity
     * (or booked one with no job) for the same client whose name shares two distinctive words with it, or whose
     * contact is at the same company, and nothing that says it's other work. Null when none, or more than one, could be it.
     */
    private function undated(array $facts): ?Opportunity
    {
        $key = JobCreator::clientKey($facts['client']);
        if ($key === '') {
            return null;
        }
        $found = Opportunity::query()->open()->whereNotNull('client_name')->get()
            ->filter(fn (Opportunity $o) => JobCreator::clientKey($o->client_name) === $key && ! self::conflicts($o, $facts)
                && (count(array_intersect(self::words($o->title), self::words($facts['title']))) >= 2 || self::sameCompany($o->contact_email, $facts['contact_email'])));

        return $found->count() === 1 ? $found->first() : null;
    }

    /**
     * How strongly the details point to the same work on overlapping dates: client 2, postcode 2, venue 1, title words
     * 1, the same person or company asking 1; 0 below 3, so a client alone isn't enough (an agency books you for many
     * jobs, often on the same days), and 0 when a detail says it's other work.
     */
    private static function sameWork(Opportunity $o, array $facts): int
    {
        if (self::conflicts($o, $facts)) {
            return 0;
        }
        $score = 0;
        $a = JobCreator::clientKey($o->client_name);
        if ($a !== '' && $a === JobCreator::clientKey($facts['client'])) {
            $score += 2;
        }
        if ($o->postcode && $facts['postcode'] && self::postcodeKey($o->postcode) === self::postcodeKey($facts['postcode'])) {
            $score += 2;
        }
        if (self::samePlace($o->venue, $facts['venue'])) {
            $score++;
        }
        if (count(array_intersect(self::words($o->title), self::words($facts['title']))) >= 2) {
            $score++;
        }
        if (self::sameCompany($o->contact_email, $facts['contact_email'])) {
            $score++;
        }

        return $score >= 3 ? $score : 0;
    }

    /**
     * Details on both sides that say it's other work: another client, venue or postcode, or names with no word in
     * common. A detail you corrected yourself (an email gave it, you changed it) isn't compared: the emails may well
     * still say otherwise.
     */
    private static function conflicts(Opportunity $o, array $facts): bool
    {
        $corrected = fn (string $detail) => $o->ownerSet($detail) && $o->evidenceFor($detail) !== [];
        $a = JobCreator::clientKey($o->client_name);
        $b = JobCreator::clientKey($facts['client']);
        if ($a !== '' && $b !== '' && $a !== $b && ! $corrected('client')) {
            return true;
        }
        if ($o->postcode && $facts['postcode'] && self::postcodeKey($o->postcode) !== self::postcodeKey($facts['postcode']) && ! $corrected('postcode')) {
            return true;
        }
        if ($o->venue && $facts['venue'] && ! JobGatherer::vaguePlace($o->venue) && ! JobGatherer::vaguePlace($facts['venue']) && ! self::samePlace($o->venue, $facts['venue'])
            && ! $corrected('venue')) {
            return true;
        }
        $x = self::words($o->title);
        $y = self::words($facts['title']);

        return $x !== [] && $y !== [] && array_intersect($x, $y) === [] && ! $corrected('title');
    }

    /** The same address, or two at the same company (not a free email service, where anyone can have one). */
    private static function sameCompany(?string $a, ?string $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);
        $domain = fn (string $e) => str_contains($e, '@') ? substr((string) strrchr($e, '@'), 1) : '';

        return $a === $b || ($domain($a) !== '' && $domain($a) === $domain($b) && ! in_array($domain($a), OpportunityRules::FREE_MAIL, true));
    }

    private static function sameSender(Opportunity $o, array $facts): bool
    {
        $a = JobCreator::clientKey($o->client_name);
        if ($a !== '' && $a === JobCreator::clientKey($facts['client'])) {
            return true;
        }

        return self::sameCompany($o->contact_email, $facts['contact_email']);
    }

    /** Dates within two weeks of each other (or not known on one side). */
    public static function datesFit(Opportunity $o, array $facts): bool
    {
        if (! $o->starts_on || ! $facts['dates']) {
            return true;
        }
        $from = CarbonImmutable::parse($facts['dates'][0]['date']);
        $to = CarbonImmutable::parse($facts['dates'][count($facts['dates']) - 1]['date']);

        return $from->subDays(14)->format('Y-m-d') <= ($o->ends_on ?? $o->starts_on)->format('Y-m-d')
            && $to->addDays(14)->format('Y-m-d') >= $o->starts_on->format('Y-m-d');
    }

    // ---------------------------------------------------------------- creating and updating

    private function createFrom(array $facts, Document $document, Document $top, CarbonImmutable $seenAt, array $keys): Opportunity
    {
        $title = $facts['title'] ?? self::subjectTitle($top->email_subject ?? $document->email_subject)
            ?? ($facts['client'] ? 'Work for '.$facts['client'] : 'Possible work');
        $dates = $facts['dates'];
        $o = new Opportunity([
            'status' => $facts['status'] ?? 'enquiry', 'hold_rank' => $facts['hold_rank'],
            'title' => mb_substr($title, 0, 150), 'client_id' => self::clientIdFor($facts['client']), 'client_name' => $facts['client'],
            'contact_name' => $facts['contact_name'], 'contact_email' => $facts['contact_email'], 'role' => $facts['role'],
            'venue' => $facts['venue'], 'postcode' => $facts['postcode'],
            'starts_on' => $dates[0]['date'] ?? null, 'ends_on' => $dates ? $dates[count($dates) - 1]['date'] : null, 'dates' => $dates ?: null,
            'rate_minor' => $facts['rate']['amount_minor'] ?? null, 'rate_currency' => $facts['rate']['currency'] ?? null, 'rate_unit' => $facts['rate']['unit'] ?? null,
            'reply_by' => $facts['reply_by'], 'po_number' => $facts['po_number'],
            'source' => self::sourceOf($top), 'found_by' => 'ai', 'evidence' => $facts['evidence'],
            'thread_key' => $keys[0] ?? null, 'fingerprint' => self::fingerprintFor($top),
            'first_seen_at' => $seenAt, 'last_seen_at' => $seenAt,
        ]);
        $o->confidence = self::confidence($o);
        $o->save();
        AuditLog::record('opportunity.found', $o, null, self::snapshot($o) + ['unchecked' => $o->unverifiedFields()],
            'From '.self::describe($top, $document), 'ai');

        return $o;
    }

    /**
     * A later (or earlier) email or document about the same work. Its words are always added to the evidence. Once
     * you've declined or dismissed it, or made it a job, nothing else changes; nor does any detail you set yourself
     * (Edit details): the email's words are kept beside yours and the history notes the difference. Otherwise a newer
     * email's checked details replace older ones, an older email only fills blanks, and the stage only moves forward
     * (asked, held, offered, confirmed) on words found in it, unless a newer email says it went elsewhere or a closed
     * one is back on. Details the rules guessed from an email's words alone give way to a checked AI reading.
     */
    private function merge(Opportunity $o, array $facts, Document $document, Document $top, CarbonImmutable $seenAt, array $keys): Opportunity
    {
        $before = self::snapshot($o);
        $known = array_intersect($o->documentIds(), [$document->id, $top->id]) !== [];
        $newer = ! $known && (! $o->last_seen_at || $seenAt->gte($o->last_seen_at));
        $locked = in_array($o->status, Opportunity::YOURS, true) || $o->isAccepted();
        // A detail only the rules have words for (spotted before this email was read with AI) is a guess.
        $guess = fn (string $field) => ($ev = $o->evidenceFor($field)) !== [] && collect($ev)->every(fn ($e) => ($e['by'] ?? null) === 'rules');

        $evidence = $o->evidence ?? [];
        $have = array_flip(array_map(self::evidenceKey(...), $evidence));
        $added = 0;
        foreach ($facts['evidence'] as $e) {
            if (! isset($have[self::evidenceKey($e)])) {
                $evidence[] = $e;
                $have[self::evidenceKey($e)] = true;
                $added++;
            }
        }
        $changes = $added ? ['evidence' => $evidence] : [];
        if (! $o->last_seen_at || $seenAt->gt($o->last_seen_at)) {
            $changes['last_seen_at'] = $seenAt;
        }
        if (! $o->thread_key && $keys) {
            $changes['thread_key'] = $keys[0];
        }
        $differs = [];

        if (! $locked) {
            $ok = $facts['verified'];
            $take = function (string $field, mixed $new, mixed $current) use ($o, $ok, $newer, $guess, &$differs): bool {
                if ($new === null || $new === $current) {
                    return false;
                }
                if ($o->ownerSet($field)) {
                    $differs[$field] = true; // yours: the email's words are kept as evidence only

                    return false;
                }

                return blank($current) || (($newer || $guess($field)) && ($ok[$field] ?? false));
            };
            // A title is the reader's own wording, so it isn't changed with every reply: only one made from the
            // subject (or "Possible work") gives way to the first title the reader finds.
            if ($facts['title'] !== null && $o->evidenceFor('title') === [] && $facts['title'] !== $o->title && ! $o->ownerSet('title')) {
                $changes['title'] = $facts['title'];
            }
            foreach (['client' => 'client_name', 'role' => 'role', 'venue' => 'venue', 'postcode' => 'postcode', 'reply_by' => 'reply_by', 'po_number' => 'po_number'] as $fact => $column) {
                $current = $column === 'reply_by' ? $o->reply_by?->format('Y-m-d') : $o->{$column};
                if ($take($fact, $facts[$fact], $current)) {
                    $changes[$column] = $facts[$fact];
                    if ($column === 'client_name') {
                        $changes['client_id'] = self::clientIdFor($facts['client']);
                    }
                }
            }
            if ($facts['contact_email'] !== null && $take('contact', $facts['contact_email'], $o->contact_email)) {
                [$changes['contact_email'], $changes['contact_name']] = [$facts['contact_email'], $facts['contact_name']];
            } elseif ($facts['contact_name'] !== null && blank($o->contact_name) && ! $o->ownerSet('contact')
                && ($facts['contact_email'] === null || $facts['contact_email'] === $o->contact_email)) {
                $changes['contact_name'] = $facts['contact_name'];
            }

            // Its days: a newer email's checked dates replace the old ones, unless they're only some of them (a call
            // time for one day), when only their kinds are taken.
            if ($facts['dates'] && $o->ownerSet('dates')) {
                if (array_column($facts['dates'], 'date') !== $o->dayDates()) {
                    $differs['dates'] = true;
                }
            } elseif ($facts['dates']) {
                $current = $o->dates ?? [];
                $now = array_column($current, 'kind', 'date');
                $new = array_column($facts['dates'], 'kind', 'date');
                $dates = null;
                if (! $current && ! $o->starts_on) {
                    $dates = $facts['dates'];
                } elseif (($newer || $guess('dates')) && ($ok['dates'] ?? false)) {
                    if (array_diff_key($new, $now) === [] && $current) {
                        foreach ($new as $date => $kind) {
                            $now[$date] = $kind !== 'work' ? $kind : $now[$date];
                        }
                        $merged = array_map(fn ($date, $kind) => ['date' => $date, 'kind' => $kind], array_keys($now), $now);
                        $dates = $merged !== $current ? $merged : null;
                    } else {
                        $dates = $facts['dates'];
                    }
                }
                if ($dates !== null && $dates !== $current) {
                    $changes['dates'] = $dates;
                    $changes['starts_on'] = $dates[0]['date'];
                    $changes['ends_on'] = $dates[count($dates) - 1]['date'];
                }
            }

            $rate = $facts['rate'] ? [$facts['rate']['amount_minor'], $facts['rate']['currency'], $facts['rate']['unit']] : null;
            if ($rate !== null && $rate !== [$o->rate_minor, $o->rate_currency, $o->rate_unit]) {
                if ($o->ownerSet('rate')) {
                    $differs['rate'] = true;
                } elseif ($o->rate_minor === null || (($newer || $guess('rate')) && ($ok['rate'] ?? false))) {
                    [$changes['rate_minor'], $changes['rate_currency'], $changes['rate_unit']] = $rate;
                }
            }

            // The stage moves only on words found in the email that say it.
            if ($facts['status'] !== null && $facts['status'] !== $o->status && ($ok['status'] ?? false)) {
                if ($o->ownerSet('status')) {
                    $differs['status'] = true;
                } else {
                    $status = self::nextStatus($o->status, $facts['status'], $newer || $guess('status'));
                    if ($status !== $o->status) {
                        $changes['status'] = $status;
                    }
                }
            }
            if ($facts['hold_rank'] !== null && $facts['hold_rank'] !== $o->hold_rank && ! $o->ownerSet('hold_rank')
                && ($o->hold_rank === null || ($newer && $facts['status'] === 'held'))) {
                $changes['hold_rank'] = $facts['hold_rank'];
            }
            if ($o->found_by === 'rules') {
                $changes['found_by'] = 'ai'; // read with AI now: its details are the reading's, checked against the email
            }
        }

        if (! $changes) {
            return $o;
        }
        $o->fill($changes);
        $o->confidence = self::confidence($o);
        $o->save();
        $after = self::snapshot($o);
        $diff = array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        $yours = $differs ? ' It says something different about '.implode(', ', array_keys($differs)).': you set '.(count($differs) === 1 ? 'that' : 'those').' yourself, so yours '.(count($differs) === 1 ? 'is' : 'are').' kept.' : '';
        AuditLog::record('opportunity.updated', $o, array_intersect_key($before, $diff), $diff + ['evidence_added' => $added] + ($differs ? ['differs_from_yours' => array_keys($differs)] : []),
            ($locked ? 'Evidence only (you decided it, or it’s a job): ' : 'From ').self::describe($top, $document).'.'.$yours, 'ai');

        return $o;
    }

    /** The stage after a new email: forward only; "went elsewhere" and reviving a closed one need a newer email. */
    private static function nextStatus(string $current, ?string $hint, bool $newer): string
    {
        if ($hint === null || $hint === $current || in_array($current, Opportunity::YOURS, true)) {
            return $current;
        }
        if ($hint === 'lost') {
            return $newer ? 'lost' : $current;
        }
        if (in_array($current, ['lost', 'expired'], true)) {
            return $newer ? $hint : $current;
        }

        return (Opportunity::STAGES[$hint] ?? 0) > (Opportunity::STAGES[$current] ?? 0) ? $hint : $current;
    }

    /**
     * Where to reopen it: the furthest stage its emails reached (asked, held, offered, confirmed), or the stage it was
     * at before it was last closed or matched to a job, whichever is further.
     */
    private static function furthestStage(Opportunity $o): string
    {
        $stages = array_map(fn ($e) => $e['value'] ?? null, $o->evidenceFor('status'));
        $last = AuditEvent::query()->where('subject_type', 'Opportunity')->where('subject_id', $o->id)->orderByDesc('id')->get(['before', 'after'])
            ->first(fn (AuditEvent $e) => isset(Opportunity::STAGES[$e->before['status'] ?? null])
                && (! isset(Opportunity::STAGES[$e->after['status'] ?? null]) || (! ($e->before['work_job_id'] ?? null) && ($e->after['work_job_id'] ?? null))));
        $stages[] = $last?->before['status'];
        $best = 'enquiry';
        foreach ($stages as $v) {
            if (isset(Opportunity::STAGES[$v]) && Opportunity::STAGES[$v] > Opportunity::STAGES[$best]) {
                $best = $v;
            }
        }

        return $best;
    }

    /** 0-100: how much of it is known, and how much of that was found word for word in the emails. */
    private static function confidence(Opportunity $o): int
    {
        $unchecked = array_flip($o->unverifiedFields());
        $checked = [];
        foreach ($o->evidence ?? [] as $e) {
            if (isset($e['field'])) {
                $checked[$e['field']] = ! isset($unchecked[$e['field']]);
            }
        }
        foreach ($o->owner_fields ?? [] as $f) {
            $checked[$f] = true;
        }
        $score = 20;
        $score += $o->starts_on ? (($checked['dates'] ?? false) ? 25 : 10) : 0;
        $score += $o->client_name ? (($checked['client'] ?? false) ? 15 : 5) : 0;
        $score += $o->rate_minor ? (($checked['rate'] ?? false) ? 10 : 3) : 0;
        $score += ($checked['status'] ?? false) ? 10 : 0;
        $score += ($checked['title'] ?? false) ? 5 : 0;
        $score += ($o->venue || $o->postcode) ? ((($checked['venue'] ?? false) || ($checked['postcode'] ?? false)) ? 10 : 3) : 0;
        $score += $o->contact_email ? 5 : 0;

        return min(100, $score);
    }

    // ---------------------------------------------------------------- jobs

    /**
     * Match an opportunity to a job you've already added: the job its emails or documents are on (it's then
     * confirmed, as that job), or the one job (not cancelled) on its dates with the same client, or with the same
     * postcode or venue and no other client: that's only a suggestion, so where it stands is left as it is until you
     * say it's that job (confirmLink) or not (reopen). A job you said it isn't is never matched again; a close call
     * between two jobs is left alone.
     */
    private function linkToJob(Opportunity $o): ?WorkJob
    {
        if ($o->work_job_id || ! in_array($o->status, [...Opportunity::OPEN, 'expired'], true)) {
            return null;
        }
        $rejected = $o->rejected_job_ids ?? [];
        $job = null;
        $why = null;

        $docs = $o->documentIds() ? Document::query()->whereIn('id', $o->documentIds())->get(['id', 'parent_id', 'work_job_id']) : collect();
        $parents = $docs->pluck('parent_id')->filter()->all();
        $onJobs = $docs->pluck('work_job_id')->merge($parents ? Document::query()->whereIn('id', $parents)->pluck('work_job_id') : [])->filter()->unique();
        $jobs = $onJobs->isEmpty() ? collect() : WorkJob::query()->whereIn('id', $onJobs)->where('status', '!=', 'cancelled')->whereNotIn('id', $rejected ?: [0])->get();
        if ($jobs->count() === 1) {
            [$job, $why] = [$jobs->first(), 'Its email or document is on this job'];
        } elseif ($jobs->isEmpty() && $o->starts_on) {
            $dates = $o->dayDates();
            $matches = WorkJob::query()->with(['client', 'days'])->where('status', '!=', 'cancelled')->whereNotIn('id', $rejected ?: [0])
                ->whereDate('starts_on', '<=', end($dates))->whereDate('ends_on', '>=', $dates[0])->get()
                ->filter(fn (WorkJob $j) => array_intersect($j->workDates(), $dates) !== [])
                ->map(fn (WorkJob $j) => ['job' => $j, 'why' => self::sameJob($o, $j)])
                ->filter(fn ($m) => $m['why'] !== null)->values();
            if ($matches->count() === 1) {
                [$job, $why] = [$matches[0]['job'], $matches[0]['why']];
            }
        }
        if (! $job) {
            return null;
        }
        $before = self::snapshot($o);
        $onJob = $why === 'Its email or document is on this job';
        $o->update(['work_job_id' => $job->id] + ($onJob && ! $o->ownerSet('status') ? ['status' => 'confirmed'] : []));
        AuditLog::record('opportunity.linked_to_job', $o, $before, self::snapshot($o), $why.': '.$job->title.($onJob ? '' : '. A suggestion: say whether it’s this job.'), 'matcher');

        return $job;
    }

    /**
     * Why an opportunity is this job (on its dates): the same client, or the same postcode or venue when they don't
     * name different clients (two clients' work at one venue on the same day is two jobs). Null when none is.
     */
    private static function sameJob(Opportunity $o, WorkJob $job): ?string
    {
        $a = JobCreator::clientKey($o->client_name);
        $b = JobCreator::clientKey($job->client?->name);
        if (($o->client_id && $o->client_id === $job->client_id) || ($a !== '' && $a === $b)) {
            return 'Same client on the same dates';
        }
        if (($a !== '' || $o->client_id) && ($b !== '' || $job->client_id)) {
            return null; // another client's work
        }
        if ($o->postcode && $job->postcode && self::postcodeKey($o->postcode) === self::postcodeKey($job->postcode)) {
            return 'Same postcode on the same dates';
        }
        if (self::samePlace($o->venue, $job->location)) {
            return 'Same venue on the same dates';
        }

        return null;
    }

    /**
     * Link the opportunity's emails and documents to its new job (each email with its attachments). One already on
     * another job stays there.
     *
     * @return array{linked: int, missed: list<int>}
     */
    private function linkDocuments(Opportunity $o, WorkJob $job): array
    {
        $finder = app(JobFinder::class);
        $linked = 0;
        $missed = [];
        $done = [];
        foreach ($o->documents() as $doc) {
            $top = $doc->parent_id ? ($doc->parent ?? $doc) : $doc;
            if (isset($done[$top->id])) {
                continue;
            }
            $done[$top->id] = true;
            if ($top->work_job_id === $job->id) {
                $linked++;
            } elseif ($top->work_job_id) {
                $missed[] = $top->id;
            } elseif ($finder->linkDocument($top, $job, 'you')) {
                $linked++;
            }
        }

        return ['linked' => $linked, 'missed' => $missed];
    }

    private function decide(Opportunity $opportunity, string $status, ?string $note, string $by): Opportunity
    {
        self::owner($by);

        return DB::transaction(function () use ($opportunity, $status, $note, $by) {
            $o = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            if ($o->isAccepted()) {
                throw new InvalidArgumentException('You’ve made this a job. Cancel the job if it isn’t going ahead.');
            }
            $before = self::snapshot($o);
            $changes = ['status' => $status, 'decided_at' => now(), 'decided_by' => $by];
            if ($note !== null && trim($note) !== '') {
                $changes['note'] = mb_substr(trim($note), 0, 2000);
            }
            if ($o->work_job_id) {
                // The rules matched it to a job: it isn't that job.
                $changes['rejected_job_ids'] = array_values(array_unique([...($o->rejected_job_ids ?? []), $o->work_job_id]));
                $changes['work_job_id'] = null;
            }
            $o->update($changes);
            AuditLog::record('opportunity.'.$status, $o, $before, self::snapshot($o), $note, $by);

            return $o;
        });
    }

    // ---------------------------------------------------------------- helpers

    private static function owner(string $by): void
    {
        if (! in_array($by, JobCreator::OWNER, true)) {
            throw new InvalidArgumentException('Only you can decide this. Automation can only suggest.');
        }
    }

    /** One fingerprint per email (its body and attachments together), so reading it again finds the same one. */
    private static function fingerprintFor(Document $top): string
    {
        return sha1('document:'.$top->id);
    }

    /**
     * Keys for the conversation an email is part of: its Gmail thread, and its subject without "Re:" and "Fwd:" when
     * the subject says something about the work (not just "Availability").
     *
     * @return list<string>
     */
    private static function threadKeys(Document $top): array
    {
        // The subject first: it also finds a copy you forwarded, or the same email sent to the forwarding mailbox.
        $keys = [];
        $subject = self::subjectKey($top->email_subject);
        if ($subject !== null) {
            $keys[] = 'subject:'.sha1($subject);
        }
        $thread = GmailMessage::query()->where('document_id', $top->id)->value('thread_id');
        if ($thread) {
            $keys[] = 'gmail:'.mb_substr($thread, 0, 120);
        }

        return $keys;
    }

    /**
     * A subject to compare, without "Re:" and "Fwd:", in plain lower-case words. Null when it says nothing about
     * which job it is ("Availability", "Re: Crew request").
     */
    public static function subjectKey(?string $subject): ?string
    {
        $s = mb_strtolower(trim((string) $subject));
        $s = preg_replace('/\[(external|ext|spam)\]/iu', ' ', $s) ?? $s;
        do {
            $was = $s;
            $s = preg_replace('/^\s*(re|fw|fwd|aw|wg|tr|sv)\s*(\[\d+\]|\(\d+\))?\s*:\s*/iu', '', $s) ?? $s;
        } while ($s !== $was);
        $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '') ?? '');
        $words = array_filter(explode(' ', $s), fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::GENERIC, true) && ! preg_match('/^\d+$/', $w));

        return count($words) >= 1 && mb_strlen(implode('', $words)) >= 5 ? $s : null;
    }

    /** An email's subject as a title, without "Re:" and "Fwd:". */
    private static function subjectTitle(?string $subject): ?string
    {
        $s = trim((string) $subject);
        do {
            $was = $s;
            $s = preg_replace('/^\s*(re|fw|fwd|aw|wg|tr|sv)\s*(\[\d+\]|\(\d+\))?\s*:\s*/iu', '', $s) ?? $s;
        } while ($s !== $was);

        return $s !== '' ? mb_substr($s, 0, 150) : null;
    }

    private static function sourceOf(Document $top): string
    {
        return in_array($top->source, ['gmail', 'email', 'telegram', 'upload'], true) ? $top->source : 'upload';
    }

    private static function describe(Document $top, Document $document): string
    {
        $what = $top->email_subject ? '“'.mb_substr($top->email_subject, 0, 100).'”' : '“'.mb_substr($document->original_name, 0, 100).'”';

        return $what.($top->received_at ? ' of '.$top->received_at->format('j M Y') : '');
    }

    /**
     * Who sent an email (or, for one you forwarded, who sent the original): the first "From:" in it that isn't you
     * or a machine. The quote is that line as it is in the email's text.
     *
     * @param  array<string, true>  $own  your own addresses
     * @return array{name: ?string, email: string, quote: string, verified: bool}|null
     */
    public static function senderOf(Document $doc, ?array $own = null): ?array
    {
        $own ??= self::ownAddresses();
        $lines = [];
        if ($doc->text && preg_match_all('/^[ \t>*]*From:[ \t*]*(.+)$/mi', $doc->text, $m)) {
            foreach ($m[0] as $i => $line) {
                $lines[] = ['raw' => trim($m[1][$i]), 'quote' => rtrim(ltrim($line, " \t>*"), " \t*"), 'text' => true];
            }
        }
        if ($doc->email_from) {
            $lines[] = ['raw' => $doc->email_from, 'quote' => $doc->email_from, 'text' => false];
        }
        foreach ($lines as $line) {
            if (! preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line['raw'], $em)) {
                continue;
            }
            $email = strtolower($em[0]);
            if (isset($own[$email]) || self::machine($email)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/', ' ', preg_replace('/<[^>]*>|\[[^\]]*\]|mailto:\S+|"/i', ' ', str_ireplace($em[0], ' ', $line['raw'])) ?? '') ?? '', " \t'<>(),;:");

            return [
                'name' => $name !== '' ? mb_substr($name, 0, 120) : null, 'email' => mb_substr($email, 0, 200), 'quote' => mb_substr($line['quote'], 0, 400),
                'verified' => $doc->text_status === 'extracted' && Grounding::quoteFound($doc->text, $line['quote']),
            ];
        }

        return null;
    }

    /** @return array<string, true> your own email addresses (the account, the forwarding mailbox, your invoices') */
    public static function ownAddresses(): array
    {
        $list = [];
        foreach (['business_email', 'gmail_account', 'imap_username', 'mail_from_address', 'smtp_username'] as $key) {
            $v = Setting::get($key);
            if (is_string($v)) {
                $list[] = $v;
            }
        }
        foreach ((array) Setting::get('allowed_senders') as $v) {
            $list[] = is_string($v) ? $v : '';
        }
        foreach (User::query()->pluck('email') as $v) {
            $list[] = (string) $v;
        }
        $out = [];
        foreach ($list as $v) {
            $v = strtolower(trim($v));
            if (str_contains($v, '@')) {
                $out[$v] = true;
            }
        }

        return $out;
    }

    private static function machine(string $email): bool
    {
        return (bool) preg_match('/^(no-?reply|do-?not-?reply|notifications?|mailer-daemon|postmaster|bounces?)[@+]/i', $email);
    }

    private static function clientIdFor(?string $name): ?int
    {
        $key = JobCreator::clientKey($name);
        if ($key === '') {
            return null;
        }
        $same = Client::query()->get(['id', 'name'])->filter(fn ($c) => JobCreator::clientKey($c->name) === $key);

        return $same->count() === 1 ? $same->first()->id : null;
    }

    /** A short note for the new job: the role, who it's with, and a rate that wasn't found word for word. */
    private static function jobNote(Opportunity $o, bool $uncheckedRate = false): ?string
    {
        $parts = array_filter([
            $o->role ? 'Role: '.$o->role : null,
            $o->contact_name || $o->contact_email ? 'Contact: '.trim(($o->contact_name ?? '').($o->contact_email ? ' <'.$o->contact_email.'>' : '')) : null,
            $uncheckedRate && $o->rate_minor ? 'Rate read from the email, not found word for word (check it): '.Money::formatMinor($o->rate_minor, $o->rate_currency ?? 'GBP', false)
                .' '.(['day' => 'a day', 'hour' => 'an hour'][$o->rate_unit] ?? 'for the job') : null,
        ]);

        return $parts ? implode("\n", $parts) : null;
    }

    private static function snapshot(Opportunity $o): array
    {
        $out = [];
        foreach (self::TRACKED as $field) {
            $v = $o->{$field};
            $out[$field] = $v instanceof \DateTimeInterface ? ($field === 'decided_at' ? $v->format('Y-m-d H:i:s') : $v->format('Y-m-d')) : $v;
        }

        return $out;
    }

    private static function evidenceKey(array $e): string
    {
        return implode('|', [$e['type'] ?? '', $e['id'] ?? '', $e['field'] ?? '', $e['value'] ?? '', Grounding::normalise((string) ($e['quote'] ?? ''))]);
    }

    private static function text(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private static function email(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[^@\s<>]+@[^@\s<>]+\.[a-z]{2,}$/i', trim($value)) ? mb_substr(strtolower(trim($value)), 0, 200) : null;
    }

    private static function ymd(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }

    /** @return ?array{amount_minor: int, currency: string, unit: string} */
    private static function cleanRate(mixed $rate): ?array
    {
        if (! is_array($rate) || ! is_int($rate['amount_minor'] ?? null) || $rate['amount_minor'] <= 0) {
            return null;
        }

        return [
            'amount_minor' => $rate['amount_minor'],
            'currency' => preg_match('/^[A-Z]{3}$/', (string) ($rate['currency'] ?? '')) ? $rate['currency'] : 'GBP',
            'unit' => in_array($rate['unit'] ?? null, Opportunity::RATE_UNITS, true) ? $rate['unit'] : 'job',
        ];
    }

    /** @return list<array{date: string, kind: string}> */
    private static function cleanDates(mixed $dates, mixed $from, mixed $to): array
    {
        $out = [];
        if (is_array($dates) && $dates) {
            foreach ($dates as $d) {
                $date = self::ymd(is_array($d) ? ($d['date'] ?? null) : $d) ?? throw new InvalidArgumentException('Give dates as YYYY-MM-DD.');
                $kind = is_array($d) && in_array($d['kind'] ?? null, Opportunity::DAY_KINDS, true) ? $d['kind'] : 'work';
                $out[$date] = ['date' => $date, 'kind' => $kind];
            }
        } elseif ($from) {
            $start = self::ymd($from) ?? throw new InvalidArgumentException('Give dates as YYYY-MM-DD.');
            $end = $to ? (self::ymd($to) ?? throw new InvalidArgumentException('Give dates as YYYY-MM-DD.')) : $start;
            if ($end < $start) {
                throw new InvalidArgumentException('It can’t end before it starts.');
            }
            for ($d = CarbonImmutable::parse($start), $i = 0; $d->format('Y-m-d') <= $end; $d = $d->addDay(), $i++) {
                if ($i >= JobDays::MAX_DAYS) {
                    throw new InvalidArgumentException('That’s more than '.JobDays::MAX_DAYS.' days.');
                }
                $out[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'kind' => 'work'];
            }
        }
        ksort($out);
        if (count($out) > JobDays::MAX_DAYS || ($out && (int) CarbonImmutable::parse(array_key_first($out))->diffInDays(CarbonImmutable::parse(array_key_last($out))) + 1 > JobDays::MAX_DAYS)) {
            throw new InvalidArgumentException('That’s more than '.JobDays::MAX_DAYS.' days.');
        }

        return array_values($out);
    }

    /** Is the value (a client, venue, postcode, email) in the quoted words? */
    private static function inQuote(?string $value, string $quote): bool
    {
        $v = Grounding::normalise((string) $value);

        return mb_strlen($v) >= 2 && str_contains(Grounding::normalise($quote), $v);
    }

    /** "1st option", "first hold", "2nd pencil". */
    private static function rankInQuote(int $rank, string $quote): bool
    {
        $words = [1 => '1st|first', 2 => '2nd|second', 3 => '3rd|third', 4 => '4th|fourth'][$rank] ?? $rank.'th';

        return (bool) preg_match('/\b('.$words.')\b/i', $quote);
    }

    /** Currencies whose three-letter code may stand in a quote ("CHF 500"). */
    private const CURRENCY_CODES = ['GBP', 'EUR', 'USD', 'CHF', 'AUD', 'CAD', 'NZD', 'JPY', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'ZAR', 'AED', 'SGD', 'HKD', 'CNY', 'INR'];

    /**
     * Does a rate's quote say its unit and currency? A day or hour rate must say "a day" or "an hour" (or "day rate",
     * "/hr"); a fee for the whole job mustn't ("£320 per day" isn't £320 for the job). When the quote shows a currency
     * (£, €, $, or a code such as GBP), it must be the one given.
     */
    public static function rateInQuote(string $currency, string $unit, string $quote): bool
    {
        $perDay = '/(?:\b(?:per|a|each|every)\s+day\b|\/\s*day\b|\bdaily\b|\bp\/d\b|\bday\s*rate\b|\bper\s+diem\b)/iu';
        $perHour = '/(?:\b(?:per|an|a|each|every)\s+(?:hour|hr)\b|\/\s*(?:hour|hr|h)\b|\bhourly\b|\bp\/h\b|\bph\b)/iu';
        $unitOk = match ($unit) {
            'day' => (bool) preg_match('/\b(day|days|daily|p\/?d|a day|per day|dr|day rate)\b/i', $quote),
            'hour' => (bool) preg_match('/\b(hour|hours|hourly|hr|hrs|p\/?h|ph)\b/i', $quote),
            default => ! preg_match($perDay, $quote) && ! preg_match($perHour, $quote),
        };
        if (! $unitOk) {
            return false;
        }
        $named = [];
        foreach (['GBP' => '£|\bGBP\b|\bpounds?\b|\bquid\b', 'EUR' => '€|\bEUR\b|\beuros?\b', 'USD' => '\$|\bUSD\b|\bdollars?\b'] as $code => $p) {
            if (preg_match('/'.$p.'/iu', $quote)) {
                $named[] = $code;
            }
        }
        preg_match_all('/\b([A-Z]{3})\b/', $quote, $m);
        $named = [...$named, ...array_intersect($m[1], self::CURRENCY_CODES)];

        return $named === [] || in_array(strtoupper($currency), $named, true);
    }

    /**
     * Do the words say this stage? A hold says hold, option, pencil or first refusal; a booking says confirmed or
     * booked; one that's off says cancelled, released, gone with someone else, pulled and so on. An enquiry or an
     * offer can be put in many ways, so any words will do.
     */
    public static function stageInQuote(string $status, string $quote): bool
    {
        $words = match ($status) {
            'held' => '\b(?:hold|holds|holding|held|on hold|option|pencil\w*|first refusal|put you down)\b',
            'confirmed' => '\b(?:confirm\w*|booked)\b',
            'lost' => '\b(?:cancel\w*|releas\w*|(?:gone|went|going) (?:with|to)|pulled|called off|postponed|fallen through|fell through|not going ahead|no longer|stand\w* you down|stood you down|let\w* (?:\w+ ){0,3}go|won[\'’]?t (?:be )?need\w*|someone else|in-?house)\b',
            default => null,
        };

        return $words === null || (bool) preg_match('/'.$words.'/iu', $quote);
    }

    /** An email's newest message (the part above what it quotes, or the message you forwarded); null for other documents. */
    public static function newestOf(Document $document): ?string
    {
        $email = $document->mime === 'message/rfc822' || str_ends_with(mb_strtolower((string) $document->original_name), '.eml');

        return $email && $document->text !== null ? OpportunityRules::newestMessage((string) $document->text, $document->email_subject) : null;
    }

    /**
     * Does the quote give this date, on its own ("13 March", "Sat 13th March") or within a run or list of days
     * ("12-14 March", "Thurs 12th - Sat 14th March", "12th & 13th March", "12, 13 and 14 March", "March 12-14",
     * "30 March - 2 April")? Read by DateWords, as the rules read dates. When the quote gives a year it has to be
     * the right one.
     */
    public static function dateCovered(string $date, string $quote): bool
    {
        if (Grounding::dateInQuote($date, $quote)) {
            return true;
        }
        $d = CarbonImmutable::parse($date);
        $q = preg_replace('/[£$€]\s?\d[\d,]*(?:\.\d+)?/u', ' ', $quote) ?? $quote;
        preg_match_all('/\b(?:19|20)\d{2}\b/', $q, $years);
        if ($years[0] && ! in_array($d->format('Y'), $years[0], true)) {
            return false;
        }
        $year = (int) $d->format('Y');
        foreach (DateWords::spans($q) as $span) {
            foreach ($span['parts'] as [$from, $to]) {
                try {
                    $start = CarbonImmutable::create($year, $from[1], $from[0]);
                    $end = CarbonImmutable::create($year, $to[1], $to[0]);
                } catch (Throwable) {
                    continue;
                }
                if (! $start || ! $end) {
                    continue;
                }
                if ($end->lt($start)) {
                    $end = $end->addYear(); // 30 December - 2 January
                }
                if ($d->betweenIncluded($start, $end) || $d->betweenIncluded($start->subYear(), $end->subYear())) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function postcodeKey(string $postcode): string
    {
        return strtoupper(preg_replace('/\s+/', '', $postcode) ?? '');
    }

    /** The same venue: the same name, or one name inside the other, and not a place that could be anywhere. */
    private static function samePlace(?string $a, ?string $b): bool
    {
        if (! $a || ! $b || JobGatherer::vaguePlace($a) || JobGatherer::vaguePlace($b)) {
            return false;
        }
        $x = Grounding::normalise(explode(',', $a)[0]);
        $y = Grounding::normalise(explode(',', $b)[0]);

        return mb_strlen($x) >= 5 && mb_strlen($y) >= 5 && ($x === $y || str_contains($x, $y) || str_contains($y, $x));
    }

    /** @return list<string> distinctive words of a title */
    private static function words(?string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{4,}/u', mb_strtolower((string) $text), $m);

        return array_values(array_unique(array_diff($m[0], self::GENERIC)));
    }
}
