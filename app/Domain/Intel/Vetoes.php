<?php

namespace App\Domain\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Matching\TransferPairer;
use App\Domain\Todo\TodoList;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Document;
use App\Models\JobFind;
use App\Models\ManualExpense;
use App\Models\TodoDismissal;
use App\Models\Veto;
use App\Models\WorkJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Rejection memory (docs/intelligence-cycle.md 4.6 and M7): what you said no to, so nothing automatic proposes it again.
 *
 * A veto is narrow. It is about one thing (a payment, a pair of payments, a stay, an invoice, a job) and either the
 * shape of a decision that was proposed for it (its kind, category, business share and job: shape()) or the thing it
 * was linked to; and it holds back one automatic method, or every one (method null). It never holds you back: your own
 * decisions are never checked against it, so you can still choose what you said no to. Each one is visible (about())
 * and can be lifted (lift()); recording and lifting are both audited, with who did it.
 *
 * Two effects: block (never propose it) and ask (propose it, never act on it: a write that would confirm on its own is
 * written as a suggestion instead).
 *
 * The rejection stores that existed before vetoes stay authoritative for their links and are read here through
 * adapters, never copied: a job's "not this job" (job_finds rejected) for an invoice on a job, the to-do list's "not
 * relevant" on mileage (todo_dismissals mileage_job:…) for mileage, a calendar entry's rejected jobs and "not a job"
 * (calendar_job_links), and a document's rejected jobs (documents.job_rejected).
 */
final class Vetoes
{
    /** The reasons offered with "Not this one", in plain words. */
    public const REASONS = [
        'wrong_category' => 'Wrong category',
        'wrong_job' => 'Wrong job',
        'not_business' => 'Not for work',
        'not_mine' => 'Not mine',
        'other' => 'Other',
    ];

    /** Recorded by Expend itself for a decision of yours (not offered as a reason). */
    public const OWN_REASONS = ['changed' => 'You decided it differently', 'unpaired' => 'You said it isn’t a transfer', 'client_booked' => 'You said the client paid for it',
        'not_this_job' => 'You said it isn’t this job’s', 'no_drive' => 'You didn’t drive', 'rule_undone' => 'You undid the rule', 'cleared' => 'You sent it back'];

    /** Links whose only rejection store is one that existed before vetoes: read here, never recorded here. */
    public const ADAPTED = ['calendar_job', 'document_job'];

    /** What a ref's kind is in the audit log, so a veto shows in the history of what it's about. */
    private const SUBJECT_TYPES = ['tx' => 'BankTransaction', 'exp' => 'ManualExpense', 'travel' => 'TravelItem', 'inv' => 'Invoice', 'job' => 'WorkJob', 'doc' => 'Document'];

    /**
     * A decision's shape: a short hash of what each line is (its kind, category, business share and job), whatever the
     * amounts. Lines given as AllocationWriter takes them, or as allocations.
     *
     * @param  iterable<array<string, mixed>|Allocation>  $lines
     */
    public static function shape(iterable $lines): string
    {
        return substr(hash('sha256', json_encode(self::shapeParts($lines))), 0, 16);
    }

    /**
     * The parts a shape is made from, in order: [kind, category, business share, job] for each line.
     *
     * @param  iterable<array<string, mixed>|Allocation>  $lines
     * @return list<array{0:string, 1:?int, 2:?int, 3:?int}>
     */
    public static function shapeParts(iterable $lines): array
    {
        $parts = [];
        foreach ($lines as $line) {
            $l = $line instanceof Allocation ? $line->only(['kind', 'category_id', 'business_use_pct', 'work_job_id']) : $line;
            $kind = (string) ($l['kind'] ?? '');
            $categorised = in_array($kind, Allocation::NEEDS_CATEGORY, true);
            $int = fn ($v) => $v === null || $v === '' ? null : (int) $v;
            $parts[] = [$kind, $categorised ? $int($l['category_id'] ?? null) : null, $categorised ? ($int($l['business_use_pct'] ?? null) ?? 100) : null, $int($l['work_job_id'] ?? null)];
        }
        usort($parts, fn ($a, $b) => json_encode($a) <=> json_encode($b));

        return $parts;
    }

