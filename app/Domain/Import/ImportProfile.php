<?php

namespace App\Domain\Import;

/**
 * How to read one bank's CSV: which column is which, the date format and the sign convention.
 * Stored on the account after the first import, so the next file needs no setup.
 */
final class ImportProfile
{
    public const DATE_FORMATS = ['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd.m.Y', 'm/d/Y', 'd M Y', 'j M Y'];

    public function __construct(
        public string $preset = 'custom',
        public ?string $dateColumn = null,
        public ?string $transactionDateColumn = null,
        public array $descriptionColumns = [],
        public ?string $counterpartyColumn = null,
        public ?string $referenceColumn = null,
        public ?string $amountColumn = null,
        public ?string $debitColumn = null,
        public ?string $creditColumn = null,
        public bool $invertSign = false,
        public ?string $balanceColumn = null,
        public ?string $categoryColumn = null,
        public ?string $currencyColumn = null,
        public ?string $originalAmountColumn = null,
        public string $dateFormat = 'd/m/Y',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            preset: $data['preset'] ?? 'custom',
            dateColumn: $data['dateColumn'] ?? null,
            transactionDateColumn: $data['transactionDateColumn'] ?? null,
            descriptionColumns: array_values(array_filter($data['descriptionColumns'] ?? [])),
            counterpartyColumn: $data['counterpartyColumn'] ?? null,
            referenceColumn: $data['referenceColumn'] ?? null,
            amountColumn: $data['amountColumn'] ?? null,
            debitColumn: $data['debitColumn'] ?? null,
            creditColumn: $data['creditColumn'] ?? null,
            invertSign: (bool) ($data['invertSign'] ?? false),
            balanceColumn: $data['balanceColumn'] ?? null,
            categoryColumn: $data['categoryColumn'] ?? null,
            currencyColumn: $data['currencyColumn'] ?? null,
            originalAmountColumn: $data['originalAmountColumn'] ?? null,
            dateFormat: $data['dateFormat'] ?? 'd/m/Y',
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @return list<string> what's missing before this profile can read a file */
    public function problems(array $headers): array
    {
        $problems = [];
        if (! $this->dateColumn || ! in_array($this->dateColumn, $headers, true)) {
            $problems[] = 'Choose the date column.';
        }
        if ($this->descriptionColumns === [] && ! $this->counterpartyColumn) {
            $problems[] = 'Choose at least one description column.';
        }
        if (! $this->amountColumn && ! ($this->debitColumn && $this->creditColumn)) {
            $problems[] = 'Choose an amount column, or both money-out and money-in columns.';
        }
        foreach (['transactionDateColumn', 'counterpartyColumn', 'referenceColumn', 'amountColumn', 'debitColumn', 'creditColumn', 'balanceColumn', 'categoryColumn', 'currencyColumn', 'originalAmountColumn'] as $field) {
            if ($this->{$field} && ! in_array($this->{$field}, $headers, true)) {
                $problems[] = "The column \"{$this->{$field}}\" isn't in this file.";
            }
        }

        return $problems;
    }
}
