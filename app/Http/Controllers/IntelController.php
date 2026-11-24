<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Methods;
use App\Domain\Intel\RefResolver;
use App\Domain\Intel\Vetoes;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Travel\TravelMatcher;
use App\Models\AuditEvent;
use App\Models\CalendarJobLink;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\JobFind;
use App\Models\LinkProvenance;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * What Expend found and did overnight (docs/intelligence-cycle.md 7.1 and 7.5): the latest morning report, "Check now",
 * the run log at /intel/runs, and the links it made on its own for you to accept or say no to at /intel/proposals (7.3).
 * Only reads, except "Check now", which asks for a run (CycleRunner::request()), and your answer to a proposal.
 */
class IntelController extends Controller
{
    /** Proposals listed of each kind. */
    private const PROPOSALS = 50;

    /** What each kind of link is, in plain words. */
    private const LINK_WORDS = [
        'calendar_job' => 'A calendar entry on a job', 'document_job' => 'A document on a job', 'travel_job' => 'A stay or journey on a job',
        'travel_payment' => 'The payment for a stay or journey', 'invoice_job' => 'An invoice on a job',
    ];

    public function index(): Response
    {
        $run = CycleRun::query()->whereNotNull('report')->orderByDesc('id')->first();

        return Inertia::render('intel/index', [
            'mode' => CycleRunner::mode(),
            'report' => $run?->report,
            'reportRun' => $run ? self::runRow($run) : null,
            'runs' => CycleRun::query()->orderByDesc('id')->limit(5)->get()->map(fn (CycleRun $r) => self::runRow($r))->all(),
            'checking' => CycleRun::query()->whereIn('kind', ['manual', 'import'])->where('status', 'running')->exists(),
        ]);
    }

