<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostClass;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Clients\ClientFigures;
use App\Domain\Clients\ClientIds;
use App\Domain\Clients\ClientMerge;
use App\Domain\Income\PayerAliases;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobBilling;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobStage;
use App\Domain\Todo\Dismissals;
use App\Http\Presenters\Present;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The client area: your own pages about your clients (they never log in). The list, one client's page, their
 * details, merging duplicates and undoing it, and their usual arrangement for travel and expenses. Every write goes
 * through the domain (JobCreator::client, ClientMerge, ChargeTermsWriter) as you; a merge and an arrangement save are
 * tied to what the page showed, and one shown before a change is refused (409 to a sheet asking for JSON, with the
 * sheet as it is now).
 */
class ClientController extends Controller
{
    /** How many of a client's jobs each list on their page shows before "All 12". */
    private const JOBS_SHOWN = 5;

    /** Plain words for the details form. */
    private const MESSAGES = [
        'name.required' => 'Give the client a name.',
        'name.max' => 'The name is too long (120 characters at most).',
        'invoice_name.max' => 'The name on invoices is too long (200 characters at most).',
        'billing_email.email' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.',
        'billing_email.max' => 'That email address is too long.',
        'billing_address.max' => 'The address is too long (1,000 characters at most).',
        'payment_terms_days.integer' => 'Payment terms are a number of days, like 30.',
        'payment_terms_days.min' => 'Payment terms are at least 1 day.',
        'payment_terms_days.max' => 'Payment terms are at most 365 days.',
        'invoice_route.in' => 'Pick how their invoices go.',
        'notes.max' => 'The notes are too long (2,000 characters at most).',
    ];