    /** The two payments of a pair, as a veto keeps them: the lower id first. @return array{0:string, 1:string} */
    public static function pairRefs(int $a, int $b): array
    {
        return ['tx:'.min($a, $b), 'tx:'.max($a, $b)];
    }

    public static function key(string $relation, string $subjectRef, ?string $objectRef, ?string $shape, ?string $method): string
    {
        return hash('sha256', implode("\x1f", [$subjectRef, $relation, $objectRef ?? '', $shape ?? '', $method ?? '']));
    }

    /**
     * Remember that you said no. Recorded once: saying it again changes nothing (and isn't audited again); saying it
     * again after it was lifted puts it back, audited. Audited as the surrounding AuditContext's actor ('you' outside
     * one), in the history of what it's about.
     *
     * @param  string|null  $method  the automatic method it holds back, or null for every one
     * @param  list<array{0:string, 1:?int, 2:?int, 3:?int}>|null  $what  the shape's parts (shapeParts()), kept in the audit
     *                                                                    event so it can be described later
     */
    public static function record(string $relation, string $subjectRef, ?string $objectRef = null, ?string $shape = null, ?string $method = null,
        string $effect = 'block', ?string $reasonCode = null, ?string $reason = null, ?array $what = null): Veto
    {
        if (! isset(Veto::RELATIONS[$relation])) {
            throw new InvalidArgumentException("Unknown veto relation “{$relation}”.");
        }
        if (! Ref::valid($subjectRef) || ($objectRef !== null && ! Ref::valid($objectRef))) {
            throw new InvalidArgumentException('A veto is about refs (tx:381, job:14).');
        }
        if (! in_array($effect, Veto::EFFECTS, true)) {
            throw new InvalidArgumentException("Unknown veto effect “{$effect}”.");
        }
        if ($method !== null && ! Methods::has($method)) {
            throw new InvalidArgumentException("Unknown method “{$method}”.");
        }
        if ($shape !== null && ! preg_match('/^[0-9a-f]{16}$/', $shape)) {
            throw new InvalidArgumentException('A shape is 16 hexadecimal characters (Vetoes::shape()).');
        }
        $key = self::key($relation, $subjectRef, $objectRef, $shape, $method);
        $reason = $reason !== null && mb_strlen($reason) > 255 ? mb_substr($reason, 0, 254).'…' : $reason;

        return DB::transaction(function () use ($key, $relation, $subjectRef, $objectRef, $shape, $method, $effect, $reasonCode, $reason, $what) {
            $veto = Veto::query()->where('veto_key', $key)->lockForUpdate()->first();
            if ($veto && $veto->isActive() && $veto->effect === $effect) {
                return $veto; // said already: nothing new to remember
            }
            $before = $veto ? self::snapshot($veto) : null;
            $veto ??= new Veto(['veto_key' => $key]);
            $veto->fill([
                'subject_ref' => $subjectRef, 'relation' => $relation, 'object_ref' => $objectRef, 'shape' => $shape, 'method' => $method,
                'effect' => $effect, 'reason_code' => $reasonCode !== null ? mb_substr($reasonCode, 0, 16) : null, 'reason' => $reason, 'lifted_at' => null,
            ])->save();
            $event = self::audit('veto.recorded', $veto, $before, self::snapshot($veto) + ($what !== null ? ['what' => $what] : []), $reason ?? self::reasonWords($reasonCode));
            $veto->forceFill(['audit_event_id' => $event->id])->saveQuietly();

            return $veto;
        });
    }

