<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Grounding;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
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
    public function index(Request $request, JobFinder $finder): Response
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('m')) ? CarbonImmutable::parse($request->query('m').'-01') : CarbonImmutable::today()->startOfMonth();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $events = CalendarEvent::query()->whereBetween('starts_at', [$from->subDay()->format('Y-m-d 00:00:00'), $to->addDay()->format('Y-m-d 23:59:59')])
            ->orderBy('starts_at')->limit(1500)->get()
            ->filter(fn (CalendarEvent $e) => $e->day() >= $from->format('Y-m-d') && $e->day() <= $to->format('Y-m-d'))->values();
        $links = CalendarJobLink::query()->whereIn('event_key', $events->map->key())->get()->keyBy('event_key');
        $jobs = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')
            ->whereDate('starts_on', '<=', $to->addDays(14)->format('Y-m-d'))->whereDate('ends_on', '>=', $from->subDays(14)->format('Y-m-d'))
            ->orderBy('starts_on')->get();
        $clients = Client::query()->orderBy('name')->get(['id', 'name']);

        return Inertia::render('jobs/calendar', [
            'month' => $month->format('Y-m'),
            'prev' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'events' => $events->map(function (CalendarEvent $e) use ($links, $jobs, $clients, $finder) {
                $link = $links[$e->key()] ?? null;
                $job = $link?->work_job_id ? $jobs->firstWhere('id', $link->work_job_id) ?? WorkJob::query()->find($link->work_job_id) : null;

                return [
                    'key' => $e->key(), 'date' => $e->day(), 'last_day' => $e->lastDay()->format('Y-m-d'), 'summary' => $e->summary ?: '(no title)', 'location' => $e->location,
                    'all_day' => $e->all_day, 'start_time' => $e->all_day ? null : $e->localStart()->format('H:i'), 'end_time' => $e->all_day || ! $e->ends_at ? null : $e->localEnd()->format('H:i'),
                    'job' => $job ? ['id' => $job->id, 'title' => $job->title] : null, 'by' => $link?->linked_by, 'not_job' => (bool) $link?->not_job,
                    'looks_like_work' => ! $job && ! $link?->not_job && $finder->workScore($e) >= 2,
                    'postcode' => self::postcode((string) $e->location),
                    'client_id' => $clients->first(fn ($c) => self::mentions($e->text(), $c->name))?->id,
                ];
            })->values(),
            'jobs' => $jobs->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d'), 'client' => $j->client?->name])->values(),
            'clients' => $clients,
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
            'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i', 'note' => 'nullable|string|max:200']);
        $date = CarbonImmutable::parse($data['date'])->format('Y-m-d');
        if (abs(CarbonImmutable::parse($date)->diffInDays($job->starts_on)) > 120) {
            return back()->with('error', 'That’s more than four months from the job. Make it a separate job.');
        }
        JobDays::add($job, $date, $data['kind'], $data['start_time'] ?? null, $data['end_time'] ?? null, null, 'you', $data['note'] ?? null);
        $result = $gatherer->gather($job->fresh(), false);

        return back()->with('success', 'Added '.CarbonImmutable::parse($date)->format('D j M').'. '.$result['summary']);
    }

    public function updateDay(Request $request, JobDay $day): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|in:'.implode(',', array_keys(JobDay::KINDS)), 'start_time' => 'nullable|date_format:H:i', 'end_time' => 'nullable|date_format:H:i', 'note' => 'nullable|string|max:200']);
        $before = $day->only(['kind', 'start_time', 'end_time', 'note']);
        $day->update($data + ['source' => 'you']);
        AuditLog::record('job.day_updated', $day->job, $before, $day->only(['kind', 'start_time', 'end_time', 'note']));

        return back()->with('success', 'Saved.');
    }

    public function removeDay(JobDay $day): RedirectResponse
    {
        try {
            JobDays::remove($day);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Removed that day.');
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

        return back()->with('success', $gatherer->reject($find));
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
        return preg_match('/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i', $text, $m) ? strtoupper($m[1].' '.$m[2]) : null;
    }

    private static function mentions(string $text, string $name): bool
    {
        $n = Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b/i', '', $name));

        return mb_strlen($n) >= 3 && str_contains(Grounding::normalise($text), $n);
    }
}
