<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Http\Controllers\JobFinderController;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\CalendarPick;
use App\Models\Client;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\TaxYear;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Days picked on the calendar (any days: a rig, show days and a de-rig, not one after another) made into one job, or
 * added to one you have, with the calendar entries you included.
 *
 *  - preview() says, for the days picked, which entries cover each one and what each is now (free, on this job, on
 *    another job, "not a job", "not this job"), which Expend ticks for you, each day's kind and times from them, the
 *    possible jobs made only of them, and what to call the job. It writes nothing.
 *  - create() and addTo() save what you saw and chose: the days with your kinds, times and notes (they're yours),
 *    the entries linked as yours (adding no days of their own), the ones you unticked remembered as "not part of this
 *    job", and possible jobs made only of these entries closed. What changed since you looked (an entry linked
 *    elsewhere by you, moved, or gone) is left as it is and named.
 *
 * One pick is one token (a ULID, CalendarPick): a second tap, or a retry after a lost answer, never makes a second
 * job. Everything is written in one transaction, which takes the audit head first and then the job and the entries'
 * links (one lock order for every audited writer), and every event in it carries the token as its correlation.
 * Days in a tax year you've locked are added and named: a job's days are dates, and that year's figures stay as they
 * are (nothing that writes figures writes into a locked year).
 */
final class DayPick
{
    /** The most days picked at once. */
    public const MAX_DATES = 120;

    /** The most calendar entries in one pick (included, or left out). */
    public const MAX_ENTRIES = 150;

    /** The most possible jobs closed by one pick. */
    public const MAX_CANDIDATES = 10;

    /** How far (in days) a day added to a job can be from the job's first or last day (as "Add a day"). */
    public const NEAR_JOB = 120;

    /** How far from the picked days an entry with the same title is offered ("Also in your calendar"). */
    public const RELATED_DAYS = 14;

    /** The most jobs offered for "Add to job". */
    public const JOB_OPTIONS = 12;

    public const KINDS_SEEN = ['free', 'this_job', 'other_job', 'not_job', 'not_this_job'];

    private const REASON_LINK = 'You picked it with the job’s days';

    private const REASON_LEFT_OUT = 'Left out when you picked the job’s days';

    public function __construct(private JobFinder $finder) {}

    // ---------------------------------------------------------------- preview (reads only)

