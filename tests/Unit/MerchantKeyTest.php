<?php

namespace Tests\Unit;

use App\Domain\Matching\MerchantKey;
use PHPUnit\Framework\TestCase;

class MerchantKeyTest extends TestCase
{
    public function test_noise_and_references_are_removed(): void
    {
        $this->assertSame('UBER TRIP HELP', MerchantKey::from('UBER *TRIP HELP.UBER.COM'));
        $this->assertSame('TFL TRAVEL CH', MerchantKey::from('TFL TRAVEL CH'));
        $this->assertSame('AMAZON', MerchantKey::from('AMAZON.CO.UK*AB12CD34'));
        $this->assertSame('PRET MANGER', MerchantKey::from('PRET A MANGER 0423', null));
        $this->assertSame('ADOBE', MerchantKey::from('ADOBE CREATIVE CLD', 'Adobe'));
    }
}
