<?php

namespace App\Domain\Income;

use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who pays you for whom, worked out from what you decided, so no table of payer names is kept (it replaces the docs'
 * M10 payer_aliases): "ACME PAYROLL pays for Acme Events (3 payments)".
 *
 * It counts confirmed money in that's business income, on active statements, by the payer (the line's merchant_key)
 * and the line's worked-out client (Income\IncomeClient: its invoice's client, else its job's, else its own), each
 * payment once. A line counts when you made it, whatever says who the client is; or when you accepted it (you
 * confirmed it, or an older matcher or AI line confirmed before who confirmed was kept, as Categoriser::weight counts
 * it) and its client comes from its invoice or job, never one stored on the line: a client that automation filled in
 * from these very counts (enrich()) and you merely accepted teaches nothing new. Rule-confirmed lines, waiting
 * suggestions, money paid back, voided lines and "UNKNOWN" payers don't count.
 *
 * A payer who has paid for more than one client is shared: sole() then names no client, however few payments the
 * others have. One query loads a whole page's payers (forKeys()).
 */
final class PayerAliases
{
    /** @param  array<string, array<int, array{n:int, latest:int}>>  $rows  by payer, then client (most payments first) */
    private function __construct(private array $rows) {}

    /**
     * Everything about these payers, in one grouped query.
     *
     * @param  iterable<?string>  $keys
     */
    public static function forKeys(iterable $keys): self
    {
        $keys = collect($keys)->filter(fn ($k) => is_string($k) && $k !== '' && $k !== 'UNKNOWN')->unique()->values();
        $rows = [];
        foreach ($keys->chunk(500) as $chunk) {
            foreach (self::counted()->whereIn('pa_tx.merchant_key', $chunk->values()->all())->get() as $row) {
                $rows[(string) $row->merchant_key][(int) $row->{IncomeClient::COLUMN}] = ['n' => (int) $row->n, 'latest' => (int) $row->latest];
            }
        }
        foreach ($rows as $key => $clients) {
            uasort($clients, fn ($a, $b) => [$b['n'], $b['latest']] <=> [$a['n'], $a['latest']]);
            $rows[$key] = $clients;
        }

        return new self($rows);
    }

    /** One payer's sole client (see sole()), in one query. */
    public static function soleClient(?string $key, int $min = 1): ?int
    {
        return $key === null ? null : self::forKeys([$key])->sole($key, $min);
    }

    /**
     * The one client this payer pays for, when you've confirmed at least $min of its payments for that client; null
     * when it has paid for more than one client (shared always wins: $min only sets how much evidence one client needs),
     * or for none.
     */
    public function sole(?string $key, int $min = 1): ?int
    {
        $clients = $key === null ? [] : ($this->rows[$key] ?? []);
        if (count($clients) !== 1) {
            return null;
        }
        $client = array_key_first($clients);

        return $clients[$client]['n'] >= max(1, $min) ? $client : null;
    }

    /** Whether this payer has paid for more than one client. */
    public function shared(?string $key): bool
    {
        return $key !== null && count($this->rows[$key] ?? []) > 1;
    }

    /**
     * The clients this payer has paid for, most payments first.
     *
     * @return array<int, array{n:int, latest:int}> by client id: how many payments, and the latest one's id
     */
    public function clients(?string $key): array
    {
        return $key === null ? [] : ($this->rows[$key] ?? []);
    }

    /** How many of this payer's payments you confirmed for this client. */
    public function count(?string $key, int $clientId): int
    {
        return $this->clients($key)[$clientId]['n'] ?? 0;
    }

    /**
     * The payers that pay for this client (merged duplicates included), most payments first: "Pays you as ACME
     * PAYROLL (5 payments)".
     *
     * @return array<string, int> payments by payer
     */
    public static function keysFor(int $clientId): array
    {
        $client = IncomeClient::resolve($clientId);
        if ($client === null) {
            return [];
        }
        $out = [];
        foreach (self::counted()->whereRaw(IncomeClient::expression().' = ?', [$client])->get() as $row) {
            $out[(string) $row->merchant_key] = (int) $row->n;
        }
        arsort($out);

        return $out;
    }

    /**
     * Fill in the client on automation's income lines from who the payer pays for: job-less, invoice-less business
     * income lines with no client get the payer's sole client (one payment is enough), and the reason says so
     * (" · for Acme Events (from your payments)") with the latest counted payment as evidence. Nothing changes when the
     * payer pays for several clients or none, or on money out. The writer stores such lines as a suggestion only (owner
     * answer 3): you accept them.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, note: ?string, evidence: list<string>, client_id: ?int}
     */
    public static function enrich(BankTransaction $tx, array $lines, ?self $aliases = null): array
    {
        $none = ['lines' => $lines, 'note' => null, 'evidence' => [], 'client_id' => null];
        if ($tx->amount_pence <= 0) {
            return $none;
        }
        $open = array_filter($lines, fn (array $l) => ($l['kind'] ?? null) === 'business_income' && ($l['work_job_id'] ?? null) === null
            && ($l['invoice_id'] ?? null) === null && ($l['client_id'] ?? null) === null);
        if ($open === []) {
            return $none;
        }
        $aliases ??= self::forKeys([$tx->merchant_key]);
        $client = $aliases->sole($tx->merchant_key, 1);
        $name = $client === null ? null : Client::query()->whereKey($client)->value('name');
        if ($name === null) {
            return $none;
        }
        foreach (array_keys($open) as $i) {
            $lines[$i]['client_id'] = $client;
        }

        return [
            'lines' => $lines,
            'note' => ' · for '.$name.' (from your payments)',
            'evidence' => ['tx:'.$aliases->clients($tx->merchant_key)[$client]['latest']],
            'client_id' => $client,
        ];
    }

    /**
     * The counted lines, grouped by payer and worked-out client: merchant_key, client_id_worked, n (payments) and
     * latest (the latest payment's id).
     */
    private static function counted(): Builder
    {
        $query = Allocation::query()->select('pa_tx.merchant_key')
            ->join('bank_transactions as pa_tx', 'pa_tx.id', '=', 'allocations.bank_transaction_id')
            ->where('pa_tx.state', 'active')->where('pa_tx.merchant_key', '!=', 'UNKNOWN')
            ->where('allocations.kind', 'business_income')->where('allocations.amount_pence', '>', 0)->whereNotNull('allocations.confirmed_at')
            ->where(fn ($q) => $q->where('allocations.decided_by', 'you')
                ->orWhere(fn ($q) => $q->whereNull('allocations.client_id')->where(fn ($q) => $q->where('allocations.confirmed_by', 'you')
                    ->orWhere(fn ($q) => $q->whereNull('allocations.confirmed_by')->whereIn('allocations.decided_by', ['matcher', 'ai'])))));
        IncomeClient::scope($query);

        return $query->whereRaw(IncomeClient::expression().' IS NOT NULL')
            ->addSelect(DB::raw('COUNT(DISTINCT pa_tx.id) as n'), DB::raw('MAX(pa_tx.id) as latest'))
            ->groupBy('pa_tx.merchant_key')->groupByRaw(IncomeClient::expression());
    }
}
