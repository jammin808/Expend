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
    ) {}

    public function effectiveDate(): CarbonImmutable
    {
        return $this->transactionOn ?? $this->postedOn;
    }

    public function fingerprint(int $accountId): string
    {
        return hash('sha256', implode('|', [
            $accountId,
            $this->postedOn->format('Y-m-d'),
            $this->amountPence,
            strtoupper(preg_replace('/\s+/', ' ', trim($this->description))),
            $this->balanceAfterPence ?? '',
        ]));
    }
}
