<?php

namespace App\Domain\Tax;

use App\Support\Months;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;

/**
 * Late submission points (FA 2021 Sch 24), counted over all of a person's returns and quarterly updates under the
 * new rules, day by day in date order.
 *
 *  - A submission not made by its due date fails on the day after (para 3(10)) and makes you liable to a point: at
 *    most one a calendar month for each kind of submission (the return; the quarterly updates) (para 5(3)), and none
 *    while you're at the threshold (para 5(8)). The threshold is 4 if you're required to send quarterly updates, 2
 *    otherwise, volunteers included (para 5(9)).
 *  - £200 when a point takes you to the threshold, and for each later failure while you're at it, at most one a
 *    calendar month for each kind of submission (para 15).
 *  - A point expires at the end of the 24 months beginning with the 1st of the month after its failure, unless you're
 *    at the threshold just before then: then it stays until all your points go (para 7).
 *  - All points go at the start of the first day on which (A) everything was on time for 12 months (24 without
 *    quarterly updates), counted from the 1st of the month after the last failure, and (B) everything due in the 24
 *    months before that day has been sent, on time or late (para 8). A failure with an excuse HMRC accepted earns
 *    nothing and counts as on time (paras 8(7) and 19).
 *  - Starting quarterly updates adds 2 points, stopping takes 2 off; none stays none (para 10). Points taken off are
 *    the oldest (para 11); points added go with the latest (para 12). Condition A then counts from the 1st of the
 *    month after the move, until the next failure (para 13).
 *
 * Who gets no point at all is decided by the caller: a late quarterly update in your first year in Making Tax Digital
 * is passed with eligible = false (HMRC's easement, which still charges £200 at the threshold); an accepted
 * volunteer's quarterly updates aren't passed at all. Submissions that only matter for condition B (returns under the
 * old rules) are passed with counts = false.
 */
