<?php

namespace Tests\Feature;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\LatePaymentInterest;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\TaxEstimator;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\TaxYear;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Late payment interest (FA 2009 s101): each run of days at one balance, rate and year length, divided by 365 (366 in
 * a leap year) and rounded down to the penny, then added up. That's the only method that gives HMRC's published
 * £96.89 (GOV.UK Interest rate review, 3 December 2020, para 2.14). The other figures are the settled rules' computed
 * vectors, checked day by day outside the app.
 */
class PenaltyInterestTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** The rates in force for HMRC's example (GOV.UK rates table); the app's own table starts in 2020. */
    private const FIXTURE_RATES = [
        ['from' => '2016-08-23', 'pct' => '2.75'],
        ['from' => '2017-11-21', 'pct' => '3.00'],
        ['from' => '2018-08-21', 'pct' => '3.25'],
        ['from' => '2020-03-30', 'pct' => '2.75'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    private function rates(): array
    {
        return PenaltyEstimator::rules()['interest']['late_payment_rates'];
    }

    private function tx(string $date, int $pence, string $kind, ?string $code = null): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $kind.' '.$this->n, 'merchant_key' => strtoupper($kind).$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $code ? Category::query()->where('code', $code)->value('id') : null));

        return $tx;
    }

    public function test_hmrcs_published_example_comes_to_96_89(): void
    {
        $vector = PenaltyEstimator::rules()['interest']['unit_test_vector'];
        $this->assertSame(9689, $vector['expected_pence']);

        $r = LatePaymentInterest::calculate($vector['amount_pence'], $this->d($vector['due']), $this->d($vector['paid']), [], self::FIXTURE_RATES);

        $this->assertSame(9689, $r['pence']);
        $this->assertSame(1155, $r['days']);
        // Five runs, exactly as HMRC show them: 22.07 + 22.43 + 44.34 + 7.90 + 0.15.
        $this->assertSame(array_map(fn ($s) => [$s['from'], $s['to'], $s['days'], $s['pct'], $s['divisor'], $s['pence']], $vector['segments']),
            array_map(fn ($s) => [$s['from'], $s['to'], $s['days'], $s['pct'], $s['divisor'], $s['pence']], $r['segments']));
        // No new run on 1 January 2019 (2018 and 2019 are both 365 days long), but one on 1 January 2020.
        $this->assertSame(['2018-08-21', '2019-12-31', 498], [$r['segments'][2]['from'], $r['segments'][2]['to'], $r['segments'][2]['days']]);
        $this->assertSame(['2020-01-01', 366], [$r['segments'][3]['from'], $r['segments'][3]['divisor']]);
    }

    public function test_interest_over_the_2024_leap_year_and_the_rate_change_on_24_november_2026(): void
    {
        // £1,000 due 31 January 2024: 1,027 days to 23 November 2026, then a day at 8.00% from 24 November 2026.
        $to23 = LatePaymentInterest::calculate(100_000, $this->d('2024-01-31'), $this->d('2026-11-23'), [], $this->rates());
        $to24 = LatePaymentInterest::calculate(100_000, $this->d('2024-01-31'), $this->d('2026-11-24'), [], $this->rates());

        $this->assertSame(21_833, $to23['pence']);
        $this->assertSame(1027, $to23['days']);
        $this->assertSame(21_854, $to24['pence']);
        $last = end($to24['segments']);
        $this->assertSame(['2026-11-24', '2026-11-24', 1, '8.00', 21], [$last['from'], $last['to'], $last['days'], $last['pct'], $last['pence']]);

        // 2024 is divided by 366 and splits from 2025 at the year end; 2025 and 2026 (both 365) don't split.
        $ends = array_column($to24['segments'], 'to');
        $this->assertContains('2024-12-31', $ends);
        $this->assertNotContains('2025-12-31', $ends);
        $this->assertSame([366, 366, 366, 365], array_slice(array_column($to24['segments'], 'divisor'), 0, 4));
    }

    public function test_a_year_with_no_leap_day_and_several_rate_changes(): void
    {
        // £1,000 due 31 January 2025, paid 31 January 2026: 365 days, £79.55.
        $r = LatePaymentInterest::calculate(100_000, $this->d('2025-01-31'), $this->d('2026-01-31'), [], $this->rates());

        $this->assertSame(7_955, $r['pence']);
        $this->assertSame(365, $r['days']);
        $this->assertSame(['7.25', '7.00', '8.50', '8.25', '8.00', '7.75'], array_column($r['segments'], 'pct'));
    }

    public function test_a_part_payment_lowers_the_balance_from_the_next_day(): void
    {
        // £1,000 due 31 January 2026; £400 paid 28 February: that day still bears £1,000, then £600 from 1 March.
        $r = LatePaymentInterest::calculate(100_000, $this->d('2026-01-31'), $this->d('2026-03-31'), [['date' => $this->d('2026-02-28'), 'amount' => 40_000]], $this->rates());

        $this->assertSame([
            ['from' => '2026-02-01', 'to' => '2026-02-28', 'days' => 28, 'balance_pence' => 100_000, 'pct' => '7.75', 'divisor' => 365, 'pence' => 594],
            ['from' => '2026-03-01', 'to' => '2026-03-31', 'days' => 31, 'balance_pence' => 60_000, 'pct' => '7.75', 'divisor' => 365, 'pence' => 394],
        ], $r['segments']);
        $this->assertSame(988, $r['pence']);
    }

    public function test_the_due_day_carries_no_interest_and_the_payment_day_does(): void
    {
        $this->assertSame(0, LatePaymentInterest::calculate(100_000, $this->d('2026-01-31'), $this->d('2026-01-31'), [], $this->rates())['pence']);
        // Paid on 1 February: one day (CH140220).
        $one = LatePaymentInterest::calculate(100_000, $this->d('2026-01-31'), $this->d('2026-02-01'), [], $this->rates());
        $this->assertSame([1, 21], [$one['days'], $one['pence']]); // 1,000 x 7.75% / 365 = 21.23p, rounded down
        // Paid in full on the due date: nothing.
        $this->assertSame(0, LatePaymentInterest::calculate(100_000, $this->d('2026-01-31'), $this->d('2026-11-24'), [['date' => $this->d('2026-01-31'), 'amount' => 100_000]], $this->rates())['pence']);
        // Paid in full on 1 February: just that day.
        $this->assertSame(21, LatePaymentInterest::calculate(100_000, $this->d('2026-01-31'), $this->d('2026-11-24'), [['date' => $this->d('2026-02-01'), 'amount' => 100_000]], $this->rates())['pence']);
    }

    public function test_the_estimator_uses_it_and_says_how(): void
    {
        // 2022-23 filed on time, the tax never paid: due 31 January 2024, so the 2024 leap year and every rate change
        // since are in it.
        $this->tx('2022-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2022-23')->update(['status' => 'filed', 'filed_on' => '2024-01-20']);
        $e = TaxEstimator::estimate('2022-23', 3_000_000);
        $L = $e['income_tax']['total_pence'] + $e['class4_pence'] + $e['class2_pence'];

        $y = PenaltyEstimator::forYear('2022-23');
        $balancing = collect($y['obligations'])->firstWhere('kind', 'balancing');
        $expected = LatePaymentInterest::calculate($L, $this->d('2024-01-31'), $this->d('2026-11-24'), [], $this->rates());

        $this->assertSame($L, $balancing['amount_pence']);
        $this->assertSame($expected['pence'], $balancing['interest_pence']);
        $this->assertSame($expected['segments'], $balancing['interest_segments']);
        $this->assertSame(array_sum(array_column($balancing['interest_segments'], 'pence')), $balancing['interest_pence']);
        $this->assertContains('2024-12-31', array_column($balancing['interest_segments'], 'to'));
        // Tomorrow's interest on each amount still owed (the tax and the penalties on it): a day at 8.00% of a
        // 365-day year, to the nearest penny.
        $owed = collect($y['obligations'])->filter(fn ($o) => $o['due'] < '2026-11-24')->pluck('outstanding_pence');
        $this->assertContains($L, $owed->all());
        $this->assertSame($owed->sum(fn ($o) => (int) round($o * 8 / 36_500)), $y['per_day_pence']);
        $this->assertNotEmpty(array_filter($y['assumptions'], fn ($a) => str_contains($a, 'rounded down to the penny') && str_contains($a, 'estimate')));
    }

    public function test_the_rules_file_records_the_method_chosen(): void
    {
        $method = PenaltyEstimator::rules()['interest']['method'];

        $this->assertStringContainsString('truncated down to the penny', $method);
        $this->assertStringContainsString('366 for days in 2024 or 2028', $method);
        $this->assertStringContainsString('DMBM404010', $method);
        $this->assertStringContainsString('£96.89', $method);
    }
}
