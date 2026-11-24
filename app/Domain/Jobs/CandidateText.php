<?php

namespace App\Domain\Jobs;

use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How a possible job reads on the to-do list (and wherever else it's listed). One found in your calendar says so:
 * "From your calendar: Tue 3 Dec, 08:00–18:00, Acme Arena", opens that calendar entry, and offers "Part of Acme
 * Awards" when a job you have is within a few days and has the same client or place. The next 14 days come first.
 * One whose entry has since left your calendar (cancelled or deleted) says so and opens the job finder instead.
 *
 * "Part of" is never offered for a job you've said its entry or documents aren't part of ("Not this job"), nor at
 * all when you've said its entry isn't a job: the same stores Intel\Vetoes reads for calendar_job and document_job.
 *
 * It only reads: nothing is linked or merged until you press. Use forAll() for a list (one query each for the jobs,
 * the calendar entries, their links and the documents).
 */
final class CandidateText
{
    /** Possible jobs starting within this many days from today (or under way) are listed first. */
    public const SOON_DAYS = 14;

    /** A job you have this many days or fewer before or after a possible job may be the same work. */
    public const NEAR_DAYS = 3;

    /** Words that name a kind of place, not a place: a venue called only this matches nothing on its own. */
    private const GENERIC_PLACES = ['hall', 'arena', 'hotel', 'theatre', 'theater', 'centre', 'center', 'stadium', 'club', 'house', 'park',
        'church', 'school', 'warehouse', 'site', 'stage', 'room', 'suite', 'gallery', 'museum', 'barn', 'field'];

    /** Left out when comparing clients' names: "Acme Events Ltd" is "Acme Events". */
    private const COMPANY_WORDS = '/\b(ltd|limited|plc|llp|inc|the|co)\b/';

    /**
     * Everything for one possible job.
     *
     * @param  Collection<int, WorkJob>|null  $jobs  jobs to look for "Part of" in (default: those near it)
     * @return array{title: string, detail: string, href: string, from_calendar: bool, part_of: ?array{job_id: int, title: string, label: string, href: string}, sort: string}
     */
    public static function for(JobCandidate $candidate, ?Collection $jobs = null, ?CarbonInterface $today = null): array
    {
        return self::build($candidate, self::calendarEvent($candidate), $jobs, $today, self::refused($candidate));
    }

    /**
     * The same for a list of possible jobs, sorted with the next 14 days first, keyed by candidate id.
     *
     * @param  iterable<JobCandidate>  $candidates
     * @return array<int, array> in sort order
     */
    public static function forAll(iterable $candidates, ?CarbonInterface $today = null): array
    {
        $candidates = collect($candidates);
        if ($candidates->isEmpty()) {
            return [];
        }
        $from = CarbonImmutable::parse($candidates->min(fn (JobCandidate $c) => $c->starts_on->format('Y-m-d')))->subDays(self::NEAR_DAYS);
        $to = CarbonImmutable::parse($candidates->max(fn (JobCandidate $c) => ($c->ends_on ?? $c->starts_on)->format('Y-m-d')))->addDays(self::NEAR_DAYS);
        $jobs = self::jobsBetween($from, $to);
        $keys = $candidates->map(fn (JobCandidate $c) => self::calendarSource($c)['id'] ?? null)->filter()->unique()->values();
        $events = $keys->isEmpty() ? collect() : CalendarEvent::query()->whereIn('event_key', $keys)->get()->keyBy('event_key');
        $allKeys = $candidates->flatMap(fn (JobCandidate $c) => self::sourceIds($c, 'calendar'))->unique()->values();
        $links = $allKeys->isEmpty() ? collect() : CalendarJobLink::query()->whereIn('event_key', $allKeys)->get()->keyBy('event_key');
        $docIds = $candidates->flatMap(fn (JobCandidate $c) => self::sourceIds($c, 'document'))->map(fn ($id) => (int) $id)->unique()->values();
        $documents = $docIds->isEmpty() ? collect() : Document::query()->whereIn('id', $docIds)->get(['id', 'job_rejected'])->keyBy('id');

        $out = [];
        foreach ($candidates as $c) {
            $out[$c->id] = self::build($c, $events[self::calendarSource($c)['id'] ?? ''] ?? null, $jobs, $today, self::refused($c, $links, $documents));
        }
        uasort($out, fn ($a, $b) => $a['sort'] <=> $b['sort']);

        return $out;
    }

    /**
     * @param  Collection<int, WorkJob>|null  $jobs
     * @param  array{all: bool, jobs: list<int>}  $refused
     */
    private static function build(JobCandidate $candidate, ?CalendarEvent $event, ?Collection $jobs, ?CarbonInterface $today, array $refused): array
    {
        // An entry that has left your calendar: nothing to open there, and nothing to offer it as part of.
        $gone = self::calendarSource($candidate) !== null && $event === null;
        $partOf = $gone ? null : self::partOf($candidate, $jobs, $refused);

        return [
            'title' => self::title($candidate),
            'detail' => self::describe($candidate, $event, $today),
            'href' => self::link($candidate, $event),
            'from_calendar' => self::calendarSource($candidate) !== null,
            'part_of' => $partOf ? [
                'job_id' => $partOf->id,
                'title' => $partOf->title,
                'label' => 'Part of '.$partOf->title,
                // The calendar entry with "Part of a job I have" open on that job.
                'href' => self::link($candidate, $event, $partOf),
            ] : null,
            'sort' => self::sortKey($candidate, $today, $gone),
        ];
    }

    public static function title(JobCandidate $candidate): string
    {
        return 'Possible job: '.$candidate->title;
    }

    /**
     * "From your calendar: Tue 3 Dec, 08:00–18:00, Acme Arena" for one found in your calendar (with " · and 1 other
     * source" when paperwork or a stay points to it too); "No longer in your calendar: …" once the entry has
     * left it (cancelled or deleted), from what the job finder kept; otherwise as before: "3 Dec 2026 · Acme Events ·
     * from 2 sources".
     */
    public static function detail(JobCandidate $candidate, ?CalendarEvent $event = null, ?CarbonInterface $today = null): string
    {
        return self::describe($candidate, $event ?? self::calendarEvent($candidate), $today);
    }

    /** detail() with the entry already read ($event null: it has left your calendar). */
    private static function describe(JobCandidate $candidate, ?CalendarEvent $event, ?CarbonInterface $today): string
    {
        $sources = count($candidate->sources ?? []);
        $source = self::calendarSource($candidate);
        if (! $source) {
            return $candidate->starts_on->format('j M Y').($candidate->client_name ? ' · '.$candidate->client_name : '')
                .' · from '.$sources.' '.($sources === 1 ? 'source' : 'sources');
        }
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::now(CalendarEvent::TZ))->format('Y-m-d'));
        $day = fn (string $date) => CarbonImmutable::parse($date)->format('D j M'.(CarbonImmutable::parse($date)->year !== $today->year ? ' Y' : ''));

        if ($event) {
            $first = $event->day();
            $last = JobDays::lastDay($event);
            $start = $event->all_day ? null : $event->localStart()->format('H:i');
            $end = $event->all_day || ! $event->ends_at ? null : $event->localEnd()->format('H:i');
            $place = self::place((string) $event->location) ?? $candidate->location;
        } else {
            // The entry has left the feed since: what the job finder kept from it.
            $first = (string) ($source['date'] ?? $candidate->starts_on->format('Y-m-d'));
            $last = (string) ($source['to'] ?? $first);
            $start = $candidate->start_time ? substr((string) $candidate->start_time, 0, 5) : null;
            $end = $candidate->end_time ? substr((string) $candidate->end_time, 0, 5) : null;
            $place = $candidate->location;
        }

        $when = $day($first).($last > $first ? ' to '.$day($last) : '');
        $time = $start ? $start.($end ? '–'.$end : '') : ($last > $first ? null : 'all day');
        $others = $sources - 1;

        return ($event ? 'From your calendar: ' : 'No longer in your calendar: ')
            .implode(', ', array_filter([$when, $time, $place ? mb_substr($place, 0, 80) : null]))
            .($others > 0 ? ' · and '.$others.' other '.($others === 1 ? 'source' : 'sources') : '');
    }

    /**
     * Where the item opens: the calendar entry itself (its month, with the entry open), the job finder for one whose
     * entry has left your calendar (there's nothing to open there), or the job finder's map of that year for one found
     * elsewhere. With $partOf, the entry opens on "Part of a job I have" with that job chosen.
     */
    public static function href(JobCandidate $candidate, ?CalendarEvent $event = null, ?WorkJob $partOf = null): string
    {
        return self::link($candidate, $event ?? self::calendarEvent($candidate), $partOf);
    }

    /** href() with the entry already read ($event null: it has left your calendar). */
    private static function link(JobCandidate $candidate, ?CalendarEvent $event, ?WorkJob $partOf = null): string
    {
        $source = self::calendarSource($candidate);
        if (! $source) {
            return '/connections?year='.UkTaxYear::fromDate($candidate->starts_on)->label();
        }
        if (! $event) {
            return '/jobs/finder';
        }
        $day = $event->day();

        return '/jobs/calendar?'.http_build_query(array_filter([
            'm' => substr($day, 0, 7),
            'entry' => $source['id'],
            'job' => $partOf?->id,
        ]));
    }

    /**
     * A job you have that this may be part of: within NEAR_DAYS of it, not cancelled, with the same client or the
     * same place (the same postcode, or the same venue: see samePlace()). The place alone never counts when both name
     * a client and they differ. The nearest wins; none when two are equally near. Never a job you've said its entry
     * or documents aren't part of, and none when you've said its entry isn't a job ($refused, read when not given).
     *
     * @param  Collection<int, WorkJob>|null  $jobs
     * @param  array{all: bool, jobs: list<int>}|null  $refused
     */
    public static function partOf(JobCandidate $candidate, ?Collection $jobs = null, ?array $refused = null): ?WorkJob
    {
        $refused ??= self::refused($candidate);
        if ($refused['all']) {
            return null;
        }
        $from = CarbonImmutable::parse($candidate->starts_on->format('Y-m-d'));
        $to = CarbonImmutable::parse(($candidate->ends_on ?? $candidate->starts_on)->format('Y-m-d'));
        $jobs ??= self::jobsBetween($from->subDays(self::NEAR_DAYS), $to->addDays(self::NEAR_DAYS));

        $scored = $jobs->filter(fn (WorkJob $j) => $j->status !== 'cancelled' && ! in_array($j->id, $refused['jobs'], true))
            ->map(function (WorkJob $j) use ($candidate, $from, $to) {
                $jobFrom = CarbonImmutable::parse($j->starts_on->format('Y-m-d'));
                $jobTo = CarbonImmutable::parse($j->ends_on->format('Y-m-d'));
                $gap = $jobTo->lt($from) ? (int) $jobTo->diffInDays($from) : ($jobFrom->gt($to) ? (int) $to->diffInDays($jobFrom) : 0);
                $same = self::sameClient($candidate, $j) || (! self::differentClients($candidate, $j) && self::samePlace($candidate, $j));

                return ['job' => $j, 'gap' => $gap, 'same' => $same];
            })
            ->filter(fn ($s) => $s['gap'] <= self::NEAR_DAYS && $s['same'])
            ->sortBy('gap')->values();

        if ($scored->isEmpty() || ($scored->count() > 1 && $scored[1]['gap'] === $scored[0]['gap'])) {
            return null;
        }

        return $scored[0]['job'];
    }

    /**
     * Sorts the next 14 days first (soonest first, including one under way), then the rest by date as before. One
     * whose calendar entry has gone ($gone) isn't counted as soon: it may not be on any more.
     */
    public static function sortKey(JobCandidate $candidate, ?CarbonInterface $today = null, bool $gone = false): string
    {
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::now(CalendarEvent::TZ))->format('Y-m-d'));
        $starts = $candidate->starts_on->format('Y-m-d');
        $ends = ($candidate->ends_on ?? $candidate->starts_on)->format('Y-m-d');
        $soon = ! $gone && $ends >= $today->format('Y-m-d') && $starts <= $today->addDays(self::SOON_DAYS)->format('Y-m-d');

        return ($soon ? '0' : '1').'|'.$starts.'|'.str_pad((string) $candidate->id, 10, '0', STR_PAD_LEFT);
    }

    /** @return array{key: string, type: string, id: string, date?: string, to?: string}|null the calendar entry it was found from */
    public static function calendarSource(JobCandidate $candidate): ?array
    {
        foreach ($candidate->sources ?? [] as $s) {
            if (($s['type'] ?? null) === 'calendar' && is_string($s['id'] ?? null) && $s['id'] !== '') {
                return $s;
            }
        }

        return null;
    }

    /**
     * What you've said about this possible job's sources: that a calendar entry of it isn't a job ('all'), or the jobs
     * an entry or a document isn't part of ("Not this job"). Read from the calendar links and the documents, as
     * Intel\Vetoes::allows() reads them for calendar_job and document_job.
     *
     * @param  Collection<string, CalendarJobLink>|null  $links  by event key (read when not given)
     * @param  Collection<int, Document>|null  $documents  by id (read when not given)
     * @return array{all: bool, jobs: list<int>}
     */
    public static function refused(JobCandidate $candidate, ?Collection $links = null, ?Collection $documents = null): array
    {
        $keys = self::sourceIds($candidate, 'calendar');
        $docIds = array_map('intval', self::sourceIds($candidate, 'document'));
        $links ??= $keys === [] ? collect() : CalendarJobLink::query()->whereIn('event_key', $keys)->get()->keyBy('event_key');
        $documents ??= $docIds === [] ? collect() : Document::query()->whereIn('id', $docIds)->get(['id', 'job_rejected'])->keyBy('id');
        $all = false;
        $jobs = [];
        foreach ($keys as $key) {
            if ($link = $links[$key] ?? null) {
                $all = $all || (bool) $link->not_job;
                array_push($jobs, ...array_map('intval', (array) ($link->rejected ?? [])));
            }
        }
        foreach ($docIds as $id) {
            array_push($jobs, ...array_map('intval', (array) ($documents[$id]->job_rejected ?? [])));
        }

        return ['all' => $all, 'jobs' => array_values(array_unique($jobs))];
    }

    /** @return list<string> the ids of this possible job's sources of one type ('calendar': event keys, 'document': ids) */
    private static function sourceIds(JobCandidate $candidate, string $type): array
    {
        $ids = [];
        foreach ($candidate->sources ?? [] as $s) {
            if (($s['type'] ?? null) === $type && ($s['id'] ?? null) !== null && (string) $s['id'] !== '') {
                $ids[] = (string) $s['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function calendarEvent(JobCandidate $candidate): ?CalendarEvent
    {
        $source = self::calendarSource($candidate);

        return $source ? CalendarEvent::query()->where('event_key', $source['id'])->first() : null;
    }

    /** @return Collection<int, WorkJob> */
    private static function jobsBetween(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return WorkJob::query()->with('client')->where('status', '!=', 'cancelled')
            ->whereDate('starts_on', '<=', $to->format('Y-m-d'))->whereDate('ends_on', '>=', $from->format('Y-m-d'))
            ->orderBy('starts_on')->get();
    }

    private static function sameClient(JobCandidate $candidate, WorkJob $job): bool
    {
        $a = self::client($candidate->client_name);
        $b = self::client($job->client?->name);

        return $a !== '' && $a === $b;
    }

    /** Both name a client, and neither name is the other's (as whole words): two clients' work, whatever the place. */
    private static function differentClients(JobCandidate $candidate, WorkJob $job): bool
    {
        $a = self::client($candidate->client_name);
        $b = self::client($job->client?->name);

        return $a !== '' && $b !== '' && ! self::withinWords($a, $b) && ! self::withinWords($b, $a);
    }

    /**
     * The same postcode, or the same venue: the part before the first comma ("ExCeL London" of "ExCeL London, Royal
     * Victoria Dock"), equal or one inside the other as whole words. A place that could be anywhere ("London", "Home",
     * "TBC": JobGatherer::vaguePlace()) or a word for a kind of place ("Hall", "Arena") says nothing on its own.
     */
    private static function samePlace(JobCandidate $candidate, WorkJob $job): bool
    {
        $pa = str_replace(' ', '', strtoupper((string) $candidate->postcode));
        $pb = str_replace(' ', '', strtoupper((string) $job->postcode));
        if ($pa !== '' && $pa === $pb) {
            return true;
        }
        if (JobGatherer::vaguePlace($candidate->location) || JobGatherer::vaguePlace($job->location)) {
            return false;
        }
        $a = self::norm(explode(',', (string) $candidate->location)[0]);
        $b = self::norm(explode(',', (string) $job->location)[0]);
        [$short, $long] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        if (mb_strlen($short) < 4 || in_array($short, self::GENERIC_PLACES, true) || JobGatherer::vaguePlace($short)) {
            return false;
        }

        return self::withinWords($short, $long);
    }

    /** Whether $needle is $haystack, or a run of whole words in it (both normalised). */
    private static function withinWords(string $needle, string $haystack): bool
    {
        return $needle !== '' && str_contains(' '.$haystack.' ', ' '.$needle.' ');
    }

    private static function client(?string $name): string
    {
        return self::norm(preg_replace(self::COMPANY_WORDS, ' ', mb_strtolower((string) $name)));
    }

    private static function norm(?string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $text)) ?? '');
    }

    /** A place to show: not an online meeting link. */
    private static function place(string $location): ?string
    {
        $location = trim($location);

        return $location === '' || preg_match('#^https?://|zoom\.us|teams\.microsoft|meet\.google#i', $location) ? null : $location;
    }
}
