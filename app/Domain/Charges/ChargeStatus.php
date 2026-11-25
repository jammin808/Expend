<?php

namespace App\Domain\Charges;

use App\Domain\Clients\ClientIds;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobBilling;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where each cost (or part of a job's work) stands: covered, on an invoice, charged another way, to claim on the
 * client's form, not charged, ticked on a draft, or owed. Read only, and for many costs at once (a few queries per
 * batch, never one per cost).
 *
 * How much is charged: the ticked lines on issued (or legacy paid) invoices whose ref is the cost's or an alias, in
 * the cost's currency (by quantity for miles, days and hours; a ticked line in another currency counts as the whole
 * cost). What's left = max(0, min(the cost − charged, the subject's cost − charged across the subject)), so a ticket
 * charged once has nothing left when it's later split between jobs or moved to another one. Lines on void invoices
 * don't count: voiding frees a cost.
 *
 * Covered jobs were billed before Expend tracked where lines come from, or another way: an issued invoice with no
 * sync (made before this release), recorded income with no Expend invoice issued (JobBilling), or "Already sorted"
 * on its 'Invoice <job>' item. Their work reads as charged, and their costs are never ticked or pulled onto a draft
 * (JobCloseout asks about them instead) unless you decided otherwise since: your recharge decision newer than the
 * covering invoice makes the cost owed late. A tracked invoice made from a PO covers its job's work only.
 *
 * The statuses, in order: covered; on_invoice (nothing left and something charged, or you said it was on an issued
 * invoice: a voided one is followed to the invoice that replaced it, up to 5 steps, and when that one doesn't carry it
 * the status moves on, saying 'Was on INV-0031, since voided'); elsewhere (charged another way, or claimed on their
 * form); to_claim (their form, not claimed yet); not_charged (you said so); not_charged (left off an issued invoice,
 * unless your recharge decision is newer than it); on_draft (ticked on a draft); owed, or owed_late once the job has a
 * tracked issued invoice or one that covered it (something left, and expected to be charged); not_charged (by the
 * arrangement, or none yet).
 *
 * Expected to be charged: you said recharge; or its kind is charged at cost or per mile; or it's work. A cost charged
 * for more than it costs now shows it and is never credited; one that costs more offers only the difference,
 * unticked. A refund (credit) is ticked only while the refunds of its kind on the job stay within what was charged for
 * costs of that kind (issued, or ticked on a draft); otherwise it asks whether the original was charged. Two records
 * that may be one cost: once the one CostUnits flagged is charged, the other is unticked too (flags, pair_ref). Costs
 * of a job cancelled with nothing owed are not charged ('cancelled'), unless you said to charge one.
 */
final class ChargeStatus
{
    public const STATUSES = ['covered', 'on_invoice', 'elsewhere', 'to_claim', 'not_charged', 'on_draft', 'owed', 'owed_late'];

    /** The client page's filter chips and the statuses each shows. */
    public const CHIPS = [
        'to_charge' => ['label' => 'To charge', 'statuses' => ['owed', 'owed_late']],
        'on_draft' => ['label' => 'On a draft', 'statuses' => ['on_draft']],
        'charged' => ['label' => 'Charged', 'statuses' => ['on_invoice', 'covered']],
        'to_claim' => ['label' => 'To claim', 'statuses' => ['to_claim']],
        'not_charged' => ['label' => 'Not charged', 'statuses' => ['not_charged']],
        'elsewhere' => ['label' => 'Charged another way', 'statuses' => ['elsewhere']],
    ];

    /** Flags that leave a cost unticked until you look (CostUnits). */
    public const UNTICKED_FLAGS = ['maybe_same', 'paid_by_other', 'check', 'no_amount'];

    /** How many replacements of a void invoice are followed. */
    private const HOPS = 5;

