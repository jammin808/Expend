<?php

namespace App\Http\Controllers;

use App\Domain\Ai\AiUsage;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\AiConfig;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailScanner;
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
            'gmail' => [
                'configured' => GmailClient::configured(), 'connected' => GmailClient::connected(), 'account' => Setting::get('gmail_account'),
                'client_id' => Setting::get('google_client_id'), 'redirect_uri' => GmailClient::redirectUri(), 'last_result' => Setting::get('gmail_last_result'),
                'searches' => collect(GmailScanner::SEARCHES)->map(fn ($s, $key) => [
                    'key' => $key, 'label' => $s['label'], 'query' => GmailScanner::query($key), 'default' => $s['query'],
                ])->values(),
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
        $data = $request->validate(['token' => 'required|string|max:1000']);
        // Accept the whole BotFather message pasted in: the token is the "digits:letters" part.
        if (! preg_match('/\b\d{5,}:[A-Za-z0-9_-]{30,}\b/', $data['token'], $m)) {
            return back()->with('error', 'That doesn’t look like a bot token. In @BotFather send /mybots, choose your bot, then API Token, and copy the line that looks like 123456789:AA….');
        }
        Setting::put('telegram_bot_token', $m[0]);
        Setting::put('telegram_chat_id', null);
        $result = Telegram::connect(url('/hooks/telegram'));
        AuditLog::record('settings.telegram_connected', 'Settings', null, ['ok' => $result['ok'] ?? false]);

        if ($result['ok'] ?? false) {
            return back()->with('success', 'Bot connected. Now send it the pairing code shown below.');
        }
        // Don't keep a token Telegram refused, or the page would look connected when it isn't.
        foreach (['telegram_bot_token', 'telegram_webhook_secret', 'telegram_pairing_code'] as $key) {
            Setting::put($key, null);
        }

        return back()->with('error', 'Telegram refused the token ('.($result['description'] ?? 'no answer').'). In @BotFather send /mybots, choose your bot, then API Token, and paste that token here.');
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

    public function updateGmail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'string', 'max:300', 'regex:/^[\w.-]+\.apps\.googleusercontent\.com$/'],
            'client_secret' => 'nullable|string|max:300',
            'queries' => 'array', 'queries.*' => 'nullable|string|max:2000',
        ], ['client_id.regex' => 'The client ID ends in .apps.googleusercontent.com. Copy it from the Google Cloud console.']);

        Setting::put('google_client_id', $data['client_id'] ? trim($data['client_id']) : null);
        if (! empty($data['client_secret'])) {
            Setting::put('google_client_secret', trim($data['client_secret']));
        }
        foreach (array_keys(GmailScanner::SEARCHES) as $key) {
            $query = trim((string) ($data['queries'][$key] ?? ''));
            Setting::put('gmail_query_'.$key, $query === '' || $query === GmailScanner::SEARCHES[$key]['query'] ? null : $query);
        }
        AuditLog::record('settings.gmail', 'Settings', null, ['client_id' => $data['client_id'] ?? null, 'secret_changed' => ! empty($data['client_secret'])]);

        return back()->with('success', 'Gmail settings saved.');
    }

    public function connectGmail(): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        if (! GmailClient::configured()) {
            return back()->with('error', 'Add your Google client ID and secret first.');
        }

        return Inertia::location(GmailClient::authUrl());
    }

    /**
     * Google sends you back here. This route runs without the session: the session cookie is SameSite=Strict,
     * so the browser doesn't send it on a redirect from Google, and starting a new session would sign you out.
     * The one-time state proves the request started from your own Settings page.
     */
    public function gmailCallback(Request $request): \Illuminate\Http\Response
    {
        try {
            if ($request->query('error')) {
                throw new \RuntimeException($request->query('error') === 'access_denied' ? 'You didn’t allow access on Google’s screen.' : 'Google said: '.$request->query('error'));
            }
            $account = GmailClient::complete((string) $request->query('code'), (string) $request->query('state'));
            $result = ['ok' => true, 'message' => "Gmail connected (read-only) for {$account}."];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        Setting::put('gmail_last_result', $result + ['at' => now()->toIso8601String()]);

        // A page of our own moves you on, so the next request is same-site and your session cookie is sent.
        return response()->view('gmail-return', ['result' => $result])->header('Cache-Control', 'no-store');
    }

    public function disconnectGmail(): RedirectResponse
    {
        GmailClient::disconnect();
        Setting::put('gmail_last_result', null);

        return back()->with('success', 'Gmail disconnected, and the app’s access revoked at Google.');
    }
}
