<?php

namespace App\Domain\Todo;

use App\Domain\Intel\Backfills\Findings as FindingsBackfill;
use App\Domain\Intel\Contracts\CoversCodes;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Ranker;
use App\Domain\Intel\Ref;
use App\Domain\Intel\RefResolver;
use App\Models\AuditEvent;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps a history of what the to-do list finds: money you may be owed, gaps with the bank, missing POs, duplicate
 * charges, failed checks, unpaid invoices and the rest. Each finding is recorded when first seen, and marked
 * resolved when it no longer appears (or snoozed or dismissed if you hid it), so you can see what was found and
 * dealt with, and the money involved. Routine items (payments to decide, rule offers) aren't findings.
 *
 * Findings come from the detectors (Intel\Detectors\Registry: the to-do list's own groups, and the detectors for
 * payments to HMRC, mileage and filed returns), which only read. Every change to a finding is an event
 * (FindingEvent): found, its money changed, snoozed and the snooze ended, marked not relevant, back again, dealt with
 * (with what caused it: an import, your decision), or kept under a new key.
 *
 * A finding missing this time is dealt with only when its detector looked at everything (coverage complete). One
 * whose detector was capped (a limit left some out) is unseen, not dealt with; one whose detector failed gets a
 * detector_failed event and stays open. A snoozed finding that's then dealt with counts as dealt with. A gap with
 * the bank that's partly filled stays one finding (kept by its first day: gap:{account}:{from}) whose money
 * changes, and one whose first day moves is followed by its dates (rekeyed, keeping when it was first seen); the
 * money dealt with is the most it involved (the whole gap, not what was left of it at the end).
 */
final class FindingsRegistry
{
    public const ROUTINE = ['payments', 'rules', 'transfers', 'stays'];

    /** How it ended: dealt with, snoozed, marked not relevant, or (a gap) merged into another gap still open. */
    public const RESOLUTIONS = ['resolved', 'snoozed', 'dismissed', 'superseded'];

    /** When the findings were last recorded, and from what (TodoList::fingerprint()). */
    public const SYNCED_KEY = 'findings_synced';

    /** How each direction reads as a total. */
    public const DIRECTION_TOTALS = ['owed_to_you' => 'You may be owed', 'owed_by_you' => 'You may owe', 'unrecorded' => 'Missing from your records', 'at_risk' => 'May be lost',
        'claimable' => 'You could claim', 'none' => 'Other amounts'];

    /**
     * Record what's found now. One at a time: the history page and the nightly run can overlap, and two at once
     * would both add the same new finding. 'kept' counts findings missing this time but left open because their kind
     * wasn't worked out in full ('unseen' of them because a limit left some out); 'failed' names the kinds whose check
     * went wrong. In a shadow run ($ctx->shadow) nothing is kept: the counts say what would have changed.
     *
     * @return array{new:int, resolved:int, open:int, kept?:int, unseen?:int, failed?:list<string>}
     */
    public static function sync(?CycleContext $ctx = null): array
    {
        try {
            return Cache::lock('findings-sync', 300)->block(20, fn () => self::record(null, $ctx ?? new CycleContext));
        } catch (LockTimeoutException) {
            // Another run is recording the same findings right now.
            return ['new' => 0, 'resolved' => 0, 'open' => Finding::query()->whereNull('resolved_at')->count()];
        }
    }