    /**
     * Each cost's status, by its ref.
     *
     * @param  list<array<string, mixed>>  $units  from CostUnits
     * @param  int|null  $ignore  a draft whose own lines don't count (the draft sync asks where each cost stands apart
     *                            from the draft it's filling, so a cost ticked there still reads as owed)
     * @param  array<int, array>|null  $covered  covered() for these jobs, when the caller has it already
     * @param  array<int, array>|null  $terms  ChargeTerms::forJobs() for these jobs, when the caller has it already
     * @return array<string, array{ref:string, status:string, reason:?string, expected:bool, tick:bool, late:bool, measure:string,
     *     currency:string, amount:int|string, charged:int|string, subject_charged:int|string, remaining:int|string|null,
     *     invoices:list<int>, drafts:list<int>, note:?string, decision:?array, claimed:bool, difference:bool, covered:?array,
     *     flags:array<string, string>, pair_ref:?string}>  flags: what to check before it's ticked, on top of the cost's own
     */
    public static function for(array $units, ?int $ignore = null, ?array $covered = null, ?array $terms = null): array
    {
        if ($units === []) {
            return [];
        }
        $jobIds = collect($units)->pluck('job_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $jobs = WorkJob::query()->whereIn('id', $jobIds ?: [0])->get(['id', 'title', 'client_id', 'status', 'cancel_charge', 'starts_on', 'ends_on'])->keyBy('id');
        $covered ??= self::covered($jobIds);
        $terms ??= ChargeTerms::forJobs($jobs);
        $decisions = CostCharges::winning($units);
        $resolved = ClientIds::resolveMany(collect($units)->pluck('client_id')->concat($jobs->pluck('client_id')));
        $names = Client::query()->whereIn('id', array_values($resolved) ?: [0])->pluck('name', 'id');
        $subjectOf = [];
        foreach ($units as $u) {
            foreach ([$u['ref'], ...$u['aliases']] as $r) {
                $subjectOf[$r] = $u['subject'];
            }
        }
        $lines = self::lines(array_keys($subjectOf), collect(array_keys($subjectOf))
            ->filter(fn ($r) => str_starts_with($r, 'tx:') || str_starts_with($r, 'exp:'))->map(fn ($r) => CostRef::subject($r))->unique()->values()->all())
            ->reject(fn ($l) => $ignore !== null && (int) $l->invoice_id === $ignore)->values();
        $bySubject = $lines->groupBy(fn ($l) => $subjectOf[$l->source_ref] ?? CostRef::subject($l->source_ref));
        $byRef = $lines->groupBy('source_ref');
        $amounts = CostUnits::subjects(collect($units)->pluck('subject')->filter(fn ($s) => str_starts_with($s, 'tx:') || str_starts_with($s, 'exp:'))->unique()->values()->all());
        $tracked = Invoice::query()->whereIn('work_job_id', $jobIds ?: [0])->whereIn('status', InvoicePayments::PAYABLE)->whereNotNull('synced_at')
            ->orderBy('id')->get(['id', 'number', 'work_job_id', 'issued_at'])->groupBy('work_job_id');
        $named = self::named(collect($decisions)->filter()->where('charge', 'invoiced')->pluck('invoice_id')->filter()->all());
        $jobTitles = Invoice::query()->whereIn('invoices.id', $lines->pluck('invoice_id')->unique()->values()->all() ?: [0])
            ->leftJoin('work_jobs', 'work_jobs.id', '=', 'invoices.work_job_id')->pluck('work_jobs.title', 'invoices.id');

        $out = [];
        foreach ($units as $u) {
            $job = $u['job_id'] !== null ? ($jobs[(int) $u['job_id']] ?? null) : null;
            $client = $u['client_id'] !== null ? ($names[$resolved[(int) $u['client_id']] ?? (int) $u['client_id']] ?? null) : null;
            $out[$u['ref']] = self::one($u, $job, $client, $covered[(int) $u['job_id']] ?? null, $job ? ($terms[(int) $job->id] ?? []) : [], $decisions[$u['ref']] ?? null,
                collect([$u['ref'], ...$u['aliases']])->flatMap(fn ($r) => $byRef[$r] ?? [])->unique('id')->values(),
                $bySubject[$u['subject']] ?? collect(), $amounts[$u['subject']] ?? null, $job ? ($tracked[(int) $job->id] ?? collect())->last() : null, $named, $jobTitles);
        }

        return self::twins($units, self::credits($units, $out));
    }

    /** @return array<string, array<string, mixed>> a job's costs (and, unless $withWork is false, its work), by ref */
    public static function forJob(WorkJob $job, bool $withWork = true): array
    {
        return self::for([...CostUnits::forJob($job), ...($withWork ? CostUnits::work($job) : [])]);
    }

    /** @return array<string, array<string, mixed>> every cost for a client (their jobs' and their client-only costs), by ref */
    public static function forClient(Client|int $client): array
    {
        return self::for(CostUnits::forClient($client));
    }

    /**
     * The jobs billed before Expend tracked where lines come from, or another way, and what covers each.
     *
     * @param  list<int>  $jobIds
     * @return array<int, array{by:string, invoice_id:?int, number:?string, at:?CarbonImmutable, note:string, work_only:bool}> by job id
     */
    public static function covered(array $jobIds): array
    {
        $jobIds = array_values(array_unique(array_map('intval', array_filter($jobIds))));
        if ($jobIds === []) {
            return [];
        }
        $out = [];
        $invoices = collect();
        foreach (array_chunk($jobIds, 500) as $chunk) {
            $invoices = $invoices->concat(Invoice::query()->whereIn('work_job_id', $chunk)->whereIn('status', InvoicePayments::PAYABLE)->orderBy('id')
                ->get(['id', 'number', 'work_job_id', 'synced_at', 'document_id', 'issued_at']));
        }
        $invoiced = $invoices->groupBy('work_job_id');
        foreach ($invoiced as $job => $list) {
            $legacy = $list->first(fn (Invoice $i) => $i->synced_at === null);
            if ($legacy !== null) {
                $out[(int) $job] = ['by' => 'invoice', 'invoice_id' => (int) $legacy->id, 'number' => $legacy->number, 'at' => $legacy->issued_at ? CarbonImmutable::parse($legacy->issued_at) : null,
                    'note' => 'Covered by '.($legacy->number ?? 'an earlier invoice'), 'work_only' => false];
            }
        }
        foreach (array_keys(JobBilling::billed($jobIds)) as $job) {
            if (! isset($invoiced[$job])) {
                $out[$job] ??= ['by' => 'income', 'invoice_id' => null, 'number' => null, 'at' => null, 'note' => 'Paid without an invoice from Expend', 'work_only' => false];
            }
        }
        $sorted = TodoDismissal::query()->whereIn('key', array_map(fn ($id) => 'invoice:'.$id, $jobIds))->whereNull('until')
            ->where(fn ($q) => $q->where('note', 'Already sorted')->orWhere('note', 'like', 'Already sorted:%'))->pluck('key');
        foreach ($sorted as $key) {
            $out[(int) substr($key, 8)] ??= ['by' => 'sorted', 'invoice_id' => null, 'number' => null, 'at' => null, 'note' => 'You said it was invoiced another way', 'work_only' => false];
        }
        // A tracked invoice made from a PO covers its job's work: the PO set the money.
        foreach ($invoiced as $job => $list) {
            $po = $list->first(fn (Invoice $i) => $i->synced_at !== null && $i->document_id !== null);
            if ($po !== null) {
                $out[(int) $job] ??= ['by' => 'po', 'invoice_id' => (int) $po->id, 'number' => $po->number, 'at' => $po->issued_at ? CarbonImmutable::parse($po->issued_at) : null,
                    'note' => 'Covered by the PO on '.($po->number ?? 'its invoice'), 'work_only' => true];
            }
        }

        return $out;
    }

    /** The chip a status shows under on the client page. */
    public static function chip(string $status): string
    {
        foreach (self::CHIPS as $chip => $c) {
            if (in_array($status, $c['statuses'], true)) {
                return $chip;
            }
        }

        return 'not_charged';
    }

    /**
     * What a cost's sheet offers: recharge ("Charge to Acme"), no_charge ("Not charged"), elsewhere ("Charged another
     * way…"), claimed ("Claimed") and clear (your decision forgotten), as they fit where it stands.
     *
     * @param  array<string, mixed>  $status  one of for()'s
     * @return list<string>
     */
    public static function actions(array $status): array
    {
        if (in_array($status['status'], ['on_invoice', 'covered'], true) && ($status['decision'] === null || $status['decision']['booking'])) {
            return [];
        }
        $charge = $status['decision']['charge'] ?? null;
        $mine = $status['decision'] !== null && ! $status['decision']['booking'];

        return array_values(array_filter([
            $charge !== 'recharge' && $status['status'] !== 'on_draft' ? 'recharge' : null,
            $charge !== 'no_charge' ? 'no_charge' : null,
            $charge !== 'elsewhere' ? 'elsewhere' : null,
            $status['status'] === 'to_claim' ? 'claimed' : null,
            $mine ? 'clear' : null,
        ]));
    }

    /**
     * One cost's status.
     *
     * @param  Collection<int, object>  $own  lines citing this cost (its ref or an alias)
     * @param  Collection<int, object>  $sub  lines citing any cost of its subject
     */
    private static function one(array $u, ?WorkJob $job, ?string $client, ?array $covered, array $terms, ?array $decision, Collection $own, Collection $sub,
        ?array $subjectAmount, ?Invoice $tracked, Collection $named, Collection $titles): array
    {
        $work = $u['kind'] === 'work';
        $measure = in_array($u['kind'], ['work', 'trip'], true) ? 'quantity' : 'minor';
        $charge = $decision['charge'] ?? null;
        $recharge = $charge === 'recharge';
        $term = fn (?string $class) => $class !== null ? ($terms[$class] ?? null) : null;
        $claimed = false;

        // What's expected on the invoice, and how much of it (only the parts charged, when they differ).
        $full = $measure === 'quantity' ? (string) BigDecimal::of((string) $u['quantity'])->toScale(2) : abs((int) $u['amount_minor']);
        if ($work) {
            [$expected, $amount] = [true, $full];
        } elseif ($u['kind'] === 'trip') {
            [$expected, $amount] = [$recharge || CostClass::expected($term('mileage')['charge'] ?? null), $full];
        } else {
            $parts = collect($u['parts']);
            $charged = $u['job_id'] === null ? collect() : $parts->filter(fn (array $p) => CostClass::expected($term($p['class'])['charge'] ?? null));
            $expected = $recharge || $charged->isNotEmpty();
            $amount = $full;
            $all = (int) $parts->sum(fn (array $p) => abs($p['amount_pence']));
            if (! $recharge && $charged->isNotEmpty() && $charged->count() < $parts->count() && $all > 0) {
                $amount = BigDecimal::of($full)->multipliedBy((int) $charged->sum(fn (array $p) => abs($p['amount_pence'])))->dividedBy($all, 0, RoundingMode::HALF_UP)->toInt();
            }
        }
        $toClaim = ! $work && collect($u['parts'])->contains(fn (array $p) => ($term($p['class'])['charge'] ?? null) === 'their_form');

        // Charged, across the cost and across its subject, on issued invoices only.
        $issued = fn ($l) => (bool) $l->included && in_array($l->inv_status, InvoicePayments::PAYABLE, true);
        $sum = function (Collection $lines) use ($measure, $u, $full) {
            $total = $measure === 'quantity' ? BigDecimal::zero() : 0;
            foreach ($lines as $l) {
                if ($measure === 'quantity') {
                    $total = $total->plus(BigDecimal::of((string) $l->quantity));
                } else {
                    $total += strtoupper((string) $l->inv_currency) === $u['currency'] ? abs((int) $l->amount_minor) : $full;
                }
            }

            return $measure === 'quantity' ? (string) $total->toScale(2) : $total;
        };
        $chargedLines = $own->filter($issued);
        $charged = $sum($chargedLines);
        $subjectCharged = $work ? $charged : $sum($sub->filter($issued));
        $subjectFull = $work || ! in_array($u['kind'], ['cost', 'credit'], true) ? $full
            : ($subjectAmount !== null && $subjectAmount['currency'] === $u['currency'] ? abs((int) $subjectAmount['amount']) : $full);
        $known = $measure === 'quantity' || ! isset($u['flags']['no_amount']);
        $ownLeft = self::minus($amount, $charged);
        $subjectLeft = self::minus($subjectFull, $subjectCharged);
        $remaining = $known ? self::max0(self::min($ownLeft, $subjectLeft)) : null;
        $someCharged = self::positive($charged) || self::positive($subjectCharged);
        // Part of this very cost was charged: on its own line, or (moved from another job) under its subject. Another
        // job's half of a split payment being charged isn't part of this one.
        $partCharged = self::positive($charged) || self::positive(self::minus($ownLeft, $subjectLeft));
        $drafts = $own->filter(fn ($l) => (bool) $l->included && $l->inv_status === 'draft')->pluck('invoice_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $status = null;
        $reason = null;
        $note = null;
        $invoices = $chargedLines->pluck('invoice_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $number = fn ($l) => $l->inv_number ?? 'an invoice';

        // 1. Covered: billed before tracking, or another way. Your own decision since then says otherwise.
        $overrides = $decision !== null && ! $decision['booking'] && ($charge !== 'recharge' || $covered === null || $covered['at'] === null || $decision['at'] === null || $decision['at']->gt($covered['at']));
        if ($covered !== null && ($work || ! $covered['work_only']) && ! $someCharged && ($work || ! $overrides)) {
            $status = 'covered';
            $note = $covered['note'];
        }
        // 2. On an invoice.
        $voided = null;
        if ($status === null && $someCharged && $remaining !== null && ! self::positive($remaining)) {
            $status = 'on_invoice';
            if ($chargedLines->isEmpty()) {
                $l = $sub->filter($issued)->first();
                $invoices = $sub->filter($issued)->pluck('invoice_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $note = 'Charged on '.$number($l).(($titles[$l->invoice_id] ?? null) ? ' ('.$titles[$l->invoice_id].')' : '');
            } else {
                $note = 'On '.$number($chargedLines->first());
            }
        }
        if ($status === null && $charge === 'invoiced') {
            $first = $named[(int) $decision['invoice_id']] ?? null;
            $end = $first;
            for ($hops = 0; $end !== null && $end->status === 'void' && $end->replaced_by_id !== null && $hops < self::HOPS; $hops++) {
                $end = $named[(int) $end->replaced_by_id] ?? null;
            }
            if ($first !== null && in_array($first->status, InvoicePayments::PAYABLE, true)) {
                [$status, $remaining, $invoices, $note] = ['on_invoice', $measure === 'quantity' ? '0.00' : 0, [(int) $first->id], 'On '.($first->number ?? 'an earlier invoice')];
            } elseif ($end !== null && $end !== $first && in_array($end->status, InvoicePayments::PAYABLE, true)
                && $own->contains(fn ($l) => (bool) $l->included && (int) $l->invoice_id === (int) $end->id)) {
                [$status, $remaining, $invoices, $note] = ['on_invoice', $measure === 'quantity' ? '0.00' : 0, [(int) $end->id], 'On '.($end->number ?? 'the invoice that replaced it')];
            } else {
                // Voided, and what replaced it doesn't carry it: it's to charge again.
                $voided = 'Was on '.($first?->number ?? 'an invoice').', since voided';
                [$expected, $recharge] = [true, true];
            }
        }
        // 3-5. Charged another way, to claim on their form, or not charged by you.
        if ($status === null && $charge === 'elsewhere') {
            [$status, $claimed] = ['elsewhere', $toClaim || $decision['note'] === CostCharges::CLAIMED_NOTE];
            $note = $claimed ? 'Claimed on '.($client ? $client.'’s' : 'their').' expense form' : 'Charged another way';
        }
        if ($status === null && $toClaim && ! $recharge && $charge === null) {
            [$status, $note] = ['to_claim', 'To claim on '.($client ? $client.'’s' : 'their').' expense form'];
        }
        if ($status === null && $charge === 'no_charge') {
            [$status, $reason, $note] = ['not_charged', 'yours', 'You said it isn’t charged'];
        }
        // 6. Left off an issued invoice (unticked), unless you decided to charge it since.
        if ($status === null && $chargedLines->isEmpty()) {
            $left = $own->first(fn ($l) => ! (bool) $l->included && in_array($l->inv_status, InvoicePayments::PAYABLE, true));
            $since = $left !== null && $recharge && $decision['at'] !== null && $left->inv_issued_at !== null && $decision['at']->gt(CarbonImmutable::parse($left->inv_issued_at));
            if ($left !== null && ! $since) {
                [$status, $reason, $note] = ['not_charged', 'left_off', 'Left off '.$number($left)];
            }
        }
        // 7. Ticked on a draft.
        if ($status === null && $drafts !== []) {
            $status = 'on_draft';
        }
        // 8-9. Owed (late once the job has a tracked issued invoice), or not charged by the arrangement. A job cancelled
        // with nothing owed owes none of its costs, unless you said to charge one.
        $difference = false;
        $nothingOwed = ! $work && $job !== null && $job->status === 'cancelled' && ! in_array($job->cancel_charge, ['fee', 'costs'], true);
        if ($status === null && $nothingOwed && ! $recharge) {
            [$status, $reason, $note] = ['not_charged', 'cancelled', 'Cancelled: they owe nothing for it'];
        }
        if ($status === null) {
            $left = $remaining === null || self::positive($remaining);
            if ($expected && $left) {
                // Late once the job has an issued invoice it isn't on: a tracked one, or the one that covered it (you
                // decided to charge it since).
                $status = $tracked !== null || ($covered['by'] ?? null) === 'invoice' ? 'owed_late' : 'owed';
                if ($partCharged && $remaining !== null) {
                    $difference = true;
                    $on = $number($chargedLines->first() ?? $sub->filter($issued)->first());
                    $note = $measure === 'quantity'
                        ? self::more($remaining, $u['unit'], $on)
                        : 'Difference on '.$on.': you paid '.Money::format($amount, $u['currency'], false).', charged '.Money::format((int) ($charged ?: $subjectCharged), $u['currency'], false);
                }
            } else {
                [$status, $reason] = ['not_charged', 'arrangement'];
                $class = $u['class'] ?? null;
                $note = $u['job_id'] === null ? 'Not marked to recharge'
                    : (($term($class)['from'] ?? 'none') === 'none' ? ($client ?? 'The client').' has no arrangement for '.mb_strtolower(CostClass::label($class)).' yet' : 'Not charged by the arrangement');
            }
        }
        $note = $voided !== null && in_array($status, ['owed', 'owed_late', 'not_charged', 'on_draft'], true) ? $voided : $note;
        $blocked = collect(self::UNTICKED_FLAGS)->contains(fn ($f) => isset($u['flags'][$f]));

        return [
            'ref' => $u['ref'], 'status' => $status, 'reason' => $reason, 'expected' => $expected,
            'tick' => in_array($status, ['owed', 'owed_late'], true) && $expected && ! $blocked && ! $difference,
            'late' => $status === 'owed_late', 'measure' => $measure, 'currency' => $u['currency'], 'amount' => $amount, 'charged' => $charged,
            'subject_charged' => $subjectCharged, 'remaining' => $remaining, 'invoices' => $invoices, 'drafts' => $drafts, 'note' => $note,
            'decision' => $decision, 'claimed' => $claimed, 'difference' => $difference, 'covered' => $status === 'covered' ? $covered : null,
            'flags' => [], 'pair_ref' => null,
        ];
    }

    /**
     * A refund is ticked only against what was charged for costs of its kind on the job (issued, or ticked on a
     * draft), shared out between the refunds of that kind, oldest first: one is ticked only while all the credits
     * (those already charged or ticked, and those ticked here) stay within what was charged. The rest are left
     * unticked, asking whether the original was charged.
     *
     * @param  array<string, array<string, mixed>>  $out
     */
    private static function credits(array $units, array $out): array
    {
        $key = fn (array $u) => $u['job_id'].'|'.$u['class'].'|'.$u['currency'];
        $pool = [];
        foreach ($units as $c) {
            $s = $out[$c['ref']];
            $k = $key($c);
            if ($c['kind'] === 'credit') {
                // Credits already on an invoice, or ticked on a draft, have used up what they credit.
                if (in_array($s['status'], ['on_invoice', 'on_draft'], true)) {
                    $pool[$k] = ($pool[$k] ?? 0) - abs((int) $c['amount_minor']);
                }

                continue;
            }
            if ($s['status'] === 'on_invoice' && $s['measure'] === 'minor') {
                $pool[$k] = ($pool[$k] ?? 0) + (int) $s['charged'];
            } elseif ($s['status'] === 'on_draft' && $c['amount_minor'] !== null) {
                $pool[$k] = ($pool[$k] ?? 0) + abs((int) $c['amount_minor']);
            }
        }
        $refunds = collect($units)->filter(fn (array $u) => $u['kind'] === 'credit')->sortBy(fn (array $u) => [$u['date'] ?? '9999', $u['ref']])->values();
        foreach ($refunds as $u) {
            $s = $out[$u['ref']];
            if (! in_array($s['status'], ['owed', 'owed_late'], true) || ($s['decision']['charge'] ?? null) === 'recharge') {
                continue;
            }
            $refund = abs((int) $u['amount_minor']);
            $k = $key($u);
            if (($pool[$k] ?? 0) >= $refund && $refund > 0) {
                $pool[$k] -= $refund;

                continue;
            }
            $client = $u['client_id'] !== null ? Client::query()->whereKey(ClientIds::resolve((int) $u['client_id']))->value('name') : null;
            $out[$u['ref']] = ['status' => 'not_charged', 'reason' => 'unsure', 'expected' => false, 'tick' => false, 'late' => false,
                'note' => 'Was the original charged to '.($client ?? 'the client').'?'] + $s;
        }

        return $out;
    }

    /**
     * Two records that may be one cost (a booking and a payment near it, a hand-entered expense and its card line):
     * CostUnits flags one maybe_same, naming the other. Once the flagged one is charged (on an issued invoice, or
     * ticked on a draft), the other is left unticked too, flagged maybe_same, until you say whether they're the same.
     *
     * @param  array<string, array<string, mixed>>  $out
     */
    private static function twins(array $units, array $out): array
    {
        $byRef = collect($units)->keyBy('ref');
        foreach ($units as $u) {
            $other = $u['pair_ref'] ?? null;
            if ($other === null || ! isset($u['flags']['maybe_same'], $out[$other]) || ! in_array($out[$u['ref']]['status'], ['on_invoice', 'on_draft'], true)) {
                continue;
            }
            $s = $out[$other];
            if (! in_array($s['status'], ['owed', 'owed_late'], true)) {
                continue;
            }
            $where = $out[$u['ref']]['status'] === 'on_invoice' ? 'charged already' : 'ticked on a draft';
            $out[$other] = ['tick' => false, 'pair_ref' => $u['ref'],
                'flags' => ['maybe_same' => 'Maybe the same cost as '.$byRef[$u['ref']]['description'].', '.$where.': say if it is.'] + $s['flags']] + $s;
        }

        return $out;
    }

    /**
     * Lines citing these refs, or any cost of these subjects, with their invoice's status, number, currency and when
     * it was issued.
     *
     * @param  list<string>  $refs
     * @param  list<string>  $subjects  payments and expenses (tx:N, exp:N): every job's or client's cost of each
     * @return Collection<int, object>
     */
    private static function lines(array $refs, array $subjects): Collection
    {
        $select = ['invoice_lines.id', 'invoice_lines.invoice_id', 'invoice_lines.source_ref', 'invoice_lines.included', 'invoice_lines.quantity', 'invoice_lines.amount_minor',
            'cs_inv.status as inv_status', 'cs_inv.number as inv_number', 'cs_inv.currency as inv_currency', 'cs_inv.issued_at as inv_issued_at'];
        $base = fn () => DB::table('invoice_lines')->join('invoices as cs_inv', 'cs_inv.id', '=', 'invoice_lines.invoice_id')->select($select);
        $out = collect();
        foreach (array_chunk($refs, 500) as $chunk) {
            $out = $out->concat($base()->whereIn('invoice_lines.source_ref', $chunk)->get());
        }
        foreach (array_chunk($subjects, 100) as $chunk) {
            $out = $out->concat($base()->where(function ($q) use ($chunk) {
                foreach ($chunk as $s) {
                    $q->orWhere('invoice_lines.source_ref', 'like', $s.'#%');
                }
            })->get());
        }

        return $out->unique('id')->values();
    }

    /**
     * Invoices named by "It was on INV-0031", with the void ones' replacements (up to 5 steps).
     *
     * @return Collection<int, Invoice> by id
     */
    private static function named(array $ids): Collection
    {
        $out = collect();
        $want = array_values(array_unique(array_map('intval', $ids)));
        for ($hop = 0; $want !== [] && $hop <= self::HOPS; $hop++) {
            $found = Invoice::query()->whereIn('id', $want)->get(['id', 'number', 'status', 'replaced_by_id'])->keyBy('id');
            $out = $out->union($found);
            $want = $found->where('status', 'void')->pluck('replaced_by_id')->filter()->map(fn ($id) => (int) $id)->reject(fn ($id) => $out->has($id))->unique()->values()->all();
        }

        return $out;
    }

    /** "1 more day than on INV-0041". */
    private static function more(string $remaining, string $unit, string $on): string
    {
        $n = (string) BigDecimal::of($remaining)->stripTrailingZeros();
        $word = match ($unit) {
            'days' => $n === '1' ? 'day' : 'days',
            'hours' => $n === '1' ? 'hour' : 'hours',
            'miles' => $n === '1' ? 'mile' : 'miles',
            default => '',
        };

        return trim($n.' more '.$word).' than on '.$on;
    }

    private static function minus(int|string $a, int|string $b): int|string
    {
        return is_int($a) && is_int($b) ? $a - $b : (string) BigDecimal::of((string) $a)->minus((string) $b)->toScale(2);
    }

    private static function min(int|string $a, int|string $b): int|string
    {
        return is_int($a) && is_int($b) ? min($a, $b) : (BigDecimal::of((string) $a)->isLessThan((string) $b) ? (string) $a : (string) $b);
    }

    private static function max0(int|string $a): int|string
    {
        return is_int($a) ? max(0, $a) : (BigDecimal::of($a)->isNegative() ? '0.00' : (string) BigDecimal::of($a)->toScale(2));
    }

    private static function positive(int|string|null $a): bool
    {
        return $a !== null && (is_int($a) ? $a > 0 : BigDecimal::of($a)->isPositive());
    }
}
