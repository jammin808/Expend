<?php

namespace Tests\Feature\Planning;

use App\Domain\Tax\Planning;
use App\Domain\Tax\StructureCalculator;
use App\Domain\Tax\TaxEstimator;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The structure calculator against examples worked by hand from the research's rates (resources/tax/planning.json).
 *
 * Rates used (rest of the UK), from the rates block:
 *   2025-26 and 2026-27: personal allowance £12,570 (less £1 for every £2 over £100,000); basic rate band £37,700;
 *   income tax 20% / 40% / 45%; Class 4 6% on £12,570 to £50,270 and 2% above; employer NIC 15% above £5,000 (no
 *   Employment Allowance for a sole director); employee NIC 8% from £12,570 to £50,270, 2% above; corporation tax 19% up
 *   to £50,000, 25% from £250,000, and between them 25% less 3/200 x (£250,000 - profits); dividend allowance £500;
 *   dividends 8.75% / 33.75% / 39.35% in 2025-26 and 10.75% / 35.75% / 39.35% in 2026-27.
 * Every figure is exact until the end, then rounded down to the penny; take-home is the rounded figures added up.
 */
class StructureCalculatorTest extends TestCase
{
    use RefreshDatabase;

    /** £1,000 a year of running costs, all as the accountant, so the hand sums stay readable. */
    private const COSTS_1000 = ['accountant_pence' => 100_000, 'software_pence' => 0, 'confirmation_statement_pence' => 0, 'break_even' => false];

    private const NO_COSTS = ['accountant_pence' => 0, 'software_pence' => 0, 'confirmation_statement_pence' => 0];

    /**
     * [year, profit, sole income tax, sole Class 4, sole take-home, company employer NIC, corporation tax, dividends,
     *  dividend tax, company total tax, company take-home, company difference, each partner's income tax, each
     *  partner's Class 4, partnership take-home, partnership difference] in pence.
     *
     * Company: running costs £1,000 and the best salary, which is £12,570 in every case here (employer NIC
     * 15% x (12,570 - 5,000) = 1,135.50, no employee NIC at the primary threshold, and the salary uses the whole
     * personal allowance, so every pound of dividend is taxable). Partnership: 50/50 with a spouse with no other income.
     */
    public static function workedExamples(): array
    {
        return [
            // 2025-26, £20,000.
            // Sole: income tax 20% x (20,000 - 12,570 = 7,430) = 1,486.00; Class 4 6% x 7,430 = 445.80; take-home 20,000 - 1,931.80 = 18,068.20.
            // Company: taxable 20,000 - 1,000 - 12,570 - 1,135.50 = 5,294.50; CT 19% = 1,005.955; dividends 5,294.50 - 1,005.955 = 4,288.545;
            //   dividend tax (4,288.545 - 500) x 8.75% = 331.4976875. Total tax 1,135.50 + 1,005.95 + 331.49 = 2,472.94.
            //   Take-home 12,570 + 4,288.54 - 331.49 = 16,527.05; against sole -1,541.15.
            // Partnership: £10,000 each, under the personal allowance and the Class 4 limit: no tax; take-home 20,000; +1,931.80.
            '2025-26 £20,000' => ['2025-26', 2_000_000, 148_600, 44_580, 1_806_820, 113_550, 100_595, 428_854, 33_149, 247_294, 1_652_705, -154_115, 0, 0, 2_000_000, 193_180],
            // 2025-26, £40,000.
            // Sole: income tax 20% x 27,430 = 5,486.00; Class 4 6% x 27,430 = 1,645.80; take-home 40,000 - 7,131.80 = 32,868.20.
            // Company: taxable 40,000 - 1,000 - 13,705.50 = 25,294.50; CT 19% = 4,805.955; dividends 20,488.545;
            //   dividend tax 19,988.545 x 8.75% = 1,748.9976875. Total 1,135.50 + 4,805.95 + 1,748.99 = 7,690.44.
            //   Take-home 12,570 + 20,488.54 - 1,748.99 = 31,309.55; against sole -1,558.65.
            // Partnership: £20,000 each: income tax 20% x 7,430 = 1,486; Class 4 6% x 7,430 = 445.80; 2 x 18,068.20 = 36,136.40; +3,268.20.
            '2025-26 £40,000' => ['2025-26', 4_000_000, 548_600, 164_580, 3_286_820, 113_550, 480_595, 2_048_854, 174_899, 769_044, 3_130_955, -155_865, 148_600, 44_580, 3_613_640, 326_820],
            // 2025-26, £60,000.
            // Sole: income tax 20% x 37,700 = 7,540 + 40% x (60,000 - 50,270 = 9,730) = 3,892 = 11,432.00;
            //   Class 4 6% x 37,700 = 2,262 + 2% x 9,730 = 194.60 = 2,456.60; take-home 60,000 - 13,888.60 = 46,111.40.
            // Company: taxable 60,000 - 1,000 - 13,705.50 = 45,294.50; CT 19% = 8,605.955; dividends 36,688.545;
            //   salary + dividends = 49,258.545, inside the basic band: dividend tax (36,688.545 - 500) x 8.75% = 3,166.4976875.
            //   Total 1,135.50 + 8,605.95 + 3,166.49 = 12,907.94. Take-home 12,570 + 36,688.54 - 3,166.49 = 46,092.05; against sole -19.35.
            // Partnership: £30,000 each: income tax 20% x 17,430 = 3,486; Class 4 6% x 17,430 = 1,045.80; 2 x 25,468.20 = 50,936.40; +4,825.00.
            '2025-26 £60,000' => ['2025-26', 6_000_000, 1_143_200, 245_660, 4_611_140, 113_550, 860_595, 3_668_854, 316_649, 1_290_794, 4_609_205, -1_935, 348_600, 104_580, 5_093_640, 482_500],
            // 2025-26, £120,000.
            // Sole: personal allowance 12,570 - (120,000 - 100,000) / 2 = 2,570; taxable 117,430: 20% x 37,700 = 7,540 + 40% x 79,730 = 31,892
            //   = 39,432.00; Class 4 2,262 + 2% x 69,730 = 1,394.60 = 3,656.60; take-home 120,000 - 43,088.60 = 76,911.40.
            // Company: taxable 120,000 - 1,000 - 13,705.50 = 105,294.50, between the limits: CT 25% x 105,294.50 = 26,323.625
            //   less 3/200 x (250,000 - 105,294.50 = 144,705.50) = 2,170.5825, so 24,153.0425; dividends 81,141.4575;
            //   adjusted net income 93,711.4575 (under £100,000, no taper): £500 at 0%, 37,200 x 8.75% = 3,255.00,
            //   (81,141.4575 - 37,700 = 43,441.4575) x 33.75% = 14,661.49190625; dividend tax 17,916.49190625.
            //   Total 1,135.50 + 24,153.04 + 17,916.49 = 43,205.03. Take-home 12,570 + 81,141.45 - 17,916.49 = 75,794.96; against sole -1,116.44.
            // Partnership: £60,000 each: as the £60,000 sole trader, 11,432 + 2,456.60; 2 x 46,111.40 = 92,222.80; +15,311.40.
            '2025-26 £120,000' => ['2025-26', 12_000_000, 3_943_200, 365_660, 7_691_140, 113_550, 2_415_304, 8_114_145, 1_791_649, 4_320_503, 7_579_496, -111_644, 1_143_200, 245_660, 9_222_280, 1_531_140],
            // 2026-27: sole trader and partnership as 2025-26 (same bands and Class 4); the company's dividend rates are 10.75% / 35.75%.
            // £20,000: dividend tax 3,788.545 x 10.75% = 407.2685875. Total 1,135.50 + 1,005.95 + 407.26 = 2,548.71.
            //   Take-home 12,570 + 4,288.54 - 407.26 = 16,451.28; against sole -1,616.92.
            '2026-27 £20,000' => ['2026-27', 2_000_000, 148_600, 44_580, 1_806_820, 113_550, 100_595, 428_854, 40_726, 254_871, 1_645_128, -161_692, 0, 0, 2_000_000, 193_180],
            // £40,000: dividend tax 19,988.545 x 10.75% = 2,148.7685875. Total 1,135.50 + 4,805.95 + 2,148.76 = 8,090.21.
            //   Take-home 12,570 + 20,488.54 - 2,148.76 = 30,909.78; against sole -1,958.42.
            '2026-27 £40,000' => ['2026-27', 4_000_000, 548_600, 164_580, 3_286_820, 113_550, 480_595, 2_048_854, 214_876, 809_021, 3_090_978, -195_842, 148_600, 44_580, 3_613_640, 326_820],
            // £60,000: dividend tax 36,188.545 x 10.75% = 3,890.2685875. Total 1,135.50 + 8,605.95 + 3,890.26 = 13,631.71.
            //   Take-home 12,570 + 36,688.54 - 3,890.26 = 45,368.28; against sole -743.12.
            '2026-27 £60,000' => ['2026-27', 6_000_000, 1_143_200, 245_660, 4_611_140, 113_550, 860_595, 3_668_854, 389_026, 1_363_171, 4_536_828, -74_312, 348_600, 104_580, 5_093_640, 482_500],
            // £120,000: dividend tax 37,200 x 10.75% = 3,999.00 + 43,441.4575 x 35.75% = 15,530.32105625 = 19,529.32105625.
            //   Total 1,135.50 + 24,153.04 + 19,529.32 = 44,817.86. Take-home 12,570 + 81,141.45 - 19,529.32 = 74,182.13; against sole -2,729.27.
            '2026-27 £120,000' => ['2026-27', 12_000_000, 3_943_200, 365_660, 7_691_140, 113_550, 2_415_304, 8_114_145, 1_952_932, 4_481_786, 7_418_213, -272_927, 1_143_200, 245_660, 9_222_280, 1_531_140],
        ];
    }

