<?php

namespace App\Domain\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Exact percentage of a pence amount, rounded to whole pence. */
final class Percent
{
    public static function of(int $pence, string $percent, RoundingMode $mode = RoundingMode::HALF_UP): int
    {
        if ($pence <= 0) {
            return 0;
        }

        return BigDecimal::of($pence)->multipliedBy(BigDecimal::of($percent))
            ->dividedBy(100, 0, $mode)->toInt();
    }
}