final class LateSubmissionPoints
{
    /**
     * Each obligation: made means sent on a date not recorded (taken as on time); counts is false for one that only
     * matters for condition B.
     *
     * @param  list<array{key:string, kind:string, due:CarbonImmutable, submitted:?CarbonImmutable, made:bool, excused:bool, eligible:bool, counts:bool, reason:?string}>  $obligations
     * @param  array<string, string>  $groups  tax year => '1A' (yearly return only) or '1B' (quarterly updates too), for the years under the new rules
     * @param  array  $rules  penalties.json "points"
     * @return array{group:?string, threshold:int, held:int, points:list<array>, reset_on:?string, reset_needs:list<string>,
     *     events:array<string, array>, history:list<array{on:string, text:string}>}
     */
    public static function run(array $obligations, array $groups, CarbonImmutable $asOf, array $rules): array
    {
        $asOf = $asOf->startOfDay();
        $max = ['1A' => (int) ($rules['threshold_annual'] ?? 2), '1B' => (int) ($rules['threshold_quarterly'] ?? 4)];
        $resetMonths = ['1A' => (int) ($rules['reset_months']['annual_group_1A'] ?? 24), '1B' => (int) ($rules['reset_months']['quarterly_group_1B'] ?? 12)];
        $life = (int) ($rules['point_life_months'] ?? 24);
        $fine = (int) ($rules['penalty_pence'] ?? 20_000);
        ksort($groups);
        // The group changes on 6 April of a year in a different group.
        $moves = [];
        foreach ($groups as $label => $g) {
            $moves[UkTaxYear::fromLabel($label)->startsOn()->format('Y-m-d')] = $g;
        }
        $groupOn = function (CarbonImmutable $d) use ($moves, $groups): ?string {
            $g = $groups ? reset($groups) : null;
            foreach ($moves as $on => $mg) {
                if ($on <= $d->format('Y-m-d')) {
                    $g = $mg;
                }
            }

            return $g;
        };

        // What happened to each submission, and the failures to count, by day.
        $events = [];
        $byDay = [];
        foreach ($obligations as $o) {
            $state = self::state($o, $asOf);
            $failure = in_array($state, ['late', 'not_sent', 'not_due'], true) ? $o['due']->startOfDay()->addDay() : null;
            $events[$o['key']] = ['failure_on' => $failure?->format('Y-m-d'), 'state' => $state, 'projected' => $failure !== null && $failure->gt($asOf),
                'point' => false, 'held' => null, 'threshold' => null, 'penalty_pence' => 0, 'note' => null, 'expires_on' => null, 'counts' => $o['counts']];
            if ($failure === null || ! $o['counts']) {
                continue;
            }
            if ($o['excused']) {
                $events[$o['key']]['state'] = 'excused';
                $events[$o['key']]['note'] = 'HMRC accepted your reasonable excuse: no point, and it counts as on time.';

                continue;
            }
            $byDay[$failure->format('Y-m-d')][] = $o;
        }
        ksort($byDay);

        $group = $groupOn($asOf);
        $points = [];      // live points: key, failure_on, expires_on, stays (blocked at the threshold), added (para 12)
        $pointed = [];     // kind|Y-m of the failure => the point given that month
        $charged = [];     // kind|Y-m => £200 charged that month
        $last = null;      // the latest failure (excused ones count as on time)
        $moved = null;     // [day of the move, start of condition A after it]
        $history = [];
        $snapshot = null;
        $what = fn (string $kind) => $kind === 'annual' ? 'return' : 'quarterly update';

        if ($byDay !== []) {
            $first = CarbonImmutable::parse(array_key_first($byDay));
            $lastDay = CarbonImmutable::parse(array_key_last($byDay));
            $horizon = $lastDay->gt($asOf) ? $lastDay : $asOf;
            $group = $groupOn($first);
            for ($d = $first; $d->lte($horizon); $d = $d->addDay()) {
                $day = $d->format('Y-m-d');
                $actual = $d->lte($asOf);
                // At the start of the day: all points go once both conditions are met (para 8).
                if ($points !== [] && $last !== null && $d->gte(self::conditionAMet($last, $moved, $resetMonths[$group])) && self::conditionB($obligations, $d)) {
                    if ($actual) {
                        $history[] = ['on' => $day, 'text' => 'All '.count($points).' points went: everything was on time for '.$resetMonths[$group].' months and nothing due in the 24 months before was outstanding.'];
                    }
                    $points = [];
                }
                // 6 April: moving into (or out of) quarterly updates adds (or takes off) 2 points (para 10).
                if (isset($moves[$day]) && $moves[$day] !== $group) {
                    $from = $group;
                    $group = $moves[$day];
                    if ($points !== []) {
                        $before = count($points);
                        if ($max[$group] > $max[$from]) {
                            // Added points count as given for the latest failure, so they go with its point (para 12).
                            $latest = end($points);
                            array_push($points, ['added' => true] + $latest, ['added' => true] + $latest);
                        } else {
                            $points = array_slice($points, min(2, count($points)));
                        }
                        $moved = [$d, Months::add($d->startOfMonth(), 1)];
                        if ($actual) {
                            $history[] = ['on' => $day, 'text' => ($group === '1B' ? 'Starting quarterly updates took your points from ' : 'Stopping quarterly updates took your points from ').$before.' to '.count($points).'.'];
                        }
                    }
                }
                // Failures on the day.
                foreach ($byDay[$day] ?? [] as $o) {
                    $last = $d;
                    $month = $o['kind'].'|'.$d->format('Y-m');
                    $threshold = $max[$group];
                    $e = &$events[$o['key']];
                    $e['threshold'] = $threshold;
                    if (count($points) >= $threshold) {
                        if (! isset($charged[$month])) {
                            $charged[$month] = $o['key'];
                            $e['penalty_pence'] = $fine;
                            $e['note'] = 'You were already at '.$threshold.' points, so no point but £200.';
                        } else {
                            $e['note'] = 'You were already at '.$threshold.' points; the £200 for a late '.$what($o['kind']).' in '.$d->format('F Y').' already covers it.';
                        }
                    } elseif (! $o['eligible']) {
                        $e['note'] = $o['reason'];
                    } elseif (isset($pointed[$month])) {
                        $e['note'] = 'No extra point: one point a month for each kind of submission, already given for '.$d->format('F Y').'.';
                    } else {
                        $expires = Months::periodEnd(Months::add($d->startOfMonth(), 1), $life);
                        $points[] = ['key' => $o['key'], 'failure_on' => $day, 'expires_on' => $expires->format('Y-m-d'), 'stays' => false, 'added' => false];
                        $pointed[$month] = $o['key'];
                        $e['point'] = true;
                        $e['expires_on'] = $expires->format('Y-m-d');
                        if (count($points) >= $threshold && ! isset($charged[$month])) {
                            $charged[$month] = $o['key'];
                            $e['penalty_pence'] = $fine;
                        }
                    }
                    $e['held'] = count($points);
                    if ($actual) {
                        $history[] = ['on' => $day, 'text' => 'Late '.$what($o['kind']).': '.($e['point'] ? 'a point ('.count($points).' of '.$threshold.')' : 'no point')
                            .($e['penalty_pence'] > 0 ? ' and £200' : '').'.'];
                    }
                    unset($e);
                }
                // At the end of the day: points reaching the end of their 24 months expire, unless you're at the
                // threshold just before then (para 7).
                $ending = array_keys(array_filter($points, fn ($p) => ! $p['stays'] && $p['expires_on'] === $day));
                if ($ending !== []) {
                    $atMax = count($points) >= $max[$group];
                    foreach ($ending as $i) {
                        if ($atMax) {
                            $points[$i]['stays'] = true;
                        } else {
                            unset($points[$i]);
                        }
                    }
                    $points = array_values($points);
                    if ($actual) {
                        $history[] = ['on' => $day, 'text' => $atMax
                            ? count($ending).' point'.(count($ending) === 1 ? '' : 's').' reached '.(count($ending) === 1 ? 'its' : 'their').' 24 months while you were at the threshold, so '.(count($ending) === 1 ? 'it stays' : 'they stay').' until all your points go.'
                            : count($ending).' point'.(count($ending) === 1 ? '' : 's').' expired.'];
                    }
                }
                if ($d->eq($asOf)) {
                    $snapshot = [$group, $points, $last, $moved];
                }
            }
        }
        // Where things stand at the end of today (before the first failure, nothing).
        [$group, $points, $last, $moved] = $snapshot ?? [$groupOn($asOf), [], null, null];
        $threshold = $group ? $max[$group] : 0;

        // If you send what's outstanding today and everything else on time: when all your points go.
        $resetOn = null;
        $needs = [];
        if ($points !== [] && $last !== null) {
            $from = self::conditionAMet($last, $moved, $resetMonths[$group]);
            $resetOn = $from->gt($asOf) ? $from : $asOf->addDay();
            foreach ($obligations as $o) {
                $state = self::state($o, $asOf);
                if ($state === 'not_sent' && $o['due']->gte(Months::add($resetOn, -24))) {
                    $needs[] = $o['key'];
                }
            }
        }
        // At the threshold no point can expire on its own (each would reach its end at the threshold), so they all go
        // together; below it, each goes at the end of its 24 months, or with the rest if that's sooner.
        $atMax = $points !== [] && count($points) >= $threshold;
        foreach ($points as &$p) {
            $p['stays'] = $p['stays'] || $atMax;
            $ownEnd = CarbonImmutable::parse($p['expires_on'])->addDay();
            $p['goes_on'] = ($p['stays'] || ($resetOn !== null && $resetOn->lte($ownEnd)) ? $resetOn : $ownEnd)?->format('Y-m-d');
        }
        unset($p);

        return ['group' => $group, 'threshold' => $threshold, 'held' => count($points), 'points' => $points,
            'reset_on' => $resetOn?->format('Y-m-d'), 'reset_needs' => $needs, 'events' => $events, 'history' => $history];
    }