    /**
     * @param  array{pick?: ?string, dates: list<string>, overrides?: array<string, string>, job_id?: ?int}  $input
     */
    public function preview(array $input): array
    {
        $token = isset($input['pick']) && is_string($input['pick']) && AuditContext::isCorrelation($input['pick']) ? $input['pick'] : null;
        if ($token && ($done = CalendarPick::query()->with('job:id,title')->where('token', $token)->first())) {
            return ['pick' => $token, 'done' => ['job_id' => $done->work_job_id, 'title' => $done->job?->title, 'mode' => $done->mode]];
        }
        $job = ! empty($input['job_id']) ? WorkJob::query()->with('days')->find($input['job_id']) : null;
        $dates = collect($input['dates'])->map(fn ($d) => CarbonImmutable::parse($d)->format('Y-m-d'))->unique()->sort()->values()->all();
        $overrides = array_filter((array) ($input['overrides'] ?? []), fn ($v, $k) => is_string($k) && preg_match('/^[0-9a-f]{40}$/', $k) && in_array($v, ['in', 'out'], true), ARRAY_FILTER_USE_BOTH);
        $picked = array_flip($dates);
        $first = $dates[0];
        $last = $dates[count($dates) - 1];

        // The entries on the picked days (and in the small hours after each) are read run by run, each with its own
        // limit, so a busy calendar can't push a later day's entries out; then those around them, for "Also in your
        // calendar" (only that list can come up short).
        $around = collect();
        foreach (self::runs($dates) as [$a, $b]) {
            $around = $around->concat(CalendarMonth::covering($a, CarbonImmutable::parse($b)->addDay()->format('Y-m-d')));
        }
        $around = $around->concat(CalendarMonth::covering(CarbonImmutable::parse($first)->subDays(self::RELATED_DAYS)->format('Y-m-d'), CarbonImmutable::parse($last)->addDays(self::RELATED_DAYS)->format('Y-m-d')))
            ->unique('id')->sortBy(fn (CalendarEvent $e) => [$e->starts_at->getTimestamp(), $e->id])->values();
        $links = $this->links($around->map->key()->all());
        $jobIds = $links->pluck('work_job_id')->filter()->unique()->values()->all();
        $jobs = WorkJob::query()->with('days')->whereIn('id', $jobIds)->get()->keyBy('id');
        $jobLinked = $job ? CalendarJobLink::query()->where('work_job_id', $job->id)->get() : collect();
        $jobBases = $jobLinked->map(fn (CalendarJobLink $l) => JobDays::baseTitle($l->summary))->filter(fn ($b) => mb_strlen($b) >= 4)->unique()->values()->all();

        // The entries shown: those covering a picked day, and those starting in the small hours after one (folded).
        $shown = [];
        $nearby = [];
        foreach ($around as $e) {
            $covers = self::coveredOf($e, $dates);
            $fold = self::foldOnto($e, $picked);
            if ($covers === [] && $fold === null) {
                $nearby[] = $e;

                continue;
            }
            $shown[$e->key()] = ['event' => $e, 'covers' => $covers, 'fold' => $fold, 'score' => $this->finder->workScore($e)] + $this->stateOf($links[$e->key()] ?? null, $job, $jobs);
        }

        // Which are ticked: Expend's own choice (default_include), then with what you've ticked and unticked (included).
        $default = $this->ticked($shown, $jobBases, []);
        $included = $this->ticked($shown, $jobBases, $overrides);
        $bases = collect($shown)->filter(fn ($s, $k) => isset($included[$k]))->map(fn ($s) => JobDays::baseTitle($s['event']->summary))
            ->filter(fn ($b) => mb_strlen($b) >= 4)->merge($jobBases)->unique()->values()->all();

        $entries = [];
        foreach ($shown as $key => $s) {
            $e = $s['event'];
            $entries[] = [
                'key' => $key, 'date' => $e->day(), 'last_day' => JobDays::lastDay($e), 'summary' => $e->summary ?: '(no title)', 'location' => $e->location,
                'all_day' => (bool) $e->all_day, 'start_time' => $e->all_day ? null : $e->localStart()->format('H:i'),
                'end_time' => $e->all_day || ! $e->ends_at ? null : $e->localEnd()->format('H:i'),
                'recurring' => $e->recurrence_id !== null, 'base' => JobDays::baseTitle($e->summary), 'score' => $s['score'],
                'partial' => ['picked' => count($s['covers']), 'total' => self::length($e)],
                'fold_onto' => $s['fold'],
                'state' => $s['state'], 'by' => $s['by'], 'other_job' => $s['other_job'],
                'default_include' => isset($default[$key]), 'included' => isset($included[$key]),
                'move_default' => $s['state'] === 'other_job' && $s['by'] !== 'you',
            ];
        }
        $entryKeys = array_keys($shown);

        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->flip()->all();
        $others = CalendarMonth::jobDays($first, $last, $job?->id);
        $days = [];
        foreach ($dates as $d) {
            $year = UkTaxYear::fromDate($d)->label();
            $own = $job?->dayOn($d);
            $days[] = [
                'date' => $d, 'tax_year' => $year, 'locked' => isset($locked[$year]),
                'other_jobs' => array_map(fn ($j) => ['id' => $j['job_id'], 'title' => $j['title'], 'cancelled' => $j['cancelled']], $others[$d] ?? []),
                'on_job' => $own ? ['kind' => $own->kind, 'start_time' => self::minute($own->start_time), 'end_time' => self::minute($own->end_time), 'note' => $own->note] : null,
                'too_far' => $job && ! $own ? self::tooFar($job, $d) : false,
                // Adding it would make the job longer than JobDays::MAX_DAYS from its first day to its last.
                'too_long' => $job && ! $own ? self::span([$job->starts_on->format('Y-m-d'), $job->ends_on->format('Y-m-d'), $d]) > JobDays::MAX_DAYS : false,
                'suggested' => self::dayDefaults($d, $shown, $included),
                'entries' => array_values(array_filter($entryKeys, fn ($k) => in_array($d, $shown[$k]['covers'], true))),
                'folds' => array_values(array_filter($entryKeys, fn ($k) => $shown[$k]['fold'] === $d)),
            ];
        }

        // Free entries with the same title within two weeks, not on a picked day: "Also in your calendar".
        $related = collect($nearby)->filter(function (CalendarEvent $e) use ($links, $bases, $first, $last) {
            $base = JobDays::baseTitle($e->summary);

            return CalendarMonth::state($links[$e->key()] ?? null) === 'free' && mb_strlen($base) >= 4 && in_array($base, $bases, true)
                && $e->day() >= CarbonImmutable::parse($first)->subDays(self::RELATED_DAYS)->format('Y-m-d')
                && $e->day() <= CarbonImmutable::parse($last)->addDays(self::RELATED_DAYS)->format('Y-m-d');
        })->take(20)->map(fn (CalendarEvent $e) => [
            'key' => $e->key(), 'date' => $e->day(), 'last_day' => JobDays::lastDay($e), 'summary' => $e->summary ?: '(no title)', 'location' => $e->location,
            'all_day' => (bool) $e->all_day, 'start_time' => $e->all_day ? null : $e->localStart()->format('H:i'),
            'end_time' => $e->all_day || ! $e->ends_at ? null : $e->localEnd()->format('H:i'), 'base' => JobDays::baseTitle($e->summary),
        ])->values()->all();

        [$candidates, $partial] = $this->candidatesFor($entryKeys, array_keys($included), $job ? $jobLinked->where('linked_by', 'you')->pluck('event_key')->all() : [], $job);

        $out = [
            'pick' => $token ?? AuditContext::newCorrelation(),
            'dates' => $days,
            'entries' => $entries,
            'related' => $related,
            'candidates' => $candidates,
            'candidates_partial' => $partial,
        ];
        if ($job) {
            // bases: the titles of every entry on the job, which the review ticks the same title by (as the job's
            // search links it), so what it shows unticked is left out, not linked a moment after the save.
            $out['job'] = ['id' => $job->id, 'title' => $job->title, 'starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d'),
                'cancelled' => $job->status === 'cancelled', 'days' => $job->workDates(), 'has_mileage' => $job->mileageTrips()->exists(), 'bases' => $jobBases];
        } else {
            $suggest = $this->suggest($shown, $included, $candidates, $last);
            $out['suggest'] = $suggest;
            $out['job_options'] = $this->jobOptions($dates, $shown, $suggest);
        }

