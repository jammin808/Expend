<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;

/** How much of a year is decided, by value and by count. */
final class YearProgress
{
    public static function for(string $label): array
    {
        $rows = BankTransaction::query()->where('tax_year', $label)
            ->selectRaw('review_status, COUNT(*) as n, SUM(ABS(amount_pence)) as value')
            ->groupBy('review_status')->get()->keyBy('review_status');

        $total = (int) $rows->sum('value');
        $confirmed = (int) ($rows['confirmed']->value ?? 0);
        $count = (int) $rows->sum('n');

        return [
            'total_count' => $count,
            'confirmed_count' => (int) ($rows['confirmed']->n ?? 0),
            'suggested_count' => (int) ($rows['suggested']->n ?? 0),
            'unreviewed_count' => (int) ($rows['unreviewed']->n ?? 0),
            'value_pct' => $total > 0 ? (int) floor($confirmed * 100 / $total) : 0,
        ];
    }
}
