<?php

namespace App\Domain\Import;

use Carbon\CarbonImmutable;

final class NormalizedRow
{
    public function __construct(
        public CarbonImmutable $postedOn,
        public ?CarbonImmutable $transactionOn,
        public string $description,
        public ?string $counterparty,
        public ?string $reference,
        public int $amountPence,
        public ?int $balanceAfterPence,
        public ?int $originalAmountMinor,
        public ?string $originalCurrency,
        public ?string $bankCategory,
        public array $raw,
        public int $line,
        // A row in an account kept in another currency (a Revolut euro pocket): its own amount and running balance in
        // that currency, and how its sterling amount was worked out.
        public ?int $nativeAmountMinor = null,
        public ?int $nativeBalanceMinor = null,
        public ?string $fxUnitsPerGbp = null,
        public ?string $fxRateDate = null,
        public ?string $fxSource = null,
        public ?int $feeMinor = null,
        public ?string $exchangeKey = null,
    ) {}

    /** The amount and balance the statement itself uses (its own currency). */
    public function statementAmount(): int
    {
        return $this->nativeAmountMinor ?? $this->amountPence;
    }

    public function statementBalance(): ?int
    {
        return $this->nativeAmountMinor !== null ? $this->nativeBalanceMinor : $this->balanceAfterPence;
    }

    public function effectiveDate(): CarbonImmutable
    {
        return $this->transactionOn ?? $this->postedOn;
    }

    public function fingerprint(int $accountId): string
    {
        return hash('sha256', implode('|', [
            $accountId,
            $this->postedOn->format('Y-m-d'),
            $this->statementAmount(),
            strtoupper(preg_replace('/\s+/', ' ', trim($this->description))),
            $this->statementBalance() ?? '',
        ]));
    }
}
