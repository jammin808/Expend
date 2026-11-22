<?php

namespace App\Domain\Tax;

use App\Models\Setting;
use Brick\Math\RoundingMode;

/**
 * A cross-check of income tax and National Insurance on self-employment profit only, using that year's rules.
 * HMRC's own calculation is the authority. Not available for Scottish taxpayers or years without rates.
 */
final class TaxEstimator
{
    public static function estimate(string $label, int $profitPence): ?array
    {
        $p = TaxParameters::for($label);
        if (! $p || Setting::get('scottish_taxpayer')) {
            return null;
        }

        $profit = max(0, $profitPence);

        // Personal allowance tapers by £1 for every £2 over £100,000.
        $allowance = $p['personal_allowance'];
        if ($profit > $p['personal_allowance_taper_from']) {
            $allowance = max(0, $allowance - intdiv($profit - $p['personal_allowance_taper_from'], 2));
        }

        $taxable = max(0, $profit - $allowance);
        $basicBand = min($taxable, $p['basic_rate_limit']);
        $higherBand = max(0, min($taxable, $p['additional_rate_threshold']) - $p['basic_rate_limit']);
        $additionalBand = max(0, $taxable - $p['additional_rate_threshold']);

        $basic = Percent::of($basicBand, $p['rates']['basic'], RoundingMode::DOWN);
        $higher = Percent::of($higherBand, $p['rates']['higher'], RoundingMode::DOWN);
        $additional = Percent::of($additionalBand, $p['rates']['additional'], RoundingMode::DOWN);
        $incomeTax = $basic + $higher + $additional;

        $c4 = $p['class4'];
        $c4MainBand = max(0, min($profit, $c4['upper']) - $c4['lower']);
        $c4UpperBand = max(0, $profit - $c4['upper']);
        $class4 = Percent::of($c4MainBand, $c4['main_rate'], RoundingMode::DOWN) + Percent::of($c4UpperBand, $c4['upper_rate'], RoundingMode::DOWN);

        $class2 = 0;
        if ($p['class2'] && $profit >= $p['class2']['payable_from']) {
            $class2 = $p['class2']['weekly'] * 52;
        }

        return [
            'profit_pence' => $profit,
            'personal_allowance_pence' => $allowance,
            'taxable_pence' => $taxable,
            'income_tax' => [
                'basic_pence' => $basic, 'higher_pence' => $higher, 'additional_pence' => $additional, 'total_pence' => $incomeTax,
            ],
            'class4_pence' => $class4,
            'class2_pence' => $class2,
            'total_pence' => $incomeTax + $class4 + $class2,
            'notes' => array_values(array_filter([
                'Covers self-employment profit only. Other income (employment, savings, dividends, pensions) isn\'t included.',
                $class2 ? 'Class 2 is estimated at 52 weeks.' : null,
            ])),
        ];
    }
}
