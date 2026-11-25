<?php

namespace App\Domain\Income;

use App\Domain\Clients\ClientIds;
use App\Domain\Intel\Vetoes;
use App\Models\Allocation;
use App\Models\Client;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What you're shown about an income suggestion before you accept it: which invoices and how much of each, any fee or
 * commission taken off, and who it's from ("INV-041 £600 + INV-043 £750, £30 fee taken off · Acme Events";
 * "Turnover · Show B · Acme Events"). The to-do list (and so Telegram), the Inbox banner and the year page's groups all
 * use it, so an Accept button never accepts links you weren't shown. Any other suggestion reads as it always has.
 *
 * Also the one test of what an invoice suggestion is (isInvoiceSuggestion), and of payers whose words look like a
 * payslip (PAYSLIP_PATTERN), who are never offered a fee shortcut.
 */
final class IncomeText
{
    /**
     * Words of a payslip or of tax taken at source: such a payer is never offered "a fee was taken off" or a learned
     * deduction (tax or National Insurance taken off isn't a fee, and isn't recorded as one).
     */
    public const PAYSLIP_PATTERN = '/\b(?:PAYE|NICS?|NI|NATIONAL\s+INSURANCE|TAX|HMRC)\b/i';

    /** Shown wherever a deduction is offered. */
    public const DEDUCTION_NOTE = 'Only for a fee or commission. If they took off tax or National Insurance, don’t record it here: ask an accountant.';

    /** Whether any of these words (a payer's name, a line's description) look like a payslip or tax taken at source. */
    public static function isPayslipLike(?string ...$texts): bool
    {
        return preg_match(self::PAYSLIP_PATTERN, implode(' ', array_filter($texts, fn ($t) => $t !== null && $t !== ''))) === 1;
    }

    /**
     * Whether a payment's allocations are an invoice suggestion: an open automatic suggestion (Vetoes::isOpenSuggestion)
     * with at least one line naming an invoice, whatever its method (the matcher's, the assistant's, or an old
     * "Matches invoice" line). Nothing automatic writes over one; bulk decisions leave it for you.
     *
     * @param  Collection<int, Allocation>  $allocations
     */
    public static function isInvoiceSuggestion(Collection $allocations): bool
    {
        return Vetoes::isOpenSuggestion($allocations) && $allocations->contains(fn (Allocation $a) => $a->invoice_id !== null);
    }

    /** An amount the short way: "£600", "£1,350", "£0.50", "€1,200". */
    public static function money(int $minor, string $currency = 'GBP'): string
    {
        $text = Money::format(abs($minor), strtoupper($currency ?: 'GBP'), false); // pence, or an invoice's hundredths

        return preg_replace('/\.00$/', '', $text) ?? $text;
    }

    /**
     * A suggestion in words, line by line. Income linked to invoices: "INV-041 £600 + INV-043 £750, £30 fee taken off ·
     * Acme Events". Income for a job or a client: "Turnover · Show B · Acme Events". Anything else as it always read:
     * each line's kind, category and job ("Business expense: Travel · Show B").
     *
     * @param  Collection<int, Allocation>  $allocations
     * @param  array<int, array{0:?int, 1:?string}>|null  $clients  each line's client and its name, when a page has read them
     *                                                              for all its lines at once (clients())
     */
    public static function suggestion(Collection $allocations, ?array $clients = null): ?string
    {
        if ($allocations->isEmpty()) {
            return null;
        }
        self::load($allocations, ['category:id,name', 'job:id,title,client_id', 'invoice:id,number,currency,client_id,work_job_id']);
        $income = $allocations->where('kind', 'business_income')->values();
        $other = $allocations->reject(fn (Allocation $a) => $a->kind === 'business_income' || Lines::isDeduction($a));
        $linked = $income->contains(fn (Allocation $a) => $a->invoice_id !== null || $a->work_job_id !== null || $a->client_id !== null);
        if ($income->isEmpty() || $other->isNotEmpty() || ! $linked) {
            return self::plain($allocations);
        }

        $deducted = -(int) $allocations->filter(fn (Allocation $a) => Lines::isDeduction($a))->sum('amount_pence');
        $fee = $deducted > 0 ? ', '.self::money($deducted).' fee taken off' : '';
        $client = self::clientName($income, $clients);
        $withInvoice = $income->whereNotNull('invoice_id');
        if ($withInvoice->isNotEmpty()) {
            $parts = $income->map(fn (Allocation $a) => ($a->invoice?->number ?: ($a->invoice_id ? 'an invoice' : ($a->job?->title ?? 'no job'))).' '.self::money((int) $a->amount_pence));

            return $parts->implode(' + ').$fee.($client ? ' · '.$client : '');
        }

        $category = self::short($income->first()->category?->name) ?? 'Business income';
        $jobs = $income->filter(fn (Allocation $a) => $a->job !== null);
        $where = match (true) {
            $income->count() === 1 => $income->first()->job?->title,
            $jobs->isEmpty() => null,
            default => $income->map(fn (Allocation $a) => ($a->job?->title ?? 'no job').' '.self::money((int) $a->amount_pence))->implode(' + '),
        };

        return implode(' · ', array_filter([$category, $where])).$fee.($client ? ' · '.$client : '');
    }

