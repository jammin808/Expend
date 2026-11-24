<?php

namespace App\Domain\Mileage;

use App\Domain\Travel\Places;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Mileage from your jobs: a return trip from home to each venue you drove to. The distance is an estimate (the
 * straight line between postcodes, times 1.25 for roads), so each trip says so and can be corrected. Left out:
 * jobs reached by train, plane or coach, jobs with mileage already, jobs without a venue postcode, jobs whose last
 * day is still to come, and days in a locked tax year (a locked year's figures never change).
 */
final class JobTrips
{
    public const ROAD_FACTOR = 1.25;

    /** Journeys that mean you didn't drive. */
    private const JOURNEYS = ['rail', 'flight', 'coach'];

    /** @return list<array{job_id:int, title:string, starts_on:string, ends_on:string, venue:?string, postcode:string, one_way:float, trips:int, miles:float, trip_dates:list<string>, why:string}> */
    public static function proposals(string $taxYear): array
    {
        $home = Setting::get('home_postcode');
        if (! $home) {
            return [];
        }
        $year = UkTaxYear::fromLabel($taxYear);
        $locked = self::lockedYears();
        if (in_array($year->label(), $locked, true)) {
            return []; // nothing can be added to a locked year
        }
        $today = today()->format('Y-m-d');
        // Every job with days in this year. Each is offered on the page of the year that holds its first trip you
        // can still add: usually the year it starts in, but the next year when it began in a locked one (a rig on
        // 4 April in a filed year, a de-rig on 8 April in this one).
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled')->whereNotNull('postcode')
            ->whereDate('starts_on', '<=', $year->endsOn()->format('Y-m-d'))->whereDate('ends_on', '>=', $year->startsOn()->format('Y-m-d'))
            ->whereDoesntHave('mileageTrips')->with('days')->orderBy('starts_on')->get();
        $out = [];
        foreach ($jobs as $job) {
            $dates = $job->workDates();
            // Offered once the last day is done: a trip is never dated in the future, and adding the first days of
            // a job that's still under way would stop its later days (a de-rig to come) from ever being offered.
            if ($dates === [] || end($dates) > $today) {
                continue;
            }
            $travel = self::travelFor($job, $dates);
            if ($travel['journey']) {
                continue; // went by train, plane or coach
            }
            $straight = Places::miles($home, $job->postcode);
            if ($straight === null || $straight < 1) {
                continue;
            }
            $oneWay = round($straight * self::ROAD_FACTOR, 1);
            $days = count($dates);
            $stayed = $travel['stayed'];
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
            $allDates = $stayed ? array_map(fn ($r) => $r[0], $runs) : $dates;
            $tripDates = array_values(array_filter($allDates, fn ($d) => ! in_array(UkTaxYear::fromDate($d)->label(), $locked, true)));
            if ($tripDates === [] || UkTaxYear::fromDate($tripDates[0])->label() !== $year->label()) {
                continue; // nothing left to add, or offered on an earlier year's page
            }
            $trips = count($tripDates);
            $lockedOut = count($allDates) - $trips;
            $out[] = [
                'job_id' => $job->id, 'title' => $job->title, 'starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d'),
                'venue' => $job->location, 'postcode' => $job->postcode, 'one_way' => $oneWay, 'trips' => $trips, 'miles' => round($oneWay * 2 * $trips, 1),
                'trip_dates' => $tripDates,
                'why' => match (true) {
                    $stayed && count($runs) > 1 => count($runs).' separate visits, staying nearby: a return trip for each',
                    $stayed => 'One return trip; you stayed nearby',
                    $days > 1 => "{$days} days without a stay: a return trip each day",
                    default => 'One return trip',
                }.($lockedOut > 0 ? " ({$lockedOut} in a locked tax year left out)" : ''),
            ];
        }

        return $out;
    }

    /** Add the trips for the jobs you ticked. Days in a locked tax year are never added. @return int trips added */
    public static function add(string $taxYear, int $vehicleId, array $jobIds): int
    {
        $locked = self::lockedYears();
        $added = 0;
        foreach (self::proposals($taxYear) as $p) {
            if (! in_array($p['job_id'], $jobIds, true)) {
                continue;
            }
            foreach ($p['trip_dates'] as $date) {
                $day = CarbonImmutable::parse($date);
                $label = UkTaxYear::fromDate($day)->label();
                if (in_array($label, $locked, true)) {
                    continue;
                }
                MileageTrip::query()->create([
                    'vehicle_id' => $vehicleId, 'work_job_id' => $p['job_id'], 'trip_on' => $day->format('Y-m-d'), 'tax_year' => $label,
                    'from_place' => 'Home', 'to_place' => trim(($p['venue'] ?? '').' '.$p['postcode']), 'miles' => number_format($p['one_way'] * 2, 1, '.', ''),
                    'purpose' => 'Return trip to '.($p['venue'] ?: $p['postcode']).' for '.$p['title'].' (estimated from postcodes: check against your route)',
                ]);
                $added++;
            }
        }

        return $added;
    }

    /** @return list<string> */
    private static function lockedYears(): array
    {
        return TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
    }

    /**
     * The travel that says how you got to this job. Travel linked to the job always counts. Other travel counts
     * when it fits the job's days. A train, plane or coach counts when it's unlinked and within a day of a working
     * day; one linked to another job was the way to that job. A hotel counts when you woke up there on a working
     * day, whichever job it's linked to: one stay can cover back-to-back jobs, but it's linked to only one of them.
     * A night after the last of a run of days doesn't count: that's the evening you moved on to the next job, or a
     * stay before driving home, and it saves none of the drives in on the days before. Travel you marked personal
     * wasn't for work, so it never counts.
     *
     * @param  list<string>  $dates  the job's working days
     * @return array{journey:bool, stayed:bool}
     */
    private static function travelFor(WorkJob $job, array $dates): array
    {
        $from = CarbonImmutable::parse($dates[0])->subDay()->format('Y-m-d');
        $to = CarbonImmutable::parse(end($dates))->addDay()->format('Y-m-d');
        /** @var Collection<int, TravelItem> $items */
        $items = TravelItem::query()->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'personal'))
            ->where(fn ($q) => $q->where('work_job_id', $job->id)->orWhere(fn ($q) => $q
                ->whereDate('starts_on', '<=', $to)
                ->where(fn ($q) => $q->whereDate('starts_on', '>=', $from)->orWhereDate('ends_on', '>=', $from))))
            ->get();

        $near = [];
        foreach ($dates as $d) {
            $day = CarbonImmutable::parse($d);
            $near[$day->subDay()->format('Y-m-d')] = true;
            $near[$d] = true;
            $near[$day->addDay()->format('Y-m-d')] = true;
        }
        $fits = function (TravelItem $item) use ($job, $dates, $near): bool {
            if ((int) $item->work_job_id === $job->id) {
                return true;
            }
            if ($item->kind !== 'hotel') {
                return $item->work_job_id === null && isset($near[$item->starts_on->format('Y-m-d')]);
            }
            // A night before a working day: you woke up there on it.
            foreach ($item->nights() as $night) {
                if (in_array(CarbonImmutable::parse($night)->addDay()->format('Y-m-d'), $dates, true)) {
                    return true;
                }
            }

            return false;
        };
        $relevant = $items->filter($fits);

        return [
            'journey' => $relevant->whereIn('kind', self::JOURNEYS)->isNotEmpty(),
            'stayed' => $relevant->where('kind', 'hotel')->isNotEmpty(),
        ];
    }
}
