<?php

namespace App\Domain\Ledger;

use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\ManualExpense;

/**
 * A payment's decision as it stands: what each line is (kind, category, job, invoice, amount, business share, and its
 * own client when it has one), who made it, and whether it's confirmed, with the line's review status and its transfer
 * pair. Exactly the state a to-do
 * button on a payment is tied to (Todo\Fingerprints, 'tx_accept'), so a button, the invoice's "Confirm and issue" and
 * "Shared with another job" all refuse a payment that changed since it was shown.
 *
 * For a bank line the state and its fingerprint are the ones Fingerprints has always worked out, so a button offered
 * before keeps its hash. A manual expense has the same state (it has no transfer pair), tied under its own code.
 */
final class SourceState
{
    /** The fingerprint's length, as a to-do button's (Fingerprints::LENGTH). */
    public const LENGTH = 6;

    /** The action a bank line's fingerprint is worked out for (Fingerprints' 'tx_accept'). */
    public const TX_CODE = 'tx_accept';

    /** The same for a manual expense, which has no to-do button of its own. */
    public const EXP_CODE = 'exp_accept';

    /**
     * The decision as it stands, or null when the payment has gone (a held or undone statement's line).
     *
     * @return array{status:string, pair:?int, lines:list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:?int, 6:string, 7:bool, 8?:int}>}|null
     */
    public static function of(BankTransaction|ManualExpense $source): ?array
    {
        return $source instanceof BankTransaction ? self::ofTransaction((int) $source->getKey()) : self::ofExpense((int) $source->getKey());
    }

    /** A bank line's decision: the state Fingerprints ties its buttons to (unchanged for lines with no client of their own). */
    public static function ofTransaction(int $id): ?array
    {
        $tx = BankTransaction::query()->find($id, ['id', 'review_status', 'transfer_pair_id']);
        if (! $tx) {
            return null;
        }

        return ['status' => $tx->review_status, 'pair' => $tx->transfer_pair_id, 'lines' => self::lines('bank_transaction_id', $id)];
    }

    /** A manual expense's decision, in the same shape (no pair). */
    public static function ofExpense(int $id): ?array
    {
        $expense = ManualExpense::query()->find($id, ['id', 'review_status']);
        if (! $expense) {
            return null;
        }

        return ['status' => $expense->review_status, 'pair' => null, 'lines' => self::lines('manual_expense_id', $id)];
    }

    /**
     * The fingerprint of the payment's decision now: for a bank line, the one its to-do button carries
     * (Fingerprints::of('tx_accept', id)). Something gone has one too, so a request made on it never matches anything else.
     */
    public static function fingerprint(BankTransaction|ManualExpense $source): string
    {
        $code = $source instanceof BankTransaction ? self::TX_CODE : self::EXP_CODE;
        $id = (string) $source->getKey();

        return substr(hash('sha256', json_encode([$code, $id, self::of($source)])), 0, self::LENGTH);
    }

    /** Whether a fingerprint sent with a request is the payment's decision as it stands now. */
    public static function matches(BankTransaction|ManualExpense $source, ?string $fp): bool
    {
        return $fp !== null && hash_equals(self::fingerprint($source), strtolower(trim($fp)));
    }

    /**
     * Each line's state; its own client (allocations.client_id) as a ninth part only when it has one, so a line without
     * one keeps the state and hash it always had, and a suggestion whose only change is its client ("Turnover · Acme
     * Events" becoming "Turnover · Beta Crew") is a change.
     *
     * @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:?int, 6:string, 7:bool, 8?:int}>
     */
    private static function lines(string $column, int $id): array
    {
        $int = fn ($v) => $v === null ? null : (int) $v;

        return Allocation::query()->where($column, $id)->get()
            ->map(fn (Allocation $a) => [$a->kind, $int($a->category_id), $int($a->work_job_id), $int($a->invoice_id), (int) $a->amount_pence,
                $int($a->business_use_pct), $a->decided_by, $a->confirmed_at !== null, ...($a->client_id !== null ? [(int) $a->client_id] : [])])
            ->sortBy(fn ($l) => json_encode($l))->values()->all();
    }
}
