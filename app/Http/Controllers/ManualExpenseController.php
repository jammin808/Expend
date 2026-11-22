<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Fx\ExchangeRates;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Ledger\DecisionService;
use App\Domain\Matching\MerchantKey;
use App\Models\Allocation;
use App\Models\ManualExpense;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cash spending, receipts paid abroad, and anything else that never went through an imported account.
 * Other currencies are converted with the document's own sterling figure if it has one, otherwise HMRC's monthly rate.
 */
class ManualExpenseController extends Controller
{
    public function __construct(private ExchangeRates $fx, private DecisionService $decisions) {}

    public function rate(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => 'required|string|size:3', 'date' => 'required|date', 'amount' => 'nullable|string']);
        try {
            $date = CarbonImmutable::parse($data['date']);
            $rate = $this->fx->rateFor($data['currency'], $date);
            $converted = null;
            if (! empty($data['amount'])) {
                $minor = $this->minor($data['amount'], $data['currency']);
                $converted = $this->fx->toPence($minor, $data['currency'], $date)['pence'];
            }

            return response()->json(['ok' => true, 'rate' => $rate, 'pence' => $converted]);
        } catch (RateUnavailable|\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'spent_on' => 'required|date',
            'description' => 'required|string|max:500',
            'direction' => 'required|in:out,in',
            'amount' => 'required|string',
            'currency' => 'required|string|size:3',
            'sterling_from_document' => 'nullable|string',
            'manual_rate' => 'nullable|numeric|min:0.000001',
            'paid_with' => 'required|in:cash,personal_card,other',
            'kind' => ['required', Rule::in(array_keys(Allocation::KINDS))],
            'category_id' => 'nullable|integer|exists:categories,id',
            'work_job_id' => 'nullable|integer|exists:work_jobs,id',
            'business_pct' => 'nullable|integer|min:1|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        $currency = strtoupper($data['currency']);
        $date = CarbonImmutable::parse($data['spent_on']);
        $sign = $data['direction'] === 'out' ? -1 : 1;

        try {
            $minor = abs($this->minor($data['amount'], $currency)) * $sign;
            $fx = ['original_amount_minor' => null, 'original_currency' => null, 'fx_units_per_gbp' => null, 'fx_rate_date' => null, 'fx_source' => null];

            if ($currency === 'GBP') {
                $pence = $minor;
            } elseif (! empty($data['sterling_from_document'])) {
                $pence = abs(Money::parse($data['sterling_from_document'])) * $sign;
                $fx = ['original_amount_minor' => $minor, 'original_currency' => $currency, 'fx_units_per_gbp' => round(abs($minor) / max(1, abs($pence)), 6), 'fx_rate_date' => $date->format('Y-m-d'), 'fx_source' => 'document'];
            } else {
                if (! empty($data['manual_rate'])) {
                    $this->fx->setManual($currency, $date, (string) $data['manual_rate']);
                }
                $converted = $this->fx->toPence($minor, $currency, $date);
                $pence = $converted['pence'];
                $fx = ['original_amount_minor' => $minor, 'original_currency' => $currency, 'fx_units_per_gbp' => $converted['units_per_gbp'], 'fx_rate_date' => $converted['rate_date'], 'fx_source' => $converted['source']];
            }
        } catch (RateUnavailable|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $expense = ManualExpense::query()->create([
            'spent_on' => $date->format('Y-m-d'),
            'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $data['description'],
            'merchant_key' => MerchantKey::from($data['description']),
            'paid_with' => $data['paid_with'],
            'amount_pence' => $pence,
            ...$fx,
        ]);
        AuditLog::record('manual_expense.created', $expense, null, $expense->toArray());

        $result = $this->decisions->apply($data, collect([$expense]));
        if ($result['errors']) {
            return back()->with('error', implode(' ', $result['errors']));
        }

        return back()->with('success', 'Added '.Money::format(abs($pence)).($currency !== 'GBP' ? " ({$currency} converted)" : '').'.');
    }

    private function minor(string $amount, string $currency): int
    {
        $parsed = Money::parse($amount);
        if ($parsed === null) {
            throw new \InvalidArgumentException('Enter an amount.');
        }
        $digits = Currency::of(strtoupper($currency))->getDefaultFractionDigits();

        // Money::parse gives hundredths; adjust for currencies with other minor units (for example JPY has none).
        return match ($digits) {
            2 => $parsed,
            0 => intdiv($parsed, 100),
            default => $parsed * (10 ** ($digits - 2)),
        };
    }
}
