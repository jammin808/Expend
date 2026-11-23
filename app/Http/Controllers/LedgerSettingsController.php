<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Calendar\CalendarSync;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Models\Allocation;
use App\Models\CalendarEvent;
use App\Models\MerchantRule;
use App\Models\Setting;
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
        ]);
        $lines = fn (?string $text) => array_values(array_unique(array_filter(array_map(fn ($l) => mb_substr(trim($l), 0, 80), preg_split('/\r?\n|,/', (string) $text) ?: []))));
        $own = ['own_names' => $lines($data['own_names'] ?? null), 'own_other_accounts' => $lines($data['own_other_accounts'] ?? null)];
        $ownChanged = $own['own_names'] !== (array) Setting::get('own_names') || $own['own_other_accounts'] !== (array) Setting::get('own_other_accounts');
        foreach ($own as $key => $value) {
            Setting::put($key, $value);
        }
        unset($data['own_names'], $data['own_other_accounts']);

        foreach (['home_postcode', 'scottish_taxpayer', 'overnight_near_miles', 'overnight_far_miles', 'early_start', 'late_finish', 'mileage_threshold_scope'] as $key) {
            Setting::put($key, $key === 'home_postcode' && $data[$key] ? strtoupper(trim($data[$key])) : $data[$key] ?? null);
        }
        if (! empty($data['calendar_ics_url'])) {
            Setting::put('calendar_ics_url', $data['calendar_ics_url']);
        }
        if ($data['clear_calendar'] ?? false) {
            Setting::put('calendar_ics_url', null);
        }
        AuditLog::record('settings.updated', 'Settings', null, collect($data)->except('calendar_ics_url')->all() + $own);

        $found = 0;
        if ($ownChanged) {
            $pairer = app(TransferPairer::class);
            $found = $pairer->pairAll() + $pairer->suggestOneSided();
        }

        return back()->with('success', 'Settings saved.'.($found ? " Found {$found} more transfers between your accounts to confirm." : ''));
    }

    public function syncCalendar(CalendarSync $sync): RedirectResponse
    {
        try {
            $count = $sync->sync();
            Setting::put('calendar_synced_at', now()->format('Y-m-d H:i'));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Calendar synced: {$count} events.");
    }

    public function rules(): Response
    {
        return Inertia::render('settings/rules', [
            'rules' => MerchantRule::query()->with('category')->orderBy('merchant_key')->get()->map(fn (MerchantRule $r) => [
                'id' => $r->id, 'merchant_key' => $r->merchant_key, 'kind' => $r->kind, 'category' => $r->category?->name,
                'business_pct' => $r->business_pct, 'auto_confirm' => $r->auto_confirm,
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
