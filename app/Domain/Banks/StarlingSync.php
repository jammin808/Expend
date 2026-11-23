<?php

namespace App\Domain\Banks;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Models\Account;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Daily import from Starling with your own read-only personal access token. It downloads Starling's CSV statement for
 * the days since the last import (with a week's overlap) and imports it exactly like a file you upload: the running
 * balance is checked (a statement that doesn't add up is held for review), and overlapping days never duplicate rows.
 * The token is stored encrypted and can only read; nothing is ever paid or changed at Starling.
 */
final class StarlingSync
{
    public const API = 'https://api.starlingbank.com/api/v2/';

    public static function connected(): bool
    {
        return (bool) Setting::get('starling_token');
    }

    /** Check a token and remember it, with which of your accounts each Starling account imports into. */
    public function connect(string $token): array
    {
        $accounts = $this->call('accounts', [], 'application/json', $token)->json('accounts') ?? [];
        if (! $accounts) {
            throw new RuntimeException('Starling accepted the token but listed no accounts. Check its permissions include reading accounts.');
        }
        Setting::put('starling_token', $token);
        $map = (array) Setting::get('starling_accounts');
        foreach ($accounts as $a) {
            $uid = (string) $a['accountUid'];
            if (isset($map[$uid])) {
                continue;
            }
            $existing = Account::query()->where('provider', 'starling')->get();
            $account = $existing->count() === 1 && ! collect($map)->pluck('account_id')->contains($existing->first()->id)
                ? $existing->first()
                : Account::query()->firstOrCreate(['name' => 'Starling '.($a['name'] ?? 'account')], ['kind' => 'bank', 'provider' => 'starling']);
            $map[$uid] = ['account_id' => $account->id, 'name' => (string) ($a['name'] ?? 'Starling'), 'currency' => $a['currency'] ?? 'GBP', 'synced_to' => null];
        }
        Setting::put('starling_accounts', $map);
        AuditLog::record('settings.starling_connected', 'Settings', null, ['accounts' => count($accounts)]);

        return $map;
    }

    public function disconnect(): void
    {
        Setting::put('starling_token', null);
        AuditLog::record('settings.starling_disconnected', 'Settings');
    }

    /**
     * Import each linked account's statement since its last import.
     *
     * @return list<array{account:string, result:string, ok:bool}>
     */
    public function sync(?string $from = null): array
    {
        if (! self::connected()) {
            throw new RuntimeException('Starling isn’t connected.');
        }
        $map = (array) Setting::get('starling_accounts');
        $end = CarbonImmutable::yesterday('Europe/London');
        $results = [];
        foreach ($map as $uid => $info) {
            $account = Account::query()->find($info['account_id']);
            if (! $account) {
                continue;
            }
            $start = $from ? CarbonImmutable::parse($from)
                : ($info['synced_to'] ? CarbonImmutable::parse($info['synced_to'])->subDays(7) : $end->subDays(89));
            if ($start->gt($end)) {
                continue;
            }
            try {
                $csv = $this->call("accounts/{$uid}/statement/downloadForDateRange", ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')], 'text/csv')->body();
                $results[] = ['account' => $account->name, 'ok' => true, 'result' => $this->import($account, $csv, $start, $end)];
                $map[$uid]['synced_to'] = $end->format('Y-m-d');
            } catch (Throwable $e) {
                $results[] = ['account' => $account->name, 'ok' => false, 'result' => mb_substr($e->getMessage(), 0, 300)];
            }
        }
        Setting::put('starling_accounts', $map);
        Setting::put('starling_last_result', ['at' => now()->toIso8601String(), 'results' => $results]);

        return $results;
    }

    private function import(Account $account, string $csv, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $lines = array_filter(preg_split('/\r?\n/', trim($csv)) ?: []);
        if (count($lines) <= 1) {
            return 'No new transactions.';
        }
        $dir = storage_path('app/private/uploads');
        @mkdir($dir, 0700, true);
        $path = $dir.'/starling-'.Str::uuid().'.csv';
        file_put_contents($path, $csv);
        try {
            $profile = ProfileDetector::detect(CsvReader::read($path)['headers'], $account->kind);
            if ($profile->preset !== 'starling') {
                throw new RuntimeException('Starling’s statement wasn’t in the format the app expects, so nothing was imported. Import the CSV from the Starling app instead, and tell me so I can adapt.');
            }
            $batch = app(Importer::class)->commit($account, $path, "Starling {$start->format('j M')}–{$end->format('j M Y')} (automatic).csv", $profile);
        } finally {
            @unlink($path);
        }

        return match ($batch->status) {
            'quarantined' => "Held for review: the running balance didn’t add up ({$batch->row_count} rows). Check it in Accounts › Import.",
            default => "Imported {$batch->imported_count} new, {$batch->duplicate_count} already here.",
        };
    }

    private function call(string $path, array $query, string $accept, ?string $token = null)
    {
        $token ??= Setting::get('starling_token');
        $response = Http::withToken((string) $token)->accept($accept)->timeout(60)->retry(2, 1000, throw: false)->get(self::API.$path, $query);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Starling refused the token ('.$response->status().'). Check it hasn’t expired and has permission to read accounts and CSV statements.');
        }
        if ($response->failed()) {
            throw new RuntimeException('Starling said: '.($response->json('error_description') ?? $response->json('errors.0.message') ?? $response->json('message') ?? 'HTTP '.$response->status()));
        }

        return $response;
    }
}
