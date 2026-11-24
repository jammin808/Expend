<?php

namespace App\Http\Controllers;

use App\Domain\Tax\FilingDates;
use App\Domain\Tax\PenaltyRegime;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Setting;
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
            // A return you filed and unlocked to amend isn't late: it can be amended until 12 months after its filing
            // date (TMA 1970 s9ZA), so that's its date, and once that day has gone there's nothing left to send for it.
            $amending = $year->status === 'amending';
            // The filing date follows HMRC's notice to file; with none, the date that matters is telling HMRC by 5 October.
            $dates = FilingDates::for($year->label, $year);
            $noNotice = $dates['filing_date'] === null;
            $filingDate = $dates['filing_date'] ?? $dates['notify_by'];
            $deadline = $amending ? FilingDates::amendBy($year->label, $year) : $filingDate;

            return [
                'label' => $year->label,
                'status' => $year->status,
                'deadline' => $deadline->format('Y-m-d'),
                'no_notice' => $noNotice,
                // You said you told HMRC by 5 October, so with no notice yet there's nothing to tell them.
                'told_hmrc' => $noNotice && $dates['told_by_5_october'] === true,
                // The deadline is an estimate from the notice's date, or rests on an assumed notice: the date on it counts.
                'deadline_basis' => $amending || $noNotice ? null : ($dates['filing_basis'] === 'estimate' ? 'estimate' : ($dates['notice'] === 'assumed' ? 'assumed' : null)),
                // On time until the end of the deadline day.
                'overdue' => ! $amending && ! $noNotice && $year->status !== 'filed' && today()->gt($deadline),
                'amending' => $amending,
                'amend_open' => $amending && today()->lte($deadline),
                'progress' => $progress,
                'turnover_pence' => $summary['turnover_pence'],
                'net_profit_pence' => $summary['net_profit_pence'],
            ];
        });

        $current = UkTaxYear::current();
        $summaries = [];
        $summary = function (string $label) use (&$summaries) {
            return $summaries[$label] ??= ReturnSummary::for($label);
        };
        $mtd = Setting::remembering(fn () => collect(config('tax.mtd_thresholds'))->map(function (array $t) use ($summary) {
            $turnover = $summary($t['based_on'])['turnover_pence'];

            // As the penalties work it out: income, and an exemption from its year.
            return $t + ['turnover_pence' => $turnover, 'applies' => PenaltyRegime::mandated(UkTaxYear::fromDate($t['starts'])->label(), $summary)];
        })->values());

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
