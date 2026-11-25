<?php

namespace App\Domain\Income;

use App\Domain\Import\RevolutImport;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobCreator;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\OwnAccounts;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\Veto;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Which invoice money coming in is for (income design v3), and the only writer of suggest.invoice, suggest.invoice_set
 * and suggest.invoice_net. One level per pass, first that fits:
 *  - exact: the only open invoice whose amount still to come is the payment (in pounds for a pound invoice; in its own
 *    currency for another, when the payment came in that currency). Tied candidates keep those of the payer's sole
 *    client; still tied, nothing is proposed (the Inbox shows them as chips);
 *  - set: with a payer known to pay for one client, 2 to 4 of that client's oldest 12 open pound invoices that add up
 *    to the payment, when exactly one combination does;
 *  - net: a fee or commission taken off, for pound invoices into a pound account and never for a payslip-like payer:
 *    the bank's own fee (RevolutImport::poundFee), or the payer's usual (2 of its last 5 receipts you decided with a
 *    deduction agree on a fixed fee within 1p or a rate within 10 basis points). One invoice, else (with a sole client)
 *    the same bounded set search; a unique fit proposes the gross income and the deduction.
 *
 * An open invoice is an issued one with money still to come: what's outstanding, less what other payments waiting in
 * the Inbox name for it (in its own currency; a payment's own suggestion is never taken off what it's measured
 * against), less what this run has already proposed for it (the tally), so two payments never take the same amount. Invoices you said a payment isn't for are dropped before any search; one in another currency
 * with a payment waiting that can't be measured against it is left out. The payment must come after the invoice (3
 * days' slack, or 60 for a deposit from its known payer); a payer known to pay for one client from 2 or more payments is
 * only matched to that client's invoices.
 *
 * Nothing is ever confirmed (the writer stores income links as suggestions), and a write is refused, never thrown, over
 * your lines, a veto or a stronger suggestion. It never writes over an invoice suggestion, whatever made it.
 */
final class InvoiceMatcher
{
    /** How many of a client's open invoices a set is searched among (the oldest). */
    public const SEARCH = 12;

    /** The smallest and largest number of invoices in a set. */
    public const SET_SIZES = [2, 3, 4];

    /** The most payments forInvoice() looks at for a newly issued invoice. */
    public const FOR_INVOICE_LIMIT = 200;

    /** A payment may come this many days before the invoice's date (sent early), or DEPOSIT_DAYS from its known payer. */
    public const SLACK_DAYS = 3;

    public const DEPOSIT_DAYS = 60;

    /** How many of a payer's latest receipts with a deduction are read for its usual fee, and how many must agree. */
    private const USUAL_OF = 5;

    private const USUAL_AGREE = 2;

    /** Open invoices for this run, by id. */
    private ?EloquentCollection $open = null;

    /** @var array<int, array<string, mixed>> InvoicePayments::received() for them */
    private array $received = [];

    /** @var array<int, ?int> each open invoice's worked-out client (merges resolved) */
    private array $clientOf = [];

    /** @var array<int, int> what this run has proposed for each invoice, in its own currency */
    private array $tally = [];

    /** @var array<string, PayerAliases> by payer */
    private array $aliases = [];

    /** @var array<string, Collection<int, Veto>> invoice vetoes by payment ref */
    private array $vetoes = [];

    /** @var array<string, ?array> the payer's usual fee, by payer */
    private array $usual = [];

    /** @var array<int, string> client keys (JobCreator::clientKey of the name), by client */
    private array $clientKeys = [];

    /** @var array<int, array<int, array{0:int, 1:int}>> each payment's own waiting lines by invoice (ownWaiting()), by payment */
    private array $own = [];

    public function __construct(private AllocationWriter $writer) {}