    /**
     * The list: each client that wasn't merged, with what they owe, what's to invoice, and their recent work; filters
     * (owe, to_invoice) and search (their names, the names merged into them and the names they pay you under); and
     * the clients that may be the same. A fixed number of queries, however many clients.
     */
    public function index(Request $request): Response
    {
        $f = $request->validate(['q' => 'nullable|string|max:100', 'show' => 'nullable|in:owe,to_invoice,all', 'year' => 'nullable|regex:/^\d{4}-\d{2}$/']);
        $label = $f['year'] ?? UkTaxYear::current()->label();
        $show = $f['show'] ?? 'all';

        $clients = Client::query()->unmerged()->orderBy('name')->get(['id', 'name', 'invoice_name', 'billing_email', 'invoice_route']);
        $merged = Client::query()->whereNotNull('merged_into_id')->get(['id', 'name', 'merged_into_id'])->groupBy('merged_into_id');
        $jobs = WorkJob::query()->whereNotNull('client_id')->orderBy('ends_on')->orderBy('id')
            ->get(['id', 'title', 'client_id', 'end_client_id', 'status', 'starts_on', 'ends_on']);
        $resolved = ClientIds::resolveMany($jobs->pluck('client_id')->concat($jobs->pluck('end_client_id')));
        $payer = fn (?int $id) => $id === null ? null : ($resolved[$id] ?? $id);

        // Owed: issued invoices with something still to come, by their worked-out client.
        $invoices = Invoice::query()->where('status', 'issued')->get();
        $jobClient = $jobs->pluck('client_id', 'id');
        $invoiceClients = ClientIds::resolveMany($invoices->pluck('client_id'));
        $received = InvoicePayments::received($invoices);
        $owed = [];
        foreach ($invoices as $i) {
            $r = $received[$i->id] ?? InvoicePayments::EMPTY;
            $client = $i->client_id !== null ? ($invoiceClients[(int) $i->client_id] ?? (int) $i->client_id) : $payer(isset($jobClient[$i->work_job_id]) ? (int) $jobClient[$i->work_job_id] : null);
            if ($client === null || $r['outstanding'] <= 0) {
                continue;
            }
            $cur = InvoicePayments::currencyOf($i);
            $owed[$client]['amounts'][$cur] = ($owed[$client]['amounts'][$cur] ?? 0) + $r['outstanding'];
            // Overdue as every page counts it: once sent (an invoice not sent is "Not sent", never overdue).
            $owed[$client]['overdue'] = ($owed[$client]['overdue'] ?? 0) + (ChaseLadder::daysOverdue($i, today()) !== null ? 1 : 0);
        }

        // To invoice: finished jobs, not cancelled, with no invoice of theirs (bar void ones) and nothing billed.
        $invoiced = Invoice::query()->whereNotNull('work_job_id')->where('status', '!=', 'void')->distinct()->pluck('work_job_id')->flip();
        $finished = $jobs->filter(fn (WorkJob $j) => $j->status !== 'cancelled' && $j->ends_on->lt(today()) && ! $invoiced->has($j->id));
        $billed = JobBilling::billed($finished->pluck('id')->all());
        $year = UkTaxYear::fromLabel($label);
        $rows = $clients->map(function (Client $c) use ($jobs, $payer, $owed, $finished, $billed, $merged, $year, $resolved) {
            $mine = $jobs->filter(fn (WorkJob $j) => $payer((int) $j->client_id) === (int) $c->id);
            $toInvoice = $c->invoiceRoute() === 'none' ? 0 : $finished->filter(fn (WorkJob $j) => $payer((int) $j->client_id) === (int) $c->id && ! isset($billed[$j->id]))->count();
            $ends = $mine->pluck('end_client_id')->filter()->map(fn ($e) => $resolved[(int) $e] ?? (int) $e)->unique()->values();
            $last = $mine->filter(fn (WorkJob $j) => $j->starts_on->lte(today()))->sortBy('ends_on')->last();
            $thisYear = $mine->filter(fn (WorkJob $j) => $j->starts_on->betweenIncluded($year->startsOn(), $year->endsOn()))->count();
            $o = $owed[(int) $c->id] ?? null;

            return [
                'id' => $c->id, 'name' => $c->name, 'invoice_name' => $c->invoice_name, 'billing_email' => $c->billing_email,
                'merged_names' => ($merged[$c->id] ?? collect())->pluck('name')->values()->all(),
                'owed' => $o ? collect($o['amounts'])->map(fn ($minor, $cur) => ['currency' => $cur, 'minor' => $minor])->values()->all() : [],
                'owed_words' => $o ? 'Owes '.collect($o['amounts'])->map(fn ($minor, $cur) => Money::format($minor, $cur, false))->implode(' + ')
                    .($o['overdue'] > 0 ? ' · '.$o['overdue'].' overdue' : '') : null,
                'overdue' => (int) ($o['overdue'] ?? 0),
                'to_invoice' => $toInvoice,
                'last_job_on' => $last?->ends_on->format('Y-m-d'),
                'jobs_this_year' => $thisYear,
                'end_client_ids' => $ends->all(),
                'recent_words' => $last ? 'Last job '.$last->ends_on->format('j M').' · '.$thisYear.' '.($thisYear === 1 ? 'job' : 'jobs').' this year' : null,
            ];
        });
        $names = $clients->pluck('name', 'id');
        $rows = $rows->map(fn (array $r) => $r + ['for' => $r['end_client_ids'] === [] ? null
            : 'for '.collect($r['end_client_ids'])->map(fn ($id) => $names[$id] ?? null)->filter()->take(3)->implode(', ')]);

        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $needle = mb_strtolower($q);
            $payers = self::payersMatching($q);
            $rows = $rows->filter(fn (array $r) => isset($payers[$r['id']])
                || str_contains(mb_strtolower($r['name'].' '.$r['invoice_name'].' '.implode(' ', $r['merged_names'])), $needle));
        }
        $counts = ['owe' => $rows->filter(fn ($r) => $r['owed'] !== [])->count(), 'to_invoice' => $rows->filter(fn ($r) => $r['to_invoice'] > 0)->count(), 'all' => $rows->count()];
        $rows = $rows->filter(fn (array $r) => match ($show) {
            'owe' => $r['owed'] !== [],
            'to_invoice' => $r['to_invoice'] > 0,
            default => true,
        })->sortBy(fn (array $r) => [$r['overdue'] > 0 ? 0 : 1, $r['owed'] !== [] ? 0 : 1, $r['to_invoice'] > 0 ? 0 : 1, -strtotime($r['last_job_on'] ?? '1970-01-01'), mb_strtolower($r['name'])])->values();