    #[DataProvider('workedExamples')]
    public function test_worked_examples(string $year, int $profit, int $soleIt, int $soleC4, int $soleTake, int $erNic, int $ct, int $dividends, int $divTax,
        int $coTax, int $coTake, int $coDiff, int $partnerIt, int $partnerC4, int $partTake, int $partDiff): void
    {
        $r = StructureCalculator::compare($year, $profit, self::COSTS_1000);

        $this->assertTrue($r['estimate']);
        $this->assertSame($profit, $r['profit_pence']);
        $this->assertSame([$soleIt, $soleC4, 0, $soleIt + $soleC4, $soleTake],
            [$r['sole_trader']['income_tax_pence'], $r['sole_trader']['class4_pence'], $r['sole_trader']['class2_pence'], $r['sole_trader']['total_tax_pence'], $r['sole_trader']['take_home_pence']]);

        $c = $r['company'];
        $this->assertSame('personal_allowance', $c['salary_option']);
        $this->assertSame(1_257_000, $c['salary_pence']);
        $this->assertSame(100_000, $c['running_costs']['total_pence']);
        $this->assertSame([$erNic, 0, $ct, $dividends, 0, $divTax, $coTax, $coTake, $coDiff],
            [$c['employer_nic_pence'], $c['employee_nic_pence'], $c['corporation_tax_pence'], $c['dividends_pence'], $c['income_tax_on_salary_pence'],
                $c['dividend_tax_pence'], $c['total_tax_pence'], $c['take_home_pence'], $c['difference_pence']]);
        $this->assertSame($coTake - $soleTake, $c['difference_pence']);
        // The best salary is the one that leaves the least tax of those tried.
        $this->assertSame(['personal_allowance', 'secondary_threshold', 'none'], array_column($c['salaries_tried'], 'option'));
        $this->assertSame(min(array_column($c['salaries_tried'], 'total_tax_pence')), $c['total_tax_pence']);

        $p = $r['partnership'];
        $this->assertSame('50', $p['partner_share_pct']);
        $this->assertSame([$partnerIt, $partnerC4, $partnerIt, $partnerC4], [$p['you']['income_tax_pence'], $p['you']['class4_pence'], $p['partner']['income_tax_pence'], $p['partner']['class4_pence']]);
        $this->assertSame([$partTake, $partDiff, 0], [$p['take_home_pence'], $p['difference_pence'], $p['trading_allowance_pence']]);
    }