    /**
     * Start a run: open invoices and what they've received are read once, and the payers and vetoes of these payments
     * (when given) in one query each. The tally starts empty.
     *
     * @param  iterable<BankTransaction>  $transactions
     */
    public function begin(iterable $transactions = []): self
    {
        $this->open = Invoice::query()->where('status', 'issued')->with('job:id,title,client_id')->orderBy('issue_date')->orderBy('id')->get()->keyBy('id');
        $this->received = $this->open->isEmpty() ? [] : InvoicePayments::received($this->open);
        $raw = $this->open->mapWithKeys(fn (Invoice $i) => [$i->id => $i->client_id ?? $i->job?->client_id])->all();
        $merged = Client::query()->whereIn('id', array_values(array_unique(array_filter($raw))))->whereNotNull('merged_into_id')->pluck('merged_into_id', 'id');
        $this->clientOf = array_map(fn ($c) => $c === null ? null : (int) ($merged[$c] ?? $c), $raw);
        $this->tally = [];
        $this->usual = [];
        $this->clientKeys = [];
        $this->own = [];

        $txs = collect($transactions)->filter(fn ($t) => $t instanceof BankTransaction);
        $keys = $txs->pluck('merchant_key')->filter()->unique()->values();
        $loaded = PayerAliases::forKeys($keys);
        $this->aliases = $keys->mapWithKeys(fn ($k) => [$k => $loaded])->all();
        $this->vetoes = $txs->isEmpty() ? [] : Vetoes::forSubjects('invoice_payment', $txs->map(fn ($t) => 'tx:'.$t->id))->all();
        foreach ($txs as $t) {
            $this->vetoes['tx:'.$t->id] ??= collect();
        }

        return $this;
    }

    /**
     * What this payment looks like it's for: the lines, the reason and the provenance to write, or null when nothing
     * fits (or it's tied). Nothing is written.
     *
     * @return array{method:string, level:string, lines:list<array<string, mixed>>, reason:string, provenance:Provenance, invoices:list<array{invoice:Invoice, open:int, amount:int}>, fee:?int}|null
     */
    public function propose(BankTransaction $tx): ?array
    {
        $o = $this->options($tx);

        return match (true) {
            count($o['exact']) === 1 => $this->exactProposal($tx, $o['exact'][0], $o['exact_count']),
            count($o['exact']) > 1 => null, // tied: the chips show them
            default => $o['set'] ?? $o['net'],
        };
    }

    /**
     * Everything that could fit this payment, for the Inbox's chips (IncomeTargets) as well as propose(): the exact
     * candidates (tied ones too, narrowed to the payer's client), and the one set, or net proposal, when exactly one
     * fits. Set and net are only looked for when no exact candidate exists.
     *
     * @return array{exact: list<array{invoice:Invoice, open:int}>, exact_count:int, set: ?array, net: ?array}
     */
    public function options(BankTransaction $tx): array
    {
        $none = ['exact' => [], 'exact_count' => 0, 'set' => null, 'net' => null];
        if ($tx->amount_pence <= 0 || $tx->transfer_pair_id || OwnAccounts::reason($tx) !== null) {
            return $none;
        }
        $this->open ?? $this->begin([$tx]);
        $candidates = $this->candidates($tx);
        if ($candidates === []) {
            return $none;
        }
        $sole = $this->aliasesFor($tx->merchant_key)->sole($tx->merchant_key, 1);

        $exact = array_values(array_filter($candidates, fn (array $c) => $this->paysExactly($tx, $c['invoice'], $c['open'])));
        $count = count($exact);
        if ($count > 1 && $sole !== null) {
            $theirs = array_values(array_filter($exact, fn (array $c) => $this->clientOf[$c['invoice']->id] === $sole));
            $exact = $theirs ?: $exact;
        }
        if ($exact !== []) {
            return ['exact' => $exact, 'exact_count' => $count, 'set' => null, 'net' => null];
        }

        $pounds = array_values(array_filter($candidates, fn (array $c) => InvoicePayments::currencyOf($c['invoice']) === 'GBP'));
        $theirs = $sole === null ? [] : array_slice(array_values(array_filter($pounds, fn (array $c) => $this->isClients($c['invoice'], $sole))), 0, self::SEARCH);

        // Set: exactly one combination of the client's invoices adds up to the payment.
        $sets = $theirs === [] ? [] : self::combinations($theirs, fn (int $sum) => $sum === $tx->amount_pence);
        if (count($sets) === 1) {
            return ['exact' => [], 'exact_count' => 0, 'set' => $this->setProposal($tx, $sets[0], count($theirs)), 'net' => null];
        }
        if (count($sets) > 1) {
            return $none; // two or more fit: not guessed
        }

        return ['exact' => [], 'exact_count' => 0, 'set' => null, 'net' => $this->net($tx, $pounds, $theirs)];
    }

