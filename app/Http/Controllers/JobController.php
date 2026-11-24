<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Jobs\PoStatus;
use App\Domain\Tax\MileageCalculator;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobController extends Controller
{
    public function index(Request $request): Response
    {
        $year = $request->string('year')->toString() ?: null;
        $period = $year ? UkTaxYear::fromLabel($year) : null;
        $show = in_array($request->query('show'), ['no_po', 'not_invoiced'], true) ? $request->query('show') : null;

        $jobs = WorkJob::query()->with('client')
            ->when($period, fn ($q) => $q->whereBetween('starts_on', [$period->startsOn(), $period->endsOn()]))
            ->orderByDesc('starts_on')->limit(300)->get();

        $costs = Allocation::query()->whereIn('work_job_id', $jobs->pluck('id'))->whereNotNull('confirmed_at')
            ->selectRaw('work_job_id, kind, SUM(amount_pence) as total')->groupBy('work_job_id', 'kind')->get()->groupBy('work_job_id');

        $po = PoStatus::forJobs($jobs);
        $rows = $jobs->map(fn (WorkJob $j) => Present::job($j) + $po[$j->id] + [
            'income_pence' => (int) ($costs[$j->id] ?? collect())->where('kind', 'business_income')->sum('total'),
            'costs_pence' => (int) -($costs[$j->id] ?? collect())->where('kind', 'business_expense')->sum('total'),
        ]);

        return Inertia::render('jobs/index', [
            'jobs' => $rows->filter(fn ($r) => match ($show) {
                'no_po' => $r['missing'],
                'not_invoiced' => ! $r['invoice'] && $r['status'] !== 'cancelled',
                default => true,
            })->values(),
            'counts' => ['no_po' => $rows->where('missing', true)->count(), 'not_invoiced' => $rows->filter(fn ($r) => ! $r['invoice'] && $r['status'] !== 'cancelled')->count(),
                'possible' => JobCandidate::query()->where('status', 'open')->count()],
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['year' => $year, 'show' => $show],
        ]);
    }

    public function show(WorkJob $job): Response
    {
        $job->load('client');
        $allocations = Allocation::query()->where('work_job_id', $job->id)->with(['category', 'transaction.account', 'manualExpense'])->get();
        $years = collect([UkTaxYear::fromDate($job->starts_on)->label(), UkTaxYear::fromDate($job->ends_on)->label()])->unique();
        $mileage = $years->flatMap(fn ($y) => MileageCalculator::forYear($y)['trips'])->where('job.id', $job->id)->values();

        $near = BankTransaction::query()->with('account')
            ->whereBetween('posted_on', [$job->starts_on->copy()->subDays(3), $job->ends_on->copy()->addDays(5)])
            ->whereIn('review_status', ['unreviewed', 'suggested'])->orderBy('posted_on')->limit(60)->get();

        // The fee usually arrives later: money in from this client, or for the agreed amount, within 90 days.
        $clientWords = collect(preg_split('/\s+/', strtoupper((string) $job->client?->name)))
            ->reject(fn ($w) => strlen($w) < 3 || in_array($w, ['LTD', 'LIMITED', 'THE', 'AND', 'PLC', 'LLP'], true));
        $fees = BankTransaction::query()->with('account')->where('amount_pence', '>', 0)
            ->whereIn('review_status', ['unreviewed', 'suggested'])
            ->whereBetween('posted_on', [$job->starts_on->copy()->subDays(30), $job->ends_on->copy()->addDays(90)])
            ->where(function ($q) use ($job, $clientWords) {
                if ($job->fee_pence) {
                    $q->orWhere('amount_pence', $job->fee_pence);
                }
                foreach ($clientWords as $word) {
                    $q->orWhere('description', 'like', "%{$word}%")->orWhere('counterparty', 'like', "%{$word}%");
                }
            })
            ->when(! $job->fee_pence && $clientWords->isEmpty(), fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('posted_on')->limit(10)->get();
        $near = $fees->concat($near)->unique('id')->values();

        $income = $allocations->where('kind', 'business_income')->whereNotNull('confirmed_at')->sum('amount_pence');
        $spent = -$allocations->where('kind', 'business_expense')->whereNotNull('confirmed_at')->sum('amount_pence');
        $mileagePence = $mileage->sum('claim_pence');

        $documents = Document::query()->where('work_job_id', $job->id)->whereNull('parent_id')->withCount('children')->orderByDesc('received_at')->get();
        $invoicedDocs = PoStatus::invoicedDocuments($documents);
        $docChecks = Verification::forMany('document', $documents->pluck('id'));

        // Stays and journeys linked to this job, and unlinked ones around its dates that you can link.
        $travel = TravelItem::query()->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('work_job_id', $job->id)->orWhere(fn ($q) => $q->whereNull('work_job_id')
                ->whereDate('starts_on', '<=', $job->ends_on->copy()->addDay())
                ->whereDate('starts_on', '>=', $job->starts_on->copy()->subDays(3))))
            ->orderBy('starts_on')->limit(30)->get();
        $travelChecks = Verification::forMany('travel_item', $travel->pluck('id'));

        return Inertia::render('jobs/show', [
            'job' => Present::job($job) + PoStatus::forJobs([$job])[$job->id],
            'lines' => $allocations->map(fn (Allocation $a) => [
                'id' => $a->id, 'date' => $a->transaction?->effectiveDate()->format('Y-m-d') ?? $a->manualExpense?->spent_on->format('Y-m-d'),
                'description' => $a->transaction?->description ?? $a->manualExpense?->description, 'account' => $a->transaction?->account->name ?? 'Manual',
                'kind' => $a->kind, 'category' => $a->category?->name, 'amount_pence' => $a->amount_pence, 'confirmed' => $a->confirmed_at !== null,
                'source' => $a->transaction ? 'bank' : 'manual', 'source_id' => $a->bank_transaction_id ?? $a->manual_expense_id,
            ])->sortBy('date')->values(),
            'mileage' => $mileage,
            'totals' => ['income_pence' => $income, 'costs_pence' => $spent, 'mileage_pence' => $mileagePence, 'profit_pence' => $income - $spent - $mileagePence],
            'near' => $near->map(fn ($tx) => Present::tx($tx))->values(),
            'facts' => DocumentFact::query()->with('document')->where('work_job_id', $job->id)->orderByDesc('id')->get()
                ->map(fn ($f) => $f->only(['id', 'field', 'label', 'value', 'at_time', 'quote', 'verified', 'verification_note', 'document_id']) + [
                    'on_date' => $f->on_date?->format('Y-m-d'), 'document' => $f->document?->original_name, 'received' => $f->document?->received_at?->format('Y-m-d'),
                ]),
            'documents' => $documents->map(fn ($d) => DocumentController::row($d) + ['invoiced' => $invoicedDocs[$d->id] ?? null, 'linked_by' => $d->job_linked_by, 'check' => $docChecks[$d->id] ?? null]),
            'calendar' => JobFinderController::calendarFor($job),
            'finds' => JobCalendarController::finds($job),
            'days' => $job->days()->get()->map(fn (JobDay $d) => ['id' => $d->id, 'date' => $d->on_date->format('Y-m-d'), 'kind' => $d->kind, 'start_time' => $d->start_time,
                'end_time' => $d->end_time, 'note' => $d->note, 'source' => $d->source, 'from_calendar' => (bool) $d->calendar_key])->values(),
            'day_kinds' => JobDay::KINDS,
            'gather' => ['at' => $job->gathered_at?->format('Y-m-d H:i'), 'state' => $job->gather_state, 'gmail_query' => JobGatherer::gmailQuery($job)],
            'travel' => $travel->map(fn (TravelItem $t) => [
                'id' => $t->id, 'kind' => TravelItem::KINDS[$t->kind] ?? $t->kind, 'provider' => $t->provider, 'place' => $t->kind === 'hotel' ? $t->place : trim($t->place.' → '.$t->destination, ' →'),
                'starts_on' => $t->starts_on->format('Y-m-d'), 'ends_on' => $t->ends_on?->format('Y-m-d'), 'amount_minor' => $t->amount_minor, 'currency' => $t->currency,
                'linked' => $t->work_job_id === $job->id, 'by' => $t->job_linked_by, 'decision' => $t->decision ? (TravelItem::DECISIONS[$t->decision] ?? $t->decision) : null, 'source' => $t->source,
                'check' => $travelChecks[$t->id] ?? null,
            ])->values(),
            'invoices' => Invoice::query()->where('work_job_id', $job->id)->get()->map(fn ($i) => InvoiceController::row($i)),
            'kinds' => Document::KINDS,
            'lookups' => Present::lookups(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $job = WorkJob::query()->create($this->validated($request));
        AuditLog::record('job.created', $job, null, $job->toArray());

        return back()->with('success', "Added {$job->title}.");
    }

    public function update(Request $request, WorkJob $job): RedirectResponse
    {
        $before = $job->toArray();
        $job->update($this->validated($request));
        AuditLog::record('job.updated', $job, $before, $job->fresh()->toArray());

        return back()->with('success', 'Saved.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'client_id' => 'nullable|integer|exists:clients,id',
            'new_client' => 'nullable|string|max:120',
            'starts_on' => 'required|date',
            'ends_on' => 'required|date|after_or_equal:starts_on',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:200',
            'postcode' => 'nullable|string|max:10',
            'fee' => 'nullable|string',
            'status' => 'nullable|in:booked,done,cancelled',
            'notes' => 'nullable|string|max:2000',
            'po_number' => 'nullable|string|max:100',
            'po_not_required' => 'boolean',
        ]);

        if (! empty($data['new_client'])) {
            $data['client_id'] = Client::query()->firstOrCreate(['name' => trim($data['new_client'])])->id;
        }
        $data['fee_pence'] = Money::parse($data['fee'] ?? null);
        $data['postcode'] = isset($data['postcode']) ? strtoupper(trim($data['postcode'])) : null;
        $data['po_number'] = isset($data['po_number']) && trim($data['po_number']) !== '' ? trim($data['po_number']) : null;
        $data['po_not_required'] = (bool) ($data['po_not_required'] ?? false);
        unset($data['new_client'], $data['fee']);

        return $data + ['status' => 'booked'];
    }
}