    /** on_time, late (sent after the due date), not_sent (due date passed), not_due (not sent, not due yet). */
    public static function state(array $o, CarbonImmutable $asOf): string
    {
        if ($o['made'] && $o['submitted'] === null) {
            return 'on_time';
        }
        if ($o['submitted'] !== null) {
            return $o['submitted']->startOfDay()->lte($o['due']->startOfDay()) ? 'on_time' : 'late';
        }

        return $o['due']->startOfDay()->lt($asOf->startOfDay()) ? 'not_sent' : 'not_due';
    }

    /**
     * The first day condition A can be met: the end of 12 (or 24) months beginning with the 1st of the month after
     * the last failure (para 8(2)-(3)), or after a move between groups with no failure since, the 1st of the month
     * after the move (para 13).
     */
    private static function conditionAMet(CarbonImmutable $last, ?array $moved, int $months): CarbonImmutable
    {
        $start = Months::add($last->startOfMonth(), 1);
        if ($moved !== null && $last->lt($moved[0]) && $moved[1]->gt($start)) {
            $start = $moved[1];
        }

        return Months::after($start, $months);
    }

    /** Condition B at the start of $day: everything due in the 24 months ending the day before has been sent (para 8(4)). */
    private static function conditionB(array $obligations, CarbonImmutable $day): bool
    {
        $from = Months::add($day, -24);
        $before = $day->subDay();
        foreach ($obligations as $o) {
            $due = $o['due']->startOfDay();
            if ($due->lt($from) || $due->gt($before)) {
                continue;
            }
            $sent = ($o['made'] && $o['submitted'] === null) || ($o['submitted'] !== null && $o['submitted']->startOfDay()->lte($before));
            if (! $sent) {
                return false;
            }
        }

        return true;
    }
}
