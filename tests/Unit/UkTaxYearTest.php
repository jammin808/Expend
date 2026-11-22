<?php

namespace Tests\Unit;

use App\Support\UkTaxYear;
use PHPUnit\Framework\TestCase;

class UkTaxYearTest extends TestCase
{
    public function test_the_boundary_is_between_5_and_6_april(): void
    {
        $this->assertSame('2024-25', UkTaxYear::fromDate('2025-04-05')->label());
        $this->assertSame('2025-26', UkTaxYear::fromDate('2025-04-06')->label());
        $this->assertSame('2024-25', UkTaxYear::fromDate('2024-04-06')->label());
        $this->assertSame('2023-24', UkTaxYear::fromDate('2024-01-31')->label());
    }

    public function test_labels_round_trip_and_deadlines(): void
    {
        $year = UkTaxYear::fromLabel('2024-25');
        $this->assertSame('2024-04-06', $year->startsOn()->format('Y-m-d'));
        $this->assertSame('2025-04-05', $year->endsOn()->format('Y-m-d'));
        $this->assertSame('2026-01-31', $year->filingDeadline()->format('Y-m-d'));
        $this->assertSame('2023-24', $year->previous()->label());
        $this->assertCount(13, $year->months());
    }

    public function test_bad_labels_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UkTaxYear::fromLabel('2024-26');
    }
}