    /**
     * The other salaries tried at £60,000 in 2025-26 (running costs £1,000):
     *  £5,000 (the secondary threshold): no NIC; taxable 54,000: CT 25% x 54,000 = 13,500 less 3/200 x 196,000 = 2,940, so 10,560;
     *    dividends 43,440, of which 7,570 uses the rest of the personal allowance; 35,870 taxable, inside the basic band:
     *    (35,870 - 500) x 8.75% = 3,094.875. Total 10,560 + 3,094.87 = 13,654.87; take-home 5,000 + 43,440 - 3,094.87 = 45,345.13.
     *  No salary: taxable 59,000: CT 14,750 less 3/200 x 191,000 = 2,865, so 11,885; dividends 47,115, 12,570 tax-free;
     *    (34,545 - 500) x 8.75% = 2,978.9375. Total 11,885 + 2,978.93 = 14,863.93; take-home 47,115 - 2,978.93 = 44,136.07.
     */
    public function test_the_salaries_tried(): void
    {
        $c = StructureCalculator::compare('2025-26', 6_000_000, self::COSTS_1000)['company'];
        $this->assertSame([
            ['option' => 'personal_allowance', 'salary_pence' => 1_257_000, 'total_tax_pence' => 1_290_794, 'take_home_pence' => 4_609_205],
            ['option' => 'secondary_threshold', 'salary_pence' => 500_000, 'total_tax_pence' => 1_365_487, 'take_home_pence' => 4_534_513],
            ['option' => 'none', 'salary_pence' => 0, 'total_tax_pence' => 1_486_393, 'take_home_pence' => 4_413_607],
        ], $c['salaries_tried']);

        // Asking for one salary uses it.
        $st = StructureCalculator::compare('2025-26', 6_000_000, ['salary' => 'secondary_threshold'] + self::COSTS_1000)['company'];
        $this->assertSame(['secondary_threshold', 500_000, 0, 1_056_000, 4_534_513], [$st['salary_option'], $st['salary_pence'], $st['employer_nic_pence'], $st['corporation_tax_pence'], $st['take_home_pence']]);
    }

    /**
     * A salary of £20,000 (2026-27, £60,000 profit, no running costs):
     *  employee NIC 8% x (20,000 - 12,570 = 7,430) = 594.40; employer NIC 15% x (20,000 - 5,000) = 2,250.00;
     *  income tax on salary 20% x 7,430 = 1,486.00; taxable profit 60,000 - 20,000 - 2,250 = 37,750: CT 19% = 7,172.50;
     *  dividends 30,577.50 sit from 7,430 to 38,007.50 of taxable income: £500 at 0% (7,430 to 7,930), 10.75% x (37,700 - 7,930 = 29,770)
     *  = 3,200.275, 35.75% x (38,007.50 - 37,700 = 307.50) = 109.93125, so 3,310.20625.
     *  Total 2,250 + 594.40 + 7,172.50 + 1,486 + 3,310.20 = 14,813.10; take-home 20,000 - 594.40 - 1,486 - 3,310.20 + 30,577.50 = 45,186.90.
     */
    public function test_a_salary_of_your_own_choosing(): void
    {
        $c = StructureCalculator::compare('2026-27', 6_000_000, ['salary' => 'custom', 'salary_pence' => 2_000_000] + self::NO_COSTS)['company'];
        $this->assertSame(['custom', 2_000_000, 59_440, 225_000, 148_600, 717_250, 3_057_750, 331_020, 1_481_310, 4_518_690], [
            $c['salary_option'], $c['salary_pence'], $c['employee_nic_pence'], $c['employer_nic_pence'], $c['income_tax_on_salary_pence'],
            $c['corporation_tax_pence'], $c['dividends_pence'], $c['dividend_tax_pence'], $c['total_tax_pence'], $c['take_home_pence']]);
    }

    public function test_the_sole_trader_figures_are_tax_estimators(): void
    {
        foreach (['2024-25', '2025-26', '2026-27'] as $year) {
            foreach ([0, 500_000, 1_257_001, 2_000_000, 4_000_000, 4_567_891, 6_000_000, 11_000_150, 12_000_000, 15_000_000] as $profit) {
                $est = TaxEstimator::estimate($year, $profit);
                $sole = StructureCalculator::compare($year, $profit, ['break_even' => false])['sole_trader'];
                $this->assertSame('tax_estimator', $sole['worked_out_with']);
                $this->assertSame($est['income_tax']['total_pence'], $sole['income_tax_pence'], "{$year} {$profit} income tax");
                $this->assertSame($est['class4_pence'], $sole['class4_pence'], "{$year} {$profit} Class 4");
                $this->assertSame($profit - $est['total_pence'], $sole['take_home_pence']);
            }
            // The calculator's own engine (used for 2027-28, pensions, partners and the break-even) agrees with it on whole £500s.
            $rates = StructureCalculator::rates($year);
            for ($profit = 0; $profit <= 20_000_000; $profit += 250_000) {
                $est = TaxEstimator::estimate($year, $profit);
                $own = StructureCalculator::incomeTax($rates, BigDecimal::of($profit))['total']->toScale(0, RoundingMode::DOWN)->toInt();
                $this->assertSame($est['income_tax']['total_pence'], $own, "{$year} {$profit} own income tax");
                $this->assertSame($est['class4_pence'], StructureCalculator::class4($rates, BigDecimal::of($profit))->toScale(0, RoundingMode::DOWN)->toInt());
            }
        }
    }

    /**
     * Partners can't use the trading allowance (ITTOIA s783AA(1)(a)): a £1,000 share is taxed in full.
     * 2026-27, profit £2,000 split 50/50; the partner already earns £30,000, so their £1,000 share is taxed at 20%: 200.00;
     * no Class 4 on £1,000 (under £12,570). Your £1,000 is under your personal allowance: nothing.
     * With 30% to a partner earning £45,000 out of £60,000 of profit: their £18,000 share takes their income to £63,000:
     * tax on 63,000 (7,540 + 40% x 12,730 = 12,632) less tax on 45,000 (20% x 32,430 = 6,486) = 6,146.00; Class 4 6% x (18,000 - 12,570) = 325.80.
     * Your £42,000: income tax 20% x 29,430 = 5,886.00; Class 4 6% x 29,430 = 1,765.80.
     */
    public function test_the_partnership_has_no_trading_allowance_and_taxes_each_share_on_its_partner(): void
    {
        foreach (['2026-27', '2027-28'] as $year) {
            $p = StructureCalculator::compare($year, 200_000, ['partner_other_income_pence' => 3_000_000, 'break_even' => false])['partnership'];
            $this->assertSame([100_000, 0, 0], [$p['you']['share_pence'], $p['you']['income_tax_pence'], $p['you']['class4_pence']]);
            $this->assertSame([100_000, 20_000, 0], [$p['partner']['share_pence'], $p['partner']['income_tax_pence'], $p['partner']['class4_pence']], "{$year}: no trading allowance for the partner");
            $this->assertSame(0, $p['trading_allowance_pence']);
            $this->assertSame(180_000, $p['take_home_pence']);
        }
        $r = StructureCalculator::compare('2026-27', 6_000_000, ['partner_share_pct' => 30, 'partner_other_income_pence' => 4_500_000, 'break_even' => false]);
        $p = $r['partnership'];
        $this->assertSame('30', $p['partner_share_pct']);
        $this->assertSame([1_800_000, 614_600, 32_580], [$p['partner']['share_pence'], $p['partner']['income_tax_pence'], $p['partner']['class4_pence']]);
        $this->assertSame([4_200_000, 588_600, 176_580], [$p['you']['share_pence'], $p['you']['income_tax_pence'], $p['you']['class4_pence']]);
        $this->assertStringContainsString('Partners can’t use the trading allowance', implode(' ', $r['assumptions']));
        $codes = array_column($r['warnings'], 'code');
        $this->assertContains('partnership_settlements', $codes);
        $this->assertContains('partnership_genuine', $codes);
    }

