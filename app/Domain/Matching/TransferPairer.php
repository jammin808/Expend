<?php

namespace App\Domain\Matching;

use App\Domain\Ledger\AllocationWriter;
use App\Models\BankTransaction;

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
        $incoming = BankTransaction::query()->whereNull('transfer_pair_id')->where('amount_pence', '>', 0)
            ->with('account')->orderBy('posted_on')->get();

        foreach ($incoming as $in) {
            $date = $in->posted_on;
            $candidates = BankTransaction::query()
                ->whereNull('transfer_pair_id')
                ->where('account_id', '!=', $in->account_id)
                ->where('amount_pence', -$in->amount_pence)
                ->whereBetween('posted_on', [$date->copy()->subDays(self::WINDOW_DAYS), $date->copy()->addDays(self::WINDOW_DAYS)])
                ->with('account')
                ->get()
                ->sortBy(fn (BankTransaction $out) => [
                    $this->mentions($out, $in) ? 0 : 1,
                    abs($out->posted_on->diffInDays($date)),
                ]);

            $out = $candidates->first();
            if (! $out || (! $in->account->isCard() && ! $out->account->isCard() && ! $this->mentions($out, $in))) {
                continue;
            }

            $in->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
            $out->forceFill(['transfer_pair_id' => $in->id])->saveQuietly();

            foreach ([$in, $out] as $tx) {
                if ($tx->allocations()->count() === 0) {
                    $this->writer->decide($tx, AllocationWriter::lines($tx->amount_pence, 'transfer'), 'matcher', false, 'Matches a payment between your accounts');
                }
            }
            $paired++;
        }

        return $paired;
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