    /**
     * A group of waiting payments on the year page, when every suggestion in it is income: "Business income · Acme
     * Events · 4 invoice matches". Null when anything else is suggested in the group.
     *
     * @param  Collection<int, Allocation>  $allocations  the group's suggested lines
     */
    public static function group(Collection $allocations): ?string
    {
        // Every payment's lines income, with at most a fee taken off beside it.
        $bySource = $allocations->groupBy(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id);
        if ($allocations->isEmpty() || $bySource->contains(fn (Collection $lines) => ! $lines->contains(fn (Allocation $a) => $a->kind === 'business_income' && $a->amount_pence > 0)
            || $lines->contains(fn (Allocation $a) => $a->kind !== 'business_income' && ! Lines::isDeduction($a)))) {
            return null;
        }
        self::load($allocations, ['job:id,title,client_id', 'invoice:id,number,currency,client_id,work_job_id']);
        $matches = $allocations->whereNotNull('invoice_id')->map(fn (Allocation $a) => $a->bank_transaction_id ? 'tx:'.$a->bank_transaction_id : 'exp:'.$a->manual_expense_id)->unique()->count();
        $client = self::clientName($allocations->where('kind', 'business_income'));

        return implode(' · ', array_filter([
            'Business income',
            $client,
            $matches > 0 ? $matches.' invoice '.($matches === 1 ? 'match' : 'matches') : null,
        ]));
    }

    /** Loads what the words need onto the lines themselves (whichever kind of collection they came in). */
    private static function load(Collection $allocations, array $relations): void
    {
        (new EloquentCollection($allocations->all()))->loadMissing($relations);
    }

    /** How a suggestion always read: each line's kind, its category and its job. */
    private static function plain(Collection $allocations): string
    {
        return $allocations->map(fn (Allocation $a) => (Allocation::KINDS[$a->kind] ?? $a->kind).($a->category ? ': '.$a->category->name : '').($a->job ? ' · '.$a->job->title : ''))
            ->unique()->implode(' + ');
    }

    /** The one client these lines are for (worked out, merges resolved), by name; null when none or several. */
    private static function clientName(Collection $lines, ?array $clients = null): ?string
    {
        $ids = $lines->map(fn (Allocation $a) => $clients !== null && array_key_exists($a->id, $clients) ? $clients[$a->id][0] : IncomeClient::of($a))->unique()->values();
        if ($ids->count() !== 1 || $ids->first() === null) {
            return null;
        }
        $known = $lines->first(fn (Allocation $a) => $clients !== null && isset($clients[$a->id][1]));

        return $known !== null ? $clients[$known->id][1] : Client::query()->whereKey($ids->first())->value('name');
    }

    /**
     * Each line's worked-out client (IncomeClient::of: its invoice's, else its job's, else its own; merges resolved) and
     * that client's name, for a page's lines all at once: a fixed number of queries however many lines.
     *
     * @param  Collection<int, Allocation>  $lines
     * @return array<int, array{0:?int, 1:?string}> by line id
     */
    public static function clients(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }
        self::load($lines, ['job:id,title,client_id', 'invoice:id,number,currency,client_id,work_job_id']);
        $raw = $lines->mapWithKeys(fn (Allocation $a) => [$a->id => $a->invoice?->client_id ?? $a->job?->client_id ?? $a->client_id]);
        $resolved = ClientIds::resolveMany($raw->filter()->values());
        $names = Client::query()->whereKey(array_values(array_unique($resolved)) ?: [0])->pluck('name', 'id');

        return $raw->map(function ($id) use ($resolved, $names) {
            $now = $id === null ? null : ($resolved[(int) $id] ?? (int) $id);

            return [$now, $now === null ? null : ($names[$now] ?? null)];
        })->all();
    }

    /** A category's name without its explanation: "Turnover (sales and fees)" is "Turnover". */
    private static function short(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return trim(preg_replace('/\s*\(.*\)\s*$/u', '', $name) ?? $name) ?: $name;
    }
}
