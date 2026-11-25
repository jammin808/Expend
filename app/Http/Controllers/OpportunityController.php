<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\Opportunities;
use App\Domain\Todo\TodoList;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\JobDay;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Work you might get (availability checks, holds, offers and bookings found in your emails and documents) and the
 * jobs still waiting for a PO. Nothing here replies to anyone: you answer the client yourself, then record what you
 * decided. Only you make an opportunity a job, decline it or dismiss it, and every change is in its history.
 */
class OpportunityController extends Controller
{
    /** The lists on the page: still open, waiting for your reply, booked with no job, each stage, made jobs, closed, everything. */
    public const FILTERS = ['open', 'reply', 'needs_job', 'enquiry', 'held', 'offered', 'confirmed', 'jobs', 'closed', 'all'];

    /** The stages you can set yourself when editing (declining and dismissing have their own buttons). */
    private const EDIT_STATUSES = ['enquiry', 'held', 'offered', 'confirmed', 'lost'];

    private const RATE_UNITS = ['day' => 'a day', 'hour' => 'an hour', 'job' => 'for the job'];

    public function index(Request $request): Response
    {
        $show = in_array($request->query('show'), self::FILTERS, true) ? (string) $request->query('show') : 'open';
        $query = Opportunity::query()->with(['job:id,title', 'client:id,name']);
        match ($show) {
            'open' => $query->open(),
            'reply' => TodoList::toAnswer($query),
            'needs_job' => $query->needsJob(),
            'enquiry', 'held', 'offered' => $query->where('status', $show)->whereNull('work_job_id'),
            'confirmed' => $query->where('status', 'confirmed'),
            'jobs' => $query->whereNotNull('work_job_id'),
            'closed' => $query->whereIn('status', ['declined', 'lost', 'expired', 'dismissed']),
            default => $query,
        };
        $today = today()->format('Y-m-d');

        // Upcoming first (soonest at the top), then those with no dates yet (newest news first), then past ones (latest first).
        $rows = $query->orderByDesc('last_seen_at')->orderByDesc('id')->limit(500)->get()
            ->sortBy(function (Opportunity $o) use ($today) {
                $start = $o->starts_on?->format('Y-m-d');
                $end = $o->ends_on?->format('Y-m-d') ?? $start;
                if ($start === null) {
                    return [1, ''];
                }

                return $end >= $today ? [0, $start] : [2, (string) (99999999 - (int) str_replace('-', '', $start))];
            })->values();

        return Inertia::render('opportunities/index', [
            'opportunities' => $rows->map(fn (Opportunity $o) => self::row($o))->all(),
            'counts' => self::counts(),
            'filters' => ['show' => $show],
            'statuses' => Opportunity::STATUSES,
            'new_statuses' => array_intersect_key(Opportunity::STATUSES, Opportunity::STAGES),
            'day_kinds' => array_intersect_key(JobDay::KINDS, array_flip(Opportunity::DAY_KINDS)),
            'rate_units' => self::RATE_UNITS,
            'lastRun' => Setting::get('opportunities_last_run'),
        ]);
    }

    public function show(Opportunity $opportunity): Response
    {
        $o = $opportunity->load(['job:id,title,starts_on,ends_on,status', 'client:id,name']);
        $documents = $o->documents()->keyBy('id');
        $history = AuditEvent::query()->where('subject_type', 'Opportunity')->where('subject_id', $o->id)->orderBy('id')->get();

        return Inertia::render('opportunities/show', [
            'opportunity' => self::row($o) + [
                'evidence' => array_map(fn (array $e) => self::evidence($e, $documents), array_values($o->evidence ?? [])),
                'edited' => self::editedByYou($history, $o->found_by === 'you' ? [] : ($o->owner_fields ?? [])),
                'rejected_jobs' => count($o->rejected_job_ids ?? []),
            ],
            'documents' => $documents->values()->map(fn (Document $d) => [
                'id' => $d->id, 'name' => $d->original_name, 'subject' => $d->email_subject, 'from' => $d->email_from, 'kind' => $d->kind,
                'received' => $d->received_at?->format('Y-m-d H:i'), 'job' => $d->work_job_id,
            ])->all(),
            'history' => $history->map(fn (AuditEvent $e) => [
                'id' => $e->id, 'at' => $e->occurred_at?->format('Y-m-d H:i'), 'action' => $e->action, 'actor' => $e->actor, 'reason' => $e->reason,
            ])->all(),
            'prefill' => self::prefill($o),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'day_kinds' => JobDay::KINDS,
            'opportunity_day_kinds' => array_intersect_key(JobDay::KINDS, array_flip(Opportunity::DAY_KINDS)),
            'rate_units' => self::RATE_UNITS,
            'statuses' => Opportunity::STATUSES,
            'edit_statuses' => array_intersect_key(Opportunity::STATUSES, array_flip(self::editStatuses($o))),
        ]);
    }

