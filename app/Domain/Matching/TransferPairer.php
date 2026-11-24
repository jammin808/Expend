<?php

namespace App\Domain\Matching;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pairs money moving between your own accounts (paying the Amex bill from Starling, topping up Revolut, moving
 * money to savings), so both sides are suggested as a transfer and never counted as spending or income. Lines
 * whose other side isn't imported are still suggested as transfers when the line itself shows it's your own money
 * (your name, a savings pot, a top-up of another account of yours, another account of yours named on it).
 *
 * Same amount on nearby days is never enough: both lines have to show it's your money, and a line that names
 * somebody else as the payer or payee is never paired. Your rules and the other matchers' suggestions are never
 * replaced, except an invoice matched on the amount alone to money the line itself shows is your own (the invoice is
 * then left open for the client's payment); and every suggestion is only a suggestion until you confirm it.
 */
final class TransferPairer
{
    private const WINDOW_DAYS = 5;

    /** How sure one side of a pair is: names the other account outright, shows it's yours, hints at it. */
    private const STRONG = 4;

    private const MEDIUM = 2;

    private const WEAK = 1;

    /** How a pair this class made ends, so a later run can check it still holds. */
    private const PAIR_REASON_END = 'moving between your accounts';

    /** How a one-sided suggestion of this class starts (OwnAccounts::reason, the earlier wording included). */
    private const ONE_SIDED_REASONS = ['Paid to you (your own name)', 'Paid in by you (your own name)', 'A top-up', 'A savings pot, exchange', 'A payment towards this card', 'Paid to your ', 'Paid in from your ', 'Names ', 'Money added to this account', 'Moved to ', 'Moved from '];

    /** Suggestions of this class that a run took back because they no longer hold (for a message). */
    public int $cleared = 0;

    /**
     * Lines a run left unpaired because two partners fitted equally well (as sure, as near), or because the partner
     * that fitted them best was caught up in such a tie: which pairing is right is for you to say, so none is guessed.
     */
    public int $ambiguous = 0;

    /** @var array<int, bool> */
    private array $namesAnother = [];

    public function __construct(private AllocationWriter $writer) {}

    /**
     * Pair both sides where they're both imported. After an import, only around the new statements' dates (with the
     * lines those could pair with, whatever their dates); a full run (nightly, after a deploy, after you change your
     * names) first takes back pairs of its own that no longer hold.
     *
     * @param  list<int>|null  $batchIds  the imports just made, or null for everything
     * @return int pairs made
     */
    public function pairAll(?array $batchIds = null): int
    {
        OwnAccounts::flush();
        $this->namesAnother = [];
        $locked = self::lockedYears();
        if ($batchIds === null) {
            $this->cleared += $this->recheckPairs($locked);
        }

        $window = null;
        if ($batchIds !== null) {
            $span = BankTransaction::query()->whereIn('import_batch_id', $batchIds ?: [0])->selectRaw('MIN(posted_on) as f, MAX(posted_on) as t')->toBase()->first();
            if (! $span || ! $span->f) {
                return 0;
            }
            $window = [CarbonImmutable::parse($span->f)->subDays(self::WINDOW_DAYS)->startOfDay(), CarbonImmutable::parse($span->t)->addDays(self::WINDOW_DAYS)->endOfDay()];
        }

        // Light rows first, so only lines with a possible partner are loaded in full.
        $columns = ['id', 'account_id', 'amount_pence', 'posted_on'];
        $slim = $this->pairable($locked)->when($window, fn ($q) => $q->whereBetween('posted_on', $window))->toBase()->get($columns);
        $seeds = null;
        if ($window) {
            // The lines around the new statements are paired just as a full run would pair them. A partner, or a
            // rival for one, can be just outside the window, and a tie can reach further through a chain of lines,
            // so every line of the same amounts is looked at, whatever its date; only the chains that reach a line
            // in the window are paired here.
            $seeds = array_fill_keys($slim->pluck('id')->map(fn ($id) => (int) $id)->all(), true);
            $amounts = $slim->map(fn ($r) => abs((int) $r->amount_pence))->unique()->values();
            $slim = collect();
            foreach ($amounts->chunk(200) as $chunk) {
                $slim = $slim->merge($this->pairable($locked)->whereIn('amount_pence', [...$chunk->all(), ...$chunk->map(fn ($p) => -$p)->all()])->toBase()->get($columns));
            }
        }
        $day = fn ($r) => intdiv((int) strtotime(substr((string) $r->posted_on, 0, 10).' 00:00:00 UTC'), 86400);
        $outs = $slim->where('amount_pence', '<', 0)->groupBy(fn ($r) => -$r->amount_pence);
        $pairs = [];
        foreach ($slim->where('amount_pence', '>', 0) as $in) {
            foreach ($outs[$in->amount_pence] ?? [] as $out) {
                $days = abs($day($in) - $day($out));
                if ($out->account_id !== $in->account_id && $days <= self::WINDOW_DAYS) {
                    $pairs[] = [(int) $in->id, (int) $out->id, $days];
                }
            }
        }
        if ($seeds !== null) {
            $group = self::groups($pairs);
            $reached = [];
            foreach ($group as $id => $root) {
                if (isset($seeds[$id])) {
                    $reached[$root] = true;
                }
            }
            $pairs = array_values(array_filter($pairs, fn ($p) => isset($reached[$group[$p[0]]])));
        }
        if (! $pairs) {
            return 0;
        }

        $rows = collect();
        foreach (array_chunk(array_values(array_unique(array_merge(array_column($pairs, 0), array_column($pairs, 1)))), 500) as $ids) {
            $rows = $rows->merge(BankTransaction::query()->whereIn('id', $ids)->with(['account', 'allocations'])->get());
        }
        $rows = $rows->keyBy('id');

        // The strongest pairs first, then the nearest days, so an earlier, weaker line never takes a partner that
        // belongs to a stronger one.
        $scored = [];
        foreach ($pairs as [$inId, $outId, $days]) {
            $in = $rows[$inId] ?? null;
            $out = $rows[$outId] ?? null;
            if ($in && $out && $in->account && $out->account && ($s = $this->strength($out, $in)) > 0) {
                $scored[] = [$s, $days, $inId, $outId];
            }
        }
        usort($scored, fn ($a, $b) => [$b[0], $a[1], $a[2], $a[3]] <=> [$a[0], $b[1], $b[2], $b[3]]);

        // Equally good pairs (as sure, as near) are taken together, joined where they share a line. A pair on its own
        // is made. Where lines have more than one equally good partner, none is guessed and every line joined to
        // them is left for you: so is a line whose best partner left is one of those (it would have had it, had the
        // tie gone the other way), and no weaker partner is guessed for any of them instead. The one exception is
        // lines that are all alike (the same accounts, the same days, each fitting each): which pairs with which
        // changes nothing, so they're paired in the order they came.
        $linked = [];
        $left = [];
        $paired = 0;
        foreach (self::levels($scored) as $level) {
            $open = array_values(array_filter($level, fn ($p) => ! isset($linked[$p[2]]) && ! isset($linked[$p[3]])));
            $group = self::groups(array_map(fn ($p) => [$p[2], $p[3]], $open));
            $parts = [];
            foreach ($open as $p) {
                $parts[$group[$p[2]]][] = $p;
            }
            foreach ($parts as $part) {
                $lines = array_values(array_unique([...array_column($part, 2), ...array_column($part, 3)]));
                $clear = ! array_filter($lines, fn ($id) => isset($left[$id]));
                if ($clear && (count($part) === 1 || $this->alike($part, $rows))) {
                    foreach ($part as [, , $inId, $outId]) {
                        if (! isset($linked[$inId]) && ! isset($linked[$outId])) {
                            $linked[$inId] = $linked[$outId] = true;
                            $paired += (int) $this->link($rows[$inId], $rows[$outId]);
                        }
                    }

                    continue;
                }
                foreach ($lines as $id) {
                    $this->ambiguous += (int) ! isset($left[$id]);
                    $left[$id] = true;
                }
            }
        }

        return $paired;
    }

    /**
     * Scored pairs, already in order, in runs of the same strength and days apart.
     *
     * @param  list<array{0:int, 1:int, 2:int, 3:int}>  $scored  [strength, days, in id, out id]
     * @return list<list<array{0:int, 1:int, 2:int, 3:int}>>
     */
    private static function levels(array $scored): array
    {
        $levels = [];
        $at = null;
        foreach ($scored as $p) {
            if ($at !== [$p[0], $p[1]]) {
                $at = [$p[0], $p[1]];
                $levels[] = [];
            }
            $levels[array_key_last($levels)][] = $p;
        }

        return $levels;
    }

    /**
     * Lines joined into groups through the given links (directly or through other lines): line id => the group.
     *
     * @param  iterable<array{0:int, 1:int}>  $links
     * @return array<int, int>
     */
    private static function groups(iterable $links): array
    {
        $parent = [];
        $find = function (int $id) use (&$parent): int {
            $parent[$id] ??= $id;
            while ($parent[$id] !== $id) {
                $id = $parent[$id] = $parent[$parent[$id]];
            }

            return $id;
        };
        foreach ($links as [$a, $b]) {
            [$ra, $rb] = [$find((int) $a), $find((int) $b)];
            if ($ra !== $rb) {
                $parent[max($ra, $rb)] = min($ra, $rb);
            }
        }
        $groups = [];
        foreach (array_keys($parent) as $id) {
            $groups[$id] = $find($id);
        }

        return $groups;
    }

    /**
     * Equally good pairs whose lines are all alike: the money in all on one account on one day, the money out all on
     * one account on one day, and each fitting each. Which pairs with which changes nothing in your books.
     *
     * @param  list<array{0:int, 1:int, 2:int, 3:int}>  $part
     * @param  Collection<int, BankTransaction>  $rows
     */
    private function alike(array $part, Collection $rows): bool
    {
        $ins = array_values(array_unique(array_column($part, 2)));
        $outs = array_values(array_unique(array_column($part, 3)));
        if (count($part) !== count($ins) * count($outs)) {
            return false;
        }
        foreach ([$ins, $outs] as $side) {
            $where = fn (int $id) => $rows[$id]->account_id.'|'.$rows[$id]->posted_on?->format('Y-m-d');
            if (count(array_unique(array_map($where, $side))) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** Link the two sides and suggest each as a transfer, unless it's already decided as one. */
    private function link(BankTransaction $in, BankTransaction $out): bool
    {
        $why = 'Matches '.($in->account->name ?? 'another account').' and '.($out->account->name ?? 'another account').': '.Money::format(abs($in->amount_pence)).' '.self::PAIR_REASON_END;
        $linked = DB::transaction(function () use ($in, $out, $why) {
            // Paired by another run since they were loaded: left as that run made it.
            if (BankTransaction::query()->whereIn('id', [$in->id, $out->id])->whereNull('transfer_pair_id')->lockForUpdate()->count() !== 2) {
                return false;
            }
            $in->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
            $out->forceFill(['transfer_pair_id' => $in->id])->saveQuietly();
            AuditLog::record('transfer.paired', $in, ['transfer_pair_id' => null], ['transfer_pair_id' => $out->id], $why, 'matcher');
            AuditLog::record('transfer.paired', $out, ['transfer_pair_id' => null], ['transfer_pair_id' => $in->id], $why, 'matcher');

            return true;
        });
        if (! $linked) {
            return false;
        }
        foreach ([$in, $out] as $tx) {
            if (! $this->isOpen($tx) || ($tx->allocations->isNotEmpty() && $this->settled($tx) && $tx->allocations->every(fn (Allocation $a) => $a->reason === $why))) {
                continue; // decided as a transfer already (by you, your rule, or confirmed): the decision stands
            }
            try {
                $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $why);
            } catch (InvalidArgumentException) {
                // protected by a decision of yours since it was loaded: the pair link stays, the decision is yours
            }
        }

        return true;
    }

    /**
     * Lines with no partner that still show they're your own money moving: suggested as a transfer, never confirmed.
     * A full run (no $batchIds) first takes back its own earlier suggestions that no longer hold, and looks at every
     * waiting line, however many there are.
     *
     * @param  list<string>|null  $locked
     * @param  list<int>|null  $batchIds  only the lines of these imports, or null for everything
     */
    public function suggestOneSided(?array $locked = null, ?array $batchIds = null): int
    {
        OwnAccounts::flush();
        $locked ??= self::lockedYears();
        $otherwise = self::decidedOtherwise();
        if ($batchIds === null) {
            $this->cleared += $this->recheckOneSided($locked, $otherwise);
        }
        $made = 0;
        $invoices = null;
        // Undecided, or only guessed by AI: your rules and the other matchers (HMRC, travel, invoices) come first.
        // The one exception is an invoice match on money the line shows is your own (below).
        $this->unpairedWaiting($locked)
            ->where(fn ($q) => $q->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')->orWhere('is_manual', true)->orWhere('decided_by', '!=', 'ai')))
                ->orWhere(fn ($q) => $q->where('amount_pence', '>', 0)->whereHas('allocations', fn ($a) => $a->whereNotNull('invoice_id'))
                    ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')->orWhere('is_manual', true)
                        ->orWhere('decided_by', '!=', 'matcher')->orWhere('kind', '!=', 'business_income')->orWhereNull('invoice_id')))))
            ->when($batchIds !== null, fn ($q) => $q->whereIn('import_batch_id', $batchIds ?: [0]))
            ->with(['account', 'allocations'])
            ->chunkById(500, function (Collection $rows) use (&$made, &$invoices, $otherwise) {
                foreach ($rows as $tx) {
                    if ($this->youDecidedOtherwise($tx, $otherwise) || ! ($reason = OwnAccounts::reason($tx))) {
                        continue;
                    }
                    if ($invoiceId = $tx->allocations->first()?->invoice_id) {
                        // An invoice matched on the amount alone, on money the line itself shows is your own (your
                        // name, a top-up from your card, your other account or card bill named; never a line naming
                        // somebody else, whom reason() never calls yours): never income. The invoice is left open
                        // for the client's payment.
                        $invoices ??= Invoice::query()->pluck('number', 'id');
                        $reason .= '. It had been matched to invoice '.($invoices[$invoiceId] ?? '#'.$invoiceId).' for the same amount; that invoice is left open for the client’s payment';
                    }
                    try {
                        $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $reason);
                    } catch (InvalidArgumentException) {
                        // locked, or decided by you meanwhile
                    }
                }
            });

        return $made;
    }

    /**
     * Suggested transfers that are safe to confirm in one go: both sides of a pair, and money leaving an account that
     * the line itself shows is going to you (your name, a pot, another account of yours). Money paid in with no other
     * side imported is left out: a client could have paid it, so each is checked on its own.
     *
     * @param  int|null  $upTo  only suggestions made up to this point (the allocation id shown on the button)
     * @param  CarbonInterface|null  $before  only suggestions made before this time (a Telegram message's date)
     * @return array{ids:list<int>, count:int, out_count:int, out_pence:int, in_count:int, in_pence:int, up_to:int, left_ids:list<int>, left:int, left_pence:int}
     */
    public static function confirmable(?int $upTo = null, ?CarbonInterface $before = null): array
    {
        $rows = BankTransaction::query()->where('review_status', 'suggested')->whereNotIn('tax_year', self::lockedYears() ?: [''])
            ->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer')->whereNull('confirmed_at')->where('decided_by', 'matcher'))
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->where('kind', '!=', 'transfer')->orWhere('decided_by', '!=', 'matcher')->orWhere('is_manual', true)))
            ->with('allocations:id,bank_transaction_id,created_at')->orderBy('id')->get(['id', 'amount_pence', 'transfer_pair_id']);
        [$safe, $left] = $rows->partition(fn (BankTransaction $t) => $t->transfer_pair_id !== null || $t->amount_pence < 0);
        $safe = $safe->filter(fn (BankTransaction $t) => (! $upTo || $t->allocations->max('id') <= $upTo)
            && (! $before || $t->allocations->every(fn (Allocation $a) => $a->created_at === null || $a->created_at->lte($before))));
        $out = $safe->where('amount_pence', '<', 0);
        $in = $safe->where('amount_pence', '>', 0);

        return [
            'ids' => $safe->pluck('id')->values()->all(), 'count' => $safe->count(),
            'out_count' => $out->count(), 'out_pence' => (int) -$out->sum('amount_pence'),
            'in_count' => $in->count(), 'in_pence' => (int) $in->sum('amount_pence'),
            'up_to' => (int) $safe->flatMap(fn (BankTransaction $t) => $t->allocations->pluck('id'))->max(),
            'left_ids' => $left->pluck('id')->values()->all(), 'left' => $left->count(), 'left_pence' => (int) $left->sum('amount_pence'),
        ];
    }

    /**
     * Transfers confirmed earlier (often with one tap) that no longer look like your own money moving by today's
     * checks: listed for you to look at again, never changed, as they're your decision.
     *
     * @return list<int>
     */
    public static function doubtful(): array
    {
        OwnAccounts::flush();
        $pairer = app(self::class);
        $ids = [];
        BankTransaction::query()->whereNotIn('tax_year', self::lockedYears() ?: [''])
            ->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer')->where('decided_by', 'matcher')->whereNotNull('confirmed_at'))
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->where('kind', '!=', 'transfer')->orWhere('decided_by', '!=', 'matcher')))
            ->with(['account', 'allocations'])
            ->chunkById(500, function (Collection $rows) use (&$ids, $pairer) {
                foreach ($rows as $tx) {
                    $reason = (string) $tx->allocations->first()?->reason;
                    if ($tx->transfer_pair_id && str_ends_with($reason, self::PAIR_REASON_END)) {
                        $partner = BankTransaction::query()->with(['account', 'allocations'])->find($tx->transfer_pair_id);
                        [$out, $in] = $tx->amount_pence < 0 ? [$tx, $partner] : [$partner, $tx];
                        if ($out && $in && $out->account && $in->account && $pairer->strength($out, $in) === 0) {
                            $ids[] = $tx->id;
                        }
                    } elseif (! $tx->transfer_pair_id && self::isOneSidedReason($reason) && ! OwnAccounts::reason($tx)) {
                        $ids[] = $tx->id;
                    }
                }
            });

        return $ids;
    }

    /**
     * After you decide one side of a pair: deciding it's a transfer confirms the other side too (if it was only
     * suggested); deciding it's something else breaks the pair and puts the other side back to be decided. A side in
     * a locked year is never changed: the pair is left as it is until that year is unlocked.
     */
    public function afterYourDecision(BankTransaction $tx): void
    {
        if (! $tx->transfer_pair_id) {
            return;
        }
        $tx->load('allocations');
        $partner = BankTransaction::query()->with('allocations')->find($tx->transfer_pair_id);
        if (! $partner) {
            return;
        }
        $isTransfer = self::movesMoney($tx->allocations);
        // The other side as the matcher left it: a transfer, maybe with the exchange fee split off (never yours).
        $partnerSuggested = self::movesMoney($partner->allocations) && $partner->allocations->every(fn (Allocation $a) => $a->confirmed_at === null && ! $a->is_manual);
        $partnerYear = $this->writer->taxYearOf($partner);

        if ($isTransfer) {
            // Confirmed with this side only when it's wholly a transfer: a fee split off it (a bank charge) is for you
            // to look at, so that side stays suggested, and paired.
            $partnerWhole = $partner->allocations->every(fn (Allocation $a) => $a->kind === 'transfer');
            if ($partnerSuggested && $partnerWhole && ! in_array($partnerYear, self::lockedYears(), true)) {
                try {
                    // The matcher settles its own suggestion because of your decision: recorded as the matcher's.
                    AuditContext::with('matcher', fn () => $this->writer->confirm($partner, 'Its other side was decided as a transfer'));
                } catch (InvalidArgumentException) {
                    // locked since: left as it is
                }
            }

            return;
        }
        if (in_array($partnerYear, self::lockedYears(), true)) {
            // Nothing in a locked year changes, its pair link included. The link does no harm here: this side is
            // counted by your decision.
            AuditLog::record('transfer.kept', $tx, null, ['transfer_pair_id' => $partner->id],
                "You decided it isn’t a transfer, but its other side is in {$partnerYear}, which is locked, so the pair is left as it is. Unlock {$partnerYear} and decide this again to unpair them");

            return;
        }
        DB::transaction(function () use ($tx, $partner, $partnerSuggested) {
            $tx->forceFill(['transfer_pair_id' => null])->saveQuietly();
            $partner->forceFill(['transfer_pair_id' => null])->saveQuietly();
            AuditLog::record('transfer.unpaired', $tx, ['transfer_pair_id' => $partner->id], ['transfer_pair_id' => null], 'You decided it isn’t a transfer');
            AuditLog::record('transfer.unpaired', $partner, ['transfer_pair_id' => $tx->id], ['transfer_pair_id' => null], 'You decided its other side isn’t a transfer');
            if ($partnerSuggested) {
                // The matcher takes back its own suggestion because of your decision: recorded as the matcher's.
                $this->writer->clear($partner, 'Its other side was decided as not a transfer', 'matcher');
            }
        });
    }

    /** Pairs of this class that are only suggested, and no longer hold by today's checks: unpaired and put back. */
    private function recheckPairs(array $locked): int
    {
        $cleared = 0;
        $ours = fn (BankTransaction $t) => $t->allocations->isEmpty() || $t->allocations->every(fn (Allocation $a) => $a->kind === 'transfer'
            && $a->decided_by === 'matcher' && $a->confirmed_at === null && ! $a->is_manual && str_ends_with((string) $a->reason, self::PAIR_REASON_END));
        BankTransaction::query()->whereNotNull('transfer_pair_id')->where('amount_pence', '>', 0)->whereNotIn('tax_year', $locked ?: [''])
            ->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer')->where('decided_by', 'matcher')->whereNull('confirmed_at')->where('reason', 'like', '%'.self::PAIR_REASON_END))
            ->with(['account', 'allocations'])
            ->chunkById(300, function (Collection $rows) use (&$cleared, $ours, $locked) {
                foreach ($rows as $in) {
                    $out = BankTransaction::query()->with(['account', 'allocations'])->find($in->transfer_pair_id);
                    if (! $out || $out->transfer_pair_id !== $in->id || in_array($out->tax_year, $locked, true) || ! $ours($in) || ! $ours($out)
                        || ! $in->account || ! $out->account || $this->strength($out, $in) > 0) {
                        continue;
                    }
                    try {
                        DB::transaction(function () use ($in, $out) {
                            $why = 'No longer looks like the same money moving between your accounts (checked again), so it’s back for you to decide';
                            foreach ([[$in, $out], [$out, $in]] as [$tx, $other]) {
                                $tx->forceFill(['transfer_pair_id' => null])->saveQuietly();
                                AuditLog::record('transfer.unpaired', $tx, ['transfer_pair_id' => $other->id], ['transfer_pair_id' => null], $why, 'matcher');
                                if ($tx->allocations->isNotEmpty()) {
                                    $this->writer->clear($tx, $why, 'matcher');
                                }
                            }
                        });
                        $cleared += 2;
                    } catch (InvalidArgumentException) {
                        // locked meanwhile: left as it is
                    }
                }
            });

        return $cleared;
    }

    /** One-sided suggestions of this class that no longer hold (or that your own decisions now contradict): put back. */
    private function recheckOneSided(array $locked, array $otherwise): int
    {
        $cleared = 0;
        $this->unpairedWaiting($locked, rules: false)
            ->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer')->where('decided_by', 'matcher')->whereNull('confirmed_at'))
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->where('kind', '!=', 'transfer')->orWhere('decided_by', '!=', 'matcher')
                ->orWhereNotNull('confirmed_at')->orWhere('is_manual', true)))
            ->with(['account', 'allocations'])
            ->chunkById(500, function (Collection $rows) use (&$cleared, $otherwise) {
                foreach ($rows as $tx) {
                    if (! self::isOneSidedReason((string) $tx->allocations->first()?->reason)) {
                        continue; // another matcher's (a currency exchange, say): not this class's to take back
                    }
                    $mine = $this->youDecidedOtherwise($tx, $otherwise);
                    if (! $mine && OwnAccounts::reason($tx)) {
                        continue;
                    }
                    try {
                        $this->writer->clear($tx, $mine
                            ? 'You’ve decided payments like this as something other than a transfer, so it’s back for you to decide'
                            : 'No longer looks like your own money moving (checked again), so it’s back for you to decide', 'matcher');
                        $cleared++;
                    } catch (InvalidArgumentException) {
                        // locked meanwhile
                    }
                }
            });

        return $cleared;
    }

    /** Lines with no pair in unlocked years, left out where a rule of yours (other than "transfer") covers them. */
    private function unpairedWaiting(array $locked, bool $rules = true): Builder
    {
        $q = BankTransaction::query()->whereNull('transfer_pair_id')->whereNotIn('tax_year', $locked ?: ['']);

        return $rules ? self::withoutRuled($q, MerchantRule::query()->get(['merchant_key', 'direction'])) : $q;
    }

    /**
     * Lines that can be paired: no pair yet, in an unlocked year, not covered by a rule of yours, and either
     * waiting (undecided, an AI guess, a one-sided transfer suggestion) or already decided as a transfer. Never a
     * line another matcher has suggested something else for (an invoice match, HMRC, travel, a category).
     */
    private function pairable(array $locked): Builder
    {
        $q = BankTransaction::query()->whereNull('transfer_pair_id')->whereNotIn('tax_year', $locked ?: [''])
            ->where(fn ($q) => self::open($q)->orWhere(fn ($q) => $q->whereHas('allocations')->whereDoesntHave('allocations', fn ($a) => $a->where('kind', '!=', 'transfer'))));

        return self::withoutRuled($q, MerchantRule::query()->where('kind', '!=', 'transfer')->get(['merchant_key', 'direction']));
    }

    /** A rule covers its merchant only the way round it applies (a learned rule keeps to the way it was learned). */
    private static function withoutRuled(Builder $q, Collection $rules): Builder
    {
        $ruled = fn (?string $direction) => ($direction ? $rules->where('direction', $direction) : $rules->whereNull('direction'))->pluck('merchant_key')->all() ?: [''];

        return $q->whereNotIn('merchant_key', $ruled(null))
            ->where(fn ($q) => $q->where('amount_pence', '>', 0)->orWhereNotIn('merchant_key', $ruled('out')))
            ->where(fn ($q) => $q->where('amount_pence', '<', 0)->orWhereNotIn('merchant_key', $ruled('in')));
    }

    /** Not decided yet, or only guessed by AI, or only suggested as a transfer by this class (never by you or a rule, never confirmed). */
    private static function open(Builder $q): Builder
    {
        return $q->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')->orWhere('is_manual', true)
            ->orWhereNotNull('invoice_id')->orWhereNotIn('decided_by', ['matcher', 'ai'])
            ->orWhere(fn ($a) => $a->where('decided_by', 'matcher')->where('kind', '!=', 'transfer'))));
    }

    private function isOpen(BankTransaction $tx): bool
    {
        return $tx->allocations->every(fn (Allocation $a) => $a->confirmed_at === null && ! $a->is_manual && $a->invoice_id === null
            && ($a->decided_by === 'ai' || ($a->decided_by === 'matcher' && $a->kind === 'transfer')));
    }

    /**
     * Whether a decision keeps a line as money moving between your accounts: a transfer, alone or with fees split
     * off it as bank charges (a currency exchange's fee). Splitting the fee off, by hand or by the import, never
     * breaks the pair; deciding it's anything else does.
     *
     * @param  Collection<int, Allocation>  $allocations
     */
    public static function movesMoney(Collection $allocations): bool
    {
        if (! $allocations->contains('kind', 'transfer')) {
            return false;
        }
        $fees = null;

        return $allocations->every(function (Allocation $a) use (&$fees) {
            if ($a->kind === 'transfer') {
                return true;
            }
            $fees ??= self::feeCategoryIds();

            return $a->kind === 'business_expense' && in_array((int) $a->category_id, $fees, true);
        });
    }

    /** @return list<int> the category a bank charge goes in */
    private static function feeCategoryIds(): array
    {
        return Category::query()->where('code', 'finance')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function settled(BankTransaction $tx): bool
    {
        return $tx->allocations->isNotEmpty() && $tx->allocations->every(fn (Allocation $a) => $a->kind === 'transfer');
    }

    /**
     * How sure we are that $out and $in are the same money moving. Same amount on nearby days isn't enough: both
     * lines have to show it's yours (your name, a pot or exchange, a top-up of yours, the other account named), and
     * a mere hint on one side (a bank's name, "top up") counts only when the other side names the account outright.
     * A line naming somebody else as the payer or payee, naming a different account of yours, or a top-up from a
     * card other than the one that paid, is never paired. 0 = not a pair.
     */
    private function strength(BankTransaction $out, BankTransaction $in): int
    {
        if ($out->account_id === $in->account_id || OwnAccounts::vetoed($out) || OwnAccounts::vetoed($in)
            || $this->namesAnother($out, $in->account) || $this->namesAnother($in, $out->account)) {
            return 0;
        }
        // "Top-up by *7777" names the card the money came from: never a payment made with another card.
        $topUpCard = OwnAccounts::topUpCard($in);
        $paidWith = OwnAccounts::paidWithCard($out);
        if ($topUpCard !== null && $paidWith !== null && $topUpCard !== $paidWith) {
            return 0;
        }
        $o = $this->side($out, $in->account, true);
        $i = $this->side($in, $out->account, false);
        if ($topUpCard !== null && $topUpCard === $paidWith) {
            $o = max($o, self::STRONG); // paid with the very card the top-up names
        }
        if ($in->account->isCard() && ! $out->account->isCard() && preg_match(OwnAccounts::CARD_PAYMENT, $in->description.' '.$in->counterparty)) {
            $i = max($i, self::STRONG); // a card's "payment received": the bill paid from a bank account
            if (OwnAccounts::paysACard($out)) {
                $o = max($o, self::WEAK); // paid to a bank or card issuer ("BARCLAYCARD"), or a card bill in its own words
            }
        }

        return $o > 0 && $i > 0 && $o + $i >= 2 * self::MEDIUM ? $o + $i : 0;
    }

    /** What one line says about being your own money moving to or from $other. */
    private function side(BankTransaction $tx, Account $other, bool $isOut): int
    {
        $naming = OwnAccounts::naming($tx, $other);
        if ($naming === 'strict') {
            return self::STRONG;
        }
        if (OwnAccounts::byOrToYou($tx) || OwnAccounts::ownWording($tx) || OwnAccounts::topUpBySelf($tx)) {
            return self::MEDIUM;
        }
        // A wallet whose statement you import (PayPal): "PAYPAL *THOMANN" paid from your bank went into it, and its
        // "General Card Deposit" is that money arriving. The purchase is the wallet's own line.
        if ($isOut ? OwnAccounts::fundsWallet($tx, $other) : OwnAccounts::walletFunding($tx)) {
            return self::MEDIUM;
        }

        // A bank's name on money going out (a shop in Halifax?) or "top up" only hints at it.
        return ($naming === 'loose' && $isOut) || OwnAccounts::topUpWording($tx) ? self::WEAK : 0;
    }

    /**
     * The line names, as where the money went or came from, an account of yours other than $other, by what tells
     * that account apart from $other: "AMERICAN EXPRESS" doesn't name your Amex rather than your Amex Business.
     */
    private function namesAnother(BankTransaction $tx, Account $other): bool
    {
        $key = $tx->id * 100000 + $other->id;
        if (isset($this->namesAnother[$key])) {
            return $this->namesAnother[$key];
        }
        foreach (OwnAccounts::accounts() as $a) {
            if ($a->id !== $tx->account_id && ! OwnAccounts::family($a, $other) && OwnAccounts::naming($tx, $a, $other) === 'strict') {
                return $this->namesAnother[$key] = true;
            }
        }

        return $this->namesAnother[$key] = false;
    }

    /**
     * Merchants you decide as something other than a transfer: your latest decisions for the merchant (the same way
     * round) are mostly not transfers. A transfer guess for them is never made, so your choice is followed.
     *
     * @return array<string, array<string, bool>> [direction][merchant_key]
     */
    private static function decidedOtherwise(): array
    {
        $seen = [];
        BankTransaction::query()->where('review_status', 'confirmed')->where('merchant_key', '!=', 'UNKNOWN')
            ->where('posted_on', '>=', now()->subYears(3)->format('Y-m-d'))
            ->whereDoesntHave('allocations', fn ($a) => $a->where('decided_by', '!=', 'you'))
            ->with('allocations:id,bank_transaction_id,kind')
            ->select(['id', 'merchant_key', 'amount_pence', 'posted_on'])->orderByDesc('posted_on')->limit(8000)->get()
            ->each(function (BankTransaction $t) use (&$seen) {
                $dir = $t->amount_pence < 0 ? 'out' : 'in';
                if ($t->allocations->isNotEmpty() && count($seen[$dir][$t->merchant_key] ?? []) < 5) {
                    $seen[$dir][$t->merchant_key][] = $t->allocations->every(fn ($a) => $a->kind === 'transfer');
                }
            });
        $out = [];
        foreach ($seen as $dir => $keys) {
            foreach ($keys as $key => $transfers) {
                $not = count(array_filter($transfers, fn ($t) => ! $t));
                if ($not / count($transfers) >= 0.6) {
                    $out[$dir][$key] = true;
                }
            }
        }

        return $out;
    }

    private function youDecidedOtherwise(BankTransaction $tx, array $otherwise): bool
    {
        return isset($otherwise[$tx->amount_pence < 0 ? 'out' : 'in'][$tx->merchant_key]);
    }

    private static function isOneSidedReason(string $reason): bool
    {
        foreach (self::ONE_SIDED_REASONS as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function lockedYears(): array
    {
        return TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
    }
}