    public function runs(): Response
    {
        $runs = CycleRun::query()->orderByDesc('id')->paginate(20);
        $steps = CycleRunStep::query()->whereIn('cycle_run_id', $runs->pluck('id'))->orderBy('seq')->orderBy('id')->get()->groupBy('cycle_run_id');

        return Inertia::render('intel/runs', [
            'mode' => CycleRunner::mode(),
            'page' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'links' => ['prev' => $runs->previousPageUrl(), 'next' => $runs->nextPageUrl()]],
            'runs' => $runs->getCollection()->map(fn (CycleRun $r) => self::runRow($r) + [
                'steps' => ($steps[$r->id] ?? collect())->map(fn (CycleRunStep $s) => [
                    'step' => $s->step, 'words' => CycleRunner::stepWords($s->step), 'stage' => $s->stage, 'status' => $s->status,
                    'status_words' => CycleRunStep::STATUS_WORDS[$s->status] ?? $s->status, 'attempts' => $s->attempts, 'coverage' => $s->coverage,
                    'detector_version' => $s->detector_version, 'counts' => $s->counts, 'error' => $s->error,
                    'started_at' => $s->started_at?->toIso8601String(), 'finished_at' => $s->finished_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    /** "Check now": run the detectors and record what they find, in the worker, within the minute. */
    public function check(CycleRunner $runner): RedirectResponse
    {
        if (CycleRunner::mode() === 'off') {
            return back()->with('error', 'The nightly check isn’t switched on yet, so there’s nothing to run. The to-do list is still up to date.');
        }
        $run = $runner->request('manual');
        if (! $run) {
            return back()->with('error', 'Couldn’t start a check just now. Try again in a minute.');
        }
        if (! $run->wasRecentlyCreated && in_array($run->kind, ['nightly', 'morning'], true)) {
            // A run going now records the findings as they are when it gets there: no second one.
            return back()->with('success', 'The '.($run->kind === 'nightly' ? 'nightly' : 'morning').' check is running now: it records what it finds when it gets there.');
        }

        return back()->with('success', 'Checking now: the findings are updated within a minute or two.');
    }

    /**
     * Links Expend made on its own that you haven't said yes or no to (docs/intelligence-cycle.md 7.3, M7): calendar
     * entries, documents and stays put on jobs, a stay's payment found in your statements, an invoice put on a job by its
     * PO number. Each with the claim in a sentence, how it was made, what it relied on, and the alternatives it weighed;
     * Accept, Not this one (with a reason) or Later. Saying no is remembered, so it isn't linked again. Only reads.
     */
    public function proposals(): Response
    {
        $cards = [];
        $jobs = collect();
        $add = function (string $link, string $subject, ?int $jobId, string $claim, ?string $by, ?string $href, ?string $txRef = null) use (&$cards) {
            $cards[] = ['link' => $link, 'subject' => $subject, 'object' => $txRef ?? ($jobId ? 'job:'.$jobId : null), 'claim' => $claim, 'by' => $by, 'href' => $href];
        };
        foreach (CalendarJobLink::query()->whereIn('linked_by', ['rules', 'ai'])->whereNotNull('work_job_id')->where('not_job', false)->orderByDesc('id')->limit(self::PROPOSALS)->get() as $l) {
            $add('calendar_job', 'cal:'.$l->event_key, (int) $l->work_job_id, 'Calendar entry “'.($l->summary ?? 'an entry').'”', $l->linked_by, '/jobs/calendar');
        }
        foreach (Document::query()->whereIn('job_linked_by', ['rules', 'ai'])->whereNotNull('work_job_id')->whereNull('parent_id')->orderByDesc('id')->limit(self::PROPOSALS)->get() as $d) {
            $add('document_job', 'doc:'.$d->id, (int) $d->work_job_id, 'Document “'.($d->email_subject ?: $d->original_name).'”', $d->job_linked_by, '/documents/'.$d->id);
        }
        foreach (TravelItem::query()->whereNull('decided_at')->where(fn ($q) => $q->where('job_linked_by', 'rules')->whereNotNull('work_job_id')->orWhereNotNull('bank_transaction_id'))
            ->orderByDesc('id')->limit(self::PROPOSALS)->get() as $t) {
            $what = (TravelItem::KINDS[$t->kind] ?? 'Travel').' “'.($t->place ?: $t->provider).'”, '.$t->starts_on->format('j M Y');
            if ($t->job_linked_by === 'rules' && $t->work_job_id) {
                $add('travel_job', 'travel:'.$t->id, (int) $t->work_job_id, $what, 'rules', '/travel');
            }
            if ($t->bank_transaction_id) {
                $add('travel_payment', 'travel:'.$t->id, null, $what, 'matcher', '/travel', 'tx:'.$t->bank_transaction_id);
            }
        }
        // Only drafts: issuing an invoice that's on a job is you accepting it there (an issued invoice can't be moved, only
        // voided), so it isn't offered for checking.
        $linked = AuditEvent::query()->where('subject_type', 'Invoice')->where('action', 'invoice.job_linked')->orderByDesc('id')->limit(500)->get()->unique('subject_id');
        $invoices = Invoice::query()->whereIn('id', $linked->pluck('subject_id')->all() ?: [0])->whereNotNull('work_job_id')->where('status', 'draft')->get()->keyBy('id');
        foreach ($linked as $e) {
            $i = $invoices[$e->subject_id] ?? null;
            if ($i && $e->actor !== 'you' && (int) ($e->after['work_job_id'] ?? 0) === (int) $i->work_job_id && count($cards) < self::PROPOSALS * 5) {
                $add('invoice_job', 'inv:'.$i->id, (int) $i->work_job_id, 'Invoice '.($i->number ?? 'draft').' ('.Money::format($i->total_minor, $i->currency ?? 'GBP').')', 'matcher', '/invoices/'.$i->id);
            }
        }

        // Their stored why (only while it still describes the link), and every ref they cite, worked out together.
        $rows = LinkProvenance::query()->whereIn('subject_ref', array_column($cards, 'subject') ?: [''])->get()->groupBy('link');
        $refs = [];
        foreach ($cards as $i => $c) {
            $row = ($rows[$c['link']] ?? collect())->firstWhere('subject_ref', $c['subject']);
            $row = $row && $row->object_ref === $c['object'] ? $row : null;
            $cards[$i]['row'] = $row;
            array_push($refs, ...array_filter([$c['object'], ...($row?->evidence ?? []), ...array_column($row?->alternatives ?? [], 'ref')]));
        }
        $resolved = RefResolver::resolveMany(array_values(array_unique($refs)));
        $label = fn (?string $ref) => $ref === null ? null : ['ref' => $ref, 'label' => (string) ($resolved[$ref]['label'] ?? $ref), 'href' => $resolved[$ref]['href'] ?? null];
        $out = array_map(function (array $c) use ($label) {
            $row = $c['row'];
            $method = $row?->method ?? self::legacyMethod($c['link'], $c['by']);
            $to = $label($c['object']);

            return [
                'link' => $c['link'], 'subject' => $c['subject'], 'object' => $c['object'], 'href' => $c['href'],
                'about' => self::LINK_WORDS[$c['link']],
                'claim' => $c['claim'].($c['link'] === 'travel_payment' ? ' was paid by '.($to['label'] ?? 'a payment') : ' is on '.($to['label'] ?? 'a job')).'.',
                'to' => $to,
                'method' => $method !== null && Methods::has($method) ? Methods::describe($method) : null,
                'basis' => $method !== null && Methods::has($method) ? (Methods::BASES[Methods::basis($method)] ?? null) : null,
                'grade' => 'unmeasured', 'grade_basis' => 'Not measured yet: an automatic link, which you can accept or say no to',
                'score' => $row?->score, 'score_means' => $method !== null && Methods::has($method) ? Methods::scoreMeans($method) : null,
                'evidence' => array_values(array_filter(array_map($label, array_values(array_diff($row?->evidence ?? [], [$c['subject'], $c['object']]))))),
                'alternatives' => array_map(fn (array $a) => $label($a['ref']) + ['score' => $a['score'] ?? null, 'behind' => isset($a['score'], $row->score) ? $row->score - $a['score'] : null],
                    $row?->alternatives ?? []),
                'can_accept' => $c['link'] !== 'travel_payment',
            ];
        }, $cards);

        return Inertia::render('intel/proposals', ['proposals' => $out, 'reasons' => Vetoes::REASONS]);
    }

    /**
     * Accept an automatic link (it's yours now, and automation leaves it alone), or say "Not this one": it's taken off
     * and remembered, through the store each link already has (a calendar entry's or document's rejected jobs, a stay
     * unlinked by you) or a veto (a stay's payment, an invoice on a job), so it isn't linked again. Audited as yours.
     */
    public function decideProposal(Request $request, JobFinder $finder, TravelMatcher $matcher): RedirectResponse
    {
        $data = $request->validate([
            'link' => ['required', Rule::in(array_keys(self::LINK_WORDS))], 'subject' => 'required|string|max:191', 'object' => 'nullable|string|max:191',
            'answer' => 'required|in:accept,reject', 'reason_code' => ['nullable', Rule::in(array_keys(Vetoes::REASONS))],
        ]);
        $id = fn (?string $ref, string $kind) => $ref !== null && preg_match('/^'.$kind.':(\d+)$/', $ref, $m) ? (int) $m[1] : null;
        // Why not, kept with what saying no records (the link's history, or its veto), so it can be seen later.
        $reason = isset($data['reason_code']) ? Vetoes::REASONS[$data['reason_code']] : null;
        $note = $reason !== null ? 'Not this job: '.mb_strtolower($reason) : null;
        $job = ($j = $id($data['object'] ?? null, 'job')) ? WorkJob::query()->find($j) : null;
        $accept = $data['answer'] === 'accept';
        $changed = fn () => back()->with('error', 'That has changed since the page was shown: look at it again.');

        try {
            $message = DB::transaction(function () use ($data, $id, $job, $accept, $reason, $note, $finder, $matcher) {
                switch ($data['link']) {
                    case 'calendar_job':
                        $event = str_starts_with($data['subject'], 'cal:') ? JobFinder::event(substr($data['subject'], 4), null) : null;
                        $link = $event ? CalendarJobLink::query()->where('event_key', $event->key())->first() : null;
                        if (! $event || ! $job || (int) $link?->work_job_id !== $job->id) {
                            return null;
                        }
                        $accept ? $finder->linkCalendar($event, $job, 'you', 'Accepted by you') : $finder->rejectCalendarFor($event, $job->id, $note);
                        break;
                    case 'document_job':
                        $doc = Document::query()->find($id($data['subject'], 'doc'));
                        if (! $doc || ! $job || (int) $doc->work_job_id !== $job->id) {
                            return null;
                        }
                        $accept ? $finder->linkDocument($doc, $job, 'you') : $finder->rejectDocumentFor($doc, $job, $reason);
                        break;
                    case 'travel_job':
                        $item = TravelItem::query()->find($id($data['subject'], 'travel'));
                        if (! $item || ! $job || (int) $item->work_job_id !== $job->id) {
                            return null;
                        }
                        $finder->linkTravel($item, $accept ? $job : null, 'you', reason: $accept ? null : $note);
                        break;
                    case 'travel_payment':
                        $item = TravelItem::query()->find($id($data['subject'], 'travel'));
                        $txId = $id($data['object'] ?? null, 'tx');
                        if ($accept || ! $item || ! $txId || (int) $item->bank_transaction_id !== $txId || $item->decided_at !== null) {
                            return null;
                        }
                        if (! $matcher->notItsPayment($item, $txId, $data['reason_code'] ?? null, $reason)) {
                            return null;
                        }
                        break;
                    case 'invoice_job':
                        $invoice = Invoice::query()->find($id($data['subject'], 'inv'));
                        if (! $invoice || ! $job || (int) $invoice->work_job_id !== $job->id) {
                            return null;
                        }
                        if ($accept) {
                            AuditLog::record('invoice.job_linked', $invoice, ['work_job_id' => $job->id], ['work_job_id' => $job->id], 'Accepted by you');
                            break;
                        }
                        if ($invoice->status !== 'draft') {
                            throw new InvalidArgumentException('Invoice '.($invoice->number ?? 'draft').' has been issued, so it stays with '.$job->title.'. To put it on another job, void it and issue a new one.');
                        }
                        $find = JobFind::query()->firstOrCreate(['work_job_id' => $job->id, 'type' => 'invoice', 'ref' => (string) $invoice->id],
                            ['status' => 'linked', 'label' => 'Invoice '.($invoice->number ?? 'draft'), 'detail' => 'Quotes the job’s PO number']);
                        app(JobGatherer::class)->reject($find, $data['reason_code'] ?? null, $reason);
                        break;
                }

                return $accept ? 'Accepted: it’s yours now, and Expend leaves it as it is.' : 'OK: not this one. It won’t be linked again.';
            });
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $message === null ? $changed() : back()->with('success', $message);
    }

    /** The method of an automatic link made before its why was stored, from who made it. */
    private static function legacyMethod(string $link, ?string $by): ?string
    {
        return match ($link) {
            'calendar_job' => $by === 'ai' ? 'jobs.calendar_ai' : 'jobs.calendar_rules',
            'document_job' => $by === 'ai' ? 'doc.read' : 'jobs.document',
            'travel_job', 'travel_payment' => 'travel.match',
            'invoice_job' => 'jobs.invoice_po',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function runRow(CycleRun $r): array
    {
        return [
            'id' => $r->id, 'run_key' => $r->run_key, 'kind' => $r->kind, 'kind_words' => CycleRun::KIND_WORDS[$r->kind] ?? $r->kind, 'mode' => $r->mode,
            'status' => $r->status, 'status_words' => CycleRun::STATUS_WORDS[$r->status] ?? $r->status,
            'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(), 'reported_at' => $r->reported_at?->toIso8601String(),
            'ai_spent_usd' => round($r->ai_spent_micro / 1_000_000, 2), 'ai_budget_usd' => round($r->ai_budget_micro / 1_000_000, 2),
            'summary' => ($r->counts ?? [])['summary'] ?? null, 'sealed' => $r->seal_audit_id !== null, 'error' => $r->error,
        ];
    }
}
