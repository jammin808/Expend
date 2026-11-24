<?php

namespace App\Domain\Import;

use App\Domain\Audit\AuditLog;
use App\Domain\Fx\ExchangeRates;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Support\Money;
use Brick\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Revolut statements, personal and Business. A Revolut account holds several currencies (and savings), each with its
 * own statement or its own rows in one file, so each currency goes into its own "pocket" account (Revolut EUR,
 * Revolut USD...) with its own running balance, checked in that currency.
 *
 * Every line in another currency also gets the sterling amount it counts as for tax: the sterling side of a
 * currency exchange when it was bought with pounds, the bank's sterling figure when the file gives one, and
 * otherwise HMRC's monthly rate for that month (HMRC accepts a bank's rate or its own published rates, used
 * consistently: BIM39515). Exchanges between your own currencies are paired as transfers, never spending.
 *
 * Only completed rows count: pending, declined, failed and reverted ones are skipped and counted.
 */
final class RevolutImport
{
    private const PERSONAL = ['type', 'product', 'started date', 'completed date', 'description', 'amount', 'fee', 'currency', 'state', 'balance'];

    private const PERSONAL_DE = ['art', 'produkt', 'datum des beginns', 'datum des abschlusses', 'beschreibung', 'betrag', 'gebühr', 'währung', 'status', 'kontostand'];

    private const DONE = ['COMPLETED', 'ABGESCHLOSSEN', 'DOKONČENO', 'TERMINÉ', 'COMPLETADO', 'COMPLETATO', 'VOLTOOID', 'ZAKOŃCZONO'];

    public function __construct(private Importer $importer, private ExchangeRates $rates, private AllocationWriter $writer) {}

    /** 'personal', 'business', or null when it isn't a Revolut statement. */
    public static function detect(array $headers): ?string
    {
        $h = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $headers);
        if (in_array('date started (utc)', $h, true) && in_array('payment currency', $h, true)) {
            return 'business';
        }
        $first = array_slice($h, 0, 10);