        return $out;
    }

    /**
     * Jobs you have for "Add to job": up to JOB_OPTIONS, never a cancelled one, those holding the entries picked first,
     * then the same client or title within 3 days of the picked days, then the nearest.
     *
     * @return list<array{id:int, title:string, client:?string, starts_on:string, ends_on:string, days:list<string>, holds:int}>
     */
    private function jobOptions(array $dates, array $shown, array $suggest): array
    {
        $holds = [];
        foreach ($shown as $s) {
            if ($s['state'] === 'other_job' && ! $s['other_job']['cancelled']) {
                $holds[$s['other_job']['id']] = ($holds[$s['other_job']['id']] ?? 0) + 1;
            }
        }
        $first = CarbonImmutable::parse($dates[0]);
        $last = CarbonImmutable::parse($dates[count($dates) - 1]);
        $jobs = WorkJob::query()->with(['client:id,name', 'days'])->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('id', array_keys($holds))->orWhere(fn ($q) => $q
                ->whereDate('ends_on', '>=', $first->subDays(self::NEAR_JOB)->format('Y-m-d'))->whereDate('starts_on', '<=', $last->addDays(self::NEAR_JOB)->format('Y-m-d'))))
            ->limit(300)->get();
        $title = JobDays::baseTitle($suggest['title'] ?? '');

        return $jobs->map(function (WorkJob $j) use ($dates, $holds, $suggest, $title) {
            $gap = self::gap($j->workDates(), $dates);
            $same = $gap <= 3 && (($suggest['client_id'] ?? null) && $j->client_id === $suggest['client_id']
                || (mb_strlen($title) >= 4 && JobDays::baseTitle($j->title) === $title));

            return ['job' => $j, 'holds' => $holds[$j->id] ?? 0, 'same' => $same, 'gap' => $gap];
        })->sort(fn ($a, $b) => [$b['holds'], $b['same'], $a['gap'], $a['job']->id] <=> [$a['holds'], $a['same'], $b['gap'], $b['job']->id])
            ->take(self::JOB_OPTIONS)->map(fn ($r) => [
                'id' => $r['job']->id, 'title' => $r['job']->title, 'client' => $r['job']->client?->name,
                'starts_on' => $r['job']->starts_on->format('Y-m-d'), 'ends_on' => $r['job']->ends_on->format('Y-m-d'),
                'days' => $r['job']->workDates(), 'holds' => $r['holds'],
            ])->values()->all();
    }

    /**
     * Jobs whose title or client name has $q in it, nearest to $near first (never a cancelled one): the search on
     * "Add to job".
     *
     * @return list<array{id:int, title:string, client:?string, starts_on:string, ends_on:string, days:list<string>}>
     */
    public static function searchJobs(?string $q, ?string $near): array
    {
        $q = trim((string) $q);
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
        $jobs = WorkJob::query()->with(['client:id,name', 'days'])->where('status', '!=', 'cancelled')
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('title', 'like', $like)->orWhereHas('client', fn ($c) => $c->where('name', 'like', $like))))
            ->when($near, fn ($w) => $w->whereDate('ends_on', '>=', CarbonImmutable::parse($near)->subDays(400)->format('Y-m-d'))
                ->whereDate('starts_on', '<=', CarbonImmutable::parse($near)->addDays(400)->format('Y-m-d')))
            ->orderByDesc('starts_on')->limit(300)->get();
        if ($near) {
            $jobs = $jobs->sortBy(fn (WorkJob $j) => [self::gap($j->workDates(), [$near]), $j->id])->values();
        }

        return $jobs->take(self::JOB_OPTIONS)->map(fn (WorkJob $j) => [
            'id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name, 'starts_on' => $j->starts_on->format('Y-m-d'),
            'ends_on' => $j->ends_on->format('Y-m-d'), 'days' => $j->workDates(),
        ])->values()->all();
    }

    /**
     * What to call the job, and its client, venue and PO, from the entries ticked: a possible job made only of them
     * that you edited first (your edits are kept), else the title most of them share (without "Rig", "Show"), the
     * first client one of them names, the venue most of them give. Kept within the job form's limits, so the form
     * saves as it loads. Done once its last day has gone (in the UK).
     */
    private function suggest(array $shown, array $included, array $candidates, string $last): array
    {
        $in = collect($shown)->filter(fn ($s, $k) => isset($included[$k]))->sortBy(fn ($s) => [$s['event']->starts_at->getTimestamp(), $s['event']->id])->values();
        $edited = collect($candidates)->first(fn ($c) => $c['covered'] && $c['edited']);
        $po = $edited['po_number'] ?? collect($candidates)->first(fn ($c) => $c['covered'] && $c['po_number'])['po_number'] ?? null;

        $title = $edited['title'] ?? null;
        if ($title === null || trim($title) === '') {
            $byBase = $in->groupBy(fn ($s) => JobDays::baseTitle($s['event']->summary))->filter(fn ($g, $b) => $b !== '');
            $top = $byBase->sortByDesc(fn ($g) => $g->count())->first();
            $title = $top ? JobDays::cleanTitle($top->first()['event']->summary) : '';
            if ($title === '') {
                $title = (string) ($in->first()['event']->summary ?? '');
            }
        }

        $clientId = null;
        $newClient = null;
        if (! empty($edited['client_name'])) {
            $clientId = Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($edited['client_name']))])->value('id');
            $newClient = $clientId ? null : mb_substr(trim($edited['client_name']), 0, 120);
        } else {
            $clients = Client::query()->orderBy('name')->get(['id', 'name']);
            foreach ($in as $s) {
                if ($c = $clients->first(fn (Client $c) => CalendarMonth::mentions($s['event']->text(), $c->name))) {
                    $clientId = $c->id;
                    break;
                }
            }
        }

        $location = $edited['location'] ?? null;
        if ($location === null || trim($location) === '') {
            $places = $in->map(fn ($s) => trim((string) $s['event']->location))->filter(fn ($l) => $l !== '' && ! JobFinder::isLink($l));
            $location = $places->countBy()->sortDesc()->keys()->first(); // the most common; the earliest of equals (countBy keeps order)
            $location = $location !== null ? (string) $location : null;
        }
        $postcode = $edited['postcode'] ?? null;
        $postcode = $postcode !== null && trim($postcode) !== '' ? $postcode : ($location ? CalendarMonth::postcode($location) : null);

        return [
            'title' => $title !== '' ? JobFinderController::shorten(trim((string) preg_replace('/\s+/u', ' ', $title)), 150) : '',
            'client_id' => $clientId,
            'new_client' => $newClient,
            'location' => $location ? JobFinderController::shorten(trim((string) preg_replace('/\s+/u', ' ', $location)), 200) : null,
            'postcode' => $postcode ? mb_substr(strtoupper(trim($postcode)), 0, 10) : null,
            'po_number' => $po ? mb_substr(trim((string) $po), 0, 100) : null,
            'status' => $last < CalendarMonth::today() ? 'done' : 'booked',
            'from_candidate' => $edited['id'] ?? null,
        ];
    }

    /**
     * Open possible jobs whose calendar entries are all shown here (or already yours on the job), with what else they
     * were found from; and how many more have only some of them here.
     *
     * @param  list<string>  $shown  keys of the entries shown
     * @param  list<string>  $included  keys of those ticked
     * @param  list<string>  $onJob  keys already yours on the job (adding to one)
     * @param  WorkJob|null  $job  the job added to: a document you said isn't part of it is marked rejected
     * @return array{0: list<array>, 1: int}
     */
    private function candidatesFor(array $shown, array $included, array $onJob, ?WorkJob $job = null): array
    {
        $shownSet = array_flip($shown);
        $have = array_flip([...$shown, ...$onJob]);
        $ticked = array_flip([...$included, ...$onJob]);
        $out = [];
        $partial = 0;
        foreach (JobCandidate::query()->where('status', 'open')->orderBy('id')->get() as $c) {
            $cal = collect($c->sources ?? [])->where('type', 'calendar')->pluck('id')->map(fn ($k) => (string) $k)->unique()->values()->all();
            if ($cal === [] || ! array_filter($cal, fn ($k) => isset($shownSet[$k]))) {
                continue;
            }
            if (array_filter($cal, fn ($k) => ! isset($have[$k]))) {
                $partial++;

                continue;
            }
            $out[] = [
                'id' => $c->id, 'title' => $c->title, 'client_name' => $c->client_name, 'location' => $c->location, 'postcode' => $c->postcode,
                'po_number' => $c->po_number, 'edited' => $c->edited_at !== null, 'calendar_keys' => $cal,
                'others' => collect($c->sources ?? [])->where('type', '!=', 'calendar')->map(fn ($s) => ['type' => $s['type'], 'id' => $s['id'], 'label' => (string) ($s['label'] ?? ''),
                    'rejected' => $job && $s['type'] === 'document' && in_array($job->id, Document::query()->whereKey($s['id'])->value('job_rejected') ?? [], true)])->values()->all(),
                'covered' => ! array_filter($cal, fn ($k) => ! isset($ticked[$k])),
            ];
        }

        return [$out, $partial];
    }

    /**
     * A day's kind and times from the entries ticked on it: the kind of the earliest that says which day it is (a rig,
     * a show), the earliest start of those starting that day, and the latest finish of those ending that day or folded
     * onto it (a load-out at 00:30–04:00 after the show). A timed entry that ends on a day not picked gives no finish.
     *
     * @return array{kind: string, start_time: ?string, end_time: ?string}
     */
    private static function dayDefaults(string $date, array $shown, array $included): array
    {
        $kind = 'work';
        $start = null;
        $end = null;
        $on = collect($shown)->filter(fn ($s, $k) => isset($included[$k]) && (in_array($date, $s['covers'], true) || $s['fold'] === $date))
            ->sortBy(fn ($s) => [$s['event']->starts_at->getTimestamp(), $s['event']->id]);
        foreach ($on as $s) {
            $e = $s['event'];
            if ($s['fold'] !== null && $s['fold'] !== $date) {
                continue; // a small-hours entry folded onto the evening before is that evening's, not this day's
            }
            if (in_array($date, $s['covers'], true) && $kind === 'work') {
                $kind = JobDays::kindFrom((string) $e->summary);
            }
            if ($e->all_day) {
                continue;
            }
            if ($e->day() === $date) {
                $start = JobDays::earliest($start, $e->localStart()->format('H:i'));
            }
            if ($e->ends_at && (JobDays::lastDay($e) === $date || $s['fold'] === $date)) {
                $end = JobDays::latest($end, $e->localEnd()->format('H:i'));
            }
        }

        return ['kind' => $kind, 'start_time' => $start, 'end_time' => $end];
    }

    /**
     * Which entries are ticked: free ones that look like work (CalendarMonth::ticks) on a picked day, and ones already
     * on the job; then any free one on a picked day with the same title as one ticked or one on the job (the job's
     * own search links those, so what you see ticked is what it links); then, if given, what you ticked and unticked.
     *
     * @param  array<string, array>  $shown
     * @param  list<string>  $jobBases  the titles (baseTitle) of the entries on the job
     * @param  array<string, string>  $overrides  key => in|out
     * @return array<string, true>
     */
    private function ticked(array $shown, array $jobBases, array $overrides): array
    {
        $in = [];
        foreach ($shown as $key => $s) {
            $e = $s['event'];
            if (($overrides[$key] ?? null) === 'in' || (! isset($overrides[$key]) && $s['covers'] !== []
                && ($s['state'] === 'this_job' || CalendarMonth::ticks($s['state'], $s['score'], $e->recurrence_id !== null)))) {
                $in[$key] = true;
            }
        }
        $bases = array_flip(array_filter([...$jobBases, ...array_map(fn ($k) => JobDays::baseTitle($shown[$k]['event']->summary), array_keys($in))], fn ($b) => mb_strlen($b) >= 4));
        foreach ($shown as $key => $s) {
            if (! isset($in[$key]) && ! isset($overrides[$key]) && $s['state'] === 'free' && $s['covers'] !== [] && isset($bases[JobDays::baseTitle($s['event']->summary)])) {
                $in[$key] = true;
            }
        }

        return $in;
    }

    /**
     * What an entry's link is now, for the job being added to (none when making a new one): free, this_job, other_job
     * (with what moving it costs that job), not_job or not_this_job.
     *
     * @return array{state: string, by: ?string, other_job: ?array{id:int, title:string, by:?string, cancelled:bool, loses:list<string>, only_day:bool, keeps:?string}}
     */
    private function stateOf(?CalendarJobLink $link, ?WorkJob $job, Collection $jobs): array
    {
        if ($link?->not_job) {
            return ['state' => 'not_job', 'by' => $link->linked_by, 'other_job' => null];
        }
        if ($link?->work_job_id && $job && $link->work_job_id === $job->id) {
            return ['state' => 'this_job', 'by' => $link->linked_by, 'other_job' => null];
        }
        if ($link?->work_job_id) {
            $other = $jobs[$link->work_job_id] ?? null;
            $all = $other ? $other->days : collect();
            // Moving it takes off that job the days it brought there (never the job's last day: JobDays::forgetCalendarKey).
            $loses = $all->filter(fn (JobDay $d) => $d->calendar_key === $link->event_key && $d->source === 'calendar')
                ->map(fn (JobDay $d) => $d->on_date->format('Y-m-d'))->sort()->values()->all();
            $only = $loses !== [] && count($loses) >= $all->count();
            // It keeps one day: the latest (forgetCalendarKey goes oldest first and leaves the last).
            $keeps = $only ? array_pop($loses) : null;

            return ['state' => 'other_job', 'by' => $link->linked_by, 'other_job' => [
                'id' => $link->work_job_id, 'title' => (string) ($other?->title ?? 'another job'), 'by' => $link->linked_by,
                'cancelled' => $other?->status === 'cancelled', 'loses' => $loses, 'only_day' => $only, 'keeps' => $keeps,
            ]];
        }
        if ($job && in_array($job->id, $link?->rejected ?? [], true)) {
            return ['state' => 'not_this_job', 'by' => $link->linked_by, 'other_job' => null];
        }

        return ['state' => 'free', 'by' => null, 'other_job' => null];
    }

    // ---------------------------------------------------------------- saving

    /**
     * A new job from the days picked, with your details.
     *
     * @param  array  $data  validated (CalendarPickController::store): pick, days, entries, left_out, candidates and the job's fields
     * @return array the result, for message()
     *
     * @throws InvalidArgumentException a refusal, in plain words: nothing was written
     */
    public function create(array $data): array
    {
        return $this->save('create', null, $data);
    }

    /**
     * Days picked added to a job you have (the calendar's "Add to job", or "Add days" on the job page).
     *
     * @throws InvalidArgumentException a refusal, in plain words: nothing was written
     */
    public function addTo(WorkJob $job, array $data): array
    {
        return $this->save('add', $job, $data);
    }

    private function save(string $mode, ?WorkJob $target, array $data): array
    {
        $token = (string) $data['pick'];
        $days = collect($data['days'])->map(fn ($d) => [
            'date' => CarbonImmutable::parse($d['date'])->format('Y-m-d'), 'kind' => $d['kind'] ?? 'work',
            'start_time' => self::minute($d['start_time'] ?? null), 'end_time' => self::minute($d['end_time'] ?? null),
            'note' => isset($d['note']) && trim((string) $d['note']) !== '' ? trim((string) $d['note']) : null,
        ])->sortBy('date')->values()->all();
        $entries = array_values($data['entries'] ?? []);
        $leftOut = array_values($data['left_out'] ?? []);

        // Before the transaction: the entries, read once, and whether each is still on the day it was picked with.
        $events = $this->events([...$entries, ...$leftOut]);
        $names = $this->names($events, [...$entries, ...$leftOut]);
        $picked = array_flip(array_column($days, 'date'));
        $result = ['mode' => $mode, 'gone' => [], 'moved' => [], 'skipped' => [], 'left' => []];
        $usable = [];
        foreach ($entries as $item) {
            $e = $events[$item['key']] ?? null;
            if (! $e) {
                $result['gone'][] = $names[$item['key']];

                continue;
            }
            if (! self::isOn($e, $item['on'], $picked)) {
                // Moved since: still one of the picked days' entries when it's on (or folds onto) another of them.
                $on = self::coveredOf($e, array_keys($picked))[0] ?? self::foldOnto($e, $picked);
                if ($on === null) {
                    $result['moved'][] = [$names[$item['key']], $e->day()];

                    continue;
                }
                $item['on'] = $on;
            }
            $usable[] = $item;
        }

        return AuditContext::with('you', fn () => DB::transaction(function () use ($mode, $target, $data, $token, $days, $usable, $leftOut, $events, $names, $picked, $result) {
            // The job goes on the token row at the end: naming it now would lock the job's row (its foreign key)
            // before the audit head, against the one lock order (the head, then the job, then the links).
            $pick = CalendarPick::query()->create(['token' => $token, 'mode' => $mode, 'work_job_id' => null]);
            AuditLog::lockHead();
            $job = null;
            if ($target) {
                $job = WorkJob::query()->whereKey($target->id)->lockForUpdate()->first();
                if (! $job || $job->status === 'cancelled') {
                    throw new InvalidArgumentException('That job is cancelled. Pick another job or make a new one.');
                }
                self::checkReach($job, array_column($days, 'date'));
            }
            $keys = array_values(array_unique([...array_column($usable, 'key'), ...array_column($leftOut, 'key')]));
            sort($keys);
            $links = $keys === [] ? collect() : CalendarJobLink::query()->whereIn('event_key', $keys)->orderBy('event_key')->lockForUpdate()->get()->keyBy('event_key');

            [$take, $confirm, $mine, $result] = $this->resolveEntries($usable, $links, $job, $events, $names, $result);

            // The entries this pick puts on the job (or confirms there), oldest first: each day is named after the
            // earliest that covers it or is folded onto it.
            $linked = collect([...$take, ...$confirm, ...$mine])->unique('key')
                ->sortBy(fn ($i) => [$events[$i['key']]->starts_at->getTimestamp(), $events[$i['key']]->id])->values();
            $dayKey = [];
            foreach ($linked as $i) {
                $e = $events[$i['key']];
                // A small-hours entry folded onto the evening before names that evening only.
                $fold = self::foldOnto($e, $picked);
                foreach ($fold !== null ? [$fold] : self::coveredOf($e, array_keys($picked)) as $d) {
                    $dayKey[$d] ??= $e->key();
                }
            }

            if ($mode === 'create') {
                // Each day is named after its entry as it's made, so the job's history says so from the start.
                $job = JobDays::withCalendarKeys($dayKey, fn () => $this->makeJob($data, $days, $linked->map(fn ($i) => $events[$i['key']])->values()->all()));
                $result['added'] = array_column($days, 'date');
                $result['changed'] = [];
            } else {
                $rows = array_map(fn ($d) => $d + ['calendar_key' => $dayKey[$d['date']] ?? null], $days);
                $r = JobDays::addMany($job, $rows, 'Days you picked');
                $result['added'] = $r['added'];
                $result['changed'] = $r['changed'];
            }

            foreach ($take as $i) {
                $this->finder->linkCalendar($events[$i['key']], $job, 'you', self::REASON_LINK, null, null, false);
            }
            foreach ($confirm as $i) {
                if ($link = CalendarJobLink::query()->where('event_key', $i['key'])->first()) {
                    $this->finder->confirmCalendarLink($link);
                }
            }
            $already = array_flip(array_column($mine, 'key')); // yours here already: not named as linked
            $result['linked'] = $linked->reject(fn ($i) => isset($already[$i['key']]))->map(fn ($i) => $names[$i['key']])->values()->all();
            $result['left_out'] = $this->leaveOut($leftOut, $links, $job, $events);
            [$result['candidates'], $result['vetoed']] = $this->closeCandidates($data['candidates'] ?? [], $job, $mode);
            $pick->update(['work_job_id' => $job->id]);

            $result['job'] = $job->fresh();
            $result['locked'] = self::lockedDays($result['added']);
            $result['mileage'] = $mode === 'add' && $result['added'] !== [] && $job->mileageTrips()->exists();

            return $result;
        }, 3), 'web', $token);
    }

    /** The job itself, made the one way jobs are made (JobCreator), with exactly the days picked and what they came from. */
    private function makeJob(array $data, array $days, array $linked): WorkJob
    {
        $evidence = array_map(fn (CalendarEvent $e) => ['type' => 'calendar_event', 'id' => $e->key(), 'quote' => (string) $e->summary, 'verified' => true], $linked);
        if (count($evidence) > 40) {
            $more = count($evidence) - 39;
            $evidence = [...array_slice($evidence, 0, 39), '…and '.$more.' more calendar entries, each linked in this job’s history'];
        }
        $n = count($linked);
        $fields = [
            'title' => $data['title'], 'client_id' => $data['client_id'] ?? null,
            // A new client is added inside the same transaction, so a refused pick leaves no client behind.
            'client_name' => empty($data['client_id']) && ! empty($data['new_client']) ? $data['new_client'] : null,
            'dates' => array_map(fn ($d) => array_filter($d, fn ($v) => $v !== null), $days),
            // The job's own times only when both are known (each day has its own): a lone finish reads oddly.
            'start_time' => $days[0]['start_time'] && $days[count($days) - 1]['end_time'] ? $days[0]['start_time'] : null,
            'end_time' => $days[0]['start_time'] && $days[count($days) - 1]['end_time'] ? $days[count($days) - 1]['end_time'] : null,
            'location' => $data['location'] ?? null, 'postcode' => $data['postcode'] ?? null,
            'fee_pence' => $data['fee_pence'] ?? null, 'po_number' => $data['po_number'] ?? null, 'po_not_required' => (bool) ($data['po_not_required'] ?? false),
            'status' => $data['status'] ?? 'booked', 'notes' => $data['notes'] ?? null,
        ];

        return app(JobCreator::class)->create($fields, 'you', $evidence,
            $n ? 'From '.$n.' calendar '.($n === 1 ? 'entry' : 'entries').' you picked' : 'From the days you picked', false);
    }

    /**
     * What to do with each entry, by what you were shown and what it is now (its link, locked). Your press beats
     * automation; it never beats what you did yourself since.
     *
     * @return array{0: list<array>, 1: list<array>, 2: list<array>, 3: array} to link, to confirm, already yours here, and the result
     */
    private function resolveEntries(array $items, Collection $links, ?WorkJob $job, array $events, array $names, array $result): array
    {
        $take = [];
        $confirm = [];
        $mine = [];
        $titles = WorkJob::query()->whereIn('id', $links->pluck('work_job_id')->filter()->unique()->values()->all())->pluck('title', 'id');
        foreach ($items as $i) {
            $link = $links[$i['key']] ?? null;
            $name = $names[$i['key']];
            $here = $job && $link?->work_job_id === $job->id;
            $yours = $link?->linked_by === 'you';
            $elsewhere = $link?->work_job_id && ! $here ? (string) ($titles[$link->work_job_id] ?? 'another job') : null;
            $notThis = $job && ! $link?->work_job_id && ! $link?->not_job && in_array($job->id, $link?->rejected ?? [], true);
            // What happens to it: take (link it here as yours), confirm (an automatic link here becomes yours), mine
            // (yours here already), left (left on its job, as you chose), or a reason it was skipped.
            $what = match ($i['seen']) {
                'free' => match (true) {
                    $here => $yours ? 'mine' : 'confirm',
                    (bool) $link?->not_job => 'you marked it not a job since',
                    $elsewhere !== null => $yours ? 'you linked it to '.$elsewhere.' since' : 'take', // your press beats automation
                    $notThis => 'you said it isn’t part of this job since',
                    default => 'take',
                },
                'other_job' => match (true) {
                    $here => $yours ? 'mine' : 'confirm',
                    empty($i['take']) => 'left',
                    // Still on the job you saw, but you confirmed it there since Expend ticked Move for you: it stays.
                    $link?->work_job_id && (int) $link->work_job_id === (int) ($i['seen_job_id'] ?? 0) => $yours && ($i['seen_by'] ?? 'you') !== 'you' ? 'you confirmed it on '.$elsewhere.' since' : 'take',
                    default => $elsewhere !== null ? 'it’s on '.$elsewhere.' now' : 'it came off that job since',
                },
                'not_job' => empty($i['take']) ? null : ($link?->not_job ? 'take' : 'it changed since you looked'),
                'not_this_job' => empty($i['take']) ? null : ($notThis ? 'take' : 'it changed since you looked'),
                'this_job' => $here ? ($yours ? 'mine' : 'confirm') : 'it came off the job since',
                default => null,
            };
            match ($what) {
                null => null,
                'take' => $take[] = $i,
                'confirm' => $confirm[] = $i,
                'mine' => $mine[] = $i,
                'left' => $result['left'][] = [$name, $elsewhere ?? 'its job'],
                default => $result['skipped'][] = [$name, $what],
            };
        }

        return [$take, $confirm, $mine, $result];
    }

    /**
     * Entries Expend ticked that you unticked: "not part of this job", so no search links them here (linking one
     * yourself later lifts it). Only for an entry that's free, linked automatically elsewhere, or (adding to a job)
     * linked automatically here; never for a link you made, or an entry you said isn't a job.
     *
     * @return int how many
     */
    private function leaveOut(array $items, Collection $links, WorkJob $job, array $events): int
    {
        $n = 0;
        foreach ($items as $i) {
            $e = $events[$i['key']] ?? null;
            $link = $links[$i['key']] ?? null;
            if (! $e || $link?->not_job || ($link?->work_job_id && $link->linked_by === 'you')) {
                continue;
            }
            $this->finder->rejectCalendarFor($e, $job->id, self::REASON_LEFT_OUT);
            $n++;
        }

        return $n;
    }

    /**
     * Possible jobs made only of entries that are now yours on the job are closed: accepted (a new job) or merged (added
     * to one). One also found from documents or stays is closed only when you said to bring those too (they're then
     * linked as yours, unless you put them on another job, or said a document isn't part of this job); otherwise it
     * stays open. Adding to a job, one you edited fills the job's blank PO number, venue and postcode with what you
     * typed (as "Part of a job I have" does).
     *
     * @param  list<array{id:int, others?:bool}>  $items
     * @return array{0: int, 1: list<string>} how many were closed, and the documents left off because you said they
     *                                        aren't part of this job
     */
    private function closeCandidates(array $items, WorkJob $job, string $mode): array
    {
        $closed = 0;
        $vetoed = [];
        foreach ($items as $item) {
            $c = JobCandidate::query()->whereKey($item['id'])->where('status', 'open')->lockForUpdate()->first();
            if (! $c) {
                continue; // decided since
            }
            $cal = collect($c->sources ?? [])->where('type', 'calendar')->pluck('id')->map(fn ($k) => (string) $k)->unique()->values()->all();
            if ($cal === [] || CalendarJobLink::query()->whereIn('event_key', $cal)->where('work_job_id', $job->id)->where('linked_by', 'you')->count() !== count($cal)) {
                continue;
            }
            $others = collect($c->sources ?? [])->where('type', '!=', 'calendar')->isNotEmpty();
            $linked = 0;
            if ($others) {
                if (empty($item['others'])) {
                    continue;
                }
                // A tick that was on for you never lifts a "not part of this job" you gave a document.
                $r = $this->finder->linkSources($c, $job, null, ['document', 'travel'], true);
                $linked = $r['linked'];
                array_push($vetoed, ...$r['vetoed']);
            }
            if ($mode === 'add' && $c->edited_at !== null) {
                $this->fillFrom($c, $job);
            }
            $status = $mode === 'create' ? 'accepted' : 'merged';
            $c->update(['status' => $status, 'work_job_id' => $job->id, 'decided_at' => now()]);
            AuditLog::record('jobs.candidate_closed', $c, null, ['status' => $status, 'work_job_id' => $job->id, 'sources' => $c->sourceKeys(), 'others_linked' => $linked],
                'Its calendar entries were picked with the job’s days');
            $closed++;
        }

        return [$closed, array_values(array_unique($vetoed))];
    }

    /** The job's blank PO number, venue and postcode, filled with what you typed on a possible job (audited). */
    private function fillFrom(JobCandidate $c, WorkJob $job): void
    {
        $fill = array_filter([
            'po_number' => $job->po_number ? null : (trim((string) $c->po_number) !== '' ? mb_substr(trim((string) $c->po_number), 0, 100) : null),
            'location' => $job->location ? null : (trim((string) $c->location) !== '' ? JobFinderController::shorten(trim((string) $c->location), 200) : null),
            'postcode' => $job->postcode ? null : (trim((string) $c->postcode) !== '' ? mb_substr(strtoupper(trim((string) $c->postcode)), 0, 10) : null),
        ]);
        if ($fill === []) {
            return;
        }
        $before = $job->toArray();
        $job->update($fill);
        AuditLog::record('job.updated', $job, $before, $job->fresh()->toArray(), 'Blank details filled from the possible job you edited');
    }

    /**
     * A day more than NEAR_JOB days from the job, or one that would make it longer than JobDays::MAX_DAYS, is refused,
     * naming the day.
     *
     * @param  list<string>  $dates
     */
    private static function checkReach(WorkJob $job, array $dates): void
    {
        $have = array_flip($job->workDates());
        $first = $job->starts_on->format('Y-m-d');
        $last = $job->ends_on->format('Y-m-d');
        foreach ($dates as $d) {
            if (! isset($have[$d]) && self::tooFar($job, $d)) {
                throw new InvalidArgumentException(self::label($d).' is more than four months from '.$job->title.'. Make it a separate job.');
            }
        }
        $all = [...$dates, $first, $last];
        if ((int) CarbonImmutable::parse(min($all))->diffInDays(CarbonImmutable::parse(max($all))) + 1 > JobDays::MAX_DAYS) {
            $far = max($dates) > $last ? max($dates) : min($dates);
            throw new InvalidArgumentException('Adding '.self::label($far).' would make '.$job->title.' longer than '.JobDays::MAX_DAYS.' days. Make it a separate job.');
        }
    }

    private static function tooFar(WorkJob $job, string $date): bool
    {
        $first = $job->starts_on->format('Y-m-d');
        $last = $job->ends_on->format('Y-m-d');
        $away = $date < $first ? CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($first)) : ($date > $last ? CarbonImmutable::parse($last)->diffInDays(CarbonImmutable::parse($date)) : 0);

        return abs($away) > self::NEAR_JOB;
    }

    // ---------------------------------------------------------------- the message

    /**
     * The one line said when the pick is saved: the days added or changed, the entries linked, those left where they
     * are (and why), days in a locked year, and what the job's search found. At most three names in each list.
     */
    public static function message(array $r, ?string $found): string
    {
        $text = self::sentences($r, $found, 3);

        // About 600 characters at most: with long names, each list names only its first.
        return mb_strlen($text) > 600 ? self::sentences($r, $found, 1) : $text;
    }

    private static function sentences(array $r, ?string $found, int $max): string
    {
        $job = $r['job'];
        $added = $r['added'];
        $changed = $r['changed'];
        $list = fn (array $items) => self::joined($items, $max);
        $parts = [];
        if ($r['mode'] === 'create') {
            $parts[] = 'Added '.$job->title.' with '.self::counted(count($added), 'day').': '.$list(array_map(self::label(...), $added)).'.';
        } elseif ($added || $changed) {
            $parts[] = trim(($added ? 'Added '.self::counted(count($added), 'day').' to '.$job->title.': '.$list(array_map(self::label(...), $added)).'.' : '')
                .($changed ? ' '.($added ? 'Changed ' : 'Changed '.$job->title.' on ').$list(array_map(self::label(...), $changed)).'.' : ''));
        } else {
            $parts[] = 'Nothing new to add to '.$job->title.'.';
        }
        if ($r['linked']) {
            $parts[] = 'Linked '.self::counted(count($r['linked']), 'calendar entry', 'calendar entries').(count($r['linked']) <= $max ? ': '.$list($r['linked']) : '').'.';
        }
        if ($r['left']) {
            $parts[] = 'Left where '.(count($r['left']) === 1 ? 'it is' : 'they are').': '.$list(array_map(fn ($l) => $l[0].' on '.$l[1], $r['left'])).'.';
        }
        if ($r['skipped']) {
            $parts[] = 'Not linked: '.$list(array_map(fn ($s) => $s[0].' ('.$s[1].')', $r['skipped'])).'.';
        }
        if ($r['moved']) {
            $parts[] = 'Moved since you picked, so not linked: '.$list(array_map(fn ($m) => $m[0].' (now '.self::label($m[1]).')', $r['moved'])).'.';
        }
        if ($r['gone']) {
            $parts[] = 'Gone from your calendar: '.$list($r['gone']).'.';
        }
        if (($r['left_out'] ?? 0) > 0) {
            $parts[] = self::counted($r['left_out'], 'entry', 'entries').' you left out won’t be linked to it automatically.';
        }
        if (($r['candidates'] ?? 0) > 0) {
            $parts[] = 'Closed '.self::counted($r['candidates'], 'possible job').' made of these entries.';
        }
        if (! empty($r['vetoed'])) {
            $parts[] = 'Left off: '.$list(self::quoted($r['vetoed'])).' (you said '.(count($r['vetoed']) === 1 ? 'it isn’t' : 'they aren’t').' part of '.$job->title.').';
        }
        foreach ($r['locked'] as $year => $n) {
            $parts[] = ($n === 1 ? '1 day is' : $n.' days are').' in '.$year.', which is locked: '.($n === 1 ? 'it’s' : 'they’re').' on the job, and that year’s figures stay as they are.';
        }
        if ($r['mileage']) {
            $parts[] = 'It has mileage already: trips for the new days aren’t added automatically.';
        }
        $parts[] = $found ?? 'Saved. Search again on the job to find what belongs to it.';

        return implode(' ', $parts);
    }

    // ---------------------------------------------------------------- helpers

    /** Whether a failed save was another save holding the locks (a deadlock, a lock wait, SQLite busy): try again shortly. */
    public static function busy(Throwable $e): bool
    {
        for ($x = $e; $x; $x = $x->getPrevious()) {
            if ($x instanceof DeadlockException || (new ConcurrencyErrorDetector)->causedByConcurrencyError($x)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, int> by locked tax year, how many of these days are in it */
    private static function lockedDays(array $dates): array
    {
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->flip()->all();
        $out = [];
        foreach ($dates as $d) {
            $y = UkTaxYear::fromDate($d)->label();
            if (isset($locked[$y])) {
                $out[$y] = ($out[$y] ?? 0) + 1;
            }
        }

        return $out;
    }

    /**
     * The entries by key, read with one query; one not found by its key (loaded before keys were kept) is looked for
     * by its date.
     *
     * @param  list<array{key: string, date?: ?string}>  $items
     * @return array<string, ?CalendarEvent>
     */
    private function events(array $items): array
    {
        $keys = array_values(array_unique(array_column($items, 'key')));
        $found = $keys === [] ? collect() : CalendarEvent::query()->whereIn('event_key', $keys)->orderBy('id')->get()->keyBy('event_key');
        $out = [];
        foreach ($items as $i) {
            $out[$i['key']] ??= $found[$i['key']] ?? JobFinder::event($i['key'], $i['date'] ?? null);
        }

        return $out;
    }

    /**
     * How the message names each entry: “its title” (the one its link kept, when it has gone from your calendar), or,
     * with no title, the entry on its day.
     *
     * @return array<string, string>
     */
    private function names(array $events, array $items): array
    {
        $gone = array_keys(array_filter($events, fn ($e) => $e === null));
        $kept = $gone === [] ? collect() : CalendarJobLink::query()->whereIn('event_key', $gone)->pluck('summary', 'event_key');
        $out = [];
        foreach ($items as $i) {
            $e = $events[$i['key']] ?? null;
            $title = trim((string) ($e ? $e->summary : ($kept[$i['key']] ?? '')));
            $date = $e?->day() ?? (is_string($i['date'] ?? null) ? $i['date'] : null);
            $out[$i['key']] = $title !== '' ? self::quote($title) : ($date ? 'the entry on '.self::label($date) : 'a calendar entry');
        }

        return $out;
    }

    /** @return Collection<string, CalendarJobLink> by event key */
    private function links(array $keys): Collection
    {
        $out = collect();
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $out = $out->concat(CalendarJobLink::query()->whereIn('event_key', $chunk)->get());
        }

        return $out->keyBy('event_key');
    }

    /** Of $dates (oldest first), those an entry covers, from its first UK day to its last (JobDays::lastDay), however long it is. */
    private static function coveredOf(CalendarEvent $e, array $dates): array
    {
        $first = $e->day();
        $last = JobDays::lastDay($e);
        $out = array_values(array_filter($dates, fn ($d) => $first <= $d && $d <= $last));
        sort($out);

        return $out;
    }

    /** Whether an entry is on the picked day $on: it covers it, or it's a small-hours entry folded onto it. */
    private static function isOn(CalendarEvent $e, string $on, array $picked): bool
    {
        return ($e->day() <= $on && $on <= JobDays::lastDay($e)) || self::foldOnto($e, $picked) === $on;
    }

    /** How many days an entry covers (an all-day season of 456 days is 456). */
    private static function length(CalendarEvent $e): int
    {
        return (int) CarbonImmutable::parse($e->day())->diffInDays(CarbonImmutable::parse(JobDays::lastDay($e))) + 1;
    }

    /** From the earliest of $dates to the latest, in days (both counted). */
    private static function span(array $dates): int
    {
        return (int) CarbonImmutable::parse(min($dates))->diffInDays(CarbonImmutable::parse(max($dates))) + 1;
    }

    /**
     * The picked dates (oldest first) as runs of days one after another, each at most a month long: the entries on a
     * pick are read a run at a time.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function runs(array $dates): array
    {
        $runs = [];
        foreach ($dates as $d) {
            $n = count($runs) - 1;
            if ($n >= 0 && CarbonImmutable::parse($runs[$n][1])->addDay()->format('Y-m-d') === $d && CarbonImmutable::parse($runs[$n][0])->diffInDays(CarbonImmutable::parse($d)) < 31) {
                $runs[$n][1] = $d;
            } else {
                $runs[] = [$d, $d];
            }
        }

        return $runs;
    }

    /**
     * The picked day an entry is folded onto: a timed entry of one day that starts in the small hours (before
     * JobDays::OVERNIGHT_UNTIL) the day after a picked day, such as a load-out at 00:30 after the show. It's that
     * evening's work: its finish goes on the picked day, and no day is added for it (nor a mileage trip).
     *
     * @param  array<string, mixed>  $picked  by date
     */
    public static function foldOnto(CalendarEvent $e, array $picked): ?string
    {
        if ($e->all_day || $e->localStart()->format('H:i') >= JobDays::OVERNIGHT_UNTIL || JobDays::lastDay($e) !== $e->day()) {
            return null;
        }
        $before = CarbonImmutable::parse($e->day())->subDay()->format('Y-m-d');

        return isset($picked[$before]) ? $before : null;
    }

    /** Days from the nearest of $dates to the nearest of $days (0 when they share one). */
    private static function gap(array $days, array $dates): int
    {
        $best = PHP_INT_MAX;
        foreach ($days as $a) {
            foreach ($dates as $b) {
                $best = min($best, abs((int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b), false)));
                if ($best === 0) {
                    return 0;
                }
            }
        }

        return $best;
    }

    private static function minute(?string $time): ?string
    {
        return $time === null || trim($time) === '' ? null : substr(trim($time), 0, 5);
    }

    /** "Mon 3 Nov" (with the year when it isn't this year's). */
    public static function label(string $date): string
    {
        $d = CarbonImmutable::parse($date);

        return $d->format($d->year === CarbonImmutable::parse(CalendarMonth::today())->year ? 'D j M' : 'D j M Y');
    }

    private static function counted(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));
    }

    private static function quote(string $s): string
    {
        return '“'.mb_substr($s, 0, 40).(mb_strlen($s) > 40 ? '…' : '').'”';
    }

    /** @return list<string> */
    private static function quoted(array $names): array
    {
        return array_map(self::quote(...), $names);
    }

    /** "a", "a and b", "a, b and c", or "a, b, c and 2 more". */
    private static function joined(array $items, int $max = 3): string
    {
        $items = array_values($items);
        if (count($items) > $max) {
            return implode(', ', array_slice($items, 0, $max)).' and '.(count($items) - $max).' more';
        }
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.$items[count($items) - 1];
    }
}
