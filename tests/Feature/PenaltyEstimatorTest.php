<?php

namespace Tests\Feature;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\TaxEstimator;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Fines and interest for late returns and late tax, worked out from the rules and your records. */
class PenaltyEstimatorTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow('2026-11-23 10:00:00');
        CarbonImmutable::setTestNow('2026-11-23 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tx(string $date, int $pence, string $kind, ?string $code = null): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $kind, 'merchant_key' => strtoupper($kind), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $code ? Category::query()->where('code', $code)->value('id') : null));

        return $tx;
    }

    private function liability(string $label, int $profit): int
    {
        $e = TaxEstimator::estimate($label, $profit);

        return $e['income_tax']['total_pence'] + $e['class4_pence'] + $e['class2_pence'];
    }

    private function codes(array $year, string $list = 'penalties'): array
    {
        return collect($year[$list])->mapWithKeys(fn ($p) => [$p['code'] => [$p['date'], $p['amount_pence']]])->all();
    }

    public function test_a_return_never_filed_and_tax_never_paid(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $L = $this->liability('2024-25', 3_000_000);
        $y = PenaltyEstimator::forYear('2024-25');

        $this->assertSame($L, $y['liability_pence']);
        $this->assertSame([
            'sch55_initial' => ['2026-02-01', 10_000],
            'sch55_daily' => ['2026-05-01', 90_000],
            'sch55_6m' => ['2026-08-01', max(30_000, intdiv($L * 5, 100))],
            'sch56_30d' => ['2026-03-03', intdiv($L * 5, 100)],
            'sch56_6m' => ['2026-08-03', intdiv($L * 5, 100)],
        ], $this->codes($y));
        $this->assertSame([
            'sch55_12m' => ['2027-02-01', max(30_000, intdiv($L * 5, 100))],
            'sch56_12m' => ['2027-02-03', intdiv($L * 5, 100)],
        ], $this->codes($y, 'upcoming'));

        // Interest on the balancing payment: 296 days from 31 January 2026 at 7.75% (in force from 9 January 2026).
        $balancing = collect($y['obligations'])->firstWhere('kind', 'balancing');
        $this->assertSame((int) round($L * 7.75 / 100 * 296 / 365), $balancing['interest_pence']);
        $this->assertSame($L, $balancing['outstanding_pence']);
        $this->assertGreaterThan(0, $y['per_day_pence']);
        $this->assertStringContainsString('File the return as soon as you can', $y['actions'][0]);
    }

    public function test_filed_and_paid_on_time_costs_nothing(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20']);
        $L = $this->liability('2024-25', 3_000_000);
        $this->tx('2026-01-28', -$L, 'tax_payment');

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame([], $y['penalties']);
        $this->assertSame([], $y['upcoming']);
        $this->assertSame(0, $y['interest_pence']);
        $this->assertSame(0, $y['unpaid_pence']);
    }

    public function test_filed_late_and_paid_late_counts_only_until_then(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-05-11']);
        $L = $this->liability('2024-25', 3_000_000);
        $this->tx('2026-05-11', -$L, 'tax_payment');

        $y = PenaltyEstimator::forYear('2024-25');
        $codes = $this->codes($y);
        $this->assertSame(['2026-05-01', 10 * 1_000], $codes['sch55_daily'], 'Ten days, 1 to 10 May');
        $this->assertSame(['2026-03-03', intdiv($L * 5, 100)], $codes['sch56_30d']);
        $this->assertArrayNotHasKey('sch55_6m', $codes);
        $this->assertArrayNotHasKey('sch56_6m', $codes);
        $this->assertSame([], $y['upcoming']);
        // Interest on the tax: 100 days (1 February to 11 May) at 7.75%.
        $this->assertSame((int) round($L * 7.75 / 100 * 100 / 365), collect($y['obligations'])->firstWhere('kind', 'balancing')['interest_pence']);
    }

    public function test_what_if_you_file_and_pay_next_week_and_a_payment_plan(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $y = PenaltyEstimator::forYear('2024-25', null, ['filed_on' => ['2024-25' => '2026-11-30'], 'pay_all_on' => '2026-11-30']);
        $this->assertSame([], $y['upcoming'], 'Filing and paying before 1 February avoids the 12-month penalties');

        TaxYear::query()->where('label', '2024-25')->update(['payment_plan_from' => '2026-06-01']);
        $codes = $this->codes(PenaltyEstimator::forYear('2024-25'));
        $this->assertArrayHasKey('sch56_30d', $codes, 'A plan doesn’t undo a penalty already charged');
        $this->assertArrayNotHasKey('sch56_6m', $codes, 'but it stops the later ones');
    }

    public function test_payments_on_account_carry_interest_but_no_late_payment_penalty(): void
    {
        // 2023-24's tax makes payments on account due for 2024-25 (31 January and 31 July 2025).
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000]);
        $relevantPrev = TaxEstimator::estimate('2023-24', 4_000_000);
        $poa = intdiv($relevantPrev['income_tax']['total_pence'] + $relevantPrev['class4_pence'], 2);
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10', 'filed_profit_pence' => 4_000_000]);
        $this->tx('2025-01-20', -$this->liability('2023-24', 4_000_000), 'tax_payment'); // 2023-24's bill, on time

        $y = PenaltyEstimator::forYear('2024-25');
        $kinds = collect($y['obligations'])->keyBy('kind');
        $this->assertSame($poa, $kinds['poa1']['amount_pence']);
        $this->assertGreaterThan(0, $kinds['poa1']['interest_pence']);
        // 2024-25's tax (lower Class 4) is less than the two payments on account, so nothing more is due for the
        // year: the unpaid payments on account carry interest (on half this year's tax, the most needed) but no penalty.
        $this->assertArrayNotHasKey('balancing', $kinds->all());
        $this->assertSame([], $y['penalties']);
        $this->assertSame([], $y['upcoming']);
    }

    public function test_the_new_rules_for_someone_in_making_tax_digital(): void
    {
        // Turnover over £50,000 in 2024-25 brings you into MTD from 6 April 2026, so 2026-27 is under the new rules.
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2026-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10']);
        TaxYear::track('2026-27');
        Carbon::setTestNow('2028-04-20');
        CarbonImmutable::setTestNow('2028-04-20');
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame('new', $y['regime']);
        $codes = $this->codes($y);
        $this->assertSame(['2028-02-01', 0], $codes['points'], 'A late return earns a point, not £100');
        $L = $y['liability_pence'];
        // First year under the new rules and not paid within 30 days: 3% at day 15 and 3% at day 30, then 10% a year.
        $this->assertSame(['2028-03-02', intdiv($L * 3, 100)], $codes['sch26_a']);
        $this->assertSame(['2028-03-02', intdiv($L * 3, 100)], $codes['sch26_b']);
        // 10% a year on the whole amount from 2 March to 20 April 2028: 50 days of a leap year.
        $this->assertEqualsWithDelta($L * 0.10 * 50 / 366, $codes['sch26_daily'][1], 2);
        $this->assertArrayNotHasKey('sch55_initial', $codes);
    }

    public function test_the_tax_years_page_and_the_assistant_show_it(): void
    {
        $this->actingAs(User::factory()->create());
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.tax_year', '2024-25')->where('penalties.penalties.0.code', 'sch55_initial'));
        $this->get('/years')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.totals.penalties_pence', fn ($v) => $v > 0));

        // You tell it HMRC agreed a payment plan; the assistant can answer "what if I file and pay next week?".
        $this->patch('/years/2024-25', ['payment_plan_from' => '2026-06-01'])->assertSessionHas('success');
        $this->assertSame('2026-06-01', TaxYear::query()->where('label', '2024-25')->value('payment_plan_from')?->format('Y-m-d'));
        $answer = app(\App\Domain\Ai\AssistantTools::class)->run('penalties', ['tax_year' => '2024-25', 'file_on' => '2026-11-30', 'pay_all_on' => '2026-11-30']);
        $this->assertTrue($answer['estimate']);
        $this->assertSame([], $answer['years'][0]['coming_if_nothing_changes']);
        $this->assertNotEmpty($answer['years'][0]['already_due']);
    }
}
