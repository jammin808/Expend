<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_parses_bank_formats_into_pence(): void
    {
        $this->assertSame(123456, Money::parse('£1,234.56'));
        $this->assertSame(-1230, Money::parse('-12.30'));
        $this->assertSame(-450, Money::parse('(4.50)'));
        $this->assertSame(1230, Money::parse('12.3'));
        $this->assertSame(500, Money::parse('5'));
        $this->assertSame(-2000, Money::parse('20.00DR'));
        $this->assertSame(2000, Money::parse('20.00 CR'));
        $this->assertNull(Money::parse(''));
    }

    public function test_it_refuses_ambiguous_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parse('1.234,56');
    }

    public function test_it_formats_pence(): void
    {
        $this->assertSame('£1,234.56', Money::format(123456));
        $this->assertSame("\u{2212}£0.05", Money::format(-5));
        $this->assertSame('$10.00', Money::format(1000, 'USD'));
    }

    public function test_percentage_splits_always_add_up_and_the_remainder_penny_goes_to_personal(): void
    {
        foreach ([[-1001, 60], [-1, 50], [-999999, 33], [12345, 70], [-500, 100], [-500, 0]] as [$amount, $pct]) {
            [$business, $personal] = Money::splitByPercent($amount, $pct);
            $this->assertSame($amount, $business + $personal);
        }
        $this->assertSame([-600, -401], Money::splitByPercent(-1001, 60));
    }
}