    /**
     * 2027-28 uses the "2027-28_announced" rates and says which are announced, not law. Its rates are the same as
     * 2026-27's for everything the comparison uses, so the figures are too. Voluntary Class 2 is £3.80 a week
     * (announced): 52 x 3.80 = 197.60 on a profit under the £7,355 small profits threshold; 2026-27's is 52 x 3.65 = 189.80.
     */
    public function test_2027_28_is_flagged_as_announced_not_law(): void
    {
        $r = StructureCalculator::compare('2027-28', 6_000_000, self::COSTS_1000);
        $this->assertTrue($r['law']['announced']);
        $this->assertSame('Announced, not yet law', $r['law']['label']);
        $this->assertStringContainsString('20%, 40% and 45%', implode(' ', $r['law']['announced_items']));
        $this->assertStringContainsString('Already law for 2027-28', $r['law']['law_note']);
        $this->assertContains('announced', array_column($r['warnings'], 'code'));
        $this->assertSame('planning_rates', $r['sole_trader']['worked_out_with']);

        $before = StructureCalculator::compare('2026-27', 6_000_000, self::COSTS_1000);
        $this->assertFalse($before['law']['announced']);
        $this->assertNotContains('announced', array_column($before['warnings'], 'code'));
        foreach (['sole_trader', 'company', 'partnership'] as $s) {
            $this->assertSame($before[$s]['take_home_pence'], $r[$s]['take_home_pence'], $s);
        }

        $this->assertSame(19_760, StructureCalculator::compare('2027-28', 500_000, ['voluntary_class2' => true, 'break_even' => false])['sole_trader']['class2_pence']);
        $this->assertSame(18_980, StructureCalculator::compare('2026-27', 500_000, ['voluntary_class2' => true, 'break_even' => false])['sole_trader']['class2_pence']);
        // At or above the threshold it is treated as paid: nothing to pay.
        $this->assertSame(0, StructureCalculator::compare('2026-27', 800_000, ['voluntary_class2' => true, 'break_even' => false])['sole_trader']['class2_pence']);
        // Without asking, no voluntary Class 2.
        $this->assertSame(0, StructureCalculator::compare('2026-27', 500_000, ['break_even' => false])['sole_trader']['class2_pence']);
    }

    /**
     * Break-even, with no running costs and all profit paid out, on a £500 grid (the research puts the band at about
     * £55,480 to £70,250 for 2025-26, £52,430 to £73,680 for 2024-25 and only about £60,150 to £60,500 for 2026-27).
     * By hand, 2025-26:
     *  £55,000: sole income tax 7,540 + 40% x 4,730 = 9,432; Class 4 2,262 + 2% x 4,730 = 94.60, so 2,356.60; take-home 43,211.40.
     *    Company: taxable 55,000 - 13,705.50 = 41,294.50; CT 19% = 7,845.955; dividends 33,448.545; dividend tax 32,948.545 x 8.75%
     *    = 2,882.9976875; take-home 12,570 + 33,448.54 - 2,882.99 = 43,135.55: behind.
     *  £55,500: sole 7,540 + 40% x 5,230 = 9,632; 2,262 + 104.60 = 2,366.60; take-home 43,501.40.
     *    Company: taxable 41,794.50; CT 7,940.955; dividends 33,853.545; dividend tax 33,353.545 x 8.75% = 2,918.4351875;
     *    take-home 12,570 + 33,853.54 - 2,918.43 = 43,505.11: ahead by 3.71, so the break-even is £55,500.
     */
    public function test_the_break_even(): void
    {
        $at = fn (int $p) => StructureCalculator::compare('2025-26', $p, self::NO_COSTS + ['break_even' => false]);
        $this->assertSame([4_321_140, 4_313_555], [$at(5_500_000)['sole_trader']['take_home_pence'], $at(5_500_000)['company']['take_home_pence']]);
        $this->assertSame([4_350_140, 4_350_511], [$at(5_550_000)['sole_trader']['take_home_pence'], $at(5_550_000)['company']['take_home_pence']]);

        $be = StructureCalculator::compare('2025-26', 6_000_000, self::NO_COSTS)['break_even'];
        $this->assertSame([5_550_000, 7_000_000, 50_000], [$be['profit_pence'], $be['ahead_until_pence'], $be['step_pence']]);
        $this->assertStringContainsString('£55,500', $be['note']);
        $this->assertStringContainsString('an estimate', $be['note']);

        $this->assertSame([5_250_000, 7_350_000], array_values(array_intersect_key(StructureCalculator::compare('2024-25', 6_000_000, self::NO_COSTS)['break_even'], array_flip(['profit_pence', 'ahead_until_pence']))));
        $this->assertSame([6_050_000, 6_050_000], array_values(array_intersect_key(StructureCalculator::compare('2026-27', 6_000_000, self::NO_COSTS)['break_even'], array_flip(['profit_pence', 'ahead_until_pence']))));

        // With the default running costs (£1,370 in 2026-27) a company never comes out ahead in 2026-27 on full extraction.
        $none = StructureCalculator::compare('2026-27', 6_000_000)['break_even'];
        $this->assertNull($none['profit_pence']);
        $this->assertStringContainsString('doesn’t leave you more to take home', $none['note']);
    }