    /**
     * When you clear an automatic suggestion, or decide differently from it: remember what it was, so nothing automatic
     * proposes it for this line again (docs/intelligence-cycle.md 4.6). Only an open suggestion counts: one made by a
     * matcher, the AI or a rule, not confirmed. A line you decided, or a suggestion you accepted, isn't one. The invoice
     * it was matched to is remembered as not this payment's (unless you kept it); otherwise its shape (what each line
     * was), unless your decision has the same shape. Every automatic method is held back from it.
     *
     * @param  Collection<int, Allocation>  $was  the line's allocations before you changed them
     * @param  list<array<string, mixed>>|null  $now  your decision's lines, or null when you cleared it
     */
    public static function fromSuggestion(BankTransaction|ManualExpense $source, Collection $was, ?array $now, string $reasonCode, ?string $reason = null): ?Veto
    {
        if (! self::isOpenSuggestion($was)) {
            return null;
        }
        $subject = ($source instanceof BankTransaction ? 'tx:' : 'exp:').$source->getKey();
        $reason ??= self::reasonWords($reasonCode);
        $invoices = $was->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($invoices->isNotEmpty()) {
            $kept = collect($now ?? [])->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->all();
            $last = null;
            foreach ($invoices->reject(fn (int $id) => in_array($id, $kept, true)) as $id) {
                $last = self::record('invoice_payment', $subject, 'inv:'.$id, null, null, 'block', $reasonCode, $reason);
            }

            return $last;
        }
        $shape = self::shape($was);
        if ($now !== null && self::shape($now) === $shape) {
            return null; // decided as it was suggested (with a note, or another split of the same): nothing said no to
        }

        return self::record('allocation_shape', $subject, null, $shape, null, 'block', $reasonCode, $reason, self::shapeParts($was));
    }

    /**
     * Whether a line's allocations are an open automatic suggestion: made by a matcher, the AI or a rule, none of it
     * confirmed. Saying no to one is remembered (fromSuggestion()); a decision of yours, or one you accepted, isn't one.
     *
     * @param  Collection<int, Allocation>  $allocations
     */
    public static function isOpenSuggestion(Collection $allocations): bool
    {
        return $allocations->isNotEmpty() && ! $allocations->contains(fn (Allocation $a) => $a->is_manual || $a->confirmed_at !== null || $a->decided_by === 'you');
    }

    /**
     * Whether a line's allocations are the ones a page showed, by id ($shown): a suggestion that replaces another is
     * written as new lines, so its ids are new. What you say no to is bound to what you saw (docs/intelligence-cycle.md
     * 4.5): a suggestion that replaced it since isn't the one you meant.
     *
     * @param  Collection<int, Allocation>  $now
     * @param  list<int|string>|null  $shown
     */
    public static function isShown(Collection $now, ?array $shown): bool
    {
        $a = $now->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $b = collect($shown ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $a === $b;
    }

    /**
     * Lift the vetoes a decision of yours recorded on this line (fromSuggestion()), when you undo that decision straight
     * away (the Inbox's Undo): undoing puts things back as they were, so it neither records a veto nor keeps one. Only
     * those recorded since the line's latest decision: what it was suggested as, the invoice it was matched to, and the
     * pair it was unpaired from (TransferPairer::afterYourDecision, kept under the lower id of the two, so this line can
     * be either side), so the next pairing can pair the two again.
     */
    public static function liftSinceLastDecision(BankTransaction|ManualExpense $source, string $reason = 'Undone'): int
    {
        $type = $source instanceof BankTransaction ? 'BankTransaction' : 'ManualExpense';
        $since = AuditEvent::query()->where('subject_type', $type)->where('subject_id', $source->getKey())
            ->whereIn('action', ['allocation.decided', 'allocation.confirmed'])->max('id');
        if ($since === null) {
            return 0;
        }
        $lifted = 0;
        $ref = ($source instanceof BankTransaction ? 'tx:' : 'exp:').$source->getKey();
        // The pairs this decision unpaired: this line's own "you decided it isn't a transfer" since then (never one its
        // other side's decision unpaired, which is that decision's to undo).
        $pairs = $source instanceof BankTransaction ? AuditEvent::query()->where('subject_type', $type)->where('subject_id', $source->getKey())
            ->where('action', 'transfer.unpaired')->where('reason', TransferPairer::UNPAIRED_BY_YOU)->where('id', '>', $since)->get()
            ->map(fn (AuditEvent $e) => (int) (is_array($e->before) ? ($e->before['transfer_pair_id'] ?? 0) : 0))->filter()
            ->map(fn (int $partner) => self::pairRefs((int) $source->getKey(), $partner))->values()->all() : [];
        $vetoes = Veto::query()->active()->where('audit_event_id', '>', $since)
            ->where(function ($q) use ($ref, $pairs) {
                $q->where(fn ($q) => $q->where('subject_ref', $ref)->whereIn('relation', ['allocation_shape', 'invoice_payment']));
                foreach ($pairs as [$a, $b]) {
                    $q->orWhere(fn ($q) => $q->where('relation', 'transfer_pair')->where('subject_ref', $a)->where('object_ref', $b));
                }
            })->get();
        foreach ($vetoes as $veto) {
            $lifted += (int) self::lift($veto, $reason);
        }

        return $lifted;
    }

    /**
     * Lift a veto: what it held back may be proposed again. Audited, as the surrounding AuditContext's actor. A rejection
     * kept in an older store for the same thing (a job's "not this job" for an invoice, the to-do list's "not relevant" on
     * a job's mileage) is lifted with it, so lifting is all it takes. Lifting one that's lifted already changes nothing.
     */
    public static function lift(Veto $veto, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($veto, $reason) {
            $veto = Veto::query()->whereKey($veto->id)->lockForUpdate()->first();
            if (! $veto || ! $veto->isActive()) {
                return false;
            }
            $before = self::snapshot($veto);
            $veto->forceFill(['lifted_at' => now()])->save();
            $cleared = self::liftAdapted($veto->relation, $veto->subject_ref, $veto->object_ref);
            self::audit('veto.lifted', $veto, $before, self::snapshot($veto) + ($cleared ? ['also_cleared' => $cleared] : []), $reason ?? 'Lifted');

            return true;
        });
    }

