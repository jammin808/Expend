<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Export\YearExporter;
use App\Domain\Tax\BillPreview;
use App\Domain\Tax\FilingDates;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\Submissions;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Tax\TaxParameters;
use App\Domain\Tax\TaxSavings;
use App\Domain\Tax\YearChecks;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Setting;
use App\Models\TaxSubmission;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                // No notice to file, no deadline (you tell HMRC by 5 October instead).
                'deadline' => FilingDates::deadline($y->label, $y)?->format('Y-m-d'), 'progress' => YearProgress::for($y->label),
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
            'unpaid_pence' => $y['unpaid_pence'], 'at_risk_pence' => $y['at_risk_pence'], 'next' => self::next($y['upcoming']),
        ])->filter(fn ($y) => $y['penalties_pence'] || $y['interest_pence'] || $y['upcoming_pence'] || $y['unpaid_pence'] || $y['at_risk_pence'])->values()];
    }

    /**
     * The next penalty coming: every late payment penalty arising that day together (they can fall on one date), and
     * a penalty set within a range (telling HMRC late) at the most it can be.
     */
    private static function next(array $upcoming): ?array
    {
        $first = $upcoming[0] ?? null;
        if ($first === null) {
            return null;
        }
        $late = PenaltyEstimator::nextLatePayment($upcoming);
        if ($late && $late['date'] === $first['date']) {
            return ['label' => implode(' and ', $late['labels']), 'date' => $late['date'], 'amount_pence' => $late['pence'], 'up_to' => false];
        }

        return ['label' => $first['label'], 'date' => $first['date'], 'amount_pence' => $first['range']['max_pence'] ?? $first['amount_pence'], 'up_to' => $first['range'] !== null];
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
        // A plan can't end, or be broken, before it was asked for (the date sent now, or the one saved).
        $askedOn = $request->filled('plan_requested_on') ? 'plan_requested_on' : ($request->has('plan_requested_on') ? null : $year->plan_requested_on?->format('Y-m-d'));
        $afterAsked = $askedOn ? '|after_or_equal:'.$askedOn : '';
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(TaxYear::STATUSES))],
            'filed_on' => 'nullable|date', 'hmrc_reference' => 'nullable|string|max:60',
            'filed_turnover' => 'nullable|string', 'filed_expenses' => 'nullable|string', 'filed_profit' => 'nullable|string',
            'payments_on_account_paid' => 'nullable|string', 'notes' => 'nullable|string|max:2000',
            // A payment plan: the day you asked HMRC is what protects you, if they agree and you keep to it.
            'plan_requested_on' => 'nullable|date|after:'.$year->period()->endsOn()->format('Y-m-d').'|before_or_equal:today',
            'plan_agreed' => 'nullable|boolean',
            'plan_ends_on' => 'nullable|date'.$afterAsked,
            'plan_broken_on' => 'nullable|date|before_or_equal:today'.$afterAsked,
            // HMRC's notice charging the penalties a broken plan held off (until then they're only at risk).
            'plan_notice_on' => 'nullable|date|before_or_equal:today'.$afterAsked,
            'plan_amount' => 'nullable|string|max:20',
            // An amended return: when HMRC got it, and the tax on the original.
            'amended_on' => 'nullable|date|after:'.$year->period()->endsOn()->format('Y-m-d').'|before_or_equal:today',
            'original_liability' => 'nullable|string|max:20',
            'determination_on' => 'nullable|date|after:'.$year->period()->endsOn()->format('Y-m-d').'|before_or_equal:today',
            // What HMRC's letters say: they decide the filing date and which penalties can apply.
            'notice_to_file' => ['nullable', Rule::in(array_keys(TaxYear::NOTICES))],
            'notice_date' => 'nullable|date|after:'.$year->period()->endsOn()->format('Y-m-d'),
            'filed_method' => ['nullable', Rule::in(array_keys(TaxYear::FILED_METHODS))],
            'filing_date_override' => 'nullable|date|after:'.$year->period()->endsOn()->format('Y-m-d'),
            'deferred_to' => 'nullable|date|after:'.$year->period()->endsOn()->format('Y-m-d'),
            'told_hmrc_by_5_october' => 'nullable|boolean',
        ], [
            'hmrc_reference.max' => 'The HMRC reference can’t be longer than 60 characters.',
            'notice_date.after' => 'A notice to file for '.$label.' comes after the year ends ('.$year->period()->endsOn()->format('j F Y').').',
            'filing_date_override.after' => 'The deadline on HMRC’s notice is after the year ends.',
            'deferred_to.after' => 'A deferred deadline is after the year ends.',
            'plan_requested_on.after' => 'A payment plan for '.$label.'’s tax is asked for after the year ends.',
            'plan_requested_on.before_or_equal' => 'The date you asked HMRC for a payment plan can’t be in the future.',
            'plan_ends_on.after_or_equal' => 'The plan can’t end before you asked for it.',
            'plan_broken_on.after_or_equal' => 'The plan can’t be broken before you asked for it.',
            'plan_broken_on.before_or_equal' => 'The date the plan was broken can’t be in the future.',
            'plan_notice_on.after_or_equal' => 'HMRC’s notice can’t be before you asked for the plan.',
            'plan_notice_on.before_or_equal' => 'The date of HMRC’s notice can’t be in the future.',
            'amended_on.after' => 'An amended return for '.$label.' is sent after the year ends.',
            'amended_on.before_or_equal' => 'The date HMRC got your amendment can’t be in the future.',
            'determination_on.after' => 'HMRC make a determination after the year ends.',
            'determination_on.before_or_equal' => 'The date of HMRC’s determination can’t be in the future.',
        ]);
        // Amounts in pounds: refuse anything that isn't one, rather than guess.
        $amounts = [];
        foreach (['plan_amount', 'original_liability'] as $field) {
            if ($request->has($field)) {
                try {
                    $pence = Money::parse($data[$field] ?? null);
                } catch (\InvalidArgumentException $e) {
                    return back()->with('error', $e->getMessage());
                }
                if ($pence !== null && $pence < 0) {
                    return back()->with('error', 'Enter the amount without a minus sign.');
                }
                $amounts[$field.'_pence'] = $pence;
            }
        }

        // The return's figures (a loss can be below zero). A locked year's can't change, but the Edit form sends them
        // back as they are along with the status, filed date and reference: only a figure that differs is refused.
        $locked = "{$label} is locked. Unlock it before changing its figures.";
        $figures = [];
        foreach (['filed_turnover', 'filed_expenses', 'filed_profit', 'payments_on_account_paid'] as $field) {
            if ($request->has($field)) {
                try {
                    $figures[$field.'_pence'] = Money::parse($data[$field] ?? null) ?? ($field === 'payments_on_account_paid' ? 0 : null);
                } catch (\InvalidArgumentException $e) {
                    return back()->with('error', $year->isLocked() ? $locked : $e->getMessage());
                }
            }
        }
        if ($year->isLocked() && collect($figures)->contains(fn ($pence, $column) => $pence !== $year->{$column})) {
            return back()->with('error', $locked);
        }

        $before = $year->toArray();
        $year->fill(array_filter([
            'status' => $data['status'] ?? null,
            'filed_on' => $data['filed_on'] ?? null,
            'hmrc_reference' => $data['hmrc_reference'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($v) => $v !== null));
        // Each of these can be cleared back to "don't know", so a field sent empty is saved as empty.
        foreach (['notice_to_file', 'notice_date', 'filed_method', 'filing_date_override', 'deferred_to', 'told_hmrc_by_5_october',
            'plan_requested_on', 'plan_agreed', 'plan_ends_on', 'plan_broken_on', 'plan_notice_on', 'amended_on', 'determination_on'] as $field) {
            if ($request->has($field)) {
                $year->{$field} = $data[$field] ?? null;
            }
        }
        foreach ($amounts as $column => $pence) {
            $year->{$column} = $pence;
        }
        if ($year->notice_to_file === 'not_given') {
            $year->notice_date = null; // no notice, no notice date
        }
        if ($year->plan_requested_on === null) {
            // No request, no plan.
            $year->fill(['plan_agreed' => null, 'plan_ends_on' => null, 'plan_broken_on' => null, 'plan_notice_on' => null, 'plan_amount_pence' => null]);
        }
        foreach ($figures as $column => $pence) {
            $year->{$column} = $pence;
        }
        $year->save();
        AuditLog::record('tax_year.updated', $year, $before, $year->fresh()->toArray());
        // The return's row in the submissions record follows the year's filing facts.
        Submissions::sync($year->label);

        return back()->with('success', 'Saved.');
    }

    /**
     * Record a submission late submission points are counted on: the day a quarterly update was sent, whether HMRC
     * accepted a reasonable excuse for a late one, and a note. The return's date is the year's filed date, so it's
     * changed there, never here.
     */
    public function submission(Request $request, string $label, TaxSubmission $submission): RedirectResponse
    {
        abort_unless($submission->tax_year === $label, 404);
        if ($submission->kind === 'annual' && $request->has('submitted_on')) {
            return back()->with('error', 'The return’s date is the date it was filed: set it with the year’s status on the Tax years page.');
        }
        $data = $request->validate([
            'submitted_on' => 'nullable|date|after_or_equal:'.$submission->period_start->format('Y-m-d').'|before_or_equal:today',
            'excused' => 'nullable|boolean',
            'note' => 'nullable|string|max:500',
        ], [
            'submitted_on.after_or_equal' => 'An update for '.$submission->period_start->format('j F Y').' onwards can’t have been sent before then.',
            'submitted_on.before_or_equal' => 'The date it was sent can’t be in the future.',
        ]);
        $before = $submission->toArray();
        foreach (['submitted_on', 'note'] as $field) {
            if ($request->has($field)) {
                $submission->{$field} = $data[$field] ?? null;
            }
        }
        if ($request->has('excused')) {
            $submission->excused = (bool) ($data['excused'] ?? false);
        }
        $submission->save();
        AuditLog::record('tax_submission.updated', $submission, $before, $submission->fresh()->toArray());

        return back()->with('success', 'Saved.');
    }

    /**
     * Lock a year, or unlock it with a reason. Locking keeps a snapshot in the tax_year.locked event (section 4.6:
     * YearChecks::lockSnapshot): the return's figures, the sha256 of a spreadsheet exported now (kept, so it can be
     * checked and downloaded as it was), the open findings and the checks before filing. With a check failing, locking
     * needs a reason, kept with the event. Unlocking is as it was.
     */
    public function lock(Request $request, string $label): RedirectResponse
    {
        $year = TaxYear::query()->where('label', $label)->firstOrFail();
        if ($year->isLocked()) {
            $request->validate(['reason' => 'required|string|min:3|max:250'], [
                'reason.required' => 'Say why you are unlocking this year.',
                'reason.min' => 'Say why you are unlocking this year in a few more words (3 characters at least).',
                'reason.max' => 'The reason is too long (250 characters at most).',
            ]);
            $year->update(['locked_at' => null, 'status' => $year->status === 'filed' ? 'amending' : $year->status]);
            AuditLog::record('tax_year.unlocked', $year, null, null, $request->string('reason'));

            return back()->with('success', "{$label} unlocked.");
        }

        $request->validate(['reason' => 'nullable|string|min:3|max:250']);
        $reason = $request->filled('reason') ? trim((string) $request->string('reason')) : null;
        $checks = YearChecks::checks($label, ReturnSummary::for($label));
        if (YearChecks::failing($checks) !== [] && $reason === null) {
            $names = collect($checks)->whereIn('key', YearChecks::failing($checks))->pluck('label')->map(fn ($l) => mb_strtolower($l))->implode('; ');
            throw ValidationException::withMessages(['reason' => 'Some checks before filing are failing ('.$names.'). Say why you’re locking it anyway: it’s kept with the lock.']);
        }
        $taken = YearChecks::lockSnapshot($label);
        DB::transaction(function () use ($year, $taken, $reason) {
            $year->update(['locked_at' => now()]);
            AuditLog::record('tax_year.locked', $year, null, ['snapshot' => $taken['snapshot']] + ($taken['failing'] !== [] ? ['failing' => $taken['failing']] : []), $reason);
        });

        return back()->with('success', "{$label} locked. Nothing in it can change without unlocking. Its figures and a copy of the spreadsheet were kept with the lock.");
    }

    /** The catch-up page for one year. */
    public function show(Request $request, string $label): Response
    {
        $period = UkTaxYear::fromLabel($label);
        $year = TaxYear::track($period->label());
        // Rows to record each submission against (the return's kept in step with the year; quarterly updates).
        Submissions::sync($period->label());
        $dates = FilingDates::for($period->label(), $year);
        $deadline = $dates['filing_date'];
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

        // The fines and the savings review each work out every year's penalties: read the settings once for both.
        [$penalties, $savings] = Setting::remembering(fn () => [rescue(fn () => PenaltyEstimator::forYear($label), null), rescue(fn () => TaxSavings::review($label), null)]);

        return Inertia::render('years/show', [
            'year' => [
                'label' => $year->label, 'status' => $year->status, 'locked' => $year->isLocked(),
                'starts_on' => $period->startsOn()->format('Y-m-d'), 'ends_on' => $period->endsOn()->format('Y-m-d'),
                'deadline' => $deadline?->format('Y-m-d'),
                'notify_by' => $dates['notify_by']->format('Y-m-d'),
                // On time until the end of the deadline day; a return filed (or filed and being amended) isn't overdue.
                'overdue' => $deadline !== null && ! in_array($year->status, ['filed', 'amending'], true) && today()->gt($deadline),
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
            'penalties' => $penalties,
            'savings' => $savings,
            // What was kept when the year was last locked, and (when asked: "Check it") whether it still holds.
            'lock' => YearChecks::lockShown($year),
            'lock_check' => Inertia::optional(fn () => YearChecks::verifyLock($year)),
        ]);
    }

    /** The year's spreadsheet, made now; ?as_locked=1 gives the one kept when the year was last locked, as it was. */
    public function export(Request $request, string $label): BinaryFileResponse
    {
        $label = UkTaxYear::fromLabel($label)->label();
        if ($request->boolean('as_locked')) {
            $year = TaxYear::query()->where('label', $label)->firstOrFail();
            $kept = YearChecks::lockExport($year);
            abort_if($kept === null, 404, 'No spreadsheet was kept for this year.');
            AuditLog::record('tax_year.exported', 'TaxYear', null, ['label' => $label, 'as_locked' => true]);

            return response()->download($kept, 'expense-ledger-'.$label.'-as-locked.xlsx');
        }
        $path = storage_path('app/private/exports');
        @mkdir($path, 0700, true);
        $file = "{$path}/expense-ledger-{$label}.xlsx";
        YearExporter::write($label, $file);
        AuditLog::record('tax_year.exported', 'TaxYear', null, ['label' => $label]);

        return response()->download($file)->deleteFileAfterSend();
    }
}
