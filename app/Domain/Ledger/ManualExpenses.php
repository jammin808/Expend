<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLog;
use App\Domain\Fx\ExchangeRates;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Matching\MerchantKey;
use App\Models\Allocation;
use App\Models\ManualExpense;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Spending (or income) that never went through an imported account: cash, a personal card, a receipt paid abroad. The
 * one way such an item is added, corrected or taken back, whether from the Transactions page (ManualExpenseController)
 * or the phone (Quick\QuickEntries).
 *
 *  - Another currency is converted before any transaction (convert()): with the sterling figure on the document when
 *    there is one, otherwise HMRC's monthly rate (which may mean a request to HMRC). Any failure is RateUnavailable,
 *    so the caller can keep what it has and ask for a rate, and no lock is ever held across a request.
 *  - What it is goes through DecisionService (and so AllocationWriter), as your decision.
 *  - A locked year refuses every write; a correction checks the year it's in and the year it would move to.
 *  - Every change is audited: manual_expense.created, .corrected and .withdrawn, with the before and after.
 */
final class ManualExpenses
{
    public function __construct(private ExchangeRates $fx, private DecisionService $decisions, private AllocationWriter $writer) {}

    /**
     * The sterling amount of an amount in another currency, and the columns that say how it was converted. $minor is
     * signed, in the currency's own minor units; the pence come back with the same sign.
     *
     * @param  int|null  $sterlingPence  the sterling figure printed on the document (fx_source document)
     * @param  string|null  $manualRate  your own rate (units per £1), kept for the month as today
     * @return array{pence:int, fx:array{original_amount_minor:?int, original_currency:?string, fx_units_per_gbp:?string, fx_rate_date:?string, fx_source:?string}}
     *
     * @throws RateUnavailable whatever went wrong (no rate, HMRC couldn't be reached, an unknown currency)
     */
    public function convert(int $minor, string $currency, CarbonImmutable $date, ?int $sterlingPence = null, ?string $manualRate = null): array
    {
        $currency = strtoupper(trim($currency));
        $none = ['original_amount_minor' => null, 'original_currency' => null, 'fx_units_per_gbp' => null, 'fx_rate_date' => null, 'fx_source' => null];
        if ($currency === 'GBP') {
            return ['pence' => $minor, 'fx' => $none];
        }
        $sign = $minor < 0 ? -1 : 1;
        try {
            if ($sterlingPence !== null && $sterlingPence !== 0) {
                $pence = abs($sterlingPence) * $sign;
                $original = \Brick\Money\Money::ofMinor(abs($minor), Currency::of($currency))->getAmount();
                $rate = $original->dividedBy(BigDecimal::ofUnscaledValue(max(1, abs($pence)), 2), 6, RoundingMode::HALF_UP);

                return ['pence' => $pence, 'fx' => ['original_amount_minor' => $minor, 'original_currency' => $currency, 'fx_units_per_gbp' => (string) $rate,
                    'fx_rate_date' => $date->format('Y-m-d'), 'fx_source' => 'document']];
            }
            if ($manualRate !== null && trim($manualRate) !== '') {
                $this->fx->setManual($currency, $date, trim($manualRate));
            }
            $converted = $this->fx->toPence($minor, $currency, $date);

            return ['pence' => $converted['pence'], 'fx' => ['original_amount_minor' => $minor, 'original_currency' => $currency, 'fx_units_per_gbp' => $converted['units_per_gbp'],
                'fx_rate_date' => $converted['rate_date'], 'fx_source' => $converted['source']]];
        } catch (RateUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new RateUnavailable("The exchange rate for {$currency} couldn’t be looked up just now. Enter the rate yourself, or the sterling amount from the document.", 0, $e);
        }
    }