    /**
     * Lift what the older stores hold for a job's mileage (the to-do list's "not relevant", which left the job out), when
     * no veto row was ever written for it: the Mileage page's "Put back". Audited.
     */
    public static function liftMileage(int $jobId, ?string $reason = null): int
    {
        $vetoes = Veto::query()->active()->where('relation', 'mileage_day')->where('subject_ref', 'job:'.$jobId)->get();
        $lifted = 0;
        foreach ($vetoes as $veto) {
            $lifted += (int) self::lift($veto, $reason);
        }
        if ($lifted === 0) {
            $cleared = DB::transaction(fn () => self::liftAdapted('mileage_day', 'job:'.$jobId, null));
            if ($cleared) {
                AuditLog::record('veto.lifted', 'WorkJob', null, ['relation' => 'mileage_day', 'subject' => 'job:'.$jobId, 'also_cleared' => $cleared], $reason ?? 'Put back', subjectId: $jobId);
                $lifted = 1;
            }
        }
        if ($lifted > 0) {
            TodoList::forget();
        }

        return $lifted;
    }

    /**
     * Whether an automatic method may make this link or decision: no active veto holds it back, and no older rejection
     * store says no. Your own decisions are never checked against this.
     *
     * @param  string|null  $objectRef  what it would be linked to (inv:9, tx:382, job:14), or null
     * @param  string|null  $shape  the decision's shape (shape()), for allocation_shape
     * @param  string|null  $method  the method about to write
     * @param  bool  $acting  whether it would act (confirm on its own) rather than only propose: an 'ask' veto stops that
     */
    public static function allows(string $relation, string $subjectRef, ?string $objectRef = null, ?string $shape = null, ?string $method = null, bool $acting = false): bool
    {
        if ($relation === 'transfer_pair' && $objectRef !== null && preg_match('/^tx:(\d+)$/', $subjectRef, $a) && preg_match('/^tx:(\d+)$/', $objectRef, $b)) {
            [$subjectRef, $objectRef] = self::pairRefs((int) $a[1], (int) $b[1]);
        }
        if (! in_array($relation, self::ADAPTED, true)) {
            $vetoes = Veto::query()->active()->where('subject_ref', $subjectRef)->where('relation', $relation)->get();
            if (self::matching($vetoes, $objectRef, $shape, $method, $acting)->isNotEmpty()) {
                return false;
            }
        }

        return ! self::adapted($relation, $subjectRef, $objectRef);
    }