    /**
     * £10,000 a year into a pension, 2026-27, £60,000 profit, running costs £1,000.
     *  Sole trader (relief at source): you pay 8,000 and the provider adds 2,000; the basic band grows to 47,700, so all of the
     *    47,430 taxable is at 20%: 9,486.00 (extra relief 11,432 - 9,486 = 1,946.00); Class 4 unchanged 2,456.60;
     *    take-home 60,000 - 9,486 - 2,456.60 - 8,000 = 40,057.40; cost to you 46,111.40 - 40,057.40 = 6,054.00.
     *  Company (employer contribution, before corporation tax, no NIC): taxable 45,294.50 - 10,000 = 35,294.50; CT 19% = 6,705.955;
     *    dividends 28,588.545; dividend tax 28,088.545 x 10.75% = 3,019.5185875; take-home 12,570 + 28,588.54 - 3,019.51 = 38,139.03;
     *    cost to you 45,368.28 - 38,139.03 = 7,229.25, the research's own figure (10,000 x 81% x 89.25%).
     *  Partnership: your £30,000 share stays in the basic band, so no extra relief: take-home 50,936.40 - 8,000 = 42,936.40.
     * At £120,000 the company's contribution comes off profits taxed at the 26.5% marginal rate and dividends at 35.75%:
     *  taxable 95,294.50: CT 23,823.625 - 3/200 x 154,705.50 = 2,320.5825, so 21,503.0425; dividends 73,791.4575; dividend tax
     *  3,999 + 36,091.4575 x 35.75% = 12,902.69605625, so 16,901.69605625; take-home 12,570 + 73,791.45 - 16,901.69 = 69,459.76;
     *  cost to you 74,182.13 - 69,459.76 = 4,722.37 (the research rounds 4,722.375 half up, £4,722.38).
     *  The sole trader at £120,000: adjusted net income 110,000 restores £5,000 of allowance (7,570); taxable 112,430 with bands
     *  47,700 / 135,140: 9,540 + 40% x 64,730 = 25,892, so 35,432.00; extra relief 39,432 - 35,432 = 4,000.00; cost 8,000 - 4,000 = 4,000.00.
     */
    public function test_a_pension_for_each_structure(): void
    {
        $r = StructureCalculator::compare('2026-27', 6_000_000, ['pension_pence' => 1_000_000] + self::COSTS_1000);
        $s = $r['sole_trader']['pension'];
        $this->assertSame([1_000_000, 800_000, 200_000, 194_600, 948_600, 245_660, 4_005_740, 605_400, false],
            [$s['gross_pence'], $s['you_pay_pence'], $s['added_by_provider_pence'], $s['extra_relief_pence'], $s['income_tax_pence'], $s['class4_pence'],
                $s['take_home_pence'], $s['cost_to_you_pence'], $s['capped']]);
        $c = $r['company']['pension'];
        $this->assertSame([1_000_000, 1_257_000, 670_595, 2_858_854, 301_951, 3_813_903, 722_925],
            [$c['gross_pence'], $c['salary_pence'], $c['corporation_tax_pence'], $c['dividends_pence'], $c['dividend_tax_pence'], $c['take_home_pence'], $c['cost_to_you_pence']]);
        $this->assertSame(3_813_903 - 4_005_740, $c['difference_pence']);
        $p = $r['partnership']['pension'];
        $this->assertSame([1_000_000, 800_000, 0, 4_293_640, 800_000], [$p['gross_pence'], $p['you_pay_pence'], $p['extra_relief_pence'], $p['take_home_pence'], $p['cost_to_you_pence']]);
        $this->assertContains('pension_company', array_column($r['warnings'], 'code'));
        $this->assertContains('https://www.gov.uk/government/publications/rates-and-allowances-pension-schemes/pension-schemes-rates', array_column($r['sources'], 'url'));

        $high = StructureCalculator::compare('2026-27', 12_000_000, ['pension_pence' => 1_000_000] + self::COSTS_1000);
        $this->assertSame([2_150_304, 7_379_145, 1_690_169, 6_945_976, 472_237],
            [$high['company']['pension']['corporation_tax_pence'], $high['company']['pension']['dividends_pence'], $high['company']['pension']['dividend_tax_pence'],
                $high['company']['pension']['take_home_pence'], $high['company']['pension']['cost_to_you_pence']]);
        $this->assertSame([3_543_200, 400_000, 400_000], [$high['sole_trader']['pension']['income_tax_pence'], $high['sole_trader']['pension']['extra_relief_pence'], $high['sole_trader']['pension']['cost_to_you_pence']]);

        // Relief on your own contributions stops at your earnings; the company can only pay in what is left after salary.
        $capped = StructureCalculator::compare('2026-27', 200_000, ['pension_pence' => 1_000_000] + self::COSTS_1000);
        $this->assertTrue($capped['sole_trader']['pension']['capped']);
        $this->assertSame(360_000, $capped['sole_trader']['pension']['gross_pence'], 'up to £3,600 gross with low earnings');
        $this->assertContains('pension_capped', array_column($capped['warnings'], 'code'));
        $this->assertNull(StructureCalculator::compare('2026-27', 6_000_000, ['break_even' => false])['company']['pension']);
    }

    /**
     * Leaving money in the company (2026-27, £80,000, no running costs): employer NIC 1,135.50; taxable 66,294.50:
     * CT 25% x 66,294.50 = 16,573.625 less 3/200 x 183,705.50 = 2,755.5825, so 13,818.0425; 52,476.4575 after tax, 14,776.45 kept,
     * 37,700.0075 paid out: dividend tax 37,200 x 10.75% = 3,999.00 (plus 0.0075 x 35.75%, under a penny). Tax now 1,135.50 + 13,818.04
     * + 3,999.00 = 18,952.54, as the research's own partial-extraction example; take-home 12,570 + 37,700.00 - 3,999.00 = 46,271.00.
     */
    public function test_keeping_profit_in_the_company(): void
    {
        $r = StructureCalculator::compare('2026-27', 8_000_000, ['retain_pence' => 1_477_645, 'salary' => 'personal_allowance', 'break_even' => false] + self::NO_COSTS);
        $c = $r['company'];
        $this->assertSame([113_550, 1_381_804, 1_477_645, 3_770_000, 399_900, 1_895_254, 4_627_100],
            [$c['employer_nic_pence'], $c['corporation_tax_pence'], $c['retained_pence'], $c['dividends_pence'], $c['dividend_tax_pence'], $c['total_tax_pence'], $c['take_home_pence']]);
        $this->assertContains('retained', array_column($r['warnings'], 'code'));
    }