    /**
     * Add it, and record what it is as your decision.
     *
     * @param  array{spent_on:string|\DateTimeInterface, description:string, paid_with:string, kind:string, category_id?:?int, work_job_id?:?int, business_pct?:?int, note?:?string, reason?:?string}  $data
     * @param  array{pence:int, fx:array}  $converted  convert()'s (already signed: money out is negative)
     *
     * @throws InvalidArgumentException a locked year, a missing category, or a decision the writer refuses
     */
    public function create(array $data, array $converted): ManualExpense
    {
        $date = CarbonImmutable::parse($data['spent_on'] instanceof \DateTimeInterface ? $data['spent_on']->format('Y-m-d') : $data['spent_on']);
        $label = UkTaxYear::fromDate($date)->label();
        if (self::locked($label)) {
            throw new InvalidArgumentException("Tax year {$label} is locked. Unlock it before adding to it.");
        }
        if (in_array($data['kind'], Allocation::NEEDS_CATEGORY, true) && empty($data['category_id'])) {
            throw new InvalidArgumentException('Choose an HMRC category.');
        }
        if ((int) $converted['pence'] === 0) {
            throw new InvalidArgumentException('Enter an amount.');
        }

        return DB::transaction(function () use ($data, $date, $label, $converted) {
            $expense = ManualExpense::query()->create([
                'spent_on' => $date->format('Y-m-d'),
                'tax_year' => $label,
                'description' => mb_substr((string) $data['description'], 0, 500),
                'merchant_key' => MerchantKey::from((string) $data['description']),
                'paid_with' => $data['paid_with'],
                'amount_pence' => (int) $converted['pence'],
                ...$converted['fx'],
            ]);
            AuditLog::record('manual_expense.created', $expense, null, $expense->toArray(), $data['reason'] ?? null);

            $result = $this->decisions->apply($data, collect([$expense]));
            if ($result['errors']) {
                throw new InvalidArgumentException(implode(' ', $result['errors']));
            }

            return $expense;
        });
    }

    /**
     * Correct what was added: its amount (in its own currency), currency, sterling figure, date or description. The
     * year it's in and the year it would move to must both be open, checked before anything is written. A change of
     * amount, currency or date works the sterling out again (convert(), before the transaction), and writes its one line
     * again as your decision with the same category, job and business share. One split into several lines of its own
     * is changed in the app instead.
     *
     * @param  array{amount_minor?:int, currency?:string, sterling_pence?:?int, spent_on?:string, description?:string}  $changes
     * @param  string  $actor  'you' for your answer; 'system' when only the description is filled in from the photo's read
     *
     * @throws InvalidArgumentException a locked year, or a split that can't be changed here
     * @throws RateUnavailable no rate for the new currency or date
     */
    public function correct(ManualExpense $expense, array $changes, string $actor = 'you', ?string $reason = null): ManualExpense
    {
        $expense->refresh();
        $from = CarbonImmutable::parse($expense->spent_on->format('Y-m-d'));
        $to = isset($changes['spent_on']) ? CarbonImmutable::parse($changes['spent_on']) : $from;
        foreach (array_unique([UkTaxYear::fromDate($from)->label(), UkTaxYear::fromDate($to)->label()]) as $label) {
            if (self::locked($label)) {
                throw new InvalidArgumentException("Tax year {$label} is locked. Unlock it (with a reason) before changing anything in it.");
            }
        }
        $sign = $expense->amount_pence < 0 ? -1 : 1;
        $was = strtoupper((string) ($expense->original_currency ?? 'GBP'));
        $wasMinor = abs((int) ($expense->original_currency !== null ? $expense->original_amount_minor : $expense->amount_pence));
        $currency = strtoupper((string) ($changes['currency'] ?? $was));
        // "It was €15.00" on £15.00: the same figure, in the other currency's own units.
        $minor = (isset($changes['amount_minor']) ? abs((int) $changes['amount_minor']) : (int) Money::fromHundredths((int) Money::toHundredths($wasMinor, $was), $currency)) * $sign;
        $money = isset($changes['amount_minor']) || isset($changes['currency']) || isset($changes['spent_on']) || array_key_exists('sterling_pence', $changes);
        $converted = null;
        if ($money) {
            // The sterling figure printed on the document still holds while the amount and currency do.
            $sterling = array_key_exists('sterling_pence', $changes) ? $changes['sterling_pence']
                : ($expense->fx_source === 'document' && $currency === $was && ! isset($changes['amount_minor']) ? abs((int) $expense->amount_pence) : null);
            $converted = $this->convert($minor, $currency, $to, $sterling);
        }

        $allocations = $expense->allocations()->get();
        $shape = self::singleShape($allocations, (int) $expense->amount_pence);
        $yearMoves = UkTaxYear::fromDate($from)->label() !== UkTaxYear::fromDate($to)->label();
        $rewrite = ($converted !== null && $converted['pence'] !== (int) $expense->amount_pence) || $yearMoves;
        if ($rewrite && $allocations->isNotEmpty() && $shape === null) {
            throw new InvalidArgumentException('It’s split into parts of your own: change it in the app.');
        }

        return DB::transaction(function () use ($expense, $changes, $converted, $to, $shape, $actor, $reason, $rewrite, $yearMoves) {
            $locked = ManualExpense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $before = $locked->only(['spent_on', 'tax_year', 'description', 'merchant_key', 'amount_pence', 'original_amount_minor', 'original_currency', 'fx_units_per_gbp', 'fx_rate_date', 'fx_source']);
            $before['spent_on'] = $locked->spent_on?->format('Y-m-d');
            $fill = [];
            if (isset($changes['description']) && trim((string) $changes['description']) !== '') {
                $fill['description'] = mb_substr(trim((string) $changes['description']), 0, 500);
                $fill['merchant_key'] = MerchantKey::from($fill['description']);
            }
            if (isset($changes['spent_on'])) {
                $fill['spent_on'] = $to->format('Y-m-d');
                $fill['tax_year'] = UkTaxYear::fromDate($to)->label();
            }
            if ($converted !== null) {
                $fill['amount_pence'] = $converted['pence'];
                $fill += $converted['fx'];
            }
            $locked->forceFill($fill);
            if (! $locked->isDirty()) {
                return $locked;
            }
            $locked->save();
            if ($rewrite && $shape !== null) {
                if ($yearMoves) {
                    // The same lines in another year: cleared first, so they're written again in the year it moved to.
                    $this->writer->clear($locked, $reason, $actor);
                }
                $this->writer->decide($locked, AllocationWriter::lines((int) $locked->amount_pence, $shape['kind'], $shape['category_id'], $shape['work_job_id'],
                    $shape['business_pct'], $shape['note']), 'you', true, $reason);
            }
            $after = $locked->only(array_keys($before));
            $after['spent_on'] = $locked->spent_on?->format('Y-m-d');
            AuditLog::record('manual_expense.corrected', $locked, $before, $after, $reason, $actor);

            return $locked;
        });
    }

