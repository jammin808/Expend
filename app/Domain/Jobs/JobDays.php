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

    /** The words that say which day of a job an entry is ("Rig", "Show", "De-rig", "Day 2"), for baseTitle() and cleanTitle(). */
    private const DAY_WORDS = '/\b(de-?rig|rig|load[- ]?(?:in|out)|get[- ]?(?:in|out)|strike|break[- ]?down|pack[- ]?down|build|set[- ]?up|install|prep|show|travel|tech|rehearsals?|day\s*\d*|day)\b/iu';

    private static bool $syncing = false;

    /** Set while a job is made with its days listed (withoutRange()): fromRange() leaves the days to the caller. */
    private static bool $noRange = false;

    /** @var array<string, string> while a job is made from days picked on the calendar: each day's calendar entry, by date */
    private static array $keys = [];

    /**
     * Run $work (making a job whose days are listed) without the new job getting every date from its first to last:
     * the caller adds exactly the days it was given, so none are made only to be deleted again.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public static function withoutRange(callable $work): mixed
    {
        $was = self::$noRange;
        self::$noRange = true;
        try {
            return $work();
        } finally {
            self::$noRange = $was;
        }
    }

    /**
     * Run $work (making a job from days picked on the calendar) with each new day named after the calendar entry it
     * came from, as it's made: the job's history then says so from the start. Only DayPick knows these (worked out on
     * the server from the entries you picked); nothing a caller sends to JobCreator can set them.
     *
     * @template T
     *
     * @param  array<string, string>  $keys  date => calendar entry key
     * @param  callable():T  $work
     * @return T
     */
    public static function withCalendarKeys(array $keys, callable $work): mixed
    {
        $was = self::$keys;
        self::$keys = $keys;
        try {
            return $work();
        } finally {
            self::$keys = $was;
        }
    }

    /** The calendar entry a day being made is named after (withCalendarKeys()), if any. */
    public static function pendingKey(string $date): ?string
    {
        return self::$keys[$date] ?? null;
    }

    /** Every date from the job's first to last, for a new job. */
    public static function fromRange(WorkJob $job): void
    {
        if (self::$noRange) {
            return;
        }
        $d = CarbonImmutable::parse($job->starts_on->format('Y-m-d'));
        $end = CarbonImmutable::parse(($job->ends_on ?? $job->starts_on)->format('Y-m-d'));
        for ($i = 0; $d->lte($end) && $i < self::MAX_DAYS; $d = $d->addDay(), $i++) {
            JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d->format('Y-m-d')], ['kind' => 'work', 'source' => 'range']);
        }
    }

    /**
     * The first or last date was changed on the job form. Moving the whole job (its first and last dates by the same
     * number of days: postponed, or brought forward) moves every day with it, each keeping its kind, times and note,
     * whether the job is one day, days without a break or separate days. Otherwise a job whose days ran without a
     * break keeps running without one, and a job with separate days (a rig, then a de-rig days later) keeps its days:
     * a new first or last date moves the first or last day there (with its kind and times), so changing the last date
     * when the de-rig moves doesn't leave the old de-rig behind. Days outside the new dates go.
     */
    public static function rangeChanged(WorkJob $job, string $oldStart, string $oldEnd): void
    {
        if (self::$syncing) {
            return;
        }
        $start = $job->starts_on->format('Y-m-d');
        $end = $job->ends_on->format('Y-m-d');
        $before = self::rows($job);
        $dates = array_column($before, 'date');
        $shift = (int) CarbonImmutable::parse($oldStart)->diffInDays(CarbonImmutable::parse($start), false);
        $moved = $dates && $shift !== 0 && $shift === (int) CarbonImmutable::parse($oldEnd)->diffInDays(CarbonImmutable::parse($end), false);
        // The same whole number of months or years (a year typed wrong, then put right): across 29 February the first
        // and last dates move by different numbers of days, but it's still the whole job moving.
        $months = ! $moved && $dates ? self::monthStep($oldStart, $oldEnd, $start, $end) : null;

        if ($moved || $months !== null) {
            $to = fn (string $d) => $moved ? CarbonImmutable::parse($d)->addDays($shift)->format('Y-m-d') : CarbonImmutable::parse($d)->addMonthsNoOverflow($months)->format('Y-m-d');
            // The whole job moved: every day moves with it. The furthest day moves first, so no day lands on one that
            // hasn't moved yet (a job has one day per date).
            $days = JobDay::query()->where('work_job_id', $job->id)->orderBy('on_date', ($moved ? $shift : $months) > 0 ? 'desc' : 'asc')->get();
            // Two days landing on one date (29 February moved to a year without one, beside 28 February): the day whose
            // own date it is stays, and the other is merged into it.
            $wins = [];
            foreach ($days as $day) {
                $from = $day->on_date->format('Y-m-d');
                $target = $to($from);
                $exact = substr($from, 8, 2) === substr($target, 8, 2);
                if (! isset($wins[$target]) || ($exact && ! $wins[$target][1])) {
                    $wins[$target] = [$day->id, $exact];
                }
            }
            $keep = array_flip(array_map(fn ($w) => $w[0], $wins));
            foreach ($days as $day) {
                if (! isset($keep[$day->id])) {
                    $day->delete();
                }
            }
            foreach ($days as $day) {
                if (isset($keep[$day->id])) {
                    $day->update(['on_date' => $to($day->on_date->format('Y-m-d')), 'source' => $day->source === 'calendar' ? 'you' : $day->source]);
                }
            }
            foreach (array_unique([$start, $end]) as $d) {
                JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d], ['kind' => 'work', 'source' => 'you']);
            }
        } elseif (! $dates || self::continuous($dates)) {
            JobDay::query()->where('work_job_id', $job->id)->where(fn ($q) => $q->whereDate('on_date', '<', $start)->orWhereDate('on_date', '>', $end))->delete();
            self::fromRange($job);
        } else {
            self::moveDay($job, $oldEnd, $end);
            self::moveDay($job, $oldStart, $start);
            JobDay::query()->where('work_job_id', $job->id)->where(fn ($q) => $q->whereDate('on_date', '<', $start)->orWhereDate('on_date', '>', $end))->delete();
            foreach (array_unique([$start, $end]) as $d) {
                JobDay::query()->firstOrCreate(['work_job_id' => $job->id, 'on_date' => $d], ['kind' => 'work', 'source' => 'you']);
            }
        }
        $job->unsetRelation('days');
        // With each day's times and note, so the history shows they moved with it.
        self::recordChange($job, $before, 'The job’s dates were changed', null, true);
        self::afterRemoval($job, $before);
    }

    /**
     * How many whole months both of a job's dates moved by (12 for a year), when they moved by the same number: the
     * first from $oldStart to $start and the last from $oldEnd to $end. Null when they didn't.
     */
    private static function monthStep(string $oldStart, string $oldEnd, string $start, string $end): ?int
    {
        $a = CarbonImmutable::parse($oldStart);
        $b = CarbonImmutable::parse($start);
        $n = ($b->year - $a->year) * 12 + ($b->month - $a->month);
        if ($n === 0) {
            return null;
        }

        return $a->addMonthsNoOverflow($n)->format('Y-m-d') === $start && CarbonImmutable::parse($oldEnd)->addMonthsNoOverflow($n)->format('Y-m-d') === $end ? $n : null;
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
        // Recorded as whoever is acting (AuditContext): you, for a day you add or an entry you link; the matcher when a
        // search links an entry. Only days the search itself adds are named as the matcher's outright.
        $actor = $source === 'you' || $source === 'calendar' ? null : 'matcher';
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
        // Oldest first: when every day is this entry's, the one kept (a job keeps one day) is its latest.
        $days = JobDay::query()->where('work_job_id', $job->id)->where('calendar_key', $key)->where('source', 'calendar')->orderBy('on_date')->get();
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
        self::recordChange($job, $before, 'Calendar entry '.($summary !== '' ? '“'.mb_substr($summary, 0, 80).'” ' : '').'unlinked');
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
        $t = preg_replace(self::DAY_WORDS, ' ', (string) $title);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string) $t)) ?? '') ?? '');
    }

    /**
     * A calendar title as a job's title, with its capitals and punctuation kept: the words that say which day of a job
     * it is come off only where they stand apart from the name, at the start before a separator ("Rig: Winter Expo",
     * "Show - Acme Awards", "Rig/Show/De-rig: Spring Trade Show"), in brackets ("Acme Awards (de-rig)") or after a
     * separator at the end ("Acme Awards – Day 2"). Words that are part of the name stay ("Spring Trade Show", "Frost
     * Festival build and show"). Empty when nothing else is left ("Rig").
     */
    public static function cleanTitle(?string $title): string
    {
        $word = '(?:de-?rig|rig|load[- ]?(?:in|out)|get[- ]?(?:in|out)|strike|break[- ]?down|pack[- ]?down|build|set[- ]?up|install|prep|show|travel|tech|rehearsals?|day\s*\d+|day)';
        $words = $word.'(?:\s*(?:[\/&+,]|and)\s*'.$word.')*';
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) $title));
        if (preg_match('/^[\s\-–—:|·\/()\[\]]*'.$words.'[\s\-–—:|·\/()\[\].]*$/iu', $t)) {
            return ''; // only day words
        }
        $t = (string) preg_replace('/\s*[(\[]\s*'.$words.'\s*[)\]]/iu', ' ', $t); // "(rig)", "[Day 2]"
        $t = (string) preg_replace('/^\s*'.$words.'\s*(?::|\||·|–|—|-(?=\s))\s*/iu', '', $t); // "Rig: ", "Show - ", "De-rig | "
        $t = (string) preg_replace('/\s*(?::|\||·|–|—|\s-)\s*'.$words.'\s*$/iu', '', $t); // " – Day 2", " - rig"
        $t = (string) preg_replace('/\s+/u', ' ', $t);
        $t = (string) preg_replace('/^[\s\-–—:;,|·\/.+&]+|[\s\-–—:;,|·\/+&]+$/u', '', $t);

        return trim($t);
    }

    /**
     * Days you picked for a job you have (the calendar's "Add to job", or "Add days" on the job page), with the kind,
     * times and note you gave each. A new date is added as your day, with the calendar entry it came from. A day the
     * job has already is written only when what you gave differs (times compared to the minute: MariaDB gives a time
     * back as "08:00:00"); then it is exactly what you gave, a cleared time cleared, and yours. One you picked as it is
     * becomes yours too (only its source changes). No day is removed. One history entry for the lot, then the job's
     * first and last dates follow its days once.
     *
     * @param  list<array{date: string, kind?: ?string, start_time?: ?string, end_time?: ?string, note?: ?string, calendar_key?: ?string}>  $rows
     * @return array{added: list<string>, changed: list<string>, yours: list<string>}
     */
    public static function addMany(WorkJob $job, array $rows, string $reason): array
    {
        $existing = JobDay::query()->where('work_job_id', $job->id)->get()->keyBy(fn (JobDay $d) => $d->on_date->format('Y-m-d'));
        $before = self::rows($job);
        $added = [];
        $changed = [];
        $yours = [];
        foreach ($rows as $row) {
            $date = CarbonImmutable::parse($row['date'])->format('Y-m-d');
            $kind = (string) ($row['kind'] ?? 'work');
            $note = isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null;
            $values = ['kind' => array_key_exists($kind, JobDay::KINDS) ? $kind : 'work', 'start_time' => self::minute($row['start_time'] ?? null),
                'end_time' => self::minute($row['end_time'] ?? null), 'note' => $note];
            $day = $existing[$date] ?? null;
            if (! $day) {
                JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => $date, 'source' => 'you', 'calendar_key' => $row['calendar_key'] ?? null] + $values);
                $added[] = $date;

                continue;
            }
            if ($day->kind === $values['kind'] && self::minute($day->start_time) === $values['start_time'] && self::minute($day->end_time) === $values['end_time']
                && ($day->note === '' ? null : $day->note) === $values['note']) {
                // As it is, but you picked it: it's yours now (only its source is written), so marking the entry it
                // came from "not part of this job" later doesn't take it off the job.
                if ($day->source !== 'you') {
                    $day->update(['source' => 'you']);
                    $yours[] = $date;
                }

                continue;
            }
            $day->update($values + ['source' => 'you']); // its calendar entry (calendar_key) is kept
            $changed[] = $date;
        }
        sort($added);
        sort($changed);
        sort($yours);
        if ($added || $changed || $yours) {
            AuditLog::record('job.days_changed', $job, ['days' => $before], ['days' => self::rows($job), 'added' => $added, 'changed' => $changed] + ($yours ? ['yours' => $yours] : []), $reason);
        }
        self::sync($job);
        $job->unsetRelation('days');

        return ['added' => $added, 'changed' => $changed, 'yours' => $yours];
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

    /**
     * One history entry for days added and removed together (a date change, an entry unlinked), recorded as whoever is
     * acting (AuditContext): you on the web or in Telegram, the matcher when a search moved or unlinked the entry.
     */
    private static function recordChange(WorkJob $job, array $before, string $reason, ?string $actor = null, bool $full = false): void
    {
        $after = $full ? self::rows($job) : self::snapshot($job);
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

    /**
     * @return list<array{date:string, kind:string, start_time:?string, end_time:?string, note:?string, calendar_key?:string}> the job's days with
     *                                                                                                                         their times and notes, and the calendar entry each is named after (only when it has one)
     */
    private static function rows(WorkJob $job): array
    {
        return JobDay::query()->where('work_job_id', $job->id)->orderBy('on_date')->get()
            ->map(fn (JobDay $d) => ['date' => $d->on_date->format('Y-m-d'), 'kind' => $d->kind, 'start_time' => self::minute($d->start_time),
                'end_time' => self::minute($d->end_time), 'note' => $d->note === '' ? null : $d->note] + ($d->calendar_key ? ['calendar_key' => $d->calendar_key] : []))->all();
    }

    /** A time to the minute ("08:00:00" from MariaDB is "08:00"); none for an empty one. */
    private static function minute(?string $time): ?string
    {
        return $time === null || trim($time) === '' ? null : substr(trim($time), 0, 5);
    }

    /** The earlier start. */
    public static function earliest(?string $a, ?string $b): ?string
    {
        return $a && $b ? min($a, $b) : ($a ?? $b);
    }

    /** The later finish; one in the small hours (01:30) is after one the evening before (23:00). */
    public static function latest(?string $a, ?string $b): ?string
    {
        if (! $a || ! $b) {
            return $a ?? $b;
        }
        $key = fn (string $t) => ($t < self::OVERNIGHT_UNTIL ? '1' : '0').$t;

        return $key($a) >= $key($b) ? $a : $b;
    }
}
