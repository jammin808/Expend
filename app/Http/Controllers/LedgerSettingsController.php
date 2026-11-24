<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Calendar\CalendarSync;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Domain\Tax\TaxEstimator;
use App\Models\Allocation;
use App\Models\CalendarEvent;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Support\UkTaxYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class LedgerSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/ledger', [
            'settings' => Setting::publicAll(),
            'own' => ['names' => OwnAccounts::names(), 'saved_names' => (array) Setting::get('own_names'), 'other_accounts' => (array) Setting::get('own_other_accounts')],
            'calendar' => ['events' => CalendarEvent::query()->count(), 'synced' => Setting::get('calendar_synced_at')],
            // The years Class 2 National Insurance was compulsory, for the weeks you were self-employed in each.
            'class2_years' => TaxEstimator::class2Years(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'home_postcode' => 'nullable|string|max:10',
            'scottish_taxpayer' => 'boolean',
            'overnight_near_miles' => 'required|integer|min:0|max:500',
            'overnight_far_miles' => 'required|integer|min:1|max:1000|gte:overnight_near_miles',
            'early_start' => 'required|date_format:H:i',
            'late_finish' => 'required|date_format:H:i',
            'mileage_threshold_scope' => 'required|in:all_vehicles,per_vehicle',
            'calendar_ics_url' => 'nullable|url|max:1000',
            'clear_calendar' => 'boolean',
            'own_names' => 'nullable|string|max:1000',
            'own_other_accounts' => 'nullable|string|max:2000',
            // Making Tax Digital: the year HMRC accepted you as a volunteer for the new penalties from (2024-25 at the
            // earliest, SI 2024/440), the year an exemption runs from, and whether HMRC treat the earlier requirements as
            // never imposed (TMA 1970 Sch A1 para 14(1A) or 15(3)). They decide which penalties apply.
            'mtd_volunteer_from' => ['nullable', 'string', 'regex:/^20\d{2}-\d{2}$/'],
            'mtd_exempt_from' => ['nullable', 'string', 'regex:/^20\d{2}-\d{2}$/'],
            'mtd_exempt_never_required' => 'boolean',
            // Weeks of self-employment in each year with compulsory Class 2; empty means a full year (52).
            'class2_weeks' => 'nullable|array',
            'class2_weeks.*' => 'nullable|integer|min:0|max:'.TaxEstimator::MAX_CLASS2_WEEKS,
        ], [
            'mtd_volunteer_from.regex' => 'Choose a tax year like 2024-25.',
            'mtd_exempt_from.regex' => 'Choose a tax year like 2026-27.',
            'class2_weeks.*.integer' => 'Enter the weeks as a whole number.',
            'class2_weeks.*.min' => 'The weeks can’t be negative.',
            'class2_weeks.*.max' => 'A tax year has at most '.TaxEstimator::MAX_CLASS2_WEEKS.' weeks.',
        ]);
        if (! empty($data['mtd_volunteer_from'])) {
            try {
                $data['mtd_volunteer_from'] = UkTaxYear::fromLabel($data['mtd_volunteer_from'])->label();
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['mtd_volunteer_from' => $e->getMessage()]);
            }
            if ($data['mtd_volunteer_from'] < '2024-25') {
                return back()->withErrors(['mtd_volunteer_from' => 'Volunteering for the new penalties started with 2024-25.']);
            }
        }
        if (! empty($data['mtd_exempt_from'])) {
            try {
                $data['mtd_exempt_from'] = UkTaxYear::fromLabel($data['mtd_exempt_from'])->label();
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['mtd_exempt_from' => $e->getMessage()]);
            }
        }
        if ($request->has('mtd_exempt_from')) {
            // The year replaces the old tick, which had none; "never required" only means something with a year.
            $data['mtd_exempt_never_required'] = ! empty($data['mtd_exempt_from']) && ! empty($data['mtd_exempt_never_required']);
            Setting::put('mtd_exempt', false);
        }
        // Only the years with compulsory Class 2 are kept; an empty box goes back to a full year. Left alone if the
        // form didn't send it.
        if ($request->has('class2_weeks')) {
            $years = TaxEstimator::class2Years();
            Setting::put('class2_weeks', collect($data['class2_weeks'] ?? [])
                ->filter(fn ($w, $label) => in_array($label, $years, true) && $w !== null && $w !== '')
                ->map(fn ($w) => (int) $w)->sortKeys()->all());
        }
        $lines = fn (?string $text) => array_values(array_unique(array_filter(array_map(fn ($l) => mb_substr(trim($l), 0, 80), preg_split('/\r?\n|,/', (string) $text) ?: []))));
        $own = ['own_names' => $lines($data['own_names'] ?? null), 'own_other_accounts' => $lines($data['own_other_accounts'] ?? null)];
        $ownChanged = $own['own_names'] !== (array) Setting::get('own_names') || $own['own_other_accounts'] !== (array) Setting::get('own_other_accounts');
        foreach ($own as $key => $value) {
            Setting::put($key, $value);
        }
        unset($data['own_names'], $data['own_other_accounts']);

        foreach (['home_postcode', 'scottish_taxpayer', 'overnight_near_miles', 'overnight_far_miles', 'early_start', 'late_finish', 'mileage_threshold_scope', 'mtd_volunteer_from'] as $key) {
            Setting::put($key, $key === 'home_postcode' && $data[$key] ? strtoupper(trim($data[$key])) : $data[$key] ?? null);
        }
        if ($request->has('mtd_exempt_from')) {
            Setting::put('mtd_exempt_from', $data['mtd_exempt_from'] ?: null);
            Setting::put('mtd_exempt_never_required', $data['mtd_exempt_never_required']);
        }
        if (! empty($data['calendar_ics_url'])) {
            Setting::put('calendar_ics_url', $data['calendar_ics_url']);
        }
        if ($data['clear_calendar'] ?? false) {
            Setting::put('calendar_ics_url', null);
        }
        AuditLog::record('settings.updated', 'Settings', null, collect($data)->except('calendar_ics_url')->all() + $own);

        $found = $cleared = 0;
        if ($ownChanged) {
            // Checked again with the new names and accounts: new transfers are suggested, and earlier suggestions
            // that no longer fit go back to be decided (never one you've confirmed).
            $pairer = app(TransferPairer::class);
            $found = $pairer->pairAll() + $pairer->suggestOneSided();
            $cleared = $pairer->cleared;
        }

        return back()->with('success', 'Settings saved.'
            .($found ? " Found {$found} more ".($found === 1 ? 'transfer' : 'transfers').' between your accounts to confirm.' : '')
            .($cleared ? " {$cleared} earlier transfer ".($cleared === 1 ? 'suggestion no longer fits and is' : 'suggestions no longer fit and are').' back for you to decide.' : ''));
    }

    public function syncCalendar(CalendarSync $sync): RedirectResponse
    {
        try {
            $count = $sync->sync();
            Setting::put('calendar_synced_at', now()->format('Y-m-d H:i'));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        // The sync queues the job finder (CalendarSync::sync), which runs when it's set to look for jobs on its own.
        return back()->with('success', "Calendar synced: {$count} ".($count === 1 ? 'entry.' : 'entries.').(Setting::get('job_finder_auto')
            ? ' Looking for jobs in it now: possible jobs appear on the to-do list in a minute or two.'
            : ' To look for jobs in it, press Find jobs now in the job finder.'));
    }

    public function rules(): Response
    {
        return Inertia::render('settings/rules', [
            'rules' => MerchantRule::query()->with('category')->orderBy('merchant_key')->get()->map(fn (MerchantRule $r) => [
                'id' => $r->id, 'merchant_key' => $r->merchant_key, 'kind' => $r->kind, 'category' => $r->category?->name,
                'business_pct' => $r->business_pct, 'auto_confirm' => $r->auto_confirm,
                // A rule learned from your decisions keeps to the way the money went in them; empty means both ways.
                'direction' => $r->direction,
                'applied' => Allocation::query()->where('merchant_rule_id', $r->id)->where('decided_by', 'rule')->distinct()->count('bank_transaction_id'),
            ]),
        ]);
    }

    public function updateRule(Request $request, MerchantRule $rule): RedirectResponse
    {
        $data = $request->validate(['auto_confirm' => 'required|boolean']);
        $rule->update($data);
        AuditLog::record('rule.updated', $rule, null, $data);

        return back()->with('success', $data['auto_confirm'] ? 'This rule will now confirm on its own. Items stay marked as rule-confirmed.' : 'This rule will only suggest.');
    }

    public function destroyRule(MerchantRule $rule): RedirectResponse
    {
        AuditLog::record('rule.deleted', $rule, $rule->toArray());
        $rule->delete();

        return back()->with('success', 'Rule removed. Past decisions are unchanged.');
    }
}
