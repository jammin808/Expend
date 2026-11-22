<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Tax\MileageCalculator;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Client;
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

        $jobs = WorkJob::query()->with('client')
            ->when($period, fn ($q) => $q->whereBetween('starts_on', [$period->startsOn(), $period->endsOn()]))
            ->orderByDesc('starts_on')->limit(300)->get();

        $costs = Allocation::query()->whereIn('work_job_id', $jobs->pluck('id'))->whereNotNull('confirmed_at')
            ->selectRaw('work_job_id, kind, SUM(amount_pence) as total')->groupBy('work_job_id', 'kind')->get()->groupBy('work_job_id');

        return Inertia::render('jobs/index', [
            'jobs' => $jobs->map(fn (WorkJob $j) => Present::job($j) + [
                'income_pence' => (int) ($costs[$j->id] ?? collect())->where('kind', 'business_income')->sum('total'),
                'costs_pence' => (int) -($costs[$j->id] ?? collect())->where('kind', 'business_expense')->sum('total'),
            ]),
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'filters' => ['year' => $year],
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

        return Inertia::render('jobs/show', [
            'job' => Present::job($job),
            'lines' => $allocations->map(fn (Allocation $a) => [
                'id' => $a->id, 'date' => $a->transaction?->effectiveDate()->format('Y-m-d') ?? $a->manualExpense?->spent_on->format('Y-m-d'),
                'description' => $a->transaction?->description ?? $a->manualExpense?->description, 'account' => $a->transaction?->account->name ?? 'Manual',
                'kind' => $a->kind, 'category' => $a->category?->name, 'amount_pence' => $a->amount_pence, 'confirmed' => $a->confirmed_at !== null,
                'source' => $a->transaction ? 'bank' : 'manual', 'source_id' => $a->bank_transaction_id ?? $a->manual_expense_id,
            ])->sortBy('date')->values(),
            'mileage' => $mileage,
            'totals' => ['income_pence' => $income, 'costs_pence' => $spent, 'mileage_pence' => $mileagePence, 'profit_pence' => $income - $spent - $mileagePence],
            'near' => $near->map(fn ($tx) => Present::tx($tx))->values(),
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
        ]);

        if (! empty($data['new_client'])) {
            $data['client_id'] = Client::query()->firstOrCreate(['name' => trim($data['new_client'])])->id;
        }
        $data['fee_pence'] = Money::parse($data['fee'] ?? null);
        $data['postcode'] = isset($data['postcode']) ? strtoupper(trim($data['postcode'])) : null;
        unset($data['new_client'], $data['fee']);

        return $data + ['status' => 'booked'];
    }
}
