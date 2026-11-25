<?php

namespace App\Domain\Jobs;

use App\Domain\Documents\Grounding;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\JobDay;
use App\Models\TaxYear;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A month of your calendar for the calendar page and the day picker (the calendar's grid, and "Add days" on a job):
 * every entry that covers a day of the month (one that began in an earlier month, a festival from 28 Oct to 2 Nov,
 * is in November too), each with whether it's free, on a job or "not a job", and whether picking its day ticks it
 * for you; the days of your jobs, by date; and the tax years you've locked. It only reads.
 */
final class CalendarMonth
{
    /** The most entries read for one stretch of days (a month, or the days of one pick). */
    public const LIMIT = 1500;

    public function __construct(private JobFinder $finder) {}

    /**
     * @return array{month: string, prev: string, next: string, today: string, events: list<array>,
     *     job_days: array<string, list<array{job_id: int, title: string, cancelled: bool}>>,
     *     locked_years: list<array{label: string, starts_on: string, ends_on: string}>}
     */
    public function build(CarbonImmutable $month): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $events = self::covering($from->format('Y-m-d'), $to->format('Y-m-d'));
        $links = CalendarJobLink::query()->whereIn('event_key', $events->map->key()->all())->get()->keyBy('event_key');
        $jobs = WorkJob::query()->whereIn('id', $links->pluck('work_job_id')->filter()->unique()->values()->all())->get(['id', 'title', 'status'])->keyBy('id');
        $clients = Client::query()->orderBy('name')->get(['id', 'name']);

        return [
            'month' => $from->format('Y-m'),
            'prev' => $from->subMonth()->format('Y-m'),
            'next' => $from->addMonth()->format('Y-m'),
            'today' => self::today(),
            'events' => $events->map(fn (CalendarEvent $e) => $this->entry($e, $links[$e->key()] ?? null, $jobs, $clients))->values()->all(),
            'job_days' => self::jobDays($from->format('Y-m-d'), $to->format('Y-m-d')),
            'locked_years' => self::lockedYears(),
        ];
    }

    /** One entry as the calendar page shows it. */
    private function entry(CalendarEvent $e, ?CalendarJobLink $link, Collection $jobs, Collection $clients): array
    {
        $job = $link?->work_job_id ? $jobs[$link->work_job_id] ?? null : null;
        $score = $this->finder->workScore($e);
        $state = self::state($link);
        $recurring = $e->recurrence_id !== null;

        return [
            // A gig that ends in the small hours is one day's work, so the form suggests one day.
            'key' => $e->key(), 'date' => $e->day(), 'last_day' => JobDays::lastDay($e), 'summary' => $e->summary ?: '(no title)', 'location' => $e->location,
            'all_day' => $e->all_day, 'start_time' => $e->all_day ? null : $e->localStart()->format('H:i'), 'end_time' => $e->all_day || ! $e->ends_at ? null : $e->localEnd()->format('H:i'),
            'job' => $job ? ['id' => $job->id, 'title' => $job->title] : null, 'by' => $link?->linked_by, 'not_job' => (bool) $link?->not_job,
            'looks_like_work' => ! $job && ! $link?->not_job && $score >= 2,
            'postcode' => self::postcode((string) $e->location),
            'client_id' => $clients->first(fn ($c) => self::mentions($e->text(), $c->name))?->id,
            'state' => $state, 'recurring' => $recurring, 'base' => JobDays::baseTitle($e->summary), 'score' => $score,
            'default_include' => self::ticks($state, $score, $recurring),
        ];
    }

    /**
     * Entries that cover any day from $from to $to (UK dates): one starting in the stretch, or one that started before
     * it and is still going (an all-day tour from 28 Nov to 4 Jan is in January). Read with an overlap test in SQL
     * first, so a long spell of earlier entries can't push the stretch's own past the limit.
     *
     * @return Collection<int, CalendarEvent> oldest first
     */
    public static function covering(string $from, string $to, int $limit = self::LIMIT): Collection
    {
        $start = CarbonImmutable::parse($from)->subDay()->format('Y-m-d 00:00:00');

        return CalendarEvent::query()->where('starts_at', '<=', CarbonImmutable::parse($to)->addDay()->format('Y-m-d 23:59:59'))
            ->where(fn ($q) => $q->where('starts_at', '>=', $start)->orWhere('ends_at', '>=', $start))
            ->orderBy('starts_at')->orderBy('id')->limit($limit)->get()
            ->filter(fn (CalendarEvent $e) => $e->day() <= $to && JobDays::lastDay($e) >= $from)->values();
    }

    /** free, job (on a job, yours or automatic) or not_job. */
    public static function state(?CalendarJobLink $link): string
    {
        return match (true) {
            (bool) $link?->not_job => 'not_job',
            (bool) $link?->work_job_id => 'job',
            default => 'free',
        };
    }

    /**
     * Whether picking an entry's day ticks it for you: a free entry that looks like work (workScore 2 or more; 3 or
     * more for an occurrence of a repeating entry, so a weekly physio doesn't spread over a job). One on a job, or
     * marked "not a job", needs your say.
     */
    public static function ticks(string $state, int $score, bool $recurring): bool
    {
        return $state === 'free' && $score >= ($recurring ? 3 : 2);
    }

    /** @return array<string, list<array{job_id: int, title: string, cancelled: bool}>> the days of your jobs from $from to $to, by date */
    public static function jobDays(string $from, string $to, ?int $except = null): array
    {
        $out = [];
        JobDay::query()->join('work_jobs', 'work_jobs.id', '=', 'job_days.work_job_id')
            ->whereDate('job_days.on_date', '>=', $from)->whereDate('job_days.on_date', '<=', $to)
            ->when($except, fn ($q) => $q->where('work_jobs.id', '!=', $except))
            ->orderBy('job_days.on_date')->orderBy('work_jobs.id')
            ->get(['job_days.on_date', 'work_jobs.id as job_id', 'work_jobs.title', 'work_jobs.status'])
            ->each(function ($row) use (&$out) {
                $out[substr((string) $row->getRawOriginal('on_date'), 0, 10)][] = ['job_id' => (int) $row->job_id, 'title' => (string) $row->title, 'cancelled' => $row->status === 'cancelled'];
            });

        return $out;
    }

    /** @return list<array{label: string, starts_on: string, ends_on: string}> */
    public static function lockedYears(): array
    {
        return TaxYear::query()->whereNotNull('locked_at')->orderBy('starts_on')->get(['label', 'starts_on', 'ends_on'])
            ->map(fn (TaxYear $y) => ['label' => $y->label, 'starts_on' => $y->starts_on->format('Y-m-d'), 'ends_on' => $y->ends_on->format('Y-m-d')])->values()->all();
    }

    /** Today in the UK (the server runs on UTC: just after midnight in summer it's still yesterday there). */
    public static function today(): string
    {
        return CarbonImmutable::now(CalendarEvent::TZ)->format('Y-m-d');
    }

    public static function postcode(string $text): ?string
    {
        return preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i', $text, $m) ? strtoupper($m[1].' '.$m[2]) : null;
    }

    /** Whether the text names the client (without Ltd, Limited, PLC, LLP or The; 3 letters at least). */
    public static function mentions(string $text, string $name): bool
    {
        $n = Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b/i', '', $name));

        return mb_strlen($n) >= 3 && str_contains(Grounding::normalise($text), $n);
    }
}
