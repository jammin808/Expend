<?php

namespace App\Http\Controllers;

use App\Domain\Tax\ReturnSummary;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        if (! Account::query()->exists() && ! TaxYear::query()->exists()) {
            return to_route('setup');
        }

        $years = TaxYear::query()->orderBy('label')->get()->map(function (TaxYear $year) {
            $summary = ReturnSummary::for($year->label);
            $progress = YearProgress::for($year->label);
            // A return you filed and unlocked to amend isn't late: it can be amended until a year after the deadline,
            // so that's its date, and once that day has gone there's nothing left to send for it.
            $amending = $year->status === 'amending';
            $deadline = $amending ? $year->period()->filingDeadline()->addYear() : $year->period()->filingDeadline();

            return [
                'label' => $year->label,
                'status' => $year->status,
                'deadline' => $deadline->format('Y-m-d'),
                // On time until the end of the deadline day.
                'overdue' => ! $amending && $year->status !== 'filed' && today()->gt($deadline),
                'amending' => $amending,
                'amend_open' => $amending && today()->lte($deadline),
                'progress' => $progress,
                'turnover_pence' => $summary['turnover_pence'],
                'net_profit_pence' => $summary['net_profit_pence'],
            ];
        });

        $current = UkTaxYear::current();
        $mtd = collect(config('tax.mtd_thresholds'))->map(function (array $t) {
            $turnover = ReturnSummary::for($t['based_on'])['turnover_pence'];

            return $t + ['turnover_pence' => $turnover, 'applies' => $turnover > $t['turnover_over']];
        })->values();

        $twelveMonths = BankTransaction::query()
            ->where('posted_on', '>=', now()->subYear())
            ->whereHas('allocations', fn ($q) => $q->where('kind', 'business_income')->whereNotNull('confirmed_at'))
            ->with(['allocations' => fn ($q) => $q->where('kind', 'business_income')])->get()
            ->sum(fn ($tx) => $tx->allocations->sum('amount_pence'));

        return Inertia::render('dashboard', [
            'years' => $years,
            'currentYear' => $current->label(),
            'mtd' => $mtd,
            'vat' => ['rolling_turnover_pence' => $twelveMonths, 'threshold_pence' => 9_000_000],
            'counts' => [
                'accounts' => Account::query()->count(),
                'transactions' => BankTransaction::query()->count(),
                'to_review' => BankTransaction::query()->whereIn('review_status', ['unreviewed', 'suggested'])->count(),
                // Only jobs with no PO number typed and not marked "no PO needed" can be missing one.
                'jobs_without_po' => TodoList::withoutPo(WorkJob::query()->where('status', '!=', 'cancelled')->where('po_not_required', false)
                    ->whereRaw("TRIM(COALESCE(po_number, '')) = ''")->get())->count(),
            ],
            'twoFactor' => request()->user()->hasTwoFactor(),
            'todo' => (function () {
                $list = TodoList::cached(3);

                return ['total' => $list['total'], 'items' => array_slice($list['items'], 0, 6)];
            })(),
        ]);
    }
}
