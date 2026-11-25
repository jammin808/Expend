<?php

namespace App\Domain\Gmail;

use App\Domain\Audit\AuditLog;
use App\Domain\Telegram\Telegram;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gmail access through Google's own API and your own Google sign-in client.
 *
 * The gmail.modify scope is requested: read, change labels and work with drafts. Google's permission is broad (it
 * can't delete mail permanently, but it could change mail), so the app's code only ever reads, adds or removes its own
 * "✓ Invoiced" label (GmailLabeller), and puts an issued invoice or a reminder you asked for in your drafts
 * (GmailDrafts). It sends one of those drafts only when you press Send, and deletes only its own drafts you haven't
 * edited; it never moves or deletes your mail. Connections made with read-only access keep working for reading. The
 * refresh token is stored encrypted; disconnecting revokes it at Google.
 *
 * A request Gmail refuses throws a RuntimeException whose code is the HTTP status (404: it isn't there any more), so
 * callers can tell a draft you deleted from Gmail being unreachable.
 */
final class GmailClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/gmail.modify';

    public const READONLY = 'https://www.googleapis.com/auth/gmail.readonly';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me/';

    public static function configured(): bool
    {
        return Setting::get('google_client_id') && Setting::get('google_client_secret');
    }

    public static function connected(): bool
    {
        return Setting::get('gmail_refresh_token') !== null;
    }

    /** Whether the connection allows adding labels (connections made before labels were added are read-only). */
    public static function canModify(): bool
    {
        return self::connected() && in_array(self::SCOPE, (array) Setting::get('gmail_scopes'), true);
    }

    public static function redirectUri(): string
    {
        return url('/settings/gmail/callback');
    }

    /**
     * The Google consent URL. The state carries the PKCE verifier encrypted with the app key, plus a
     * one-time nonce kept on the server, because the session cookie (SameSite=Strict) isn't sent
     * when Google redirects back.
     */
    public static function authUrl(): string
    {
        $verifier = Str::random(64);
        $nonce = Str::random(32);
        Setting::put('gmail_oauth_nonce', ['nonce' => $nonce, 'expires' => now()->addMinutes(15)->timestamp]);

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => Setting::get('google_client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
            'state' => Crypt::encryptString(json_encode(['nonce' => $nonce, 'verifier' => $verifier])),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Check the state Google sent back and use up its one-time nonce. Returns the PKCE verifier, or null if
     * the state isn't one this app issued in the last 15 minutes (forged, replayed or expired).
     */
    public static function acceptState(string $state): ?string
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return null;
        }
        $expected = Setting::get('gmail_oauth_nonce');
        if (! $expected || ($expected['expires'] ?? 0) < now()->timestamp || ! hash_equals((string) $expected['nonce'], (string) ($data['nonce'] ?? ''))) {
            return null;
        }
        Setting::put('gmail_oauth_nonce', null);

        return (string) ($data['verifier'] ?? '');
    }

    /** Finish the sign-in: swap the code for tokens and remember the account. */
    public static function complete(string $code, string $verifier): string
    {

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => Setting::get('google_client_id'),
            'client_secret' => Setting::get('google_client_secret'),
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ])->json() ?? [];

        if (empty($response['refresh_token'])) {
            throw new RuntimeException('Google didn’t grant access: '.($response['error_description'] ?? $response['error'] ?? 'no refresh token returned').'.');
        }
        $granted = explode(' ', (string) ($response['scope'] ?? ''));
        if (! in_array(self::SCOPE, $granted, true) && ! in_array(self::READONLY, $granted, true)) {
            throw new RuntimeException('Gmail access wasn’t granted. Tick the Gmail box on Google’s consent screen and try again.');
        }

        Setting::put('gmail_refresh_token', $response['refresh_token']);
        Setting::put('gmail_scopes', array_values(array_intersect($granted, [self::SCOPE, self::READONLY])));
        self::rememberAccess($response);
        $email = (string) (self::get('profile')['emailAddress'] ?? '');
        Setting::put('gmail_account', $email);
        AuditLog::record('settings.gmail_connected', 'Settings', null, ['account' => $email, 'scope' => self::SCOPE]);

        return $email;
    }

    public static function disconnect(): void
    {
        if ($token = Setting::get('gmail_refresh_token')) {
            try {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => $token]);
            } catch (\Throwable $e) {
                report($e); // the tokens are still removed below; access can also be removed at myaccount.google.com
            }
        }
        foreach (['gmail_refresh_token', 'gmail_access_token', 'gmail_access_expires', 'gmail_account', 'gmail_scopes'] as $key) {
            Setting::put($key, null);
        }
        AuditLog::record('settings.gmail_disconnected', 'Settings');
    }

    /**
     * GET a Gmail API path, for example "messages" or "messages/{id}". The query may be a string, for a parameter Gmail
     * takes more than once (metadataHeaders=To&metadataHeaders=Subject). With a timeout (seconds), a request made while
     * you wait, it isn't retried.
     */
    public static function get(string $path, array|string $query = [], ?int $timeout = null): array
    {
        // Retry only what a retry can fix: network trouble and Google's own server errors.
        $retryable = fn ($e) => $e instanceof ConnectionException
            || ($e instanceof RequestException && $e->response->serverError());
        $request = fn () => Http::withToken(self::accessToken())->timeout($timeout ?? 30);
        $response = ($timeout === null ? $request()->retry(2, 500, $retryable, throw: false) : $request())->get(self::API.$path, $query);
        if ($response->status() === 401) {
            Setting::put('gmail_access_token', null);
            $response = $request()->get(self::API.$path, $query);
        }

        return self::answer($response);
    }

    /** POST to a Gmail API path, for example "labels", "messages/batchModify", "drafts" or "drafts/send". */
    public static function post(string $path, array $body, ?int $timeout = null): array
    {
        $response = Http::withToken(self::accessToken())->timeout($timeout ?? 30)->asJson()->post(self::API.$path, $body);

        return self::answer($response);
    }

    /** DELETE a Gmail API path: only ever one of Expend's own drafts ("drafts/{id}"). */
    public static function delete(string $path, ?int $timeout = null): void
    {
        self::answer(Http::withToken(self::accessToken())->timeout($timeout ?? 30)->delete(self::API.$path));
    }

    /** The answer's JSON, or a RuntimeException with Gmail's message and the HTTP status as its code. */
    private static function answer(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('Gmail said: '.($response->json('error.message') ?? 'HTTP '.$response->status()), $response->status());
        }

        return $response->json() ?? [];
    }

    /** @return array{ids:list<string>, next:?string} */
    public static function search(string $query, ?string $pageToken = null, int $max = 100): array
    {
        $data = self::get('messages', array_filter(['q' => $query, 'maxResults' => $max, 'pageToken' => $pageToken]));

        return ['ids' => array_column($data['messages'] ?? [], 'id'), 'next' => $data['nextPageToken'] ?? null];
    }

    /** @return array{raw:string, thread_id:?string, sent_at:?int} */
    public static function message(string $id): array
    {
        $data = self::get('messages/'.rawurlencode($id), ['format' => 'raw']);

        return [
            'raw' => (string) base64_decode(strtr((string) ($data['raw'] ?? ''), '-_', '+/')),
            'thread_id' => $data['threadId'] ?? null,
            'sent_at' => isset($data['internalDate']) ? intdiv((int) $data['internalDate'], 1000) : null,
        ];
    }

    private static function accessToken(): string
    {
        $token = Setting::get('gmail_access_token');
        if ($token && (int) Setting::get('gmail_access_expires') > now()->addMinute()->timestamp) {
            return $token;
        }

        $refresh = Setting::get('gmail_refresh_token');
        if (! $refresh) {
            throw new RuntimeException('Gmail isn’t connected. Connect it in Settings › Integrations.');
        }
        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => Setting::get('google_client_id'),
            'client_secret' => Setting::get('google_client_secret'),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ])->json() ?? [];

        if (($response['error'] ?? null) === 'invalid_grant') {
            Setting::put('gmail_refresh_token', null);
            Setting::put('gmail_last_result', ['ok' => false, 'message' => 'Google ended the Gmail connection (in Testing it lasts 7 days). Press Connect Gmail to carry on.', 'at' => now()->toIso8601String()]);
            // Say so on Telegram, so searches and labels don't quietly stop.
            if (Setting::get('telegram_chat_id') && Telegram::token()) {
                Telegram::reply(Setting::get('telegram_chat_id'), 'Gmail needs reconnecting: Google ends the connection every 7 days while your Google app is in Testing. Open Settings › Integrations › Gmail and press Connect Gmail. '.url('/settings/integrations#gmail'));
            }
            throw new RuntimeException('Google ended the app’s Gmail access (it was revoked, or the Google sign-in client is in Testing, which ends access after 7 days). Connect again in Settings › Integrations.');
        }
        if (empty($response['access_token'])) {
            throw new RuntimeException('Couldn’t refresh Gmail access: '.($response['error_description'] ?? $response['error'] ?? 'no answer').'.');
        }

        return self::rememberAccess($response);
    }

    private static function rememberAccess(array $response): string
    {
        Setting::put('gmail_access_token', $response['access_token']);
        Setting::put('gmail_access_expires', now()->addSeconds((int) ($response['expires_in'] ?? 3600))->timestamp);

        return $response['access_token'];
    }
}
