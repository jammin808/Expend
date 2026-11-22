<?php

namespace App\Http\Controllers;

use App\Domain\Ai\AiUsage;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Telegram\Telegram;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** AI, business details for invoices, and the email and Telegram ways in. */
class IntegrationSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('settings/integrations', [
            'ai' => [
                'key_set' => AiConfig::ready(), 'key_from_env' => (bool) config('services.anthropic.key'), 'model' => AiConfig::model(), 'models' => AiConfig::MODELS,
                'effort' => AiConfig::effort(), 'cap_usd' => Setting::get('ai_monthly_cap_usd'), 'usage' => AiUsage::thisMonth(),
                'suggestions' => (bool) Setting::get('ai_suggestions'), 'extraction' => Setting::get('ai_extraction'),
            ],
            'business' => [
                'name' => Setting::get('business_name'), 'address' => Setting::get('business_address'), 'email' => Setting::get('business_email'),
                'bank' => Setting::get('business_bank_details'), 'prefix' => Setting::get('invoice_prefix'), 'terms' => Setting::get('invoice_terms_days'),
            ],
            'email' => [
                'host' => Setting::get('imap_host'), 'port' => Setting::get('imap_port'), 'username' => Setting::get('imap_username'),
                'password_set' => Setting::get('imap_password') !== null, 'allowed' => implode(', ', (array) Setting::get('allowed_senders')),
            ],
            'telegram' => [
                'token_set' => Telegram::token() !== null, 'paired' => Setting::get('telegram_chat_id') !== null,
                'pairing_code' => Setting::get('telegram_pairing_code'), 'webhook' => url('/hooks/telegram'),
            ],
        ]);
    }

    public function updateAi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => 'nullable|string|max:300', 'model' => ['required', Rule::in(array_keys(AiConfig::MODELS))],
            'effort' => 'required|in:low,medium,high,xhigh', 'cap_usd' => 'required|numeric|min:0|max:10000',
            'suggestions' => 'boolean', 'extraction' => 'required|in:off,on_demand,on',
        ]);
        if (! empty($data['api_key'])) {
            Setting::put('anthropic_api_key', trim($data['api_key']));
        }
        Setting::put('ai_model', $data['model']);
        Setting::put('ai_effort', $data['effort']);
        Setting::put('ai_monthly_cap_usd', (float) $data['cap_usd']);
        Setting::put('ai_suggestions', (bool) ($data['suggestions'] ?? false));
        Setting::put('ai_extraction', $data['extraction']);
        AuditLog::record('settings.ai', 'Settings', null, collect($data)->except('api_key')->all() + ['api_key_changed' => ! empty($data['api_key'])]);

        return back()->with('success', 'AI settings saved.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:200', 'address' => 'nullable|string|max:1000', 'email' => 'nullable|email|max:200',
            'bank' => 'nullable|string|max:500', 'prefix' => 'required|string|max:12|regex:/^[A-Za-z0-9\-\/]+$/', 'terms' => 'required|integer|min:0|max:120',
        ]);
        foreach (['name' => 'business_name', 'address' => 'business_address', 'email' => 'business_email', 'bank' => 'business_bank_details', 'prefix' => 'invoice_prefix', 'terms' => 'invoice_terms_days'] as $field => $key) {
            Setting::put($key, $data[$field] ?? null);
        }
        AuditLog::record('settings.business', 'Settings', null, collect($data)->except('bank')->all());

        return back()->with('success', 'Business details saved.');
    }

    public function updateEmail(Request $request, MailboxFetcher $fetcher): RedirectResponse
    {
        $data = $request->validate([
            'host' => 'nullable|string|max:200', 'port' => 'nullable|integer', 'username' => 'nullable|string|max:200',
            'password' => 'nullable|string|max:300', 'allowed' => 'nullable|string|max:1000', 'test' => 'boolean',
        ]);
        Setting::put('imap_host', $data['host'] ?? null);
        Setting::put('imap_port', $data['port'] ?? 993);
        Setting::put('imap_username', $data['username'] ?? null);
        if (! empty($data['password'])) {
            Setting::put('imap_password', $data['password']);
        }
        Setting::put('allowed_senders', array_values(array_filter(array_map(fn ($s) => strtolower(trim($s)), explode(',', (string) ($data['allowed'] ?? ''))))));
        AuditLog::record('settings.email', 'Settings', null, collect($data)->except('password')->all());

        if ($data['test'] ?? false) {
            try {
                $result = $fetcher->fetch(5);

                return back()->with('success', "Connected. Took in {$result['taken']}, held {$result['held']}.");
            } catch (Throwable $e) {
                return back()->with('error', 'Couldn’t connect: '.$e->getMessage());
            }
        }

        return back()->with('success', 'Email settings saved.');
    }

    public function connectTelegram(Request $request): RedirectResponse
    {
        $data = $request->validate(['token' => 'required|string|max:200']);
        Setting::put('telegram_bot_token', trim($data['token']));
        Setting::put('telegram_chat_id', null);
        $result = Telegram::connect(url('/hooks/telegram'));
        AuditLog::record('settings.telegram_connected', 'Settings', null, ['ok' => $result['ok'] ?? false]);

        return ($result['ok'] ?? false)
            ? back()->with('success', 'Bot connected. Now send it the pairing code shown below.')
            : back()->with('error', 'Telegram said: '.($result['description'] ?? 'unknown error').'. Check the token, and that the site is on HTTPS.');
    }

    public function disconnectTelegram(): RedirectResponse
    {
        Telegram::api('deleteWebhook');
        foreach (['telegram_bot_token', 'telegram_webhook_secret', 'telegram_chat_id', 'telegram_pairing_code'] as $key) {
            Setting::put($key, null);
        }
        AuditLog::record('settings.telegram_disconnected', 'Settings');

        return back()->with('success', 'Telegram disconnected.');
    }
}
