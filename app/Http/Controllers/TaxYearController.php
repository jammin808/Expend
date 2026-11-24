<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Export\YearExporter;
use App\Domain\Tax\BillPreview;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Tax\TaxParameters;
use App\Domain\Tax\YearChecks;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaxYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('years/index', [
            'years' => TaxYear::query()->orderByDesc('label')->get()->map(fn (TaxYear $y) => [
                'label' => $y->label, 'status' => $y->status, 'filed_on' => $y->filed_on?->format('Y-m-d'),
                'hmrc_reference' => $y->hmrc_reference, 'locked' => $y->isLocked(),
                'filed_turnover_pence' => $y->filed_turnover_pence, 'filed_expenses_pence' => $y->filed_expenses_pence,
                'filed_profit_pence' => $y->filed_profit_pence, 'payments_on_account_paid_pence' => $y->payments_on_account_paid_pence,
                'deadline' => $y->period()->filingDeadline()->format('Y-m-d'), 'progress' => YearProgress::for($y->label),
                'has_rates' => TaxParameters::has($y->label),
            ]),
            'statuses' => TaxYear::STATUSES,
            'penalties' => self::penalties(),
        ]);
    }

    /** Fines and interest across all years, for the list page. */
    private static function penalties(): ?array
    {
        try {
            $all = PenaltyEstimator::all();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return ['totals' => $all['totals'], 'as_of' => $all['as_of'], 'years' => collect($all['years'])->map(fn ($y) => [
            'tax_year' => $y['tax_year'], 'penalties_pence' => $y['penalties_pence'], 'interest_pence' => $y['interest_pence'], 'upcoming_pence' => $y['upcoming_pence'],
            'unpaid_pence' => $y['unpaid_pence'], 'next' => $y['upcoming'][0] ?? null,
        ])->filter(fn ($y) => $y['penalties_pence'] || $y['interest_pence'] || $y['upcoming_pence'] || $y['unpaid_pence'])->values()];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['label' => 'required|string']);
        try {
            $label = UkTaxYear::fromLabel($data['label'])->label();
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        TaxYear::track($label);
        AuditLog::record('tax_year.tracked', 'TaxYear', null, ['label' => $label]);

        return back()->with('success', "Tracking {$label}.");
    }

    public function update(Request $request, string $label): RedirectResponse
    {
        $year = TaxYear::query()->where('label', $label)->firstOrFail();
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(TaxYear::STATUSES))],
            'filed_on' => 'nullable|date', 'hmrc_reference' => 'nullable|string|max:60',
            'filed_turnover' => 'nullable|string', 'filed_expenses' => 'nullable|string', 'filed_profit' => 'nullable|string',
            'payments_on_account_paid' => 'nullable|string', 'notes' => 'nullable|string|max:2000',
            'payment_plan_from' => 'nullable|date',
        ]);

        if ($year->isLocked() && collect(['filed_turnover', 'filed_expenses', 'filed_profit', 'payments_on_account_paid'])->contains(fn ($f) => $request->has($f))) {
            return back()->with('error', "{$label} is locked. Unlock it before changing its figures.");
        }

        $before = $year->toArray();
        $year->fill(array_filter([
            'status' => $data['status'] ?? null,
            'filed_on' => $data['filed_on'] ?? null,
            'hmrc_reference' => $data['hmrc_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));
        if ($request->has('payment_plan_from')) {
            $year->payment_plan_from = $data['payment_plan_from'] ?? null;
        }
        foreach (['filed_turnover', 'filed_expenses', 'filed_profit', 'payments_on_account_paid'] as $field) {
            if ($request->has($field)) {
                $year->{$field.'_pence'} = Money::parse($data[$field] ?? null) ?? ($field === 'payments_on_account_paid' ? 0 : null);
            }
        }
        $year->save();
        AuditLog::record('tax_year.updated', $year, $before, $year->fresh()->toArray());

        return back()->with('success', 'Saved.');
    }

    public function lock(Request $request, string $label): RedirectResponse
    {
        $year = TaxYear::query()->where('label', $label)->firstOrFail();
        if ($year->isLocked()) {
            $request->validate(['reason' => 'required|string|min:3|max:250'], ['reason.required' => 'Say why you are unlocking this year.']);
            $year->update(['locked_at' => null, 'status' => $year->status === 'filed' ? 'amending' : $year->status]);
            AuditLog::record('tax_year.unlocked', $year, null, null, $request->string('reason'));

            return back()->with('success', "{$label} unlocked.");
        }

        $year->update(['locked_at' => now()]);
        AuditLog::record('tax_year.locked', $year);

        return back()->with('success', "{$label} locked. Nothing in it can change without unlocking.");
    }

    /** The catch-up page for one year. */
    public function show(Request $request, string $label): Response
    {
        $period = UkTaxYear::fromLabel($label);
        $year = TaxYear::track($period->label());
        $summary = ReturnSummary::for($label);
        $estimate = TaxEstimator::estimate($label, $summary['net_profit_pence']);

        $groups = BankTransaction::query()->where('tax_year', $label)->whereIn('review_status', ['unreviewed', 'suggested'])
            ->selectRaw("merchant_key, COUNT(*) as n, SUM(ABS(amount_pence)) as value, SUM(CASE WHEN amount_pence > 0 THEN 1 ELSE 0 END) as money_in, SUM(CASE WHEN review_status = 'suggested' THEN 1 ELSE 0 END) as suggested, MIN(description) as sample")
            ->groupBy('merchant_key')->orderByDesc('value')->limit(60)->get()
            ->map(function ($g) use ($label) {
                $suggestion = null;
                if ((int) $g->suggested === (int) $g->n) {
                    $kinds = Allocation::query()->whereIn('bank_transaction_id', BankTransaction::query()->where('tax_year', $label)->where('merchant_key', $g->merchant_key)->where('review_status', 'suggested')->select('id'))
                        ->distinct()->pluck('kind');
                    $suggestion = $kinds->count() === 1 ? (Allocation::KINDS[$kinds->first()] ?? null) : 'Mixed suggestions';
                }

                return ['merchant_key' => $g->merchant_key, 'count' => (int) $g->n, 'value_pence' => (int) $g->value, 'money_in' => (int) $g->money_in, 'sample' => $g->sample, 'suggested' => (int) $g->suggested, 'suggestion' => $suggestion];
            });

        return Inertia::render('years/show', [
            'year' => [
                'label' => $year->label, 'status' => $year->status, 'locked' => $year->isLocked(),
                'starts_on' => $period->startsOn()->format('Y-m-d'), 'ends_on' => $period->endsOn()->format('Y-m-d'),
                'deadline' => $period->filingDeadline()->format('Y-m-d'),
                // On time until the end of the deadline day; a return filed (or filed and being amended) isn't overdue.
                'overdue' => ! in_array($year->status, ['filed', 'amending'], true) && today()->gt($period->filingDeadline()),
                'payments_on_account_paid_pence' => $year->payments_on_account_paid_pence,
            ],
            'progress' => YearProgress::for($label),
            'coverage' => YearChecks::coverage($label),
            'groups' => $groups,
            'summary' => $summary,
            'estimate' => $estimate,
            'bill' => BillPreview::for($label, $estimate),
            'checks' => YearChecks::checks($label, $summary),
            'lookups' => Present::lookups(),
            'penalties' => rescue(fn () => PenaltyEstimator::forYear($label), null),
        ]);
    }

    public function export(string $label): BinaryFileResponse
    {
        $label = UkTaxYear::fromLabel($label)->label();
        $path = storage_path('app/private/exports');
        @mkdir($path, 0700, true);
        $file = "{$path}/expense-ledger-{$label}.xlsx";
        YearExporter::write($label, $file);
        AuditLog::record('tax_year.exported', 'TaxYear', null, ['label' => $label]);

        return response()->download($file)->deleteFileAfterSend();
    }
}
