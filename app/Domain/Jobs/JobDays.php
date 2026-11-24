<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\JobDay;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * A job's days. A new job gets one for each date from its first to last; linking a calendar entry yourself adds its
 * days (a rig day, a de-rig days later); you can add, change and remove days on the job. The job's first and last
 * dates always follow its days. Every change to its days is in the job's history.
 */
final class JobDays
{
    /** The most days one job can cover (a theatre season runs for months; more than this is separate jobs). */
    public const MAX_DAYS = 400;

    /** A timed entry that ends before this time the next morning is one evening's work (a gig to 01:30), not two days. */
    public const OVERNIGHT_UNTIL = '06:00';

    private static bool $syncing = false;

    /** Every date from the job's first to last, for a new job. */
    public static function fromRange(WorkJob $job): void
    {
        $d = CarbonImmutable::parse($job->starts_on->format('Y-m-d'));
        $end = CarbonImmutable::parse(($job->ends_on ?? $job->starts_on)->format('Y-m-d'));
        for ($i = 0; $d->lte($end) && $i < self::MAX_DAYS; $d = $d->addDay(), $i++) {
            JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d->format('Y-m-d')], ['kind' => 'work', 'source' => 'range']);
        }
    }

    /**
     * The first or last date was changed on the job form. A job whose days ran without a break keeps running without
     * one. A job with separate days (a rig, then a de-rig days later) keeps its days: moving the whole job moves every
     * day, and a new first or last date moves the first or last day there (with its kind and times), so changing the
     * last date when the de-rig moves doesn't leave the old de-rig behind. Days outside the new dates go.
     */
    public static function rangeChanged(WorkJob $job, string $oldStart, string $oldEnd): void
    {
        if (self::$syncing) {
            return;
        }
        $start = $job->starts_on->format('Y-m-d');
        $end = $job->ends_on->format('Y-m-d');
        $before = self::snapshot($job);
        $dates = array_column($before, 'date');

        if (! $dates || self::continuous($dates)) {
            JobDay::query()->where('work_job_id', $job->id)->where(fn ($q) => $q->whereDate('on_date', '<', $start)->orWhereDate('on_date', '>', $end))->delete();
            self::fromRange($job);
        } else {
            $shift = (int) CarbonImmutable::parse($oldStart)->diffInDays(CarbonImmutable::parse($start), false);
            if ($shift !== 0 && $shift === (int) CarbonImmutable::parse($oldEnd)->diffInDays(CarbonImmutable::parse($end), false)) {
                // The whole job moved (postponed, or brought forward): every day moves with it. The furthest day
                // moves first, so no day lands on one that hasn't moved yet.
                $days = JobDay::query()->where('work_job_id', $job->id)->orderBy('on_date', $shift > 0 ? 'desc' : 'asc')->get();
                foreach ($days as $day) {
                    $day->update(['on_date' => CarbonImmutable::parse($day->on_date->format('Y-m-d'))->addDays($shift)->format('Y-m-d'), 'source' => $day->source === 'calendar' ? 'you' : $day->source]);
                }
            } else {
                self::moveDay($job, $oldEnd, $end);
                self::moveDay($job, $oldStart, $start);
                JobDay::query()->where('work_job_id', $job->id)->where(fn ($q) => $q->whereDate('on_date', '<', $start)->orWhereDate('on_date', '>', $end))->delete();
            }
            foreach (array_unique([$start, $end]) as $d) {
                JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d], ['kind' => 'work', 'source' => 'you']);
            }
        }
        $job->unsetRelation('days');
        self::recordChange($job, $before, 'The job’s dates were changed', 'you');
        self::afterRemoval($job, $before);
    }

    /** Add (or update) a day. */
    public static function add(WorkJob $job, string $date, string $kind = 'work', ?string $start = null, ?string $end = null, ?string $calendarKey = null, string $source = 'you', ?string $note = null): JobDay
    {
        $kind = array_key_exists($kind, JobDay::KINDS) ? $kind : 'work';
        $day = JobDay::query()->firstOrNew(['work_job_id' => $job->id, 'on_date' => $date]);
        $fresh = ! $day->exists;
        $before = $fresh ? null : self::dayFields($day);
        if (! $fresh && $source === 'calendar') {
            // Another entry on a day the job has already (a de-rig and a van return the same day): the day keeps the
            // entry it came from, its times only widen to cover both, and times you set on it yourself are kept.
            $calendarKey = $day->calendar_key ?? $calendarKey;
            if ($day->source === 'you') {
                [$start, $end] = [$day->start_time ?? $start, $day->end_time ?? $end];
            } else {
                $start = self::earliest($day->start_time, $start);
                $end = self::latest($day->end_time, $end);
            }
        }
        // What you set on a day is yours (a calendar entry linked later doesn't change it); an entry only names a
        // plain work day's kind.
        $mine = $source === 'you';
        $day->fill(array_filter(['kind' => $fresh || $mine || $day->kind === 'work' ? $kind : null, 'start_time' => $start, 'end_time' => $end, 'calendar_key' => $calendarKey, 'note' => $note])
            + ['source' => $fresh || $mine ? $source : $day->source]);
        $day->save();
        $actor = $source === 'you' || $source === 'calendar' ? 'you' : 'rules';
        if ($fresh) {
            AuditLog::record('job.day_added', $job, null, ['date' => $date, 'kind' => $day->kind] + array_filter(['start_time' => $day->start_time, 'end_time' => $day->end_time]), null, $actor);
        } elseif ($day->wasChanged()) {
            AuditLog::record('job.day_updated', $job, ['date' => $date] + $before, ['date' => $date] + self::dayFields($day), $source === 'calendar' ? 'From a calendar entry linked to the job' : null, $actor);
        }
        self::sync($job);

        return $day;
    }

    /**
     * The days of a calendar entry you linked to the job. With bounds (the dates you typed when making a job from
     * the entry), only its days within them are added: the entry's own kind and times go on those days.
     */
    public static function fromCalendar(WorkJob $job, CalendarEvent $event, ?string $from = null, ?string $to = null): void
    {
        $kind = self::kindFrom((string) $event->summary);
        $first = CarbonImmutable::parse($event->day());
        $last = CarbonImmutable::parse(self::lastDay($event));
        $d = $from && $from > $first->format('Y-m-d') ? CarbonImmutable::parse($from) : $first;
        $until = $to && $to < $last->format('Y-m-d') ? CarbonImmutable::parse($to) : $last;
        for ($i = 0; $d->lte($until) && $i < 31; $d = $d->addDay(), $i++) {
            self::add($job, $d->format('Y-m-d'), $kind, $d->eq($first) && ! $event->all_day ? $event->localStart()->format('H:i') : null,
                $d->eq($last) && ! $event->all_day && $event->ends_at ? $event->localEnd()->format('H:i') : null, $event->key(), 'calendar');
        }
    }

    /**
     * The start and finish times you typed when making the job (from a calendar entry, or a possible job) are yours:
     * they go on its first and last days in place of the calendar entry's, so the night-before and night-after checks
     * use them. A time you cleared is cleared; a time the form didn't send leaves the entry's.
     */
    public static function useFormTimes(WorkJob $job, array $data): void
    {
        $days = $job->days()->get();
        foreach (['start_time' => $days->first(), 'end_time' => $days->last()] as $field => $day) {
            if (! $day || ! array_key_exists($field, $data) || $day->{$field} === ($data[$field] ?? null)) {
                continue;
            }
            $before = $day->only([$field]);
            $day->update([$field => $data[$field] ?? null]);
            AuditLog::record('job.day_updated', $job, ['date' => $day->on_date->format('Y-m-d')] + $before, ['date' => $day->on_date->format('Y-m-d')] + $day->only([$field]), 'The time you gave the job');
        }
        $job->unsetRelation('days');
    }

    /**
     * The last UK date a calendar entry's work covers. Like CalendarEvent::lastDay, except that a timed entry ending
     * early the next morning (a gig from 19:00 to 01:30) is one day's work, with its finish time on that day.
     */
    public static function lastDay(CalendarEvent $event): string
    {
        $last = $event->lastDay()->format('Y-m-d');
        $end = $event->localEnd();
        if ($event->all_day || ! $end || $last === $event->day()) {
            return $last;
        }
        $overnight = $end->format('H:i') < self::OVERNIGHT_UNTIL
            && CarbonImmutable::parse($event->day())->addDay()->format('Y-m-d') === $end->format('Y-m-d')
            && $event->starts_at->diffInHours($event->ends_at, true) < 24;

        return $overnight ? $event->day() : $last;
    }

    /**
     * Unlinking a calendar entry removes the days it added (not days you added or changed yourself). A day another
     * entry you linked to the job also covers stays, as that entry's day. An entry the search linked never adds a day
     * (it was linked because the day was already the job's), so it doesn't keep one either: it comes off the job with
     * the day (see JobGatherer::recheckOffDays).
     */
    public static function forgetCalendar(WorkJob $job, CalendarEvent $event): void
    {
        self::forgetCalendarKey($job, $event->key(), (string) $event->summary);
    }

    /** The same, by the entry's key (for an entry that has gone from your calendar since). */
    public static function forgetCalendarKey(WorkJob $job, string $key, string $summary = ''): void
    {
        $before = self::snapshot($job);
        $days = JobDay::query()->where('work_job_id', $job->id)->where('calendar_key', $key)->where('source', 'calendar')->get();
        $all = JobDay::query()->where('work_job_id', $job->id)->count();
        $others = $days->isEmpty() ? collect() : CalendarJobLink::query()->where('work_job_id', $job->id)->where('event_key', '!=', $key)->where('linked_by', 'you')->get()
            ->map(fn (CalendarJobLink $l) => JobFinder::event($l->event_key, $l->event_starts_at?->copy()->setTimezone(CalendarEvent::TZ)->format('Y-m-d')))->filter();
        foreach ($days as $day) {
            $date = $day->on_date->format('Y-m-d');
            $other = $others->first(fn (CalendarEvent $e) => $e->day() <= $date && self::lastDay($e) >= $date);
            if ($other) {
                $day->update(['calendar_key' => $other->key()]);
            } elseif ($all > 1) {
                $day->delete();
                $all--;
            }
        }
        $job->unsetRelation('days');
        self::recordChange($job, $before, 'Calendar entry '.($summary !== '' ? '“'.mb_substr($summary, 0, 80).'” ' : '').'unlinked', 'you');
        self::sync($job);
        self::afterRemoval($job, $before);
    }

    public static function remove(JobDay $day): void
    {
        $job = $day->job;
        if (JobDay::query()->where('work_job_id', $job->id)->count() <= 1) {
            throw new InvalidArgumentException('A job needs at least one day.');
        }
        $before = self::snapshot($job);
        AuditLog::record('job.day_removed', $job, ['date' => $day->on_date->format('Y-m-d'), 'kind' => $day->kind], null);
        $day->delete();
        self::sync($job);
        self::afterRemoval($job, $before);
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
            $was = ['starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d')];
            self::$syncing = true;
            try {
                $job->update(['starts_on' => $min, 'ends_on' => $max]);
            } finally {
                self::$syncing = false;
            }
            AuditLog::record('job.dates_changed', $job, $was, ['starts_on' => $min, 'ends_on' => $max], 'Its first and last dates follow its days');
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

    /**
     * A calendar title without the words that say which day of a job it is ("Rig - Acme Awards", "Show: Acme Awards"
     * and "De-rig Acme Awards" are all "acme awards"), lower case with punctuation as spaces.
     */
    public static function baseTitle(?string $title): string
    {
        $t = preg_replace('/\b(de-?rig|rig|load[- ]?(?:in|out)|get[- ]?(?:in|out)|strike|break[- ]?down|pack[- ]?down|build|set[- ]?up|install|prep|show|travel|tech|rehearsals?|day\s*\d*|day)\b/iu', ' ', (string) $title);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $t)) ?? '') ?? '');
    }

    // ---------------------------------------------------------------- helpers

    /** Move a job's first or last day to its new date, keeping what it is (a de-rig moved a day later). */
    private static function moveDay(WorkJob $job, string $was, string $now): void
    {
        if ($was === $now) {
            return;
        }
        $day = JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', $was)->first();
        if ($day && ! JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', $now)->exists()) {
            $day->update(['on_date' => $now, 'source' => 'you']); // moved by you: no longer the calendar entry's day
        }
    }

    /** @param list<string> $dates oldest first */
    private static function continuous(array $dates): bool
    {
        foreach ($dates as $i => $d) {
            if ($i > 0 && CarbonImmutable::parse($dates[$i - 1])->addDay()->format('Y-m-d') !== $d) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{date:string, kind:string}> the job's days as they are now */
    private static function snapshot(WorkJob $job): array
    {
        return JobDay::query()->where('work_job_id', $job->id)->orderBy('on_date')->get()
            ->map(fn (JobDay $d) => ['date' => $d->on_date->format('Y-m-d'), 'kind' => $d->kind])->all();
    }

    private static function dayFields(JobDay $day): array
    {
        return $day->only(['kind', 'start_time', 'end_time', 'calendar_key', 'note']);
    }

    /** One history entry for days added and removed together (a date change, an entry unlinked). */
    private static function recordChange(WorkJob $job, array $before, string $reason, string $actor): void
    {
        $after = self::snapshot($job);
        $was = array_column($before, 'date');
        $now = array_column($after, 'date');
        if ($was === $now && $before === $after) {
            return;
        }
        AuditLog::record('job.days_changed', $job, ['days' => $before], ['days' => $after, 'removed' => array_values(array_diff($was, $now)), 'added' => array_values(array_diff($now, $was))], $reason, $actor);
    }

    /** Days taken off the job: costs and stays suggested for them are looked at again (see JobGatherer::recheckOffDays). */
    private static function afterRemoval(WorkJob $job, array $before): void
    {
        $gone = array_values(array_diff(array_column($before, 'date'), $job->fresh()?->workDates() ?? []));
        if ($gone === []) {
            return;
        }
        try {
            app(JobGatherer::class)->recheckOffDays($job->fresh(), $gone);
        } catch (Throwable $e) {
            report($e); // the days are changed either way; the next search looks again
        }
    }

    private static function earliest(?string $a, ?string $b): ?string
    {
        return $a && $b ? min($a, $b) : ($a ?? $b);
    }

    /** The later finish; one in the small hours (01:30) is after one the evening before (23:00). */
    private static function latest(?string $a, ?string $b): ?string
    {
        if (! $a || ! $b) {
            return $a ?? $b;
        }
        $key = fn (string $t) => ($t < self::OVERNIGHT_UNTIL ? '1' : '0').$t;

        return $key($a) >= $key($b) ? $a : $b;
    }
}
