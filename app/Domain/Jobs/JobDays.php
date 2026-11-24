<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Models\CalendarEvent;
use App\Models\JobDay;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A job's days. A new job gets one for each date from its first to last; linking a calendar entry yourself adds its
 * days (a rig day, a de-rig days later); you can add, change and remove days on the job. The job's first and last
 * dates always follow its days.
 */
final class JobDays
{
    private static bool $syncing = false;

    /** Every date from the job's first to last (up to two months), for a new job. */
    public static function fromRange(WorkJob $job): void
    {
        $d = CarbonImmutable::parse($job->starts_on->format('Y-m-d'));
        $end = CarbonImmutable::parse(($job->ends_on ?? $job->starts_on)->format('Y-m-d'));
        for ($i = 0; $d->lte($end) && $i < 62; $d = $d->addDay(), $i++) {
            JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d->format('Y-m-d')], ['kind' => 'work', 'source' => 'range']);
        }
    }

    /**
     * The first or last date was changed on the job form: days outside the new dates go; a job whose days ran
     * without a break keeps running without one; otherwise the new first and last dates are added.
     */
    public static function rangeChanged(WorkJob $job, string $oldStart, string $oldEnd): void
    {
        if (self::$syncing) {
            return;
        }
        $start = $job->starts_on->format('Y-m-d');
        $end = $job->ends_on->format('Y-m-d');
        $dates = JobDay::query()->where('work_job_id', $job->id)->orderBy('on_date')->pluck('on_date')->map->format('Y-m-d')->all();
        $continuous = $dates && count($dates) === (int) CarbonImmutable::parse($oldStart)->diffInDays(CarbonImmutable::parse($oldEnd)) + 1;
        JobDay::query()->where('work_job_id', $job->id)->where(fn ($q) => $q->whereDate('on_date', '<', $start)->orWhereDate('on_date', '>', $end))->delete();
        if ($continuous || ! $dates) {
            self::fromRange($job);
        } else {
            foreach (array_unique([$start, $end]) as $d) {
                JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d], ['kind' => 'work', 'source' => 'you']);
            }
        }
    }

    /** Add (or update) a day. */
    public static function add(WorkJob $job, string $date, string $kind = 'work', ?string $start = null, ?string $end = null, ?string $calendarKey = null, string $source = 'you', ?string $note = null): JobDay
    {
        $kind = array_key_exists($kind, JobDay::KINDS) ? $kind : 'work';
        $day = JobDay::query()->firstOrNew(['work_job_id' => $job->id, 'on_date' => $date]);
        $fresh = ! $day->exists;
        $day->fill(array_filter(['kind' => $fresh || $day->kind === 'work' ? $kind : null, 'start_time' => $start, 'end_time' => $end, 'calendar_key' => $calendarKey, 'note' => $note]) + ['source' => $fresh ? $source : $day->source]);
        $day->save();
        if ($fresh) {
            AuditLog::record('job.day_added', $job, null, ['date' => $date, 'kind' => $day->kind], null, $source === 'you' || $source === 'calendar' ? 'you' : 'rules');
        }
        self::sync($job);

        return $day;
    }

    /** The days of a calendar entry you linked to the job. */
    public static function fromCalendar(WorkJob $job, CalendarEvent $event): void
    {
        $kind = self::kindFrom((string) $event->summary);
        $d = CarbonImmutable::parse($event->day());
        $last = CarbonImmutable::parse($event->lastDay()->format('Y-m-d'));
        for ($i = 0; $d->lte($last) && $i < 31; $d = $d->addDay(), $i++) {
            $first = $i === 0;
            self::add($job, $d->format('Y-m-d'), $kind, $first && ! $event->all_day ? $event->localStart()->format('H:i') : null,
                $d->eq($last) && ! $event->all_day && $event->ends_at ? $event->localEnd()->format('H:i') : null, $event->key(), 'calendar');
        }
    }

    /** Unlinking a calendar entry removes the days it added (not days you added or changed yourself). */
    public static function forgetCalendar(WorkJob $job, CalendarEvent $event): void
    {
        $days = JobDay::query()->where('work_job_id', $job->id)->where('calendar_key', $event->key())->where('source', 'calendar')->get();
        $all = JobDay::query()->where('work_job_id', $job->id)->count();
        foreach ($days as $day) {
            if ($all > 1) {
                $day->delete();
                $all--;
            }
        }
        self::sync($job);
    }

    public static function remove(JobDay $day): void
    {
        $job = $day->job;
        if (JobDay::query()->where('work_job_id', $job->id)->count() <= 1) {
            throw new InvalidArgumentException('A job needs at least one day.');
        }
        AuditLog::record('job.day_removed', $job, ['date' => $day->on_date->format('Y-m-d'), 'kind' => $day->kind], null);
        $day->delete();
        self::sync($job);
    }

    /** The job's first and last dates follow its days. */
    public static function sync(WorkJob $job): void
    {
        $min = JobDay::query()->where('work_job_id', $job->id)->min('on_date');
        $max = JobDay::query()->where('work_job_id', $job->id)->max('on_date');
        if (! $min) {
            return;
        }
        $min = substr((string) $min, 0, 10);
        $max = substr((string) $max, 0, 10);
        if ($job->starts_on->format('Y-m-d') !== $min || $job->ends_on->format('Y-m-d') !== $max) {
            self::$syncing = true;
            try {
                $job->update(['starts_on' => $min, 'ends_on' => $max]);
            } finally {
                self::$syncing = false;
            }
        }
        $job->unsetRelation('days');
    }

    public static function kindFrom(string $text): string
    {
        return match (true) {
            (bool) preg_match('/\b(de-?rig|load[- ]?out|get[- ]?out|strike|break[- ]?down|pack[- ]?down)\b/i', $text) => 'derig',
            (bool) preg_match('/\b(rig|load[- ]?in|get[- ]?in|build|set[- ]?up|install|prep)\b/i', $text) => 'rig',
            (bool) preg_match('/\b(show|gig|event|performance|concert|conference|awards|festival|live)\b/i', $text) => 'show',
            (bool) preg_match('/\b(travel|drive|flight|train)\b/i', $text) => 'travel',
            default => 'work',
        };
    }
}