    /**
     * Take it back (Undo on the phone, "Same receipt: remove the later one", "It was the card"): its lines are cleared
     * through the writer, then it's deleted, audited as manual_expense.withdrawn with everything it was. Refused in a
     * locked year.
     *
     * @throws InvalidArgumentException a locked year
     */
    public function withdraw(ManualExpense $expense, ?string $reason = null): void
    {
        $label = UkTaxYear::fromDate($expense->spent_on)->label();
        if (self::locked($label)) {
            throw new InvalidArgumentException("Kept: {$label} is locked. Unlock it (with a reason) before removing anything from it.");
        }
        DB::transaction(function () use ($expense, $reason) {
            $locked = ManualExpense::query()->whereKey($expense->id)->lockForUpdate()->first();
            if (! $locked) {
                return; // already gone
            }
            $lines = $locked->allocations()->get()->map(fn (Allocation $a) => $a->only(['kind', 'category_id', 'work_job_id', 'amount_pence', 'business_use_pct', 'decided_by', 'is_manual', 'note'])
                + ['confirmed' => $a->confirmed_at !== null])->all();
            if ($lines !== []) {
                $this->writer->clear($locked, $reason);
            }
            $before = $locked->toArray() + ['lines' => $lines];
            $locked->delete();
            AuditLog::record('manual_expense.withdrawn', 'ManualExpense', $before, null, $reason, subjectId: $expense->id);
        });
    }

    /** Whether a tax year is locked. */
    public static function locked(string $label): bool
    {
        return TaxYear::query()->where('label', $label)->whereNotNull('locked_at')->exists();
    }

    /**
     * What a single decision says (kind, category, job, business share), when its lines are exactly what
     * AllocationWriter::lines() makes of it; null when they're split some other way (or there are none).
     *
     * @param  Collection<int, Allocation>  $allocations
     * @return array{kind:string, category_id:?int, work_job_id:?int, business_pct:int, note:?string}|null
     */
    public static function singleShape($allocations, int $amount): ?array
    {
        $first = $allocations->first(fn (Allocation $a) => $a->kind !== 'personal') ?? $allocations->first();
        if (! $first) {
            return null;
        }
        $shape = ['kind' => $first->kind, 'category_id' => $first->category_id === null ? null : (int) $first->category_id, 'work_job_id' => $first->work_job_id === null ? null : (int) $first->work_job_id,
            'business_pct' => (int) ($first->business_use_pct ?? 100), 'note' => $first->note];
        $want = collect(AllocationWriter::lines($amount, $shape['kind'], $shape['category_id'], $shape['work_job_id'], $shape['business_pct'], $shape['note']))
            ->map(fn ($l) => [$l['kind'], $l['category_id'] === null ? null : (int) $l['category_id'], (int) $l['amount_pence']])->sort()->values()->all();
        $have = $allocations->map(fn (Allocation $a) => [$a->kind, $a->category_id === null ? null : (int) $a->category_id, (int) $a->amount_pence])->sort()->values()->all();

        return $want === $have ? $shape : null;
    }
}
