<?php

namespace App\Domain\Income;

use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobBilling;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\WorkJob;
use Illuminate\Support\Collection;

/**
 * What a payment could be for, as one-tap chips on the Inbox card, in the drawer and on the to-do list (income design
 * v3). At most 4, in this order, for money in:
 *  - an open invoice whose amount still to come is the payment ("INV-042 · Show A · £450"), two when tied;
 *  - a unique set of the payer's client's invoices, or a set or one invoice with a fee or commission taken off
 *    ("INV-041 + INV-043 · £1,350", "… · £30 taken off");
 *  - a finished job of the payer's client with nothing billed ("Show B · 12 Oct · nothing billed");
 *  - the payer's client with no job ("Acme Events · no job").
 * A payer that pays for several clients gets invoice chips only, its most frequent client's first. Money out gets
 * refunds: "Refund to Acme Events" (income paid back, no job), "Money back for the £450 on 3 Mar (INV-042)" (a payment
 * of yours from them in the last 60 days, reversed with its links), and "Money back for the £100 overpaid on INV-042".
 *
 * A chip fills the decision: its lines (with explicit invoice_id and client_id), saved as your decision. Nothing is
 * written here.
 */
final class IncomeTargets
{
    public const MAX = 4;

    /** How far back a payment you're paying back can be, for "Money back for …". */
    public const REVERSAL_DAYS = 60;

    /** @var array<int, ?string> client names */
    private array $names = [];

    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, WorkJob>> each client's finished jobs with nothing billed */
    private array $owed = [];

    private function __construct(private InvoiceMatcher $matcher, private PayerAliases $aliases) {}

    /**
     * The chips for one payment.
     *
     * @return list<array{kind:string, label:string, save:string, lines:list<array<string, mixed>>, invoice_ids:list<int>, job_id:?int, client_id:?int}>
     */
    public static function for(BankTransaction $tx): array
    {
        return self::forMany(collect([$tx]))[$tx->id] ?? [];
    }

