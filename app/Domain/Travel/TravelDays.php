<?php

namespace App\Domain\Travel;

use App\Domain\Jobs\JobDays;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;

/**
 * Lines up a night or a travel day against your jobs, and says business, personal or "needs review", with
 * the reason. The rules and their defaults are in Settings › Ledger:
 *
 *   - a night between two working days is business;
 *   - the night before the first day or after the last depends on distance from home and the job's times:
 *     under 12 miles needs review, 12–40 miles is business with an early start (night before) or late
 *     finish (night after) and otherwise needs review, over 40 miles is business;
 *   - a travel day is business on a working day, and on the day before or after when a stay or the
 *     distance makes it a travel day; days with no job are personal.
 */
final class TravelDays
{
    /** @return array{verdict:string, job:?WorkJob, reason:string} */
    public function night(string $date): array
    {
        $n = CarbonImmutable::parse($date);
        $next = $n->addDay();
        $jobs = $this->jobsAround($n);

        [$tonight, $tomorrow] = [$n->format('Y-m-d'), $next->format('Y-m-d')];
        foreach ($jobs as $job) {
            if ($job->worksOn($tonight) && $job->worksOn($tomorrow)) {
                return $this->result('business', $job, 'between two working days of '.$this->name($job));
            }
        }
        foreach ($jobs as $job) {
            if ($job->worksOn($tomorrow)) {
                return $this->edge($job, 'before', $tomorrow);
            }
        }
        foreach ($jobs as $job) {
            if ($job->worksOn($tonight)) {
                return $this->edge($job, 'after', $tonight);
            }
        }

        return $this->result('personal', null, 'no job that day or the next');
    }

    /** @return array{verdict:string, job:?WorkJob, reason:string} */
    public function day(string $date): array
    {
        $d = CarbonImmutable::parse($date);
        $jobs = $this->jobsAround($d);

        foreach ($jobs as $job) {
            if ($job->worksOn($d->format('Y-m-d'))) {
                $kind = $job->dayOn($d->format('Y-m-d'))?->kind;

                return $this->result('business', $job, 'on '.($kind && $kind !== 'work' ? 'the '.mb_strtolower(JobDay::KINDS[$kind]).' day' : 'a working day').' of '.$this->name($job));
            }
        }
        foreach ($jobs as $job) {
            $side = $job->worksOn($d->addDay()->format('Y-m-d')) ? 'before' : ($job->worksOn($d->subDay()->format('Y-m-d')) ? 'after' : null);
            if (! $side) {
                continue;
            }
            $stayed = TravelItem::query()->where('kind', 'hotel')->where('work_job_id', $job->id)->where('status', '!=', 'cancelled')
                ->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'personal'))->exists();
            $miles = $this->milesFromHome($job);
            if ($stayed || ($miles !== null && $miles > $this->far())) {
                return $this->result('business', $job, "the day {$side} ".$this->name($job).($stayed ? ', with a hotel stay for it' : ", {$miles} miles from home"));
            }

            return $this->result('review', $job, "the day {$side} ".$this->name($job).': business if it was travel for the job');
        }

        return $this->result('personal', null, 'no job that day');
    }

    public function milesFromHome(WorkJob $job): ?float
    {
        return Places::miles(Setting::get('home_postcode'), $job->postcode);
    }

    /** Night before the first day or after the last: distance from home plus the job's start or finish time. */
    private function edge(WorkJob $job, string $side, ?string $workDay = null): array
    {
        $day = $workDay ? $job->dayOn($workDay) : null;
        $miles = $this->milesFromHome($job);
        $label = "the night {$side} ".$this->name($job);
        if ($miles === null) {
            return $this->result('review', $job, $label.'. Distance unknown: add your home postcode in Settings › Ledger and the job’s postcode');
        }
        if ($miles > $this->far()) {
            return $this->result('business', $job, "{$label}, {$miles} miles from home");
        }
        if ($miles < $this->near()) {
            return $this->result('review', $job, "{$label}, only {$miles} miles from home: unusual to claim");
        }

        $time = $side === 'before' ? $this->time($day?->start_time ?? $job->start_time) : $this->time($day?->end_time ?? $job->end_time);
        $limit = $side === 'before' ? (string) Setting::get('early_start') : (string) Setting::get('late_finish');
        // A finish in the small hours is later than any evening limit when the work ran past midnight (a gig from 19:00
        // to 01:30), not when it started after midnight (a de-rig from 00:00 to 04:00) or its start isn't known: then
        // it's yours to decide. The start goes with the finish: the day's own, or the job's when the day has no finish
        // of its own (on a job of several days, the job's start is its first day's, not this one's).
        $start = $this->time($day?->end_time !== null
            ? ($day->start_time ?? (count($job->workDates()) === 1 ? $job->start_time : null))
            : $job->start_time);
        $late = $side === 'after' && $time && ($time > $limit || ($time < JobDays::OVERNIGHT_UNTIL && $start !== null && $start > $time));
        if ($time && ($side === 'before' ? $time < $limit : $late)) {
            return $this->result('business', $job, "{$label}, {$miles} miles from home with ".($side === 'before' ? "an early start ({$time})" : "a late finish ({$time})"));
        }

        return $this->result('review', $job, "{$label}, {$miles} miles from home".($time ? " ({$time} ".($side === 'before' ? 'start' : 'finish').')' : '')
            .': business if traffic or the hours made a stay necessary');
    }

    private function jobsAround(CarbonImmutable $day)
    {
        return WorkJob::query()->where('status', '!=', 'cancelled')
            ->whereDate('starts_on', '<=', $day->addDays(2)->format('Y-m-d'))
            ->whereDate('ends_on', '>=', $day->subDays(2)->format('Y-m-d'))
            ->with('days')->orderBy('starts_on')->get();
    }

    private function result(string $verdict, ?WorkJob $job, string $reason): array
    {
        return ['verdict' => $verdict, 'job' => $job, 'reason' => $reason];
    }

    private function name(WorkJob $job): string
    {
        return $job->title.' (job '.$job->id.')';
    }

    private function time(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    private function near(): float
    {
        return (float) Setting::get('overnight_near_miles');
    }

    private function far(): float
    {
        return (float) Setting::get('overnight_far_miles');
    }
}
