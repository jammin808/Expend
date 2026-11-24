<?php

namespace App\Domain\Mileage;

use App\Domain\Intel\Vetoes;
use App\Domain\Travel\Places;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Mileage from your jobs: a return trip from home to each venue you drove to. The distance is an estimate (the
 * straight line between postcodes, times 1.25 for roads), so each trip says so and can be corrected. Left out:
 * jobs reached by train, plane, coach or hire car (a hire car's cost and fuel are claimed instead, never mileage on
 * top), jobs with mileage already, jobs without a venue postcode, jobs whose last day is still to come, days in a
 * locked tax year (a locked year's figures never change), and jobs or days you said you didn't drive to (the Mileage
 * page's "Didn't drive", or "Not relevant" on the to-do list's mileage: Intel\Vetoes::notDriven()).
 */
final class JobTrips
{
    public const ROAD_FACTOR = 1.25;

    /**
     * Journeys that mean you didn't drive your own vehicle to the job: no mileage to claim. The to-do list's mileage
     * items use the same list and the same rule (journeys()).
     */
    public const JOURNEYS = ['rail', 'flight', 'coach', 'car_hire'];

    /**
     * How you reached these jobs other than in your own vehicle (JOURNEYS: train, plane, coach or hire car), by the
     * rule proposals() leaves a job out by: a journey linked to the job, or not linked to any job and within a day of
     * a working day (reached()), not cancelled and not marked personal (usable()). For many jobs in one query: the
     * to-do list's mileage items (Intel\Detectors\TravelUnclaimed) use this, so the two never disagree.
     *
     * @param  Collection<int, WorkJob>  $jobs  by id, with their days
     * @return array<int, list<string>> the kinds of journey, by job id (jobs you may have driven to aren't in it)
     */
    public static function journeys(Collection $jobs): array
    {
        $dates = $jobs->map(fn (WorkJob $j) => $j->workDates());
        $all = $dates->flatten()->sort()->values();
        if ($all->isEmpty()) {
            return [];
        }
        $journeys = self::usable(TravelItem::query())->whereIn('kind', self::JOURNEYS)
            ->where(fn ($q) => $q->whereIn('work_job_id', $jobs->keys()->all())->orWhere(fn ($q) => $q->whereNull('work_job_id')
                ->whereDate('starts_on', '>=', CarbonImmutable::parse($all->first())->subDay()->format('Y-m-d'))
                ->whereDate('starts_on', '<=', CarbonImmutable::parse($all->last())->addDay()->format('Y-m-d'))))
            ->get(['kind', 'work_job_id', 'starts_on']);
        $out = [];
        foreach ($jobs as $id => $j) {
            $near = self::near($dates[$id]);
            $kinds = $journeys->filter(fn (TravelItem $i) => self::reached($i, (int) $id, $near))->pluck('kind')->unique()->values()->all();
            if ($kinds !== []) {
                $out[$id] = $kinds;
            }
        }

        return $out;
    }

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
        // Jobs (or days) you said you didn't drive to: never offered again until you put them back.
        $notDriven = Vetoes::notDriven();
        $out = [];
        foreach ($jobs as $job) {
            if (isset($notDriven['jobs'][$job->id])) {
                continue;
            }
            $dates = $job->workDates();
            // Offered once the last day is done: a trip is never dated in the future, and adding the first days of
            // a job that's still under way would stop its later days (a de-rig to come) from ever being offered.
            if ($dates === [] || end($dates) > $today) {
                continue;
            }
            if (isset($notDriven['days'][$job->id])) {
                $dates = array_values(array_filter($dates, fn ($d) => ! isset($notDriven['days'][$job->id][$d])));
                if ($dates === []) {
                    continue;
                }
            }
            $travel = self::travelFor($job, $dates);
            if ($travel['journey']) {
                continue; // went by train, plane, coach or hire car
            }
            $straight = Places::miles($home, $job->postcode);
            if ($straight === null || $straight < 1) {
                continue;
            }
            $oneWay = round($straight * self::ROAD_FACTOR, 1);
            $days = count($dates);
            // A run of consecutive days you stayed nearby for is one return trip; a run without a stay is a return
            // trip each day (a rig with a hotel, then a de-rig days later driven there and back, is 1 + 1).
            $runs = [];
            foreach ($dates as $d) {
                $last = end($runs);
                if ($last && CarbonImmutable::parse($last[count($last) - 1])->addDay()->format('Y-m-d') === $d) {
                    $runs[count($runs) - 1][] = $d;
                } else {
                    $runs[] = [$d];
                }
            }
            $stayedRuns = array_values(array_filter($runs, fn ($r) => (bool) array_intersect($r, $travel['woke'])));
            $stayed = $stayedRuns !== [];
            $allDates = array_merge(...array_map(fn ($r) => in_array($r, $stayedRuns, true) ? [$r[0]] : $r, $runs));
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
                    $stayed && count($stayedRuns) < count($runs) => count($allDates).' return trips: one for each visit you stayed nearby for, and one for each day you drove there and back',
                    $stayed && count($runs) > 1 => count($runs).' separate visits, staying nearby: a return trip for each',
                    $stayed => 'One return trip; you stayed nearby',
                    $days > 1 => "{$days} days without a stay: a return trip each day",
                    default => 'One return trip',
                }.($lockedOut > 0 ? " ({$lockedOut} in a locked tax year left out)" : ''),
            ];
        }

        return $out;
    }

    /**
     * The jobs with days in this year that you said you didn't drive to (the Mileage page's "Didn't drive", or "Not
     * relevant" on the to-do list's mileage), so the page can list them with "Put back".
     *
     * @return list<array{job_id:int, title:string, starts_on:string, postcode:?string}>
     */
    public static function leftOut(string $taxYear): array
    {
        $jobs = array_keys(Vetoes::notDriven()['jobs']);
        if ($jobs === []) {
            return [];
        }
        $year = UkTaxYear::fromLabel($taxYear);

        return WorkJob::query()->whereIn('id', $jobs)->whereDate('starts_on', '<=', $year->endsOn()->format('Y-m-d'))->whereDate('ends_on', '>=', $year->startsOn()->format('Y-m-d'))
            ->orderBy('starts_on')->get(['id', 'title', 'starts_on', 'postcode'])
            ->map(fn (WorkJob $j) => ['job_id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'), 'postcode' => $j->postcode])->all();
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
     * when it fits the job's days. A train, plane, coach or hire car counts when it's unlinked and within a day of a
     * working day; one linked to another job was the way to that job. A hotel counts when you woke up there on a
     * working day, whichever job it's linked to: one stay can cover back-to-back jobs, but it's linked to only one.
     * A night after the last of a run of days doesn't count: that's the evening you moved on to the next job, or a
     * stay before driving home, and it saves none of the drives in on the days before. Travel you marked personal
     * wasn't for work, so it never counts.
     *
     * @param  list<string>  $dates  the job's working days
     * @return array{journey:bool, woke:list<string>} whether you went by train, plane, coach or hire car, and the
     *                                                working days you woke up at a hotel on
     */
    private static function travelFor(WorkJob $job, array $dates): array
    {
        $from = CarbonImmutable::parse($dates[0])->subDay()->format('Y-m-d');
        $to = CarbonImmutable::parse(end($dates))->addDay()->format('Y-m-d');
        /** @var Collection<int, TravelItem> $items */
        $items = self::usable(TravelItem::query())
            ->where(fn ($q) => $q->where('work_job_id', $job->id)->orWhere(fn ($q) => $q
                ->whereDate('starts_on', '<=', $to)
                ->where(fn ($q) => $q->whereDate('starts_on', '>=', $from)->orWhereDate('ends_on', '>=', $from))))
            ->get();

        $near = self::near($dates);
        $fits = function (TravelItem $item) use ($job, $dates, $near): bool {
            if ($item->kind !== 'hotel' || (int) $item->work_job_id === $job->id) {
                return self::reached($item, $job->id, $near);
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

        $woke = $relevant->where('kind', 'hotel')->flatMap(fn (TravelItem $h) => array_map(fn ($n) => CarbonImmutable::parse($n)->addDay()->format('Y-m-d'), $h->nights()))
            ->filter(fn ($d) => in_array($d, $dates, true))->unique()->values()->all();

        return [
            'journey' => $relevant->whereIn('kind', self::JOURNEYS)->isNotEmpty(),
            'woke' => $woke,
        ];
    }

    /** Travel that can say how you got to a job: not cancelled, and not marked personal (that wasn't for work). */
    private static function usable(Builder $query): Builder
    {
        return $query->where('status', '!=', 'cancelled')->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'personal'));
    }

    /**
     * Whether a train, plane, coach or hire car (or any travel linked to the job) is how you got to this job: linked
     * to it, or linked to no job and starting within a day of one of its working days ($near). One linked to another
     * job was the way to that job.
     *
     * @param  array<string, true>  $near  near()'s
     */
    private static function reached(TravelItem $item, int $jobId, array $near): bool
    {
        return (int) $item->work_job_id === $jobId || ($item->work_job_id === null && isset($near[$item->starts_on->format('Y-m-d')]));
    }

    /**
     * Each working day, and the days either side of it.
     *
     * @param  list<string>  $dates
     * @return array<string, true>
     */
    private static function near(array $dates): array
    {
        $near = [];
        foreach ($dates as $d) {
            $day = CarbonImmutable::parse($d);
            $near[$day->subDay()->format('Y-m-d')] = true;
            $near[$d] = true;
            $near[$day->addDay()->format('Y-m-d')] = true;
        }

        return $near;
    }
}
