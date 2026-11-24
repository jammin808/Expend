<?php

namespace App\Domain\Banks;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\NormalizedRow;
use App\Domain\Import\ProfileDetector;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
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
 * Rows from an import you undid stay undone: they're left out rather than brought back as new, undecided copies, so
 * restoring that import still brings them back with your decisions on them. Linking a Starling account to another of
 * your accounts never counts a day in both (see relink()).
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
            if (($kept = self::stillKept($info)) !== $info) {
                AuditLog::record('settings.starling_linked', 'Settings', ['uid' => $uid, 'kept_in' => $info['kept_in'], 'synced_to' => $info['synced_to']],
                    ['uid' => $uid, 'kept_in' => $kept['kept_in'] ?? null, 'synced_to' => $kept['synced_to']],
                    'What it imported into the account it fed before was undone there', 'system');
                $info = $map[$uid] = $kept;
            }
            $start = $from ? CarbonImmutable::parse($from)
                : ($info['synced_to'] ? CarbonImmutable::parse($info['synced_to'])->subDays(7) : $end->subDays(89));
            $floor = isset($info['kept_in']) ? CarbonImmutable::parse(max($info['kept_in'])) : null;
            $note = '';
            if ($floor && $start->lt($floor)) {
                if ($from) {
                    // The date you chose is used, but the days before the floor are in another account already.
                    $note = ' '.self::keptText($info['kept_in']).': undo those imports in Accounts › Import if they belong here, or they count twice.';
                } else {
                    $start = $floor;
                }
            }
            if ($start->gt($end)) {
                continue;
            }
            try {
                $csv = $this->call("accounts/{$uid}/statement/downloadForDateRange", ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')], 'text/csv')->body();
                $results[] = ['account' => $account->name, 'ok' => true, 'result' => $this->import($account, $csv, $start, $end, (string) $uid).$note];
                $map[$uid]['synced_to'] = $end->format('Y-m-d');
            } catch (Throwable $e) {
                $results[] = ['account' => $account->name, 'ok' => false, 'result' => mb_substr($e->getMessage(), 0, 300)];
            }
        }
        Setting::put('starling_accounts', $map);
        Setting::put('starling_last_result', ['at' => now()->toIso8601String(), 'results' => $results]);

        return $results;
    }

    /**
     * Point a Starling account at another of your accounts. What it already imported into the account it fed before is
     * left there, so the new account isn't given the same days again: it gets what's new from the day after. Once you
     * undo those imports, the next import brings the last three months into the new account (anything already there
     * isn't added twice). If nothing it imported is left where it was, the new account gets the three months straight
     * away.
     *
     * @return string what happens next, in plain words
     */
    public function relink(string $uid, Account $to): string
    {
        $map = (array) Setting::get('starling_accounts');
        $before = $map[$uid] ?? throw new RuntimeException('That Starling account isn’t connected.');
        if ((int) $before['account_id'] === $to->id) {
            return 'Saved.';
        }
        // Where this Starling account's rows still count: the account it fed until now, and any it fed before that.
        $keptIn = [];
        foreach ([(int) $before['account_id'] => null] + (array) ($before['kept_in'] ?? []) as $id => $from) {
            $kept = (int) $id === $to->id ? ['count' => 0, 'last' => null] : self::importedInto((int) $id);
            if ($kept['count']) {
                $keptIn[(int) $id] = $from ?? CarbonImmutable::parse($kept['last'])->addDay()->format('Y-m-d');
            }
        }
        $after = ['account_id' => $to->id, 'synced_to' => null] + $before;
        unset($after['kept_in']);
        if ($keptIn) {
            $after['kept_in'] = $keptIn;
        }
        $map[$uid] = $after;
        Setting::put('starling_accounts', $map);
        AuditLog::record('settings.starling_linked', 'Settings', ['uid' => $uid] + array_intersect_key($before, array_flip(['account_id', 'synced_to', 'kept_in'])),
            ['uid' => $uid] + array_intersect_key($after, array_flip(['account_id', 'synced_to', 'kept_in'])));

        if (! $keptIn) {
            return "Saved. The next import brings the last three months into {$to->name}; anything already there isn’t added twice.";
        }
        $from = CarbonImmutable::parse(max($keptIn));

        return 'Saved. '.self::keptText($keptIn).", so {$to->name} gets what’s new from {$from->format('j M Y')} and nothing counts twice. If those belong in "
            ."{$to->name} instead, undo those imports in Accounts › Import, and the next import brings the last three months into {$to->name}.";
    }

    /**
     * How many rows the automatic Starling imports put into an account that still count there, and the latest one's
     * date. Imports you undid don't count.
     *
     * @return array{count:int, last:?string}
     */
    public static function importedInto(int $accountId): array
    {
        $rows = BankTransaction::query()->where('account_id', $accountId)->whereIn('import_batch_id', ImportBatch::query()
            ->where('account_id', $accountId)->where('status', 'committed')->where('original_filename', 'like', 'Starling % (automatic).csv')->select('id'));
        $count = $rows->clone()->count();

        return ['count' => $count, 'last' => $count ? CarbonImmutable::parse($rows->max('posted_on'))->format('Y-m-d') : null];
    }

    /** Forget the accounts that no longer hold anything this Starling account imported (you undid it there). */
    private static function stillKept(array $info): array
    {
        if (empty($info['kept_in'])) {
            return $info;
        }
        $info['kept_in'] = array_filter((array) $info['kept_in'], fn ($from, $id) => self::importedInto((int) $id)['count'] > 0, ARRAY_FILTER_USE_BOTH);
        if ($info['kept_in'] === []) {
            // Undone there since: the account it feeds now gets the last three months, without duplicates.
            unset($info['kept_in']);
            $info['synced_to'] = null;
        }

        return $info;
    }

    /**
     * "Starling Personal still has the 3 payments this Starling account imported into it, up to 20 Nov 2026"
     *
     * @param  array<int, string>  $keptIn  account id => the day after the last row it holds
     */
    private static function keptText(array $keptIn): string
    {
        $names = Account::query()->whereKey(array_keys($keptIn))->orderBy('name')->pluck('name')->all();
        $count = array_sum(array_map(fn ($id) => self::importedInto((int) $id)['count'], array_keys($keptIn)));
        $upTo = CarbonImmutable::parse(max($keptIn))->subDay()->format('j M Y');

        return implode(' and ', $names).' still '.(count($names) === 1 ? 'has' : 'have')." the {$count} ".($count === 1 ? 'payment' : 'payments')
            .' this Starling account imported into '.(count($names) === 1 ? 'it' : 'them').", up to {$upTo}";
    }

    private function import(Account $account, string $csv, CarbonImmutable $start, CarbonImmutable $end, ?string $uid = null): string
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
            $name = "Starling {$start->format('j M')}–{$end->format('j M Y')} (automatic).csv";
            $importer = app(Importer::class);
            $preview = $importer->preview($account, $path, $profile);
            if ($preview['errors']) {
                $batch = $importer->commit($account, $path, $name, $profile); // refused with the rows to fix
            } else {
                // As commit(), without the rows you undid (the balance check still covers the whole statement), and
                // with what Starling's feed adds that its statement leaves out: the time, the country and the currency
                // a card payment abroad was made in.
                $undone = self::undone($account, $preview['rows']);
                $rows = $this->withFeedDetails($uid, array_values(array_diff_key($preview['rows'], array_flip($undone))), $start, $end);
                $batch = $importer->store($account, $rows, $path, $name, $profile->toArray(), $preview['balance'], $preview['first'], $preview['last']);
                if ($undone) {
                    AuditLog::record('import.left_out_undone', $batch, null, ['rows' => count($undone)],
                        'In an import you undid: restore that import to bring them back with your decisions', 'system');
                }
                if ($batch->status !== 'quarantined') {
                    $importer->afterImport($batch);
                }
            }
        } finally {
            @unlink($path);
        }
        $undone ??= [];
        $left = count($undone) ? ', '.count($undone).' you undid left out (restore that import in Accounts › Import to bring '.(count($undone) === 1 ? 'it' : 'them').' back)' : '';

        return match ($batch->status) {
            'quarantined' => "Held for review: the running balance didn’t add up ({$batch->row_count} rows). Check it in Accounts › Import.",
            default => "Imported {$batch->imported_count} new, {$batch->duplicate_count} already here{$left}.",
        };
    }

    /**
     * The rows (by index) that are in an import of this account you undid, and not in the ledger again since. Rows are
     * numbered like the importer does (the second identical coffee that day is occurrence 2). Where a day has more of
     * the same row than the undone import had, none of them is left out, so nothing new is lost.
     *
     * @param  list<NormalizedRow>  $rows
     * @return list<int>
     */
    private static function undone(Account $account, array $rows): array
    {
        $reverted = ImportBatch::query()->where('account_id', $account->id)->where('status', 'reverted')->pluck('id');
        if ($reverted->isEmpty() || $rows === []) {
            return [];
        }
        $keys = [];
        $counts = [];
        foreach ($rows as $i => $row) {
            $fp = $row->fingerprint($account->id);
            $counts[$fp] = ($counts[$fp] ?? 0) + 1;
            $keys[$i] = [$fp, $counts[$fp]];
        }
        $key = fn ($t) => $t->fingerprint.'#'.$t->occurrence;
        $fps = array_keys($counts);
        $reversed = BankTransaction::withoutGlobalScope('active')->whereIn('import_batch_id', $reverted)->where('state', 'reversed')
            ->whereIn('fingerprint', $fps)->get(['fingerprint', 'occurrence'])->map($key)->flip();
        $active = BankTransaction::query()->where('account_id', $account->id)->whereIn('fingerprint', $fps)->get(['fingerprint', 'occurrence'])->map($key)->flip();

        $undone = [];
        foreach ($keys as $i => [$fp, $occurrence]) {
            if (isset($reversed[$fp.'#'.$occurrence]) && ! isset($active[$fp.'#'.$occurrence])) {
                $undone[$fp][] = $i;
            }
        }

        return array_merge([], ...array_values(array_filter($undone, fn ($found, $fp) => count($found) === $counts[$fp], ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * Starling's statement has no time, place or currency for a line; its transaction feed has them. Each row is
     * matched to one feed item (same day in the UK, same amount and direction, and the same payee where there's more
     * than one), and given the time it was made (UK time), the country when it wasn't the UK, and the amount in the
     * currency it was paid in. These are added to what's kept from the row (so they show like any statement's) and
     * never change how a row is recognised again. A token without permission to read the feed, or a feed that can't
     * be read, leaves the rows as the statement has them.
     *
     * @param  list<NormalizedRow>  $rows
     * @return list<NormalizedRow>
     */
    private function withFeedDetails(?string $uid, array $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if (! $uid || $rows === []) {
            return $rows;
        }
        try {
            // Once each: a token without permission to read the feed is refused every time.
            $category = collect($this->call('accounts', [], 'application/json', tries: 1)->json('accounts') ?? [])->firstWhere('accountUid', $uid)['defaultCategory'] ?? null;
            if (! $category) {
                return $rows;
            }
            $utc = fn (CarbonImmutable $d) => $d->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
            $items = $this->call("feed/account/{$uid}/category/{$category}/transactions-between", [
                'minTransactionTimestamp' => $utc($start->subDays(2)->startOfDay()),
                'maxTransactionTimestamp' => $utc($end->addDays(2)->endOfDay()),
            ], 'application/json', tries: 1)->json('feedItems') ?? [];
        } catch (Throwable) {
            return $rows;
        }

        // The feed's items by day (UK) and amount, as the statement has them.
        $byKey = [];
        foreach ($items as $i => $item) {
            $minor = $item['amount']['minorUnits'] ?? null;
            if (! is_int($minor) || empty($item['transactionTime']) || ($item['status'] ?? '') === 'DECLINED') {
                continue;
            }
            try {
                $at = CarbonImmutable::parse($item['transactionTime'])->setTimezone('Europe/London');
            } catch (Throwable) {
                continue;
            }
            $signed = ($item['direction'] ?? '') === 'OUT' ? -$minor : $minor;
            $byKey[$at->format('Y-m-d').'|'.$signed][] = ['i' => $i, 'at' => $at, 'item' => $item];
        }
        $used = [];
        $name = fn (?string $n) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $n) ?? ''));
        foreach ($rows as $k => $row) {
            $candidates = array_values(array_filter($byKey[$row->effectiveDate()->format('Y-m-d').'|'.$row->amountPence] ?? [], fn ($c) => ! isset($used[$c['i']])));
            if (count($candidates) > 1) {
                $candidates = array_values(array_filter($candidates, fn ($c) => $name($c['item']['counterPartyName'] ?? null) !== '' && $name($c['item']['counterPartyName'] ?? null) === $name($row->counterparty)));
            }
            if (count($candidates) !== 1) {
                continue; // none, or more than one that could be it: left as the statement has it
            }
            ['i' => $i, 'at' => $at, 'item' => $item] = $candidates[0];
            $used[$i] = true;
            // Under the names any statement uses for them (so they're read as one's are), and said where they came from.
            $extra = ['Time' => $at->format('H:i')];
            $country = strtoupper((string) ($item['country'] ?? ''));
            if ($country !== '' && ! in_array($country, ['GB', 'UK'], true)) {
                $extra['Country'] = $country;
            }
            $row = clone $row;
            $source = $item['sourceAmount'] ?? null;
            $currency = strtoupper((string) ($source['currency'] ?? ''));
            if ($row->originalCurrency === null && preg_match('/^[A-Z]{3}$/', $currency) && $currency !== 'GBP' && is_int($source['minorUnits'] ?? null)) {
                // The feed gives it in the currency's own minor units (¥1,500 is 1500), signed like the line.
                $row->originalAmountMinor = $row->amountPence < 0 ? -abs($source['minorUnits']) : abs($source['minorUnits']);
                $row->originalCurrency = $currency;
                $extra['Paid amount'] = $currency.' '.$source['minorUnits'];
            }
            $row->raw = $row->raw + $extra + ['Added from Starling’s feed' => implode(', ', array_keys($extra))];
            $rows[$k] = $row;
        }

        return $rows;
    }

    private function call(string $path, array $query, string $accept, ?string $token = null, int $tries = 2)
    {
        $token ??= Setting::get('starling_token');
        $response = Http::withToken((string) $token)->accept($accept)->timeout(60)->retry($tries, 1000, throw: false)->get(self::API.$path, $query);
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Starling refused the token ('.$response->status().'). Check it hasn’t expired and has permission to read accounts and CSV statements.');
        }
        if ($response->failed()) {
            throw new RuntimeException('Starling said: '.($response->json('error_description') ?? $response->json('errors.0.message') ?? $response->json('message') ?? 'HTTP '.$response->status()));
        }

        return $response;
    }
}
