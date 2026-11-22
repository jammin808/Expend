<?php

namespace App\Domain\Tax;

final class TaxParameters
{
    public static function for(string $label): ?array
    {
        return config("tax.years.{$label}");
    }

    public static function has(string $label): bool
    {
        return self::for($label) !== null;
    }
}
