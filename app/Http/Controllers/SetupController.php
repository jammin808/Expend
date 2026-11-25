<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Models\Account;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\Vehicle;
use App\Support\Money;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetupController extends Controller
{
    public function show(): Response
    {
        $current = UkTaxYear::current();
        $choices = [];
        for ($y = $current; count($choices) < 6; $y = $y->previous()) {
            $choices[] = $y->label();
        }

        return Inertia::render('setup', [
            'yearChoices' => $choices,
            'tracked' => TaxYear::query()->pluck('label'),
            'accounts' => Account::query()->get(['id', 'name', 'kind', 'provider']),
            'settings' => Setting::publicAll(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'accounts' => 'array',
            'accounts.*.name' => 'required|string|max:80',
            'accounts.*.kind' => 'required|in:bank,credit_card',
            'accounts.*.provider' => 'nullable|in:starling,amex,capital_one,other',
            'years' => 'required|array|min:1',
            'years.*' => 'string',
            'prior_year' => 'nullable|array',
            'prior_year.turnover' => 'nullable|string',
            'prior_year.expenses' => 'nullable|string',
            'prior_year.profit' => 'nullable|string',
            'home_postcode' => 'nullable|string|max:10',
            'scottish_taxpayer' => 'boolean',
            'vehicle' => 'nullable|array',
            'vehicle.name' => 'nullable|string|max:60',
            'vehicle.method' => 'nullable|in:simplified,actual',
        ]);

        // The last filed return's figures, read before anything is saved. Only those typed in count: a box left blank
        // keeps what the year already has.
        $figures = [];
        foreach (['turnover', 'expenses', 'profit'] as $field) {
            try {
                $pence = Money::parse($data['prior_year'][$field] ?? null);
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }
            if ($pence !== null) {
                $figures["filed_{$field}_pence"] = $pence;
            }
        }

        foreach ($data['accounts'] ?? [] as $account) {
            Account::query()->firstOrCreate(['name' => $account['name']], ['kind' => $account['kind'], 'provider' => $account['provider'] ?? 'other']);
        }

        $labels = collect($data['years'])->map(fn ($l) => UkTaxYear::fromLabel($l)->label())->sort()->values();
        foreach ($labels as $label) {
            TaxYear::track($label);
        }

        $note = '';
        if ($figures) {
            // Read back in full, so the audit's "before" shows a new year's empty figures too.
            $prior = TaxYear::track(UkTaxYear::fromLabel($labels->first())->previous()->label(), 'filed')->refresh();
            if ($prior->isLocked()) {
                // A locked year's figures can't change from here either.
                $note = " {$prior->label} is locked, so the figures from its return weren’t changed.";
            } else {
                $before = $prior->toArray();
                $prior->fill($figures);
                if ($prior->isDirty()) {
                    $prior->save();
                    AuditLog::record('tax_year.updated', $prior, $before, $prior->fresh()->toArray(), 'Figures from the last filed return, entered in setup');
                }
            }
        }

        Setting::put('home_postcode', $data['home_postcode'] ? strtoupper(trim($data['home_postcode'])) : null);
        Setting::put('scottish_taxpayer', (bool) ($data['scottish_taxpayer'] ?? false));

        if (! empty($data['vehicle']['name'])) {
            Vehicle::query()->firstOrCreate(['name' => $data['vehicle']['name']], ['type' => 'car_van', 'method' => $data['vehicle']['method'] ?? 'simplified']);
        }

        AuditLog::record('setup.completed', 'Setup', null, ['years' => $labels->all(), 'accounts' => count($data['accounts'] ?? [])]);

        return to_route('dashboard')->with('success', "All set.{$note} Import your first statement to start.");
    }
}