    /**
     * Whether an 'ask' veto (only) holds back acting on this: a write that would confirm on its own is written as a
     * suggestion instead.
     */
    public static function asks(string $relation, string $subjectRef, ?string $objectRef = null, ?string $shape = null, ?string $method = null): bool
    {
        return self::allows($relation, $subjectRef, $objectRef, $shape, $method) && ! self::allows($relation, $subjectRef, $objectRef, $shape, $method, true);
    }

    /**
     * The active vetoes of one relation for many subjects at once (a nightly pass), by subject.
     *
     * @param  iterable<string>  $subjectRefs
     * @return Collection<string, Collection<int, Veto>>
     */
    public static function forSubjects(string $relation, iterable $subjectRefs): Collection
    {
        $refs = collect($subjectRefs)->filter()->unique()->values();
        if ($refs->isEmpty()) {
            return collect();
        }

        return $refs->chunk(500)->flatMap(fn (Collection $chunk) => Veto::query()->active()->where('relation', $relation)->whereIn('subject_ref', $chunk->all())->get())
            ->groupBy('subject_ref');
    }

    /**
     * Of these vetoes (one subject's), the ones that hold back this write.
     *
     * @param  Collection<int, Veto>  $vetoes
     * @return Collection<int, Veto>
     */
    public static function matching(Collection $vetoes, ?string $objectRef, ?string $shape, ?string $method, bool $acting = false): Collection
    {
        return $vetoes->filter(fn (Veto $v) => $v->isActive()
            && ($v->object_ref === null || $v->object_ref === $objectRef)
            && ($v->shape === null || $v->shape === $shape)
            && ($v->method === null || $v->method === $method)
            && ($v->effect === 'block' || $acting))->values();
    }

    /**
     * Every pair of payments you said isn't a transfer, as "lower id|higher id" (TransferPairer leaves them unpaired).
     *
     * @return array<string, true>
     */
    public static function vetoedPairs(): array
    {
        $out = [];
        foreach (Veto::query()->active()->where('relation', 'transfer_pair')->get(['subject_ref', 'object_ref']) as $v) {
            if (preg_match('/^tx:(\d+)$/', $v->subject_ref, $a) && preg_match('/^tx:(\d+)$/', (string) $v->object_ref, $b)) {
                $out[min((int) $a[1], (int) $b[1]).'|'.max((int) $a[1], (int) $b[1])] = true;
            }
        }

        return $out;
    }

    /**
     * The jobs (and days of jobs) you said you didn't drive to: a veto from the Mileage page's "Didn't drive", or the
     * to-do list's "not relevant" on mileage (which leaves each of its jobs out).
     *
     * @return array{jobs:array<int, true>, days:array<int, array<string, true>>} whole jobs, and single days (Y-m-d) by job
     */
    public static function notDriven(): array
    {
        $jobs = [];
        $days = [];
        foreach (Veto::query()->active()->where('relation', 'mileage_day')->get(['subject_ref', 'object_ref']) as $v) {
            if (! preg_match('/^job:(\d+)$/', $v->subject_ref, $m)) {
                continue;
            }
            if ($v->object_ref === null) {
                $jobs[(int) $m[1]] = true;
            } elseif (preg_match('/^jday:(\d+)$/', $v->object_ref, $d)) {
                $days[(int) $m[1]]['#'.$d[1]] = true;
            }
        }
        foreach (array_keys(TodoDismissal::activeKeys()) as $key) {
            if (str_starts_with($key, TodoList::MILEAGE_JOB)) {
                $jobs[(int) substr($key, strlen(TodoList::MILEAGE_JOB))] = true;
            }
        }
        if ($days !== []) {
            // Single days are kept by their job_days row: read as dates.
            $ids = collect($days)->flatMap(fn ($d) => array_map(fn ($k) => (int) substr($k, 1), array_keys($d)))->all();
            $dates = DB::table('job_days')->whereIn('id', $ids)->get(['id', 'work_job_id', 'on_date']);
            $days = [];
            foreach ($dates as $row) {
                $days[(int) $row->work_job_id][substr((string) $row->on_date, 0, 10)] = true;
            }
        }
        ksort($jobs);

        return ['jobs' => $jobs, 'days' => $days];
    }

