<?php

namespace App\Domain\Mileage;

use App\Domain\Travel\Places;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;

/**
 * Mileage from your jobs: a return trip from home to each venue you drove to. The distance is an estimate (the
 * straight line between postcodes, times 1.25 for roads), so each trip says so and can be corrected. Jobs reached by
 * train, plane or coach, jobs with mileage already, and jobs without a venue postcode are left out.
 */
final class JobTrips
{
    public const ROAD_FACTOR = 1.25;

    /** @return list<array{job_id:int, title:string, starts_on:string, ends_on:string, venue:?string, postcode:string, one_way:float, trips:int, miles:float, why:string}> */
    public static function proposals(string $taxYear): array
    {
        $home = Setting::get('home_postcode');
        if (! $home) {
            return [];
        }
        $year = UkTaxYear::fromLabel($taxYear);
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled')->whereNotNull('postcode')
            ->whereDate('starts_on', '>=', $year->startsOn()->format('Y-m-d'))->whereDate('starts_on', '<=', $year->endsOn()->format('Y-m-d'))
            ->whereDoesntHave('mileageTrips')->orderBy('starts_on')->get();
        $out = [];
        foreach ($jobs as $job) {
            $from = CarbonImmutable::parse($job->starts_on->format('Y-m-d'))->subDay()->format('Y-m-d');
            $to = CarbonImmutable::parse($job->ends_on->format('Y-m-d'))->addDay()->format('Y-m-d');
            $travel = TravelItem::query()->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $q->where('work_job_id', $job->id)->orWhere(fn ($q) => $q->whereDate('starts_on', '>=', $from)->whereDate('starts_on', '<=', $to)))->get();
            if ($travel->whereIn('kind', ['rail', 'flight', 'coach'])->isNotEmpty()) {
                continue; // went by train, plane or coach
            }
            $straight = Places::miles($home, $job->postcode);
            if ($straight === null || $straight < 1) {
                continue;
            }
            $oneWay = round($straight * self::ROAD_FACTOR, 1);
            $dates = $job->workDates();
            $days = count($dates);
            $stayed = $travel->where('kind', 'hotel')->isNotEmpty();
            // With a stay, one return trip per run of consecutive days (a rig, then a de-rig days later, is two runs).
            $runs = [];
            foreach ($dates as $d) {
                $last = end($runs);
                if ($last && CarbonImmutable::parse($last[count($last) - 1])->addDay()->format('Y-m-d') === $d) {
                    $runs[count($runs) - 1][] = $d;
                } else {
                    $runs[] = [$d];
                }
            }
            $tripDates = $stayed ? array_map(fn ($r) => $r[0], $runs) : $dates;
            $trips = count($tripDates);
            $out[] = [
                'job_id' => $job->id, 'title' => $job->title, 'starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d'),
                'venue' => $job->location, 'postcode' => $job->postcode, 'one_way' => $oneWay, 'trips' => $trips, 'miles' => round($oneWay * 2 * $trips, 1),
                'trip_dates' => $tripDates,
                'why' => match (true) {
                    $stayed && count($runs) > 1 => count($runs).' separate visits, staying nearby: a return trip for each',
                    $stayed => 'One return trip; you stayed nearby',
                    $trips > 1 => "{$days} days without a stay: a return trip each day",
                    default => 'One return trip',
                },
            ];
        }

        return $out;
    }

    /** Add the trips for the jobs you ticked. @return int trips added */
    public static function add(string $taxYear, int $vehicleId, array $jobIds): int
    {
        $added = 0;
        foreach (self::proposals($taxYear) as $p) {
            if (! in_array($p['job_id'], $jobIds, true)) {
                continue;
            }
            foreach ($p['trip_dates'] as $date) {
                $day = CarbonImmutable::parse($date);
                MileageTrip::query()->create([
                    'vehicle_id' => $vehicleId, 'work_job_id' => $p['job_id'], 'trip_on' => $day->format('Y-m-d'), 'tax_year' => UkTaxYear::fromDate($day)->label(),
                    'from_place' => 'Home', 'to_place' => trim(($p['venue'] ?? '').' '.$p['postcode']), 'miles' => number_format($p['one_way'] * 2, 1, '.', ''),
                    'purpose' => 'Return trip to '.($p['venue'] ?: $p['postcode']).' for '.$p['title'].' (estimated from postcodes: check against your route)',
                ]);
                $added++;
            }
        }

        return $added;
    }
}
