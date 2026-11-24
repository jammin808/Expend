<?php

namespace App\Domain\Invoices;

use App\Models\Allocation;
use App\Models\Invoice;

/**
 * What has come in for invoices, counted the same way everywhere: the to-do list, the invoices pages, the overdue
 * nudges, reminders, the matcher and the assistant.
 */
final class InvoicePayments
{
    /** Only an invoice that's been issued can be paid by income recorded for its job. */
    private const PAYABLE = ['issued', 'paid'];

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
     * @param  iterable<Invoice>  $invoices
     * @return array<int, array{paid:int, elsewhere:int, waiting:int}> by invoice id
     */
    public static function received(iterable $invoices): array
    {
        $invoices = collect($invoices)->keyBy('id');
        if ($invoices->isEmpty()) {
            return [];
        }
        $jobIds = $invoices->pluck('work_job_id')->filter()->unique()->values();
        // Every issued invoice of those jobs takes its turn, so income pays an earlier invoice first.
        $all = $invoices->union($jobIds->isEmpty() ? [] : Invoice::query()->whereIn('work_job_id', $jobIds)->whereIn('status', self::PAYABLE)->get()->keyBy('id'));
        $out = $all->map(fn () => ['paid' => 0, 'elsewhere' => 0, 'waiting' => 0])->all();

        $lines = Allocation::query()
            ->where(fn ($q) => $q->whereIn('invoice_id', $all->keys())->orWhere(fn ($q) => $q->where('kind', 'business_income')->whereIn('work_job_id', $jobIds)))
            ->with(['transaction:id,amount_pence,original_amount_minor,original_currency', 'manualExpense:id,amount_pence,original_amount_minor,original_currency'])
            ->orderBy('id')->get(['id', 'bank_transaction_id', 'manual_expense_id', 'invoice_id', 'work_job_id', 'kind', 'amount_pence', 'confirmed_at']);

        // Other income on each job (picking none of its issued invoices: none at all, or one since voided), by the
        // currency it came in: [pence, the same in that currency].
        $pool = [];
        $waiting = [];
        $others = $lines->pluck('invoice_id')->filter()->unique()->diff($all->keys());
        $othersOpen = $others->isEmpty() ? collect() : Invoice::query()->whereIn('id', $others)->whereIn('status', self::PAYABLE)->pluck('id');
        foreach ($lines as $a) {
            if ($a->bank_transaction_id && ! $a->transaction) {
                continue; // a line from a held or undone statement counts nowhere
            }
            $invoice = $a->invoice_id ? ($all[$a->invoice_id] ?? null) : null;
            $open = $invoice ? in_array($invoice->status, self::PAYABLE, true) : ($a->invoice_id && $othersOpen->contains($a->invoice_id));
            // Income for the job that isn't linked to one of its issued invoices pays them in turn. (A payment linked
            // to a void invoice is still shown on it, and counts for the invoice that replaced it.)
            $forJob = ! $open && $a->kind === 'business_income' && $a->work_job_id && $jobIds->contains($a->work_job_id);
            if (! $a->confirmed_at) {
                if ($invoice) {
                    $out[$invoice->id]['waiting'] += $a->amount_pence;
                }
                if ($forJob) {
                    $waiting[$a->work_job_id] = ($waiting[$a->work_job_id] ?? 0) + $a->amount_pence;
                }

                continue;
            }
            [$currency, $minor] = self::inCurrency($a);
            if ($invoice) {
                $target = self::currencyOf($invoice);
                match (true) {
                    $target === 'GBP' => $out[$invoice->id]['paid'] += $a->amount_pence,
                    $target === $currency => $out[$invoice->id]['paid'] += $minor,
                    default => $out[$invoice->id]['elsewhere'] += $a->amount_pence,
                };
            }
            if ($forJob) {
                $pool[$a->work_job_id][$currency] ??= [0, 0];
                $pool[$a->work_job_id][$currency][0] += $a->amount_pence;
                $pool[$a->work_job_id][$currency][1] += $minor;
            }
        }

        $payable = $all->filter(fn (Invoice $i) => in_array($i->status, self::PAYABLE, true));
        $order = $payable->sortBy(fn (Invoice $i) => [$i->issue_date?->format('Y-m-d') ?? '9999-12-31', $i->id]);
        foreach ($order->groupBy('work_job_id') as $jobId => $jobInvoices) {
            foreach ($jobInvoices as $invoice) {
                $out[$invoice->id]['waiting'] += $jobId ? ($waiting[$jobId] ?? 0) : 0;
            }
            if (! $jobId || empty($pool[$jobId])) {
                continue;
            }
            $left = $pool[$jobId];
            $need = function (Invoice $i) use (&$out) {
                return $i->total_minor - $out[$i->id]['paid'];
            };

            // First, money in each invoice's own currency, oldest invoice first, so euros pay a euro invoice even
            // when a pound invoice for the job is older.
            foreach ($jobInvoices as $invoice) {
                $target = self::currencyOf($invoice);
                [$pence, $minor] = $left[$target] ?? [0, 0];
                $take = min($need($invoice), $minor);
                if ($take <= 0) {
                    continue;
                }
                $out[$invoice->id]['paid'] += $take;
                $left[$target] = [$pence - ($target === 'GBP' ? $take : self::share($take, $pence, $minor)), $minor - $take];
            }

            // Then a pound invoice takes what's left in other currencies, by what it was worth in pounds.
            foreach ($jobInvoices->filter(fn (Invoice $i) => self::currencyOf($i) === 'GBP') as $invoice) {
                foreach ($left as $currency => [$pence, $minor]) {
                    $take = min($need($invoice), $pence);
                    if ($take <= 0) {
                        continue;
                    }
                    $out[$invoice->id]['paid'] += $take;
                    $left[$currency] = [$pence - $take, $minor - self::share($take, $minor, $pence)];
                }
            }

            // An invoice in another currency still short: money left in a currency it isn't in can't be measured
            // against it, so it's given separately, in pounds.
            foreach ($jobInvoices->filter(fn (Invoice $i) => self::currencyOf($i) !== 'GBP' && $need($i) > 0) as $invoice) {
                foreach ($left as $currency => [$pence]) {
                    if ($currency !== self::currencyOf($invoice) && $pence > 0) {
                        $out[$invoice->id]['elsewhere'] += $pence;
                    }
                }
            }
        }

        return array_intersect_key($out, $invoices->all());
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

    /** @return array{0:string, 1:int} the currency a payment came in (pounds unless the bank gave another) and its amount in it */
    private static function inCurrency(Allocation $a): array
    {
        $source = $a->transaction ?? $a->manualExpense;
        $currency = strtoupper((string) $source?->original_currency);
        if ($currency === '' || $currency === 'GBP' || ! $source->original_amount_minor || ! $source->amount_pence) {
            return ['GBP', $a->amount_pence];
        }

        // This line's share of the payment, in the currency it came in.
        return [$currency, self::share($a->amount_pence, abs($source->original_amount_minor), abs($source->amount_pence))];
    }

    /** $amount × $of / $per in whole units, rounded half away from zero, without floats. */
    private static function share(int $amount, int $of, int $per): int
    {
        if ($per <= 0 || $of <= 0) {
            return 0;
        }
        $units = intdiv(2 * abs($amount) * $of + $per, 2 * $per);

        return $amount < 0 ? -$units : $units;
    }
}
