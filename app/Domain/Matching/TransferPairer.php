<?php

namespace App\Domain\Matching;

use App\Domain\Audit\AuditLog;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Pairs money moving between your own accounts (paying the Amex bill from Starling, topping up Revolut, moving
 * money to savings), so both sides are suggested as a transfer and never counted as spending or income. Lines
 * whose other side isn't imported are still suggested as transfers when the line itself shows it's your own money
 * (your name, a top-up, a savings pot, another account of yours).
 */
final class TransferPairer
{
    private const WINDOW_DAYS = 5;

    public function __construct(private AllocationWriter $writer) {}

    /** Pair both sides where they're both imported. @return int pairs made */
    public function pairAll(): int
    {
        OwnAccounts::flush();
        $paired = 0;
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $incoming = self::open(BankTransaction::query())->where('amount_pence', '>', 0)->whereNotIn('tax_year', $locked)
            ->with(['account', 'allocations'])->orderBy('posted_on')->get();

        foreach ($incoming as $in) {
            if ($in->fresh()?->transfer_pair_id) {
                continue;
            }
            $date = $in->posted_on;
            $candidates = self::open(BankTransaction::query())
                ->whereNotIn('tax_year', $locked)
                ->where('account_id', '!=', $in->account_id)
                ->where('amount_pence', -$in->amount_pence)
                ->whereBetween('posted_on', [$date->copy()->subDays(self::WINDOW_DAYS), $date->copy()->addDays(self::WINDOW_DAYS)])
                ->with(['account', 'allocations'])
                ->get()
                ->filter(fn (BankTransaction $out) => $this->strength($out, $in) > 0)
                ->sortBy(fn (BankTransaction $out) => [-$this->strength($out, $in), abs($out->posted_on->diffInDays($date))]);

            $out = $candidates->first();
            if (! $out) {
                continue;
            }

            $in->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
            $out->forceFill(['transfer_pair_id' => $in->id])->saveQuietly();
            $why = 'Matches '.($in->account->name ?? 'another account').' and '.($out->account->name ?? 'another account').': '.Money::format(abs($in->amount_pence)).' moving between your accounts';
            foreach ([$in, $out] as $tx) {
                try {
                    $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $why);
                } catch (InvalidArgumentException) {
                    // protected by a decision of yours since it was loaded: the pair link stays, the decision is yours
                }
            }
            $paired++;
        }

        return $paired;
    }

    /**
     * Lines with no partner that still show they're your own money moving: suggested as a transfer, never confirmed.
     *
     * @param  list<string>  $locked
     */
    public function suggestOneSided(?array $locked = null): int
    {
        OwnAccounts::flush();
        $locked ??= TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $ruled = MerchantRule::query()->pluck('merchant_key')->all();
        $made = 0;
        // Undecided, or only guessed by AI: your rules and the other matchers (HMRC, travel, invoices) come first.
        $rows = BankTransaction::query()->whereNull('transfer_pair_id')->whereNotIn('tax_year', $locked)->whereNotIn('merchant_key', $ruled ?: [''])
            ->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')->orWhere('is_manual', true)->orWhere('decided_by', '!=', 'ai')))
            ->with(['account', 'allocations'])->orderBy('posted_on')->limit(5000)->get();
        foreach ($rows as $tx) {
            $reason = OwnAccounts::reason($tx);
            if (! $reason) {
                continue;
            }
            try {
                $made += (int) $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, $reason);
            } catch (InvalidArgumentException) {
                // locked, or decided by you meanwhile
            }
        }

        return $made;
    }

    /**
     * After you decide one side of a pair: deciding it's a transfer confirms the other side too (if it was only
     * suggested); deciding it's something else breaks the pair and puts the other side back to be decided.
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
        $isTransfer = $tx->allocations->isNotEmpty() && $tx->allocations->every(fn (Allocation $a) => $a->kind === 'transfer');
        $partnerSuggested = $partner->allocations->isNotEmpty() && $partner->allocations->every(fn (Allocation $a) => $a->kind === 'transfer' && $a->confirmed_at === null && ! $a->is_manual);

        try {
            if ($isTransfer) {
                if ($partnerSuggested) {
                    $this->writer->confirm($partner);
                }
            } else {
                $tx->forceFill(['transfer_pair_id' => null])->saveQuietly();
                $partner->forceFill(['transfer_pair_id' => null])->saveQuietly();
                AuditLog::record('transfer.unpaired', $tx, ['transfer_pair_id' => $partner->id], ['transfer_pair_id' => null], 'You decided it isn’t a transfer');
                if ($partnerSuggested) {
                    $this->writer->clear($partner, 'Its other side was decided as not a transfer');
                }
            }
        } catch (InvalidArgumentException) {
            // the other side is in a locked year: left as it is
        }
    }

    /** Not decided yet, or only suggested by the matcher or AI (never by you or your rules, never confirmed). */
    private static function open(Builder $q): Builder
    {
        return $q->whereNull('transfer_pair_id')->whereDoesntHave('allocations', fn ($a) => $a->where(fn ($a) => $a->whereNotNull('confirmed_at')
            ->orWhere('is_manual', true)->orWhereNotIn('decided_by', ['matcher', 'ai'])));
    }

    /**
     * How sure we are that $out and $in are the same money moving. Same amount on nearby days isn't enough on its
     * own: a card purchase and a client payment can match by chance. 0 = not a pair.
     */
    private function strength(BankTransaction $out, BankTransaction $in): int
    {
        $outText = $out->description.' '.$out->counterparty;
        $inText = $in->description.' '.$in->counterparty;
        $s = 0;
        if (OwnAccounts::names_account($outText, $in->account) || OwnAccounts::names_account($inText, $out->account)) {
            $s += 3;
        }
        if (OwnAccounts::isYou($out->counterparty) || OwnAccounts::isYou($in->counterparty) || OwnAccounts::mentionsYou($outText) || OwnAccounts::mentionsYou($inText)) {
            $s += 3;
        }
        if (preg_match(OwnAccounts::OWN_PATTERN, $outText) || preg_match(OwnAccounts::OWN_PATTERN, $inText)) {
            $s += 2;
        }
        if ($in->account->isCard() && ! $out->account->isCard() && preg_match(OwnAccounts::CARD_PAYMENT, $inText)) {
            $s += 2;
        }
        // An incoming line that clearly came from somebody else (a client named on it) isn't your own money.
        if ($s > 0 && $in->counterparty && ! OwnAccounts::isYou($in->counterparty) && ! OwnAccounts::names_account($inText, $out->account)
            && ! preg_match(OwnAccounts::OWN_PATTERN, $inText) && ! $in->account->isCard()) {
            $s -= 2;
        }

        return max(0, $s);
    }
}