        $byId = $clients->keyBy('id');

        return Inertia::render('clients/index', [
            'clients' => $rows,
            'counts' => $counts,
            'filters' => ['q' => $f['q'] ?? null, 'show' => $show, 'year' => $label],
            'figures' => collect(ClientFigures::forYear($label)['clients'])->map(fn (array $r, $id) => ['client_id' => (int) $id, 'made_pence' => $r['made_pence'], 'profit_pence' => $r['profit_pence']])->values(),
            'duplicates' => collect(ClientIds::possibleDuplicates())->filter(fn ($p) => $byId->has($p['a']) && $byId->has($p['b']))->map(fn (array $p) => [
                'a' => ['id' => $p['a'], 'name' => $byId[$p['a']]->name], 'b' => ['id' => $p['b'], 'name' => $byId[$p['b']]->name], 'why' => $p['why'],
                'text' => $byId[$p['a']]->name.' and '.$byId[$p['b']]->name.' may be the same client',
            ])->values(),
        ]);
    }

    /**
     * One client's page. A client merged into another opens the one it went into, saying so.
     */
    public function show(Request $request, Client $client): Response|RedirectResponse
    {
        if ($client->merged_into_id !== null && ($into = Client::query()->find($client->merged_into_id))) {
            return to_route('clients.show', $into)->with('success', "{$client->name} is merged into {$into->name}.");
        }
        $label = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('year')) ? (string) $request->query('year') : UkTaxYear::current()->label();
        $id = (int) $client->id;
        $ids = ClientIds::withMerged($id);

        // Owed: open invoices, with their state.
        $invoices = Invoice::query()->with(['job', 'client'])->whereIn('status', InvoicePayments::PAYABLE)
            ->where(fn ($q) => $q->whereIn('client_id', $ids)->orWhere(fn ($q) => $q->whereNull('client_id')->whereHas('job', fn ($j) => $j->whereIn('client_id', $ids))))
            ->orderBy('issue_date')->orderBy('id')->get();
        $rows = InvoiceController::rows($invoices);
        $open = $rows->filter(fn (array $r) => $r['outstanding_minor'] > 0)->values();
        $drafts = Invoice::query()->where('status', 'draft')->where(fn ($q) => $q->whereIn('client_id', $ids)->orWhereHas('job', fn ($j) => $j->whereIn('client_id', $ids)))
            ->orderBy('id')->get(['id', 'work_job_id', 'total_minor', 'currency']);

        // Jobs: as the paying client, and as the end client.
        $paying = WorkJob::query()->with(['client', 'endClient'])->whereIn('client_id', $ids)->orderByDesc('starts_on')->orderByDesc('id')->get();
        $endFor = WorkJob::query()->with(['client', 'endClient'])->whereIn('end_client_id', $ids)->orderByDesc('starts_on')->orderByDesc('id')->get();
        // Jobs finished and not billed yet, read once (with their clients) for the stages and the owed card.
        $owedJobs = JobBilling::owed($id)->load(['client', 'endClient']);
        $stages = JobStage::of($paying->take(self::JOBS_SHOWN)->concat($endFor->take(self::JOBS_SHOWN))->concat($owedJobs),
            null, InvoiceDraftSync::changeCounts($drafts->pluck('id')->all()));
        $jobRow = fn (WorkJob $j) => ['id' => $j->id, 'title' => $j->title, 'starts_on' => $j->starts_on->format('Y-m-d'), 'ends_on' => $j->ends_on->format('Y-m-d'),
            'status' => $j->status, 'client' => $j->client?->name, 'end_client' => $j->endClient?->name] + ['stage' => $stages[$j->id] ?? null];

        // Costs: every cost for them, with where it stands and what its sheet offers.
        $units = CostUnits::forClient($client);
        $statuses = ChargeStatus::for($units);
        $costs = collect($units)->filter(fn (array $u) => $u['kind'] !== 'work' && isset($statuses[$u['ref']]))->map(function (array $u) use ($statuses) {
            $s = $statuses[$u['ref']];

            return ['ref' => $u['ref'], 'id' => CostRef::toId($u['ref']), 'subject' => $u['subject'], 'kind' => $u['kind'], 'class' => $u['class'],
                'description' => $u['description'], 'date' => $u['date'], 'job_id' => $u['job_id'], 'currency' => $u['currency'],
                'amount_minor' => $u['amount_minor'], 'status' => $s['status'], 'reason' => $s['reason'], 'note' => $s['note'], 'chip' => ChargeStatus::chip($s['status']),
                'actions' => ChargeStatus::actions($s), 'remaining' => $s['remaining'], 'decision' => $s['decision'] === null ? null : ['charge' => $s['decision']['charge'], 'note' => $s['decision']['note']]];
        })->values();

        $figures = ClientFigures::forClient($client, $label);
        $endClients = $paying->pluck('endClient')->filter()->unique('id');
        $agencies = $endFor->pluck('client')->filter()->unique('id');
        $lastPaid = $rows->filter(fn (array $r) => $r['state'] === 'paid')->sortBy('issue_date')->last();

        return Inertia::render('clients/show', [
            'client' => self::details($client) + [
                'merged' => Client::query()->where('merged_into_id', $id)->orderBy('name')->get(['id', 'name'])->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'payers' => collect(PayerAliases::keysFor($id))->map(fn ($n, $key) => ['key' => (string) $key, 'payments' => $n, 'text' => 'Pays you as '.$key.' ('.$n.' '.($n === 1 ? 'payment' : 'payments').')'])->values(),
                'work_for' => $endClients->isEmpty() ? null : 'Work for '.$endClients->map(fn (Client $c) => $c->name.' ('.$paying->where('end_client_id', $c->id)->count().' '.($paying->where('end_client_id', $c->id)->count() === 1 ? 'job' : 'jobs').')')->implode(', '),
                'booked_through' => $agencies->isEmpty() ? null : 'Booked through '.$agencies->map(fn (Client $c) => $c->name.' ('.$endFor->where('client_id', $c->id)->count().' '.($endFor->where('client_id', $c->id)->count() === 1 ? 'job' : 'jobs').')')->implode(', '),
                'locked_years' => ClientMerge::lockedYears($client),
            ],
            'owed' => [
                'invoices' => $open,
                'jobs' => $owedJobs->map($jobRow)->values(),
                'drafts' => $drafts->map(fn (Invoice $i) => ['id' => $i->id, 'work_job_id' => $i->work_job_id, 'total_minor' => $i->total_minor, 'currency' => $i->currency])->values(),
                'costs_to_charge' => $figures['still_to_charge'],
                'to_claim' => ['count' => $costs->where('status', 'to_claim')->count(), 'pence' => (int) $costs->where('status', 'to_claim')->where('currency', 'GBP')->sum('amount_minor')],
                'last_paid' => $lastPaid ? ['number' => $lastPaid['number'], 'issue_date' => $lastPaid['issue_date']] : null,
            ],
            'jobs' => [
                'paying' => $paying->take(self::JOBS_SHOWN)->map($jobRow)->values(), 'paying_count' => $paying->count(),
                'end_client' => $endFor->take(self::JOBS_SHOWN)->map($jobRow)->values(), 'end_client_count' => $endFor->count(),
            ],
            'figures' => $figures,
            'years' => TaxYear::query()->orderByDesc('label')->pluck('label')->push($label)->unique()->sortDesc()->values(),
            'costs' => $costs,
            'chips' => collect(ChargeStatus::CHIPS)->map(fn (array $c, string $key) => ['key' => $key, 'label' => $c['label'], 'count' => $costs->where('chip', $key)->count()])->values(),
            'terms' => self::termsSheet($client),
            'invoice_routes' => Client::INVOICE_ROUTES,
            // The decision pickers, only when a cost's payment is opened ("Open the payment"): asked for then.
            'lookups' => Inertia::optional(fn () => Present::lookups()),
        ]);
    }

    /**
     * "New client…" (deciding a payment, the client list): the client with that name (merges resolved), or a new one.
     * A name you have already gets {existing: true} unless you said it's a different client. Answers JSON {id, name}
     * when asked for JSON, else back to the page.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120', 'invoice_name' => 'nullable|string|max:200', 'billing_email' => 'nullable|email:rfc,filter|max:200',
            'different' => 'boolean',
        ], self::MESSAGES);
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']);
        // A name you have already, or one you merged into another client (that client is the one you have).
        $existing = self::sameName($name) ?? JobCreator::findClient($name);
        if ($existing !== null && ! ($data['different'] ?? false)) {
            $answer = ['id' => $existing->id, 'name' => $existing->name, 'existing' => true, 'message' => 'You already have '.$existing->name.'.'];

            return self::wantsJson($request) ? response()->json($answer) : back()->with('success', $answer['message']);
        }
        try {
            if ($existing !== null) {
                // "It's a different client": a client of its own, whatever its name.
                $client = Client::query()->create(['name' => mb_substr($name, 0, 120)]);
                AuditLog::record('client.created', $client, null, ['name' => $client->name], 'You said it’s a different client from '.$existing->name, 'you');
            } else {
                $client = JobCreator::client($name, 'you', 'Added while deciding a payment');
            }
            // What was typed fills in only the client this form added: a client you have already keeps its details.
            $fill = array_filter(['invoice_name' => trim((string) ($data['invoice_name'] ?? '')) ?: null, 'billing_email' => $data['billing_email'] ?? null], fn ($v) => $v !== null);
            if ($fill !== [] && $client->wasRecentlyCreated) {
                $before = $client->only(array_keys($fill));
                $client->forceFill($fill)->save();
                AuditLog::record('client.updated', $client, $before, $client->only(array_keys($fill)));
            }
        } catch (InvalidArgumentException $e) {
            return self::wantsJson($request) ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }
        $answer = ['id' => $client->id, 'name' => $client->name, 'existing' => false];

        return self::wantsJson($request) ? response()->json($answer, 201) : to_route('clients.show', $client)->with('success', 'Added '.$client->name.'.');
    }

    /**
     * Their details: name, name on invoices, billing email and address, payment terms, how invoices go, notes. A name
     * another client already has (Ltd, capitals and "The" aside) is refused: merge them instead.
     */
    public function update(Request $request, Client $client): RedirectResponse
    {
        if ($client->merged_into_id !== null) {
            $into = Client::query()->find($client->merged_into_id);

            return back()->with('error', "{$client->name} is merged into ".($into?->name ?? 'another client').': change '.($into?->name ?? 'that client').' instead.');
        }
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:120', 'invoice_name' => 'nullable|string|max:200', 'billing_email' => 'nullable|email:rfc,filter|max:200',
            'billing_address' => 'nullable|string|max:1000', 'payment_terms_days' => 'nullable|integer|min:1|max:365',
            'invoice_route' => ['nullable', Rule::in(Client::INVOICE_ROUTES)], 'notes' => 'nullable|string|max:2000',
        ], self::MESSAGES);
        if (isset($data['name'])) {
            $data['name'] = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']);
            $same = self::sameName($data['name'], (int) $client->id);
            if ($same !== null) {
                return back()->with('error', "You already have {$same->name}: merge them instead of giving this one the same name.");
            }
        }
        // Only what the form sent changes; an empty box clears it.
        $fill = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, array_intersect_key($data, array_flip(array_keys($request->all()))));
        $before = $client->only(array_keys($fill));
        $client->forceFill($fill);
        if (! $client->isDirty()) {
            return back()->with('success', 'Nothing changed.');
        }
        $client->save();
        AuditLog::record('client.updated', $client, $before, $client->only(array_keys($fill)));
        $warning = ($fill['payment_terms_days'] ?? 0) > 60 ? ' Over 60 days: check it’s in your agreement.' : '';

        return back()->with('success', 'Saved.'.$warning);
    }

    /**
     * Two clients side by side, for "Compare" and "Merge into…": their details, how many jobs, invoices and payments
     * each has, what merging does, why it can't (a locked year), and the fingerprint the merge is tied to.
     */
    public function compare(Client $client, Client $other): JsonResponse
    {
        return response()->json(self::sheet($client, $other));
    }

    /** Merge this client into another (from the compare sheet, tied to both as shown). */
    public function merge(Request $request, Client $client, ClientMerge $merges): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['into_id' => 'required|integer|exists:clients,id', 'fp' => 'required|string|max:64', 'reason' => 'nullable|string|max:250'], [
            'into_id.required' => 'Pick the client to merge it into.', 'into_id.exists' => 'That client no longer exists: pick another.',
            'fp.required' => 'Open the comparison first, then merge.',
        ]);
        $into = Client::query()->findOrFail($data['into_id']);
        if (! hash_equals(ClientMerge::fingerprint($client, $into), $data['fp'])) {
            return self::changed($request, 'One of them changed since you compared them: look again. Nothing was merged.', self::sheet($client, $into));
        }
        try {
            $kept = $merges->merge($client, $into, $data['reason'] ?? null, $data['fp']);
        } catch (InvalidArgumentException $e) {
            return self::wantsJson($request) ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }
        $message = "{$client->name} is merged into {$kept->name}. Its jobs, invoices and payments count as {$kept->name}’s now. Nothing in your records was rewritten, and you can unmerge it.";

        return self::wantsJson($request) ? response()->json(['message' => $message, 'id' => $kept->id]) : to_route('clients.show', $kept)->with('success', $message);
    }

    /** Undo a merge: the client counts as itself again. */
    public function unmerge(Request $request, Client $client, ClientMerge $merges): RedirectResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:250']);
        $into = $client->merged_into_id;
        try {
            $merges->unmerge($client, $data['reason'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return ($into ? to_route('clients.show', $into) : back())->with('success', "{$client->name} is its own client again.");
    }

    /** "Not the same": the pair isn't offered as possible duplicates again. */
    public function notSame(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate(['other_id' => 'required|integer|exists:clients,id'], ['other_id.exists' => 'That client no longer exists.']);
        if ((int) $data['other_id'] === (int) $client->id) {
            return back()->with('error', 'Pick the other client.');
        }
        Dismissals::dismiss(ClientIds::dupeKey((int) $client->id, (int) $data['other_id']), null, 'Not the same client');

        return back()->with('success', 'OK: they won’t be offered as the same client again.');
    }

    /**
     * Their usual arrangement for travel and expenses: a preset, or the rows, tied to the rows as loaded (fp). A save
     * on rows that changed since is refused with the rows as they are now.
     */
    public function terms(Request $request, Client $client, ChargeTermsWriter $writer): JsonResponse|RedirectResponse
    {
        return self::saveTerms($request, $client, $writer);
    }

    /**
     * The arrangement editor's save, for a client or a job.
     *
     * @internal used by JobController::terms too
     */
    public static function saveTerms(Request $request, Client|WorkJob $owner, ChargeTermsWriter $writer): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'fp' => 'required|string|max:64',
            'preset' => ['nullable', Rule::in(ChargeTermsWriter::PRESETS)],
            'rate' => 'nullable|string|max:20', 'per' => 'nullable|in:per_job,per_day',
            'rows' => 'nullable|array', 'rows.*' => 'nullable', 'rows.*.charge' => 'nullable|string', 'rows.*.rate' => 'nullable|string|max:20', 'rows.*.currency' => 'nullable|string|size:3',
            'reason' => 'nullable|string|max:250',
        ], ['fp.required' => 'Open the arrangement first, then save it.']);
        if (! hash_equals(ChargeTermsWriter::fingerprint($owner), $data['fp'])) {
            return self::changed($request, 'This changed since you opened it: look again. Nothing was saved.', self::termsSheet($owner));
        }
        try {
            if (! empty($data['preset'])) {
                $rate = isset($data['rate']) && trim($data['rate']) !== '' ? Money::parse($data['rate']) : null;
                if (isset($data['rate']) && trim($data['rate']) !== '' && $rate === null) {
                    throw new InvalidArgumentException('Give the rate as an amount, like 0.45 or 25.00.');
                }
                $after = $writer->applyPreset($owner, $data['preset'], $rate, $data['per'] ?? 'per_job', $data['reason'] ?? null, $data['fp']);
            } else {
                $rows = [];
                foreach ((array) ($data['rows'] ?? []) as $class => $row) {
                    if ($row === null || $row === '' || (is_array($row) && ($row['charge'] ?? null) === null)) {
                        $rows[(string) $class] = null;

                        continue;
                    }
                    if (is_string($row)) {
                        $rows[(string) $class] = $row;

                        continue;
                    }
                    $rate = isset($row['rate']) && trim((string) $row['rate']) !== '' ? Money::parse((string) $row['rate']) : null;
                    if (isset($row['rate']) && trim((string) $row['rate']) !== '' && $rate === null) {
                        throw new InvalidArgumentException('Give each rate as an amount, like 0.45 or 25.00.');
                    }
                    $rows[(string) $class] = array_filter(['charge' => (string) $row['charge'], 'rate_minor' => $rate, 'currency' => $row['currency'] ?? null], fn ($v) => $v !== null);
                }
                $after = $writer->set($owner, $rows, $data['reason'] ?? null, $data['fp']);
            }
        } catch (InvalidArgumentException $e) {
            return self::wantsJson($request) ? response()->json(['message' => $e->getMessage()], 422) : back()->with('error', $e->getMessage());
        }
        $who = $owner instanceof Client ? $owner->name.'’s drafts' : 'this job’s draft';
        $message = 'Saved. Expend ticks these on '.$who.'.'.(($after['mileage']['charge'] ?? null) === 'per_mile' ? ' '.ChargeTermsWriter::PER_MILE_NOTE.'.' : '');

        return self::wantsJson($request) ? response()->json(['message' => $message, 'terms' => self::termsSheet($owner)]) : back()->with('success', $message);
    }

    /**
     * The arrangement editor's props: the owner's own rows, what applies (for a job: its own, else its client's), the
     * presets, the kinds of cost and what each may be charged, and the fingerprint a save is tied to.
     *
     * @return array<string, mixed>
     */
    public static function termsSheet(Client|WorkJob $owner): array
    {
        $rate = ChargeTermsWriter::mileageRate();

        return [
            'own' => ChargeTerms::own($owner),
            'applies' => $owner instanceof WorkJob ? ChargeTerms::forJob($owner) : ChargeTerms::forClient($owner),
            'fp' => ChargeTermsWriter::fingerprint($owner),
            'presets' => collect(ChargeTermsWriter::PRESETS)->map(fn (string $p) => ['key' => $p, 'label' => ChargeTermsWriter::label($p, $p === 'mileage' ? $rate : null)])->values(),
            'classes' => collect(CostClass::CLASSES)->map(fn (array $charges, string $class) => ['class' => $class, 'label' => CostClass::label($class), 'charges' => array_values($charges)])->values(),
            'per_mile_note' => ChargeTermsWriter::PER_MILE_NOTE,
            'mileage_rate' => $rate,
            'help' => $owner instanceof Client ? 'Expend ticks these on '.$owner->name.'’s drafts. A job can change them.' : 'Only for this job: the rest follow the client’s usual.',
        ];
    }

    /** A draft for this client with no job: their costs from earlier jobs, and their own costs you marked to recharge. */
    public function draft(Client $client, InvoiceService $invoices): RedirectResponse
    {
        try {
            $invoice = $invoices->draftForClient($client);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('invoices.show', $invoice)->with('success', $invoice->allLines()->exists()
            ? 'Here’s '.$client->name.'’s draft: check it, then issue it.' : 'Nothing is owed by '.$client->name.' to put on a draft yet: add a line, or delete the draft.');
    }

    /** @return array<string, mixed> a client's details, as the page and the compare sheet show them */
    private static function details(Client $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'invoice_name' => $c->invoice_name, 'billing_email' => $c->billing_email, 'billing_address' => $c->billing_address,
            'payment_terms_days' => $c->payment_terms_days, 'invoice_route' => $c->invoiceRoute(), 'notes' => $c->notes, 'merged_into_id' => $c->merged_into_id];
    }

    /** @return array<string, mixed> the compare sheet for merging $from into $into */
    private static function sheet(Client $from, Client $into): array
    {
        $count = function (Client $c): array {
            $ids = [(int) $c->id];

            return [
                'jobs' => WorkJob::query()->whereIn('client_id', $ids)->count(),
                'invoices' => Invoice::query()->whereIn('client_id', $ids)->where('status', '!=', 'draft')->count(),
                'payments' => (int) collect(PayerAliases::keysFor((int) $c->id))->sum(),
            ];
        };
        $from->refresh();
        $into->refresh();
        $locked = ClientMerge::lockedYears($from);
        $n = $count($from);

        return [
            'from' => self::details($from) + $n,
            'into' => self::details($into) + $count($into),
            'fp' => ClientMerge::fingerprint($from, $into),
            'can_merge' => $locked === [] && $from->id !== $into->id && $into->merged_into_id === null,
            'why_not' => $locked !== [] ? $from->name.' has records in '.implode(', ', $locked).', which '.(count($locked) === 1 ? 'is' : 'are').' locked: unlock '.(count($locked) === 1 ? 'it' : 'them').' with a reason first.' : null,
            'locked_years' => $locked,
            'what_follows' => 'Its '.$n['jobs'].' '.($n['jobs'] === 1 ? 'job' : 'jobs').', '.$n['invoices'].' '.($n['invoices'] === 1 ? 'invoice' : 'invoices').' and payments then count as '
                .$into->name.'. Nothing in your records is rewritten, and you can unmerge.',
        ];
    }

    /** Another client (not merged away, not $except) with this name, Ltd, capitals and "The" aside. */
    private static function sameName(string $name, ?int $except = null): ?Client
    {
        $key = JobCreator::clientKey($name);
        $lower = mb_strtolower(trim($name));

        return Client::query()->unmerged()->when($except !== null, fn ($q) => $q->whereKeyNot($except))->orderBy('id')->get(['id', 'name'])
            ->first(fn (Client $c) => mb_strtolower($c->name) === $lower || ($key !== '' && JobCreator::clientKey($c->name) === $key));
    }

    /**
     * The clients paying you under a name with these words in it (payers whose confirmed payments count for them), for
     * the list's search: two queries, however many clients.
     *
     * @return array<int, true> by client id (merges resolved)
     */
    private static function payersMatching(string $q): array
    {
        $keys = BankTransaction::query()->where('merchant_key', 'like', '%'.strtoupper($q).'%')->where('amount_pence', '>', 0)
            ->distinct()->limit(200)->pluck('merchant_key');
        $aliases = PayerAliases::forKeys($keys);
        $out = [];
        foreach ($keys as $key) {
            foreach (array_keys($aliases->clients($key)) as $client) {
                $out[(int) $client] = true;
            }
        }

        return $out;
    }

    /** A request from a sheet that reads JSON (not an Inertia visit). */
    private static function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->header('X-Inertia');
    }

    /**
     * It changed since the sheet was shown: nothing was done. A sheet reading JSON gets 409 with the sheet as it is now;
     * a page visit goes back, and the page shows it as it is now.
     */
    private static function changed(Request $request, string $message, array $sheet): JsonResponse|RedirectResponse
    {
        return self::wantsJson($request) ? response()->json(['message' => $message, 'changed' => true, 'sheet' => $sheet], 409) : back()->with('error', $message);
    }
}
