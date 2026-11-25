<?php

namespace App\Http\Controllers;

use App\Domain\Fx\ExchangeRates;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Ledger\ManualExpenses;
use App\Models\Allocation;
use App\Support\Money;
use App\Support\UkTaxYear;
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
    public function __construct(private ExchangeRates $fx, private ManualExpenses $expenses) {}

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
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'message' => "The exchange rate couldn't be looked up just now. Enter the rate yourself, or the sterling amount from the document."], 422);
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
        $label = UkTaxYear::fromDate($date)->label();
        if (ManualExpenses::locked($label)) {
            return back()->with('error', "Tax year {$label} is locked. Unlock it before adding to it.");
        }
        if (in_array($data['kind'], Allocation::NEEDS_CATEGORY, true) && empty($data['category_id'])) {
            return back()->with('error', 'Choose an HMRC category.');
        }
        $sign = $data['direction'] === 'out' ? -1 : 1;

        try {
            $minor = abs($this->minor($data['amount'], $currency)) * $sign;
            $sterling = ! empty($data['sterling_from_document']) && $currency !== 'GBP' ? Money::parse($data['sterling_from_document']) : null;
            // Converted before anything is written (it may ask HMRC for the month's rate).
            $converted = $this->expenses->convert($minor, $currency, $date, $sterling, ! empty($data['manual_rate']) ? (string) $data['manual_rate'] : null);
        } catch (RateUnavailable|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $this->expenses->create($data, $converted);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $pence = $converted['pence'];

        return back()->with('success', 'Added '.Money::format(abs($pence)).($currency !== 'GBP' ? " ({$currency} converted)" : '').'.');
    }

    /** An amount as typed, in the currency's own minor units (Money::parseIn: none for yen, three places for dinars). */
    private function minor(string $amount, string $currency): int
    {
        $minor = Money::parseIn($amount, strtoupper($currency));
        if ($minor === null || $minor === 0) {
            throw new \InvalidArgumentException('Enter an amount.');
        }

        return $minor;
    }
}
