<?php

namespace App\Domain\Invoices;

use App\Models\Allocation;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What has come in for invoices, counted the same way everywhere: the to-do list, the invoices pages, the overdue
 * nudges, reminders, the matcher and the assistant.
 */
final class InvoicePayments
{
    /** Only an invoice that's been issued (or marked paid by an earlier release) can be paid. */
    public const PAYABLE = ['issued', 'paid'];

    /** An invoice nothing has come in for, with every key received() gives (for readers missing a row). */
    public const EMPTY = [
        'paid' => 0, 'elsewhere' => 0, 'waiting' => 0, 'paid_linked' => 0, 'refunded' => 0, 'waiting_linked' => 0, 'waiting_unmeasured' => 0,
        'closed' => 0, 'outstanding' => 0, 'over' => 0, 'state' => 'unpaid',
    ];

    /** How many replacements are followed from a void invoice to the one that finally replaced it. */
    private const HOPS = 5;

    /**
     * What has come in for each invoice, in the invoice's own currency.
     *
     * A payment counts towards an invoice when it's linked to it (the matcher links one of exactly the invoice's
     * total), or when you decided it as business income for the invoice's job without picking the invoice: that
     * income pays the job's issued invoices in the currency it came in first, oldest first, and then a pound invoice
     * takes what's left in other currencies by its value in pounds. Only confirmed decisions count. For an invoice in
     * another currency, money left in a currency it isn't in can't be measured against it, so it's given separately,
     * in pounds, never guessed.
     * A payment linked to the invoice or suggested as income for its job, but not yet confirmed, is given as waiting
     * (in pounds): it's in the Inbox, and may be for it.
     *
     * Income design v3, none of which changes anything for a ledger without closings, replacements or lines from
     * earlier jobs:
     *  - a closed invoice takes from its job's income only what it took when it was closed (need = total − paid −
     *    what the closing still holds back: closed_minor less what payments naming it paid since, heldBack()), so the
     *    job's money passes on to its next invoice, and money naming it later shrinks what was written off;
     *  - a payment naming a void invoice that was replaced counts as linked to the invoice that finally replaced it
     *    (following up to 5 replacements, stopping at a loop), never through the job's income; it's still shown on the
     *    void one;
     *  - a payment naming a void invoice that wasn't replaced is the job's income only when it's on that invoice's own
     *    job (or the invoice had no job); on any other job, or no job, it counts nowhere (see stranded());
     *  - what's left of a job's income after its own invoices pays lines 'From earlier jobs' on other invoices that came
     *    from that job (invoice_lines.source_job_id, ticked only), oldest invoice first, up to those lines' total.
     *
     * Each invoice also gets: paid_linked (paid by payments naming it, or a void invoice it replaced), refunded (money
     * paid back against it, as a positive amount), waiting_linked (waiting payments naming it, in its own currency),
     * waiting_unmeasured (waiting payments naming it in a currency it can't be measured in, in pence), closed (what
     * your closing still writes off: min(closed_minor, total − paid)), outstanding (total − paid − closed), over (paid
     * beyond the total) and state: paid, closed, part_paid, check_currency or unpaid, or draft and void, which owe
     * nothing (closed, outstanding and over are 0).
     *
     * @param  iterable<Invoice>  $invoices
     * @return array<int, array{paid:int, elsewhere:int, waiting:int, paid_linked:int, refunded:int, waiting_linked:int, waiting_unmeasured:int, closed:int, outstanding:int, over:int, state:string}> by invoice id
     */
    public static function received(iterable $invoices): array
    {
        $invoices = collect($invoices)->keyBy('id');
        if ($invoices->isEmpty()) {
            return [];
        }
        // Every issued invoice of those jobs takes its turn, so income pays an earlier invoice first; and the jobs that
        // lines from earlier jobs came from, with the invoices that share what's left of their income.
        [$all, $jobIds, $from] = self::circle($invoices);
        $out = $all->map(fn () => array_fill_keys(['paid', 'elsewhere', 'waiting', 'paid_linked', 'refunded', 'waiting_linked', 'waiting_unmeasured'], 0))->all();

        // Void invoices that were replaced, and the void invoices whose payments count for one of these.
        $replaced = self::replacements();
        $into = array_keys(array_filter($replaced, fn ($to, $void) => $all->has(self::end($void, $replaced)), ARRAY_FILTER_USE_BOTH));

        $lines = Allocation::query()
            ->where(fn ($q) => $q->whereIn('invoice_id', $all->keys()->merge($into)->unique()->values())->orWhere(fn ($q) => $q->where('kind', 'business_income')->whereIn('work_job_id', $jobIds)))
            ->with(['transaction:id,amount_pence,original_amount_minor,original_currency', 'manualExpense:id,amount_pence,original_amount_minor,original_currency'])
            ->orderBy('id')->get(['id', 'bank_transaction_id', 'manual_expense_id', 'invoice_id', 'work_job_id', 'kind', 'amount_pence', 'confirmed_at']);

        // Other invoices these lines name (or that replaced one they name): whether they can be paid, and their job.
        $named = $lines->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $otherIds = $named->merge($named->map(fn ($id) => self::end($id, $replaced)))->unique()->diff($all->keys())->values();
        $others = $otherIds->isEmpty() ? collect() : Invoice::query()->whereIn('id', $otherIds)->get(['id', 'status', 'work_job_id'])->keyBy('id');
        $find = fn (int $id): ?Invoice => $all->get($id) ?? $others->get($id);

        // Other income on each job (picking none of the invoices that can be paid: none at all, or a void one of the
        // same job), by the currency it came in: [pence, the same in that currency].
        $pool = [];
        $waiting = [];
        foreach ($lines as $a) {
            if ($a->bank_transaction_id && ! $a->transaction) {
                continue; // a line from a held or undone statement counts nowhere
            }
            $invoice = $a->invoice_id ? $find((int) $a->invoice_id) : null;
            // The invoice it counts for: the one it names, or the one that finally replaced it.
            $target = $invoice && $invoice->status === 'void' && isset($replaced[$invoice->id]) ? ($find(self::end($invoice->id, $replaced)) ?? $invoice) : $invoice;
            $linked = $target && in_array($target->status, self::PAYABLE, true) ? $target : null;
            // Income for the job that isn't linked to an invoice that can be paid pays the job's invoices in turn: a
            // payment naming a void invoice only on that invoice's own job (or when it had none).
            $forJob = ! $linked && $a->kind === 'business_income' && $a->work_job_id && $jobIds->contains((int) $a->work_job_id)
                && (! $target || $target->status !== 'void' || $target->work_job_id === null || (int) $target->work_job_id === (int) $a->work_job_id);
            // Shown on the invoice it names (a void one too), and counted for the one that replaced it.
            $on = array_values(array_unique(array_filter([
                $invoice && $all->has($invoice->id) ? $invoice->id : null,
                $linked && $all->has($linked->id) ? $linked->id : null,
            ])));
            [$currency, $minor] = self::inCurrency($a);
            if (! $a->confirmed_at) {
                foreach ($on as $id) {
                    $out[$id]['waiting'] += $a->amount_pence;
                    $value = self::measured($all[$id], $currency, $a->amount_pence, $minor);
                    $value === null ? $out[$id]['waiting_unmeasured'] += $a->amount_pence : $out[$id]['waiting_linked'] += $value;
                }
                if ($forJob) {
                    $waiting[$a->work_job_id] = ($waiting[$a->work_job_id] ?? 0) + $a->amount_pence;
                }

                continue;
            }
            foreach ($on as $id) {
                $value = self::measured($all[$id], $currency, $a->amount_pence, $minor);
                if ($value === null) {
                    $out[$id]['elsewhere'] += $a->amount_pence;

                    continue;
                }
                $out[$id]['paid'] += $value;
                $out[$id]['paid_linked'] += $value;
                $out[$id]['refunded'] += max(0, -$value);
            }
            if ($forJob) {
                $pool[$a->work_job_id][$currency] ??= [0, 0];
                $pool[$a->work_job_id][$currency][0] += $a->amount_pence;
                $pool[$a->work_job_id][$currency][1] += $minor;
            }
        }

        // What an invoice still takes: a closed invoice only what it took when it was closed.
        $need = function (Invoice $i) use (&$out): int {
            return max(0, (int) $i->total_minor - $out[$i->id]['paid'] - self::heldBack($i, $out[$i->id]['paid_linked']));
        };
        $payable = $all->filter(fn (Invoice $i) => in_array($i->status, self::PAYABLE, true));
        $order = $payable->sortBy(fn (Invoice $i) => [$i->issue_date?->format('Y-m-d') ?? '9999-12-31', $i->id]);
        $left = $pool;
        foreach ($order->groupBy('work_job_id') as $jobId => $jobInvoices) {
            foreach ($jobInvoices as $invoice) {
                $out[$invoice->id]['waiting'] += $jobId ? ($waiting[$jobId] ?? 0) : 0;
            }
            if (! $jobId || empty($pool[$jobId])) {
                continue;
            }
            $left[$jobId] = self::share($pool[$jobId], $jobInvoices, $need, $out);

            // An invoice in another currency still short: money left in a currency it isn't in can't be measured
            // against it, so it's given separately, in pounds.
            foreach ($jobInvoices->filter(fn (Invoice $i) => self::currencyOf($i) !== 'GBP' && $need($i) > 0) as $invoice) {
                foreach ($left[$jobId] as $currency => [$pence]) {
                    if ($currency !== self::currencyOf($invoice) && $pence > 0) {
                        $out[$invoice->id]['elsewhere'] += $pence;
                    }
                }
            }
        }

        // What's left of a job's income after its own invoices pays the lines from that job on other invoices
        // ('From earlier jobs'), oldest invoice first, up to those lines' total; and what's waiting for the job may be
        // for them too.
        ksort($left);
        foreach ($left as $jobId => $money) {
            $takers = $order->filter(fn (Invoice $i) => isset($from[$i->id][$jobId]));
            if ($takers->isEmpty()) {
                continue;
            }
            $cap = $takers->mapWithKeys(fn (Invoice $i) => [$i->id => $from[$i->id][$jobId]])->all();
            $upTo = function (Invoice $i) use ($need, &$cap): int {
                return min($need($i), $cap[$i->id]);
            };
            self::share($money, $takers, $upTo, $out, $cap);
        }
        foreach ($from as $invoiceId => $jobs) {
            if ($payable->has($invoiceId)) {
                foreach (array_keys($jobs) as $jobId) {
                    $out[$invoiceId]['waiting'] += $waiting[$jobId] ?? 0;
                }
            }
        }

        return $invoices->map(fn (Invoice $i) => self::settle($i, $out[$i->id]))->all();
    }

