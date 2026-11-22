<?php

namespace App\Http\Controllers;

use App\Domain\Tax\ReturnSummary;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\TaxYear;
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

            return [
                'label' => $year->label,
                'status' => $year->status,
                'deadline' => $year->period()->filingDeadline()->format('Y-m-d'),
                'overdue' => $year->status !== 'filed' && $year->period()->filingDeadline()->isPast(),
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
            ],
            'twoFactor' => request()->user()->hasTwoFactor(),
        ]);
    }
}