    /**
     * Small profits. 2026-27, £5,000 profit, default running costs 1,200 + 120 + 50 = 1,370: the company has 3,630 left, under the
     * £5,000 secondary threshold, so the £12,570 salary is cut to 3,630 with no NIC and nothing left to tax: take-home 3,630.00
     * (the sole trader keeps all 5,000). At £1,000 profit the costs are more than the profit: you would put in 370.00.
     */
    public function test_low_profits_cut_the_salary_and_show_a_shortfall(): void
    {
        $r = StructureCalculator::compare('2026-27', 500_000, ['break_even' => false]);
        $c = $r['company'];
        $this->assertSame([137_000, ['accountant', 'software', 'confirmation_statement']], [$c['running_costs']['total_pence'], $c['running_costs']['defaults']]);
        $this->assertSame([363_000, true, 0, 0, 0, 363_000, -137_000], [$c['salary_pence'], $c['salary_capped'], $c['employer_nic_pence'], $c['corporation_tax_pence'], $c['dividends_pence'], $c['take_home_pence'], $c['difference_pence']]);
        $this->assertContains('salary_capped', array_column($r['warnings'], 'code'));

        $loss = StructureCalculator::compare('2026-27', 100_000, ['break_even' => false]);
        $this->assertSame([0, -37_000, -37_000], [$loss['company']['salary_pence'], $loss['company']['shortfall_pence'], $loss['company']['take_home_pence']]);
        $this->assertContains('costs_exceed_profit', array_column($loss['warnings'], 'code'));

        // The defaults by year: the confirmation statement fee for most of the year.
        $this->assertSame(135_400, StructureCalculator::compare('2025-26', 500_000, ['break_even' => false])['company']['running_costs']['total_pence']);
        $this->assertStringContainsString('Expend’s placeholder', implode(' ', $r['assumptions']));
    }