    /**
     * For the history page: record what's found now only if anything the to-do list is made from has changed since
     * the last time (or it's a new day), and never wait for another run. Working out every finding reads every job,
     * account and duplicate charge, so a page view that changes nothing doesn't do it again, and one that comes
     * while the nightly run (or another view) is recording shows what's recorded so far.
     *
     * @return bool whether they were recorded now
     */
    public static function refresh(): bool
    {
        $now = TodoList::fingerprint();
        if (Cache::get(self::SYNCED_KEY) === $now) {
            return false;
        }
        $lock = Cache::lock('findings-sync', 300);
        if (! $lock->get()) {
            return false;
        }
        try {
            self::record($now, new CycleContext);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @param  ?string  $fingerprint  what the list was made from, taken before it's read (so a change made meanwhile
     *                                is recorded next time)
     * @return array{new:int, resolved:int, open:int, kept:int, unseen:int, failed:list<string>}
     */
    private static function record(?string $fingerprint, CycleContext $ctx): array
    {
        if ($ctx->shadow) {
            // Worked out in full, then undone: nothing a shadow run does is kept.
            DB::beginTransaction();
            try {
                return self::work($fingerprint, $ctx);
            } finally {
                DB::rollBack();
            }
        }

        return self::work($fingerprint, $ctx);
    }

    /** @return array{new:int, resolved:int, open:int, kept:int, unseen:int, failed:list<string>} */
    private static function work(?string $fingerprint, CycleContext $ctx): array
    {
        $fingerprint ??= TodoList::fingerprint();
        $now = Carbon::now();
        $registry = app(Registry::class);
        /** @var Collection<string, FindingDraft> $all */
        $all = collect();
        $failed = [];
        foreach ($registry->all() as $detector) {
            try {
                foreach ($detector->detect($ctx) as $draft) {
                    if (! in_array($draft->group, self::ROUTINE, true)) {
                        $all->put(mb_substr($draft->key, 0, 191), $draft);
                    }
                }
            } catch (Throwable $e) {
                report($e);
            }
            $failed = [...$failed, ...self::failedKinds($detector)];
        }
        $active = TodoDismissal::activeKeys();
        $hiddenBy = $all->map(fn (FindingDraft $d) => collect([$d->key, ...$d->hiddenUnder])->first(fn ($k) => isset($active[$k])));
        $shown = $all->filter(fn ($d, $key) => $hiddenBy[$key] === null);
        self::repairStates($ctx);
        // Followed under a new key this time: the key each was kept under before.
        $rekeyed = self::followGaps($all, $now, $ctx) + self::followFamilies($all, $ctx);
        $existing = Finding::query()->whereIn('key', $all->keys())->get()->keyBy('key');
        // Your snoozes and "not relevant"s still running, by the finding they're about (a gap's is kept under its
        // dates and amount, an account's under its last line).
        $dismissals = TodoDismissal::query()->whereIn('key', array_keys($active) ?: [''])->get()->keyBy('key');
        $byFinding = $dismissals->keyBy(fn (TodoDismissal $d) => TodoList::findingKey($d->key));

        $new = 0;
        foreach ($shown as $key => $d) {
            $fields = self::fields($d, $ctx);
            $row = $existing[$key] ?? null;
            if (! $row) {
                // Found, with the event that says so, together.
                $row = DB::transaction(function () use ($key, $now, $fields, $d, $ctx) {
                    $row = Finding::unguarded(fn () => Finding::query()->createOrFirst(['key' => $key], ['first_seen_at' => $now, 'last_seen_at' => $now, 'peak_amount_pence' => $fields['amount_pence'], 'state' => 'open'] + $fields));
                    if ($row->wasRecentlyCreated) {
                        self::event($row, 'detected', $ctx, note: self::rangeNote($d));
                    }

                    return $row;
                });
                if ($row->wasRecentlyCreated) {
                    $new++;

                    continue;
                }
            }
            FindingsBackfill::adopt($row);
            $was = $row->currentState();
            $before = [$row->amount_pence, $row->currency, self::lastRange($row), $row->code ?? $d->code];
            $row->fill($fields + ['state' => 'open'] + ($row->resolved_at !== null ? ['resolved_at' => null, 'resolution' => null, 'resolution_ref' => null, 'note' => null, 'reopened' => $row->reopened + 1] : []));
            // The most it involved: a gap partly filled has less left, but the gap was what it was.
            $peak = $row->peak_amount_pence === null ? null : (int) $row->peak_amount_pence;
            if ($fields['amount_pence'] !== null && ($peak === null || abs($fields['amount_pence']) > abs($peak))) {
                $row->forceFill(['peak_amount_pence' => $fields['amount_pence']]);
            }
            // Back while a snooze (or "not relevant") of yours still runs: it changed since you hid it (a gap kept by
            // its dates and amount), so it's back early; only a snooze that's run out has ended.
            $stillHidden = $byFinding->has($key) || (isset($rekeyed[$key]) && $byFinding->has($rekeyed[$key]));
            DB::transaction(function () use ($row, $was, $before, $d, $ctx, $stillHidden) {
                if ($row->isDirty()) {
                    $row->save();
                }
                if ($was === 'snoozed') {
                    $stillHidden ? self::event($row, 'reopened', $ctx, note: 'Changed since you snoozed it') : self::event($row, 'snooze_ended', $ctx);
                } elseif ($was === 'dismissed') {
                    self::event($row, 'reopened', $ctx, note: $stillHidden ? 'Changed since you marked it not relevant' : null);
                } elseif ($was === 'resolved') {
                    self::event($row, 'reopened', $ctx);
                }
                $range = self::rangeNote($d);
                // The days a gap covers changed (when what it covered before is known), or the money did. Fines and
                // interest grow a little every day: that's noted once it's moved by MONEY_STEP since last noted.
                $moved = [$row->amount_pence, $row->currency] !== [$before[0], $before[1]]
                    && ($before[3] !== 'tax.penalty' || $row->currency !== $before[1] || self::movedEnough($row));
                if ($moved || ($range !== null && $before[2] !== null && $range !== $before[2])) {
                    self::event($row, 'changed', $ctx, note: $range);
                }
            });
        }
        // Seen again: noted without changing when the finding last changed, so the history lists what happened.
        foreach ($shown->keys()->chunk(500) as $keys) {
            Finding::query()->whereIn('key', $keys->values()->all())->toBase()->update(['last_seen_at' => $now] + ($ctx->runId !== null ? ['last_run_id' => $ctx->runId] : []));
        }

        // Hidden by you: snoozed or marked not relevant, as you hid it now. Only snoozes still running count (as for
        // the list itself, TodoDismissal::activeKeys()): one that's ended hides nothing.
        $resolved = 0;
        foreach ($all->filter(fn ($d, $key) => $hiddenBy[$key] !== null) as $key => $d) {
            $row = $existing[$key] ?? null;
            if (! $row) {
                continue;
            }
            FindingsBackfill::adopt($row);
            $dismissal = $dismissals->get($hiddenBy[$key]);
            $label = $dismissal?->until ? 'snoozed' : 'dismissed';
            $note = $dismissal?->note ?: null;
            if ($row->resolved_at === null) {
                self::change($row, ['resolved_at' => $now, 'resolution' => $label, 'state' => $label, 'note' => $note], $label, $ctx, 'you', note: $note);
                $resolved++;
            } elseif (in_array($row->resolution, ['snoozed', 'dismissed'], true)) {
                if ($row->resolution !== $label) {
                    self::change($row, ['resolution' => $label, 'state' => $label, 'note' => $note], $label, $ctx, 'you', note: $note);
                }
            } else {
                // Gone while snoozed (so dealt with), and back while that snooze still runs: there again, and hidden
                // by you, not dealt with.
                DB::transaction(function () use ($row, $now, $label, $note, $ctx) {
                    self::event($row, 'reopened', $ctx);
                    $row->forceFill(['reopened' => $row->reopened + 1, 'resolution_ref' => null, 'resolved_at' => $now, 'resolution' => $label, 'state' => $label, 'note' => $note])->save();
                    self::event($row, $label, $ctx, 'you', note: $note);
                });
            }
        }

        // Gone. Dealt with, if what finds it looked at everything; one that was snoozed and has gone since was dealt
        // with too (a snooze isn't an answer). If a limit left some out, it's unseen; if its check went wrong, it's
        // kept open. One you marked not relevant that's gone because of it (mileage for jobs you didn't drive to is
        // left out from then on) was set aside, not dealt with.
        $kept = 0;
        $unseen = 0;
        Finding::query()->whereNotIn('key', $all->keys()->all() ?: [''])->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolution', 'snoozed'))->get()
            ->each(function (Finding $f) use ($now, $ctx, $registry, $byFinding, &$resolved, &$kept, &$unseen) {
                FindingsBackfill::adopt($f);
                $coverage = self::coverageOf($registry, $f);
                if ($coverage === Coverage::Failed) {
                    $kept++;
                    if (self::newFailure($f)) {
                        self::event($f, 'detector_failed', $ctx);
                    }

                    return;
                }
                if ($coverage === Coverage::Capped) {
                    $kept++;
                    if ($f->currentState() === 'open') {
                        $unseen++;
                        self::change($f, ['state' => 'unseen'], 'unseen', $ctx);
                    }

                    return;
                }
                $dismissal = $byFinding->get($f->key);
                if ($f->currentState() === 'snoozed' && $dismissal === null) {
                    self::event($f, 'snooze_ended', $ctx);
                }
                if ($f->resolved_at === null) {
                    $resolved++;
                }
                $setAside = $dismissal !== null && $dismissal->until === null;
                $cause = $setAside ? null : self::causeFor($f);
                self::change($f, ['resolved_at' => $now, 'resolution' => $setAside ? 'dismissed' : 'resolved', 'state' => $setAside ? 'dismissed' : 'resolved', 'resolution_ref' => $cause],
                    $setAside ? 'dismissed' : 'resolved', $ctx, $setAside ? 'you' : 'system', cause: $cause, note: $setAside ? ($dismissal->note ?: null) : null);
            });

        if (! $ctx->shadow) {
            Cache::forever(self::SYNCED_KEY, $fingerprint);
        }

        return ['new' => $new, 'resolved' => $resolved, 'open' => $shown->count(), 'kept' => $kept, 'unseen' => $unseen, 'failed' => array_values(array_unique($failed))];
    }

    /** What a finding records from its draft. */
    private static function fields(FindingDraft $d, CycleContext $ctx): array
    {
        $amount = $d->amountPence;

        return ['group' => $d->group, 'title' => mb_substr($d->title, 0, 255), 'detail' => $d->detail, 'href' => $d->href ? mb_substr($d->href, 0, 255) : null,
            'amount_pence' => $amount, 'currency' => self::currency($d->currency, $amount),
            'code' => $d->code, 'detector_version' => $d->detectorVersion, 'subject_ref' => $d->subjectRef, 'scope_ref' => $d->scopeRef, 'evidence' => $d->evidence ?: null,
            'direction' => $d->direction, 'grade' => $d->grade, 'due_on' => $d->dueOn,
            'severity' => Ranker::severity(['code' => $d->code, 'group' => $d->group, 'amount' => $amount, 'direction' => $d->direction, 'grade' => $d->grade, 'due_on' => $d->dueOn]),
        ] + ($ctx->runId !== null ? ['last_run_id' => $ctx->runId] : []);
    }

    /**
     * The kinds a detector couldn't work out this time, by the start of their keys ("penalty:"), as the command
     * reports them.
     *
     * @return list<string>
     */
    private static function failedKinds(Detector $detector): array
    {
        if ($detector instanceof CoversCodes) {
            $out = [];
            foreach (TodoGroupsDetector::TABLE as [$prefix, $code]) {
                if (in_array($code, $detector->codes(), true) && $detector->coverageOf($code) === Coverage::Failed) {
                    $out[] = str_contains($prefix, ':') ? substr($prefix, 0, strpos($prefix, ':') + 1) : $prefix;
                }
            }

            return array_values(array_unique($out));
        }
        if ($detector->coverage() !== Coverage::Failed) {
            return [];
        }
        $prefix = array_search($detector->code(), TodoGroupsDetector::OWN_DETECTORS, true);

        return [$prefix !== false ? $prefix : $detector->code()];
    }

    /** How much the detector that makes a finding of this kind looked at this time. */
    private static function coverageOf(Registry $registry, Finding $f): Coverage
    {
        $code = $f->code ?? (TodoGroupsDetector::classify($f->key)['code'] ?? null);
        $detector = $registry->forCode($code);
        if ($detector === null) {
            // A kind no detector makes: the to-do list's groups (as before findings had codes), or gone for good.
            $detector = collect($registry->all())->first(fn ($d) => $d instanceof CoversCodes);

            return $detector?->coverage() ?? Coverage::Complete;
        }

        return $detector instanceof CoversCodes && $code !== null ? $detector->coverageOf($code) : $detector->coverage();
    }

    /** Change a finding and record the event that says so, together. */
    private static function change(Finding $f, array $fields, string $event, CycleContext $ctx, string $actor = 'system', ?string $cause = null, ?string $note = null): void
    {
        DB::transaction(function () use ($f, $fields, $event, $ctx, $actor, $cause, $note) {
            $f->forceFill($fields)->save();
            self::event($f, $event, $ctx, $actor, $cause, $note);
        });
    }

    /** How far fines and interest move before the change is noted: they grow a little every day. */
    public const MONEY_STEP = 1000;

    /** Whether a finding's money has moved by MONEY_STEP or more since its history last noted it. */
    private static function movedEnough(Finding $f): bool
    {
        $last = $f->events()->reorder()->whereNotNull('amount_pence')->orderByDesc('id')->value('amount_pence');

        return $last === null || $f->amount_pence === null || abs((int) $f->amount_pence - (int) $last) >= self::MONEY_STEP;
    }

    /**
     * Whether a finding's check going wrong now is a new run of failures: none recorded yet, or it's been looked at
     * (seen, or anything else noted) since the last one.
     */
    private static function newFailure(Finding $f): bool
    {
        $last = $f->events()->reorder()->where('event', 'detector_failed')->orderByDesc('id')->first(['id', 'created_at']);

        return $last === null || ($f->last_seen_at !== null && $f->last_seen_at->gt($last->created_at))
            || $f->events()->reorder()->where('id', '>', $last->id)->exists();
    }

    /**
     * Findings whose state disagrees with how they ended: an earlier version of Expend (redeployed for a while, as
     * section 3.1 allows) ended or reopened them without knowing of states. The state follows how it ended, with the
     * event that says so, so the history and the Telegram buttons read it right.
     */
    private static function repairStates(CycleContext $ctx): void
    {
        Finding::query()->whereNotNull('state')
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('resolved_at')->whereIn('state', ['open', 'unseen']))
                ->orWhere(fn ($q) => $q->whereNull('resolved_at')->whereIn('state', ['snoozed', 'dismissed', 'resolved'])))
            ->orderBy('id')->get()
            ->each(function (Finding $f) use ($ctx) {
                $note = 'Recorded by an earlier version of Expend';
                if ($f->resolved_at === null) {
                    self::change($f, ['state' => 'open', 'note' => null, 'resolution_ref' => null], 'reopened', $ctx, note: $note);

                    return;
                }
                $state = Finding::legacyState(true, $f->resolution);
                self::change($f, ['state' => $state], $state, $ctx, $state === 'resolved' ? 'system' : 'you', note: $note);
            });
    }

    /** Record what happened to a finding, with the money it involves now. */
    public static function event(Finding $f, string $event, ?CycleContext $ctx = null, string $actor = 'system', ?string $cause = null, ?string $note = null, ?Carbon $at = null): FindingEvent
    {
        return FindingEvent::query()->create(['finding_id' => $f->id, 'event' => $event, 'amount_pence' => $f->amount_pence, 'currency' => $f->currency,
            'cause_ref' => $cause !== null && Ref::valid($cause) ? $cause : null, 'run_id' => $ctx?->runId, 'actor' => $actor, 'note' => $note, 'created_at' => $at ?? Carbon::now()]);
    }

    /**
     * What dealt with a finding, as a ref: the latest change recorded since it was last seen to the record it's about
     * (your decision on a payment, an invoice issued, a job updated: its audit event), or for an account, the latest
     * statement imported for it (or a change to its balances). Null when nothing shows: the history then says the
     * records changed.
     */
    private static function causeFor(Finding $f): ?string
    {
        $subject = Ref::parse($f->subject_ref ?? (TodoGroupsDetector::classify($f->key)['subject_ref'] ?? null));
        if ($subject === null) {
            return null;
        }
        $since = ($f->last_seen_at ?? $f->first_seen_at)->copy()->subMinute();
        $audit = function (string $type, array $ids) use ($since): ?AuditEvent {
            return $ids === [] ? null : AuditEvent::query()->where('subject_type', $type)->whereIn('subject_id', $ids)->where('occurred_at', '>=', $since->copy()->utc()->format('Y-m-d H:i:s'))
                ->orderByDesc('id')->first(['id', 'hash', 'occurred_at']);
        };
        $id = $subject['id'];
        $found = match ($subject['kind']) {
            'tx' => $audit('BankTransaction', [(int) $id]),
            'inv' => $audit('Invoice', [(int) $id]),
            'travel' => $audit('TravelItem', [(int) $id]),
            'doc' => $audit('Document', [(int) $id]),
            'cand' => $audit('JobCandidate', [(int) $id]),
            'batch' => $audit('ImportBatch', [(int) $id]),
            'job' => $audit('WorkJob', [(int) $id]) ?? $audit('Invoice', Invoice::query()->where('work_job_id', (int) $id)->pluck('id')->all()),
            'year' => $audit('TaxYear', TaxYear::query()->where('label', $id)->pluck('id')->all()),
            'acct' => null,
            default => null,
        };
        if ($subject['kind'] === 'acct') {
            $batch = ImportBatch::query()->where('account_id', (int) $id)->where('created_at', '>=', $since)->orderByDesc('id')->first(['id', 'file_sha256']);
            if ($batch) {
                return Ref::make('batch', $batch->id, null, $batch->file_sha256 ?: null);
            }
            $found = $audit('Account', [(int) $id]);
        }

        return $found ? Ref::make('audit', $found->id, null, $found->hash ?: null) : null;
    }

    /** A gap's days, as its events note them ("Days 2025-06-02 to 2025-06-09"), or null for any other finding. */
    private static function rangeNote(FindingDraft $d): ?string
    {
        $range = $d->meta['range'] ?? null;

        return is_array($range) && isset($range['from'], $range['to']) ? 'Days '.$range['from'].' to '.$range['to'] : null;
    }

    /**
     * The days a gap finding last covered: as its latest event noted them ("Days 2025-06-02 to 2025-06-09"), or as the
     * key it was kept under before M3 said (gap:{account}:{from}:{to}:{amount}, now or before it was followed under
     * its first day). Null when unknown.
     */
    private static function lastRange(Finding $f): ?string
    {
        if (! str_starts_with($f->key, 'gap:')) {
            return null;
        }
        $date = '\d{4}-\d{2}-\d{2}';
        $dated = fn (string $key) => preg_match('/^gap:\d+:('.$date.'):('.$date.')(?::-?\d+)?(?::(?:twice|date))?$/', $key, $m) ? 'Days '.$m[1].' to '.$m[2] : null;
        $notes = $f->events()->reorder()->where(fn ($q) => $q->where('note', 'like', 'Days %')->orWhere('note', 'like', 'Was gap:%'))->orderByDesc('id')->limit(20)->pluck('note');
        foreach ($notes as $note) {
            if (str_starts_with($note, 'Days ')) {
                return $note;
            }
            if (($range = $dated(substr($note, 4))) !== null) {
                return $range;
            }
        }

        return $dated($f->key);
    }

    /**
     * A gap with the bank is kept by its account and first day, which can move as it's filled (the statement for its
     * first days imported) or when another balance splits or joins it. A gap finding that's gone while a new gap of
     * the same kind overlaps its days is the same gap: it takes the new key and keeps its history (and the most money
     * it involved), with a rekeyed event. One that overlaps a gap already recorded was joined into it: 'superseded',
     * not dealt with (one you marked not relevant stays so). Money missing and a line that may be in twice are
     * different findings, even for the same days.
     *
     * @param  Collection<string, FindingDraft>  $all
     * @return array<string, string> the key each gap followed now was kept under before, by its new key
     */
    private static function followGaps(Collection $all, Carbon $now, CycleContext $ctx): array
    {
        $current = $all->map(fn (FindingDraft $d, string $key) => self::draftGap($key, $d))->filter();
        if ($current->isEmpty()) {
            return [];
        }
        $known = Finding::query()->whereIn('key', $current->keys())->pluck('id', 'key')->all();
        $fresh = $current->reject(fn ($d, $k) => isset($known[$k]));
        $overlaps = fn (array $a, array $b) => $a[0] === $b[0] && $a[3] === $b[3] && $a[1] <= $b[2] && $b[1] <= $a[2];
        $rekeyed = [];
        Finding::query()->where('key', 'like', 'gap:%')->whereNotIn('key', $current->keys()->all())
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhereIn('resolution', ['snoozed', 'dismissed']))->orderBy('id')->get()
            ->each(function (Finding $f) use (&$fresh, &$known, &$rekeyed, $current, $overlaps, $now, $ctx) {
                $dates = self::storedGap($f);
                if (! $dates) {
                    return;
                }
                $to = $fresh->search(fn ($d) => $overlaps($d, $dates));
                if ($to !== false) {
                    FindingsBackfill::adopt($f);
                    $rekeyed[$to] = $f->key;
                    self::change($f, ['key' => $to], 'rekeyed', $ctx, note: 'Was '.$f->key);
                    $fresh->forget($to);
                    // Another gap joined into this one now cites it.
                    $known[$to] = $f->id;
                } elseif ($f->resolution !== 'dismissed' && ($into = $current->search(fn ($d) => $overlaps($d, $dates))) !== false) {
                    FindingsBackfill::adopt($f);
                    $target = $known[$into] ?? null;
                    self::change($f, ['resolution' => 'superseded', 'state' => 'resolved', 'resolution_ref' => $target ? 'fnd:'.$target : null] + ($f->resolved_at === null ? ['resolved_at' => $now] : []),
                        'resolved', $ctx, cause: $target ? 'fnd:'.$target : null, note: 'Joined to another gap');
                }
            });

        return $rekeyed;
    }

    /**
     * [account, from, to, kind] of a gap found now, or null for any other finding.
     *
     * @return array{0:int, 1:string, 2:string, 3:string}|null
     */
    private static function draftGap(string $key, FindingDraft $d): ?array
    {
        $range = $d->meta['range'] ?? null;
        if (! preg_match('/^gap:(\d+):/', $key, $m) || ! is_array($range)) {
            return null;
        }

        return [(int) $m[1], $range['from'], $range['to'], str_ends_with($key, ':twice') ? 'twice' : 'missing'];
    }

    /**
     * [account, from, to, kind] of a gap finding kept before: by its first day (gap:{account}:{from}, its days as its
     * events last noted them), by its dates and amount (gap:{account}:{from}:{to}:{amount}, how gaps were kept before
     * M3), or by its first day and amount (before that). ':twice' marks a line that may be in twice and ':date' one
     * that may be dated differently (a kind of money missing).
     *
     * @return array{0:int, 1:string, 2:string, 3:string}|null
     */
    private static function storedGap(Finding $f): ?array
    {
        $date = '\d{4}-\d{2}-\d{2}';
        if (preg_match('/^gap:(\d+):('.$date.'):('.$date.')(?::-?\d+)?(?::(twice|date))?$/', $f->key, $m)) {
            return [(int) $m[1], $m[2], $m[3], ($m[4] ?? '') === 'twice' ? 'twice' : 'missing'];
        }
        if (preg_match('/^gap:(\d+):('.$date.'):-?\d+$/', $f->key, $m)) {
            return [(int) $m[1], $m[2], $m[2], 'missing'];
        }
        if (preg_match('/^gap:(\d+):('.$date.')(?::(twice|date))?$/', $f->key, $m)) {
            $noted = self::lastRange($f);
            $to = $noted && preg_match('/ to ('.$date.')$/', $noted, $n) ? $n[1] : $m[2];

            return [(int) $m[1], $m[2], max($m[2], $to), ($m[3] ?? '') === 'twice' ? 'twice' : 'missing'];
        }

        return null;
    }

    /**
     * Items kept by what they're about now, whose key changes with it: mileage by its jobs (mileage:{year}:{set}), a
     * filed return by its difference (drift:{year}:{difference}) and failed background work by the newest failure
     * (health:failed_jobs:{id}); and an account with nothing imported lately, kept by its last line before M3
     * (stale:{account}:{line}) and by the account since. One whose key has changed is the same finding: an open (or
     * snoozed) one that's gone while a new key of its kind appears takes the new key and keeps its history, with a
     * rekeyed event, rather than being called dealt with. One gone while its kind's key now is already recorded (an
     * earlier version kept it under the old key for a while, or it went back to one it had before) was followed by
     * that one: 'superseded', citing it, not dealt with. One you marked not relevant stays so: you set aside what it
     * was about, and the new one is new.
     *
     * @param  Collection<string, FindingDraft>  $all
     * @return array<string, string> the key each finding followed now was kept under before, by its new key
     */
    private static function followFamilies(Collection $all, CycleContext $ctx): array
    {
        $current = $all->keys()->filter(fn ($k) => self::family($k) !== null)->mapWithKeys(fn ($k) => [self::family($k) => $k]);
        if ($current->isEmpty()) {
            return [];
        }
        $known = Finding::query()->whereIn('key', $current->values()->all())->pluck('id', 'key')->all();
        $rekeyed = [];
        Finding::query()->where(fn ($q) => $q->where('key', 'like', 'mileage:%')->orWhere('key', 'like', 'drift:%')->orWhere('key', 'like', 'health:failed_jobs:%')->orWhere('key', 'like', 'stale:%'))
            ->whereNotIn('key', $all->keys()->all())->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolution', 'snoozed'))->orderByDesc('id')->get()
            ->each(function (Finding $f) use ($current, &$known, &$rekeyed, $ctx) {
                $family = self::family($f->key);
                $to = $family !== null ? ($current[$family] ?? null) : null;
                if ($to === null) {
                    return;
                }
                FindingsBackfill::adopt($f);
                if (! isset($known[$to])) {
                    $rekeyed[$to] = $f->key;
                    self::change($f, ['key' => $to], 'rekeyed', $ctx, note: 'Was '.$f->key);
                    $known[$to] = $f->id;

                    return;
                }
                $cause = 'fnd:'.$known[$to];
                self::change($f, ['resolution' => 'superseded', 'state' => 'resolved', 'resolution_ref' => $cause] + ($f->resolved_at === null ? ['resolved_at' => Carbon::now()] : []),
                    'resolved', $ctx, cause: $cause, note: 'Followed by another of its kind');
            });

        return $rekeyed;
    }

    /** What a key changing with what it's about is about: mileage:{year} (or mileage:setup), drift:{year}, health:failed_jobs, stale:{account}. */
    private static function family(string $key): ?string
    {
        if (preg_match('/^((?:mileage|drift):[^:]+|health:failed_jobs):[^:]+$/', $key, $m)) {
            return $m[1];
        }

        return preg_match('/^(stale:\d+)(?::.+)?$/', $key, $m) ? $m[1] : null;
    }

    /** The currency of a finding's amount: its own (an invoice, booking or pocket in another currency), or null for pounds. */
    private static function currency(?string $currency, ?int $amount): ?string
    {
        $code = strtoupper((string) $currency);

        return $code === '' || $code === 'GBP' || $amount === null ? null : $code;
    }

    /** The most of each finding's history the history page is sent; the rest comes when asked for (eventsOf()). */
    public const EVENTS_SHOWN = 20;

    /**
     * What the history page shows. Money is only ever added up by which way it goes (owed to you, owed by you, at
     * risk, …) and by currency, never across either ('by_direction'): there's no one total of everything. The list
     * has the open findings and, separately, the latest to have ended, so neither crowds out the other, each with the
     * latest EVENTS_SHOWN of its history (and how many there are), what dealt with it, and your reason for hiding it.
     * $scope keeps to the findings of one job, account or year (job:14, acct:3, year:2024-25).
     *
     * @return array{open:int, resolved:int, unseen:int, by_direction:list<array{direction:string, label:string, open:array<string,int>, resolved:array<string,int>}>,
     *               by_group:array, scopes:list<array>, scope:?array, recent:list<array>}
     */
    public static function summary(?string $since = null, ?string $scope = null): array
    {
        $since ??= Carbon::now()->subYear()->format('Y-m-d');
        $scope = $scope !== null && Ref::valid($scope) ? $scope : null;
        $within = fn ($q) => $scope === null ? $q : $q->where('scope_ref', $scope);
        $open = $within(Finding::query()->whereNull('resolved_at'));
        $done = $within(Finding::query()->where('resolution', 'resolved')->where('resolved_at', '>=', $since));
        // Open: what's still involved now. Dealt with: the most each involved (a gap filled in stages, all of it).
        $amountOf = fn (Finding $f, bool $peak) => abs((int) ($peak ? ($f->peak_amount_pence ?? $f->amount_pence) : $f->amount_pence));
        $money = fn ($q) => (clone $q)->whereNotNull('amount_pence')->get(['key', 'amount_pence', 'peak_amount_pence', 'currency', 'direction']);
        $byCurrency = fn (Collection $rows, bool $peak) => $rows->groupBy(fn (Finding $f) => $f->currency ?: 'GBP')->map(fn ($fs) => (int) $fs->sum(fn (Finding $f) => $amountOf($f, $peak)));
        $byDirection = fn (Collection $rows, bool $peak) => $rows->groupBy(fn (Finding $f) => $f->direction ?? (TodoGroupsDetector::classify($f->key, $f->amount_pence)['direction'] ?? null) ?? 'none')
            ->map(fn ($fs) => $byCurrency($fs, $peak)->sortKeys()->all())->all();
        $directions = [];
        foreach (['open' => $byDirection($money($open), false), 'resolved' => $byDirection($money($done), true)] as $which => $totals) {
            foreach ($totals as $direction => $currencies) {
                $directions[$direction]['direction'] = $direction;
                $directions[$direction]['label'] = self::DIRECTION_TOTALS[$direction] ?? self::DIRECTION_TOTALS['none'];
                $directions[$direction][$which] = $currencies;
            }
        }
        $directions = collect($directions)->sortBy(fn ($d) => array_search($d['direction'], array_keys(self::DIRECTION_TOTALS), true))
            ->map(fn ($d) => $d + ['open' => [], 'resolved' => []])->values()->all();

        $rows = (clone $open)->orderByDesc('first_seen_at')->orderByDesc('id')->limit(100)->get()
            ->concat($within(Finding::query()->whereNotNull('resolved_at'))->orderByDesc('resolved_at')->orderByDesc('id')->limit(100)->get());
        $ids = $rows->pluck('id')->all() ?: [0];
        $events = self::latestEvents($ids, self::EVENTS_SHOWN)->groupBy('finding_id');
        $counts = FindingEvent::query()->whereIn('finding_id', $ids)->selectRaw('finding_id, COUNT(*) n')->groupBy('finding_id')->pluck('n', 'finding_id');
        $refs = $rows->flatMap(fn (Finding $f) => [$f->resolution_ref, $f->scope_ref])->merge($events->flatten()->pluck('cause_ref'))->filter()->unique()->values()->all();
        $scopeRefs = $within(Finding::query())->whereNotNull('scope_ref')->where('first_seen_at', '>=', $since)->selectRaw('scope_ref, COUNT(*) n')->groupBy('scope_ref')
            ->orderByDesc('n')->limit(30)->get();
        $resolved = RefResolver::resolveMany([...$refs, ...$scopeRefs->pluck('scope_ref')->all(), ...($scope ? [$scope] : [])]);
        $chip = self::chipper($resolved);
        $row = fn (Finding $f) => [
            'id' => $f->id, 'key' => $f->key, 'group' => TodoList::GROUPS[$f->group] ?? $f->group, 'title' => $f->title, 'detail' => $f->detail, 'href' => $f->href,
            'amount_pence' => $f->resolution === 'resolved' && $f->peak_amount_pence !== null ? (int) $f->peak_amount_pence : $f->amount_pence, 'currency' => $f->currency ?: 'GBP', 'first_seen' => $f->first_seen_at->format('Y-m-d'), 'resolved' => $f->resolved_at?->format('Y-m-d'),
            'resolution' => $f->resolution, 'days_open' => (int) $f->first_seen_at->diffInDays($f->resolved_at ?? Carbon::now()), 'reopened' => $f->reopened,
            'state' => $f->currentState(), 'code' => $f->code, 'direction' => $f->direction, 'grade' => $f->grade, 'severity' => $f->severity, 'note' => $f->note,
            'cause' => $chip($f->resolution_ref), 'scope' => $chip($f->scope_ref),
            'events' => self::eventRows($events[$f->id] ?? collect(), $chip),
            'events_total' => (int) ($counts[$f->id] ?? 0),
        ];

        return [
            'open' => (clone $open)->count(),
            'resolved' => (clone $done)->count(),
            'unseen' => (clone $open)->where('state', 'unseen')->count(),
            'by_direction' => $directions,
            // Found: everything first seen since (less gaps joined into another); dealt with: those that were.
            'by_group' => $within(Finding::query())->where('first_seen_at', '>=', $since)->where(fn ($q) => $q->whereNull('resolution')->orWhere('resolution', '!=', 'superseded'))
                ->selectRaw("`group`, COUNT(*) n, SUM(CASE WHEN resolution = 'resolved' THEN 1 ELSE 0 END) done")
                ->groupBy('group')->get()->map(fn ($r) => ['group' => $r->group, 'label' => TodoList::GROUPS[$r->group] ?? $r->group, 'found' => (int) $r->n, 'dealt_with' => (int) $r->done])->values()->all(),
            'scopes' => $scopeRefs->map(fn ($s) => ($chip($s->scope_ref) ?? ['ref' => $s->scope_ref, 'label' => $s->scope_ref, 'href' => null, 'state' => 'source_missing']) + ['count' => (int) $s->n])->values()->all(),
            'scope' => $scope ? ($chip($scope) ?? ['ref' => $scope, 'label' => $scope, 'href' => null, 'state' => 'source_missing']) : null,
            'recent' => $rows->map($row)->values()->all(),
        ];
    }

    /**
     * The whole history of one finding, oldest first, for the history page's "Show all" (it's sent the latest
     * EVENTS_SHOWN).
     *
     * @return array{id:int, events:list<array>}|null
     */
    public static function eventsOf(int $id): ?array
    {
        if (! Finding::query()->whereKey($id)->exists()) {
            return null;
        }
        $events = FindingEvent::query()->where('finding_id', $id)->orderBy('id')->get();

        return ['id' => $id, 'events' => self::eventRows($events, self::chipper(RefResolver::resolveMany($events->pluck('cause_ref')->filter()->unique()->values()->all())))];
    }

    /**
     * The latest $per events of each of these findings, oldest first: one query, however many findings.
     *
     * @param  list<int>  $ids
     * @return Collection<int, FindingEvent>
     */
    private static function latestEvents(array $ids, int $per): Collection
    {
        $ranked = FindingEvent::query()->whereIn('finding_id', $ids)->select('finding_events.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY finding_id ORDER BY id DESC) AS nth');

        return FindingEvent::query()->fromSub($ranked, 'finding_events')->where('nth', '<=', $per)->orderBy('id')->get();
    }

    /** @return callable(?string): ?array a ref as the history page shows it */
    private static function chipper(array $resolved): callable
    {
        return fn (?string $ref) => $ref !== null && isset($resolved[$ref]) ? ['ref' => $ref, 'label' => $resolved[$ref]['label'], 'href' => $resolved[$ref]['href'], 'state' => $resolved[$ref]['state']] : null;
    }

    /** @param  Collection<int, FindingEvent>  $events */
    private static function eventRows(Collection $events, callable $chip): array
    {
        return $events->map(fn (FindingEvent $e) => ['event' => $e->event, 'at' => $e->created_at?->format('Y-m-d'), 'amount_pence' => $e->amount_pence,
            'currency' => $e->currency ?: 'GBP', 'actor' => $e->actor, 'note' => $e->note, 'cause' => $chip($e->cause_ref)])->values()->all();
    }
}