    /**
     * The chips for a page of payments, sharing one read of open invoices, payers and vetoes.
     *
     * @param  Collection<int, BankTransaction>  $txs
     * @return array<int, list<array<string, mixed>>> by payment id
     */
    public static function forMany(Collection $txs): array
    {
        $txs = $txs->filter(fn ($t) => $t instanceof BankTransaction)->values();
        if ($txs->isEmpty()) {
            return [];
        }
        $matcher = app(InvoiceMatcher::class)->begin($txs);
        $self = new self($matcher, PayerAliases::forKeys($txs->pluck('merchant_key')));
        $out = [];
        foreach ($txs as $tx) {
            $out[$tx->id] = $tx->amount_pence > 0 ? $self->moneyIn($tx) : $self->moneyOut($tx);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function moneyIn(BankTransaction $tx): array
    {
        $key = $tx->merchant_key;
        $shared = $this->aliases->shared($key);
        $sole = $this->aliases->sole($key, 1);
        $o = $this->matcher->options($tx);
        $chips = [];

        $exact = $o['exact'];
        if ($shared) {
            // A payer for several clients: its most frequent client's invoices first.
            usort($exact, fn (array $a, array $b) => $this->aliases->count($key, (int) IncomeClient::ofInvoice($b['invoice']))
                <=> $this->aliases->count($key, (int) IncomeClient::ofInvoice($a['invoice'])));
        }
        foreach (array_slice($exact, 0, 2) as $c) {
            $invoice = $c['invoice'];
            $chips[] = self::chip('invoice', implode(' · ', array_filter([$invoice->number, $invoice->job?->title, IncomeText::money($c['open'], InvoicePayments::currencyOf($invoice))])),
                'Save '.($invoice->number ?: 'it'), [Lines::income($tx->amount_pence, $invoice->id, self::jobOf($invoice))]);
        }
        foreach (array_filter([$o['set'], $o['net']]) as $p) {
            $names = implode(' + ', array_map(fn (array $c) => $c['invoice']->number, $p['invoices']));
            $gross = array_sum(array_column($p['invoices'], 'open'));
            $label = $names.' · '.IncomeText::money($gross).($p['fee'] ? ' · '.IncomeText::money($p['fee']).' taken off' : '');
            $chips[] = self::chip($p['level'] === 'set' ? 'set' : 'net', $label, 'Save '.$names, $p['lines']);
        }
        if ($shared || $sole === null) {
            return array_slice($chips, 0, self::MAX);
        }

        if ($job = $this->owedJob($sole, $tx)) {
            $chips[] = self::chip('job', $job->title.' · '.$job->ends_on->format('j M').' · nothing billed', 'Save '.$job->title,
                [Lines::income($tx->amount_pence, null, $job->id)]);
        }
        if ($name = $this->name($sole)) {
            $chips[] = self::chip('client', $name.' · no job', 'Save', [Lines::income($tx->amount_pence, null, null, $sole)]);
        }

        return array_slice($chips, 0, self::MAX);
    }

    /** @return list<array<string, mixed>> */
    private function moneyOut(BankTransaction $tx): array
    {
        $chips = [];
        $back = abs($tx->amount_pence);
        $sole = $this->aliases->sole($tx->merchant_key, 1);

        // A payment from them you're paying back, with its links.
        $was = $tx->merchant_key === null || $tx->merchant_key === 'UNKNOWN' ? null : BankTransaction::query()->where('merchant_key', $tx->merchant_key)->where('amount_pence', $back)
            ->whereDate('posted_on', '<=', $tx->posted_on->format('Y-m-d'))->whereDate('posted_on', '>=', $tx->posted_on->copy()->subDays(self::REVERSAL_DAYS)->format('Y-m-d'))
            ->whereHas('allocations', fn ($q) => $q->where('kind', 'business_income')->whereNotNull('confirmed_at')->where(fn ($q) => $q->where('decided_by', 'you')->orWhere('confirmed_by', 'you')))
            ->with(['allocations.invoice:id,number,status', 'allocations.job:id,title'])->orderByDesc('posted_on')->orderByDesc('id')->first();
        if ($was && $was->allocations->count() === 1 && $was->allocations->first()->kind === 'business_income') {
            $line = $was->allocations->first();
            $payable = $line->invoice === null || in_array($line->invoice->status, InvoicePayments::PAYABLE, true);
            $what = $line->invoice?->number ?? $line->job?->title;
            if ($payable) {
                $chips[] = self::chip('reversal', 'Money back for the '.IncomeText::money($back).' on '.$was->posted_on->format('j M').($what ? " ({$what})" : ''), 'Save',
                    [Lines::income($tx->amount_pence, $line->invoice_id, $line->work_job_id, $line->client_id, $line->category_id)]);
            }
        }

        if ($sole !== null) {
            // An invoice of theirs paid more than it was for, by exactly this.
            $over = $this->overpaid($sole, $back);
            if ($over) {
                $chips[] = self::chip('overpaid', 'Money back for the '.IncomeText::money($back).' overpaid on '.$over->number, 'Save',
                    [Lines::income($tx->amount_pence, $over->id, self::jobOf($over))]);
            }
            if ($name = $this->name($sole)) {
                $chips[] = self::chip('refund', 'Refund to '.$name, 'Save', [Lines::income($tx->amount_pence, null, null, $sole)]);
            }
        }

        return array_slice($chips, 0, self::MAX);
    }

    /** The finished job of this client with nothing billed that ended last on or before the payment. */
    private function owedJob(int $client, BankTransaction $tx): ?WorkJob
    {
        $this->owed[$client] ??= JobBilling::owed($client);

        return $this->owed[$client]->first(fn (WorkJob $j) => $j->ends_on !== null && $j->ends_on->lte($tx->posted_on));
    }

    /** An issued invoice of this client overpaid by exactly this much. */
    private function overpaid(int $client, int $pence): ?Invoice
    {
        $invoices = Invoice::query()->where('status', 'issued')->where('currency', 'GBP')->with('job:id,client_id')->get()
            ->filter(fn (Invoice $i) => IncomeClient::ofInvoice($i) === $client)->values();
        if ($invoices->isEmpty()) {
            return null;
        }
        $received = InvoicePayments::received($invoices);

        return $invoices->first(fn (Invoice $i) => ($received[$i->id]['over'] ?? 0) === $pence);
    }

    private function name(int $client): ?string
    {
        return $this->names[$client] ??= Client::query()->whereKey($client)->value('name');
    }

    private static function jobOf(Invoice $invoice): ?int
    {
        return $invoice->work_job_id === null ? null : (int) $invoice->work_job_id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{kind:string, label:string, save:string, lines:list<array<string, mixed>>, invoice_ids:list<int>, job_id:?int, client_id:?int}
     */
    private static function chip(string $kind, string $label, string $save, array $lines): array
    {
        $jobs = array_values(array_unique(array_filter(array_map(fn ($l) => $l['work_job_id'] ?? null, $lines))));
        $clients = array_values(array_unique(array_filter(array_map(fn ($l) => $l['client_id'] ?? null, $lines))));

        return [
            'kind' => $kind, 'label' => $label, 'save' => $save, 'lines' => array_values($lines),
            'invoice_ids' => array_values(array_unique(array_filter(array_map(fn ($l) => $l['invoice_id'] ?? null, $lines)))),
            'job_id' => count($jobs) === 1 ? (int) $jobs[0] : null,
            'client_id' => count($clients) === 1 ? (int) $clients[0] : null,
        ];
    }
}
