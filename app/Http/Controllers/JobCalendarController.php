<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\CalendarMonth;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Jobs\GatherForJob;
use App\Models\Client;
use App\Models\JobDay;
use App\Models\JobFind;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Your calendar, a month at a time: click any entry to say it's a job (with the details filled in for you to
 * correct), that it's part of a job you already have, or that it isn't work. Then Expend looks for everything
 * belonging to the job: POs and documents, emails, payments, stays and the fee.
 */
class JobCalendarController extends Controller
{
    /** Plain words for a day that can't be saved: the job page shows them as they are. */
    private const DAY_MESSAGES = [
        'date.required' => 'Pick the day to add.', 'date.date' => 'The day to add isn’t a date.',
        'kind.required' => 'Pick what kind of day it is.', 'kind.in' => 'Pick what kind of day it is.',
        'start_time.date_format' => 'The start time needs hours and minutes, like 07:30.',
        'end_time.date_format' => 'The finish time needs hours and minutes, like 18:00.',
        'note.max' => 'A note on a day is 200 characters at most.',
    ];

    public function index(Request $request, CalendarMonth $calendar): Response
    {
        // The month in the UK (the server runs on UTC), unless one is asked for.
        // Only a month that exists (2026-13 isn't one, nor is 2026-00), as GET /calendar/month checks.
        $month = preg_match('/^(19|20)\d{2}-(0[1-9]|1[0-2])$/', (string) $request->query('m')) ? CarbonImmutable::parse($request->query('m').'-01') : CarbonImmutable::parse(CalendarMonth::today())->startOfMonth();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        // Every entry covering a day of the month, one that began last month included; each with its state, and
        // whether picking its day ticks it for you (CalendarMonth).
        $grid = $calendar->build($month);
        $jobs = WorkJob::query()->with(['client', 'days'])->where('status', '!=', 'cancelled')
            ->whereDate('starts_on', '<=', $to->addDays(14)->format('Y-m-d'))->whereDate('ends_on', '>=', $from->subDays(14)->format('Y-m-d'))
            ->orderBy('starts_on')->get();
        $clients = Client::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('jobs/calendar', [
            // A possible job on the to-do list opens its entry (CandidateText::href), with "Part of" on a job you have.
            'open' => preg_match('/^[0-9a-f]{40}$/', (string) $request->query('entry'))
                ? ['key' => (string) $request->query('entry'), 'job_id' => ctype_digit((string) $request->query('job')) ? (int) $request->query('job') : null]
                : null,
            'month' => $grid['month'],
            'prev' => $grid['prev'],
            'next' => $grid['next'],
            'today' => $grid['today'],
            'events' => $grid['events'],
            // With the days each job works, so the page can say which of an entry's dates linking it adds.
            'jobs' => $jobs->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d'),
                'client' => $j->client?->name, 'days' => $j->workDates()])->values(),
            'clients' => $clients,
            // For the month grid: whose days each date is (a cancelled job's faded), and the years you've locked.
            'job_days' => $grid['job_days'],
            'locked_years' => $grid['locked_years'],
        ]);
    }

    /** "Part of a job I already have": link the entry, which adds its day to the job's days (a rig day, a de-rig), and search again. */
    public function linkToJob(Request $request, JobFinder $finder, JobGatherer $gatherer): RedirectResponse
    {
        $data = $request->validate(['key' => 'required|string|size:40', 'date' => 'required|date_format:Y-m-d', 'job_id' => 'required|integer|exists:work_jobs,id']);
        $event = JobFinder::event($data['key'], $data['date']);
        abort_unless($event, 404);
        $job = WorkJob::query()->findOrFail($data['job_id']);
        $finder->linkCalendar($event, $job, 'you', 'You linked this entry to the job');
        $result = $gatherer->gather($job);

        return redirect('/jobs/'.$job->id)->with('success', 'Linked the calendar entry and added '.CarbonImmutable::parse($event->day())->format('D j M').' to the job’s days. '.$result['summary']);
    }

    public function addDay(Request $request, WorkJob $job, JobGatherer $gatherer): RedirectResponse
    {
        $data = $request->validate(['date' => 'required|date', 'kind' => 'required|in:'.implode(',', array_keys(JobDay::KINDS)),
            'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i', 'note' => 'nullable|string|max:200'], self::DAY_MESSAGES);
        $date = CarbonImmutable::parse($data['date'])->format('Y-m-d');
        // Measured from the job's nearest day, so a long job (a season) can have a day added near its end.
        $first = $job->starts_on->format('Y-m-d');
        $last = $job->ends_on->format('Y-m-d');
        $away = $date < $first ? CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($first)) : ($date > $last ? CarbonImmutable::parse($last)->diffInDays(CarbonImmutable::parse($date)) : 0);
        $span = CarbonImmutable::parse(min($date, $first))->diffInDays(CarbonImmutable::parse(max($date, $last))) + 1;
        if (abs($away) > 120 || $span > JobDays::MAX_DAYS) {
            return back()->with('error', 'That’s more than four months from the job (or makes it longer than '.JobDays::MAX_DAYS.' days). Make it a separate job.');
        }
        JobDays::add($job, $date, $data['kind'], $data['start_time'] ?? null, $data['end_time'] ?? null, null, 'you', $data['note'] ?? null);
        // Its days changed: looked again now, Gmail included (queued, once per job).
        $result = $gatherer->gather($job->fresh());

        return back()->with('success', 'Added '.CarbonImmutable::parse($date)->format('D j M').'. '.$result['summary']);
    }

    public function updateDay(Request $request, JobDay $day): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|in:'.implode(',', array_keys(JobDay::KINDS)), 'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i', 'note' => 'nullable|string|max:200'], self::DAY_MESSAGES);
        $before = $day->only(['kind', 'start_time', 'end_time', 'note']);
        $day->update($data + ['source' => 'you']);
        AuditLog::record('job.day_updated', $day->job, $before, $day->only(['kind', 'start_time', 'end_time', 'note']));

        return back()->with('success', 'Saved.');
    }

    public function removeDay(JobDay $day): RedirectResponse
    {
        $jobId = $day->work_job_id;
        try {
            JobDays::remove($day);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        // Its days changed: look again for what belongs to it, once (queued, unique per job).
        GatherForJob::dispatch($jobId)->afterCommit();

        return back()->with('success', 'Removed that day. Looking again for what belongs to the job.');
    }

    /** "Search again" on a job: its documents, emails, payments, calendar days and fee. */
    public function gather(WorkJob $job, JobGatherer $gatherer): RedirectResponse
    {
        return back()->with('success', $gatherer->gather($job)['summary']);
    }

    public function accept(JobFind $find, JobGatherer $gatherer): RedirectResponse
    {
        abort_unless($find->status === 'possible', 404);
        try {
            $message = $gatherer->accept($find);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    public function reject(JobFind $find, JobGatherer $gatherer): RedirectResponse
    {
        abort_if($find->status === 'rejected', 404);
        try {
            $message = $gatherer->reject($find);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage()); // a locked year, or an issued invoice: nothing was changed
        }

        return back()->with('success', $message);
    }

    /** What the search found, for the job's page. */
    public static function finds(WorkJob $job): array
    {
        return JobFind::query()->where('work_job_id', $job->id)->whereIn('status', ['linked', 'possible'])
            ->orderByRaw("CASE WHEN status = 'possible' THEN 0 ELSE 1 END")->orderBy('on_date')->get()
            ->map(fn (JobFind $f) => $f->only(['id', 'type', 'ref', 'status', 'label', 'detail', 'amount_pence', 'currency']) + [
                'date' => $f->on_date?->format('Y-m-d'), 'type_label' => JobFind::TYPES[$f->type] ?? $f->type,
            ])->all();
    }

    public static function postcode(string $text): ?string
    {
        return CalendarMonth::postcode($text);
    }
}