    /** Work you tell the app about yourself ("Acme asked if I'm free on 12 March"). */
    public function store(Request $request, Opportunities $opportunities): RedirectResponse
    {
        $data = $this->validated($request, false);
        $last = (int) Opportunity::query()->max('id');
        try {
            $o = $opportunities->record(self::fields($data), 'manual', 'you');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('opportunities.show', $o)->with('success', $o->id > $last ? 'Added. Nothing is sent to anyone.' : 'You’d already added this one.');
    }

    /**
     * Correct or fill in its details. What you change is recorded as yours (owner_fields): a later email never changes
     * it, only adds its words to the evidence. Its evidence is kept.
     */
    public function update(Request $request, Opportunity $opportunity): RedirectResponse
    {
        if ($opportunity->work_job_id) {
            return back()->with('error', 'It’s a job now: change the job instead.');
        }
        if (in_array($opportunity->status, Opportunity::YOURS, true)) {
            return back()->with('error', 'You’ve closed this one. Reopen it first to change it.');
        }
        $data = $this->validated($request, true, $opportunity);
        $fields = self::fields($data);
        try {
            $changes = self::changes($opportunity, $fields, $data);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($changes === []) {
            return back()->with('success', 'Nothing changed.');
        }

        DB::transaction(function () use ($opportunity, $changes) {
            $o = Opportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            // Picking an open stage for one that's closed (passed with no answer, or went elsewhere) reopens it, as Reopen
            // does (Opportunities::reopen), so the nightly run doesn't close it again for a date that had already passed.
            // When isn't a detail of yours.
            if (in_array($o->status, ['expired', 'lost'], true) && in_array($changes['status'] ?? null, Opportunity::OPEN, true)) {
                $changes['reopened_at'] = now();
            }
            $before = [];
            foreach (array_keys($changes) as $field) {
                $before[$field] = self::plain($o->{$field});
            }
            $details = array_map(fn ($f) => Opportunity::DETAIL_OF[$f] ?? $f, array_keys(array_diff_key($changes, ['note' => true, 'reopened_at' => true])));
            $o->update($changes + ['owner_fields' => array_values(array_unique([...($o->owner_fields ?? []), ...$details]))]);
            $after = [];
            foreach (array_keys($changes) as $field) {
                $after[$field] = self::plain($o->{$field});
            }
            AuditLog::record('opportunity.edited', $o, $before, $after, 'Changed by you', 'you');
        });

        return back()->with('success', 'Saved.');
    }

    /** Make it a job, with the details as you've checked them in the form. */
    public function accept(Request $request, Opportunity $opportunity, Opportunities $opportunities): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'client_id' => 'nullable|integer|exists:clients,id',
            'client_name' => 'nullable|string|max:120',
            'days' => 'required|array|min:1|max:400',
            'days.*.date' => 'required|date_format:Y-m-d',
            'days.*.kind' => ['required', Rule::in(array_keys(JobDay::KINDS))],
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'venue' => 'nullable|string|max:200',
            'postcode' => 'nullable|string|max:10',
            'po_number' => 'nullable|string|max:100',
            'rate_amount' => 'nullable|string|max:20',
            'rate_currency' => 'nullable|string|size:3|alpha',
            'rate_unit' => ['nullable', Rule::in(array_keys(self::RATE_UNITS))],
            'note' => 'nullable|string|max:2000',
            'status' => 'nullable|in:booked,done',
        ]);
        $clientId = isset($data['client_id']) ? (int) $data['client_id'] : null;

