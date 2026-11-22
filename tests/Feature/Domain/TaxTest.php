<?php

namespace Tests\Feature\Domain;

use App\Domain\Fx\ExchangeRates;
use App\Domain\Fx\RateUnavailable;
use App\Domain\Tax\BillPreview;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\TaxEstimator;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_2024_25_estimate_on_30000_profit(): void
    {
        $e = TaxEstimator::estimate('2024-25', 3_000_000);
        // (30,000 - 12,570) x 20% = 3,486.00; Class 4 at 6% = 1,045.80; no Class 2.
        $this->assertSame(348_600, $e['income_tax']['total_pence']);
        $this->assertSame(104_580, $e['class4_pence']);
        $this->assertSame(0, $e['class2_pence']);
    }

    public function test_older_years_use_their_own_rules(): void
    {
        $e = TaxEstimator::estimate('2022-23', 3_000_000);
        // Class 4 at the blended 9.73% from £11,908; Class 2 £3.15 x 52.
        $this->assertSame(176_035, $e['class4_pence']);
        $this->assertSame(16_380, $e['class2_pence']);

        $e = TaxEstimator::estimate('2023-24', 3_000_000);
        $this->assertSame(156_870, $e['class4_pence']);
        $this->assertSame(17_940, $e['class2_pence']);
    }

    public function test_higher_rate_and_allowance_taper(): void
    {
        $e = TaxEstimator::estimate('2025-26', 11_000_000);
        // Allowance tapers to £7,570; taxable £102,430: 37,700 at 20% + 64,730 at 40%.
        $this->assertSame(757_000, $e['personal_allowance_pence']);
        $this->assertSame(754_000 + 2_589_200, $e['income_tax']['total_pence']);
    }

    public function test_no_estimate_without_rates_or_for_scottish_taxpayers(): void
    {
        $this->assertNull(TaxEstimator::estimate('2019-20', 3_000_000));
        Setting::put('scottish_taxpayer', true);
        $this->assertNull(TaxEstimator::estimate('2024-25', 3_000_000));
    }

    public function test_bill_preview_subtracts_payments_already_made_and_adds_payments_on_account(): void
    {
        TaxYear::track('2024-25')->update(['payments_on_account_paid_pence' => 200_000]);
        $bill = BillPreview::for('2024-25', TaxEstimator::estimate('2024-25', 3_000_000));

        $this->assertSame(453_180, $bill['liability_pence']);
        $this->assertSame(253_180, $bill['balancing_pence']);
        $this->assertSame(226_590, $bill['payment_on_account_pence']);
        $this->assertSame('2026-01-31', $bill['due'][0]['date']);
        $this->assertSame(253_180 + 226_590, $bill['due'][0]['pence']);
        $this->assertSame('2026-07-31', $bill['due'][1]['date']);
    }

    public function test_mileage_uses_45p_before_and_55p_from_2026_27_with_the_10000_mile_threshold(): void
    {
        $car = Vehicle::create(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified']);
        $job = WorkJob::create(['title' => 'Conference', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01']);
        MileageTrip::create(['vehicle_id' => $car->id, 'work_job_id' => $job->id, 'trip_on' => '2025-06-01', 'tax_year' => '2025-26', 'from_place' => 'Home', 'to_place' => 'Venue', 'miles' => '100']);
        MileageTrip::create(['vehicle_id' => $car->id, 'trip_on' => '2026-05-01', 'tax_year' => '2026-27', 'from_place' => 'Home', 'to_place' => 'Venue', 'miles' => '9990']);
        MileageTrip::create(['vehicle_id' => $car->id, 'trip_on' => '2026-05-02', 'tax_year' => '2026-27', 'from_place' => 'Venue', 'to_place' => 'Home', 'miles' => '20']);

        $old = MileageCalculator::forYear('2025-26');
        $this->assertSame(4_500, $old['total_pence']);
        $this->assertSame([$job->id => 4_500], $old['by_job']);

        $new = MileageCalculator::forYear('2026-27');
        // 9,990 x 55p, then 10 x 55p + 10 x 25p.
        $this->assertSame(549_450 + 550 + 250, $new['total_pence']);

        // Mileage counts as a travel expense on the return.
        $summary = ReturnSummary::for('2025-26');
        $this->assertSame(4_500, $summary['expense_lines'][0]['allowable_pence']);
        $this->assertSame('travel', $summary['expense_lines'][0]['code']);
    }

    public function test_actual_cost_vehicles_get_no_mileage_claim(): void
    {
        $van = Vehicle::create(['name' => 'Van', 'type' => 'car_van', 'method' => 'actual']);
        MileageTrip::create(['vehicle_id' => $van->id, 'trip_on' => '2025-06-01', 'tax_year' => '2025-26', 'from_place' => 'A', 'to_place' => 'B', 'miles' => '50']);
        $this->assertSame(0, MileageCalculator::forYear('2025-26')['total_pence']);
    }

    public function test_exchange_rates_come_from_hmrc_monthly_rates_and_can_be_overridden(): void
    {
        Http::fake([
            'trade-tariff.service.gov.uk/*' => Http::response("Country/Territories,Currency,Currency Code,Currency Units per £1,Start date,End date\nUSA,Dollar,USD,1.2500,01/03/2025,31/03/2025\nEurozone,Euro,EUR,1.2000,01/03/2025,31/03/2025\nBenin,CFA Franc,XOF,791.9095,01/03/2025,31/03/2025\nMali,CFA Franc,XOF,791.9095,01/03/2025,31/03/2025\n"),
        ]);
        $fx = app(ExchangeRates::class);

        $usd = $fx->toPence(-10000, 'USD', CarbonImmutable::parse('2025-03-14'));
        $this->assertSame(-8000, $usd['pence']);
        $this->assertSame('hmrc_monthly', $usd['source']);
        $this->assertSame('1.25', $usd['units_per_gbp']);

        $fx->setManual('EUR', CarbonImmutable::parse('2025-03-01'), '1.05');
        $fx->setManual('EUR', CarbonImmutable::parse('2025-03-01'), '1.1');
        $this->assertSame(-9091, $fx->toPence(-10000, 'EUR', CarbonImmutable::parse('2025-03-20'))['pence']);
        Http::assertSentCount(1);
    }

    public function test_a_missing_rate_says_so_instead_of_guessing(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->expectException(RateUnavailable::class);
        app(ExchangeRates::class)->toPence(-10000, 'USD', CarbonImmutable::parse('2030-01-10'));
    }
}
