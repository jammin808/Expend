<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Jobs\PoStatus;
use App\Domain\Matching\OwnAccounts;
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
use Carbon\CarbonImmutable;
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

        // Money moving between your own accounts (a suggested transfer, your own name, a top-up, a card bill) is never
        // offered as a job's cost or fee.
        $near = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with('account')
            ->whereBetween('posted_on', [$job->starts_on->copy()->subDays(3), $job->ends_on->copy()->addDays(5)])
            ->whereIn('review_status', ['unreviewed', 'suggested'])->orderBy('posted_on')->limit(60)->get();

        // The fee usually arrives later: money in from this client, or for the agreed amount, within 90 days.
        $clientWords = collect(preg_split('/\s+/', strtoupper((string) $job->client?->name)))
            ->reject(fn ($w) => strlen($w) < 3 || in_array($w, ['LTD', 'LIMITED', 'THE', 'AND', 'PLC', 'LLP'], true));
        $fees = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with('account')->where('amount_pence', '>', 0)
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
        $near = $fees->concat($near)->unique('id')->reject(fn (BankTransaction $t) => OwnAccounts::reason($t) !== null)->values();

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
            'lines' => $allocations->filter(fn (Allocation $a) => $a->transaction || $a->manualExpense)->map(fn (Allocation $a) => self::line($a))->sortBy('date')->values(),
            'mileage' => $mileage,
            'totals' => ['income_pence' => $income, 'costs_pence' => $spent, 'mileage_pence' => $mileagePence, 'profit_pence' => $income - $spent - $mileagePence],
            'near' => $near->map(fn ($tx) => Present::tx($tx))->values(),
            'facts' => DocumentFact::query()->with('document')->where('work_job_id', $job->id)->orderByDesc('id')->get()
                ->map(fn ($f) => $f->only(['id', 'field', 'label', 'value', 'at_time', 'quote', 'verified', 'verification_note', 'document_id']) + [
                    'on_date' => $f->on_date?->format('Y-m-d'), 'document' => $f->document?->original_name, 'received' => $f->document?->received_at?->format('Y-m-d'),
                ]),
            'documents' => $documents->map(fn ($d) => DocumentController::row($d) + ['invoiced' => $invoicedDocs[$d->id] ?? null, 'linked_by' => $d->job_linked_by, 'check' => $docChecks[$d->id] ?? null]),
            'calendar' => JobFinderController::calendarFor($job),
            'finds' => self::finds($job),
            // Times to the minute, as the form sends them back (MariaDB gives "09:00:00").
            'days' => $job->days()->get()->map(fn (JobDay $d) => ['id' => $d->id, 'date' => $d->on_date->format('Y-m-d'), 'kind' => $d->kind,
                'start_time' => $d->start_time ? substr((string) $d->start_time, 0, 5) : null, 'end_time' => $d->end_time ? substr((string) $d->end_time, 0, 5) : null, 'note' => $d->note, 'source' => $d->source, 'from_calendar' => (bool) $d->calendar_key])->values(),
            'day_kinds' => JobDay::KINDS,
            'gather' => ['at' => $job->gathered_at?->format('Y-m-d H:i'), 'state' => $job->gather_state, 'gmail_query' => JobGatherer::gmailQuery($job)],
            'travel' => $travel->map(fn (TravelItem $t) => [
                'id' => $t->id, 'kind' => TravelItem::KINDS[$t->kind] ?? $t->kind, 'provider' => $t->provider, 'place' => $t->kind === 'hotel' ? $t->place : trim($t->place.' → '.$t->destination, ' →'),
                'starts_on' => $t->starts_on->format('Y-m-d'), 'ends_on' => $t->ends_on?->format('Y-m-d'), 'amount_minor' => $t->amount_minor, 'currency' => $t->currency,
                'linked' => $t->work_job_id === $job->id, 'by' => $t->job_linked_by, 'decision' => $t->decision ? (TravelItem::DECISIONS[$t->decision] ?? $t->decision) : null, 'source' => $t->source,
                'check' => $travelChecks[$t->id] ?? null,
            ])->values(),
            'invoices' => InvoiceController::rows(Invoice::query()->where('work_job_id', $job->id)->get()),
            'kinds' => Document::KINDS,
            'lookups' => Present::lookups(),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * One of the job's ledger lines: the part of the payment that's the job's, with the payment's own details (when,
     * where, which account and card, the currency it was paid in and the rate), as the rest of the ledger shows them.
     */
    private static function line(Allocation $a): array
    {
        $item = $a->transaction ? Present::tx($a->transaction) : Present::manual($a->manualExpense);

        return [
            'id' => $a->id, 'date' => $item['date'], 'description' => $item['description'],
            'account' => $item['account'], 'time' => $item['time'] ?? null, 'place' => $item['place'] ?? null,
            'card_last4' => $item['card_last4'] ?? null, 'payment_type' => $item['payment_type'] ?? null,
            'original_amount_minor' => $item['original_amount_minor'], 'original_currency' => $item['original_currency'], 'fx' => $item['fx'],
            // The whole payment, so a split shows the job's part is part of it.
            'payment_pence' => $item['amount_pence'],
            'kind' => $a->kind, 'category' => $a->category?->name, 'amount_pence' => $a->amount_pence, 'confirmed' => $a->confirmed_at !== null,
            'source' => $a->transaction ? 'bank' : 'manual', 'source_id' => $a->bank_transaction_id ?? $a->manual_expense_id,
        ];
    }

    /**
     * What the search found, as things stand now. A payment or money in that it offered may have been decided since
     * (in the side panel or the Inbox): one now on this job shows as linked, so its button takes it off again; one put
     * on another job, or decided as something the job can't take, is left out; one taken off the job since is left out.
     * A payment that still has no decision offers "Decide", and so does one found undecided that has only a suggestion
     * of a business cost since.
     */
    private static function finds(WorkJob $job): array
    {
        $finds = JobCalendarController::finds($job);
        $ids = collect($finds)->whereIn('type', ['payment', 'income'])->map(fn ($f) => (int) $f['ref'])->unique()->values();
        $txs = $ids->isEmpty() ? collect() : BankTransaction::query()->with('allocations')->whereIn('id', $ids)->get()->keyBy('id');

        // An issued or paid invoice stays with its job (void it and issue a new one to move it): no Undo on it.
        $invoiceIds = collect($finds)->where('type', 'invoice')->map(fn ($f) => (int) $f['ref'])->unique()->values();
        $issued = $invoiceIds->isEmpty() ? collect() : Invoice::query()->whereIn('id', $invoiceIds)->whereIn('status', ['issued', 'paid'])->pluck('status', 'id');

        $out = [];
        foreach ($finds as $f) {
            if (! in_array($f['type'], ['payment', 'income'], true)) {
                $out[] = $f + ['undecided' => false, 'locked' => $f['type'] === 'invoice' ? ($issued[(int) $f['ref']] ?? null) : null];

                continue;
            }
            $tx = $txs[(int) $f['ref']] ?? null;
            if (! $tx) {
                continue; // gone (a statement undone)
            }
            $a = $tx->allocations;
            $suggested = false;
            $mine = $a->filter(fn (Allocation $x) => $x->work_job_id === $job->id);
            $chosen = $a->contains(fn (Allocation $x) => $x->confirmed_at !== null || $x->is_manual);
            if ($mine->isNotEmpty()) {
                if ($f['status'] === 'possible' || $mine->every(fn (Allocation $x) => $x->confirmed_at !== null)) {
                    $f['detail'] = $mine->every(fn (Allocation $x) => $x->confirmed_at !== null)
                        ? 'On this job: confirmed'.($mine->contains('decided_by', 'you') ? ' by you' : '')
                        : 'On this job as a suggestion (confirm it in the Inbox)';
                }
                $f['status'] = 'linked';
            } elseif ($a->contains(fn (Allocation $x) => $x->work_job_id !== null) || $f['status'] === 'linked') {
                continue; // on another job now, or taken off this one since
            } elseif ($a->isNotEmpty() && $f['type'] === 'income' && ! $a->every(fn (Allocation $x) => $x->kind === 'business_income')) {
                continue; // decided as something else (a transfer, a loan)
            } elseif ($a->isNotEmpty() && $f['type'] === 'payment' && ! $a->contains('kind', 'business_expense')) {
                if ($chosen) {
                    continue; // you've decided it isn't a business cost
                }
            } elseif ($a->contains('kind', 'business_expense') && $f['type'] === 'payment' && str_contains((string) $f['detail'], 'not decided')) {
                // Found before it was decided, and it has a business-cost decision now. Only one you've made (or
                // confirmed) can go on the job with a click: a suggestion is opened and checked first, so its
                // category never becomes yours unseen.
                $f['detail'] = $chosen ? 'Decided as a business cost with no job: was it for this one?'
                    : 'Suggested as a business cost with no job (not confirmed): open it to check, and choose this job if it was for it.';
                $suggested = ! $chosen;
            }
            $f['undecided'] = $f['status'] === 'possible' && $f['type'] === 'payment' && (! $a->contains('kind', 'business_expense') || $suggested);
            $out[] = $f;
        }

        return $out;
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
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                // Every day of a job is kept (JobDays): one far longer than any job is a typing mistake.
                try {
                    $days = (int) CarbonImmutable::parse((string) $request->input('starts_on'))->diffInDays(CarbonImmutable::parse((string) $value)) + 1;
                } catch (\Throwable) {
                    return; // the date rules say what's wrong
                }
                if ($days > JobDays::MAX_DAYS) {
                    $fail('A job can cover at most '.JobDays::MAX_DAYS.' days. Make it separate jobs.');
                }
            }],
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
