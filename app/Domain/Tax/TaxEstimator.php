<?php

namespace App\Domain\Tax;

use App\Models\Setting;
use App\Support\Money;
use Brick\Math\RoundingMode;

/**
 * A cross-check of income tax and National Insurance on self-employment profit only, using that year's rules.
 * HMRC's own calculation is the authority. Not available for Scottish taxpayers or years without rates.
 */
final class TaxEstimator
{
    /** A tax year has 52 contribution weeks, occasionally 53. */
    public const MAX_CLASS2_WEEKS = 53;

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

        // Compulsory Class 2 (to 2023-24): a weekly amount for each week of self-employment from age 16 to State
        // Pension age (SSCBA 1992 s11). For 2022-23 and 2023-24 it's payable only on profits over the lower profits
        // threshold, and treated as paid from the small profits threshold up to it; before that, on profits at or
        // over the small profits threshold. Nothing is compulsory from 2024-25.
        $class2 = 0;
        $c2 = $p['class2'] ?? null;
        $weeks = null;
        $class2Note = null;
        if ($c2) {
            $liable = ($c2['payable_when'] ?? 'above') === 'at_or_above' ? $profit >= $c2['payable_from'] : $profit > $c2['payable_from'];
            if ($liable) {
                $weeks = self::class2Weeks($label);
                $class2 = $c2['weekly'] * $weeks['weeks'];
                $class2Note = 'Class 2 is '.Money::format($c2['weekly']).' a week for each week you were self-employed and aged 16 to State Pension age: '
                    .($weeks['set']
                        ? $weeks['weeks'].' weeks, as set in Settings.'
                        : '52 weeks (a full year) is assumed. If you started, stopped or reached State Pension age during '.$label.', set the weeks in Settings.');
            } elseif ($profit > 0 && isset($c2['small_profits_threshold']) && $profit >= $c2['small_profits_threshold']) {
                $class2Note = 'No Class 2 to pay: profits aren’t over '.Money::format($c2['payable_from']).' (the lower profits threshold). From '
                    .Money::format($c2['small_profits_threshold']).' up to that, it’s treated as paid for your National Insurance record.';
            }
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
            // Weeks of Class 2 counted (null if none is payable) and whether you set them, rather than the 52 assumed.
            'class2_weeks' => $weeks['weeks'] ?? null,
            'class2_weeks_set' => $weeks['set'] ?? false,
            'total_pence' => $incomeTax + $class4 + $class2,
            'notes' => array_values(array_filter([
                'Covers self-employment profit only. Other income (employment, savings, dividends, pensions) isn\'t included.',
                $class2Note,
            ])),
        ];
    }

    /**
     * Weeks of compulsory Class 2 for the year: the class2_weeks setting (weeks for each tax year, or one number for
     * every year), or 52, a full year, if it isn't set.
     *
     * @return array{weeks:int, set:bool}
     */
    public static function class2Weeks(string $label): array
    {
        $setting = Setting::get('class2_weeks');
        $weeks = is_array($setting) ? ($setting[$label] ?? null) : $setting;
        if (is_numeric($weeks) && (int) $weeks == $weeks && $weeks >= 0 && $weeks <= self::MAX_CLASS2_WEEKS) {
            return ['weeks' => (int) $weeks, 'set' => true];
        }

        return ['weeks' => 52, 'set' => false];
    }

    /** The tax years with compulsory Class 2 (each can have its own weeks). @return list<string> */
    public static function class2Years(): array
    {
        return array_keys(array_filter((array) config('tax.years'), fn ($p) => ! empty($p['class2'])));
    }
}
