<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A UK tax year: 6 April to 5 April. Labelled like "2024-25".
 */
final class UkTaxYear
{
    private function __construct(public readonly int $startYear) {}

    public static function fromDate(DateTimeInterface|string $date): self
    {
        $d = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);
        $year = (int) $d->format('Y');
        $boundary = CarbonImmutable::create($year, 4, 6)->startOfDay();

        return new self($d->startOfDay()->lt($boundary) ? $year - 1 : $year);
    }

    public static function fromLabel(string $label): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $label, $m) || ((int) $m[1] + 1) % 100 !== (int) $m[2]) {
            throw new InvalidArgumentException("\"{$label}\" isn't a tax year like 2024-25.");
        }

        return new self((int) $m[1]);
    }

    public static function current(): self
    {
        return self::fromDate(CarbonImmutable::now('Europe/London'));
    }

    public function label(): string
    {
        return sprintf('%d-%02d', $this->startYear, ($this->startYear + 1) % 100);
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear, 4, 6)->startOfDay();
    }

    public function endsOn(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear + 1, 4, 5)->startOfDay();
    }

    /** Online return and balancing payment deadline. */
    public function filingDeadline(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear + 2, 1, 31)->startOfDay();
    }

    public function previous(): self
    {
        return new self($this->startYear - 1);
    }

    public function next(): self
    {
        return new self($this->startYear + 1);
    }

    /** @return list<string> "2024-04" style months covering the year (April to April). */
    public function months(): array
    {
        $months = [];
        $cursor = $this->startsOn()->startOfMonth();
        $end = $this->endsOn()->startOfMonth();
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    public function contains(DateTimeInterface|string $date): bool
    {
        return self::fromDate($date)->startYear === $this->startYear;
    }
}