    public function test_every_result_lists_its_assumptions_sources_and_warnings_in_whole_pence(): void
    {
        $r = StructureCalculator::compare('2026-27', 12_345_678, ['pension_pence' => 500_000, 'partner_other_income_pence' => 1_000_000]);
        $codes = array_column($r['warnings'], 'code');
        foreach (['not_advice', 'ir35', 'company_admin', 'partnership_settlements', 'partnership_genuine', 'hicbc', 'taper'] as $code) {
            $this->assertContains($code, $codes);
        }
        $ir35 = collect($r['warnings'])->firstWhere('code', 'ir35');
        $this->assertStringContainsString('doesn’t work this out', $ir35['text']);
        $this->assertStringContainsString('accountant', collect($r['warnings'])->firstWhere('code', 'not_advice')['text']);
        foreach ($r['warnings'] as $w) {
            $this->assertTrue($w['url'] === null || str_starts_with($w['url'], 'https://'), $w['code']);
        }
        $this->assertNotEmpty($r['assumptions']);
        $planningUrls = [];
        foreach (Planning::SECTIONS as $section) {
            foreach (Planning::data()[$section] as $i) {
                $planningUrls[] = $i['url'];
            }
        }
        $this->assertGreaterThanOrEqual(10, count($r['sources']));
        foreach ($r['sources'] as $s) {
            $this->assertContains($s['url'], $planningUrls, 'every source is a link from the research');
        }
        $this->assertSame(Planning::researchedOn(), $r['researched_on']);

        $walk = function (array $a, string $path = '') use (&$walk) {
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $walk($v, $path.'.'.$k);
                } elseif (is_string($k) && str_ends_with($k, '_pence')) {
                    $this->assertTrue(is_int($v) || $v === null, "{$path}.{$k} is whole pence");
                }
            }
        };
        $walk($r);
    }

    public function test_a_scottish_taxpayer_is_told_the_rates_are_rest_of_uk(): void
    {
        Setting::put('scottish_taxpayer', true);
        $r = StructureCalculator::compare('2026-27', 6_000_000, ['break_even' => false]);
        $this->assertContains('scottish', array_column($r['warnings'], 'code'));
        $this->assertSame('planning_rates', $r['sole_trader']['worked_out_with']);
        $this->assertSame(4_611_140, $r['sole_trader']['take_home_pence']);
    }

    public function test_a_year_without_research_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StructureCalculator::compare('2023-24', 1_000_000);
    }

    /**
     * The pension's extra relief is worked out one way on both sides, whatever the sole trader's figure came from.
     * 2025-26, profit £110,001, £3,000 gross into a pension (ITA 2007 s35(3): a tapered allowance is rounded up to the
     * next whole pound):
     *  Without: adjusted net income 110,001; allowance 12,570 - 5,000.50 = 7,569.50, rounded up to 7,570; taxable 102,431:
     *    7,540 + 40% x 64,731 = 25,892.40, so 33,432.40.
     *  With: adjusted net income 107,001; allowance 12,570 - 3,500.50 = 9,069.50, rounded up to 9,070; taxable 100,931 with the
     *    basic band at 40,700: 8,140 + 40% x 60,231 = 24,092.40, so 32,232.40. Extra relief 1,200.00.
     *  TaxEstimator doesn't round the allowance, so its figure without a pension is 33,432.60 (40% x 64,731.50); the figure
     *  with one is that less the relief, 32,232.60. You pay 2,400 (80%); the cost to you is 2,400 - 1,200 = 1,200.00.
     */
    public function test_pension_relief_is_worked_out_the_same_way_on_both_sides(): void
    {
        $r = StructureCalculator::compare('2025-26', 11_000_100, ['pension_pence' => 300_000, 'break_even' => false] + self::NO_COSTS);
        $this->assertSame(3_343_260, $r['sole_trader']['income_tax_pence'], 'TaxEstimator, without a pension');
        $p = $r['sole_trader']['pension'];
        $this->assertSame([120_000, 3_223_260, 240_000, 120_000], [$p['extra_relief_pence'], $p['income_tax_pence'], $p['you_pay_pence'], $p['cost_to_you_pence']]);

        // Wherever the two roundings differ (odd pounds over £100,000), the relief is the engine's own difference.
        foreach (['2024-25', '2025-26', '2026-27'] as $year) {
            $rates = StructureCalculator::rates($year);
            foreach ([10_000_100, 11_000_100, 12_000_300] as $profit) {
                foreach ([100_000, 300_000, 500_000] as $gross) {
                    $tax = fn (?BigDecimal $ext) => StructureCalculator::incomeTax($rates, BigDecimal::of($profit), BigDecimal::zero(), $ext)['total']->toScale(0, RoundingMode::DOWN)->toInt();
                    $relief = $tax(null) - $tax(BigDecimal::of($gross));
                    $c = StructureCalculator::compare($year, $profit, ['pension_pence' => $gross, 'break_even' => false] + self::NO_COSTS);
                    $pension = $c['sole_trader']['pension'];
                    $this->assertSame($relief, $pension['extra_relief_pence'], "{$year} {$profit} {$gross}");
                    $this->assertSame($c['sole_trader']['income_tax_pence'] - $relief, $pension['income_tax_pence']);
                    $this->assertSame($pension['you_pay_pence'] - $relief, $pension['cost_to_you_pence']);
                }
            }
        }
    }

    /**
     * Every band where a company is ahead, not just the first. 2024-25, no running costs: from £52,500 to £73,500, and
     * again from £123,000 to £127,500 in the personal allowance taper (the research: "a tiny band of about
     * £122,900-£127,680"). By hand at £125,000 (2024-25: employer NIC 13.8% above £9,100; dividends 8.75% / 33.75%):
     *  Sole: allowance 12,570 - 12,500 = 70; taxable 124,930: 7,540 + 40% x 87,230 = 34,892, so 42,432.00; Class 4 2,262 +
     *    2% x 74,730 = 1,494.60, so 3,756.60; take-home 125,000 - 46,188.60 = 78,811.40.
     *  Company, £12,570 salary: employer NIC 13.8% x 3,470 = 478.86; taxable 111,951.14: CT 27,987.785 less 3/200 x 138,048.86 =
     *    2,070.7329, so 25,917.0521; dividends 86,034.0879 (adjusted net income 98,604.09, no taper): £500 at 0%, 37,200 x 8.75%
     *    = 3,255.00, 48,334.0879 x 33.75% = 16,312.75466625, so 19,567.75466625; take-home 12,570 + 86,034.08 - 19,567.75 =
     *    79,036.33: ahead by 224.93.
     */
    public function test_the_break_even_reports_every_band_where_a_company_is_ahead(): void
    {
        $at = StructureCalculator::compare('2024-25', 12_500_000, self::NO_COSTS + ['break_even' => false]);
        $this->assertSame([7_881_140, 7_903_633, 22_493], [$at['sole_trader']['take_home_pence'], $at['company']['take_home_pence'], $at['company']['difference_pence']]);

        $be = StructureCalculator::compare('2024-25', 6_000_000, self::NO_COSTS)['break_even'];
        $this->assertSame([['from_pence' => 5_250_000, 'until_pence' => 7_350_000], ['from_pence' => 12_300_000, 'until_pence' => 12_750_000]], $be['windows']);
        $this->assertSame([5_250_000, 7_350_000], [$be['profit_pence'], $be['ahead_until_pence']]);
        $this->assertStringContainsString('from about £52,500 to about £73,500, and again from about £123,000 to about £127,500, in 2024-25', $be['note']);
        // The page's own figure inside the second band agrees with the note.
        $this->assertGreaterThan(0, StructureCalculator::compare('2024-25', 12_514_000, self::NO_COSTS + ['break_even' => false])['company']['difference_pence']);

        // A company that is ahead at a single £500 step says so plainly.
        $one = StructureCalculator::compare('2026-27', 6_000_000, self::NO_COSTS)['break_even'];
        $this->assertSame([['from_pence' => 6_050_000, 'until_pence' => 6_050_000]], $one['windows']);
        $this->assertStringContainsString('at a profit of about £60,500 only in 2026-27', $one['note']);
        $this->assertStringNotContainsString('stays ahead', $one['note']);
        $this->assertSame([], StructureCalculator::compare('2026-27', 6_000_000)['break_even']['windows']);
    }

    /** The break-even search doesn't depend on the profit, so it is kept rather than run again on every change. */
    public function test_the_break_even_search_is_kept_between_comparisons(): void
    {
        $key = StructureCalculator::breakEvenCacheKey('2025-26', self::NO_COSTS);
        $this->assertSame($key, StructureCalculator::breakEvenCacheKey('2025-26', self::NO_COSTS + ['pension_pence' => 500_000, 'retain_pence' => 100_000,
            'partner_share_pct' => 30, 'partner_other_income_pence' => 2_000_000, 'voluntary_class2' => true]), 'profit-only options share it');
        $this->assertNotSame($key, StructureCalculator::breakEvenCacheKey('2025-26', self::NO_COSTS + ['salary' => 'none']));
        $this->assertNotSame($key, StructureCalculator::breakEvenCacheKey('2025-26', ['accountant_pence' => 100_000] + self::NO_COSTS));
        $this->assertNotSame($key, StructureCalculator::breakEvenCacheKey('2026-27', self::NO_COSTS));

        $first = StructureCalculator::compare('2025-26', 6_000_000, self::NO_COSTS)['break_even'];
        $this->assertSame([['from_pence' => 5_550_000, 'until_pence' => 7_000_000]], Cache::get($key));
        // A later comparison at another profit reads what was kept.
        Cache::put($key, [['from_pence' => 1_000_000, 'until_pence' => 1_500_000]]);
        $later = StructureCalculator::compare('2025-26', 8_000_000, self::NO_COSTS + ['pension_pence' => 500_000])['break_even'];
        $this->assertSame(1_000_000, $later['profit_pence']);
        $this->assertSame(5_550_000, $first['profit_pence']);
    }

    /**
     * A partner whose other income is self-employment pays Class 4 on their profits from both together (SSCBA 1992 Sch 2
     * para 4). 2026-27, profit £40,000 split 50/50; the partner has £30,000 of other trading profit:
     *  Class 4 on 50,000 is 6% x 37,430 = 2,245.80, on 30,000 alone 6% x 17,430 = 1,045.80: the share adds 1,200.00 (not 6% x 7,430
     *  = 445.80 on the share alone). Income tax 20% x 37,430 - 20% x 17,430 = 4,000.00 either way.
     */
    public function test_a_partners_self_employed_income_is_counted_for_class4(): void
    {
        $base = ['partner_other_income_pence' => 3_000_000, 'break_even' => false];
        $se = StructureCalculator::compare('2026-27', 4_000_000, $base + ['partner_other_self_employed' => true]);
        $this->assertSame([2_000_000, 400_000, 120_000], [$se['partnership']['partner']['share_pence'], $se['partnership']['partner']['income_tax_pence'], $se['partnership']['partner']['class4_pence']]);
        $this->assertTrue($se['options']['partner_other_self_employed']);
        $this->assertStringContainsString('from self-employment', implode(' ', $se['assumptions']));

        $wages = StructureCalculator::compare('2026-27', 4_000_000, $base);
        $this->assertSame([400_000, 44_580], [$wages['partnership']['partner']['income_tax_pence'], $wages['partnership']['partner']['class4_pence']]);
        $this->assertSame($wages['partnership']['take_home_pence'] - 75_420, $se['partnership']['take_home_pence']);
        $this->assertStringContainsString('taken to be wages or a pension, not self-employment', implode(' ', $wages['assumptions']));
    }

    /**
     * A salary under the lower earnings limit earns no State Pension year, and under the childcare minimum (salary only,
     * not dividends) may lose Tax-Free Childcare and the 30 hours. Limits (planning: class1-employee-nic, tax-free-childcare):
     * LEL 52 x £125 = £6,500 (2025-26), 52 x £129 = £6,708 (2026-27); childcare 16 x 52 x £12.21 = £10,158.72 (2025-26),
     * 16 x 52 x £12.71 = £10,574.72 (2026-27), 16 x 52 x £13.21 = £10,990.72 (2027-28, announced).
     */
    public function test_a_low_salary_says_what_it_costs_besides_tax(): void
    {
        $warning = fn (array $r) => collect($r['warnings'])->firstWhere('code', 'low_salary');
        $st = $warning(StructureCalculator::compare('2025-26', 6_000_000, ['salary' => 'secondary_threshold', 'break_even' => false]));
        $this->assertNotNull($st);
        foreach (['£5,000', '£6,500', 'qualifying year', 'State Pension', 'Tax-Free Childcare', '30 hours', 'not dividends', '£10,158.72'] as $text) {
            $this->assertStringContainsString($text, $st['text']);
        }
        $custom = $warning(StructureCalculator::compare('2026-27', 6_000_000, ['salary' => 'custom', 'salary_pence' => 900_000, 'break_even' => false]));
        $this->assertStringContainsString('£10,574.72', $custom['text']);
        $this->assertStringNotContainsString('qualifying year', $custom['text'], '£9,000 is over the £6,708 lower earnings limit');
        $this->assertStringContainsString('announced', $warning(StructureCalculator::compare('2027-28', 6_000_000, ['salary' => 'none', 'break_even' => false]))['text']);
        $this->assertNull($warning(StructureCalculator::compare('2026-27', 6_000_000, ['break_even' => false])), 'the best salary, £12,570, is over both');
    }

    public function test_the_warnings_cover_the_agency_rules_the_year_you_incorporate_and_who_your_partner_is(): void
    {
        $r = StructureCalculator::compare('2026-27', 6_000_000, ['break_even' => false]);
        $w = collect($r['warnings'])->keyBy('code');
        // The agency rules reach a company too, and inside IR35 a company usually costs more.
        foreach (['agency rules (ITEPA s44)', 'your own company too', 'PAYE', 'usually costs more than staying a sole trader'] as $text) {
            $this->assertStringContainsString($text, $w['ir35']['text']);
        }
        // The year of incorporation: kit counts as sold, and s162 must be claimed from 6 April 2026.
        foreach (['counts as selling it', 'income tax and Class 4', 'no annual investment allowance', 'writing-down allowances', 's266', 'TCGA s162', 'claimed'] as $text) {
            $this->assertStringContainsString($text, $w['incorporation_year']['text']);
        }
        $this->assertSame(Planning::item('caa-s266-election')['url'], $w['incorporation_year']['url']);
        $this->assertStringContainsString('one-off costs', implode(' ', $r['assumptions']));
        $this->assertStringNotContainsString('s162', collect(StructureCalculator::compare('2025-26', 6_000_000, ['break_even' => false])['warnings'])->firstWhere('code', 'incorporation_year')['text']);
        $this->assertContains(Planning::item('incorporation-relief-claim')['url'], array_column($r['sources'], 'url'));
        // Settlements: a spouse or civil partner differs from an unmarried partner; a child's share has a £100 floor.
        foreach (['spouse or civil partner', 'ITTOIA s626', 'aren’t married', 'generally theirs', 'no spouse relief from capital gains tax or inheritance tax', 'Marriage Allowance', 'more than £100 a year'] as $text) {
            $this->assertStringContainsString($text, $w['partnership_settlements']['text']);
        }
        // IR35 through a partnership: a spouse is a relative, so the 60% test is always met with them.
        foreach (['ITEPA s52(2)(a)', 'counts as a relative', '100%', 'IR35 applies to any engagement'] as $text) {
            $this->assertStringContainsString($text, $w['partnership_genuine']['text']);
        }
        $this->assertStringContainsString('your 50% share it applies only if', $w['partnership_genuine']['text']);
        $seventy = collect(StructureCalculator::compare('2026-27', 6_000_000, ['partner_share_pct' => 30, 'break_even' => false])['warnings'])->firstWhere('code', 'partnership_genuine');
        $this->assertStringContainsString('your own 70% share is 60% or more', $seventy['text']);
    }

    /** The research disputes the 2027-28 dividend upper rate (notes: "Disputed: s455 / dividend upper rate for 2027-28"). */
    public function test_2027_28_says_the_dividend_upper_rate_was_disputed(): void
    {
        $r = StructureCalculator::compare('2027-28', 12_000_000, ['break_even' => false]);
        $this->assertSame([StructureCalculator::DISPUTED_2027_28], $r['law']['disputed']);
        $this->assertStringContainsString('35.75%', $r['law']['law_note']);
        $this->assertStringContainsString('Disputed', $r['law']['law_note']);
        $this->assertStringContainsString('FA 2026 s4', collect($r['warnings'])->firstWhere('code', 'announced')['text']);
        $this->assertSame([], StructureCalculator::compare('2026-27', 12_000_000, ['break_even' => false])['law']['disputed']);
    }

    /** No option is both a name and that name plus "_pence", which the assistant would merge. */
    public function test_the_options_echoed_have_no_clashing_names(): void
    {
        $o = StructureCalculator::compare('2025-26', 6_000_000, ['salary' => 'custom', 'salary_pence' => 900_000, 'break_even' => false])['options'];
        $this->assertSame(['custom', 900_000], [$o['salary_option'], $o['custom_salary_pence']]);
        foreach (array_keys($o) as $k) {
            $this->assertArrayNotHasKey($k.'_pence', $o, $k);
        }
    }
}