        try {
            $job = $opportunities->accept($opportunity, [
                'title' => trim($data['title']),
                'client_id' => $clientId,
                'client_name' => $clientId ? null : self::blank($data['client_name'] ?? null),
                'dates' => array_map(fn ($d) => ['date' => $d['date'], 'kind' => $d['kind']], $data['days']),
                'start_time' => $data['start_time'] ?? null, 'end_time' => $data['end_time'] ?? null,
                'venue' => self::blank($data['venue'] ?? null),
                'postcode' => self::blank($data['postcode'] ?? null),
                'po_number' => self::blank($data['po_number'] ?? null),
                'rate' => self::rate($data),
                'note' => self::blank($data['note'] ?? null),
                'status' => $data['status'] ?? null,
            ], 'you', $links);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $n = $links['linked'] ?? 0;
        $missed = count($links['missed'] ?? []);

        return to_route('jobs.show', $job)->with('success', "Added {$job->title}".($n ? " and linked {$n} ".($n === 1 ? 'email or document' : 'emails and documents') : '')
            .($missed ? " ({$missed} already on another job, left there)" : '').'. Nothing was sent to anyone.');
    }

    public function decline(Request $request, Opportunity $opportunity, Opportunities $opportunities): RedirectResponse
    {
        $note = $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null;
        try {
            $opportunities->decline($opportunity, $note, 'you');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marked as declined. Nothing was sent: reply to them yourself if you haven’t.');
    }

    public function dismiss(Request $request, Opportunity $opportunity, Opportunities $opportunities): RedirectResponse
    {
        $note = $request->validate(['note' => 'nullable|string|max:500'])['note'] ?? null;
        try {
            $opportunities->dismiss($opportunity, $note, 'you');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dismissed: not work. Later emails about it won’t bring it back.');
    }

    /** You say the job the rules matched it to is the one. */
    public function confirmLink(Opportunity $opportunity, Opportunities $opportunities): RedirectResponse
    {
        try {
            $o = $opportunities->confirmLink($opportunity, 'you');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Confirmed: it’s '.($o->job?->title ?? 'that job').'.');
    }

    public function reopen(Opportunity $opportunity, Opportunities $opportunities): RedirectResponse
    {
        try {
            $o = $opportunities->reopen($opportunity, 'you');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Reopened: '.mb_strtolower(Opportunity::STATUSES[$o->status] ?? $o->status).'.');
    }

    /**
     * Jobs with no PO yet that are worth asking about (Opportunities::poChase), each with who to ask and an email
     * ready to open in your own mail app. Nothing is sent from here. ?job= narrows it to one job.
     */
    public function poChase(Request $request, Opportunities $opportunities): JsonResponse
    {
        $job = $request->integer('job') ?: null;
        $items = $opportunities->poChase(null, 60, $job ? [$job] : null);
        $name = trim((string) ($request->user()?->name ?? ''));

        return response()->json(['items' => array_map(fn ($i) => $i + self::poRequest($i, $name), $items)]);
    }

    /**
     * A short request for the PO, ready to open in your mail app (a mailto link): you read it and send it yourself.
     *
     * @return array{dates_text: string, request: ?array{to: string, subject: string, body: string, mailto: string}}
     */
    public static function poRequest(array $item, string $signOff = ''): array
    {
        $dates = self::datesText($item['starts_on'], $item['ends_on']);
        $email = $item['contact']['email'] ?? null;
        if (! $email) {
            return ['dates_text' => $dates, 'request' => null];
        }
        $first = trim(explode(' ', trim((string) ($item['contact']['name'] ?? '')))[0] ?? '');
        $subject = 'PO for '.$item['title'].' ('.$dates.')';
        $body = ($first !== '' ? "Hi {$first},\n\n" : "Hi,\n\n")
            .'Could you send the PO for '.$item['title'].' on '.$dates."?\n\nThanks".($signOff !== '' ? ",\n".$signOff : '');

        return ['dates_text' => $dates, 'request' => [
            'to' => $email, 'subject' => $subject, 'body' => $body,
            'mailto' => 'mailto:'.rawurlencode($email).'?subject='.rawurlencode($subject).'&body='.rawurlencode($body),
        ]];
    }

    /** "12 March 2026", "12 to 14 March 2026", "28 February to 2 March 2026". */
    public static function datesText(string $from, ?string $to): string
    {
        $a = CarbonImmutable::parse($from);
        $b = CarbonImmutable::parse($to ?? $from);
        if ($a->isSameDay($b)) {
            return $a->format('j F Y');
        }
        if ($a->year !== $b->year) {
            return $a->format('j F Y').' to '.$b->format('j F Y');
        }

        return ($a->month === $b->month ? $a->format('j') : $a->format('j F')).' to '.$b->format('j F Y');
    }

    // ---------------------------------------------------------------- presenting

    /** One opportunity as the pages show it. */
    public static function row(Opportunity $o): array
    {
        $days = $o->dates ?: array_map(fn ($d) => ['date' => $d, 'kind' => 'work'], $o->dayDates());

        return [
            'id' => $o->id, 'status' => $o->status, 'status_label' => Opportunity::STATUSES[$o->status] ?? $o->status, 'hold_rank' => $o->hold_rank,
            'title' => $o->title, 'client_id' => $o->client_id, 'client_name' => $o->client?->name ?? $o->client_name,
            'contact_name' => $o->contact_name, 'contact_email' => $o->contact_email, 'role' => $o->role, 'venue' => $o->venue, 'postcode' => $o->postcode,
            'starts_on' => $o->starts_on?->format('Y-m-d'), 'ends_on' => $o->ends_on?->format('Y-m-d'), 'days' => array_values($days),
            'rate' => $o->rate_minor ? [
                'minor' => $o->rate_minor, 'currency' => $o->rate_currency ?? 'GBP', 'unit' => $o->rate_unit ?? 'job',
                'text' => Money::formatMinor($o->rate_minor, $o->rate_currency ?? 'GBP', false).' '.(self::RATE_UNITS[$o->rate_unit ?? 'job'] ?? ''),
            ] : null,
            'reply_by' => $o->reply_by?->format('Y-m-d'),
            'reply_in' => $o->reply_by ? (int) today()->diffInDays($o->reply_by, false) : null,
            'po_number' => $o->po_number,
            'job' => $o->job ? ['id' => $o->job->id, 'title' => $o->job->title] : null,
            'source' => $o->source, 'found_by' => $o->found_by, 'confidence' => $o->confidence,
            'unverified' => $o->unverifiedFields(),
            'may_be_off' => $o->mayBeOff(),
            'documents' => count($o->documentIds()),
            'first_seen' => $o->first_seen_at?->format('Y-m-d H:i'), 'last_seen' => $o->last_seen_at?->format('Y-m-d H:i'),
            'decided_at' => $o->decided_at?->format('Y-m-d H:i'), 'decided_by' => $o->decided_by, 'note' => $o->note,
            'open' => $o->isOpen(), 'accepted' => $o->isAccepted(), 'linked_by_rules' => $o->work_job_id !== null && ! $o->isAccepted(),
        ];
    }

    /** One piece of evidence: the words a detail came from, whether they were found, and the email or document they're in. */
    private static function evidence(array $e, $documents): array
    {
        $type = (string) ($e['type'] ?? 'document');
        $id = isset($e['id']) && is_numeric($e['id']) ? (int) $e['id'] : null;
        $doc = $type === 'document' && $id ? ($documents[$id] ?? null) : null;

        return [
            'type' => $type, 'id' => $id, 'field' => $e['field'] ?? null,
            'value' => isset($e['value']) && is_scalar($e['value']) ? (string) $e['value'] : null,
            'quote' => isset($e['quote']) && is_scalar($e['quote']) ? (string) $e['quote'] : null,
            'verified' => (bool) ($e['verified'] ?? false),
            'href' => $type === 'document' && $id ? '/documents/'.$id : null,
            'source' => $doc ? ($doc->email_subject ?: $doc->original_name) : ($type === 'document' ? 'A document no longer here' : ucfirst(str_replace('_', ' ', $type))),
            'received' => $doc?->received_at?->format('Y-m-d'),
        ];
    }

    /** @return list<string> the details you've changed yourself (from its history, and those it keeps as yours) */
    private static function editedByYou($history, array $owner = []): array
    {
        $fields = array_fill_keys($owner, true);
        foreach ($history as $e) {
            if ($e->action === 'opportunity.edited') {
                foreach (array_keys(array_diff_key($e->after ?? [], ['reopened_at' => true])) as $f) {
                    $fields[match ($f) {
                        'client_name', 'client_id' => 'client', 'contact_name', 'contact_email' => 'contact',
                        'starts_on', 'ends_on', 'dates' => 'dates', 'rate_minor', 'rate_currency', 'rate_unit' => 'rate', default => $f,
                    }] = true;
                }
            }
        }

        return array_keys($fields);
    }

    /** The job form, filled in from the opportunity: you check it before the job is made. */
    private static function prefill(Opportunity $o): array
    {
        $days = $o->dates ?: array_map(fn ($d) => ['date' => $d, 'kind' => 'work'], $o->dayDates());
        $last = $days ? max(array_column($days, 'date')) : null;
        $currency = $o->rate_currency ?? 'GBP';
        $digits = Money::digits($currency);
        // A rate not found word for word (its amount, currency and unit) isn't filled in: it's in the note to check.
        $rate = $o->rate_minor && ! in_array('rate', $o->unverifiedFields(), true) ? $o->rate_minor : null;
        $note = array_filter([
            $o->role ? 'Role: '.$o->role : null,
            $o->contact_name || $o->contact_email ? 'Contact: '.trim(($o->contact_name ?? '').($o->contact_email ? ' <'.$o->contact_email.'>' : '')) : null,
            $o->rate_minor && $rate === null ? 'Rate read from the email, not found word for word (check it): '.Money::formatMinor($o->rate_minor, $currency, false)
                .' '.(self::RATE_UNITS[$o->rate_unit ?? 'job'] ?? '') : null,
        ]);

        return [
            'title' => $o->title, 'client_id' => $o->client_id, 'client_name' => $o->client?->name ?? $o->client_name,
            'days' => array_values($days), 'venue' => $o->venue, 'postcode' => $o->postcode, 'po_number' => $o->po_number,
            'rate_amount' => $rate ? number_format($rate / (10 ** $digits), $digits, '.', '') : '',
            'rate_currency' => $currency, 'rate_unit' => $o->rate_unit ?? 'day',
            'note' => $note ? implode("\n", $note) : '',
            'status' => $last !== null && $last < today()->format('Y-m-d') ? 'done' : 'booked',
        ];
    }

    /** How many in each list. */
    private static function counts(): array
    {
        $rows = Opportunity::query()->selectRaw('status, CASE WHEN work_job_id IS NULL THEN 0 ELSE 1 END AS has_job, COUNT(*) AS n')
            ->groupByRaw('status, CASE WHEN work_job_id IS NULL THEN 0 ELSE 1 END')->get();
        $sum = fn (callable $keep) => (int) $rows->filter($keep)->sum('n');

        return [
            'open' => $sum(fn ($r) => ! $r->has_job && in_array($r->status, Opportunity::OPEN, true)),
            'reply' => TodoList::toAnswer(Opportunity::query())->count(),
            'needs_job' => $sum(fn ($r) => ! $r->has_job && $r->status === 'confirmed'),
            'enquiry' => $sum(fn ($r) => ! $r->has_job && $r->status === 'enquiry'),
            'held' => $sum(fn ($r) => ! $r->has_job && $r->status === 'held'),
            'offered' => $sum(fn ($r) => ! $r->has_job && $r->status === 'offered'),
            'confirmed' => $sum(fn ($r) => $r->status === 'confirmed'),
            'jobs' => $sum(fn ($r) => (bool) $r->has_job),
            'closed' => $sum(fn ($r) => in_array($r->status, ['declined', 'lost', 'expired', 'dismissed'], true)),
            'all' => $sum(fn () => true),
        ];
    }

    // ---------------------------------------------------------------- editing

    /**
     * The stages the edit form offers: one that passed with no answer stays so until you pick another stage (it's
     * never set from the form; the nightly run does that).
     *
     * @return list<string>
     */
    private static function editStatuses(?Opportunity $o = null): array
    {
        return $o?->status === 'expired' ? [...self::EDIT_STATUSES, 'expired'] : self::EDIT_STATUSES;
    }

    private function validated(Request $request, bool $editing, ?Opportunity $opportunity = null): array
    {
        return $request->validate([
            'title' => ($editing ? 'required' : 'nullable').'|string|max:150',
            'status' => [$editing ? 'required' : 'nullable', Rule::in(self::editStatuses($opportunity))],
            'hold_rank' => 'nullable|integer|min:1|max:9',
            'client_name' => 'nullable|string|max:120',
            'contact_name' => 'nullable|string|max:120',
            // The same rule the reader keeps a contact's email by (Opportunities::contactEmail), so one it kept always saves.
            'contact_email' => ['nullable', 'bail', 'string', 'email', 'max:200', function (string $attribute, mixed $value, \Closure $fail) {
                if (Opportunities::contactEmail($value) === null) {
                    $fail('Give one email address, like name@example.co.uk.');
                }
            }],
            'role' => 'nullable|string|max:120',
            'venue' => 'nullable|string|max:200',
            'postcode' => 'nullable|string|max:10',
            'days' => 'nullable|array|max:400',
            'days.*.date' => 'required|date_format:Y-m-d',
            'days.*.kind' => ['required', Rule::in(Opportunity::DAY_KINDS)],
            'reply_by' => 'nullable|date_format:Y-m-d',
            'rate_amount' => 'nullable|string|max:20',
            'rate_currency' => 'nullable|string|size:3|alpha',
            'rate_unit' => ['nullable', Rule::in(array_keys(self::RATE_UNITS))],
            'po_number' => 'nullable|string|max:100',
            'note' => 'nullable|string|max:2000',
        ]);
    }

    /** The form's details as Opportunities::record() takes them. */
    private static function fields(array $data): array
    {
        $status = $data['status'] ?? null;

        return [
            'title' => self::blank($data['title'] ?? null), 'status' => $status === 'lost' ? null : $status,
            'hold_rank' => isset($data['hold_rank']) ? (int) $data['hold_rank'] : null,
            'client_name' => self::blank($data['client_name'] ?? null), 'contact_name' => self::blank($data['contact_name'] ?? null),
            'contact_email' => self::blank($data['contact_email'] ?? null), 'role' => self::blank($data['role'] ?? null),
            'venue' => self::blank($data['venue'] ?? null), 'postcode' => self::blank($data['postcode'] ?? null),
            'dates' => array_values(array_map(fn ($d) => ['date' => $d['date'], 'kind' => $d['kind']], $data['days'] ?? [])),
            'reply_by' => self::blank($data['reply_by'] ?? null), 'rate' => self::rate($data),
            'po_number' => self::blank($data['po_number'] ?? null), 'note' => self::blank($data['note'] ?? null),
        ];
    }

    /**
     * What an edit changes, as the opportunity's own columns.
     *
     * @throws InvalidArgumentException when the dates are too far apart
     */
    private static function changes(Opportunity $o, array $fields, array $data): array
    {
        $dates = [];
        foreach ($fields['dates'] as $d) {
            $dates[$d['date']] = $d;
        }
        ksort($dates);
        $dates = array_values($dates);
        if ($dates && CarbonImmutable::parse($dates[0]['date'])->diffInDays(CarbonImmutable::parse($dates[count($dates) - 1]['date'])) + 1 > 400) {
            throw new InvalidArgumentException('Work can cover at most 400 days.');
        }
        $clientName = $fields['client_name'];
        // The page shows the linked client's own name: sent back as it was shown, the client stays as it is.
        $sameClient = $clientName !== null && $o->client_id !== null && $clientName === $o->client?->name;
        $rate = $fields['rate'];

        $wanted = [
            'title' => mb_substr((string) $fields['title'], 0, 150),
            'status' => $data['status'],
            // The rank is in the form only for a hold, an offer or a booking: otherwise it's kept, unless you change the stage.
            'hold_rank' => in_array($data['status'], ['held', 'offered', 'confirmed'], true) ? $fields['hold_rank'] : ($data['status'] === $o->status ? $o->hold_rank : null),
            'client_name' => $sameClient ? $o->client_name : $clientName,
            'client_id' => $sameClient ? $o->client_id : ($clientName === null ? null : (self::clientIdFor($clientName) ?? ($o->client_name === $clientName ? $o->client_id : null))),
            'contact_name' => $fields['contact_name'],
            'contact_email' => Opportunities::contactEmail($fields['contact_email']),
            'role' => $fields['role'], 'venue' => $fields['venue'],
            'postcode' => $fields['postcode'] !== null ? mb_strtoupper($fields['postcode']) : null,
            'dates' => $dates ?: null,
            'starts_on' => $dates[0]['date'] ?? null,
            'ends_on' => $dates ? $dates[count($dates) - 1]['date'] : null,
            'reply_by' => $fields['reply_by'],
            'rate_minor' => $rate['amount_minor'] ?? null, 'rate_currency' => $rate['currency'] ?? null, 'rate_unit' => $rate['unit'] ?? null,
            'po_number' => $fields['po_number'], 'note' => $fields['note'],
        ];
        $changes = [];
        foreach ($wanted as $field => $value) {
            if (self::plain($o->{$field}) !== self::plain($value)) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /** The client in your list with this name (ignoring capitals and "Ltd"), only when exactly one matches. */
    private static function clientIdFor(string $name): ?int
    {
        $exact = Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->pluck('id');
        if ($exact->count() === 1) {
            return (int) $exact->first();
        }
        $key = JobCreator::clientKey($name);
        $same = $key === '' ? collect() : Client::query()->get(['id', 'name'])->filter(fn (Client $c) => JobCreator::clientKey($c->name) === $key);

        return $same->count() === 1 ? (int) $same->first()->id : null;
    }

    /**
     * The rate typed in the form, in the currency's own minor units.
     *
     * @return ?array{amount_minor: int, currency: string, unit: string}
     */
    private static function rate(array $data): ?array
    {
        $amount = self::blank($data['rate_amount'] ?? null);
        if ($amount === null) {
            return null;
        }
        $currency = strtoupper(self::blank($data['rate_currency'] ?? null) ?? 'GBP');
        try {
            $minor = Money::parseIn($amount, $currency);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rate_amount' => $e->getMessage()]);
        }
        if ($minor === null || $minor <= 0) {
            throw ValidationException::withMessages(['rate_amount' => 'Give the rate as an amount above zero, like 350.00.']);
        }

        return ['amount_minor' => $minor, 'currency' => $currency, 'unit' => $data['rate_unit'] ?? 'day'];
    }

    private static function blank(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /** A value as it's compared and kept in the history: dates as Y-m-d, lists as they are. */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_array($value) || $value === null) {
            return $value;
        }
        // A whole number may come back from the database as text ("12"), and is the same as 12.
        if (is_string($value) && preg_match('/^-?[1-9]\d{0,17}$|^0$/', $value)) {
            return (int) $value;
        }

        return (string) $value;
    }
}
