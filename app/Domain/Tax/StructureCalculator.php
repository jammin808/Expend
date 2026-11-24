<?php

namespace App\Domain\Tax;

use App\Models\Setting;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * A side-by-side ESTIMATE, for one tax year and one level of business profit (before anything the owner pays
 * themselves), of what the owner would take home and pay in tax as:
 *
 *  (a) a sole trader: income tax and Class 4 on the whole profit, as TaxEstimator works them out (voluntary Class 2
 *      only when asked);
 *  (b) a limited company with the owner as its only director and only employee: a salary chosen to leave the least
 *      tax, employer NIC with no Employment Allowance (a sole-director company can't claim it), employee NIC,
 *      corporation tax at the small profits rate, with marginal relief, or at the main rate, dividends of what's left
 *      (or part of it), the dividend allowance and rates stacked on the salary, and the company's running costs;
 *  (c) a partnership with a spouse or partner: each partner's income tax and Class 4 on their own share, with no
 *      trading allowance (partners can't use it);
 *  (d) with a pension: a personal contribution under relief at source for the sole trader and partner (basic and
 *      higher rate limits extended), an employer contribution for the company (deducted before corporation tax, no NIC);
 *
 * and the break-even: every band of profit, to the nearest £500, where the company's take-home beats the sole trader's
 * after running costs (the search is kept in the app's cache, as it doesn't depend on the profit). Every rate comes from the verified research for that year (Planning::rates); 2027-28 is marked
 * where its figures are announced but not yet law. Amounts are worked out exactly (no rounding on the way) and come
 * out in whole pence, rounded down. Each result lists its assumptions, its sources and the warnings that apply. It is
 * not advice: an accountant should check before anyone incorporates.
 */
final class StructureCalculator
{
    public const SALARY_OPTIONS = ['best', 'none', 'secondary_threshold', 'personal_allowance', 'custom'];

    /** The salaries tried for "best", in order of preference when two leave the same tax (a higher salary counts for the State Pension). */
    public const SALARY_CANDIDATES = ['personal_allowance', 'secondary_threshold', 'none'];

    /**
     * Accountancy and software for a company. The research found no official figure (planning: company-running-costs,
     * "Accountancy and software costs: null"), so these are Expend's own placeholders, labelled as such: use a quote.
     */
    public const DEFAULT_ACCOUNTANT_PENCE = 120_000;

    public const DEFAULT_SOFTWARE_PENCE = 12_000;

    /**
     * Companies House online confirmation statement fee in force for most of each tax year (planning:
     * company-running-costs): £34 from 1 May 2024 to 31 January 2026, £50 from 1 February 2026. No change is announced
     * for 2027-28, so the latest known fee is used.
     */
    public const CONFIRMATION_STATEMENT_PENCE = ['2024-25' => 3_400, '2025-26' => 3_400, '2026-27' => 5_000, '2027-28' => 5_000];

    public const BREAK_EVEN_STEP_PENCE = 50_000;

    public const BREAK_EVEN_MAX_PENCE = 30_000_000;

    /** Voluntary Class 2 is weekly; a full year is taken as 52 weeks. */
    public const CLASS2_WEEKS = 52;

    /** The small profits threshold for 2027-28 is announced (planning: sole-trader, £7,355); earlier years are in config/tax.php. */
    public const SMALL_PROFITS_THRESHOLD_2027_28_PENCE = 735_500;

    /** Gross personal pension contributions get relief up to this even with no earnings (FA 2004 s190(4)). */
    public const PENSION_BASIC_AMOUNT_PENCE = 360_000;

    /** What is announced but not yet law for 2027-28 (planning notes: "2027-28_announced rates status"). */
    public const ANNOUNCED_2027_28 = [
        'The income tax rates of 20%, 40% and 45% (announced in the Budget of 28 October 2026, for the Finance Bill 2026-27).',
        'The Class 1 National Insurance thresholds: the primary and secondary thresholds and the upper earnings limit (announced, for regulations in early 2027).',
        'Voluntary Class 2 at £3.80 a week and the small profits threshold of £7,355 (announced, for regulations in early 2027).',
    ];

    public const LAW_2027_28 = 'Already law for 2027-28: the £12,570 personal allowance, the £37,700 basic rate band, the £100,000 taper, the £125,140 higher rate limit, Class 4 (6% and 2%), the dividend allowance and rates, corporation tax for the year from 1 April 2027, the 15% employer rate and the £60,000 pension annual allowance.';

    /** Figures for 2027-28 the research marks as disputed, with the view it prefers (planning notes: "Disputed: s455 / dividend upper rate for 2027-28"). */
    public const DISPUTED_2027_28 = 'Disputed: the 35.75% dividend upper rate for 2027-28 is taken as law, because FA 2026 s4 sets it for 2026-27 and later years, though one check in the research said it isn’t yet set by law.';

    /**
     * The Class 1 lower earnings limit for a director's annual earnings period (52 x the weekly limit): a salary at or
     * above it earns a State Pension qualifying year (planning: class1-employee-nic, director-salary-at-nic-thresholds).
     * 2027-28 is announced (£133 a week), not yet law.
     */
    public const LOWER_EARNINGS_LIMIT_PENCE = ['2024-25' => 639_600, '2025-26' => 650_000, '2026-27' => 670_800, '2027-28' => 691_600];

    /**
     * The minimum income for Tax-Free Childcare and the 30 hours for someone aged 21 or over, over a year if paid evenly:
     * 16 hours x 52 weeks x the National Minimum Wage. Dividends don't count, only salary (planning: tax-free-childcare,
     * free-childcare-30-hours; calculator_notes "Tax-Free Childcare" (c)). 2027-28 uses the announced April 2027 rate.
     */
    public const CHILDCARE_MINIMUM_PENCE = ['2024-25' => 951_808, '2025-26' => 1_015_872, '2026-27' => 1_057_472, '2027-28' => 1_099_072];

    /** Bump when the break-even search changes, so results kept from before aren't used. */
    private const BREAK_EVEN_VERSION = 2;

    /** Sources, by planning item id, for every comparison. */
    private const SOURCES = [
        'sole-trader', 'limited-company-psc', 'general-partnership', 'company-vs-sole-extraction', 'incorporation-rule-of-thumb',
        'director-salary-at-nic-thresholds', 'employment-allowance-single-director', 'class1-employer-nic', 'class1-employee-nic',
        'ct-marginal-relief-formula', 'dividend-tax-rates', 'income-tax-bands-taper', 'class4-class2-self-employed', 'company-running-costs',
        'spouse-partnership', 'settlements-spouse-shares', 'ir35-for-incorporated-events-tech', 'ir35-deemed-payment', 'agency-rules-and-umbrellas',
        'caa-s266-election', 'incorporation-relief-claim',
    ];

    private const PENSION_SOURCES = ['pension-relief-at-source-self-employed', 'employer-pension-contributions', 'pension-annual-allowance'];

    /**
     * @param  array{salary?:string, salary_pence?:int, retain_pence?:int, accountant_pence?:?int, software_pence?:?int,
     *     confirmation_statement_pence?:?int, partner_share_pct?:int|float|string, partner_other_income_pence?:int,
     *     partner_other_self_employed?:bool, pension_pence?:int, voluntary_class2?:bool, break_even?:bool}  $options
     */
    public static function compare(string $year, int $profitPence, array $options = []): array
    {
        $r = self::rates($year);
        $o = self::options($year, $options);
        $profit = BigDecimal::of(max(0, $profitPence));
        $scottish = (bool) Setting::get('scottish_taxpayer');

        $sole = self::soleTrader($year, $r, $profit, $o, ! $scottish);
        $company = self::company($r, $profit, $o, $o['retain'], 0);
        $partnership = self::partnership($r, $profit, $o, 0);

        $out = [
            'tax_year' => $year,
            'estimate' => true,
            'label' => 'Estimate',
            'profit_pence' => self::pence($profit),
            'law' => self::law($year),
            'options' => self::echoOptions($o),
            'sole_trader' => $sole,
            'company' => self::presentCompany($company, $o) + ['difference_pence' => $company['take_home_pence'] - $sole['take_home_pence']],
            'partnership' => $partnership + ['difference_pence' => $partnership['take_home_pence'] - $sole['take_home_pence']],
        ];

        $out['sole_trader']['pension'] = null;
        $out['company']['pension'] = null;
        $out['partnership']['pension'] = null;
        if ($o['pension'] > 0) {
            $solePension = self::soleTraderPension($r, $profit, $o, $sole);
            $companyPension = self::company($r, $profit, $o, $o['retain'], $o['pension']);
            $partnershipPension = self::partnership($r, $profit, $o, $o['pension']);
            $out['sole_trader']['pension'] = $solePension;
            $out['company']['pension'] = self::presentCompany($companyPension, $o) + [
                'gross_pence' => $companyPension['pension_pence'],
                'capped' => $companyPension['pension_capped'],
                'cost_to_you_pence' => $company['take_home_pence'] - $companyPension['take_home_pence'],
                'difference_pence' => $companyPension['take_home_pence'] - $solePension['take_home_pence'],
            ];
            $out['partnership']['pension'] = [
                'gross_pence' => $partnershipPension['pension_pence'],
                'you_pay_pence' => $partnershipPension['pension_net_pence'],
                'added_by_provider_pence' => $partnershipPension['pension_pence'] - $partnershipPension['pension_net_pence'],
                'extra_relief_pence' => $partnership['you']['income_tax_pence'] - $partnershipPension['you']['income_tax_pence'],
                'capped' => $partnershipPension['pension_capped'],
                'you' => $partnershipPension['you'], 'partner' => $partnershipPension['partner'],
                'total_tax_pence' => $partnershipPension['total_tax_pence'], 'take_home_pence' => $partnershipPension['take_home_pence'],
                'cost_to_you_pence' => $partnership['take_home_pence'] - $partnershipPension['take_home_pence'],
                'difference_pence' => $partnershipPension['take_home_pence'] - $solePension['take_home_pence'],
            ];
        }
        unset($out['partnership']['pension_pence'], $out['partnership']['pension_net_pence'], $out['partnership']['pension_capped']);

        $out['break_even'] = ($options['break_even'] ?? true) ? self::breakEven($year, $o) : null;
        $out['assumptions'] = self::assumptions($year, $o, $out);
        $out['warnings'] = self::warnings($year, $o, $out, $scottish);
        $out['sources'] = self::sources($o);
        $out['researched_on'] = Planning::researchedOn();

        return $out;
    }

    /** The years the calculator has verified rates for. @return list<string> */
    public static function years(): array
    {
        return array_values(array_filter(Planning::YEARS, fn ($y) => Planning::rates($y) !== null));
    }

    /** The year to start from: the current tax year if it has rates, else the latest that does. */
    public static function defaultYear(): string
    {
        $years = self::years();
        $current = UkTaxYear::current()->label();

        return in_array($current, $years, true) ? $current : (string) end($years);
    }

    /**
     * The owner's profit for a year, to start the comparison from: the confirmed records for a year that is over; for
     * a year in progress, a simple projection from the records so far (as TaxSavings makes it, labelled); for a year not
     * started, the current year's figure. Never below nil.
     *
     * @return array{profit_pence:int, records_pence:int, basis:string, label:string}
     */
    public static function ownerProfit(string $year): array
    {
        $period = UkTaxYear::fromLabel($year);
        $today = CarbonImmutable::today();
        if ($today->lt($period->startsOn())) {
            $current = UkTaxYear::current()->label();
            $base = self::ownerProfit($current);

            return ['profit_pence' => $base['profit_pence'], 'records_pence' => 0, 'basis' => 'later_year',
                'label' => $year.' hasn’t started, so this is your '.$current.' profit'.($base['basis'] === 'projected' ? ' (projected)' : '').'.'];
        }
        $records = (int) ReturnSummary::for($year)['net_profit_pence'];
        if ($today->lte($period->endsOn())) {
            $days = (int) round($period->startsOn()->diffInDays($today)) + 1;
            $projected = TaxSavings::projection($period, $today, $records);

            return ['profit_pence' => max(0, $projected), 'records_pence' => $records, 'basis' => 'projected',
                'label' => 'A simple projection for the whole of '.$year.' from '.$days.' days of confirmed records ('.Money::format($records).' so far), not a forecast.'];
        }

        return ['profit_pence' => max(0, $records), 'records_pence' => $records, 'basis' => 'records',
            'label' => 'Your profit for '.$year.' from your confirmed records.'];
    }

    /**
     * One year's rates as exact decimals: amounts in pence, rates as fractions.
     *
     * @return array<string, BigDecimal>
     */
    public static function rates(string $year): array
    {
        $raw = Planning::rates($year);
        if ($raw === null) {
            throw new InvalidArgumentException("There are no verified rates for {$year}.");
        }
        $pounds = fn (string $key) => BigDecimal::of((string) $raw[$key])->multipliedBy(100);
        $rate = fn (string $key) => BigDecimal::of(self::decimal($raw[$key]));

        return [
            'pa' => $pounds('personal_allowance'), 'taper' => $pounds('pa_taper_start'), 'brb' => $pounds('basic_rate_band'),
            'hrl' => $pounds('additional_rate_threshold'),
            'basic' => $rate('basic_rate'), 'higher' => $rate('higher_rate'), 'additional' => $rate('additional_rate'),
            'div_allowance' => $pounds('dividend_allowance'), 'div_ordinary' => $rate('dividend_ordinary_rate'),
            'div_upper' => $rate('dividend_upper_rate'), 'div_additional' => $rate('dividend_additional_rate'),
            'ct_small' => $rate('ct_small_profits_rate'), 'ct_main' => $rate('ct_main_rate'), 'ct_lower' => $pounds('ct_lower_limit'),
            'ct_upper' => $pounds('ct_upper_limit'), 'ct_fraction' => $rate('ct_marginal_fraction'),
            'pt' => $pounds('employee_nic_primary_threshold'), 'ee_main' => $rate('employee_nic_main_rate'), 'ee_upper' => $rate('employee_nic_upper_rate'),
            'uel' => $pounds('upper_earnings_limit'), 'st' => $pounds('employer_nic_secondary_threshold'), 'er_rate' => $rate('employer_nic_rate'),
            'c4_lower' => $pounds('class4_lower_profits_limit'), 'c4_main' => $rate('class4_main_rate'), 'c4_upper' => $rate('class4_upper_rate'),
            'class2_weekly' => $pounds('class2_weekly_voluntary'), 'aa' => $pounds('pension_annual_allowance'),
        ];
    }

    /** A JSON number as exact decimal text (0.0875 stays "0.0875"). */
    private static function decimal(int|float|string $v): string
    {
        if (is_float($v)) {
            $text = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');

            return $text === '' || $text === '-' ? '0' : $text;
        }

        return (string) $v;
    }

    /** The options with defaults filled in and amounts checked. */
    private static function options(string $year, array $in): array
    {
        $int = fn (string $key, ?int $default = 0) => isset($in[$key]) && $in[$key] !== '' && $in[$key] !== null ? max(0, (int) $in[$key]) : $default;
        $salary = in_array($in['salary'] ?? null, self::SALARY_OPTIONS, true) ? $in['salary'] : 'best';
        if ($salary === 'custom' && ! isset($in['salary_pence'])) {
            $salary = 'best';
        }
        $share = BigDecimal::of(is_numeric($in['partner_share_pct'] ?? null) ? self::decimal($in['partner_share_pct']) : '50');
        $share = BigDecimal::min(BigDecimal::of(100), BigDecimal::max(BigDecimal::zero(), $share));
        $defaults = [];
        $accountant = $int('accountant_pence', null);
        $software = $int('software_pence', null);
        $statement = $int('confirmation_statement_pence', null);
        if ($accountant === null) {
            $defaults[] = 'accountant';
        }
        if ($software === null) {
            $defaults[] = 'software';
        }
        if ($statement === null) {
            $defaults[] = 'confirmation_statement';
        }

        return [
            'salary' => $salary,
            'salary_pence' => $salary === 'custom' ? $int('salary_pence') : null,
            'retain' => $int('retain_pence'),
            'accountant' => $accountant ?? self::DEFAULT_ACCOUNTANT_PENCE,
            'software' => $software ?? self::DEFAULT_SOFTWARE_PENCE,
            'statement' => $statement ?? (self::CONFIRMATION_STATEMENT_PENCE[$year] ?? end(self::CONFIRMATION_STATEMENT_PENCE)),
            'cost_defaults' => $defaults,
            'share' => $share,
            'partner_other' => $int('partner_other_income_pence'),
            'partner_other_se' => (bool) ($in['partner_other_self_employed'] ?? false),
            'pension' => $int('pension_pence'),
            'class2' => (bool) ($in['voluntary_class2'] ?? false),
        ];
    }

    /** The options used. No key is both a name and that name plus "_pence" (the assistant turns "x_pence" into "x"). */
    private static function echoOptions(array $o): array
    {
        return [
            'salary_option' => $o['salary'], 'custom_salary_pence' => $o['salary_pence'], 'retain_pence' => $o['retain'],
            'accountant_pence' => $o['accountant'], 'software_pence' => $o['software'], 'confirmation_statement_pence' => $o['statement'],
            'running_cost_defaults' => $o['cost_defaults'],
            'partner_share_pct' => (string) $o['share']->stripTrailingZeros(), 'partner_other_income_pence' => $o['partner_other'],
            'partner_other_self_employed' => $o['partner_other_se'],
            'pension_pence' => $o['pension'], 'voluntary_class2' => $o['class2'],
        ];
    }

    // ----- the sole trader -----

    /**
     * Income tax and Class 4 on the whole profit. Where TaxEstimator has the year (and the owner isn't a Scottish
     * taxpayer) its figures are used as they are, so this always agrees with the rest of the app.
     */
    private static function soleTrader(string $year, array $r, BigDecimal $profit, array $o, bool $useEstimator): array
    {
        $estimate = $useEstimator ? TaxEstimator::estimate($year, self::pence($profit)) : null;
        if ($estimate) {
            $incomeTax = (int) $estimate['income_tax']['total_pence'];
            $class4 = (int) $estimate['class4_pence'];
            $from = 'tax_estimator';
        } else {
            $incomeTax = self::pence(self::incomeTax($r, $profit)['total']);
            $class4 = self::pence(self::class4($r, $profit));
            $from = 'planning_rates';
        }
        $class2 = 0;
        $class2Note = null;
        if ($o['class2']) {
            $spt = self::smallProfitsThreshold($year);
            if ($spt !== null && $profit->isGreaterThanOrEqualTo($spt)) {
                $class2Note = 'No voluntary Class 2 to pay: at or above the small profits threshold ('.self::pounds($spt).') it is treated as paid.';
            } else {
                $class2 = self::pence($r['class2_weekly']->multipliedBy(self::CLASS2_WEEKS));
                $class2Note = 'Voluntary Class 2 for '.self::CLASS2_WEEKS.' weeks at '.Money::format(self::pence($r['class2_weekly'])).' a week, to keep the year for your State Pension.';
            }
        }
        $total = $incomeTax + $class4 + $class2;

        return [
            'income_tax_pence' => $incomeTax, 'class4_pence' => $class4, 'class2_pence' => $class2, 'class2_note' => $class2Note,
            'total_tax_pence' => $total, 'take_home_pence' => self::pence($profit) - $total, 'worked_out_with' => $from,
        ];
    }

    /**
     * The sole trader with a personal pension contribution under relief at source. The extra relief is worked out with
     * one method on both sides (the calculator's own engine, with and without the contribution) and taken off the figure
     * without a pension, so it stays right where TaxEstimator and the engine round the tapered allowance differently.
     */
    private static function soleTraderPension(array $r, BigDecimal $profit, array $o, array $without): array
    {
        [$gross, $capped] = self::personalPensionCap($r, $profit, $o['pension']);
        $net = $gross->multipliedBy('0.8');
        $relief = self::pence(self::incomeTax($r, $profit)['total']) - self::pence(self::incomeTax($r, $profit, BigDecimal::zero(), $gross)['total']);
        $incomeTax = $without['income_tax_pence'] - $relief;
        $class4 = $without['class4_pence'];
        $total = $incomeTax + $class4 + $without['class2_pence'];
        $takeHome = self::pence($profit) - $total - self::pence($net);

        return [
            'gross_pence' => self::pence($gross), 'you_pay_pence' => self::pence($net), 'added_by_provider_pence' => self::pence($gross) - self::pence($net),
            'extra_relief_pence' => $without['income_tax_pence'] - $incomeTax, 'capped' => $capped,
            'income_tax_pence' => $incomeTax, 'class4_pence' => $class4, 'class2_pence' => $without['class2_pence'],
            'total_tax_pence' => $total, 'take_home_pence' => $takeHome, 'cost_to_you_pence' => $without['take_home_pence'] - $takeHome,
        ];
    }

    /**
     * Gross personal contributions get relief only up to the higher of relevant UK earnings (trading profit) and £3,600
     * (FA 2004 s190), and the annual allowance (s228; carry-forward isn't counted).
     *
     * @return array{0:BigDecimal, 1:bool}
     */
    private static function personalPensionCap(array $r, BigDecimal $earnings, int $asked): array
    {
        $cap = BigDecimal::min($r['aa'], BigDecimal::max($earnings, BigDecimal::of(self::PENSION_BASIC_AMOUNT_PENCE)));
        $gross = BigDecimal::min(BigDecimal::of($asked), $cap);

        return [$gross, $gross->isLessThan(BigDecimal::of($asked))];
    }

    // ----- the company -----

    /** Every salary tried (or the one asked for), and the one that leaves the least tax. */
    private static function company(array $r, BigDecimal $profit, array $o, int $retain, int $pension): array
    {
        $costs = BigDecimal::of($o['accountant'] + $o['software'] + $o['statement']);
        $available = $profit->minus($costs);
        $names = $o['salary'] === 'best' ? self::SALARY_CANDIDATES : [$o['salary']];
        $tried = [];
        foreach ($names as $name) {
            $target = match ($name) {
                'none' => BigDecimal::zero(),
                'secondary_threshold' => $r['st'],
                'personal_allowance' => $r['pa'],
                'custom' => BigDecimal::of((int) $o['salary_pence']),
            };
            $tried[] = ['option' => $name] + self::companyAt($r, $available, $target, BigDecimal::of($retain), BigDecimal::of($pension));
        }
        $best = $tried[0];
        foreach ($tried as $t) {
            if ($t['total_tax_pence'] < $best['total_tax_pence']) {
                $best = $t;
            }
        }

        return $best + [
            'salary_option_asked' => $o['salary'],
            'running_costs_pence' => self::pence($costs),
            'salaries_tried' => array_map(fn ($t) => ['option' => $t['option'], 'salary_pence' => $t['salary_pence'], 'total_tax_pence' => $t['total_tax_pence'],
                'take_home_pence' => $t['take_home_pence']], $tried),
        ];
    }

    /**
     * The company at one salary. The salary is cut to what the company can pay with its employer NIC; an employer pension
     * contribution to what is left after that (and the annual allowance). Corporation tax is on what remains; what is
     * left after it is paid out as dividends, apart from any amount kept in the company.
     */
    private static function companyAt(array $r, BigDecimal $available, BigDecimal $target, BigDecimal $retain, BigDecimal $pension): array
    {
        $room = BigDecimal::max(BigDecimal::zero(), $available);
        // The most salary the company can pay with its employer NIC: s + rate x (s - ST) = room.
        $max = $room->isLessThanOrEqualTo($r['st']) ? $room
            : $r['st']->plus($room->minus($r['st'])->dividedBy(BigDecimal::one()->plus($r['er_rate']), 0, RoundingMode::DOWN));
        $salary = BigDecimal::min($target, $max);
        $employerNic = self::employerNic($r, $salary);
        $employeeNic = self::employeeNic($r, $salary);
        $afterSalary = $available->minus($salary)->minus($employerNic);
        $contribution = BigDecimal::max(BigDecimal::zero(), BigDecimal::min($pension, $r['aa'], $afterSalary));
        $taxable = $afterSalary->minus($contribution);
        [$ct, $band] = self::corporationTax($r, $taxable);
        $afterCt = BigDecimal::max(BigDecimal::zero(), $taxable->minus($ct));
        $kept = BigDecimal::min($retain, $afterCt);
        $dividends = $afterCt->minus($kept);
        $tax = self::incomeTax($r, $salary, $dividends);

        // Shown rounded down; take-home is the shown figures added up. A shortfall (costs more than the profit) is the owner's.
        $salaryP = self::pence($salary);
        $employeeNicP = self::pence($employeeNic);
        $salaryTaxP = self::pence($tax['non_savings']);
        $dividendTaxP = self::pence($tax['dividends']);
        $dividendsP = self::pence($dividends);
        $shortfall = $taxable->isNegative() ? self::pence($taxable) : 0;
        $total = self::pence($employerNic) + $employeeNicP + self::pence($ct) + $salaryTaxP + $dividendTaxP;

        return [
            'salary_pence' => $salaryP, 'salary_capped' => $salary->isLessThan($target),
            'employer_nic_pence' => self::pence($employerNic), 'employee_nic_pence' => $employeeNicP,
            'pension_pence' => self::pence($contribution), 'pension_capped' => $contribution->isLessThan($pension),
            'taxable_profit_pence' => self::pence($taxable), 'corporation_tax_pence' => self::pence($ct), 'corporation_tax_band' => $band,
            'dividends_pence' => $dividendsP, 'retained_pence' => self::pence($kept),
            'income_tax_on_salary_pence' => $salaryTaxP, 'dividend_tax_pence' => $dividendTaxP, 'income_tax_pence' => $salaryTaxP + $dividendTaxP,
            'personal_allowance_pence' => self::pence($tax['personal_allowance']), 'adjusted_net_income_pence' => self::pence($tax['ani']),
            'total_tax_pence' => $total,
            'take_home_pence' => $salaryP - $employeeNicP - $salaryTaxP - $dividendTaxP + $dividendsP + $shortfall,
            'shortfall_pence' => $shortfall,
        ];
    }

    private static function presentCompany(array $c, array $o): array
    {
        return [
            'salary_option' => $c['option'], 'salary_option_asked' => $c['salary_option_asked'], 'salary_pence' => $c['salary_pence'], 'salary_capped' => $c['salary_capped'],
            'running_costs' => [
                'accountant_pence' => $o['accountant'], 'software_pence' => $o['software'], 'confirmation_statement_pence' => $o['statement'],
                'total_pence' => $c['running_costs_pence'], 'defaults' => $o['cost_defaults'],
            ],
            'employer_nic_pence' => $c['employer_nic_pence'], 'employee_nic_pence' => $c['employee_nic_pence'],
            'taxable_profit_pence' => $c['taxable_profit_pence'], 'corporation_tax_pence' => $c['corporation_tax_pence'], 'corporation_tax_band' => $c['corporation_tax_band'],
            'dividends_pence' => $c['dividends_pence'], 'retained_pence' => $c['retained_pence'],
            'income_tax_on_salary_pence' => $c['income_tax_on_salary_pence'], 'dividend_tax_pence' => $c['dividend_tax_pence'], 'income_tax_pence' => $c['income_tax_pence'],
            'adjusted_net_income_pence' => $c['adjusted_net_income_pence'],
            'total_tax_pence' => $c['total_tax_pence'], 'take_home_pence' => $c['take_home_pence'], 'shortfall_pence' => $c['shortfall_pence'],
            'pension_capped' => $c['pension_capped'],
            'salaries_tried' => $c['salaries_tried'],
        ];
    }

    // ----- the partnership -----

    /**
     * The profit split with a spouse or partner. Each pays income tax and Class 4 on their own share (Class 4 is charged
     * on each partner separately); the partner's other income is taxed first, so their share is taxed at their top
     * rate. If that other income is self-employment, Class 4 is on their profits from every trade together (SSCBA 1992
     * Sch 2 para 4), so the share pays the extra Class 4 on top of it; otherwise (wages, a pension) Class 4 is on the
     * share alone. No trading allowance: partners can't use it (ITTOIA s783AA(1)(a)). A pension is the owner's own
     * contribution.
     */
    private static function partnership(array $r, BigDecimal $profit, array $o, int $pension): array
    {
        $partnerShare = $profit->multipliedBy($o['share'])->dividedByExact(100);
        $yourShare = $profit->minus($partnerShare);
        [$gross, $capped] = $pension > 0 ? self::personalPensionCap($r, $yourShare, $pension) : [BigDecimal::zero(), false];
        $net = $gross->multipliedBy('0.8');

        $yourTax = self::incomeTax($r, $yourShare, BigDecimal::zero(), $gross);
        $yourIt = self::pence($yourTax['total']);
        $yourC4 = self::pence(self::class4($r, $yourShare));

        $other = BigDecimal::of($o['partner_other']);
        $partnerIt = self::pence(self::incomeTax($r, $other->plus($partnerShare))['total']->minus(self::incomeTax($r, $other)['total']));
        $partnerC4 = self::pence($o['partner_other_se']
            ? self::class4($r, $other->plus($partnerShare))->minus(self::class4($r, $other))
            : self::class4($r, $partnerShare));

        $you = ['share_pence' => self::pence($yourShare), 'income_tax_pence' => $yourIt, 'class4_pence' => $yourC4,
            'take_home_pence' => self::pence($yourShare) - $yourIt - $yourC4 - self::pence($net), 'adjusted_net_income_pence' => self::pence($yourTax['ani'])];
        $partner = ['share_pence' => self::pence($partnerShare), 'income_tax_pence' => $partnerIt, 'class4_pence' => $partnerC4,
            'take_home_pence' => self::pence($partnerShare) - $partnerIt - $partnerC4, 'other_income_pence' => $o['partner_other'],
            'adjusted_net_income_pence' => self::pence($other->plus($partnerShare))];

        return [
            'partner_share_pct' => (string) $o['share']->stripTrailingZeros(),
            'you' => $you, 'partner' => $partner,
            'trading_allowance_pence' => 0,
            'total_tax_pence' => $yourIt + $yourC4 + $partnerIt + $partnerC4,
            'take_home_pence' => $you['take_home_pence'] + $partner['take_home_pence'],
            'pension_pence' => self::pence($gross), 'pension_net_pence' => self::pence($net), 'pension_capped' => $capped,
        ];
    }

    // ----- break-even -----

    /**
     * Every band of profit, on a £500 grid up to £300,000, where the company's take-home beats the sole trader's after
     * running costs, with this year's rates, the same salary choice and running costs, all profit paid out and no
     * pension. There can be more than one: in 2024-25 a second, small band sits in the personal allowance taper
     * (planning: incorporation-rule-of-thumb). profit_pence and ahead_until_pence are the first band.
     */
    private static function breakEven(string $year, array $o): array
    {
        $windows = Cache::remember(self::breakEvenKey($year, $o), now()->addDays(7), fn () => self::aheadWindows($year, $o));
        $money = fn (int $p) => self::pounds(BigDecimal::of($p));
        // "at a profit from about £52,500 to about £73,500, and again from about £123,000 to about £127,500", or "of about £60,500 only".
        $band = function (array $w, int $i) use ($money): string {
            if ($w['from_pence'] === $w['until_pence']) {
                return ($i === 0 ? 'of' : 'at').' about '.$money($w['from_pence']).' only';
            }

            return $w['until_pence'] >= self::BREAK_EVEN_MAX_PENCE
                ? 'from about '.$money($w['from_pence']).' upwards (checked up to '.$money(self::BREAK_EVEN_MAX_PENCE).')'
                : 'from about '.$money($w['from_pence']).' to about '.$money($w['until_pence']);
        };
        $bands = array_map($band, $windows, array_keys($windows));
        $last = array_pop($bands);

        return [
            'profit_pence' => $windows[0]['from_pence'] ?? null,
            'ahead_until_pence' => $windows[0]['until_pence'] ?? null,
            'windows' => $windows,
            'step_pence' => self::BREAK_EVEN_STEP_PENCE,
            'searched_to_pence' => self::BREAK_EVEN_MAX_PENCE,
            'note' => $windows === []
                ? 'With these running costs and all profit paid out each year, a company doesn’t leave you more to take home than sole trading at any profit up to '.$money(self::BREAK_EVEN_MAX_PENCE).' in '.$year.' (an estimate).'
                : 'A company leaves you more to take home than sole trading at a profit '.($bands === [] ? $last : implode(', ', $bands).', and again '.$last.',')
                    .' in '.$year.', with these running costs and all profit paid out (an estimate, to the nearest £500).',
        ];
    }

    /**
     * The cache key for a break-even search. The search doesn't depend on the profit, the partner, a pension or money
     * kept in the company, only on the year's rates, the salary choice and the running costs, so it is kept (in the app's
     * own cache) rather than run again on every change.
     *
     * @param  array  $options  as compare() takes them
     */
    public static function breakEvenCacheKey(string $year, array $options): string
    {
        return self::breakEvenKey($year, self::options($year, $options));
    }

    private static function breakEvenKey(string $year, array $o): string
    {
        $rates = md5((string) json_encode(Planning::rates($year)));

        return 'planning:break-even:v'.self::BREAK_EVEN_VERSION.':'.$year.':'.$rates.':'.$o['salary'].':'.($o['salary_pence'] ?? '-')
            .':'.($o['accountant'] + $o['software'] + $o['statement']);
    }

    /** @return list<array{from_pence:int, until_pence:int}> */
    private static function aheadWindows(string $year, array $o): array
    {
        $r = self::rates($year);
        $plain = ['retain' => 0, 'pension' => 0, 'class2' => false] + $o;
        $windows = [];
        $open = false;
        for ($p = self::BREAK_EVEN_STEP_PENCE; $p <= self::BREAK_EVEN_MAX_PENCE; $p += self::BREAK_EVEN_STEP_PENCE) {
            $profit = BigDecimal::of($p);
            $sole = self::soleTrader($year, $r, $profit, $plain, false);
            $company = self::company($r, $profit, $plain, 0, 0);
            if ($company['take_home_pence'] > $sole['take_home_pence']) {
                if ($open) {
                    $windows[count($windows) - 1]['until_pence'] = $p;
                } else {
                    $windows[] = ['from_pence' => $p, 'until_pence' => $p];
                }
                $open = true;
            } else {
                $open = false;
            }
        }

        return $windows;
    }

    // ----- the tax engine (rest of the UK) -----

    /**
     * Income tax on non-savings income (salary, trading profit) and dividends, stacked in that order. The personal
     * allowance is withdrawn by £1 for every £2 of adjusted net income over £100,000, rounded up to the next whole
     * pound (ITA s35), and set against non-savings income first. Relief-at-source pension contributions (gross) extend
     * the basic and higher rate limits (FA 2004 s192(4)) and reduce adjusted net income (ITA s58). The dividend
     * allowance is a nil rate that still uses up the band it falls in (ITA s13A).
     *
     * @return array{total:BigDecimal, non_savings:BigDecimal, dividends:BigDecimal, personal_allowance:BigDecimal, ani:BigDecimal}
     */
    public static function incomeTax(array $r, BigDecimal $nonSavings, ?BigDecimal $dividends = null, ?BigDecimal $extension = null): array
    {
        $dividends ??= BigDecimal::zero();
        $extension ??= BigDecimal::zero();
        $zero = BigDecimal::zero();
        $ani = $nonSavings->plus($dividends)->minus($extension);
        $pa = $r['pa'];
        if ($ani->isGreaterThan($r['taper'])) {
            $pa = BigDecimal::max($zero, $pa->minus($ani->minus($r['taper'])->dividedByExact(2)));
            // Up to the next whole pound.
            $pa = $pa->dividedBy(100, 0, RoundingMode::UP)->multipliedBy(100);
        }
        $paNonSavings = BigDecimal::min($pa, $nonSavings);
        $paDividends = BigDecimal::min($pa->minus($paNonSavings), $dividends);
        $taxableNonSavings = $nonSavings->minus($paNonSavings);
        $taxableDividends = $dividends->minus($paDividends);
        $basicLimit = $r['brb']->plus($extension);
        $higherLimit = $r['hrl']->plus($extension);

        $nonSavingsTax = $r['basic']->multipliedBy(self::overlap($zero, $taxableNonSavings, $zero, $basicLimit))
            ->plus($r['higher']->multipliedBy(self::overlap($zero, $taxableNonSavings, $basicLimit, $higherLimit)))
            ->plus($r['additional']->multipliedBy(self::overlap($zero, $taxableNonSavings, $higherLimit, null)));

        $nil = BigDecimal::min($r['div_allowance'], $taxableDividends);
        $from = $taxableNonSavings->plus($nil);
        $to = $taxableNonSavings->plus($taxableDividends);
        $dividendTax = $r['div_ordinary']->multipliedBy(self::overlap($from, $to, $zero, $basicLimit))
            ->plus($r['div_upper']->multipliedBy(self::overlap($from, $to, $basicLimit, $higherLimit)))
            ->plus($r['div_additional']->multipliedBy(self::overlap($from, $to, $higherLimit, null)));

        return ['total' => $nonSavingsTax->plus($dividendTax), 'non_savings' => $nonSavingsTax, 'dividends' => $dividendTax, 'personal_allowance' => $pa, 'ani' => $ani];
    }

    /** Class 4: the main rate between the lower and upper profits limits, the upper rate above. */
    public static function class4(array $r, BigDecimal $profit): BigDecimal
    {
        $zero = BigDecimal::zero();

        return $r['c4_main']->multipliedBy(self::overlap($zero, $profit, $r['c4_lower'], $r['uel']))
            ->plus($r['c4_upper']->multipliedBy(self::overlap($zero, $profit, $r['uel'], null)));
    }

    /** Employee (primary) Class 1 on a director's annual earnings. */
    public static function employeeNic(array $r, BigDecimal $salary): BigDecimal
    {
        $zero = BigDecimal::zero();

        return $r['ee_main']->multipliedBy(self::overlap($zero, $salary, $r['pt'], $r['uel']))
            ->plus($r['ee_upper']->multipliedBy(self::overlap($zero, $salary, $r['uel'], null)));
    }

    /** Employer (secondary) Class 1 above the secondary threshold. No Employment Allowance (only director paid). */
    public static function employerNic(array $r, BigDecimal $salary): BigDecimal
    {
        return $r['er_rate']->multipliedBy(BigDecimal::max(BigDecimal::zero(), $salary->minus($r['st'])));
    }

    /**
     * Corporation tax: the small profits rate up to the lower limit, the main rate from the upper limit, and between
     * them the main rate less marginal relief of fraction x (upper limit - profits) (CTA 2010 s18B, no associated
     * companies, a 12-month period, no exempt distributions, so N/A = 1).
     *
     * @return array{0:BigDecimal, 1:string} the tax and which band: none, small, marginal or main
     */
    public static function corporationTax(array $r, BigDecimal $profits): array
    {
        if (! $profits->isPositive()) {
            return [BigDecimal::zero(), 'none'];
        }
        if ($profits->isLessThanOrEqualTo($r['ct_lower'])) {
            return [$profits->multipliedBy($r['ct_small']), 'small'];
        }
        if ($profits->isGreaterThanOrEqualTo($r['ct_upper'])) {
            return [$profits->multipliedBy($r['ct_main']), 'main'];
        }

        return [$profits->multipliedBy($r['ct_main'])->minus($r['ct_fraction']->multipliedBy($r['ct_upper']->minus($profits))), 'marginal'];
    }

    /** How much of [from, to) lies in [lo, hi) (hi null: no top). */
    private static function overlap(BigDecimal $from, BigDecimal $to, BigDecimal $lo, ?BigDecimal $hi): BigDecimal
    {
        $start = BigDecimal::max($from, $lo);
        $end = $hi === null ? $to : BigDecimal::min($to, $hi);

        return $end->isGreaterThan($start) ? $end->minus($start) : BigDecimal::zero();
    }

    /** Whole pence, rounded down: the only rounding, at the end. */
    private static function pence(BigDecimal $amount): int
    {
        return $amount->toScale(0, RoundingMode::DOWN)->toInt();
    }

    /** "£12,570" for whole pounds, "£3.80" otherwise. */
    private static function pounds(BigDecimal $pence): string
    {
        $text = Money::format(self::pence($pence), 'GBP', false);

        return str_ends_with($text, '.00') ? substr($text, 0, -3) : $text;
    }

    private static function smallProfitsThreshold(string $year): ?BigDecimal
    {
        $spt = TaxParameters::for($year)['class2_voluntary']['small_profits_threshold'] ?? null;
        if ($spt === null && $year === '2027-28') {
            $spt = self::SMALL_PROFITS_THRESHOLD_2027_28_PENCE;
        }

        return $spt === null ? null : BigDecimal::of($spt);
    }

    // ----- what the result says about itself -----

    private static function law(string $year): array
    {
        $announced = Planning::announced($year);

        return [
            'announced' => $announced,
            'label' => $announced ? 'Announced, not yet law' : 'Law',
            'announced_items' => $announced ? self::ANNOUNCED_2027_28 : [],
            'disputed' => $year === '2027-28' ? [self::DISPUTED_2027_28] : [],
            'law_note' => $announced ? self::LAW_2027_28.' '.self::DISPUTED_2027_28 : 'All the rates used for '.$year.' are law.',
        ];
    }

    /** @return list<string> */
    private static function assumptions(string $year, array $o, array $out): array
    {
        $r = self::rates($year);
        $money = fn (int $p) => self::pounds(BigDecimal::of($p));
        $c = $out['company'];
        $costs = $c['running_costs'];
        $label = fn (string $key, string $text) => in_array($key, $o['cost_defaults'], true) ? $text : 'your figure';
        $salary = match ($c['salary_option']) {
            'none' => 'no salary',
            'secondary_threshold' => 'a salary of '.$money($c['salary_pence']).' (the employer NIC secondary threshold)',
            'personal_allowance' => 'a salary of '.$money($c['salary_pence']).' (the personal allowance)',
            default => 'a salary of '.$money($c['salary_pence']).' (your figure)',
        };

        return array_values(array_filter([
            'An estimate for '.$year.' from the verified rates (England, Wales and Northern Ireland). HMRC’s own calculation is the authority.',
            'Profit is the business’s profit for the year after its costs and before anything you pay yourself: '.$money($out['profit_pence']).'. It is the same in every structure.',
            'You have no other income, no student loan and no Gift Aid; Child Benefit and childcare schemes aren’t counted.',
            'Sole trader: income tax and Class 4 on the whole profit'.($o['class2'] ? ', with voluntary Class 2 if your profit is under the small profits threshold' : ', with no voluntary Class 2').'. Profit is after your actual costs, so the trading allowance isn’t used.',
            'Company: you are its only director and only employee, so it can’t claim the Employment Allowance. Its year matches the tax year and it has no associated companies.',
            'Company: '.$salary.($o['salary'] === 'best' ? ', the one of nothing, '.$money(self::pence($r['st'])).' and '.$money(self::pence($r['pa'])).' that leaves the least tax' : '')
                .($c['salary_capped'] ? ', cut to what the company can pay' : '').'. Salary is paid through PAYE as a director (an annual earnings period).',
            'Company: corporation tax at '.self::pct($r['ct_small']).' up to '.$money(self::pence($r['ct_lower'])).', '.self::pct($r['ct_main']).' from '.$money(self::pence($r['ct_upper']))
                .', with marginal relief between (fraction '.self::fraction($r['ct_fraction']).').',
            'Company: '.($c['retained_pence'] > 0 ? 'everything left after corporation tax is paid as dividends in the same tax year, apart from '.$money($c['retained_pence']).' kept in the company' : 'everything left after corporation tax is paid as dividends in the same tax year').'.',
            'Company running costs a year: accountant '.$money($costs['accountant_pence']).' ('.$label('accountant', 'Expend’s placeholder: there is no official figure, so use a quote').'), software '
                .$money($costs['software_pence']).' ('.$label('software', 'Expend’s placeholder').'), Companies House confirmation statement '.$money($costs['confirmation_statement_pence'])
                .' ('.$label('confirmation_statement', 'the online fee for most of '.$year).'). They are deducted before corporation tax.',
            'Partnership: your spouse or partner gets '.$out['partnership']['partner_share_pct'].'% of the profit'
                .($o['partner_other'] > 0
                    ? ' and has '.$money($o['partner_other']).' of other income, taxed first'.($o['partner_other_se']
                        ? '. It is from self-employment, so their Class 4 is worked out on their profits from both together'
                        : '. It is taken to be wages or a pension, not self-employment, so their Class 4 is on their share alone')
                    : ' and has no other income')
                .'. Each of you pays income tax and Class 4 on your own share. Partners can’t use the trading allowance.',
            $o['pension'] > 0 ? 'Pension: '.$money($o['pension']).' a year gross. Sole trader and partnership: your own contribution under relief at source: you pay 80% and the provider adds 20%; the basic and higher rate limits go up by the gross amount and it doesn’t reduce Class 4. Company: an employer contribution, deducted before corporation tax, with no National Insurance.' : null,
            'Company and partnership: a steady year after the change. The year you set up a company has one-off costs that aren’t shown here (see the warning about your kit).',
            'Every engagement is taken to be outside IR35 and the agency rules (see the warning).',
            'Each figure is worked out exactly and rounded down to the penny at the end; take-home is the figures shown added up.',
        ]));
    }

    /** @return list<array{code:string, title:string, text:string, url:?string}> */
    private static function warnings(string $year, array $o, array $out, bool $scottish): array
    {
        $money = fn (int $p) => self::pounds(BigDecimal::of($p));
        $url = fn (string $id) => Planning::item($id)['url'] ?? null;
        $w = [];
        $add = function (string $code, string $title, string $text, ?string $id = null) use (&$w, $url) {
            $w[] = ['code' => $code, 'title' => $title, 'text' => $text, 'url' => $id ? $url($id) : null];
        };

        $add('not_advice', 'An estimate, not advice',
            'This is an estimate to help you think it through and ask the right questions, not advice. Before you set up a company or a partnership, ask an accountant to check it with your real figures.');

        if ($out['law']['announced']) {
            $add('announced', $year.': announced, not yet law', 'Some '.$year.' figures are announced but not yet law: '.implode(' ', self::ANNOUNCED_2027_28).' '.self::LAW_2027_28
                .($out['law']['disputed'] ? ' '.implode(' ', $out['law']['disputed']) : ''), 'sole-trader');
        }
        if ($scottish) {
            $add('scottish', 'Scottish rates not used',
                'You’ve said you’re a Scottish taxpayer. These figures use the rest-of-UK income tax rates, so the sole trader and partnership figures (and tax on a salary) will be different for you. Dividend tax and corporation tax are the same across the UK.');
        }

        $add('ir35', 'IR35, off-payroll working and the agency rules',
            'Everything here assumes your work would be self-employment if you were hired directly. Through a crewing agency, where the client supervises, directs or controls how you work, the agency rules (ITEPA s44) come first: they apply to you as a sole trader, and to your own company too, which then has to run PAYE and National Insurance on that pay, so a company saves nothing on that work. If an engagement would be employment (inside IR35), the company’s advantage goes on that work, and if most of your work is inside IR35 a company usually costs more than staying a sole trader. For a small client, your company must work out a “deemed employment payment” at the end of the tax year: 95% of what it was paid for that work, less a few allowed costs and the salary already paid, taxed like salary with PAYE and both employee and employer NIC, and the Employment Allowance can’t be used against it (ITEPA s54). For medium or large clients and the public sector, the client decides, and whoever pays your company takes off tax and NIC before paying (off-payroll working). Expend doesn’t work this out here, because it needs what each client paid, how each booking was made and which costs an employee could claim. IR35 itself doesn’t apply to a sole trader.',
            'ir35-for-incorporated-events-tech');

        $add('incorporation_year', 'The year you set up a company: your kit',
            'These figures are for a steady year. The year you incorporate has one-off costs not shown here. Moving your kit into the company counts as selling it: on the cash basis, what the company pays for it (or its market value if it just moves across) is a receipt of your last sole-trade period, taxed with income tax and Class 4. Kit bought in that last period gets no annual investment allowance, and the company gets only writing-down allowances on kit it takes over, not the annual investment allowance. A joint election (CAA 2001 s266) can move pooled kit across at its tax value instead, but how it works for kit already claimed under the cash basis isn’t settled, so take advice.'
                .(in_array($year, ['2024-25', '2025-26'], true) ? '' : ' For a business moved into a company from 6 April 2026, incorporation relief on any gains (TCGA s162) has to be claimed: it is no longer automatic.')
                .' Ask an accountant to plan the date and the kit before you incorporate.',
            'caa-s266-election');

        $add('company_admin', 'A company means more admin, and automatic penalties',
            'A company is a separate legal person with its own duties: annual accounts at Companies House (9 months after its year end), a Company Tax Return (CT600, 12 months after, and commercial software is needed since HMRC’s free service closed on 31 March 2026), corporation tax paid 9 months and a day after the year end, a confirmation statement every year, payroll (RTI) for your salary, identity checks for directors, and a Self Assessment return for you. Late accounts bring automatic penalties of £150 to £1,500 (doubled if late two years running), and a late CT600 £200, or £400 if more than 3 months late. Company money isn’t yours: taking it other than as salary or dividends is a director’s loan, which can bring a 35.75% charge if not repaid in time.',
            'company-running-costs');

        if ($out['company']['retained_pence'] > 0) {
            $add('retained', 'Money kept in the company is taxed later',
                $money($out['company']['retained_pence']).' is left in the company. That puts off dividend tax rather than saving it: it is taxed when you take it out later, at the dividend rates then.', 'company-vs-sole-extraction');
        }
        if ($out['company']['salary_capped']) {
            $add('salary_capped', 'Salary cut to what the company can pay',
                'The company can’t pay the salary chosen and its employer NIC out of its profit after running costs, so the salary is cut to '.$money($out['company']['salary_pence']).'.');
        }
        if ($out['company']['shortfall_pence'] < 0) {
            $add('costs_exceed_profit', 'Running costs more than the profit',
                'At this profit the company’s running costs are more than its profit: you would have to put in '.$money(-$out['company']['shortfall_pence']).' to pay them.');
        }
        $salary = $out['company']['salary_pence'];
        $lel = self::LOWER_EARNINGS_LIMIT_PENCE[$year] ?? null;
        $childcare = self::CHILDCARE_MINIMUM_PENCE[$year] ?? null;
        $belowLel = $lel !== null && $salary < $lel;
        $belowChildcare = $childcare !== null && $salary < $childcare;
        if ($belowLel || $belowChildcare) {
            $announced = $out['law']['announced'] ? ' ('.$year.' figures announced, not yet law)' : '';
            $add('low_salary', 'A low salary has other costs',
                'The company salary here is '.$money($salary).'.'
                .($belowLel ? ' That is under the lower earnings limit of '.$money($lel).$announced.', so the salary doesn’t earn you a qualifying year for your State Pension (SSCBA 1992 s6A).' : '')
                .($belowChildcare ? ' For Tax-Free Childcare and the 30 hours of free childcare only salary counts, not dividends: someone aged 21 or over needs to expect about '
                    .$money($childcare).' a year if paid evenly'.($belowLel ? '' : $announced).', so with children this salary may lose them.' : ''),
                $belowChildcare ? 'tax-free-childcare' : 'director-salary-at-nic-thresholds');
        }

        $add('partnership_settlements', 'A partner’s share must be a real share (settlements rules)',
            'If your partner is your spouse or civil partner, they are taxed on their share only if it is a real share of the business, its capital as well as its income, given outright with no strings (ITTOIA s626). A share that is wholly or mainly a right to income, or that can come back to you, is taxed on you instead (s624, HMRC TSEM4215), so the saving shown disappears. '
                .'If you aren’t married or in a civil partnership, a share given to them outright is generally theirs for tax unless it can come back to you (TSEM4215 Example 11), but there is no spouse relief from capital gains tax or inheritance tax and no Marriage Allowance. '
                .'A share for your child under 18 that gives them more than £100 a year is taxed on you (s629).',
            'spouse-partnership');
        $yourShare = BigDecimal::of(100)->minus($o['share']);
        $add('partnership_genuine', 'It must be a genuine partnership',
            'A partnership is two or more people carrying on a business together. It needs a written agreement before the period it covers (not backdated), clients contracting with and paying the firm rather than you, a partnership tax return as well as a return for each partner, and both of you are liable for all the firm’s debts. HMRC is likelier to challenge it where the business depends on one person’s skills. '
                .'IR35 applies to work through a partnership where you, alone or with relatives, get 60% or more of the profits (ITEPA s52(2)(a)). A spouse or civil partner counts as a relative, so with them as your partner you get 100% between you and IR35 applies to any engagement that would be employment if you were hired directly. '
                .($yourShare->isGreaterThanOrEqualTo(60)
                    ? 'An unmarried partner isn’t a relative, but your own '.$yourShare->stripTrailingZeros().'% share is 60% or more, so it applies then too.'
                    : 'An unmarried partner isn’t a relative, so with your '.$yourShare->stripTrailingZeros().'% share it applies only if most of the profit comes from such engagements with one client, or each partner’s share follows the income they bring in from them (s52(2)(b) and (c)).'),
            'general-partnership');

        if ($o['pension'] > 0) {
            if (($out['sole_trader']['pension']['capped'] ?? false) || ($out['company']['pension']['capped'] ?? false) || ($out['partnership']['pension']['capped'] ?? false)) {
                $add('pension_capped', 'Pension contribution capped',
                    'Relief on your own contributions is limited to your earnings from the business (or £3,600 gross) and the annual allowance; a company can only pay in what it has left after salary. Carry-forward of unused allowance isn’t counted. Where the amount is capped, the lower figure is used.', 'pension-annual-allowance');
            }
            $add('pension_company', 'Employer pension contributions must be reasonable',
                'HMRC accepts a company’s pension contribution for its director as a business cost where salary and pension together are in line with what the company would pay someone else for the same work. Pension money is locked away until pension age.', 'employer-pension-contributions');
        }

        // Income levels where other charges start (adjusted net income of anyone in a scenario).
        $anis = [$out['profit_pence'], $out['company']['adjusted_net_income_pence'], $out['partnership']['you']['adjusted_net_income_pence'], $out['partnership']['partner']['adjusted_net_income_pence']];
        if (max($anis) > 6_000_000) {
            $add('hicbc', 'Child Benefit charge not included',
                'Some of these incomes are over £60,000. If you or a partner get Child Benefit, the High Income Child Benefit Charge (from £60,000 of adjusted net income) isn’t included; pension contributions reduce it.', 'high-income-child-benefit-charge');
        }
        if (max($anis) > 10_000_000) {
            $add('taper', 'Over £100,000',
                'Over £100,000 of adjusted net income the personal allowance is withdrawn (an effective 60% band, about 62% with Class 4, up to £125,140), and Tax-Free Childcare and the 30 hours of free childcare stop. Pension contributions bring adjusted net income down.', 'pension-restore-personal-allowance');
        }

        return $w;
    }

    /** @return list<array{id:string, title:string, url:string}> */
    private static function sources(array $o): array
    {
        $ids = [...self::SOURCES, ...($o['pension'] > 0 ? self::PENSION_SOURCES : [])];

        return array_values(array_filter(array_map(fn ($id) => Planning::source($id), $ids)));
    }

    private static function pct(BigDecimal $rate): string
    {
        return (string) $rate->multipliedBy(100)->stripTrailingZeros().'%';
    }

    private static function fraction(BigDecimal $rate): string
    {
        return $rate->isEqualTo('0.015') ? '3/200' : (string) $rate->stripTrailingZeros();
    }
}
