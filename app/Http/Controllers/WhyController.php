<?php

namespace App\Http\Controllers;

use App\Domain\Intel\Methods;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Ref;
use App\Domain\Intel\Vetoes;
use App\Domain\Jobs\JobFinder;
use App\Domain\Todo\Fingerprints;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LinkProvenance;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Why-panels (docs/intelligence-cycle.md 7.4): GET /why?ref=tx:381 says why a line, or an automatic link, is as it is.
 * Who made it, in plain words; the method and its basis; its score and the runner-up it beat; the evidence it relied on
 * (each with whether it's still there, and whether a pinned source has changed since); and its history, with the
 * knock-on changes the same action made elsewhere (events sharing a correlation).
 *
 * A read model only: it never writes. A link's stored why (link_provenance) is shown only while it still describes the
 * link as it is now; a line's comes from its own columns, or, for lines from before they were stored, from the
 * method its maker and reason show (Methods::of), said as recognised rather than recorded.
 *
 * Refs: tx: and exp: (a line's decision), cal: (a calendar entry on a job), doc: (a document on a job), travel: (a
 * stay or journey: its job and its payment) and inv: (an invoice on a job). With each, what you've said no to about it
 * (said_no: Intel\Vetoes), which the panel lets you lift.
 */
class WhyController extends Controller
{
    private const ACTORS = [
        'you' => 'you', 'rule' => 'your rule', 'matcher' => 'matching', 'ai' => 'AI', 'policy' => 'Expend on its own, as you allowed',
        'checker' => 'the checker', 'system' => 'Expend', 'rules' => 'matching',
    ];

    /** @var array<string, array{label:string, href:?string, exists:bool, changed:bool}> what refs point at, worked out once per explanation */
    private static array $labels = [];

    private const CHANNELS = ['web' => 'on the web', 'telegram' => 'in Telegram', 'queue' => 'in the background', 'schedule' => 'on schedule', 'cli' => 'by a command'];

    private const ACTIONS = [
        'allocation.suggested' => 'Suggested', 'allocation.decided' => 'Decided', 'allocation.confirmed' => 'Confirmed', 'allocation.cleared' => 'Taken back',
        'transfer.paired' => 'Paired as a transfer', 'transfer.unpaired' => 'Unpaired', 'transfer.kept' => 'Pair kept', 'calendar.linked' => 'Link changed',
        'calendar.link_rekeyed' => 'Matched to its entry again', 'calendar.link_removed' => 'Link removed', 'document.job_linked' => 'Job link changed',
        'travel.job_linked' => 'Job link changed', 'travel.decided' => 'Decided', 'invoice.job_linked' => 'Put on a job', 'rule.learned' => 'Rule learned',
        'rule.saved' => 'Rule saved', 'rule.undone' => 'Rule undone', 'rule.offer_declined' => 'Rule not offered again',
        'veto.recorded' => 'Said “not this”', 'veto.lifted' => '“Not this” lifted', 'travel.payment_unlinked' => 'Payment unlinked',
    ];

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['ref' => 'required|string|max:191']);
        $why = self::explain($data['ref']);
        abort_if($why === null, 404, 'Nothing to explain for that.');

        return response()->json($why);
    }

    /**
     * @return array{ref:string, items:list<array<string, mixed>>}|null null when the ref isn't one this explains, or
     *                                                                  what it points at isn't there
     */
    public static function explain(string $ref): ?array
    {
        self::$labels = [];
        $r = Ref::parse($ref);
        if ($r === null) {
            return null;
        }
        $id = $r['id'];
        $items = match ($r['kind']) {
            'tx' => ctype_digit($id) && ($tx = BankTransaction::query()->with('allocations')->find((int) $id)) ? [self::decision($tx, 'tx:'.$id)] : null,
            'exp' => ctype_digit($id) && ($e = ManualExpense::query()->with('allocations')->find((int) $id)) ? [self::decision($e, 'exp:'.$id)] : null,
            'cal' => self::calendar($id),
            'doc' => ctype_digit($id) ? self::document((int) $id) : null,
            'travel' => ctype_digit($id) ? self::travel((int) $id) : null,
            'inv' => ctype_digit($id) ? self::invoice((int) $id) : null,
            default => null,
        };

        if ($items === null) {
            return null;
        }
        // What you've said no to about it (Intel\Vetoes), each liftable from the panel (the veto_lift to-do action).
        $saidNo = array_map(fn (array $v) => $v + ['fp' => Fingerprints::of('veto_lift', (string) $v['id'])], Vetoes::about($ref));

        return ['ref' => $ref, 'items' => $items, 'said_no' => $saidNo];
    }

    // ---------------------------------------------------------------- a line's decision

    private static function decision(BankTransaction|ManualExpense $item, string $ref): array
    {
        $lines = $item->allocations;
        $events = self::events($item);
        $history = self::history($item, $events);
        if ($lines->isEmpty()) {
            return ['about' => 'decision', 'title' => 'What this is', 'state' => 'none', 'headline' => 'Not decided yet.', 'history' => $history] + self::blank();
        }
        /** @var Allocation $first */
        $first = $lines->first();
        ['method' => $method, 'recorded' => $recorded] = Methods::of($first);
        $why = Provenance::fromAllocation($first);
        $by = (string) $first->decided_by;
        $confirmed = $first->confirmed_at !== null;
        $rule = $first->merchant_rule_id ? MerchantRule::query()->find($first->merchant_rule_id) : null;

        $who = match ($by) {
            'you' => 'Decided by you',
            'rule' => ($confirmed ? 'Decided' : 'Suggested').' by your rule'.($rule ? ' for '.$rule->merchant_key : ''),
            'ai' => 'Suggested by AI',
            default => 'Suggested by matching',
        };
        $how = $method && $by !== 'rule' ? ': '.Methods::describe($method) : '';
        $accepted = match (true) {
            $by === 'you' || ! $confirmed => '',
            $first->confirmed_by === 'you' => ', and accepted by you',
            $first->confirmed_by === 'matcher' => ', and confirmed by matching because you decided the other side of the transfer',
            $first->confirmed_by === 'policy' => ', confirmed by Expend on its own, as you allowed',
            $by === 'rule' && self::madeConfirmed($first, $events) => ', confirmed by the rule on its own',
            $first->confirmed_by === 'rule' => ', and confirmed by the rule',
            // Confirmed after it was made, before who confirmed it was kept: by your tap, most likely, but not known.
            default => ', and confirmed',
        };

        return [
            'about' => 'decision', 'title' => 'Why it says this',
            'state' => $by === 'you' ? 'decided' : ($confirmed ? 'confirmed' : 'suggested'),
            'actor' => $by, 'actor_label' => self::ACTORS[$by] ?? $by,
            'confirmed_by' => $first->confirmed_by,
            'headline' => $who.$how.$accepted.'.',
            'reason' => $first->reason,
            'method' => self::method($method, $recorded, $why),
            'score' => self::score($method, $why),
            'grade' => self::grade($by, $confirmed, $first->confirmed_by, $method),
            'evidence' => self::evidence(array_values(array_diff($why?->evidence ?? [], [$ref]))),
            'alternatives' => self::alternatives($why),
            'history' => $history,
        ];
    }

    /**
     * Whether a confirmed rule line was made already confirmed (the rule confirming on its own) rather than suggested and
     * confirmed later: confirmed_by says so ('rule'), or, on a line from before it was kept, it was confirmed the moment
     * it was made. Either way, nothing confirmed it since it was written (an allocation.confirmed event after the one that
     * wrote it would be your tap in the Inbox). The same test as the confirmed_by backfill (Backfills\ConfirmedBy).
     *
     * @param  Collection<int, AuditEvent>  $events  the item's history, newest first
     */
    private static function madeConfirmed(Allocation $line, Collection $events): bool
    {
        $last = $events->first(fn (AuditEvent $e) => in_array($e->action, ['allocation.suggested', 'allocation.decided', 'allocation.confirmed', 'allocation.cleared'], true));
        if ($last?->action === 'allocation.confirmed') {
            return false;
        }

        return $line->confirmed_by === 'rule' || ($line->confirmed_by === null && $line->confirmed_at !== null && $line->created_at !== null
            && $line->confirmed_at->format('Y-m-d H:i:s') === $line->created_at->format('Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------- links

    /** @return list<array>|null */
    private static function calendar(string $key): ?array
    {
        $link = CalendarJobLink::query()->where('event_key', $key)->first();
        $event = CalendarEvent::query()->where('event_key', $key)->first();
        if (! $link && ! $event) {
            return null;
        }
        $subject = 'cal:'.$key;
        $what = '“'.($link->summary ?? $event?->summary ?? 'A calendar entry').'”';
        $extra = [];
        if ($event) {
            // How much it looks like paid work (3 or more is work): worked out now, from the entry as it is.
            $work = app(JobFinder::class)->workScore($event);
            $extra['facts'] = ['Looks like work: '.$work.' (3 or more is work, 1 to 2 unsure)'];
        }
        if (! $link) {
            return [['about' => 'calendar_job', 'title' => 'Job link', 'state' => 'none', 'headline' => $what.' isn’t on a job.', 'history' => []] + $extra + self::blank()];
        }
        if ($link->not_job) {
            return [['about' => 'calendar_job', 'title' => 'Job link', 'state' => 'rejected', 'actor' => 'you', 'actor_label' => 'you',
                'headline' => 'You said '.$what.' isn’t a job.', 'reason' => $link->reason, 'history' => self::history($link, self::events($link))] + $extra + self::blank()];
        }

        return [self::link('calendar_job', 'Job link', $subject, $what, $link->work_job_id, $link->linked_by, $link->reason, $link,
            fn (string $by) => $by === 'ai' ? 'jobs.calendar_ai' : 'jobs.calendar_rules') + $extra];
    }

    /** @return list<array>|null */
    private static function document(int $id): ?array
    {
        $doc = Document::query()->find($id);
        if (! $doc) {
            return null;
        }

        // Put on a job by the AI reading it (DocumentReader), or by the job finder.
        return [self::link('document_job', 'Job link', 'doc:'.$id, '“'.($doc->email_subject ?: $doc->original_name).'”', $doc->work_job_id, $doc->job_linked_by, null, $doc,
            fn (string $by) => $by === 'ai' ? 'doc.read' : 'jobs.document')];
    }

    /** @return list<array>|null */
    private static function travel(int $id): ?array
    {
        $item = TravelItem::query()->find($id);
        if (! $item) {
            return null;
        }
        $what = (TravelItem::KINDS[$item->kind] ?? 'Travel').': '.($item->place ?: $item->provider);
        $out = [self::link('travel_job', 'Job link', 'travel:'.$id, $what, $item->work_job_id, $item->job_linked_by, null, $item, fn () => 'travel.match')];
        $native = $item->bank_transaction_id ? 'tx:'.$item->bank_transaction_id : null;
        $why = LinkProvenance::current('travel_payment', 'travel:'.$id, $native);
        $out[] = [
            'about' => 'travel_payment', 'title' => 'Its payment',
            'state' => $native ? 'linked' : 'none', 'actor' => $native ? 'matcher' : null, 'actor_label' => $native ? 'matching' : null,
            'headline' => $native ? 'Its payment was found in your statements: '.self::label($native)['label'].'.' : 'No payment found for it yet.',
            'method' => $native ? self::method('travel.match', $why !== null, $why ? self::fromRow($why) : null) : null,
            'score' => null, 'grade' => null,
            'evidence' => self::evidence($native ? [$native] : []), 'alternatives' => [], 'history' => [],
        ];

        return $out;
    }

    /** @return list<array>|null */
    private static function invoice(int $id): ?array
    {
        $invoice = Invoice::query()->find($id);
        if (! $invoice) {
            return null;
        }
        $auto = AuditEvent::query()->where('subject_type', 'Invoice')->where('subject_id', $id)->where('action', 'invoice.job_linked')->latest('id')->first();
        $by = $invoice->work_job_id && $auto && (int) ($auto->after['work_job_id'] ?? 0) === (int) $invoice->work_job_id ? (string) $auto->actor : ($invoice->work_job_id ? 'you' : null);

        return [self::link('invoice_job', 'Job', 'inv:'.$id, 'Invoice '.($invoice->number ?? 'draft'), $invoice->work_job_id, $by, $auto?->reason, $invoice, fn () => 'jobs.invoice_po')];
    }

    /**
     * A link that isn't an allocation: who made it, and, while it's as it was made, its stored why.
     *
     * @param  callable(string):string  $legacy  the method of an automatic link made before its why was stored
     */
    private static function link(string $link, string $title, string $subject, string $what, ?int $jobId, ?string $by, ?string $reason, Model $model, callable $legacy): array
    {
        $native = $jobId ? 'job:'.$jobId : null;
        $row = LinkProvenance::current($link, $subject, $native);
        $events = self::events($model);
        $history = self::history($model, $events);
        if (! $jobId) {
            // Changed by you since the automatic unlink this row describes (you put it on a job and took it off again):
            // it's your doing now, and the row no longer counts.
            $last = self::lastJobChange($events);
            $tookOff = ($last['actor'] ?? null) === 'you' && ($last['from'] ?? null) !== null;
            if ($by === 'you' || $tookOff) {
                $row = null;
            }
            $why = $row ? self::fromRow($row) : null;
            $actor = $row ? Methods::actor($row->method) : ($tookOff ? 'you' : null);
            $headline = match (true) {
                $row !== null => $what.' was taken off its job by '.self::ACTORS[$actor].'.',
                $tookOff => 'You took '.$what.' off '.self::label('job:'.$last['from'])['label'].'.',
                default => $what.' isn’t on a job.',
            };

            return ['about' => $link, 'title' => $title, 'state' => $row ? 'taken_back' : ($tookOff ? 'decided' : 'none'), 'actor' => $actor,
                'actor_label' => $actor ? self::ACTORS[$actor] : null,
                'headline' => $headline,
                'reason' => $reason, 'method' => $row ? self::method($row->method, true, $why) : null, 'score' => null,
                'grade' => $tookOff && ! $row ? ['grade' => 'certain', 'basis' => 'Your decision'] : null,
                'evidence' => self::evidence(array_values(array_diff($why?->evidence ?? [], [$subject]))), 'alternatives' => [], 'history' => $history];
        }
        $why = $row ? self::fromRow($row) : null;
        $job = self::label($native)['label'];
        $automatic = $by !== null && $by !== 'you';
        $method = $why?->method ?? ($automatic ? $legacy($by) : null);
        $actor = match (true) {
            $automatic => $method ? Methods::actor($method) : 'matcher',
            $by === 'you' => 'you',
            default => null, // linked before who linked it was kept
        };
        $headline = match (true) {
            $automatic => $what.' was put on '.$job.' by '.self::ACTORS[$actor].($method ? ': '.Methods::describe($method) : '').'.',
            $actor === 'you' => $what.' was put on '.$job.' by you'.($why ? ' (matching had suggested it)' : '').'.',
            default => $what.' is on '.$job.'. Who put it there wasn’t kept.',
        };

        return [
            'about' => $link, 'title' => $title, 'state' => $automatic ? 'linked' : ($actor === 'you' ? 'decided' : 'linked'),
            'actor' => $actor, 'actor_label' => $actor ? (self::ACTORS[$actor] ?? $actor) : null,
            'headline' => $headline, 'reason' => $reason,
            'method' => $method ? self::method($method, $why !== null, $why) : null,
            'score' => $method ? self::score($method, $why) : null,
            'grade' => match (true) {
                $automatic => ['grade' => 'unmeasured', 'basis' => 'Not measured yet: an automatic link, which you can change or undo'],
                $actor === 'you' => ['grade' => 'certain', 'basis' => 'Your decision'],
                default => null,
            },
            'evidence' => self::evidence(array_values(array_diff($why?->evidence ?? [], [$subject]))),
            'alternatives' => self::alternatives($why),
            'history' => $history,
        ];
    }

    /**
     * The newest change of which job a link is on (an event whose before and after name different jobs): who made it,
     * and the job it was on before. Null when none is in the history.
     *
     * @param  Collection<int, AuditEvent>  $events  newest first
     * @return array{actor:string, from:?int}|null
     */
    private static function lastJobChange(Collection $events): ?array
    {
        foreach ($events as $e) {
            $after = is_array($e->after) ? $e->after : [];
            if (! array_key_exists('work_job_id', $after)) {
                continue;
            }
            $from = is_array($e->before) ? ($e->before['work_job_id'] ?? null) : null;
            if ((int) $from !== (int) ($after['work_job_id'] ?? 0)) {
                return ['actor' => (string) $e->actor, 'from' => $from !== null ? (int) $from : null];
            }
        }

        return null;
    }

    private static function fromRow(LinkProvenance $row): ?Provenance
    {
        return Methods::has($row->method)
            ? new Provenance($row->method, $row->evidence ?? [], $row->score, $row->alternatives ?? [], $row->method_version, $row->basis, $row->run_id)
            : null;
    }

    // ---------------------------------------------------------------- parts

    private static function blank(): array
    {
        return ['actor' => null, 'actor_label' => null, 'reason' => null, 'method' => null, 'score' => null, 'grade' => null, 'evidence' => [], 'alternatives' => []];
    }

    private static function method(?string $method, bool $recorded, ?Provenance $why): ?array
    {
        if ($method === null) {
            return null;
        }
        $basis = $why?->basis ?? Methods::basis($method);

        return [
            'name' => $method, 'label' => Methods::describe($method), 'basis' => $basis, 'basis_label' => Methods::BASES[$basis] ?? $basis,
            'version' => $why?->version ?? Methods::version($method), 'recorded' => $recorded,
            // Recognised from the line's maker and reason: it was written before the method was stored with it.
            'note' => $recorded ? null : 'Worked out from who made it and how its reason is worded: it was made before Expend kept the method with it.',
        ];
    }

    private static function score(?string $method, ?Provenance $why): ?array
    {
        if ($method === null || $why === null || $why->score === null) {
            return null;
        }
        $best = collect($why->alternatives)->max('score');
        $categorise = str_starts_with($method, 'categorise.') && in_array($method, ['categorise.merchant', 'categorise.similar', 'categorise.bank_learned'], true);

        return [
            'value' => $why->score, 'means' => Methods::scoreMeans($method),
            // "3 of 4": the decisions agreeing, of all those looked at (the runner-ups are the others).
            'of' => $categorise ? $why->score + (int) collect($why->alternatives)->sum('score') : null,
            'margin' => $best !== null ? $why->score - (int) $best : null,
        ];
    }

    private static function grade(string $by, bool $confirmed, ?string $confirmedBy, ?string $method): ?array
    {
        if ($by === 'you' || ($confirmed && $confirmedBy === 'you')) {
            return ['grade' => 'certain', 'basis' => 'Your decision'];
        }

        return ['grade' => 'unmeasured', 'basis' => 'Not measured yet: Expend only measures how often a method is right once you’ve decided enough of its suggestions'
            .($method ? ' (it rests on '.(Methods::BASES[Methods::basis($method)] ?? 'matching').')' : '')];
    }

    /**
     * @param  list<string>  $refs
     * @return list<array{ref:string, label:string, href:?string, exists:bool, changed:bool}>
     */
    private static function evidence(array $refs): array
    {
        self::prime($refs);

        return array_map(fn (string $ref) => ['ref' => $ref] + self::label($ref), $refs);
    }

    /** @return list<array{ref:string, label:string, href:?string, exists:bool, changed:bool, score:?int}> */
    private static function alternatives(?Provenance $why): array
    {
        self::prime(array_column($why?->alternatives ?? [], 'ref'));

        return array_map(fn (array $a) => ['ref' => $a['ref'], 'score' => $a['score']] + self::label($a['ref']), $why?->alternatives ?? []);
    }

    /**
     * What a ref points at, in a few words, with where to open it, whether it's still there, and whether a pinned source
     * has changed since it was relied on. Worked out with others of its kind when they were primed together.
     *
     * @return array{label:string, href:?string, exists:bool, changed:bool}
     */
    private static function label(string $ref): array
    {
        if (! isset(self::$labels[$ref])) {
            self::prime([$ref]);
        }

        return self::$labels[$ref] ?? ['label' => $ref, 'href' => null, 'exists' => true, 'changed' => false];
    }

    /**
     * Work out what these refs point at, one query for each kind (not one for each ref), for label() to read.
     *
     * @param  iterable<string>  $refs
     */
    private static function prime(iterable $refs): void
    {
        $todo = [];
        foreach ($refs as $ref) {
            if (! isset(self::$labels[$ref]) && ($r = Ref::parse($ref))) {
                $todo[$r['kind']][$ref] = $r;
            }
        }
        foreach ($todo as $kind => $parsed) {
            $ids = array_values(array_unique(array_map(fn (array $r) => $r['id'], $parsed)));
            $numbers = array_values(array_unique(array_map(fn (string $id) => ctype_digit($id) ? (int) $id : 0, $ids)));
            $byId = fn ($query) => $query->whereIn('id', $numbers)->get()->keyBy(fn (Model $m) => (string) $m->getKey());
            $found = match ($kind) {
                'tx' => $byId(BankTransaction::query()->with('account')),
                'exp' => $byId(ManualExpense::query()),
                'inv' => $byId(Invoice::query()),
                'job' => $byId(WorkJob::query()),
                'rule' => $byId(MerchantRule::query()),
                'doc' => $byId(Document::query()),
                'travel' => $byId(TravelItem::query()),
                'audit' => $byId(AuditEvent::query()),
                'cal' => [
                    'link' => CalendarJobLink::query()->whereIn('event_key', $ids)->get()->keyBy('event_key'),
                    'event' => CalendarEvent::query()->whereIn('event_key', $ids)->get()->keyBy('event_key'),
                ],
                default => collect(),
            };
            foreach ($parsed as $ref => $r) {
                self::$labels[$ref] = self::describe($ref, $r, $found);
            }
        }
    }

    /**
     * @param  array{kind:string, id:string, pin:?string}  $r
     * @param  Collection<string, Model>|array{link:Collection<string, CalendarJobLink>, event:Collection<string, CalendarEvent>}  $found  what its kind's query found
     * @return array{label:string, href:?string, exists:bool, changed:bool}
     */
    private static function describe(string $ref, array $r, Collection|array $found): array
    {
        $id = $r['id'];
        $model = $found instanceof Collection && ctype_digit($id) ? $found->get((string) (int) $id) : null;
        $missing = fn (string $what) => ['label' => $what.' (no longer here)', 'href' => null, 'exists' => false, 'changed' => false];
        $done = fn (string $label, ?string $href = null, bool $changed = false) => ['label' => $label, 'href' => $href, 'exists' => true, 'changed' => $changed];

        switch ($r['kind']) {
            case 'tx':
                /** @var BankTransaction|null $tx */
                $tx = $model;

                return $tx ? $done(trim(($tx->account?->name ? $tx->account->name.' · ' : '').$tx->effectiveDate()->format('j M Y').' · '.($tx->counterparty ?: $tx->description).' · '.Money::format($tx->amount_pence)),
                    '/transactions?q='.urlencode((string) ($tx->counterparty ?: $tx->description))) : $missing('A payment');
            case 'exp':
                /** @var ManualExpense|null $e */
                $e = $model;

                return $e ? $done($e->spent_on->format('j M Y').' · '.$e->description.' · '.Money::format($e->amount_pence)) : $missing('An expense');
            case 'inv':
                /** @var Invoice|null $i */
                $i = $model;

                return $i ? $done('Invoice '.($i->number ?? 'draft').' · '.Money::format($i->total_minor, $i->currency ?? 'GBP'), '/invoices/'.$i->id) : $missing('An invoice');
            case 'job':
                /** @var WorkJob|null $j */
                $j = $model;

                return $j ? $done($j->title.' · '.$j->starts_on->format('j M Y'), '/jobs/'.$j->id) : $missing('A job');
            case 'rule':
                /** @var MerchantRule|null $rule */
                $rule = $model;

                return $rule ? $done('Your rule for '.$rule->merchant_key, '/settings/rules') : $missing('A rule');
            case 'cal':
                $link = is_array($found) ? $found['link']->get($id) : null;
                $event = is_array($found) ? $found['event']->get($id) : null;
                $summary = $event?->summary ?? $link?->summary;

                return $summary !== null || $event ? $done('Calendar: '.($summary ?? 'an entry').($event ? ' · '.$event->localStart()->format('j M Y') : ''), '/jobs/calendar')
                    : $missing('A calendar entry');
            case 'doc':
                /** @var Document|null $d */
                $d = $model;

                return $d ? $done('Document: '.($d->email_subject ?: $d->original_name), '/documents/'.($d->parent_id ?? $d->id), $r['pin'] !== null && ! str_starts_with((string) $d->sha256, $r['pin']))
                    : $missing('A document');
            case 'travel':
                /** @var TravelItem|null $t */
                $t = $model;

                return $t ? $done((TravelItem::KINDS[$t->kind] ?? 'Travel').': '.($t->place ?: $t->provider).' · '.$t->starts_on->format('j M Y'), '/travel') : $missing('A stay or journey');
            case 'audit':
                /** @var AuditEvent|null $e */
                $e = $model;

                return $e ? $done('Your history: '.(self::ACTIONS[$e->action] ?? $e->action).' on '.$e->occurred_at->format('j M Y'), null, $r['pin'] !== null && ! str_starts_with((string) $e->hash, $r['pin']))
                    : $missing('A history entry');
            default:
                return ['label' => $ref, 'href' => null, 'exists' => true, 'changed' => false];
        }
    }

    /**
     * The subject's newest events (at most 30), newest first.
     *
     * @return Collection<int, AuditEvent>
     */
    private static function events(Model $subject): Collection
    {
        return AuditEvent::query()->where('subject_type', class_basename($subject))->where('subject_id', $subject->getKey())->orderByDesc('id')->limit(30)->get();
    }

    /**
     * The subject's history, newest first, each entry with where it came from and the knock-on changes the same action
     * made to other things (the events sharing its correlation): the first 10 of each action, so one big action (a
     * statement upload that suggested hundreds of lines) can't crowd out another's.
     *
     * @param  Collection<int, AuditEvent>  $events  the subject's, newest first (events())
     * @return list<array<string, mixed>>
     */
    private static function history(Model $subject, Collection $events): array
    {
        $correlations = $events->pluck('correlation')->filter()->unique()->values();
        $related = collect();
        if ($correlations->isNotEmpty()) {
            $type = class_basename($subject);
            $ranked = AuditEvent::query()->select('audit_events.*')
                ->selectRaw('row_number() over (partition by correlation order by id) as knock_on_rank')
                ->whereIn('correlation', $correlations->all())
                ->where(fn ($q) => $q->where('subject_type', '!=', $type)->orWhere('subject_id', '!=', $subject->getKey())->orWhereNull('subject_id'));
            /** @var Collection<string, Collection<int, AuditEvent>> $related */
            $related = AuditEvent::query()->fromSub($ranked, 'audit_events')->where('knock_on_rank', '<=', 10)->orderBy('id')->get()->groupBy('correlation');
            self::prime($related->flatten(1)->map(fn (AuditEvent $k) => self::subjectRef($k))->filter()->unique()->values()->all());
        }

        return $events->map(fn (AuditEvent $e) => self::event($e) + [
            'knock_on' => $e->correlation ? ($related->get($e->correlation) ?? collect())->map(fn (AuditEvent $k) => self::event($k) + ['subject' => self::subject($k)])->values()->all() : [],
        ])->all();
    }

    private static function event(AuditEvent $e): array
    {
        return [
            'id' => $e->id, 'at' => $e->occurred_at->format('Y-m-d H:i'), 'actor' => $e->actor, 'actor_label' => self::ACTORS[$e->actor] ?? $e->actor,
            'action' => $e->action, 'label' => self::ACTIONS[$e->action] ?? ucfirst(str_replace(['.', '_'], ' ', $e->action)), 'reason' => $e->reason,
            'channel' => $e->channel, 'channel_label' => $e->channel ? (self::CHANNELS[$e->channel] ?? $e->channel) : null, 'correlation' => $e->correlation,
        ];
    }

    /** What a knock-on event was about, in a few words. */
    private static function subject(AuditEvent $e): string
    {
        $ref = self::subjectRef($e);

        return $ref !== null ? self::label($ref)['label'] : ucfirst(str_replace('_', ' ', preg_replace('/(?<!^)[A-Z]/', ' $0', $e->subject_type) ?? $e->subject_type));
    }

    private static function subjectRef(AuditEvent $e): ?string
    {
        if (! $e->subject_id) {
            return null;
        }

        return match ($e->subject_type) {
            'BankTransaction' => 'tx:'.$e->subject_id, 'ManualExpense' => 'exp:'.$e->subject_id, 'Invoice' => 'inv:'.$e->subject_id, 'WorkJob' => 'job:'.$e->subject_id,
            'Document' => 'doc:'.$e->subject_id, 'TravelItem' => 'travel:'.$e->subject_id, 'MerchantRule' => 'rule:'.$e->subject_id,
            default => null,
        };
    }
}
