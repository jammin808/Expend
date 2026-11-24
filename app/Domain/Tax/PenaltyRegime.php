<?php

namespace App\Domain\Tax;

use App\Models\Setting;
use App\Support\UkTaxYear;
use Closure;

/**
 * Which late filing and late payment penalties apply to a tax year's return and tax. It follows the year the return
 * is for, not when it's filed or paid.
 *
 *  - sch55_56: FA 2009 Schedules 55 and 56 (£100, daily, 6- and 12-month late filing; 5% late payment).
 *  - sch24_26_law: FA 2021 Schedules 24 and 26 (points; new late payment penalties), which are law for you: as an
 *    accepted MTD volunteer from the year HMRC confirmed (SI 2024/440, 2024-25 at the earliest), or as a digitally
 *    obligated person, in MTD from 6 April 2026 because 2024-25 qualifying income was over £50,000 (SI 2026/370), for
 *    2026-27 and every later year, even after a later exemption. An exemption from 2026-27 (GOV.UK), or one that HMRC
 *    treat as meaning the earlier requirements were never imposed (TMA 1970 Sch A1 para 14(1A) or 15(3)), takes you
 *    out; one from 2027-28 or later leaves you on these rules, with quarterly updates (and the 4-point threshold) ending
 *    from that year.
 *  - sch24_26_announced: everyone else from 2027-28. Announced, not law (no appointed-day regulations at 24 November
 *    2026), so Schedules 55 and 56 stay the legal figures and the new rules are shown beside them as an estimate.
 */
final class PenaltyRegime
{
    public const SCH55_56 = 'sch55_56';

    public const LAW = 'sch24_26_law';

    public const ANNOUNCED = 'sch24_26_announced';

    /** @param  Closure(string):array|null  $summary  ReturnSummary::for, or a cached one */
    public static function for(string $label, ?Closure $summary = null): string
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $from = PenaltyEstimator::rules()['regimes']['new_regime_from'];
        if ($label < (PenaltyEstimator::rules()['regimes']['volunteers_from_earliest'] ?? '2024-25')) {
            return self::SCH55_56;
        }
        $volunteer = self::volunteerFrom();
        if ($volunteer !== null && $label >= $volunteer) {
            return self::LAW;
        }
        if ($label >= $from['mtd'] && self::digitallyObligated($summary)) {
            return self::LAW;
        }