        return $first === self::PERSONAL || $first === self::PERSONAL_DE ? 'personal' : null;
    }

    /**
     * Read the file into pockets, without changing anything.
     *
     * @return array{kind:string, pockets:list<array{key:string, label:string, currency:string, account:?Account, name:string, rows:list<NormalizedRow>, balance:array, first:?string, last:?string, new:int, duplicates:int}>,
     *               skipped:array<string,int>, errors:list<string>, exchanges:int}
     */
    public function read(Account $base, string $path): array
    {
        $csv = CsvReader::read($path);
        $kind = self::detect($csv['headers']) ?? throw new RuntimeException('This isn’t a Revolut statement.');
        ['lines' => $lines, 'skipped' => $skipped, 'errors' => $errors] = $kind === 'personal' ? $this->personal($csv['rows']) : $this->business($csv['headers'], $csv['rows']);

        // Pair the two sides of each currency exchange.
        $legs = [];
        foreach ($lines as $i => $l) {
            if ($l['exchange']) {
                $legs[$l['exchange']][] = $i;
            }
        }
        $pairs = array_filter($legs, fn ($ix) => count($ix) === 2 && $lines[$ix[0]]['currency'] !== $lines[$ix[1]]['currency']
            && ($lines[$ix[0]]['total'] < 0) !== ($lines[$ix[1]]['total'] < 0));

        // A personal statement covers one currency, so the other side of an exchange is often in a statement imported
        // before: find it among this account's pockets (same start time or ID, the other currency, the opposite way).
        $paired = array_merge(...array_values($pairs ?: [[]]));
        $existing = [];
        foreach ($lines as $i => $l) {
            if ($l['exchange'] && ! in_array($i, $paired, true) && ($tx = $this->existingLeg($base, $l))) {
                $existing[$i] = $tx;
            }
        }

        // Sterling for every line.
        foreach ($lines as $i => &$l) {
            if (isset($existing[$i])) {
                $l['exchange'] = 'db:'.$existing[$i]->id;
            }
            if ($l['currency'] === 'GBP') {
                $l['pence'] = $l['total'];

                continue;
            }
            $other = null;
            foreach ($pairs as $ix) {
                if (in_array($i, $ix, true)) {
                    $other = $lines[$ix[0] === $i ? $ix[1] : $ix[0]];
                }
            }
            $earlier = $existing[$i] ?? null;
            try {
                if ($other && $other['currency'] === 'GBP') {
                    $l['pence'] = -$other['total'];
                    $l['fx'] = ['source' => 'exchange', 'date' => $l['date']->format('Y-m-d')];
                } elseif ($earlier && ! $earlier->original_currency) {
                    // The pounds side was imported already: this side counts as exactly those pounds.
                    $l['pence'] = -$earlier->amount_pence;
                    $l['fx'] = ['source' => 'exchange', 'date' => $l['date']->format('Y-m-d')];
                } elseif ($l['orig_currency'] === 'GBP' && $l['orig_minor']) {
                    $l['pence'] = $l['total'] < 0 ? -abs($l['orig_minor']) : abs($l['orig_minor']);
                    $l['fx'] = ['source' => 'bank', 'date' => $l['date']->format('Y-m-d')];
                } else {
                    $c = $this->rates->toPence($l['total'], $l['currency'], $l['date']);
                    $l['pence'] = $c['pence'];
                    $l['fx'] = ['source' => 'hmrc_monthly', 'date' => $c['rate_date'], 'units' => $c['units_per_gbp']];
                }
            } catch (RateUnavailable $e) {
                $errors[] = $e->getMessage();
                $l['pence'] = null;
            }
            if (($l['pence'] ?? null) === 0) {
                $l['pence'] = $l['total'] < 0 ? -1 : 1; // a tiny amount still moves money
            }
            if (! isset($l['fx']['units']) && $l['pence']) {
                $l['fx']['units'] = number_format(abs($this->major($l['total'], $l['currency'])) / abs($l['pence'] / 100), 6, '.', '');
            }
        }
        unset($l);
        // An exchange between two other currencies: both sides count as the same sterling, so they pair exactly.
        foreach ($pairs as $ix) {
            [$a, $b] = $ix;
            if ($lines[$a]['currency'] !== 'GBP' && $lines[$b]['currency'] !== 'GBP' && $lines[$a]['pence'] !== null) {
                $lines[$b]['pence'] = -$lines[$a]['pence'];
                $lines[$b]['fx']['source'] = 'exchange';
            }
        }

        // Into pockets: one per currency (and savings), or per Business account.
        $pockets = [];
        foreach ($lines as $l) {
            $pockets[$l['pocket']] ??= ['key' => $l['pocket'], 'label' => $l['pocket_label'], 'currency' => $l['currency'], 'lines' => []];
            $pockets[$l['pocket']]['lines'][] = $l;
        }
        $mainKey = $this->mainPocket($base, array_values($pockets));
        $out = [];
        foreach ($pockets as $key => $p) {
            $account = $key === $mainKey ? $base : $this->pocketAccount($base, $p['key']);
            $rows = array_map(fn ($l) => $this->row($l), array_filter($p['lines'], fn ($l) => $l['pence'] !== null));
            $dates = array_map(fn (NormalizedRow $r) => $r->effectiveDate()->format('Y-m-d'), $rows);
            sort($dates);
            $plan = $account ? $this->importer->planCounts($account, $rows) : ['new' => count($rows), 'duplicates' => 0];
            $out[] = ['key' => $key, 'label' => $p['label'], 'currency' => $p['currency'], 'account' => $account,
                'name' => $account?->name ?? self::pocketName($base, $p['label']), 'rows' => $rows, 'balance' => BalanceCheck::run($rows),
                'first' => $dates[0] ?? null, 'last' => $dates ? end($dates) : null] + $plan;
        }
        usort($out, fn ($a, $b) => [$a['key'] !== $mainKey, $a['label']] <=> [$b['key'] !== $mainKey, $b['label']]);

        return ['kind' => $kind, 'pockets' => $out, 'skipped' => $skipped, 'errors' => array_values(array_unique($errors)), 'exchanges' => count($pairs)];
    }

    /**
     * Import it: each pocket into its account (made the first time), exchanges paired as transfers.
     *
     * @return list<ImportBatch>
     */
    public function commit(Account $base, string $path, string $originalName): array
    {
        $read = $this->read($base, $path);
        if ($read['errors']) {
            throw new RuntimeException('Fix these first: '.implode(' ', array_slice($read['errors'], 0, 5)));
        }

        $batches = DB::transaction(function () use ($read, $base, $path, $originalName) {
            $batches = [];
            foreach ($read['pockets'] as $p) {
                if (! $p['rows']) {
                    continue;
                }
                $account = $p['account'] ?? $this->makePocket($base, $p);
                $batches[] = ['batch' => $this->importer->store($account, $p['rows'], $path, $originalName,
                    ['preset' => 'revolut_'.$read['kind'], 'pocket' => $p['key']], $p['balance'], $p['first'], $p['last']), 'account' => $account, 'rows' => $p['rows']];
            }
            $this->pairExchanges($batches);

            return array_map(fn ($b) => $b['batch'], $batches);
        });
        $live = array_values(array_filter($batches, fn (ImportBatch $b) => $b->status !== 'quarantined'));
        if ($live) {
            $this->importer->afterImport(...$live);
        }

        return $batches;
    }

    // ---------------------------------------------------------------- reading

    /** @return array{lines:list<array>, skipped:array<string,int>, errors:list<string>} */
    private function personal(array $rows): array
    {
        $lines = [];
        $skipped = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $v = array_values($row);
            $raw = array_combine(['Type', 'Product', 'Started Date', 'Completed Date', 'Description', 'Amount', 'Fee', 'Currency', 'State', 'Balance'], array_pad(array_slice($v, 0, 10), 10, ''));
            $state = mb_strtoupper(trim($raw['State']));
            if ($state !== '' && ! in_array($state, self::DONE, true)) {
                $skipped[$state] = ($skipped[$state] ?? 0) + 1;

                continue;
            }
            try {
                $currency = strtoupper(trim($raw['Currency'])) ?: 'GBP';
                $amount = $this->minor($raw['Amount'], $currency) ?? throw new InvalidArgumentException('no amount');
                $fee = $this->minor($raw['Fee'], $currency) ?? 0;
                $started = self::when($raw['Started Date']);
                $completed = self::when($raw['Completed Date'] ?: $raw['Started Date']);
            } catch (InvalidArgumentException $e) {
                $errors[] = 'Line '.($n + 2).': '.$e->getMessage().'.';

                continue;
            }
            $product = trim($raw['Product']);
            $savings = $product !== '' && ! in_array(mb_strtolower($product), ['current', 'aktuell', 'aktuální', 'courant', 'corriente', 'corrente'], true);
            $total = $amount - abs($fee);
            $lines[] = [
                'pocket' => $currency.($savings ? '|'.mb_strtolower($product) : ''), 'pocket_label' => $currency.($savings ? ' '.mb_strtolower($product) : ''),
                'currency' => $currency, 'total' => $total, 'fee' => $fee ? abs($fee) : null, 'balance' => $this->minor($raw['Balance'], $currency),
                'posted' => $completed, 'date' => $started, 'description' => trim($raw['Description']) ?: trim($raw['Type']), 'counterparty' => null,
                'reference' => null, 'orig_currency' => null, 'orig_minor' => null, 'raw' => $raw, 'line' => $n + 2,
                'exchange' => preg_match('/^exchange$/i', trim($raw['Type'])) ? 'x:'.$raw['Started Date'].'|'.$product : null,
            ];
        }

        return ['lines' => $lines, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @return array{lines:list<array>, skipped:array<string,int>, errors:list<string>} */
    private function business(array $headers, array $rows): array
    {
        $lines = [];
        $skipped = [];
        $errors = [];
        foreach ($rows as $n => $row) {
            $get = function (string $name) use ($row): string {
                foreach ($row as $k => $v) {
                    if (mb_strtolower(trim((string) $k)) === $name) {
                        return trim((string) $v);
                    }
                }

                return '';
            };
            $state = mb_strtoupper($get('state'));
            if ($state !== '' && ! in_array($state, self::DONE, true)) {
                $skipped[$state] = ($skipped[$state] ?? 0) + 1;

                continue;
            }
            try {
                $currency = strtoupper($get('payment currency')) ?: 'GBP';
                $total = $get('total amount') !== ''
                    ? $this->minor($get('total amount'), $currency)
                    : (($this->minor($get('amount'), $currency) ?? throw new InvalidArgumentException('no amount')) + ($this->minor($get('fee'), $currency) ?? 0));
                $total ?? throw new InvalidArgumentException('no amount');
                $started = self::when($get('date started (utc)'));
                $completed = self::when($get('date completed (utc)') ?: $get('date started (utc)'));
            } catch (InvalidArgumentException $e) {
                $errors[] = 'Line '.($n + 2).': '.$e->getMessage().'.';

                continue;
            }
            $origCurrency = strtoupper($get('orig currency')) ?: null;
            $account = $get('account') ?: $currency;
            $type = $get('type');
            $lines[] = [
                'pocket' => 'acct|'.$account, 'pocket_label' => $account, 'currency' => $currency, 'total' => $total,
                'fee' => ($f = $this->minor($get('fee'), $currency)) ? abs($f) : null, 'balance' => $this->minor($get('balance'), $currency),
                'posted' => $completed, 'date' => $started, 'description' => $get('description') ?: $type,
                'counterparty' => $get('beneficiary name') ?: null, 'reference' => $get('reference') ?: null,
                'orig_currency' => $origCurrency && $origCurrency !== $currency ? $origCurrency : null,
                'orig_minor' => $origCurrency && $origCurrency !== $currency ? $this->minor($get('orig amount'), $origCurrency) : null,
                'raw' => $row, 'line' => $n + 2,
                'exchange' => strtoupper($type) === 'EXCHANGE' && $get('id') !== '' ? 'id:'.$get('id') : null,
            ];
        }

        return ['lines' => $lines, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function row(array $l): NormalizedRow
    {
        $foreign = $l['currency'] !== 'GBP';
        $origMinor = $l['orig_minor'] !== null ? ($l['total'] < 0 ? -abs($l['orig_minor']) : abs($l['orig_minor'])) : null;

        return new NormalizedRow(
            postedOn: $l['posted'],
            transactionOn: $l['date']->format('Y-m-d') !== $l['posted']->format('Y-m-d') ? $l['date'] : null,
            description: mb_substr($l['description'], 0, 500),
            counterparty: $l['counterparty'] ? mb_substr($l['counterparty'], 0, 255) : null,
            reference: $l['reference'] ? mb_substr($l['reference'], 0, 255) : null,
            amountPence: (int) $l['pence'],
            balanceAfterPence: $foreign ? null : $l['balance'],
            originalAmountMinor: $foreign ? $l['total'] : $origMinor,
            originalCurrency: $foreign ? $l['currency'] : $l['orig_currency'],
            bankCategory: null,
            raw: $l['raw'],
            line: $l['line'],
            nativeAmountMinor: $foreign ? $l['total'] : null,
            nativeBalanceMinor: $foreign ? $l['balance'] : null,
            fxUnitsPerGbp: $foreign ? ($l['fx']['units'] ?? null) : null,
            fxRateDate: $foreign ? ($l['fx']['date'] ?? null) : null,
            fxSource: $foreign ? ($l['fx']['source'] ?? null) : null,
            feeMinor: $l['fee'],
            exchangeKey: $l['exchange'],
        );
    }

    // ---------------------------------------------------------------- pockets

    /** The pocket that belongs in the account you chose: its currency's main (not savings) pocket. */
    private function mainPocket(Account $base, array $pockets): ?string
    {
        $currency = strtoupper($base->currency ?: 'GBP');
        $mine = array_values(array_filter($pockets, fn ($p) => $p['currency'] === $currency));
        usort($mine, fn ($a, $b) => [str_contains($a['key'], '|') && ! str_starts_with($a['key'], 'acct|'), ! preg_match('/main|current/i', $a['label'])]
            <=> [str_contains($b['key'], '|') && ! str_starts_with($b['key'], 'acct|'), ! preg_match('/main|current/i', $b['label'])]);

        return $mine[0]['key'] ?? null;
    }

    private function pocketAccount(Account $base, string $key): ?Account
    {
        return Account::query()->where('parent_account_id', $base->id)->get()->first(fn (Account $a) => ($a->import_profile['pocket'] ?? null) === $key);
    }

    private function makePocket(Account $base, array $p): Account
    {
        $account = Account::query()->create(['name' => self::pocketName($base, $p['label']), 'kind' => 'bank', 'provider' => $base->provider ?: 'revolut',
            'currency' => $p['currency'], 'parent_account_id' => $base->id, 'import_profile' => ['pocket' => $p['key']], 'is_active' => true]);
        AuditLog::record('account.created', $account, null, $account->toArray(), 'A '.$p['currency'].' pocket of '.$base->name.' in a Revolut statement');

        return $account;
    }

    private static function pocketName(Account $base, string $label): string
    {
        $label = preg_replace('/^(GBP|EUR|USD|[A-Z]{3}) Main$/', '$1', $label);

        return mb_substr($base->name.' '.$label, 0, 80);
    }

    /**
     * The other side of an exchange, already imported into this account or one of its pockets: same start time (or
     * Revolut Business ID), the other currency, money moving the other way, not yet paired.
     */
    private function existingLeg(Account $base, array $l): ?BankTransaction
    {
        $family = Account::query()->where('id', $base->id)->orWhere('parent_account_id', $base->id)->get()->keyBy('id');
        $started = (string) ($l['raw']['Started Date'] ?? '');
        $id = (string) ($l['raw']['ID'] ?? '');

        return BankTransaction::query()->whereIn('account_id', $family->keys())->whereNull('transfer_pair_id')
            ->where('amount_pence', $l['total'] < 0 ? '>' : '<', 0)
            ->whereBetween('posted_on', [$l['posted']->subDays(4)->format('Y-m-d'), $l['posted']->addDays(4)->format('Y-m-d')])
            ->get()
            ->first(function (BankTransaction $t) use ($family, $l, $started, $id) {
                $currency = strtoupper($family[$t->account_id]->currency ?: 'GBP');
                $raw = $t->raw ?? [];
                $type = (string) ($raw['Type'] ?? '');

                return $currency !== $l['currency'] && preg_match('/^exchange$/i', $type)
                    && (($started !== '' && ($raw['Started Date'] ?? null) === $started) || ($id !== '' && ($raw['ID'] ?? null) === $id));
            });
    }

    /** @param list<array{batch:ImportBatch, account:Account, rows:list<NormalizedRow>}> $batches */
    private function pairExchanges(array $batches): void
    {
        $found = [];
        foreach ($batches as $b) {
            foreach ($b['rows'] as $row) {
                if ($row->exchangeKey) {
                    $tx = BankTransaction::withoutGlobalScope('active')->where('account_id', $b['account']->id)->where('fingerprint', $row->fingerprint($b['account']->id))->orderByDesc('id')->first();
                    if ($tx && ! $tx->transfer_pair_id) {
                        $found[$row->exchangeKey][] = $tx;
                        if (str_starts_with($row->exchangeKey, 'db:') && ($earlier = BankTransaction::query()->find((int) substr($row->exchangeKey, 3))) && ! $earlier->transfer_pair_id) {
                            $found[$row->exchangeKey][] = $earlier;
                        }
                    }
                }
            }
        }
        foreach ($found as $key => $txs) {
            // Two sides of the same exchange. Within one file they count as the same pounds; across files the side
            // imported first may have been converted at HMRC's rate, so the pounds can differ slightly.
            $sameFile = ! str_starts_with((string) $key, 'db:');
            if (count($txs) !== 2 || $txs[0]->account_id === $txs[1]->account_id || ($txs[0]->amount_pence < 0) === ($txs[1]->amount_pence < 0)
                || ($sameFile && $txs[0]->amount_pence !== -$txs[1]->amount_pence)) {
                continue;
            }
            [$a, $b] = $txs;
            $a->forceFill(['transfer_pair_id' => $b->id])->saveQuietly();
            $b->forceFill(['transfer_pair_id' => $a->id])->saveQuietly();
            $out = $a->amount_pence < 0 ? $a : $b;
            $in = $out === $a ? $b : $a;
            $why = 'Currency exchange: '.Money::format(abs($out->original_amount_minor ?? $out->amount_pence), $out->original_currency ?? 'GBP', false)
                .' to '.Money::format(abs($in->original_amount_minor ?? $in->amount_pence), $in->original_currency ?? 'GBP', false).', between your own pockets';
            foreach ([$a, $b] as $tx) {
                if ($tx->state === 'active') {
                    try {
                        $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $why);
                    } catch (InvalidArgumentException) {
                        // a locked year
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /** An amount in the currency's own minor units (pence, cents; yen have none). */
    private function minor(string $value, string $currency): ?int
    {
        $v = trim(str_replace(["'", ' '], '', $value));
        if ($v === '') {
            return null;
        }
        $hundredths = Money::parse($v);
        if ($hundredths === null) {
            throw new InvalidArgumentException("can't read the amount \"{$value}\"");
        }
        $digits = self::digits($currency);

        return $digits === 2 ? $hundredths : (int) round($hundredths * (10 ** ($digits - 2)));
    }

    private function major(int $minor, string $currency): float
    {
        return $minor / (10 ** self::digits($currency));
    }

    private static function digits(string $currency): int
    {
        try {
            return Currency::of($currency)->getDefaultFractionDigits();
        } catch (\Throwable) {
            return 2;
        }
    }

    private static function when(string $value): CarbonImmutable
    {
        $value = trim($value);
        foreach (['Y-m-d H:i:s', 'Y-m-d G:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd.m.Y H:i:s', 'd.m.Y'] as $format) {
            $d = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($d !== false && ! (\DateTimeImmutable::getLastErrors()['warning_count'] ?? 0)) {
                return CarbonImmutable::instance($d)->startOfDay();
            }
        }
        throw new InvalidArgumentException("can't read the date \"{$value}\"");
    }
}
