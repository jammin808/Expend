<?php

namespace App\Http\Controllers;

use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\CalendarMonth;
use App\Domain\Jobs\DayPick;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobGatherer;
use App\Models\CalendarPick;
use App\Models\JobDay;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Picking days on the calendar (or "Add days" on a job) and making them one job, or adding them to one: the month's
 * grid, the review of what was picked, the jobs to add to, and the two saves (Jobs\DayPick). The first three only
 * read. A save carries the pick's token: once saved, the same token goes to the job and writes nothing more.
 */
class CalendarPickController extends Controller
{
    private const BUSY = 'Still saving your last tap. Look at the job in a moment.';

    /** A month of the calendar, for the grid (GET /calendar/month?m=YYYY-MM; this month in the UK by default). */
    public function month(Request $request, CalendarMonth $calendar): JsonResponse
    {
        $m = $request->validate(['m' => ['nullable', 'string', 'regex:/^(19|20)\d{2}-(0[1-9]|1[0-2])$/']], ['m.regex' => 'Give the month as year and month, like 2026-11.'])['m'] ?? null;

        return response()->json($calendar->build($m ? CarbonImmutable::parse($m.'-01') : CarbonImmutable::parse(CalendarMonth::today())->startOfMonth()));
    }

    /** What the picked days hold, for the review (a POST only so a long pick isn't held to URL limits; it writes nothing). */
    public function preview(Request $request, DayPick $pick): JsonResponse
    {
        $data = $request->validate([
            'pick' => 'nullable|string|max:40',
            'dates' => 'required|array|min:1|max:'.DayPick::MAX_DATES,
            'dates.*' => 'required|date_format:Y-m-d|distinct',
            'overrides' => 'nullable|array|max:'.DayPick::MAX_ENTRIES,
            'overrides.*' => 'in:in,out',
            'job_id' => 'nullable|integer|exists:work_jobs,id',
        ], [
            'dates.required' => 'Pick at least one day.', 'dates.min' => 'Pick at least one day.',
            'dates.max' => DayPick::MAX_DATES.' days is the most at once.',
            'dates.*.date_format' => 'A picked day isn’t a date.', 'dates.*.distinct' => 'The same day is picked twice.',
            'overrides.max' => DayPick::MAX_ENTRIES.' calendar entries is the most at once.',
            'job_id.exists' => 'That job has gone. Pick another one.',
        ]);

        return response()->json($pick->preview($data));
    }

