<?php

namespace App\Domain\Tax;

use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;

/**
 * The figures for one tax year's self-employment pages, from confirmed decisions only.
 * Unconfirmed amounts are reported separately as "pending" and never counted.
 */
final class ReturnSummary
{
    public static function for(string $label): array
    {
        $categories = Category::query()->with('parent')->get()->keyBy('id');
        $confirmed = Allocation::query()->where('tax_year', $label)->whereNotNull('confirmed_at')->get();

        $turnover = 0;
        $otherIncome = 0;
        $lines = [];

        foreach ($confirmed as $a) {
            if ($a->kind === 'business_income') {
                $code = $categories[$a->category_id]?->code;
                $code === 'other_income' ? $otherIncome += $a->amount_pence : $turnover += $a->amount_pence;
            } elseif ($a->kind === 'business_expense') {
                $category = $categories[$a->category_id];
                $top = $category->parent ?? $category;
                $line = &$lines[$top->code];
                $line ??= ['code' => $top->code, 'name' => $top->name, 'box' => $top->hmrc_box, 'allowable_pence' => 0, 'disallowable_pence' => 0];
                // Spending is negative; an expense is the positive cost. Refunds net off.
                $cost = -$a->amount_pence;
                $category->is_allowable ? $line['allowable_pence'] += $cost : $line['disallowable_pence'] += $cost;
                unset($line);
            }
        }

        $mileage = MileageCalculator::forYear($label);
        if ($mileage['total_pence'] > 0) {
            $travel = Category::query()->where('code', 'travel')->first();
            $lines['travel'] ??= ['code' => 'travel', 'name' => $travel->name, 'box' => $travel->hmrc_box, 'allowable_pence' => 0, 'disallowable_pence' => 0];
            $lines['travel']['allowable_pence'] += $mileage['total_pence'];
            $lines['travel']['mileage_pence'] = $mileage['total_pence'];
        }

        $order = Category::query()->whereNull('parent_id')->where('kind', 'expense')->orderBy('sort')->pluck('code')->all();
        usort($lines, fn ($a, $b) => array_search($a['code'], $order) <=> array_search($b['code'], $order));
        $lines = array_values($lines);

        $allowable = array_sum(array_column($lines, 'allowable_pence'));
        $disallowable = array_sum(array_column($lines, 'disallowable_pence'));
        $income = $turnover + $otherIncome;
        $params = TaxParameters::for($label);
        $tradingAllowance = $params['trading_allowance'] ?? 100_000;

        $unreviewed = BankTransaction::query()->where('tax_year', $label)->where('review_status', 'unreviewed');
        $suggested = BankTransaction::query()->where('tax_year', $label)->where('review_status', 'suggested');

        return [
            'tax_year' => $label,
            'turnover_pence' => $turnover,
            'other_income_pence' => $otherIncome,
            'expense_lines' => $lines,
            'allowable_expenses_pence' => $allowable,
            'disallowable_expenses_pence' => $disallowable,
            'mileage_pence' => $mileage['total_pence'],
            'net_profit_pence' => $income - $allowable,
            'trading_allowance' => [
                'allowance_pence' => $tradingAllowance,
                'profit_with_allowance_pence' => max(0, $income - $tradingAllowance),
                'better' => $allowable < $tradingAllowance && $income > 0,
            ],
            'short_form_allowed' => $turnover < ($params['vat_threshold'] ?? 9_000_000),
            'pending' => [
                'unreviewed_count' => (clone $unreviewed)->count(),
                'unreviewed_pence' => (int) (clone $unreviewed)->sum('amount_pence'),
                'suggested_count' => (clone $suggested)->count(),
                'manual_unreviewed_count' => ManualExpense::query()->where('tax_year', $label)->where('review_status', '!=', 'confirmed')->count(),
            ],
        ];
    }
}
