<?php

namespace App\Http\Controllers;

use App\Domain\Ai\AiUsage;
use App\Domain\Audit\AuditLog;
use App\Domain\Banks\StarlingScopeMissing;
use App\Domain\Banks\StarlingSpaces;
use App\Domain\Banks\StarlingSync;
use App\Domain\Documents\AiConfig;
use App\Domain\Gmail\GmailClient;
use App\Domain\Gmail\GmailLabeller;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Mail\MailboxFetcher;
use App\Domain\Mail\OutgoingMail;
use App\Domain\Tax\TaxPot;
use App\Domain\Telegram\Telegram;
use App\Jobs\SyncGmailLabels;
use App\Mail\TestEmail;
use App\Models\Account;
use App\Models\GmailLabel;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
                'web_research' => Setting::get('ai_web_research') !== false,
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
                'can_label' => GmailClient::canModify(), 'label' => GmailLabeller::LABEL,
                'label_on' => Setting::get('gmail_label_invoiced') !== false, 'label_status' => Setting::get('gmail_label_status'),
                'labelled' => GmailLabel::query()->count(),
                'searches' => collect(GmailScanner::SEARCHES)->map(fn ($s, $key) => [
                    'key' => $key, 'label' => $s['label'], 'query' => GmailScanner::query($key), 'default' => $s['query'],
                ])->values(),
            ],
            'mail' => [
                'method' => OutgoingMail::method() ?? 'log', 'chosen' => Setting::get('mail_method'),
                'smtp_host' => Setting::get('smtp_host') ?? 'smtp.hostinger.com', 'smtp_port' => Setting::get('smtp_port') ?? 465,
                'smtp_username' => Setting::get('smtp_username'), 'smtp_password_set' => Setting::get('smtp_password') !== null,
                'from_address' => Setting::get('mail_from_address'), 'default_from' => OutgoingMail::from(),
                'to' => request()->user()->email,
            ],
            'starling' => [
                'connected' => StarlingSync::connected(),
                'accounts' => collect((array) Setting::get('starling_accounts'))->map(fn ($a, $uid) => $a + ['uid' => $uid])->values(),
                'last' => Setting::get('starling_last_result'),
                'app_accounts' => Account::query()->orderBy('name')->get(['id', 'name']),
                // The tax pot: the Space chosen, as last read, and (only when you ask to choose one) your Spaces.
                'tax_pot' => self::taxPot(request()->boolean('spaces')),
            ],
            'telegram' => [
                'token_set' => Telegram::token() !== null, 'paired' => Setting::get('telegram_chat_id') !== null,
                'pairing_code' => Setting::get('telegram_pairing_code'), 'webhook' => url('/hooks/telegram'),
            ],
        ]);
    }

    public function connectStarling(Request $request, StarlingSync $starling): RedirectResponse
    {
        $token = trim($request->validate(['token' => 'required|string|min:20|max:4000'])['token']);
        try {
            $map = $starling->connect($token);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Starling connected: '.count($map).' '.(count($map) === 1 ? 'account' : 'accounts').'. Press Import now, or wait for tomorrow morning.');
    }

    public function linkStarling(Request $request, StarlingSync $starling): RedirectResponse
    {
        $data = $request->validate(['uid' => 'required|string|max:64', 'account_id' => 'required|integer|exists:accounts,id']);
        abort_unless(isset(((array) Setting::get('starling_accounts'))[$data['uid']]), 404);

        return back()->with('success', $starling->relink($data['uid'], Account::query()->findOrFail($data['account_id'])));
    }

    public function syncStarling(Request $request, StarlingSync $starling): RedirectResponse
    {
        $from = $request->validate(['from' => 'nullable|date|before:today|after:2015-01-01'])['from'] ?? null;
        try {
            $results = $starling->sync($from);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        $text = collect($results)->map(fn ($r) => $r['account'].': '.$r['result'])->implode(' ');

        return back()->with(collect($results)->every('ok') ? 'success' : 'error', $text ?: 'Nothing to import.');
    }

    /**
     * Choose the Starling Space that holds money for tax (or none), so the dashboard shows it against what's needed by
     * 31 January. Only read: Expend never moves money into or out of it.
     */
    public function chooseTaxPot(Request $request): RedirectResponse
    {
        $key = trim((string) $request->validate(['tax_pot' => 'present|nullable|string|max:300'])['tax_pot']);
        $before = Setting::get(TaxPot::SPACE, null);
        if ($key === '') {
            Setting::put(TaxPot::SPACE, null);
            Setting::put(TaxPot::BALANCE, null);
            AuditLog::record('settings.tax_pot', 'Settings', ['space' => $before], ['space' => null]);

            return back()->with('success', 'No Space is used as the tax pot now. The dashboard still shows what to put aside.');
        }
        try {
            $space = collect(app(StarlingSpaces::class)->all())->firstWhere('key', $key);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        if (! $space) {
            return back()->with('error', 'That Space wasn’t found at Starling. Choose it again from the list.');
        }
        $chosen = ['account_uid' => $space['account_uid'], 'kind' => $space['kind'], 'uid' => $space['uid'], 'name' => $space['name']];
        Setting::put(TaxPot::SPACE, $chosen);
        AuditLog::record('settings.tax_pot', 'Settings', ['space' => $before], ['space' => $chosen]);
        app(StarlingSpaces::class)->refresh();

        return back()->with('success', "“{$space['name']}” is your tax pot. Its balance is read at each Starling import; Expend never moves money.");
    }

    /**
     * @return array{chosen: ?array, last: ?array, spaces: ?list<array>, error: ?string, scopes: string}
     */
    private static function taxPot(bool $list): array
    {
        $spaces = null;
        $error = null;
        if ($list && StarlingSync::connected()) {
            try {
                $spaces = array_map(fn ($s) => ['key' => $s['key'], 'name' => $s['name'], 'kind' => $s['kind'], 'pence' => $s['pence'], 'currency' => $s['currency']],
                    app(StarlingSpaces::class)->all());
            } catch (StarlingScopeMissing) {
                $error = 'scope';
            } catch (Throwable $e) {
                report($e);
                $error = 'failed';
            }
        }

        return ['chosen' => Setting::get(TaxPot::SPACE, null), 'last' => Setting::get(TaxPot::BALANCE, null), 'spaces' => $spaces, 'error' => $error, 'scopes' => StarlingSpaces::SCOPES];
    }

    public function disconnectStarling(StarlingSync $starling): RedirectResponse
    {
        $starling->disconnect();

        return back()->with('success', 'Starling disconnected. Delete the token in Starling’s developer portal too.');
    }

    public function updateAi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => 'nullable|string|max:300', 'model' => ['required', Rule::in(array_keys(AiConfig::MODELS))],
            'effort' => 'required|in:low,medium,high,xhigh', 'cap_usd' => 'required|numeric|min:0|max:10000',
            'suggestions' => 'boolean', 'extraction' => 'required|in:off,on_demand,on', 'web_research' => 'boolean',
        ]);
        if (! empty($data['api_key'])) {
            Setting::put('anthropic_api_key', trim($data['api_key']));
        }
        Setting::put('ai_model', $data['model']);
        Setting::put('ai_effort', $data['effort']);
        Setting::put('ai_monthly_cap_usd', (float) $data['cap_usd']);
        Setting::put('ai_suggestions', (bool) ($data['suggestions'] ?? false));
        Setting::put('ai_extraction', $data['extraction']);
        if (array_key_exists('web_research', $data)) {
            Setting::put('ai_web_research', (bool) $data['web_research']);
        }
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
            'queries' => 'array',
            'queries.*' => ['bail', 'nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $key = substr($attribute, strlen('queries.'));
                $search = GmailScanner::SEARCHES[$key] ?? null;
                $value = trim($value);
                // The page sends every box, changed or not, so a built-in search sent back unchanged is always fine.
                if ($search !== null && $value === $search['query']) {
                    return;
                }
                if (strlen($value) > GmailScanner::MAX_QUERY_LENGTH) {
                    $fail('The “'.($search['label'] ?? $key).'” search (under Edit the Gmail searches) is too long: at most '
                        .number_format(GmailScanner::MAX_QUERY_LENGTH).' characters. Empty the box to go back to the built-in search.');
                }
            }],
            'label_invoiced' => 'boolean',
        ], ['client_id.regex' => 'The client ID ends in .apps.googleusercontent.com. Copy it from the Google Cloud console.']);

        $clientId = ($data['client_id'] ?? null) ? trim($data['client_id']) : null;
        // A secret belongs to one Google client too: a new client ID with the old secret would fail at Google's sign-in.
        if ($clientId !== null && $clientId !== Setting::get('google_client_id') && empty($data['client_secret'])) {
            throw ValidationException::withMessages([
                'client_secret' => 'A new client ID needs its own client secret: paste the secret from the same Google client, then save.',
            ]);
        }
        // A connection belongs to the Google client that made it: Google won't renew it for a different one, so the
        // old one is removed (and revoked) and the page offers Connect Gmail again.
        $switched = $clientId !== Setting::get('google_client_id') && GmailClient::connected();
        if ($switched) {
            GmailClient::disconnect();
            Setting::put('gmail_last_result', null);
        }
        Setting::put('google_client_id', $clientId);
        if (! empty($data['client_secret'])) {
            Setting::put('google_client_secret', trim($data['client_secret']));
        }
        foreach (array_keys(GmailScanner::SEARCHES) as $key) {
            $query = trim((string) ($data['queries'][$key] ?? ''));
            Setting::put('gmail_query_'.$key, $query === '' || $query === GmailScanner::SEARCHES[$key]['query'] ? null : $query);
        }
        Setting::put('gmail_label_invoiced', (bool) ($data['label_invoiced'] ?? true));
        AuditLog::record('settings.gmail', 'Settings', null, ['client_id' => $data['client_id'] ?? null, 'secret_changed' => ! empty($data['client_secret']), 'label_invoiced' => (bool) ($data['label_invoiced'] ?? true)]);

        return back()->with('success', match (true) {
            $switched && $clientId === null => 'Gmail settings saved. With no client ID, the Gmail connection has been removed.',
            $switched => 'Gmail settings saved. The old connection was made with a different Google client, so it has been removed: press Connect Gmail.',
            default => 'Gmail settings saved.',
        });
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
        $verifier = GmailClient::acceptState((string) $request->query('state'));
        if ($verifier === null) {
            // Not a sign-in this app started: show a message, but record nothing.
            return response()->view('gmail-return', ['result' => ['ok' => false, 'message' => 'That sign-in link isn’t valid or has expired. Start again from Settings › Integrations.']])
                ->header('Cache-Control', 'no-store');
        }

        try {
            if ($error = $request->query('error')) {
                throw new \RuntimeException($error === 'access_denied'
                    ? 'You didn’t allow access on Google’s screen.'
                    : 'Google returned an error ('.preg_replace('/[^a-z_]/', '', strtolower(substr((string) $error, 0, 40))).').');
            }
            $account = GmailClient::complete((string) $request->query('code'), $verifier);
            $result = ['ok' => true, 'message' => GmailClient::canModify()
                ? "Gmail connected for {$account}: Expend can read your email and add its own labels. It never sends, moves or deletes anything."
                : "Gmail connected for {$account}, to read only: Google didn’t allow adding labels, so the ✓ Invoiced label is off. Connect again and allow it to switch it on."];
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

    public function syncGmailLabels(): RedirectResponse
    {
        if (! GmailLabeller::enabled()) {
            return back()->with('error', 'Connect Gmail with permission to add labels, and switch the label on, first.');
        }
        SyncGmailLabels::dispatch();

        return back()->with('success', 'Updating the “✓ Invoiced” labels in Gmail. It takes a minute or two.');
    }

    public function updateMail(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(OutgoingMail::METHODS)],
            'smtp_host' => 'nullable|string|max:200', 'smtp_port' => 'nullable|integer|in:25,465,587,2525',
            'smtp_username' => 'nullable|string|max:200', 'smtp_password' => 'nullable|string|max:300',
            'from_address' => 'nullable|email|max:200',
        ]);
        if ($data['method'] === 'smtp' && (empty($data['smtp_host']) || empty($data['smtp_username']) || (empty($data['smtp_password']) && Setting::get('smtp_password') === null))) {
            return back()->with('error', 'For your mailbox, fill in the server, the mailbox address and its password.');
        }
        Setting::put('mail_method', $data['method']);
        Setting::put('smtp_host', $data['smtp_host'] ?? null);
        Setting::put('smtp_port', $data['smtp_port'] ?? 465);
        Setting::put('smtp_username', $data['smtp_username'] ?? null);
        if (! empty($data['smtp_password'])) {
            Setting::put('smtp_password', $data['smtp_password']);
        }
        Setting::put('mail_from_address', $data['from_address'] ?? null);
        AuditLog::record('settings.mail', 'Settings', null, collect($data)->except('smtp_password')->all() + ['password_changed' => ! empty($data['smtp_password'])]);

        return back()->with('success', 'Email sending saved. Send yourself a test email to check it arrives.');
    }

    public function testMail(Request $request): RedirectResponse
    {
        try {
            // Queued: the scheduled worker sends it (website requests can't run the server's mail program).
            Mail::to($request->user()->email)->queue(new TestEmail);
        } catch (Throwable $e) {
            return back()->with('error', 'Couldn’t send: '.str_replace((string) Setting::get('smtp_password'), '••••', $e->getMessage()));
        }

        return back()->with('success', 'On its way to '.$request->user()->email.': it should arrive within a couple of minutes. If not, look in spam.');
    }
}
