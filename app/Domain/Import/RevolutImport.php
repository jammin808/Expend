<?php

namespace App\Domain\Import;

use App\Domain\Audit\AuditLog;
use App\Domain\Fx\ExchangeRates;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\TaxYear;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Revolut statements, personal and Business. A Revolut account holds several currencies (and savings), each with its
 * own statement or its own rows in one file, so each currency goes into its own "pocket" account (Revolut EUR,
 * Revolut USD...) with its own running balance, checked in that currency. Which pocket is which is remembered on each
 * account, so a later statement (or a statement of one pocket on its own) lands where the earlier ones did, whichever
 * of the accounts you choose for it.
 *
 * Every line in another currency also gets the sterling amount it counts as for tax: the sterling side of a
 * currency exchange when it was bought with pounds, the bank's sterling figure when the file gives one, and
 * otherwise HMRC's monthly rate for that month, or the rate you set where HMRC has none (HMRC accepts a bank's rate
 * or its own published rates, used consistently: BIM39515). Exchanges between your own currencies are paired as
 * transfers, never spending, whichever of your accounts each side's statement went into. The currency bought counts as
 * the pounds exchanged without the fee; the fee stays with the transfer for now (its reason names it).
 *
 * Only completed rows count: pending, declined, failed and reverted ones are skipped and counted, and so are rows
 * that move nothing (£0.00).
 */
final class RevolutImport
{
    private const PERSONAL = ['type', 'product', 'started date', 'completed date', 'description', 'amount', 'fee', 'currency', 'state', 'balance'];

    private const PERSONAL_DE = ['art', 'produkt', 'datum des beginns', 'datum des abschlusses', 'beschreibung', 'betrag', 'gebühr', 'währung', 'status', 'kontostand'];

    private const DONE = ['COMPLETED', 'ABGESCHLOSSEN', 'DOKONČENO', 'TERMINÉ', 'COMPLETADO', 'COMPLETATO', 'VOLTOOID', 'ZAKOŃCZONO'];

    private const DATE_FORMATS = ['Y-m-d H:i:s', 'Y-m-d G:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd.m.Y H:i:s', 'd.m.Y'];

