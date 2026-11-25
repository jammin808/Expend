<?php

namespace App\Http\Controllers;

use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Jobs\PoStatus;
use App\Domain\Verification\Verifier;
use App\Jobs\FindJobs;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class JobFinderController extends Controller
{
    public function index(): Response
    {
        $open = JobCandidate::query()->where('status', 'open')->orderBy('starts_on')->limit(200)->get();
        $jobs = WorkJob::query()->with('client')->where('status', '!=', 'cancelled')->orderByDesc('starts_on')->limit(400)->get();
        $checks = Verification::forMany('job_candidate', $open->pluck('id'));

        // What was linked automatically, newest first, for you to confirm or undo.
        $autoCalendar = CalendarJobLink::query()->with('job')->whereIn('linked_by', ['rules', 'ai'])->whereNotNull('work_job_id')->latest()->limit(60)->get();
        $calChecks = Verification::forMany('calendar_link', $autoCalendar->pluck('id'));
        // An attachment linked with its email is shown as the email; one linked on its own (its email is for more
        // than one job) is shown by itself.
        $autoDocs = Document::query()->with(['job', 'parent:id,work_job_id'])->whereIn('job_linked_by', ['rules', 'ai'])->whereNotNull('work_job_id')
            ->latest('updated_at')->limit(120)->get()
            ->filter(fn (Document $d) => ! $d->parent || $d->parent->work_job_id !== $d->work_job_id)->take(60)->values();
        $docChecks = Verification::forMany('document', $autoDocs->pluck('id'));

        return Inertia::render('jobs/finder', [
            'candidates' => $open->map(fn (JobCandidate $c) => $c->only(['id', 'title', 'client_name', 'start_time', 'end_time', 'location', 'postcode', 'po_number', 'score', 'found_by']) + [
                'starts_on' => $c->starts_on->format('Y-m-d'), 'ends_on' => $c->ends_on->format('Y-m-d'), 'sources' => $c->sources,
                'why' => $c->reasons['why'] ?? [], 'options' => array_values(array_intersect($c->reasons['jobs'] ?? [], $jobs->pluck('id')->all())),
                'edited' => $c->edited_at !== null, 'check' => $checks[$c->id] ?? null,
                'strength' => $c->score >= 8 ? 'strong' : ($c->score >= 5 ? 'likely' : 'possible'),
            ])->values(),
            'jobs' => $jobs->map(fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'client' => $j->client?->name, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d')]),
            'auto' => [
                ...$autoCalendar->filter(fn ($l) => $l->job)->map(fn (CalendarJobLink $l) => [
                    'type' => 'calendar', 'id' => $l->id, 'label' => $l->summary, 'date' => $l->event_starts_at->copy()->setTimezone(CalendarEvent::TZ)->format('Y-m-d'), 'by' => $l->linked_by,
                    'reason' => $l->reason, 'job' => ['id' => $l->job->id, 'title' => $l->job->title], 'check' => $calChecks[$l->id] ?? null,
                ]),
                ...$autoDocs->filter(fn ($d) => $d->job)->map(fn (Document $d) => [
                    'type' => 'document', 'id' => $d->id, 'label' => $d->email_subject ?: $d->original_name, 'date' => $d->received_at?->format('Y-m-d'),
                    'by' => $d->job_linked_by, 'reason' => $d->extraction['job_link_reason'] ?? null, 'job' => ['id' => $d->job->id, 'title' => $d->job->title],
                    'check' => $docChecks[$d->id] ?? null,
                ]),
            ],
            'lastRun' => Setting::get('job_finder_last_run'),
            'ai' => JobFinder::aiAllowed(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function run(Request $request, JobFinder $finder, Verifier $verifier): RedirectResponse
    {
        $year = $request->validate(['year' => 'nullable|string|regex:/^\d{4}-\d{2}$/'])['year'] ?? null;
        try {
            // The rules run now; the AI pass (which can take a minute) runs in the background straight after.
            $r = $finder->run($year, false);
            if (JobFinder::aiAllowed()) {
                FindJobs::dispatch($year, true);
            }
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'The job finder stopped: '.$e->getMessage());
        }
        JobCandidate::query()->where('status', 'open')->get()->each(fn ($c) => $verifier->candidate($c));

        return back()->with('success', "Linked {$r['linked']} to your jobs and found {$r['proposed']} possible ".($r['proposed'] === 1 ? 'job' : 'jobs').'.'
            .(JobFinder::aiAllowed() ? ' Unclear calendar entries are being checked with AI in the background; refresh in a minute.' : ''));
    }

    public function update(Request $request, JobCandidate $candidate): RedirectResponse
    {
        abort_unless($candidate->status === 'open', 409);
        $data = $request->validate([
            'title' => 'required|string|max:150', 'client_name' => 'nullable|string|max:120', 'starts_on' => 'required|date',
            'ends_on' => 'required|date|after_or_equal:starts_on', 'location' => 'nullable|string|max:200', 'postcode' => 'nullable|string|max:10',
            'po_number' => 'nullable|string|max:100',
        ]);
        $candidate->update($data + ['edited_at' => now()]);

        return back()->with('success', 'Saved. Your edits are kept when the job finder runs again.');
    }

    public function accept(Request $request, JobCandidate $candidate, JobFinder $finder): RedirectResponse
    {
        abort_unless($candidate->status === 'open', 409);
        $data = $request->validate([
            'title' => 'required|string|max:150', 'client_id' => 'nullable|integer|exists:clients,id', 'new_client' => 'nullable|string|max:120',
            'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i', 'location' => 'nullable|string|max:200', 'postcode' => 'nullable|string|max:10',
            'fee' => 'nullable|string', 'po_number' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000', 'status' => 'nullable|in:booked,done',
        ]);
        if (! empty($data['new_client'])) {
            $data['client_id'] = JobCreator::client($data['new_client'], 'you')->id; // whatever its capitals, or with Ltd
        }
        $data['fee_pence'] = Money::parse($data['fee'] ?? null);
        $data['postcode'] = ! empty($data['postcode']) ? strtoupper(trim($data['postcode'])) : null;
        $data['status'] = $data['status'] ?? 'booked';
        unset($data['new_client'], $data['fee']);
        if ($long = self::tooLong($data)) {
            return back()->with('error', $long);
        }

        $job = $finder->accept($candidate, $data, $links);

        return to_route('jobs.show', $job)->with('success', "Added {$job->title} and linked ".self::linked($links).'. Stays and payments around its dates were checked again.');
    }

    /** "3 sources", and what couldn't be linked (gone since, or linked to another job by you). */
    private static function linked(?array $links): string
    {
        $n = $links['linked'] ?? 0;
        $missed = count($links['missed'] ?? []);
        $labels = array_map(fn ($m) => '“'.mb_substr($m, 0, 60).'”', array_slice(array_values(array_filter($links['missed'] ?? [])), 0, 3));

        return $n.' '.($n === 1 ? 'source' : 'sources').($missed ? " ({$missed} not linked, because ".($missed === 1 ? 'it’s' : 'they’re')
            .' no longer there or you’ve linked '.($missed === 1 ? 'it' : 'them').' to another job'.($labels ? ': '.implode(', ', $labels) : '').')' : '');
    }

    public function merge(Request $request, JobCandidate $candidate, JobFinder $finder): RedirectResponse
    {
        abort_unless($candidate->status === 'open', 409);
        $job = WorkJob::query()->findOrFail($request->validate(['work_job_id' => 'required|integer|exists:work_jobs,id'])['work_job_id']);
        $finder->merge($candidate, $job, $links);

        return back()->with('success', "Added to {$job->title} and linked ".self::linked($links).'. Only its blank details were filled in'
            .($job->status === 'cancelled' ? '.' : '. Looking again for what belongs to the job.'));
    }

    public function dismiss(Request $request, JobCandidate $candidate, JobFinder $finder): RedirectResponse
    {
        abort_unless($candidate->status === 'open', 409);
        $finder->dismiss($candidate, $request->validate(['note' => 'nullable|string|max:250'])['note'] ?? null);

        return back()->with('success', 'Dismissed. It won’t be proposed again.');
    }

    /** Confirm or undo an automatic link. Confirming makes it yours; undoing marks the source "not this job". */
    public function decideLink(Request $request, JobFinder $finder): RedirectResponse
    {
        $data = $request->validate(['type' => 'required|in:calendar,document', 'id' => 'required|integer', 'action' => 'required|in:confirm,unlink']);
        if ($data['type'] === 'calendar') {
            $link = CalendarJobLink::query()->findOrFail($data['id']);
            if ($data['action'] === 'confirm') {
                $finder->confirmCalendarLink($link); // read again under the audit head, as every link writer does
            } else {
                $event = CalendarEvent::query()->where('uid', $link->event_uid)->get()->first(fn (CalendarEvent $e) => $e->key() === $link->event_key);
                if ($event) {
                    $finder->unlinkCalendar($event, $link->work_job_id);
                } elseif ($link->job) {
                    $finder->rejectCalendarKeyFor($link->event_key, $link->job); // gone from your calendar: its days come off the job too
                } else {
                    $link->update(['work_job_id' => null, 'linked_by' => 'you', 'rejected' => array_values(array_unique([...($link->rejected ?? []), $link->work_job_id]))]);
                }
            }
        } else {
            $doc = Document::query()->findOrFail($data['id']);
            $data['action'] === 'confirm' ? $finder->linkDocument($doc, $doc->job, 'you') : $finder->linkDocument($doc, null, 'you');
        }

        return back()->with('success', $data['action'] === 'confirm' ? 'Confirmed.' : 'Unlinked. It won’t be linked to that job automatically again.');
    }

    /** Link or unlink a calendar entry or a stay/journey from a job's page. */
    public function link(Request $request, WorkJob $job, JobFinder $finder): RedirectResponse
    {
        $data = $request->validate([
            'type' => 'required|in:calendar,travel', 'action' => 'required|in:link,unlink',
            'key' => 'required_if:type,calendar|nullable|string|size:40', 'date' => 'required_if:type,calendar|nullable|date_format:Y-m-d',
            'id' => 'required_if:type,travel|nullable|integer',
        ]);
        if ($data['type'] === 'calendar') {
            $event = JobFinder::event($data['key'], $data['date']);
            abort_unless($event, 404);
            $data['action'] === 'link' ? $finder->linkCalendar($event, $job, 'you', 'Linked by you') : $finder->unlinkCalendar($event, $job->id);
        } else {
            $item = TravelItem::query()->findOrFail($data['id']);
            $finder->linkTravel($item, $data['action'] === 'link' ? $job : null, 'you');
        }

        return back()->with('success', $data['action'] === 'link' ? 'Linked.' : 'Unlinked.');
    }

    /** "This is a job": make a job from a calendar entry (you check the details first), linked to the entry. */
    public function fromCalendar(Request $request, JobFinder $finder): RedirectResponse
    {
        $entry = $request->validate(['key' => 'required|string|size:40', 'date' => 'required|date_format:Y-m-d']);
        $event = JobFinder::event($entry['key'], $entry['date']);
        abort_unless($event, 404);
        // The entry's own title and venue can be longer than a job's: shortened to fit, with the whole text in the notes.
        $request->merge($fitted = self::fitEntryText($request->only(['title', 'location', 'notes']), $event));
        $data = $request->validate([
            'key' => 'required|string|size:40', 'date' => 'required|date_format:Y-m-d',
            'title' => 'required|string|max:150', 'client_id' => 'nullable|integer|exists:clients,id', 'new_client' => 'nullable|string|max:120',
            'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i', 'location' => 'nullable|string|max:200', 'postcode' => 'nullable|string|max:10',
            'fee' => 'nullable|string', 'po_number' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:2000', 'status' => 'nullable|in:booked,done',
            'po_not_required' => 'boolean', 'then' => 'nullable|in:job',
        ], [
            // Worded as the job form's other messages are (JobController, JobCreator).
            'title.max' => 'The job title is too long (150 characters at most).', 'location.max' => 'The venue is too long (200 characters at most).',
            'notes.max' => 'The notes are too long (2,000 characters at most).',
        ]);
        if ($long = self::tooLong($data)) {
            return back()->with('error', $long);
        }
        if (! empty($data['new_client'])) {
            $data['client_id'] = JobCreator::client($data['new_client'], 'you')->id; // whatever its capitals, or with Ltd
        }
        $fields = collect($data)->except(['key', 'date', 'new_client', 'fee', 'then'])->all();
        $fields['fee_pence'] = Money::parse($data['fee'] ?? null);
        $fields['postcode'] = ! empty($data['postcode']) ? strtoupper(trim($data['postcode'])) : null;
        $fields['status'] = $data['status'] ?? 'booked';
        $fields['po_not_required'] = (bool) ($data['po_not_required'] ?? false);

        $job = DB::transaction(function () use ($fields, $data, $event, $finder) {
            // Searched for straight after (below), so not again in the background.
            $job = app(JobCreator::class)->create($fields, 'you', [['type' => 'calendar_event', 'id' => $event->key(), 'quote' => $event->summary, 'verified' => true]], 'From a calendar entry', false);
            // The entry's days within the dates you typed (not every day it covers), with its kind (rig, show, de-rig).
            $finder->linkCalendar($event, $job, 'you', 'You made this entry a job', [$job->starts_on->format('Y-m-d'), $job->ends_on->format('Y-m-d')]);
            JobDays::useFormTimes($job, $data);

            return $job;
        });
        // Then everything that belongs to it: other days, POs and documents, Gmail, payments, stays, the fee.
        $found = app(JobGatherer::class)->gather($job);
        $message = "Added {$job->title} and linked the calendar entry. ".$found['summary']
            .(PoStatus::forJobs([$job->fresh()])[$job->id]['missing'] ? ' It has no PO yet, so it’s flagged until one is linked.' : '')
            .self::shortenedNote($fitted);

        return ($data['then'] ?? null) === 'job' ? redirect('/jobs/'.$job->id)->with('success', $message) : back()->with('success', $message);
    }

    /**
     * A calendar entry's title and venue are kept up to 250 characters, longer than a job's. When the form sends the
     * entry's own text unchanged, each is shortened at a sensible place and the whole text added to the notes (if
     * they have room). Text you typed yourself is left as it is, so a long one is refused with a message.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string> the fields to change
     */
    private static function fitEntryText(array $input, CalendarEvent $event): array
    {
        $own = ['title' => Str::trim((string) $event->summary), 'location' => Str::trim((string) $event->location)];
        $notes = is_string($input['notes'] ?? null) ? $input['notes'] : '';
        $fitted = [];
        $kept = [];
        foreach (['title' => [150, 'Full title from your calendar'], 'location' => [200, 'Full venue from your calendar']] as $field => [$max, $label]) {
            $sent = $input[$field] ?? null;
            if (is_string($sent) && $sent === $own[$field] && mb_strlen($sent) > $max) {
                $fitted[$field] = self::shorten($sent, $max);
                if (! str_contains($notes, $line = $label.': '.$sent)) {
                    $kept[] = $line;
                }
            }
        }
        if ($fitted) {
            $notes = implode("\n", array_filter([$notes, ...$kept], fn ($n) => $n !== ''));
            if (mb_strlen($notes) <= 2000) {
                $fitted['notes'] = $notes;
            }
        }

        return $fitted;
    }

    /** What the success message says when the entry's title or venue was shortened. */
    private static function shortenedNote(array $fitted): string
    {
        $what = array_values(array_intersect_key(['title' => 'title', 'location' => 'venue'], $fitted));
        if (! $what) {
            return '';
        }
        $one = count($what) === 1;

        return ' The entry’s '.implode(' and ', $what).($one ? ' was' : ' were').' too long for a job, so '.($one ? 'it’s' : 'they’re').' shortened; the whole text is '
            .(isset($fitted['notes']) ? 'in the job’s notes.' : 'still in your calendar.');
    }

    /**
     * Text cut to $max characters at a sensible place: after a sentence or a line (an address, before the directions
     * that follow it), else after a part of it (a comma, a dash), else after a word, with "…" to show there's more.
     * resources/js/pages/jobs/calendar.tsx fills the form the same way.
     */
    public static function shorten(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $tidy = fn (string $s) => (string) preg_replace('/[\s,;:.\-–·|]+$/u', '', $s);
        // One character more, so a part that ends right at the limit is kept.
        $head = mb_substr($text, 0, $max + 1);
        $last = fn (array $marks) => max(array_map(fn (string $m) => ($i = mb_strrpos($head, $m)) === false ? -1 : $i, $marks));
        foreach ([[['. ', "\n", ' | '], $max / 4], [[', ', '; ', ' - ', ' – ', ' · '], $max / 2]] as [$marks, $least]) {
            if (($at = $last($marks)) >= $least && ($cut = $tidy(mb_substr($head, 0, $at))) !== '') {
                return $cut;
            }
        }
        $head = mb_substr($text, 0, $max);
        $space = mb_strrpos($head, ' ');
        $cut = $tidy($space !== false && $space >= $max / 2 ? mb_substr($head, 0, $space) : mb_substr($text, 0, $max - 1));

        return ($cut !== '' ? $cut : mb_substr($text, 0, $max - 1)).'…';
    }

    /** A job longer than JobDays::MAX_DAYS can't keep all its days: it's refused with a message rather than cut short. */
    private static function tooLong(array $data): ?string
    {
        $days = (int) CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) + 1;

        return $days > JobDays::MAX_DAYS ? 'A job can cover at most '.JobDays::MAX_DAYS.' days. Make it separate jobs.' : null;
    }

    /** Mark a calendar entry "not a job" (or undo that). */
    public function calendarNotJob(Request $request, JobFinder $finder): RedirectResponse
    {
        $data = $request->validate(['key' => 'required|string|size:40', 'date' => 'required|date_format:Y-m-d', 'undo' => 'boolean']);
        $event = JobFinder::event($data['key'], $data['date']);
        abort_unless($event, 404);
        ($data['undo'] ?? false) ? $finder->forgetCalendar($event) : $finder->markNotJob($event);

        return back()->with('success', ($data['undo'] ?? false) ? 'Undone.' : 'Marked as not a job.');
    }

    /** Calendar entries on and around a job's days, with whether each is linked, for the job's page. */
    public static function calendarFor(WorkJob $job): array
    {
        $links = CalendarJobLink::query()->get()->keyBy('event_key');
        $checks = Verification::forMany('calendar_link', $links->where('work_job_id', $job->id)->pluck('id'));

        // A week either side, like the job's search: a de-rig a few days later is offered here too.
        return CalendarEvent::query()
            ->where('starts_at', '>=', $job->starts_on->copy()->subDays(8)->format('Y-m-d 00:00:00'))
            ->where('starts_at', '<=', $job->ends_on->copy()->addDays(8)->format('Y-m-d 23:59:59'))
            ->orderBy('starts_at')->limit(120)->get()
            ->filter(fn (CalendarEvent $e) => $e->day() >= $job->starts_on->copy()->subDays(7)->format('Y-m-d') && $e->day() <= $job->ends_on->copy()->addDays(7)->format('Y-m-d'))->values()
            ->map(function (CalendarEvent $e) use ($links, $job, $checks) {
                $link = $links[$e->key()] ?? null;

                return [
                    'key' => $e->key(), 'date' => $e->day(), 'summary' => $e->summary, 'location' => $e->location,
                    'time' => $e->all_day ? null : $e->localStart()->format('H:i').($e->ends_at ? '–'.$e->localEnd()->format('H:i') : ''),
                    'linked' => $link?->work_job_id === $job->id, 'other_job' => $link?->work_job_id && $link->work_job_id !== $job->id ? $link->work_job_id : null,
                    'by' => $link?->linked_by, 'not_job' => (bool) $link?->not_job, 'check' => $link ? ($checks[$link->id] ?? null) : null,
                ];
            })->all();
    }
}
