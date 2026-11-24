<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Calendar months as the tax statutes count them. Carbon's addMonths() overflows (30 November + 3 months is
 * 2 March), which is wrong for every statutory period, so nothing here uses it.
 */
final class Months
{
    /**
     * The last day of "the period of $n months beginning with" $start (the corresponding-date rule): the day before
     * the same day number $n months on, or the last day of that month if it has no such day.
     * 1 Feb + 6 ends 31 Jul; 1 Nov + 3 ends 31 Jan; 30 Nov + 3 ends 28 (or 29) Feb; 31 Aug + 6 ends 28 (or 29) Feb.
     */
    public static function periodEnd(CarbonImmutable $start, int $n): CarbonImmutable
    {
        $start = $start->startOfDay();
        $month = CarbonImmutable::create((int) $start->format('Y'), (int) $start->format('n'), 1)->addMonthsNoOverflow($n);
        $day = (int) $start->format('j');

        return $day <= $month->daysInMonth ? $month->setDay($day)->subDay() : $month->endOfMonth()->startOfDay();
    }

    /** The day after the period of $n months beginning with $start: when something "after the end of" it happens. */
    public static function after(CarbonImmutable $start, int $n): CarbonImmutable
    {
        return self::periodEnd($start, $n)->addDay();
    }

    /** $n calendar months on, kept inside the target month (31 January + 1 month is 28 or 29 February). */
    public static function add(CarbonImmutable $date, int $n): CarbonImmutable
    {
        return $date->startOfDay()->addMonthsNoOverflow($n);
    }
}