    /**
     * The active vetoes about something (a payment, a stay, an invoice, a job): what you said no to, to show and lift.
     *
     * @return list<array{id:int, relation:string, about:string, what:string, method:?string, reason:?string, at:?string}>
     */
    public static function about(string $ref): array
    {
        $vetoes = Veto::query()->active()->where(fn ($q) => $q->where('subject_ref', $ref)->orWhere('object_ref', $ref))->orderBy('id')->limit(50)->get();
        if ($vetoes->isEmpty()) {
            return [];
        }
        $events = AuditEvent::query()->whereIn('id', $vetoes->pluck('audit_event_id')->filter()->all() ?: [0])->get()->keyBy('id');

        return $vetoes->map(fn (Veto $v) => [
            'id' => $v->id, 'relation' => $v->relation, 'about' => Veto::RELATIONS[$v->relation] ?? $v->relation,
            'what' => self::describe($v, $events[$v->audit_event_id] ?? null, $ref), 'method' => $v->method,
            'method_label' => $v->method !== null && Methods::has($v->method) ? Methods::describe($v->method) : null,
            'effect' => $v->effect, 'reason' => $v->reason ?? self::reasonWords($v->reason_code), 'at' => $v->created_at?->format('Y-m-d'),
        ])->values()->all();
    }

    /** A veto in plain words: "Not: Business cost · Food and drink", "Not paired with Starling · 3 Mar · £50.00". */
    public static function describe(Veto $v, ?AuditEvent $event = null, ?string $viewer = null): string
    {
        $what = is_array($event?->after) ? ($event->after['what'] ?? null) : null;
        // The other side, as seen from $viewer: a pair is kept with its lower id first, whichever side you're looking at.
        $otherRef = $v->relation === 'transfer_pair' && $viewer === $v->object_ref ? $v->subject_ref : $v->object_ref;
        $object = $otherRef !== null ? (RefResolver::resolveMany([$otherRef])[$otherRef]['label'] ?? $otherRef) : null;

        return match ($v->relation) {
            'allocation_shape' => 'Not: '.(is_array($what) ? self::shapeWords($what) : 'what was suggested then'),
            'transfer_pair' => 'Not a transfer with '.($object ?? 'the other payment'),
            'invoice_payment' => 'Not the payment for '.($object ?? 'that invoice'),
            'travel_payment' => 'Not the payment for this stay or journey: '.($object ?? 'that payment'),
            'invoice_job' => 'Not on '.($object ?? 'that job'),
            'mileage_day' => 'No mileage: you didn’t drive'.($object !== null ? ' that day' : ''),
            'receipt_payment' => 'Not the receipt for '.($object ?? 'that payment'),
            default => 'Not this',
        };
    }

    /**
     * A shape's parts in words: "Business cost · Food and drink · 50% business · Arena load-in".
     *
     * @param  list<array{0:string, 1:?int, 2:?int, 3:?int}>  $parts
     * @param  Collection<int, string>|null  $categories  category names by id, when already loaded (many at once)
     * @param  Collection<int, string>|null  $jobs  job titles by id, when already loaded
     */
    public static function shapeWords(array $parts, ?Collection $categories = null, ?Collection $jobs = null): string
    {
        $categories ??= Category::query()->whereIn('id', array_filter(array_column($parts, 1)) ?: [0])->pluck('name', 'id');
        $jobs ??= WorkJob::query()->whereIn('id', array_filter(array_column($parts, 3)) ?: [0])->pluck('title', 'id');

        return collect($parts)->map(fn (array $p) => implode(' · ', array_filter([
            Allocation::KINDS[$p[0]] ?? $p[0],
            $p[1] !== null ? ($categories[$p[1]] ?? null) : null,
            $p[2] !== null && $p[2] < 100 ? $p[2].'% business' : null,
            $p[3] !== null ? ($jobs[$p[3]] ?? 'a job') : null,
        ])))->implode(' + ');
    }

