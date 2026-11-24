<?php

namespace App\Domain\Intel\Backfills;

use App\Models\Allocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * allocations.confirmed_by for lines confirmed before it was stored (docs/intelligence-cycle.md 3.5): 'you' where you
 * made the line, 'rule' where a rule made it already confirmed (a rule that confirms on its own). Everything else stays
 * null, because who confirmed it can't be told now:
 *  - a rule's suggestion that was confirmed later (a rule with "confirm on its own" off only suggests, and your tap in
 *    the Inbox confirms it) is told apart from one made confirmed by when it was confirmed: one the rule confirmed was
 *    confirmed the moment it was made (decide() writes both at once), and nothing confirmed it since (no
 *    allocation.confirmed event after the one that wrote it); otherwise it stays null, not 'rule';
 *  - any other suggestion that was confirmed (by your tap, or by the matcher for the other side of a transfer);
 *  - every line not confirmed.
 * The method isn't backfilled: Methods::of recognises it from the line's maker and reason when it's read.
 *
 * Idempotent and chunked: only lines it will fill are looked at, each once, and each run updates at most $chunk of them.
 * Voided lines (on undone transactions) are filled the same way.
 */
final class ConfirmedBy implements Backfill
{
    public function name(): string
    {
        return 'confirmed_by';
    }

    public function run(int $chunk = 500): array
    {
        $chunk = max(1, $chunk);
        $base = fn () => Allocation::withoutGlobalScope('not_voided')->whereNull('confirmed_by')->whereNotNull('confirmed_at')
            ->where(fn ($q) => $q->where('decided_by', 'you')->orWhere(fn ($q) => self::madeConfirmedByRule($q)));
        $ids = $base()->orderBy('id')->limit($chunk)->pluck('id');
        if ($ids->isEmpty()) {
            return ['changed' => 0, 'done' => true];
        }

        // Straight to the rows: nothing about the decisions changes, so they aren't re-audited or re-timestamped.
        $changed = DB::transaction(fn () => DB::table('allocations')->whereIn('id', $ids)->whereNull('confirmed_by')->where('decided_by', 'you')->update(['confirmed_by' => 'you'])
            + DB::table('allocations')->whereIn('id', $ids)->whereNull('confirmed_by')->where('decided_by', 'rule')->update(['confirmed_by' => 'rule']));

        return ['changed' => $changed, 'done' => ! $base()->exists()];
    }

    /**
     * A rule's line made already confirmed: confirmed when it was made, and no allocation.confirmed event for its
     * transaction (or expense) after the latest event that wrote its lines.
     *
     * @param  Builder<Allocation>  $q
     */
    private static function madeConfirmedByRule(Builder $q): void
    {
        $q->where('decided_by', 'rule')->whereColumn('confirmed_at', 'created_at');
        foreach (['BankTransaction' => 'bank_transaction_id', 'ManualExpense' => 'manual_expense_id'] as $type => $column) {
            $q->whereNotExists(fn ($e) => $e->selectRaw('1')->from('audit_events as c')
                ->where('c.action', 'allocation.confirmed')->where('c.subject_type', $type)->whereColumn('c.subject_id', 'allocations.'.$column)
                ->whereRaw('c.id > coalesce((select max(w.id) from audit_events w where w.subject_type = c.subject_type and w.subject_id = c.subject_id and w.action in (?, ?)), 0)',
                    ['allocation.suggested', 'allocation.decided']));
        }
    }
}
