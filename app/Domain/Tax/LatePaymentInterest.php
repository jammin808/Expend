<?php

namespace App\Domain\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Late payment interest on one amount (FA 2009 s101), worked out the way HMRC's own published example is: £1,000 due
 * 31 January 2017 and paid 31 March 2020 comes to £96.89 (GOV.UK Interest rate review, 3 December 2020, para 2.14).
 *
 *  - Interest days are each day after the due date up to and including the end day (the day it's paid, or today), so
 *    days = end − due. The due date carries none; the payment day does.
 *  - Each day takes the rate in force that day; a new rate applies from its "from" date, to interest already running.
 *  - A part payment lowers the balance from the day after it's made.
 *  - Each day counts as 1/365 of a year, or 1/366 if it falls in a leap year.
 *  - Consecutive days with the same balance, rate and year length make one segment, so segments split at rate
 *    changes, part payments, and only those 1 Januaries where the year length changes (2024, 2025, 2028, 2029; 2020
 *    and 2021 in the example). Each segment is balance × rate × days ÷ 365 (or 366), rounded down to the penny, and
 *    the segments are added up.
 *
 * No law fixes the year length or the rounding. HMRC's debt manual (DMBM404010) describes a flat ÷366, which doesn't
 * give their published £96.89, so this follows the published example. The result is an estimate either way (about a
 * penny a segment): HMRC's statement is the final word.
 */
final class LatePaymentInterest
{
    /**
     * @param  int  $amount  pence due on $due
     * @param  list<array{date:\DateTimeInterface, amount:int}>  $paid  payments towards it, in any order (one on or before the due date lowers it from the start)
     * @param  list<array{from:string|\DateTimeInterface, pct:string}>  $rates  the rate table, in any order: each rate applies from its date until the next
     * @return array{pence:int, days:int, segments:list<array{from:string, to:string, days:int, balance_pence:int, pct:string, divisor:int, pence:int}>}
     */
    public static function calculate(int $amount, CarbonImmutable $due, CarbonImmutable $to, array $paid, array $rates): array
    {
        $due = $due->startOfDay();
        $to = $to->startOfDay();
        $none = ['pence' => 0, 'days' => max(0, self::days($due, $to)), 'segments' => []];
        if ($amount <= 0 || $to->lte($due)) {
            return $none;
        }
        $rates = array_map(fn ($r) => [CarbonImmutable::parse($r['from'] instanceof \DateTimeInterface ? $r['from']->format('Y-m-d') : $r['from'])->startOfDay(), (string) $r['pct']], $rates);
        usort($rates, fn ($a, $b) => $a[0] <=> $b[0]);
        $paid = array_map(fn ($p) => [CarbonImmutable::parse($p['date']->format('Y-m-d')), (int) $p['amount']], $paid);

        // The last day of each run: the day before a new rate, a payment day, the 31 December before a year of a
        // different length, and the end day.
        $ends = [$to->format('Y-m-d') => $to];
        $inside = fn (CarbonImmutable $d) => $d->gt($due) && $d->lt($to);
        foreach ($rates as [$from]) {
            if ($inside($from->subDay())) {
                $ends[$from->subDay()->format('Y-m-d')] = $from->subDay();
            }
        }
        foreach ($paid as [$on]) {
            if ($inside($on)) {
                $ends[$on->format('Y-m-d')] = $on;
            }
        }
        for ($year = (int) $due->format('Y') + 1; $year <= (int) $to->format('Y'); $year++) {
            $eve = CarbonImmutable::create($year - 1, 12, 31);
            if (self::divisor($eve) !== self::divisor($eve->addDay()) && $inside($eve)) {
                $ends[$eve->format('Y-m-d')] = $eve;
            }
        }
        ksort($ends);

        // Each run covers the days after $start up to and including $end, at the balance left at the end of $start.
        $runs = [];
        $start = $due;
        foreach ($ends as $end) {
            $first = $start->addDay();
            $balance = max(0, $amount - array_sum(array_map(fn ($p) => $p[0]->lte($start) ? $p[1] : 0, $paid)));
            $pct = self::rateOn($rates, $first);
            $run = ['from' => $first, 'to' => $end, 'days' => self::days($start, $end), 'balance_pence' => $balance, 'pct' => $pct, 'divisor' => self::divisor($first)];
            $last = $runs ? $runs[count($runs) - 1] : null;
            if ($last && $last['balance_pence'] === $balance && $last['divisor'] === $run['divisor'] && BigDecimal::of($last['pct'])->isEqualTo($pct)) {
                $runs[count($runs) - 1]['to'] = $end;
                $runs[count($runs) - 1]['days'] += $run['days'];
            } else {
                $runs[] = $run;
            }
            $start = $end;
        }

        $segments = [];
        $total = 0;
        foreach ($runs as $r) {
            if ($r['balance_pence'] <= 0 || BigDecimal::of($r['pct'])->isZero() || $r['days'] <= 0) {
                continue;
            }
            $pence = BigDecimal::of($r['balance_pence'])->multipliedBy($r['pct'])->multipliedBy($r['days'])
                ->dividedBy(100 * $r['divisor'], 0, RoundingMode::DOWN)->toInt();
            $total += $pence;
            $segments[] = ['from' => $r['from']->format('Y-m-d'), 'to' => $r['to']->format('Y-m-d')] + array_diff_key($r, ['from' => 0, 'to' => 0]) + ['pence' => $pence];
        }

        return ['pence' => $total, 'days' => self::days($due, $to), 'segments' => $segments];
    }

    /** The year length a day's interest is divided by: 366 in a leap year, 365 otherwise. */
    public static function divisor(CarbonImmutable $day): int
    {
        return $day->isLeapYear() ? 366 : 365;
    }

    /** @param  list<array{0:CarbonImmutable, 1:string}>  $rates  sorted by date */
    private static function rateOn(array $rates, CarbonImmutable $day): string
    {
        $pct = '0';
        foreach ($rates as [$from, $rate]) {
            if ($from->lte($day)) {
                $pct = $rate;
            }
        }

        return $pct;
    }

    /** Whole days from one date to another (rounded, so a clock change can't lose a day). */
    private static function days(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round($from->diffInDays($to, false));
    }
}
