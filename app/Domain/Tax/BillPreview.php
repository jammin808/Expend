<?php

namespace App\Domain\Tax;

use App\Models\TaxYear;
use App\Support\UkTaxYear;

/**
 * What HMRC is likely to ask for: the year's balancing payment, plus payments on account towards the next year.
 */
final class BillPreview
{
    public static function for(string $label, ?array $estimate): ?array
    {
        if (! $estimate) {
            return null;
        }

        $p = TaxParameters::for($label);
        $year = UkTaxYear::fromLabel($label);
        $record = TaxYear::query()->where('label', $label)->first();

        // Payments on account cover income tax and Class 4, not Class 2.
        $liability = $estimate['income_tax']['total_pence'] + $estimate['class4_pence'];
        $alreadyPaid = $record?->payments_on_account_paid_pence ?? 0;
        $balancing = $liability + $estimate['class2_pence'] - $alreadyPaid;
        $poa = $liability > ($p['payments_on_account_threshold'] ?? 100_000) ? intdiv($liability, 2) : 0;

        $deadline = $year->filingDeadline();

        return [
            'liability_pence' => $liability + $estimate['class2_pence'],
            'already_paid_pence' => $alreadyPaid,
            'balancing_pence' => $balancing,
            'payment_on_account_pence' => $poa,
            'due' => [
                ['date' => $deadline->format('Y-m-d'), 'label' => "Balance for {$label}".($poa ? ' plus 1st payment on account for '.$year->next()->label() : ''), 'pence' => $balancing + $poa],
                ...($poa ? [['date' => $deadline->addMonths(6)->format('Y-m-d'), 'label' => '2nd payment on account for '.$year->next()->label(), 'pence' => $poa]] : []),
            ],
        ];
    }
}