    /** Your answer when none of a Business statement's accounts is the main account itself. */
    public const NO_MAIN = 'none';

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
     * Read the file into pockets, without changing anything. $main is your answer to which of the statement's accounts
     * is the main account itself, when that isn't clear (a pocket's key, or NO_MAIN): 'main' says when it's asked.
     *
     * @return array{kind:string, root:Account, pockets:list<array{key:string, label:string, currency:string, account:?Account, adopt:bool, name:string, rows:list<NormalizedRow>, balance:array, first:?string, last:?string, new:int, duplicates:int, locked:int}>,
     *               skipped:array<string,int>, errors:list<string>, missing_rates:list<array{currency:string, month:string, label:string}>, exchanges:int,
     *               main:array{root:string, options:list<array{key:string, label:string}>, chosen:?string}|null}
     */
    public function read(Account $base, string $path, ?string $main = null): array
    {
        $csv = CsvReader::read($path);
        $kind = self::detect($csv['headers']) ?? throw new RuntimeException('This isn’t a Revolut statement.');
        ['lines' => $lines, 'skipped' => $skipped, 'errors' => $errors] = $kind === 'personal' ? $this->personal($csv['rows']) : $this->business($csv['headers'], $csv['rows']);

        // The account you chose may be one of the pockets: the main account and all its pockets are one family.
        $root = self::root($base);
        [$family, $depth] = self::family($root);

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
        // before: find it among this account's pockets, or another account of yours (same start time or ID, the other
        // currency, the opposite way).
        $paired = array_merge(...array_values($pairs ?: [[]]));
        $existing = [];
        foreach ($lines as $i => $l) {
            if ($l['exchange'] && ! in_array($i, $paired, true) && ($tx = $this->existingLeg($family, $l))) {
                $existing[$i] = $tx;
            }
        }
        // In an exchange between two other currencies, the second side counts as the first side's pounds (below).
        $second = [];
        foreach ($pairs as $ix) {
            if ($lines[$ix[0]]['currency'] !== 'GBP' && $lines[$ix[1]]['currency'] !== 'GBP') {
                $second[$ix[1]] = $ix[0];
            }
        }

        // Sterling for every line.
        $missing = [];
        foreach ($lines as $i => &$l) {
            if (isset($existing[$i])) {
                $l['exchange'] = 'db:'.$existing[$i]->id;
            }
            if ($l['currency'] === 'GBP') {
                $l['pence'] = $l['total'];
                $l['fee_pence'] = $l['fee'] ?? 0;

                continue;
            }
            if (isset($second[$i])) {
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
                    self::valueAgainst($l, $other['total'] + ($other['fee'] ?? 0));
                } elseif ($earlier && self::currencyOf($earlier->account) === 'GBP') {
                    // The pounds side was imported already: this side counts as exactly the pounds exchanged.
                    self::valueAgainst($l, $earlier->amount_pence + ($earlier->fee_minor ?? 0));
                } elseif ($l['orig_currency'] === 'GBP' && $l['orig_minor']) {
                    $l['pence'] = $l['total'] < 0 ? -abs($l['orig_minor']) : abs($l['orig_minor']);
                    $l['fx'] = ['source' => 'bank', 'date' => $l['date']->format('Y-m-d')];
                } else {
                    $c = $this->rates->toPence($l['total'], $l['currency'], $l['date']);
                    $l['pence'] = $c['pence'];
                    // HMRC's rate, or the one you set for a month HMRC has none for.
                    $l['fx'] = ['source' => $c['source'], 'date' => $c['rate_date'], 'units' => $c['units_per_gbp']];
                }
            } catch (RateUnavailable|ConnectionException $e) {
                $month = $l['date']->startOfMonth();
                $errors[] = $e instanceof RateUnavailable ? $e->getMessage()
                    : "Couldn’t reach HMRC for the {$l['currency']} rate for {$month->format('F Y')}. Try again, or enter the rate yourself.";
                $missing[$l['currency'].'|'.$month->format('Y-m')] = ['currency' => $l['currency'], 'month' => $month->format('Y-m'), 'label' => $month->format('F Y')];
                $l['pence'] = null;
            }
            $this->finish($l);
        }
        unset($l);
        foreach ($second as $b => $a) {
            if ($lines[$a]['pence'] === null) {
                $lines[$b]['pence'] = null; // the first side's missing rate is reported already

                continue;
            }
            self::valueAgainst($lines[$b], $lines[$a]['pence'] + $lines[$a]['fee_pence']);
            $this->finish($lines[$b]);
        }

        // Into pockets: one per currency (and savings), or per Business account.
        $pockets = [];
        foreach ($lines as $l) {
            $pockets[$l['pocket']] ??= ['key' => $l['pocket'], 'label' => $l['pocket_label'], 'currency' => $l['currency'], 'savings' => $l['savings'], 'lines' => []];
            $pockets[$l['pocket']]['lines'][] = $l;
        }
        [$route, $ask] = $this->route($root, $family, $depth, array_values($pockets), $main);
        $lockedYears = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $out = [];
        foreach ($pockets as $key => $p) {
            $account = $route[$key]['account'];
            $rows = $this->rows(array_values(array_filter($p['lines'], fn ($l) => $l['pence'] !== null)), $account);
            $dates = array_map(fn (NormalizedRow $r) => $r->effectiveDate()->format('Y-m-d'), $rows);
            sort($dates);
            $plan = $account ? $this->importer->planCounts($account, $rows) : ['new' => count($rows), 'duplicates' => 0];

            // Rows in a year you've locked (filed) aren't imported: say so, as for any other statement.
            $years = array_map(fn (NormalizedRow $r) => UkTaxYear::fromDate($r->effectiveDate())->label(), $rows);
            $inLocked = array_values(array_unique(array_intersect($years, $lockedYears)));
            $locked = $account ? count($rows) - $plan['new'] - $plan['duplicates'] : count(array_intersect($years, $lockedYears));
            if ($locked > 0) {
                sort($inLocked);
                $errors[] = "{$p['label']}: {$locked} rows fall in a locked tax year (".implode(', ', $inLocked).'). Unlock it first, or import a file without those dates.';
            }

            $out[] = ['key' => $key, 'label' => $p['label'], 'currency' => $p['currency'], 'account' => $account, 'adopt' => $route[$key]['adopt'],
                'name' => $account?->name ?? self::pocketName($root, $p['label']), 'rows' => $rows, 'balance' => BalanceCheck::run($rows),
                'first' => $dates[0] ?? null, 'last' => $dates ? end($dates) : null, 'locked' => max(0, $locked)] + $plan;
        }
        usort($out, fn ($a, $b) => [$a['account']?->id !== $root->id, $a['label']] <=> [$b['account']?->id !== $root->id, $b['label']]);

        return ['kind' => $kind, 'root' => $root, 'pockets' => $out, 'skipped' => $skipped, 'errors' => array_values(array_unique($errors)),
            'missing_rates' => array_values($missing), 'exchanges' => count($pairs), 'main' => $ask];
    }

    /**
     * Import it: each pocket into its account (made the first time), exchanges paired as transfers. With $mustChoose,
     * a statement whose main account isn't clear is only imported once you've said which it is ($main).
     *
     * @return list<ImportBatch>
     */
    public function commit(Account $base, string $path, string $originalName, ?string $main = null, bool $mustChoose = false): array
    {
        $read = $this->read($base, $path, $main);
        if ($read['errors']) {
            throw new RuntimeException('Fix these first: '.implode(' ', array_slice($read['errors'], 0, 5)));
        }
        if ($mustChoose && $read['main'] && $read['main']['chosen'] === null) {
            throw new RuntimeException("Choose which of this statement’s accounts is {$read['main']['root']} itself first.");
        }

        $batches = DB::transaction(function () use ($read, $path, $originalName) {
            $batches = [];
            foreach ($read['pockets'] as $p) {
                if (! $p['rows']) {
                    continue;
                }
                $account = $p['account'] ?? $this->makePocket($read['root'], $p);
                if ($p['adopt']) {
                    $this->adopt($account, $p['currency']);
                }
                $batch = $this->importer->store($account, $p['rows'], $path, $originalName,
                    ['preset' => 'revolut_'.$read['kind'], 'pocket' => $p['key']], $p['balance'], $p['first'], $p['last']);
                $batches[] = ['batch' => $batch, 'account' => $account, 'rows' => $p['rows']];
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
                $balance = $this->minor($raw['Balance'], $currency);
            } catch (InvalidArgumentException $e) {
                $errors[] = 'Line '.($n + 2).': '.$e->getMessage().'.';

                continue;
            }
            $total = $amount - abs($fee);
            if ($total === 0) {
                $skipped['ZERO'] = ($skipped['ZERO'] ?? 0) + 1; // moves nothing: a card check, an opening entry

                continue;
            }
            $product = trim($raw['Product']);
            $savings = $product !== '' && ! in_array(mb_strtolower($product), ['current', 'aktuell', 'aktuální', 'courant', 'corriente', 'corrente'], true);
            $lines[] = [
                'pocket' => $currency.($savings ? '|'.mb_strtolower($product) : ''), 'pocket_label' => $currency.($savings ? ' '.mb_strtolower($product) : ''), 'savings' => $savings,
                'currency' => $currency, 'total' => $total, 'fee' => $fee ? abs($fee) : null, 'balance' => $balance,
                'posted' => $completed, 'date' => $started, 'description' => trim($raw['Description']) ?: trim($raw['Type']), 'counterparty' => null,
                'reference' => null, 'orig_currency' => null, 'orig_minor' => null, 'raw' => $raw, 'line' => $n + 2,
                'exchange' => preg_match('/^exchange$/i', trim($raw['Type'])) ? 'x:'.$raw['Started Date'].'|'.$product : null,
                'is_exchange' => (bool) preg_match('/^exchange$/i', trim($raw['Type'])),
                'bank_category' => self::bankCategory($raw['Type']),
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
        $zones = self::dateColumns($headers);
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
                $feeCurrency = strtoupper($get('fee currency'));
                $fee = $feeCurrency === '' || $feeCurrency === $currency ? $this->minor($get('fee'), $currency) : null;
                $total = $get('total amount') !== ''
                    ? $this->minor($get('total amount'), $currency)
                    : ($this->minor($get('amount'), $currency) ?? throw new InvalidArgumentException('no amount')) - abs($fee ?? 0);
                $total ?? throw new InvalidArgumentException('no amount');
                $started = self::businessDate($get, $zones['started']) ?? throw new InvalidArgumentException('no date');
                $completed = self::businessDate($get, $zones['completed']) ?? $started;
                $completed = $completed->lessThan($started) ? $started : $completed;
                $balance = $this->minor($get('balance'), $currency);
                $utc = self::utcDates($get);
            } catch (InvalidArgumentException $e) {
                $errors[] = 'Line '.($n + 2).': '.$e->getMessage().'.';

                continue;
            }
            if ($total === 0) {
                $skipped['ZERO'] = ($skipped['ZERO'] ?? 0) + 1;

                continue;
            }
            $origCurrency = strtoupper($get('orig currency')) ?: null;
            $origCurrency = $origCurrency && $origCurrency !== $currency ? $origCurrency : null;
            try {
                // Only what it was in the shop's currency: one odd figure here never stops the statement.
                $origMinor = $origCurrency ? $this->minor($get('orig amount'), $origCurrency) : null;
            } catch (InvalidArgumentException) {
                $origMinor = null;
            }
            $account = $get('account') ?: $currency;
            $type = $get('type');
            $lines[] = [
                'pocket' => 'acct|'.$account, 'pocket_label' => $account, 'savings' => false, 'currency' => $currency, 'total' => $total,
                'fee' => $fee ? abs($fee) : null, 'balance' => $balance,
                'posted' => $completed, 'date' => $started, 'description' => $get('description') ?: $type,
                'counterparty' => $get('beneficiary name') ?: null, 'reference' => $get('reference') ?: null,
                'orig_currency' => $origMinor !== null ? $origCurrency : null, 'orig_minor' => $origMinor,
                'raw' => $row, 'line' => $n + 2,
                'exchange' => strtoupper($type) === 'EXCHANGE' && $get('id') !== '' ? 'id:'.$get('id') : null,
                'is_exchange' => strtoupper($type) === 'EXCHANGE',
                'bank_category' => self::bankCategory($type),
                'utc' => $utc,
            ];
        }

        return ['lines' => $lines, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Revolut's Type, kept as the bank's category only where it says what the line is: its own fees ("Revolut
     * Business Fee", a plan fee) are a bank charge, never money moving to a pocket. The other types (card payment,
     * transfer, top-up, exchange) only say how the money moved, which the line's payment type already shows.
     */
    private static function bankCategory(string $type): ?string
    {
        return preg_match('/^\s*fees?\s*$/i', $type) ? 'FEE' : null;
    }

    /**
     * The dates a Business row was imported under before its UK date was used: the plain dates of its UTC times (a
     * payment at 00:30 UK time in summer was dated the day before). Null when they couldn't be read then either.
     *
     * @return array{posted:CarbonImmutable, started:CarbonImmutable}|null
     */
    private static function utcDates(callable $get): ?array
    {
        try {
            return ['posted' => self::when($get('date completed (utc)') ?: $get('date started (utc)')), 'started' => self::when($get('date started (utc)'))];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A pocket's rows. A Business row already imported under its UTC date (before UK dates were used) is the same
     * row, so it keeps that date here and counts as already in, rather than being imported a second time a day later.
     *
     * @param  list<array>  $lines
     * @return list<NormalizedRow>
     */
    private function rows(array $lines, ?Account $account): array
    {
        $rows = [];
        $taken = [];
        foreach ($lines as $l) {
            $row = $this->row($l);
            $utc = $l['utc'] ?? null;
            if ($account && $utc && $utc['posted']->format('Y-m-d') !== $row->postedOn->format('Y-m-d')) {
                $before = $this->row(['posted' => $utc['posted'], 'date' => $utc['started']] + $l);
                $fp = $before->fingerprint($account->id);
                $n = ($taken[$fp] ?? 0) + 1;
                if (BankTransaction::query()->where('account_id', $account->id)->where('fingerprint', $fp)->where('occurrence', $n)->exists()) {
                    $taken[$fp] = $n;
                    $row = $before;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function row(array $l): NormalizedRow
    {
        $foreign = $l['currency'] !== 'GBP';
        $origMinor = $l['orig_minor'] !== null ? ($l['total'] < 0 ? -abs($l['orig_minor']) : abs($l['orig_minor'])) : null;
        // Paid in a third currency from an account in another one ($90.00 from the euro account): keep the dollars.
        $charged = $foreign && $origMinor && $l['orig_currency'] && ! $l['is_exchange'];

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
            bankCategory: $l['bank_category'] ?? null,
            raw: $l['raw'],
            line: $l['line'],
            nativeAmountMinor: $foreign ? $l['total'] : null,
            nativeBalanceMinor: $foreign ? $l['balance'] : null,
            fxUnitsPerGbp: $foreign ? ($l['fx']['units'] ?? null) : null,
            fxRateDate: $foreign ? ($l['fx']['date'] ?? null) : null,
            fxSource: $foreign ? ($l['fx']['source'] ?? null) : null,
            feeMinor: $l['fee'],
            exchangeKey: $l['exchange'],
            merchantAmountMinor: $charged ? $origMinor : null,
            merchantCurrency: $charged ? $l['orig_currency'] : null,
            feePence: $l['fee_pence'] ?? null,
        );
    }

    /**
     * One side of an exchange counts as the pounds exchanged on the other side (without that side's fee), less its
     * own fee: the fee is a bank charge, not money moved between your accounts.
     */
    private static function valueAgainst(array &$l, int $exchanged): void
    {
        $gross = $l['total'] + ($l['fee'] ?? 0);
        $fee = $l['fee'] && $gross ? (int) round($l['fee'] * abs($exchanged) / abs($gross)) : 0;
        $pence = -$exchanged - $fee;
        if ($pence === 0 || ($pence < 0) !== ($l['total'] < 0)) {
            [$fee, $pence] = [0, -$exchanged]; // a fee that big can't be right: count the whole line as exchanged
        }
        $l['pence'] = $pence;
        $l['fee_pence'] = $fee;
        $l['fx'] = ['source' => 'exchange', 'date' => $l['date']->format('Y-m-d')];
    }

    /** A tiny amount still moves money; the fee in pounds; the rate the sterling figure works out at. */
    private function finish(array &$l): void
    {
        if ($l['pence'] === null) {
            return;
        }
        if ($l['pence'] === 0) {
            $l['pence'] = $l['total'] < 0 ? -1 : 1;
        }
        $l['fee_pence'] ??= $l['fee'] ? (int) round($l['fee'] * abs($l['pence']) / abs($l['total'])) : 0;
        if (! isset($l['fx']['units'])) {
            $l['fx']['units'] = number_format(abs($this->major($l['total'], $l['currency'])) / abs($l['pence'] / 100), 6, '.', '');
        }
    }

    // ---------------------------------------------------------------- pockets

    /** The main account a pocket belongs to (the account itself when it isn't a pocket). */
    private static function root(Account $account): Account
    {
        $seen = [];
        while ($account->parent_account_id && ! isset($seen[$account->id]) && ($parent = Account::query()->find($account->parent_account_id))) {
            $seen[$account->id] = true;
            $account = $parent;
        }

        return $account;
    }

    /** @return array{0: Collection<int, Account>, 1: array<int, int>} the main account and every pocket under it, and how deep each is */
    private static function family(Account $root): array
    {
        $family = collect([$root->id => $root]);
        $depth = [$root->id => 0];
        $frontier = [$root->id];
        for ($level = 1; $frontier && $level <= 4; $level++) {
            $kids = Account::query()->whereIn('parent_account_id', $frontier)->whereNotIn('id', $family->keys())->orderBy('id')->get();
            foreach ($kids as $kid) {
                $family[$kid->id] = $kid;
                $depth[$kid->id] = $level;
            }
            $frontier = $kids->pluck('id')->all();
        }

        return [$family, $depth];
    }

    /**
     * Which account each pocket goes into. Each account remembers the pocket it holds, so a pocket goes where it went
     * before. Only a main account that doesn't remember one yet takes a pocket by guessing: its currency's main (not
     * savings) pocket, and only when that's clear. When it isn't (a Business statement with several accounts in that
     * currency, none called Main or Current), you're asked which it is ($main). Every other pocket gets an account of
     * its own under the main one.
     *
     * @return array{0: array<string, array{account:?Account, adopt:bool}>, 1: array{root:string, options:list<array{key:string, label:string}>, chosen:?string}|null}
     */
    private function route(Account $root, Collection $family, array $depth, array $pockets, ?string $main): array
    {
        // Where two accounts claim the same pocket (an earlier import could move the main account's memory), a pocket
        // directly under the main account wins, then the main account, then anything deeper.
        $rank = fn (Account $a) => [$depth[$a->id] === 1 ? 0 : ($depth[$a->id] === 0 ? 1 : $depth[$a->id]), $a->id];
        $claims = [];
        foreach ($family as $account) {
            $key = $account->import_profile['pocket'] ?? null;
            if (is_string($key) && $key !== '' && (! isset($claims[$key]) || $rank($account) < $rank($claims[$key]))) {
                $claims[$key] = $account;
            }
        }
        $out = [];
        foreach ($pockets as $p) {
            $out[$p['key']] = ['account' => $claims[$p['key']] ?? null, 'adopt' => false];
        }
        $remembered = $root->import_profile['pocket'] ?? null;
        if (is_string($remembered) && $remembered !== '' && ($claims[$remembered] ?? null)?->id === $root->id) {
            return [$out, null];
        }
        ['pick' => $pick, 'options' => $options] = $this->guessMain($root, $family, array_values(array_filter($pockets, fn ($p) => ! isset($claims[$p['key']]))));
        $ask = null;
        if (! $pick && $options) {
            $chosen = $main === self::NO_MAIN ? $main : null;
            foreach ($options as $p) {
                if ($p['key'] === $main) {
                    [$pick, $chosen] = [$p, $main];
                }
            }
            $ask = ['root' => $root->name, 'options' => array_map(fn ($p) => ['key' => $p['key'], 'label' => $p['label']], $options), 'chosen' => $chosen];
        }
        if ($pick) {
            $out[$pick['key']] = ['account' => $root, 'adopt' => self::currencyOf($root) !== $pick['currency']];
        }

        return [$out, $ask];
    }

    /**
     * The pocket that belongs in a main account that doesn't remember one yet, when that's clear; otherwise the
     * pockets it could be, for you to choose from (none when it's clearly none of them).
     *
     * @return array{pick:?array, options:list<array>}
     */
    private function guessMain(Account $root, Collection $family, array $free): array
    {
        $current = array_values(array_filter($free, fn ($p) => ! $p['savings']));
        $same = array_values(array_filter($current, fn ($p) => $p['currency'] === self::currencyOf($root)));
        if (count($same) === 1) {
            return ['pick' => $same[0], 'options' => []];
        }
        if (count($same) > 1) {
            // Several Business accounts in this currency: only one called "Main" or "Current" is clearly the main one.
            // Otherwise you choose: guessed wrong, the rows would sit in the wrong account for good.
            $named = array_values(array_filter($same, fn ($p) => preg_match('/\b(main|current)\b/i', $p['label'])));

            return count($named) === 1 ? ['pick' => $named[0], 'options' => []] : ['pick' => null, 'options' => $same];
        }
        // None in its currency: an account added for one currency's statement ("Revolut EUR": an account can't be
        // given a currency when it's added) takes that currency, as long as it holds nothing yet. Any other account
        // keeps its currency, and the statement goes into a pocket of it.
        if (count($current) !== 1 || ! self::namesCurrency($root->name, $current[0]['currency'])) {
            return ['pick' => null, 'options' => []];
        }
        $empty = $family->count() === 1 && ! BankTransaction::withoutGlobalScope('active')->where('account_id', $root->id)->exists();

        return ['pick' => $empty ? $current[0] : null, 'options' => []];
    }

    /** "Revolut EUR", "Revolut euros", "Dollar account": the account's name says which currency it's for. */
    private static function namesCurrency(string $name, string $currency): bool
    {
        $words = ['EUR' => 'euros?', 'USD' => 'dollars?'][$currency] ?? null;

        return (bool) preg_match('/\b'.preg_quote($currency, '/').'\b'.($words ? '|\b(?:'.$words.')\b' : '').'/i', $name);
    }

    private static function currencyOf(?Account $account): string
    {
        return strtoupper($account?->currency ?: 'GBP');
    }

    private function adopt(Account $account, string $currency): void
    {
        $before = $account->currency;
        $account->forceFill(['currency' => $currency])->save();
        AuditLog::record('account.currency_set', $account, ['currency' => $before], ['currency' => $currency],
            "Its first Revolut statement is in {$currency}, so the account is kept in {$currency}");
    }

    private function makePocket(Account $root, array $p): Account
    {
        $account = Account::query()->create(['name' => self::pocketName($root, $p['label']), 'kind' => 'bank', 'provider' => $root->provider ?: 'revolut',
            'currency' => $p['currency'], 'parent_account_id' => $root->id, 'import_profile' => ['pocket' => $p['key']], 'is_active' => true]);
        AuditLog::record('account.created', $account, null, $account->toArray(), 'A '.$p['currency'].' pocket of '.$root->name.' in a Revolut statement');

        return $account;
    }

    private static function pocketName(Account $root, string $label): string
    {
        $label = preg_replace('/^(GBP|EUR|USD|[A-Z]{3}) Main$/', '$1', $label);

        return mb_substr($root->name.' '.$label, 0, 80);
    }

    /**
     * The other side of an exchange, already imported: same start time (or Revolut Business ID), the other currency,
     * money moving the other way, not yet paired (or paired only with a line since discarded or undone). Looked for in
     * this account and its pockets first, then in your other accounts: a currency's statement may have gone into an
     * account added for it ("Revolut EUR") rather than a pocket of this one. A line elsewhere whose own other side is
     * already imported there (the same exchange imported into two accounts) is left alone.
     */
    private function existingLeg(Collection $family, array $l): ?BankTransaction
    {
        $started = (string) ($l['raw']['Started Date'] ?? '');
        $id = (string) ($l['raw']['ID'] ?? '');
        if ($started === '' && $id === '') {
            return null;
        }
        $same = fn (BankTransaction $t) => preg_match('/^exchange$/i', (string) ($t->raw['Type'] ?? ''))
            && (($started !== '' && ($t->raw['Started Date'] ?? null) === $started) || ($id !== '' && ($t->raw['ID'] ?? null) === $id));
        // Lines a few days either side, money out or in, in this account's family or outside it.
        $near = function (bool $out, bool $ours) use ($family, $l): Collection {
            $q = BankTransaction::query()->with('account')->where('amount_pence', $out ? '<' : '>', 0)
                ->whereBetween('posted_on', [$l['posted']->subDays(4)->format('Y-m-d'), $l['posted']->addDays(4)->format('Y-m-d')]);
            $ours ? $q->whereIn('account_id', $family->keys()->all()) : $q->whereNotIn('account_id', $family->keys()->all());

            return $q->orderBy('id')->get();
        };
        $other = fn (BankTransaction $t) => $same($t) && self::currencyOf($t->account) !== $l['currency'] && self::free($t);
        $out = $l['total'] < 0;

        if ($found = $near(! $out, true)->first($other)) {
            return $found;
        }
        $found = $near(! $out, false)->first($other);
        $twin = $found && $near($out, false)->contains(fn (BankTransaction $t) => $same($t) && self::currencyOf($t->account) === $l['currency']);

        return $twin ? null : $found;
    }

    /** Not paired, or paired only with a line that no longer counts (its statement was discarded or undone). */
    private static function free(BankTransaction $tx): bool
    {
        return ! $tx->transfer_pair_id
            || ! BankTransaction::withoutGlobalScope('active')->whereKey($tx->transfer_pair_id)->where('state', '!=', 'reversed')->exists();
    }

    /** @param list<array{batch:ImportBatch, account:Account, rows:list<NormalizedRow>}> $batches */
    private function pairExchanges(array $batches): void
    {
        $found = [];
        $fees = [];
        foreach ($batches as $b) {
            foreach ($b['rows'] as $row) {
                if (! $row->exchangeKey) {
                    continue;
                }
                $tx = BankTransaction::withoutGlobalScope('active')->where('account_id', $b['account']->id)->where('fingerprint', $row->fingerprint($b['account']->id))
                    ->where('state', '!=', 'reversed')->orderByDesc('id')->first();
                if ($tx && self::free($tx)) {
                    $found[$row->exchangeKey][] = $tx;
                    $fees[$tx->id] = $row->feePence;
                    if (str_starts_with($row->exchangeKey, 'db:') && ($earlier = BankTransaction::query()->find((int) substr($row->exchangeKey, 3))) && self::free($earlier)) {
                        $found[$row->exchangeKey][] = $earlier;
                    }
                }
            }
        }
        foreach ($found as $key => $txs) {
            // Two sides of the same exchange. Within one file they count as the same pounds, apart from the fees;
            // across files the side imported first may have been converted at HMRC's rate, so the pounds can differ.
            $sameFile = ! str_starts_with((string) $key, 'db:');
            if (count($txs) !== 2 || $txs[0]->account_id === $txs[1]->account_id || ($txs[0]->amount_pence < 0) === ($txs[1]->amount_pence < 0)) {
                continue;
            }
            [$out, $in] = $txs[0]->amount_pence < 0 ? $txs : [$txs[1], $txs[0]];
            $fee = max(0, ($fees[$out->id] ?? self::feeOf($out)) + ($fees[$in->id] ?? self::feeOf($in)));
            if ($sameFile && $out->amount_pence + $in->amount_pence + $fee !== 0) {
                continue;
            }
            foreach ([[$out, $in], [$in, $out]] as [$tx, $other]) {
                $before = $tx->transfer_pair_id;
                $tx->forceFill(['transfer_pair_id' => $other->id])->saveQuietly();
                AuditLog::record('transfer.paired', $tx, $before ? ['transfer_pair_id' => $before] : null, ['transfer_pair_id' => $other->id],
                    'The two sides of one currency exchange'.($before ? ' (its earlier other side no longer counts)' : ''), 'matcher');
            }
            $this->suggestExchange($out, $in, $fee);
        }
    }

    /**
     * Suggest both sides of an exchange as a transfer: no money exchanged counts as spending or income. The fee is
     * split off the side sold as a bank charge, for you to check (a pair stays a pair with its fee split off, see
     * TransferPairer::movesMoney); the side bought is wholly a transfer, so confirming it, or the pair in one go,
     * never confirms the fee unseen. Held statement rows are suggested when the statement is accepted.
     */
    public function suggestExchange(BankTransaction $out, BankTransaction $in, int $fee): void
    {
        $finance = $fee > 0 && $fee < abs($out->amount_pence) ? Category::query()->where('code', 'finance')->value('id') : null;
        $why = 'Currency exchange: '.self::own($out).' to '.self::own($in).', between your own pockets'
            .($fee > 0 ? ($finance ? '; its '.Money::format($fee, 'GBP', false).' fee is a bank charge: check it was for the business' : ' ('.Money::format($fee, 'GBP', false).' of it is the exchange fee, a bank charge)') : '');
        foreach ([$out, $in] as $tx) {
            if ($tx->state !== 'active') {
                continue;
            }
            $lines = $tx->is($out) && $finance
                ? [...AllocationWriter::lines($out->amount_pence + $fee, 'transfer'), ...AllocationWriter::lines(-$fee, 'business_expense', (int) $finance, note: 'The exchange fee')]
                : AllocationWriter::lines($tx->amount_pence, 'transfer');
            try {
                $this->writer->decide($tx, $lines, 'matcher', false, $why, null, new Provenance('revolut.exchange', ['tx:'.$out->id, 'tx:'.$in->id]));
            } catch (InvalidArgumentException) {
                // a locked year, or decided by you
            }
        }
    }

    /** The fee of an exchange already paired (its two sides imported), in pounds: for suggesting it again. */
    public static function exchangeFee(BankTransaction $out, BankTransaction $in): int
    {
        return max(0, self::feeOf($out) + self::feeOf($in));
    }

    /** A line's amount in its own account's currency: "£100.00", "€115.20". */
    private static function own(BankTransaction $tx): string
    {
        $currency = self::currencyOf(Account::query()->find($tx->account_id));

        return $currency === 'GBP' || $tx->original_amount_minor === null
            ? Money::format(abs($tx->amount_pence), 'GBP', false)
            : Money::formatMinor(abs($tx->original_amount_minor), $currency, false);
    }

    /** The fee on a line imported before, in pounds. */
    private static function feeOf(BankTransaction $tx): int
    {
        if (! $tx->fee_minor) {
            return 0;
        }
        if (self::currencyOf(Account::query()->find($tx->account_id)) === 'GBP') {
            return abs($tx->fee_minor);
        }

        return $tx->original_amount_minor ? (int) round(abs($tx->fee_minor) * abs($tx->amount_pence) / abs($tx->original_amount_minor)) : 0;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * An amount in the currency's own minor units (pence, cents; yen have none, dinars have three). Written with more
     * decimal places than the currency has, it isn't guessed at.
     */
    private function minor(string $value, string $currency): ?int
    {
        $v = trim(str_replace(["'", ' ', "\u{00A0}"], '', $value));
        if ($v === '') {
            return null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $v, $m)) {
            [$negative, $v] = [true, $m[1]];
        }
        if (str_starts_with($v, '+')) {
            $v = substr($v, 1);
        } elseif (str_starts_with($v, '-') || str_starts_with($v, "\u{2212}")) {
            $negative = ! $negative;
            $v = substr($v, str_starts_with($v, '-') ? 1 : strlen("\u{2212}"));
        }
        if (! preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?$/', $v, $m)) {
            throw new InvalidArgumentException("can't read the amount \"{$value}\"");
        }
        try {
            $minor = BigDecimal::of(str_replace(',', '', $m[1]).(isset($m[2]) ? '.'.$m[2] : ''))->toScale(Money::digits($currency))->getUnscaledValue()->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException("can't read the amount \"{$value}\" in {$currency}");
        }

        return $negative ? -$minor : $minor;
    }

    private function major(int $minor, string $currency): float
    {
        return $minor / (10 ** Money::digits($currency));
    }

    /**
     * A Business statement's date columns: "Date started (UTC)" first, then any in another time zone (Revolut adds
     * local-time columns once you choose a time zone).
     *
     * @return array{started: list<array{0:string, 1:string}>, completed: list<array{0:string, 1:string}>}
     */
    private static function dateColumns(array $headers): array
    {
        $out = ['started' => [], 'completed' => []];
        foreach ($headers as $header) {
            $name = mb_strtolower(trim((string) $header));
            if (! preg_match('/^date (started|completed) \((.+)\)$/', $name, $m)) {
                continue;
            }
            try {
                $zone = (new DateTimeZone($m[2]))->getName();
            } catch (\Throwable) {
                continue;
            }
            if ($zone === 'UTC') {
                array_unshift($out[$m[1]], [$name, $zone]);
            } else {
                $out[$m[1]][] = [$name, $zone];
            }
        }

        return $out;
    }

    /**
     * The UK date a Business row started or completed on. The time decides it: 23:30 UTC on 5 April is 00:30 on 6
     * April in the UK, in the next tax year. A value with no time keeps its date; one in UK time is preferred then.
     */
    private static function businessDate(callable $get, array $columns): ?CarbonImmutable
    {
        $dateOnly = null;
        foreach ($columns as [$name, $zone]) {
            $value = $get($name);
            if ($value === '') {
                continue;
            }
            $date = self::when($value, $zone);
            if (preg_match('/\d:\d{2}/', $value) || $zone === 'Europe/London') {
                return $date;
            }
            $dateOnly ??= $date;
        }

        return $dateOnly;
    }

    /** A date, as the UK date it falls on when a time zone is given and the value has a time. */
    private static function when(string $value, ?string $zone = null): CarbonImmutable
    {
        $value = trim($value);
        foreach (self::DATE_FORMATS as $format) {
            $d = DateTimeImmutable::createFromFormat('!'.$format, $value, $zone ? new DateTimeZone($zone) : null);
            if ($d !== false && ! (DateTimeImmutable::getLastErrors()['warning_count'] ?? 0)) {
                if ($zone && str_contains($format, ':')) {
                    $d = $d->setTimezone(new DateTimeZone('Europe/London'));
                }

                return CarbonImmutable::createFromFormat('!Y-m-d', $d->format('Y-m-d'));
            }
        }
        throw new InvalidArgumentException("can't read the date \"{$value}\"");
    }
}