    /**
     * Propose and write it as the matcher's suggestion. Refused (false), never thrown: over your lines or anything
     * confirmed, over an invoice suggestion (whatever made it), a veto, a stronger suggestion, a locked year, or when
     * nothing fits. What's proposed is added to the run's tally.
     */
    public function write(BankTransaction $tx): bool
    {
        $have = $tx->allocations()->get();
        if ($have->contains(fn ($a) => $a->is_manual || $a->confirmed_at !== null) || IncomeText::isInvoiceSuggestion($have)) {
            return false;
        }
        $p = $this->propose($tx);
        if ($p === null) {
            return false;
        }
        try {
            $done = $this->writer->decide($tx, $p['lines'], 'matcher', false, $p['reason'], null, $p['provenance']);
        } catch (InvalidArgumentException) {
            return false; // a locked year, or lines that no longer add up: left as it is
        }
        unset($this->own[$tx->id]); // its lines may have changed
        if ($done) {
            foreach ($p['invoices'] as $c) {
                $this->tally[$c['invoice']->id] = ($this->tally[$c['invoice']->id] ?? 0) + $c['open'];
            }
        }

        return $done;
    }

    /**
     * After an invoice is issued: waiting money in that may be for it gets a suggestion. Payments in open years, not
     * a transfer, not your own money, posted from 60 days before its date, that are plausible for it (the amount still
     * to come, a payer known for its client, or the amount plus the bank's fee); at most 200, oldest first. Each must
     * have nothing yet, or an open suggestion that isn't an invoice suggestion and ranks below an invoice match (a
     * guess; never a rule's, yours, or anything confirmed). Never fails the issue: anything that goes wrong is reported.
     *
     * @return int how many payments now have a suggestion naming this invoice
     */
    public function forInvoice(Invoice $invoice): int
    {
        try {
            $invoice->refresh();
            if ($invoice->status !== 'issued') {
                return 0;
            }
            $this->begin();
            $open = $this->open->get($invoice->id);
            $r = $this->received[$invoice->id] ?? null;
            if (! $open || ! $r || $r['outstanding'] <= 0) {
                return 0;
            }
            $total = max(0, (int) $r['outstanding'] - max(0, (int) $r['waiting_linked']));
            $currency = InvoicePayments::currencyOf($invoice);
            $client = $this->clientOf[$invoice->id] ?? null;
            $keys = $client === null ? [] : array_keys(PayerAliases::keysFor($client));
            $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
            $from = $invoice->issue_date?->copy()->subDays(self::DEPOSIT_DAYS)->format('Y-m-d');

            $payments = BankTransaction::query()->where('amount_pence', '>', 0)->whereNull('transfer_pair_id')->whereNotIn('tax_year', $locked)
                ->when($from, fn ($q) => $q->whereDate('posted_on', '>=', $from))
                ->where(function ($q) use ($total, $currency, $keys) {
                    $currency === 'GBP'
                        ? $q->where('amount_pence', $total)->orWhereRaw('amount_pence + ABS(COALESCE(fee_minor, 0)) = ?', [$total])
                        : $q->where('original_currency', $currency);
                    if ($keys !== []) {
                        $q->orWhereIn('merchant_key', $keys);
                    }
                })
                ->with(['allocations', 'account'])->orderBy('posted_on')->orderBy('id')->limit(self::FOR_INVOICE_LIMIT)->get()
                ->filter(fn (BankTransaction $tx) => $tx->allocations->isEmpty() || (Vetoes::isOpenSuggestion($tx->allocations)
                    && ! IncomeText::isInvoiceSuggestion($tx->allocations)
                    && AllocationWriter::rank($tx->allocations->first()->decided_by === 'rule' ? null : Methods::of($tx->allocations->first())['method'], (string) $tx->allocations->first()->decided_by)
                        < AllocationWriter::PRECEDENCE['suggest.invoice']))
                ->values();
            if ($payments->isEmpty()) {
                return 0;
            }

            $this->begin($payments);
            $named = 0;
            foreach ($payments as $tx) {
                if ($this->write($tx) && $tx->allocations()->where('invoice_id', $invoice->id)->exists()) {
                    $named++;
                }
            }

            return $named;
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    // ---------------------------------------------------------------- candidates

    /**
     * The open invoices this payment could be for, with what's still to come on each (in its own currency).
     *
     * @return list<array{invoice:Invoice, open:int}>
     */
    private function candidates(BankTransaction $tx): array
    {
        $aliases = $this->aliasesFor($tx->merchant_key);
        $sole = $aliases->sole($tx->merchant_key, 1);
        $known = $aliases->sole($tx->merchant_key, 2);
        $vetoes = $this->vetoes['tx:'.$tx->id] ??= Veto::query()->active()->where('relation', 'invoice_payment')->where('subject_ref', 'tx:'.$tx->id)->get();
        $vetoed = $vetoes->filter(fn (Veto $v) => $v->effect === 'block' && ($v->method === null || str_starts_with($v->method, 'suggest.invoice')))
            ->pluck('object_ref')->filter()->flip();

        $own = $this->ownWaiting($tx);
        $out = [];
        foreach ($this->open as $invoice) {
            $r = $this->received[$invoice->id] ?? null;
            if ($r === null || isset($vetoed['inv:'.$invoice->id])) {
                continue;
            }
            // What's waiting naming it from other payments: this payment's own waiting lines aren't taken off.
            [$ownLinked, $ownUnmeasured] = $own[$invoice->id] ?? [0, 0];
            if (InvoicePayments::currencyOf($invoice) !== 'GBP' && (int) $r['waiting_unmeasured'] - $ownUnmeasured > 0) {
                continue; // something waiting for it can't be measured against it: not guessed
            }
            $open = (int) $r['outstanding'] - max(0, (int) $r['waiting_linked'] - $ownLinked) - ($this->tally[$invoice->id] ?? 0);
            if ($open <= 0) {
                continue;
            }
            $client = $this->clientOf[$invoice->id] ?? null;
            if ($invoice->issue_date) {
                $slack = $sole !== null && $client === $sole ? self::DEPOSIT_DAYS : self::SLACK_DAYS;
                if ($tx->posted_on->lt($invoice->issue_date->copy()->subDays($slack))) {
                    continue;
                }
            }
            if ($known !== null && ! $this->isClients($invoice, $known)) {
                continue; // a payer that pays for one client is never matched to another client's invoice
            }
            $out[] = ['invoice' => $invoice, 'open' => $open];
        }

        return $out;
    }

    /**
     * This payment's own waiting (unconfirmed) lines naming an open invoice, by invoice: [in its currency, in pence when
     * they can't be measured in it], as received() counted them in waiting_linked and waiting_unmeasured. A payment is
     * never measured against its own suggestion: the invoice it's suggested for stays one it fits.
     *
     * @return array<int, array{0:int, 1:int}>
     */
    private function ownWaiting(BankTransaction $tx): array
    {
        if (! $tx->exists) {
            return [];
        }
        if (isset($this->own[$tx->id])) {
            return $this->own[$tx->id];
        }
        $out = [];
        $lines = $tx->relationLoaded('allocations') ? $tx->allocations : $tx->allocations()->get();
        foreach ($lines as $a) {
            $invoice = $a->invoice_id === null || $a->confirmed_at !== null ? null : $this->open->get((int) $a->invoice_id);
            if ($invoice === null) {
                continue;
            }
            $value = InvoicePayments::measuredLine((clone $a)->setRelation('transaction', $tx), $invoice);
            $out[$invoice->id] ??= [0, 0];
            $value === null ? $out[$invoice->id][1] += (int) $a->amount_pence : $out[$invoice->id][0] += $value;
        }

        return $this->own[$tx->id] = $out;
    }

    /** Whether the invoice is this client's: its worked-out client, or (with none) its bill-to name is the client's. */
    private function isClients(Invoice $invoice, int $client): bool
    {
        $of = $this->clientOf[$invoice->id] ?? null;
        if ($of !== null) {
            return $of === $client;
        }
        $key = $this->clientKeys[$client] ??= JobCreator::clientKey(Client::query()->whereKey($client)->value('name'));

        return $key !== '' && JobCreator::clientKey($invoice->bill_to_name) === $key;
    }

    /** Whether the payment is exactly what's still to come: in pence for a pound invoice, in its own currency otherwise. */
    private function paysExactly(BankTransaction $tx, Invoice $invoice, int $open): bool
    {
        $currency = InvoicePayments::currencyOf($invoice);
        if ($currency === 'GBP') {
            return $tx->amount_pence === $open;
        }

        return strtoupper((string) $tx->original_currency) === $currency && $tx->original_amount_minor
            && (int) Money::toHundredths(abs((int) $tx->original_amount_minor), $currency) === $open;
    }

    private function aliasesFor(?string $key): PayerAliases
    {
        $key = (string) $key;

        return $this->aliases[$key] ??= PayerAliases::forKeys([$key]);
    }

    // ---------------------------------------------------------------- proposals

    /** @param array{invoice:Invoice, open:int} $c */
    private function exactProposal(BankTransaction $tx, array $c, int $count): array
    {
        $invoice = $c['invoice'];
        $others = array_values(array_filter($this->candidates($tx), fn (array $o) => $o['invoice']->id !== $invoice->id && $this->paysExactly($tx, $o['invoice'], $o['open'])));
        $money = IncomeText::money($c['open'], InvoicePayments::currencyOf($invoice));

        return [
            'method' => 'suggest.invoice', 'level' => 'exact',
            'lines' => [Lines::income($tx->amount_pence, $invoice->id, $invoice->work_job_id === null ? null : (int) $invoice->work_job_id)],
            'reason' => 'Matches what’s still to come on '.self::number($invoice)." ({$money})",
            'provenance' => new Provenance('suggest.invoice', ['inv:'.$invoice->id, $invoice->work_job_id ? 'job:'.$invoice->work_job_id : null], $count,
                array_map(fn (array $o) => ['ref' => 'inv:'.$o['invoice']->id, 'score' => null], $others)),
            'invoices' => [['invoice' => $invoice, 'open' => $c['open'], 'amount' => $tx->amount_pence]],
            'fee' => null,
        ];
    }

    /** @param list<array{invoice:Invoice, open:int}> $set */
    private function setProposal(BankTransaction $tx, array $set, int $searched): array
    {
        $names = implode(' + ', array_map(fn (array $c) => self::number($c['invoice']), $set));

        return [
            'method' => 'suggest.invoice_set', 'level' => 'set',
            'lines' => array_map(fn (array $c) => Lines::income($c['open'], $c['invoice']->id, $c['invoice']->work_job_id === null ? null : (int) $c['invoice']->work_job_id), $set),
            'reason' => $names.' add up to '.IncomeText::money($tx->amount_pence),
            'provenance' => new Provenance('suggest.invoice_set', self::evidence($set), $searched),
            'invoices' => array_map(fn (array $c) => $c + ['amount' => $c['open']], $set),
            'fee' => null,
        ];
    }

    /**
     * A fee or commission taken off: one invoice whose amount still to come is the payment plus the fee, else (with the
     * payer's sole client) a set of that client's invoices. Null when no fee is known, the payer looks like a payslip,
     * it isn't pounds into a pound account, or nothing (or more than one thing) fits.
     *
     * @param  list<array{invoice:Invoice, open:int}>  $pounds  every open pound candidate
     * @param  list<array{invoice:Invoice, open:int}>  $theirs  the payer's sole client's (the oldest 12)
     */
    private function net(BankTransaction $tx, array $pounds, array $theirs): ?array
    {
        if ($pounds === [] || ! self::inPounds($tx) || IncomeText::isPayslipLike($tx->description, $tx->counterparty, $tx->merchant_key)) {
            return null;
        }
        $fee = $this->fee($tx);
        if ($fee === null) {
            return null;
        }
        $fits = fn (int $gross) => $gross > $tx->amount_pence && self::feeFits($fee, $gross, $gross - $tx->amount_pence);

        $single = array_values(array_filter($pounds, fn (array $c) => $fits($c['open'])));
        if (count($single) === 1) {
            return $this->netProposal($tx, [$single[0]], $fee, count($pounds));
        }
        if (count($single) > 1 || $theirs === []) {
            return null;
        }
        $sets = self::combinations($theirs, $fits);

        return count($sets) === 1 ? $this->netProposal($tx, $sets[0], $fee, count($theirs)) : null;
    }

    /**
     * @param  list<array{invoice:Invoice, open:int}>  $set
     * @param  array{mode:string, fee?:int, rate?:int, category_id:?int, agree:int, evidence:list<string>}  $fee
     */
    private function netProposal(BankTransaction $tx, array $set, array $fee, int $tried): array
    {
        $gross = array_sum(array_column($set, 'open'));
        $taken = $gross - $tx->amount_pence;
        $jobs = array_values(array_unique(array_map(fn (array $c) => $c['invoice']->work_job_id === null ? null : (int) $c['invoice']->work_job_id, $set), SORT_REGULAR));
        $clients = array_values(array_unique(array_map(fn (array $c) => $this->clientOf[$c['invoice']->id] ?? null, $set), SORT_REGULAR));
        $job = count($jobs) === 1 ? $jobs[0] : null;
        $client = $job === null && count($clients) === 1 ? $clients[0] : null;

        $lines = array_map(fn (array $c) => Lines::income($c['open'], $c['invoice']->id, $c['invoice']->work_job_id === null ? null : (int) $c['invoice']->work_job_id), $set);
        $lines[] = Lines::deduction($taken, $fee['category_id'] ?? Lines::category($fee['mode'] === 'bank' ? 'finance' : 'other'), $job, $client);
        $names = implode(' + ', array_map(fn (array $c) => self::number($c['invoice']), $set));
        $how = $fee['mode'] === 'bank'
            ? 'the bank’s '.IncomeText::money($taken).' fee taken off'
            : IncomeText::money($taken).' taken off as a fee, like the last '.$fee['agree'].' payments from '.($tx->merchant_key ?: 'them');

        return [
            'method' => 'suggest.invoice_net', 'level' => 'net',
            'lines' => $lines,
            'reason' => IncomeText::money($gross).' for '.$names.' with '.$how,
            'provenance' => new Provenance('suggest.invoice_net', [...self::evidence($set), ...$fee['evidence']], $tried),
            'invoices' => array_map(fn (array $c) => $c + ['amount' => $c['open']], $set),
            'fee' => $taken,
        ];
    }

    /**
     * The fee this payment had taken off: the bank's own on a pound account, else the payer's usual.
     *
     * @return array{mode:string, fee?:int, rate?:int, category_id:?int, agree:int, evidence:list<string>}|null
     */
    private function fee(BankTransaction $tx): ?array
    {
        $bank = RevolutImport::poundFee($tx);
        if ($bank !== null && $bank > 0) {
            return ['mode' => 'bank', 'fee' => $bank, 'category_id' => Lines::category('finance'), 'agree' => 0, 'evidence' => []];
        }
        $key = (string) $tx->merchant_key;
        if (! array_key_exists($key, $this->usual)) {
            $this->usual[$key] = self::usual($key, (int) $tx->id);
        }

        return $this->usual[$key];
    }

    /**
     * The payer's usual fee: of its last 5 receipts you decided with a deduction, at least 2 agree on a fixed fee (within
     * 1p) or a rate (in basis points, within 10). The most recent one that agrees with another gives the fee or rate and
     * the deduction's category.
     *
     * @return array{mode:string, fee?:int, rate?:int, category_id:?int, agree:int, evidence:list<string>}|null
     */
    private static function usual(string $key, int $except): ?array
    {
        if ($key === '' || $key === 'UNKNOWN') {
            return null;
        }
        $receipts = BankTransaction::query()->where('merchant_key', $key)->where('amount_pence', '>', 0)->whereKeyNot($except)
            ->whereHas('allocations', fn ($q) => $q->where('kind', 'business_expense')->where('amount_pence', '<', 0)->where('decided_by', 'you'))
            ->with('allocations')->orderByDesc('posted_on')->orderByDesc('id')->limit(self::USUAL_OF)->get()
            ->map(function (BankTransaction $t) {
                $gross = (int) $t->allocations->where('kind', 'business_income')->where('amount_pence', '>', 0)->sum('amount_pence');
                $deductions = $t->allocations->filter(fn ($a) => Lines::isDeduction($a));
                $fee = -(int) $deductions->sum('amount_pence');

                return $gross > 0 && $fee > 0 ? ['id' => (int) $t->id, 'fee' => $fee, 'rate' => intdiv($fee * 10000 + intdiv($gross, 2), $gross),
                    'category_id' => $deductions->first()->category_id === null ? null : (int) $deductions->first()->category_id] : null;
            })->filter()->values();

        foreach ($receipts as $r) {
            $fixed = $receipts->filter(fn ($o) => abs($o['fee'] - $r['fee']) <= 1);
            if ($fixed->count() >= self::USUAL_AGREE) {
                return ['mode' => 'fixed', 'fee' => $r['fee'], 'category_id' => $r['category_id'], 'agree' => $fixed->count(), 'evidence' => $fixed->map(fn ($o) => 'tx:'.$o['id'])->values()->all()];
            }
            $rate = $receipts->filter(fn ($o) => abs($o['rate'] - $r['rate']) <= 10);
            if ($rate->count() >= self::USUAL_AGREE) {
                return ['mode' => 'rate', 'rate' => $r['rate'], 'category_id' => $r['category_id'], 'agree' => $rate->count(), 'evidence' => $rate->map(fn ($o) => 'tx:'.$o['id'])->values()->all()];
            }
        }

        return null;
    }

    /** Whether what was taken off a gross amount is the fee: the bank's exactly, a fixed fee within 1p, a rate's within 1p. */
    private static function feeFits(array $fee, int $gross, int $taken): bool
    {
        return match ($fee['mode']) {
            'bank' => $taken === $fee['fee'],
            'fixed' => abs($taken - $fee['fee']) <= 1,
            'rate' => abs($taken - intdiv($gross * $fee['rate'] + 5000, 10000)) <= 1,
            default => false,
        };
    }

    /** Pounds into a pound account: the only payments a fee shortcut is offered for. */
    private static function inPounds(BankTransaction $tx): bool
    {
        $currency = strtoupper((string) $tx->original_currency);
        $tx->loadMissing('account');

        return ($currency === '' || $currency === 'GBP') && strtoupper($tx->account?->currency ?: 'GBP') === 'GBP';
    }

    /**
     * The combinations of 2 to 4 of these (in the order given) whose open amounts sum to what $fits accepts: at most 781
     * sums for 12, stopping once a second is found (two fits are as good as none).
     *
     * @param  list<array{invoice:Invoice, open:int}>  $items
     * @param  callable(int): bool  $fits
     * @return list<list<array{invoice:Invoice, open:int}>>
     */
    private static function combinations(array $items, callable $fits): array
    {
        $items = array_slice($items, 0, self::SEARCH);
        $n = count($items);
        $found = [];
        $walk = function (int $from, array $picked, int $sum) use (&$walk, &$found, $items, $n, $fits): void {
            if (count($found) > 1) {
                return;
            }
            if (in_array(count($picked), self::SET_SIZES, true) && $fits($sum)) {
                $found[] = array_map(fn (int $i) => $items[$i], $picked);
            }
            if (count($picked) >= max(self::SET_SIZES)) {
                return;
            }
            for ($i = $from; $i < $n; $i++) {
                $walk($i + 1, [...$picked, $i], $sum + $items[$i]['open']);
            }
        };
        $walk(0, [], 0);

        return $found;
    }

    /** @param list<array{invoice:Invoice, open:int}> $set */
    private static function evidence(array $set): array
    {
        $refs = [];
        foreach ($set as $c) {
            $refs[] = 'inv:'.$c['invoice']->id;
            if ($c['invoice']->work_job_id) {
                $refs[] = 'job:'.$c['invoice']->work_job_id;
            }
        }

        return $refs;
    }

    private static function number(Invoice $invoice): string
    {
        return $invoice->number ?: 'invoice '.$invoice->id;
    }
}