    /** Jobs for "Add to job": title or client has q in it, nearest to near first, never a cancelled one. */
    public function jobs(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'near' => 'nullable|date_format:Y-m-d']);

        return response()->json(['jobs' => DayPick::searchJobs($data['q'] ?? null, $data['near'] ?? null)]);
    }

    /** "Create job": a new job from the picked days, with the entries you included. */
    public function store(Request $request, DayPick $pick, JobGatherer $gatherer): RedirectResponse
    {
        $data = $this->validated($request, 'create');
        if ($done = CalendarPick::query()->where('token', $data['pick'])->first()) {
            return self::already($done);
        }
        $data['fee_pence'] = Money::parse($data['fee'] ?? null);
        $data['postcode'] = ! empty($data['postcode']) ? strtoupper(trim($data['postcode'])) : null;

        return $this->save(fn () => $pick->create($data), $data['pick'], $gatherer);
    }

    /** "Add to job" on the calendar, and "Add days" on the job page: the picked days added to a job you have. */
    public function addToJob(Request $request, WorkJob $job, DayPick $pick, JobGatherer $gatherer): RedirectResponse
    {
        $data = $this->validated($request, 'add');
        if ($done = CalendarPick::query()->where('token', $data['pick'])->first()) {
            return self::already($done);
        }
        if ($job->status === 'cancelled') {
            return back()->with('error', 'That job is cancelled. Pick another job or make a new one.');
        }

        return $this->save(fn () => $pick->addTo($job, $data), $data['pick'], $gatherer);
    }

    /**
     * Saved in one go (DayPick), then the job's search runs once. The same pick saved meanwhile (a second tap) goes to
     * its job; another save holding the locks says so; a refusal says why. Nothing is written by any of them.
     *
     * @param  Closure(): array  $work
     */
    private function save(Closure $work, string $token, JobGatherer $gatherer): RedirectResponse
    {
        try {
            $result = $work();
        } catch (UniqueConstraintViolationException $e) {
            if ($done = CalendarPick::query()->where('token', $token)->first()) {
                return self::already($done);
            }
            throw $e;
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            if (DayPick::busy($e)) {
                return back()->with('error', self::BUSY);
            }
            throw $e;
        }

        $job = $result['job'];
        try {
            // Once, now it's saved (it queues the Gmail search at most once): what it does is its own, under the pick's correlation.
            $found = AuditContext::with(null, fn () => $gatherer->gather($job->fresh()), null, $token)['summary'];
        } catch (Throwable $e) {
            report($e); // the job is saved either way
            $found = null;
        }

        return redirect('/jobs/'.$job->id)->with('success', DayPick::message($result, $found));
    }

    private static function already(CalendarPick $done): RedirectResponse
    {
        $job = $done->work_job_id ? WorkJob::query()->find($done->work_job_id) : null;
        if (! $job) {
            return redirect('/jobs/calendar')->with('error', 'Those days were saved, but the job has gone since. Pick the days again to make a new one.');
        }

        return redirect('/jobs/'.$job->id)->with('success', 'Already saved: '.$job->title.'.');
    }

    /**
     * The pick, checked. Messages name the day ("The Mon 3 Nov finish time needs hours and minutes"), so the review
     * shows each under its row.
     */
    private function validated(Request $request, string $mode): array
    {
        $days = is_array($request->input('days')) ? array_values(array_filter($request->input('days'), 'is_array')) : [];
        $dates = array_values(array_filter(array_map(fn ($d) => $d['date'] ?? null, $days), fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)));
        $entryKeys = array_values(array_filter(array_map(fn ($e) => is_array($e) ? ($e['key'] ?? null) : null, (array) $request->input('entries', [])), 'is_string'));
        $seen = $mode === 'add' ? DayPick::KINDS_SEEN : ['free', 'other_job', 'not_job'];
        $key = 'required|string|regex:/^[0-9a-f]{40}$/';

        $rules = [
            'pick' => ['required', 'string', fn (string $a, mixed $v, Closure $fail) => is_string($v) && AuditContext::isCorrelation($v) ? null : $fail('This pick couldn’t be read. Close it and pick the days again.')],
            'days' => 'required|array|min:1|max:'.DayPick::MAX_DATES,
            'days.*' => 'array',
            'days.*.date' => 'required|date_format:Y-m-d|distinct',
            'days.*.kind' => 'required|in:'.implode(',', array_keys(JobDay::KINDS)),
            'days.*.start_time' => 'nullable|date_format:H:i',
            'days.*.end_time' => 'nullable|date_format:H:i',
            'days.*.note' => 'nullable|string|max:200',
            'entries' => 'nullable|array|max:'.DayPick::MAX_ENTRIES,
            'entries.*' => 'array',
            'entries.*.key' => $key.'|distinct',
            'entries.*.date' => 'required|date_format:Y-m-d',
            'entries.*.on' => ['required', 'date_format:Y-m-d', Rule::in($dates)],
            'entries.*.seen' => ['required', Rule::in($seen)],
            'entries.*.seen_job_id' => 'nullable|required_if:entries.*.seen,other_job|integer',
            // Who had linked it there when you looked (an automatic link moves with the tick Expend put on for you,
            // but not once you've confirmed it there since).
            'entries.*.seen_by' => 'nullable|string|max:20',
            'entries.*.take' => 'boolean',
            'left_out' => 'nullable|array|max:'.DayPick::MAX_ENTRIES,
            'left_out.*' => 'array',
            'left_out.*.key' => [...explode('|', $key), 'distinct', Rule::notIn($entryKeys)],
            'left_out.*.date' => 'nullable|date_format:Y-m-d',
            'candidates' => 'nullable|array|max:'.DayPick::MAX_CANDIDATES,
            'candidates.*' => 'array',
            'candidates.*.id' => 'required|integer|distinct',
            'candidates.*.others' => 'boolean',
        ];
        if ($mode === 'create') {
            $rules += [
                'title' => 'required|string|max:150', 'client_id' => 'nullable|integer|exists:clients,id', 'new_client' => 'nullable|string|max:120',
                'location' => 'nullable|string|max:200', 'postcode' => 'nullable|string|max:10',
                'fee' => ['nullable', 'string', function (string $a, mixed $v, Closure $fail) {
                    if (! is_string($v)) {
                        return; // said by the string rule
                    }
                    try {
                        Money::parse($v);
                    } catch (InvalidArgumentException) {
                        $fail('The fee isn’t an amount Expend can read. Use a full stop for pence, like 450.00.');
                    }
                }],
                'po_number' => 'nullable|string|max:100', 'po_not_required' => 'boolean', 'status' => 'nullable|in:booked,done', 'notes' => 'nullable|string|max:2000',
            ];
        }

        $entry = 'A calendar entry in this pick couldn’t be read. Close it and pick again.';
        $messages = [
            'days.required' => 'Pick at least one day.', 'days.min' => 'Pick at least one day.', 'days.array' => 'Pick at least one day.',
            'days.*.array' => 'A picked day couldn’t be read. Close it and pick the days again.',
            'days.*.note.string' => 'The :attribute couldn’t be read. Type it again.',
            'days.max' => DayPick::MAX_DATES.' days is the most at once.',
            'days.*.date.required' => 'A picked day has no date.', 'days.*.date.date_format' => 'A picked day isn’t a date.',
            'days.*.date.distinct' => ':attribute is picked twice.',
            'days.*.kind.required' => 'Pick what kind of day :attribute is.', 'days.*.kind.in' => 'Pick what kind of day :attribute is.',
            'days.*.start_time.date_format' => 'The :attribute needs hours and minutes, like 07:30.',
            'days.*.end_time.date_format' => 'The :attribute needs hours and minutes, like 18:00.',
            'days.*.note.max' => 'The :attribute is too long (200 characters at most).',
            'entries.max' => DayPick::MAX_ENTRIES.' calendar entries is the most at once.', 'entries.array' => $entry, 'entries.*.array' => $entry,
            'entries.*.key.required' => $entry, 'entries.*.key.regex' => $entry, 'entries.*.key.string' => $entry,
            'entries.*.key.distinct' => 'The same calendar entry is in this pick twice. Close it and pick again.',
            'entries.*.date.required' => $entry, 'entries.*.date.date_format' => $entry,
            'entries.*.on.required' => $entry, 'entries.*.on.date_format' => $entry,
            'entries.*.on.in' => 'A calendar entry is on a day that isn’t picked. Tick it again, or pick its day.',
            'entries.*.seen.required' => $entry, 'entries.*.seen.in' => $entry,
            'entries.*.seen_job_id.required_if' => $entry, 'entries.*.seen_job_id.integer' => $entry, 'entries.*.take.boolean' => $entry,
            'entries.*.seen_by.string' => $entry, 'entries.*.seen_by.max' => $entry,
            'left_out.max' => DayPick::MAX_ENTRIES.' calendar entries is the most at once.', 'left_out.array' => $entry, 'left_out.*.array' => $entry,
            'left_out.*.key.required' => $entry, 'left_out.*.key.regex' => $entry, 'left_out.*.key.string' => $entry,
            'left_out.*.key.distinct' => 'The same calendar entry is left out twice. Close it and pick again.',
            'left_out.*.key.not_in' => 'A calendar entry is both ticked and left out. Tick or untick it again.',
            'left_out.*.date.date_format' => $entry,
            'candidates.max' => 'At most '.DayPick::MAX_CANDIDATES.' possible jobs can be closed at once.', 'candidates.array' => 'A possible job in this pick couldn’t be read.',
            'candidates.*.array' => 'A possible job in this pick couldn’t be read.', 'candidates.*.id.required' => 'A possible job in this pick couldn’t be read.',
            'candidates.*.id.integer' => 'A possible job in this pick couldn’t be read.', 'candidates.*.id.distinct' => 'The same possible job is in this pick twice.',
            'candidates.*.others.boolean' => 'A possible job in this pick couldn’t be read.',
            'pick.required' => 'This pick couldn’t be read. Close it and pick the days again.', 'pick.string' => 'This pick couldn’t be read. Close it and pick the days again.',
            // Worded as the job form's other messages are (JobController, JobFinderController::fromCalendar).
            'title.required' => 'Give the job a title.', 'title.string' => 'Give the job a title.', 'title.max' => 'The job title is too long (150 characters at most).',
            'client_id.exists' => 'That client isn’t in your list.', 'client_id.integer' => 'That client isn’t in your list.',
            'new_client.string' => 'The client’s name couldn’t be read. Type it again.', 'new_client.max' => 'The client’s name is too long (120 characters at most).',
            'location.string' => 'The venue couldn’t be read. Type it again.', 'location.max' => 'The venue is too long (200 characters at most).',
            'postcode.string' => 'The postcode couldn’t be read. Type it again.', 'postcode.max' => 'A postcode is 10 characters at most, like SE10 0DX.',
            'fee.string' => 'The fee isn’t an amount Expend can read. Use a full stop for pence, like 450.00.',
            'po_number.string' => 'The PO number couldn’t be read. Type it again.', 'po_number.max' => 'The PO number is too long (100 characters at most).',
            'po_not_required.boolean' => 'The “No PO needed” tick couldn’t be read. Tick it again, or leave it off.',
            'notes.string' => 'The notes couldn’t be read. Type them again.', 'notes.max' => 'The notes are too long (2,000 characters at most).',
            'status.in' => 'A new job is booked or done.',
        ];

        // Each day by its date: "Mon 3 Nov", "Mon 3 Nov start time", "Mon 3 Nov finish time", "Mon 3 Nov note".
        $attributes = [];
        foreach ($days as $i => $d) {
            $label = is_string($d['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date']) && checkdate((int) substr($d['date'], 5, 2), (int) substr($d['date'], 8, 2), (int) substr($d['date'], 0, 4))
                ? DayPick::label($d['date']) : 'a picked day';
            $attributes += ["days.{$i}.date" => $label, "days.{$i}.kind" => $label, "days.{$i}.start_time" => $label.' start time',
                "days.{$i}.end_time" => $label.' finish time', "days.{$i}.note" => $label.' note'];
        }

        $data = $request->validate($rules, $messages, $attributes);

        // A job can't cover more than JobDays::MAX_DAYS from its first day to its last.
        $picked = array_column($data['days'], 'date');
        if ((int) CarbonImmutable::parse(min($picked))->diffInDays(CarbonImmutable::parse(max($picked))) + 1 > JobDays::MAX_DAYS) {
            throw ValidationException::withMessages(['days' => 'A job can cover at most '.JobDays::MAX_DAYS.' days. Make it separate jobs.']);
        }

        return $data;
    }
}
