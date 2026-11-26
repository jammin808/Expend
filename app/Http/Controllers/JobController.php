<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Clients\ClientIds;
use App\Domain\Gmail\GmailClient;
use App\Domain\Income\PayerAliases;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Jobs\JobStage;
use App\Domain\Jobs\JobStatus;
use App\Domain\Jobs\PoStatus;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Todo\Fingerprints;
use App\Http\Presenters\Present;
use App\Jobs\GatherForJob;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\QuickEntry;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class JobController extends Controller
{
    public function index(Request $request): Response
    {
        $year = $request->string('year')->toString() ?: null;
        $period = $year ? UkTaxYear::fromLabel($year) : null;
        $show = in_array($request->query('show'), ['no_po', 'not_invoiced', 'done_due'], true) ? $request->query('show') : null;
        // One client's jobs ("All 12" on a client's page): the ones they pay for and the ones they're the end client of,
        // a client merged into them included.
        $client = ctype_digit((string) $request->query('client')) ? Client::query()->find(ClientIds::resolve((int) $request->query('client')), ['id', 'name']) : null;
        $clientIds = $client ? ClientIds::withMerged((int) $client->id) : null;

        $jobs = WorkJob::query()->with(['client', 'endClient', 'days'])
            ->when($period, fn ($q) => $q->whereBetween('starts_on', [$period->startsOn(), $period->endsOn()]))
            ->when($clientIds, fn ($q) => $q->where(fn ($w) => $w->whereIn('client_id', $clientIds)->orWhereIn('end_client_id', $clientIds)))
            ->orderByDesc('starts_on')->limit(300)->get();

        $costs = Allocation::query()->whereIn('work_job_id', $jobs->pluck('id'))->whereNotNull('confirmed_at')
            ->selectRaw('work_job_id, kind, SUM(amount_pence) as total')->groupBy('work_job_id', 'kind')->get()->groupBy('work_job_id');

        $po = PoStatus::forJobs($jobs);
        // Where each job is (JobStage): its chip, "Done?" when its last day has finished, and a draft's changes.
        $drafts = Invoice::query()->whereIn('work_job_id', $jobs->pluck('id'))->where('status', 'draft')->pluck('id')->all();
        $stages = JobStage::of($jobs, null, InvoiceDraftSync::changeCounts($drafts));
        $rows = $jobs->map(fn (WorkJob $j) => Present::job($j) + $po[$j->id] + [
            'income_pence' => (int) ($costs[$j->id] ?? collect())->where('kind', 'business_income')->sum('total'),
            'costs_pence' => (int) -($costs[$j->id] ?? collect())->where('kind', 'business_expense')->sum('total'),
            'stage' => $stages[$j->id] ?? null,
            'done_due' => (bool) ($stages[$j->id]['done_due'] ?? false),
        ]);

        return Inertia::render('jobs/index', [
            'jobs' => $rows->filter(fn ($r) => match ($show) {
                'no_po' => $r['missing'],
                'not_invoiced' => ! $r['invoice'] && $r['status'] !== 'cancelled',
                'done_due' => $r['done_due'],
                default => true,
            })->values(),
            'counts' => ['no_po' => $rows->where('missing', true)->count(), 'not_invoiced' => $rows->filter(fn ($r) => ! $r['invoice'] && $r['status'] !== 'cancelled')->count(),
                'possible' => JobCandidate::query()->where('status', 'open')->count(), 'done_due' => $rows->where('done_due', true)->count()],
            'clients' => Client::query()->unmerged()->orderBy('name')->get(['id', 'name']),
            'filters' => ['year' => $year, 'show' => $show, 'client' => $client?->id],
            'client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
        ]);
    }

    public function show(WorkJob $job): Response
    {
        $job->load(['client', 'endClient']);
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
        // The names the client pays you under (income design v3: payers whose payments you confirmed for them).
        $payers = $job->client_id ? array_keys(PayerAliases::keysFor((int) $job->client_id)) : [];
        $fees = OwnAccounts::scopeNotTransfers(BankTransaction::query())->with('account')->where('amount_pence', '>', 0)
            ->whereIn('review_status', ['unreviewed', 'suggested'])
            ->whereBetween('posted_on', [$job->starts_on->copy()->subDays(30), $job->ends_on->copy()->addDays(90)])
            ->where(function ($q) use ($job, $clientWords, $payers) {
                if ($job->fee_pence) {
                    $q->orWhere('amount_pence', $job->fee_pence);
                }
                foreach ($clientWords as $word) {
                    $q->orWhere('description', 'like', "%{$word}%")->orWhere('counterparty', 'like', "%{$word}%");
                }
                if ($payers !== []) {
                    $q->orWhereIn('merchant_key', $payers);
                }
            })
            ->when(! $job->fee_pence && $clientWords->isEmpty() && $payers === [], fn ($q) => $q->whereRaw('1 = 0'))
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

        $draft = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->orderByDesc('id')->first(['id']);

        return Inertia::render('jobs/show', [
            'job' => Present::job($job) + PoStatus::forJobs([$job])[$job->id],
            // Where it is (JobStage), "Draft its invoice?" for a job done with no invoice, and the days not finished yet
            // ("Did it finish early?" before Done).
            'stage' => JobStage::of([$job], null, $draft ? InvoiceDraftSync::changeCounts([(int) $draft->id]) : [])[$job->id] ?? null,
            'offers_draft' => JobStatus::offersDraft($job),
            'days_to_come' => $job->status === 'booked' ? JobStage::daysToCome($job) : [],
            // "Not yet" on "All done?" (the to-do list's job_not_yet), tied to the job as shown.
            'not_yet_fp' => $job->status === 'booked' ? Fingerprints::of('job_not_yet', (string) $job->id) : null,
            'cancel_charges' => WorkJob::CANCEL_CHARGES,
            // Its costs and where each stands (owed, on a draft, charged, not charged), and its arrangement for travel
            // and expenses (its own rows, else its client's).
            'charges' => self::charges($job),
            'terms' => ClientController::termsSheet($job),
            'lines' => $allocations->filter(fn (Allocation $a) => $a->transaction || $a->manualExpense)->map(fn (Allocation $a) => self::line($a))->sortBy('date')->values(),
            'mileage' => $mileage,
            'totals' => ['income_pence' => $income, 'costs_pence' => $spent, 'mileage_pence' => $mileagePence, 'profit_pence' => $income - $spent - $mileagePence],
            'near' => $near->map(fn ($tx) => Present::tx($tx))->values(),
            // Business-card receipts from your phone for this job whose bank line hasn't come in yet ("Add expense for
            // this job" is /quick?job=ID).
            'quick_waiting' => QuickEntry::query()->waitingOnJob($job->id)->with('category')->orderBy('spent_on')->get()
                ->map(fn (QuickEntry $e) => ['id' => $e->id, 'words' => $e->words(), 'spent_on' => $e->spent_on?->format('Y-m-d'), 'amount_minor' => $e->amount_minor,
                    'currency' => $e->currency, 'document_id' => $e->document_id, 'href' => '/quick/entries/'.$e->id])->values(),
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
            'clients' => Client::query()->unmerged()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The job's costs and work, each with where it stands and what its sheet offers.
     *
     * @return list<array<string, mixed>>
     */
    private static function charges(WorkJob $job): array
    {
        $units = [...CostUnits::forJob($job), ...CostUnits::work($job)];
        $statuses = ChargeStatus::for($units);

        return collect($units)->filter(fn (array $u) => isset($statuses[$u['ref']]))->map(function (array $u) use ($statuses) {
            $s = $statuses[$u['ref']];

            return ['ref' => $u['ref'], 'id' => CostRef::toId($u['ref']), 'subject' => $u['subject'], 'kind' => $u['kind'], 'class' => $u['class'],
                'description' => $u['description'], 'date' => $u['date'], 'currency' => $u['currency'], 'amount_minor' => $u['amount_minor'],
                'quantity' => $u['quantity'], 'status' => $s['status'], 'reason' => $s['reason'], 'note' => $s['note'], 'chip' => ChargeStatus::chip($s['status']),
                'tick' => $s['tick'], 'actions' => $u['kind'] === 'work' ? [] : ChargeStatus::actions($s)];
        })->values()->all();
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

    public function store(Request $request, JobCreator $creator, JobStatus $statuses): RedirectResponse
    {
        $data = $this->validated($request);
        // A client typed by name is found or added with the job, inside its save, so a refused job adds no client.
        foreach (['new_client' => 'client_id', 'new_end_client' => 'end_client_id'] as $name => $id) {
            if (($data[$name] ?? null) !== null) {
                unset($data[$id]);
            }
        }
        // Added already cancelled: what they still owe is kept, as Cancel keeps it.
        $charge = $data['status'] === 'cancelled' ? ($data['cancel_charge'] ?? null) : null;
        if ($charge === 'fee' && ($data['cancel_fee_pence'] ?? 0) <= 0) {
            return back()->with('error', 'Give the cancellation fee: an amount above zero.');
        }
        try {
            $job = $creator->create($data, 'you');
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($charge !== null) {
            try {
                return back()->with('success', "Added {$job->title}. ".$statuses->cancel($job, $charge, $data['cancel_fee_pence'] ?? null)['message']);
            } catch (InvalidArgumentException $e) {
                return back()->with('error', "Added {$job->title}, but what they owe wasn’t saved: ".$e->getMessage());
            }
        }
        // A job added already done gets no draft by itself (owner decision): its page offers "Draft its invoice?".
        if (JobStatus::offersDraft($job)) {
            return to_route('jobs.show', $job)->with('success', "Added {$job->title}. It’s done: draft its invoice?");
        }

        return back()->with('success', "Added {$job->title}.");
    }

    /** What a job's search for its POs, emails and payments goes by: a change to one searches again (Gmail too). */
    public const SEARCHED_BY = ['po_number', 'starts_on', 'ends_on', 'client_id', 'end_client_id', 'location', 'postcode'];

    /**
     * Save the job form. Only the fields sent change. A change of status goes through JobStatus, as the buttons do
     * (Done gets the invoice ready; back to booked clears when it was done; Cancelled keeps what they still owe). The
     * paying client can't change while the job has income in a locked year that takes its client from the job. A new
     * paying client moves the job's open drafts to them (InvoiceService::rebillDrafts).
     */
    public function update(Request $request, WorkJob $job, JobStatus $statuses, InvoiceService $invoices): RedirectResponse
    {
        $all = $this->validated($request, $job);
        // A client typed by name is found now, and only added once the save goes ahead (a refused save adds none).
        $named = array_filter(['client_id' => $all['new_client'] ?? null, 'end_client_id' => $all['new_end_client'] ?? null], fn ($n) => $n !== null);
        unset($all['new_client'], $all['new_end_client']);
        $data = $this->sent($request, $all);
        foreach ($named as $key => $name) {
            $data[$key] = JobCreator::findClient($name)?->id;
        }
        $status = $data['status'] ?? null;
        $charge = $data['cancel_charge'] ?? null;
        $fee = $data['cancel_fee_pence'] ?? null;
        unset($data['status'], $data['cancel_charge'], $data['cancel_fee_pence']);

        if (array_key_exists('rate_pence', $data) && $data['rate_pence'] !== null && $data['rate_pence'] <= 0) {
            return back()->with('error', 'Give the rate as an amount above zero, a day or an hour.');
        }
        $newClient = array_key_exists('client_id', $data) && ($data['client_id'] === null ? isset($named['client_id']) || $job->client_id !== null : (int) $data['client_id'] !== (int) $job->client_id);
        if ($newClient && ($locked = self::lockedIncomeYear($job))) {
            return back()->with('error', "This job has income in {$locked}, which is locked: unlock it (with a reason) first.");
        }
        foreach ($named as $key => $name) {
            $data[$key] ??= JobCreator::client($name, 'you', $key === 'client_id' ? 'For a job' : 'For a job, as its end client')->id;
        }
        $fromClient = $job->client_id !== null ? (int) $job->client_id : null;
        // The days agreed, when you set a fee or a day rate and none are given: the job's working days.
        $setsPrice = (array_key_exists('fee_pence', $data) && $data['fee_pence'] !== null && $data['fee_pence'] !== $job->fee_pence)
            || (array_key_exists('rate_pence', $data) && $data['rate_pence'] !== null && ($data['rate_unit'] ?? $job->rate_unit) === 'day' && $data['rate_pence'] !== $job->rate_pence);
        if ($setsPrice && ($data['days_agreed'] ?? $job->days_agreed) === null) {
            $data['days_agreed'] = JobCreator::workingDays($job) ?: null;
        }

        $before = $job->toArray();
        $job->fill($data);
        if ($job->isDirty()) {
            $job->save();
            AuditLog::record('job.updated', $job, $before, $job->fresh()->toArray());
        }
        $searchAgain = $job->wasChanged(self::SEARCHED_BY) && $job->status !== 'cancelled';
        // Its open drafts bill whoever pays for the job now.
        $rebilled = $job->wasChanged('client_id') ? count($invoices->rebillDrafts($job, $fromClient)) : 0;
        $payer = $rebilled > 0 && $job->client_id !== null ? Client::query()->whereKey(ClientIds::resolve((int) $job->client_id))->value('name') : null;
        $drafts = $rebilled === 1 ? 'Its draft' : 'Its '.$rebilled.' drafts';
        $billed = match (true) {
            $rebilled === 0 => '',
            $payer !== null => " {$drafts} now ".($rebilled === 1 ? 'bills' : 'bill')." {$payer}.",
            default => " {$drafts} ".($rebilled === 1 ? 'has' : 'have').' no client now: say who to bill on '.($rebilled === 1 ? 'it' : 'them').'.',
        };

        $said = null;
        if ($status !== null && ($status !== $job->status || ($status === 'cancelled' && ($charge !== null && ($charge !== $job->cancel_charge || $fee !== $job->cancel_fee_pence))))) {
            try {
                $said = $statuses->change($job->fresh(), $status, $charge, $fee)['message'];
            } catch (InvalidArgumentException $e) {
                return back()->with('error', ($job->wasChanged() ? 'Saved, but the status wasn’t changed: ' : '').$e->getMessage());
            }
        }
        $job->refresh();

        // A new PO number, dates, client or venue: look again for what belongs to it, once (the queued search is
        // unique per job, so saving twice in a row doesn't search twice). Other edits (the fee, notes, times) don't.
        if ($searchAgain && $job->status !== 'cancelled') {
            GatherForJob::dispatch($job->id)->afterCommit();
            // Gmail is only searched when the job has something to search it by (JobGatherer::gmailQuery): a client,
            // a PO number, a venue that isn't just "London", or a title long enough to find.
            $gmail = match (true) {
                ! GmailClient::connected() => '.',
                JobGatherer::gmailQuery($job->fresh('client')) !== null => ', Gmail included.',
                default => '. Gmail isn’t searched yet: add the client, the PO number or the venue to search it by.',
            };

            return back()->with('success', 'Saved. Looking again for what belongs to it'.$gmail.$billed.($said ? ' '.$said : ''));
        }

        return back()->with('success', 'Saved.'.$billed.($said !== null ? ' '.$said : ''));
    }

    /**
     * The first locked tax year in which this job has income that takes its client from the job (no invoice, or an
     * invoice with no client of its own): changing the job's client would move it to another client. Null when none.
     */
    private static function lockedIncomeYear(WorkJob $job): ?string
    {
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        if ($locked === []) {
            return null;
        }

        return Allocation::query()->where('work_job_id', $job->id)->where('kind', 'business_income')->whereIn('tax_year', $locked)
            ->where(fn ($q) => $q->whereNull('invoice_id')->orWhereHas('invoice', fn ($i) => $i->whereNull('client_id')))
            ->orderBy('tax_year')->value('tax_year');
    }

    /**
     * Done: the job's marked done and its invoice made ready (the open draft, or a new one), then the draft opens to
     * check. Days still to come that didn't happen ("Did it finish early?") are removed first. With stay, the page
     * you pressed it on stays.
     */
    public function done(Request $request, WorkJob $job, JobStatus $statuses): RedirectResponse
    {
        $data = $request->validate(['remove_days' => 'nullable|array', 'remove_days.*' => 'date_format:Y-m-d', 'stay' => 'boolean'],
            ['remove_days.*.date_format' => 'Pick the days that didn’t happen from the list.']);
        try {
            $r = $statuses->markDone($job, array_values($data['remove_days'] ?? []));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        if (! ($data['stay'] ?? false) && $r['invoice'] !== null && $r['invoice']->status === 'draft') {
            return to_route('invoices.show', $r['invoice'])->with('success', $r['message']);
        }

        return back()->with('success', $r['message']);
    }

    /** Back to booked (Reopen, or Undo of Done): the draft Done made goes only if you haven't touched it. */
    public function reopen(Request $request, WorkJob $job, JobStatus $statuses): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:250']);
        try {
            $r = $statuses->reopen($job, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $r['message']);
    }

    /** Cancelled, saying what they still owe: nothing, a cancellation fee, or your costs. */
    public function cancel(Request $request, WorkJob $job, JobStatus $statuses): RedirectResponse
    {
        $data = $request->validate(['charge' => ['required', Rule::in(WorkJob::CANCEL_CHARGES)], 'fee' => 'nullable|string|max:20'], [
            'charge.required' => 'Say whether they owe you anything: nothing, a cancellation fee or your costs.',
            'charge.in' => 'Say whether they owe you anything: nothing, a cancellation fee or your costs.',
        ]);
        try {
            $fee = Money::parse($data['fee'] ?? null);
        } catch (InvalidArgumentException) {
            return back()->with('error', 'Give the cancellation fee as an amount, like 150.00.');
        }
        try {
            $r = $statuses->cancel($job, $data['charge'], $fee);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $r['message']);
    }

    /** Undo of Cancelled: back to what it was before. */
    public function uncancel(WorkJob $job, JobStatus $statuses): RedirectResponse
    {
        try {
            $r = $statuses->undoCancel($job);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $r['message']);
    }

    /** "Draft its invoice?" (a job added already done, or done with no draft): the open draft, or a new one. */
    public function draft(WorkJob $job, JobStatus $statuses): RedirectResponse
    {
        try {
            $r = $statuses->draftInvoice($job);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        if ($r['invoice'] !== null) {
            return to_route('invoices.show', $r['invoice'])->with('success', $r['message']);
        }

        return back()->with('success', $r['message']);
    }

    /** The job's own arrangement for travel and expenses (where it differs from the client's usual). */
    public function terms(Request $request, WorkJob $job, ChargeTermsWriter $writer): JsonResponse|RedirectResponse
    {
        return ClientController::saveTerms($request, $job, $writer);
    }

    /**
     * Only the fields the request sent change. "Use in job" on the job sheet sends just a few, and the PO number, the
     * "No PO needed" tick, the fee and the rest stay as they were (an empty field the job form sends still clears it).
     */
    private function sent(Request $request, array $data): array
    {
        $from = ['fee_pence' => ['fee'], 'client_id' => ['client_id', 'new_client'], 'end_client_id' => ['end_client_id', 'new_end_client'],
            'rate_pence' => ['rate'], 'rate_unit' => ['rate'], 'cancel_fee_pence' => ['cancel_fee']];

        return array_filter($data, fn (string $key) => $request->hasAny($from[$key] ?? [$key]), ARRAY_FILTER_USE_KEY);
    }

    private function validated(Request $request, ?WorkJob $job = null): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'client_id' => 'nullable|integer|exists:clients,id',
            'new_client' => 'nullable|string|max:120',
            'end_client_id' => 'nullable|integer|exists:clients,id',
            'new_end_client' => 'nullable|string|max:120',
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
            'rate' => 'nullable|string|max:20',
            'rate_unit' => ['nullable', Rule::in(WorkJob::RATE_UNITS)],
            'days_agreed' => 'nullable|integer|min:1|max:'.JobDays::MAX_DAYS,
            'status' => 'nullable|in:booked,done,cancelled',
            'cancel_charge' => ['nullable', Rule::in(WorkJob::CANCEL_CHARGES)],
            'cancel_fee' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:2000',
            'po_number' => 'nullable|string|max:100',
            'po_not_required' => 'boolean',
        ], [
            // "Use in job" on the job sheet shows the first of these as a message, so they say what's wrong plainly.
            'starts_on.date' => 'The start date isn’t a date.',
            'ends_on.date' => 'The end date isn’t a date.',
            'ends_on.after_or_equal' => 'The job can’t end before it starts.',
            'start_time.date_format' => 'The start time needs hours and minutes, like 07:30.',
            'end_time.date_format' => 'The finish time needs hours and minutes, like 18:00.',
            'location.max' => 'The venue is too long (200 characters at most).',
            'postcode.max' => 'A postcode is 10 characters at most, like SE10 0DX.',
            'client_id.exists' => 'That client no longer exists: pick another.',
            'end_client_id.exists' => 'That end client no longer exists: pick another.',
            'rate_unit.in' => 'A rate is a day rate or an hourly rate.',
            'days_agreed.integer' => 'The days agreed are a whole number, like 3.',
            'days_agreed.min' => 'The days agreed are at least 1.',
            'days_agreed.max' => 'The days agreed are at most '.JobDays::MAX_DAYS.'.',
            'cancel_charge.in' => 'Say whether they owe you anything: nothing, a cancellation fee or your costs.',
        ]);

        // A client typed by name ("acme events" is the Acme Events you have) is found or added by the save itself.
        foreach (['new_client', 'new_end_client'] as $name) {
            $data[$name] = isset($data[$name]) && trim($data[$name]) !== '' ? trim($data[$name]) : null;
        }
        // Money typed the wrong way (150,50 or 250.555) is refused on its field, in words.
        $money = function (string $field, string $words) use ($data): ?int {
            try {
                return Money::parse($data[$field] ?? null);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([$field => $words]);
            }
        };
        $data['fee_pence'] = $money('fee', 'Give the fee as an amount, like 650.00.');
        // A rate in pounds, a day or an hour (its unit given, else what the job has, else a day).
        $data['rate_pence'] = $money('rate', 'Give the rate as an amount, like 250.00.');
        $data['rate_unit'] = $data['rate_pence'] === null ? null : ($data['rate_unit'] ?? $job?->rate_unit ?? 'day');
        $data['cancel_fee_pence'] = $money('cancel_fee', 'Give the cancellation fee as an amount, like 150.00.');
        $data['postcode'] = isset($data['postcode']) ? strtoupper(trim($data['postcode'])) : null;
        $data['po_number'] = isset($data['po_number']) && trim($data['po_number']) !== '' ? trim($data['po_number']) : null;
        $data['po_not_required'] = (bool) ($data['po_not_required'] ?? false);
        unset($data['fee'], $data['rate'], $data['cancel_fee']);

        return $data + ['status' => 'booked'];
    }
}