    public static function reasonWords(?string $code): ?string
    {
        return $code === null ? null : (self::REASONS[$code] ?? self::OWN_REASONS[$code] ?? null);
    }

    /** @return array<string, mixed> */
    private static function snapshot(Veto $v): array
    {
        return $v->only(['relation', 'subject_ref', 'object_ref', 'shape', 'method', 'effect', 'reason_code']) + ['lifted' => $v->lifted_at !== null];
    }

    /** In the history of what it's about (the payment, the stay), so the why-panel shows it there. */
    private static function audit(string $action, Veto $veto, ?array $before, array $after, ?string $reason): AuditEvent
    {
        $r = Ref::parse($veto->subject_ref);
        $type = $r !== null && ctype_digit($r['id']) ? (self::SUBJECT_TYPES[$r['kind']] ?? null) : null;

        return $type !== null
            ? AuditLog::record($action, $type, $before, $after + ['veto' => $veto->id], $reason, subjectId: (int) $r['id'])
            : AuditLog::record($action, $veto, $before, $after, $reason);
    }

    /** Whether an older rejection store says no to this. */
    private static function adapted(string $relation, string $subjectRef, ?string $objectRef): bool
    {
        $id = fn (?string $ref, string $kind) => $ref !== null && preg_match('/^'.$kind.':(\d+)$/', $ref, $m) ? (int) $m[1] : null;

        return match ($relation) {
            // "Not this job" on an invoice the job's search found (JobGatherer::reject).
            'invoice_job' => ($inv = $id($subjectRef, 'inv')) !== null && ($job = $id($objectRef, 'job')) !== null
                && JobFind::query()->where('work_job_id', $job)->where('type', 'invoice')->where('ref', (string) $inv)->where('status', 'rejected')->exists(),
            // "Not relevant" on mileage to claim, which leaves each of its jobs out (TodoList::mileageJobKey()).
            'mileage_day' => ($job = $id($subjectRef, 'job')) !== null
                && TodoDismissal::query()->where('key', 'like', TodoList::MILEAGE_JOB.$job.':%')->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))->exists(),
            'calendar_job' => self::calendarRejected($subjectRef, $id($objectRef, 'job')),
            'document_job' => ($doc = $id($subjectRef, 'doc')) !== null && ($job = $id($objectRef, 'job')) !== null
                && in_array($job, (array) (Document::query()->whereKey($doc)->first(['id', 'job_rejected'])?->job_rejected ?? []), true),
            default => false,
        };
    }

    private static function calendarRejected(string $subjectRef, ?int $jobId): bool
    {
        if (! str_starts_with($subjectRef, 'cal:')) {
            return false;
        }
        $link = CalendarJobLink::query()->where('event_key', substr($subjectRef, 4))->first();

        return $link !== null && ($link->not_job || ($jobId !== null && in_array($jobId, (array) ($link->rejected ?? []), true)));
    }

    /**
     * Clear what an older store holds for the same thing, when its veto is lifted: the job's "not this job" for the
     * invoice, or the to-do list's "not relevant" on the job's mileage.
     *
     * @return list<string> what was cleared, for the audit
     */
    private static function liftAdapted(string $relation, string $subjectRef, ?string $objectRef): array
    {
        $cleared = [];
        if ($relation === 'invoice_job' && preg_match('/^inv:(\d+)$/', $subjectRef, $i) && $objectRef !== null && preg_match('/^job:(\d+)$/', $objectRef, $j)) {
            $finds = JobFind::query()->where('work_job_id', (int) $j[1])->where('type', 'invoice')->where('ref', $i[1])->where('status', 'rejected')->get();
            foreach ($finds as $f) {
                $cleared[] = 'job_find:'.$f->id;
                $f->delete();
            }
        }
        if ($relation === 'mileage_day' && $objectRef === null && preg_match('/^job:(\d+)$/', $subjectRef, $j)) {
            $rows = TodoDismissal::query()->where('key', 'like', TodoList::MILEAGE_JOB.$j[1].':%')->get();
            foreach ($rows as $row) {
                $cleared[] = 'todo_dismissal:'.$row->key;
                $row->delete();
            }
        }

        return $cleared;
    }
}