        return $label >= $from['everyone'] ? self::ANNOUNCED : self::SCH55_56;
    }

    /** The first tax year under the new rules for this person (its 30-day first-year window is given once). */
    public static function firstNewRulesYear(?Closure $summary = null): string
    {
        $from = PenaltyEstimator::rules()['regimes']['new_regime_from'];
        $volunteer = self::volunteerFrom();
        $candidates = array_filter([$volunteer, self::digitallyObligated($summary) ? $from['mtd'] : null, $from['everyone']]);

        return min($candidates);
    }

    /** The year HMRC accepted you as a volunteer for the new penalties from (not before 2024-25), if you are one. */
    public static function volunteerFrom(): ?string
    {
        $from = Setting::get('mtd_volunteer_from');
        if (! is_string($from) || $from === '') {
            return null;
        }
        try {
            $label = UkTaxYear::fromLabel($from)->label();
        } catch (\InvalidArgumentException) {
            return null;
        }

        return max($label, PenaltyEstimator::rules()['regimes']['volunteers_from_earliest'] ?? '2024-25');
    }

    /**
     * The tax year your exemption from Making Tax Digital runs from (Settings), or null if you're not exempt. The old
     * single "exempt" tick, with no year, counts as exempt from 2026-27, which is what it used to mean.
     */
    public static function exemptFrom(): ?string
    {
        $from = Setting::get('mtd_exempt_from');
        if (is_string($from) && $from !== '') {
            try {
                return UkTaxYear::fromLabel($from)->label();
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return Setting::get('mtd_exempt') ? PenaltyEstimator::rules()['regimes']['new_regime_from']['mtd'] : null;
    }

    /** HMRC treated your earlier Making Tax Digital requirements as never imposed (TMA 1970 Sch A1 para 14(1A) or 15(3)). */
    public static function exemptAsIfNeverRequired(): bool
    {
        return self::exemptFrom() !== null && (bool) Setting::get('mtd_exempt_never_required');
    }

    /**
     * A digitally obligated person (SI 2026/370): in MTD from 6 April 2026 (SI 2026/336) because 2024-25 qualifying
     * income, gross trading plus other business income before expenses, was over £50,000. Not if you're exempt from
     * 2026-27 (GOV.UK: back to the old rules), or HMRC treat the earlier requirements as never imposed; an exemption
     * from 2027-28 or later leaves you one. Expend has no property income, so it isn't counted; if you had some, the
     * test may be met when this says it isn't.
     */
    public static function digitallyObligated(?Closure $summary = null): bool
    {
        $first = config('tax.mtd_thresholds')[0] ?? null;
        if ($first === null) {
            return false;
        }
        $exempt = self::exemptFrom();
        $firstYear = UkTaxYear::fromDate($first['starts'])->label();
        if ($exempt !== null && ($exempt <= $firstYear || self::exemptAsIfNeverRequired())) {
            return false;
        }

        return self::qualifyingIncome($first['based_on'], $summary) > $first['turnover_over'];
    }

    /** Whether you're required to send MTD quarterly updates in a year (4-point threshold, rather than 2). */
    public static function mandated(string $label, ?Closure $summary = null): bool
    {
        $exempt = self::exemptFrom();
        if ($exempt !== null && ($label >= $exempt || self::exemptAsIfNeverRequired())) {
            return false;
        }
        foreach (config('tax.mtd_thresholds', []) as $t) {
            if (UkTaxYear::fromDate($t['starts'])->label() <= $label && self::qualifyingIncome($t['based_on'], $summary) > $t['turnover_over']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Your first tax year in Making Tax Digital, if you're in it. Late quarterly updates in that year earn no points
     * (HMRC's first-year easement, also for a former volunteer), though a late return does.
     */
    public static function firstMandatedYear(?Closure $summary = null): ?string
    {
        $exempt = self::exemptFrom();
        if (self::exemptAsIfNeverRequired()) {
            return null;
        }
        $years = [];
        foreach (config('tax.mtd_thresholds', []) as $t) {
            $year = UkTaxYear::fromDate($t['starts'])->label();
            if (($exempt === null || $year < $exempt) && self::qualifyingIncome($t['based_on'], $summary) > $t['turnover_over']) {
                $years[] = $year;
            }
        }

        return $years ? min($years) : null;
    }

    /** How your Making Tax Digital exemption, if any, is taken (for the year page and the assistant). */
    public static function exemptionText(): ?string
    {
        $from = self::exemptFrom();
        if ($from === null) {
            return null;
        }
        if (self::exemptAsIfNeverRequired()) {
            return 'You’ve said HMRC told you you’re exempt from Making Tax Digital from '.$from.' and treat the earlier requirements as never having applied, so you’re on the rules for people not in it for every year.';
        }

        return $from <= PenaltyEstimator::rules()['regimes']['new_regime_from']['mtd']
            ? 'You’ve said HMRC told you you’re exempt from Making Tax Digital from '.$from.', so the current penalty rules apply to 2026-27 (GOV.UK: becoming exempt during 2026-27 takes you back to them).'
            : 'You’ve said HMRC told you you’re exempt from Making Tax Digital from '.$from.'. If you were in it in 2026-27, the new penalty rules still apply to you by law for 2026-27 and later years; from '.$from.' you send no quarterly updates, so your points threshold is 2 and any points you have go down by 2.';
    }

    public static function qualifyingIncome(string $label, ?Closure $summary = null): int
    {
        $s = $summary ? $summary($label) : ReturnSummary::for($label);

        return (int) (($s['turnover_pence'] ?? 0) + ($s['other_income_pence'] ?? 0));
    }

    public static function text(string $regime, string $label, ?Closure $summary = null): string
    {
        return match ($regime) {
            self::LAW => 'New rules: points for a late return and the new late payment penalties (Finance Act 2021 Schedules 24 and 26), which apply to you by law '
                .(self::volunteerFrom() !== null && $label >= self::volunteerFrom() ? 'as an accepted Making Tax Digital volunteer'
                    : (self::exemptFrom() !== null ? 'because you were in Making Tax Digital from 6 April 2026 (they stay after a later exemption)' : 'because you’re in Making Tax Digital from 6 April 2026')),
            self::ANNOUNCED => 'Current rules (Finance Act 2009 Schedules 55 and 56), which are still the law for '.$label.'. New rules are announced for everyone from 2027-28 but aren’t law yet; their figures are shown separately as an estimate',
            default => 'Current rules (Finance Act 2009 Schedules 55 and 56)',
        };
    }
}
