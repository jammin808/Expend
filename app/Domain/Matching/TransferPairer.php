<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Models\BankTransaction;
use App\Models\TaxYear;

/**
 * Pairs money moving between your own accounts (for example, paying the Amex bill from Starling), so it's
 * suggested as a transfer and never counted as spending on top of the card purchases.
 */
final class TransferPairer
{
    private const WINDOW_DAYS = 5;

    public function __construct(private AllocationWriter $writer) {}

    public function pairAll(): int
    {
        $paired = 0;
        $locked = TaxYear::query()->whereNotNull('locked_at')->pluck('label')->all();
        $incoming = BankTransaction::query()->whereNull('transfer_pair_id')->where('amount_pence', '>', 0)
            ->whereDoesntHave('allocations')->whereNotIn('tax_year', $locked)
            ->with('account')->orderBy('posted_on')->get();

        foreach ($incoming as $in) {
            $date = $in->posted_on;
            $candidates = BankTransaction::query()
                ->whereNull('transfer_pair_id')
                ->whereDoesntHave('allocations')
                ->whereNotIn('tax_year', $locked)
                ->where('account_id', '!=', $in->account_id)
                ->where('amount_pence', -$in->amount_pence)
                ->whereBetween('posted_on', [$date->copy()->subDays(self::WINDOW_DAYS), $date->copy()->addDays(self::WINDOW_DAYS)])
                ->with('account')
                ->get()
                ->filter(fn (BankTransaction $out) => $this->looksLikeTransfer($out, $in))
                ->sortBy(fn (BankTransaction $out) => [$this->mentions($out, $in) ? 0 : 1, abs($out->posted_on->diffInDays($date))]);

            $out = $candidates->first();
            if (! $out) {
                continue;
            }

            $in->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
            $out->forceFill(['transfer_pair_id' => $in->id])->saveQuietly();

            foreach ([$in, $out] as $tx) {
                $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, 'Matches a payment between your accounts');
            }
            $paired++;
        }

        return $paired;
    }

    /**
     * Same amount on the same days isn't enough: a card purchase and a client payment can match by chance.
     * A pair needs the outgoing side to name the receiving account, or a card "payment received" line
     * matched to money leaving a bank account.
     */
    private function looksLikeTransfer(BankTransaction $out, BankTransaction $in): bool
    {
        if ($this->mentions($out, $in)) {
            return true;
        }

        $cardPayment = (bool) preg_match('/\bPAYMENT\b|THANK YOU|DIRECT DEBIT/i', $in->description);

        return $in->account->isCard() && ! $out->account->isCard() && $cardPayment;
    }

    private function mentions(BankTransaction $out, BankTransaction $in): bool
    {
        $text = strtoupper($out->description.' '.$out->counterparty);
        $words = array_filter([
            strtoupper($in->account->name),
            match ($in->account->provider) {
                'amex' => 'AMERICAN EXPRESS',
                'capital_one' => 'CAPITAL ONE',
                'starling' => 'STARLING',
                default => null,
            },
            $in->account->provider === 'amex' ? 'AMEX' : null,
        ]);

        foreach ($words as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