    /**
     * Payments naming a void invoice that count nowhere, by void invoice: those with no job, on a job other than the
     * void invoice's own, or not income (net, confirmed, in pence). A payment naming a void invoice that was replaced
     * counts for its replacement; one on the void invoice's own job (or on any job when it had none) is that job's
     * income. Held statements count nowhere and aren't listed. Until you say which invoice replaced it, its client's
     * reminders are held.
     *
     * @param  iterable<Invoice|int>|null  $voids  only these void invoices (every one when null)
     * @return array<int, int> pence by void invoice id, only where some is stranded
     */
    public static function stranded(?iterable $voids = null): array
    {
        $only = $voids === null ? null : collect($voids)->map(fn ($v) => (int) ($v instanceof Invoice ? $v->id : $v))->all();
        $replaced = self::replacements();
        $ends = Invoice::query()->where('status', 'void')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => self::end((int) $id, $replaced)]);
        $endInvoices = $ends->isEmpty() ? collect() : Invoice::query()->whereIn('id', $ends->unique()->values())->get(['id', 'status', 'work_job_id'])->keyBy('id');
        // The void invoices whose payments stay theirs: no replacement, or one that was voided in turn.
        $named = $ends->filter(fn ($end) => $endInvoices->get($end)?->status === 'void' && ($only === null || in_array($end, $only, true)));
        if ($named->isEmpty()) {
            return [];
        }

        $out = [];
        $lines = Allocation::query()->whereIn('invoice_id', $named->keys())->whereNotNull('confirmed_at')
            ->with(['transaction:id', 'manualExpense:id'])->orderBy('id')
            ->get(['id', 'bank_transaction_id', 'manual_expense_id', 'invoice_id', 'work_job_id', 'kind', 'amount_pence']);
        foreach ($lines as $a) {
            if ($a->bank_transaction_id && ! $a->transaction) {
                continue;
            }
            $void = $endInvoices[$named[(int) $a->invoice_id]];
            $counts = $a->kind === 'business_income' && $a->work_job_id !== null
                && ($void->work_job_id === null || (int) $void->work_job_id === (int) $a->work_job_id);
            if (! $counts) {
                $out[$void->id] = ($out[$void->id] ?? 0) + $a->amount_pence;
            }
        }

        return array_filter($out, fn (int $pence) => $pence !== 0);
    }

    /**
     * What one line naming an invoice counts as against it, in the invoice's own currency (as received() counts it in
     * paid or waiting_linked), or null when it came in a currency the invoice can't be measured in (elsewhere or
     * waiting_unmeasured). The line's payment is read from it (transaction or manualExpense).
     */
    public static function measuredLine(Allocation $line, Invoice $invoice): ?int
    {
        [$currency, $minor] = self::inCurrency($line);

        return self::measured($invoice, $currency, (int) $line->amount_pence, $minor);
    }

    /** What has come in for one invoice, in its own currency (see received()). */
    public static function paid(Invoice $invoice): int
    {
        return $invoice->exists ? (self::received([$invoice])[$invoice->id]['paid'] ?? 0) : 0;
    }

    public static function currencyOf(Invoice $invoice): string
    {
        return strtoupper($invoice->currency ?: 'GBP');
    }

    /**
     * The invoices whose payments decide these invoices', and the jobs whose income counts: the invoices asked about,
     * every invoice that can be paid of their jobs, the jobs their lines 'From earlier jobs' came from, and every
     * invoice that can be paid with lines from those jobs (they share what's left in turn), until nothing new is found.
     * Without lines from earlier jobs, that's the invoices asked about and their jobs' invoices, as it always was.
     *
     * Returns the invoices by id, the job ids, and the ticked total of lines from each other job on each invoice
     * ([invoice id => [job id => minor units]]).
     *
     * @param  Collection<int, Invoice>  $invoices  keyed by id
     * @return array{0: Collection<int, Invoice>, 1: Collection<int, int>, 2: array<int, array<int, int>>}
     */
    private static function circle(Collection $invoices): array
    {
        $all = $invoices;
        $jobIds = collect();
        $read = collect(); // invoices whose lines from earlier jobs were read
        $from = [];
        for ($round = 0; $round < 10; $round++) {
            $newJobs = $all->pluck('work_job_id')->filter()->map(fn ($id) => (int) $id)
                ->merge(collect($from)->flatMap(fn ($jobs) => array_keys($jobs)))->unique()->diff($jobIds)->values();
            $jobIds = $jobIds->merge($newJobs)->values();
            if ($newJobs->isNotEmpty()) {
                $all = $all->union(Invoice::query()->whereIn('work_job_id', $newJobs)->whereIn('status', self::PAYABLE)->whereNotIn('id', $all->keys())->get()->keyBy('id'));
            }
            $unread = $all->keys()->diff($read)->values();
            $read = $read->merge($unread);
            if ($unread->isEmpty() && $newJobs->isEmpty()) {
                break;
            }
            $rows = DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
                ->where('l.included', true)->whereNotNull('l.source_job_id')->whereIn('i.status', self::PAYABLE)
                ->where(fn ($q) => $q->whereNull('i.work_job_id')->orWhereColumn('i.work_job_id', '!=', 'l.source_job_id'))
                ->where(fn ($q) => $q->whereIn('l.invoice_id', $unread)->orWhereIn('l.source_job_id', $newJobs))
                ->groupBy('l.invoice_id', 'l.source_job_id')
                ->get(['l.invoice_id', 'l.source_job_id', DB::raw('SUM(l.amount_minor) as minor')]);
            if ($rows->isEmpty()) {
                if ($newJobs->isEmpty()) {
                    break;
                }

                continue;
            }
            foreach ($rows as $row) {
                $from[(int) $row->invoice_id][(int) $row->source_job_id] = (int) $row->minor;
            }
            $missing = collect(array_keys($from))->diff($all->keys())->values();
            if ($missing->isNotEmpty()) {
                $all = $all->union(Invoice::query()->whereIn('id', $missing)->get()->keyBy('id'));
            }
        }

        return [$all, $jobIds, $from];
    }

    /**
     * Shares money among invoices, oldest first: first money in each invoice's own currency, so euros pay a euro
     * invoice even when a pound invoice is older; then a pound invoice takes what's left in other currencies, by what
     * it was worth in pounds. $cap (when given) is lowered by what each takes.
     *
     * @param  array<string, array{0:int, 1:int}>  $money  [currency => [pence, the same in that currency]]
     * @param  Collection<int, Invoice>  $invoices  oldest first
     * @param  callable(Invoice): int  $need
     * @param  array<int, int>|null  $cap
     * @return array<string, array{0:int, 1:int}> what's left
     */
    private static function share(array $money, Collection $invoices, callable $need, array &$out, ?array &$cap = null): array
    {
        $took = function (Invoice $invoice, int $take) use (&$out, &$cap) {
            $out[$invoice->id]['paid'] += $take;
            if ($cap !== null) {
                $cap[$invoice->id] -= $take;
            }
        };
        foreach ($invoices as $invoice) {
            $target = self::currencyOf($invoice);
            [$pence, $minor] = $money[$target] ?? [0, 0];
            $take = min($need($invoice), $minor);
            if ($take <= 0) {
                continue;
            }
            $took($invoice, $take);
            $money[$target] = [$pence - ($target === 'GBP' ? $take : self::part($take, $pence, $minor)), $minor - $take];
        }
        foreach ($invoices->filter(fn (Invoice $i) => self::currencyOf($i) === 'GBP') as $invoice) {
            foreach ($money as $currency => [$pence, $minor]) {
                $take = min($need($invoice), $pence);
                if ($take <= 0) {
                    continue;
                }
                $took($invoice, $take);
                $money[$currency] = [$pence - $take, $minor - self::part($take, $minor, $pence)];
            }
        }

        return $money;
    }

    /**
     * What a closing still holds back from the job's income: what was written off (closed_minor), less what payments
     * naming the invoice have brought in since it was closed (paid_linked beyond closed_linked_minor). So money naming
     * it after the closing shrinks what was written off, and the job's income it took when it was closed stays its own:
     * £300 of job income, closed with £700 written off, then £700 naming it is £1,000 paid. Money naming it that's paid
     * back comes off what came in since first; paid back below what it had paid at the closing, the rest is owed again.
     * A closing that didn't keep closed_linked_minor holds back closed_minor, as it always did.
     */
    private static function heldBack(Invoice $invoice, int $paidLinked): int
    {
        $closed = max(0, (int) $invoice->closed_minor);
        if ($closed === 0 || $invoice->closed_linked_minor === null) {
            return $closed;
        }

        return max(0, $closed - max(0, $paidLinked - (int) $invoice->closed_linked_minor));
    }

    /**
     * An invoice's figures and state, from what came in.
     *
     * @param  array<string, int>  $r
     */
    private static function settle(Invoice $invoice, array $r): array
    {
        $total = (int) $invoice->total_minor;
        if (! in_array($invoice->status, self::PAYABLE, true)) {
            return $r + ['closed' => 0, 'outstanding' => 0, 'over' => 0, 'state' => $invoice->status === 'void' ? 'void' : 'draft'];
        }
        $closed = min(max(0, (int) $invoice->closed_minor), max(0, $total - $r['paid']));
        $outstanding = max(0, $total - $r['paid'] - $closed);
        $state = match (true) {
            $r['paid'] >= $total => 'paid',
            $closed > 0 && $outstanding === 0 => $invoice->closed_reason === 'paid_other_currency' ? 'paid' : 'closed',
            $r['paid'] > 0 => 'part_paid',
            $r['elsewhere'] > 0 => 'check_currency',
            default => 'unpaid',
        };

        return $r + ['closed' => $closed, 'outstanding' => $outstanding, 'over' => max(0, $r['paid'] - $total), 'state' => $state];
    }

    /** A payment's amount in an invoice's currency: pence for a pound invoice, its own amount when it came in that currency, else null (can't be measured). */
    private static function measured(Invoice $invoice, string $currency, int $pence, int $minor): ?int
    {
        $target = self::currencyOf($invoice);

        return match (true) {
            $target === 'GBP' => $pence,
            $target === $currency => $minor,
            default => null,
        };
    }

    /** @return array<int, int> [void invoice id => the invoice that replaced it] */
    private static function replacements(): array
    {
        return Invoice::query()->where('status', 'void')->whereNotNull('replaced_by_id')->pluck('replaced_by_id', 'id')
            ->mapWithKeys(fn ($to, $id) => [(int) $id => (int) $to])->all();
    }

    /** The invoice that finally replaced a void one (itself when it wasn't replaced): at most HOPS steps, stopping at a loop. */
    private static function end(int $id, array $replaced): int
    {
        $seen = [$id => true];
        for ($hop = 0; $hop < self::HOPS && isset($replaced[$id]); $hop++) {
            $next = $replaced[$id];
            if (isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $id = $next;
        }

        return $id;
    }

    /** @return array{0:string, 1:int} the currency a payment came in (pounds unless the bank gave another) and its amount in it */
    private static function inCurrency(Allocation $a): array
    {
        $source = $a->transaction ?? $a->manualExpense;
        $currency = strtoupper((string) $source?->original_currency);
        if ($currency === '' || $currency === 'GBP' || ! $source->original_amount_minor || ! $source->amount_pence) {
            return ['GBP', $a->amount_pence];
        }

        // This line's share of the payment, in the currency it came in. The line keeps it in that currency's own
        // units (¥1,500 is 1500); invoices are in hundredths of theirs, so it's put in hundredths to compare.
        return [$currency, (int) Money::toHundredths(self::part($a->amount_pence, abs($source->original_amount_minor), abs($source->amount_pence)), $currency)];
    }

    /** $amount × $of / $per in whole units, rounded half away from zero, without floats. */
    private static function part(int $amount, int $of, int $per): int
    {
        if ($per <= 0 || $of <= 0) {
            return 0;
        }
        $units = intdiv(2 * abs($amount) * $of + $per, 2 * $per);

        return $amount < 0 ? -$units : $units;
    }
}
