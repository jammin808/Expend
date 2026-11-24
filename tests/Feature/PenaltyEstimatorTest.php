<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantTools;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

        // Interest on the balancing payment: 296 days from 31 January 2026 at 7.75% (in force from 9 January 2026),
        // one run of days, rounded down to the penny as in HMRC's published example.
        $balancing = collect($y['obligations'])->firstWhere('kind', 'balancing');
        $this->assertSame(intdiv($L * 775 * 296, 100 * 100 * 365), $balancing['interest_pence']);
        $this->assertSame([['from' => '2026-02-01', 'to' => '2026-11-23', 'days' => 296, 'balance_pence' => $L, 'pct' => '7.75', 'divisor' => 365, 'pence' => $balancing['interest_pence']]],
            $balancing['interest_segments']);
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
        $this->assertSame(['2026-05-01', 11 * 1_000], $codes['sch55_daily'], 'Eleven days, 1 to 11 May: the day HMRC get the return counts');
        $this->assertSame(['2026-03-03', intdiv($L * 5, 100)], $codes['sch56_30d']);
        $this->assertArrayNotHasKey('sch55_6m', $codes);
        $this->assertArrayNotHasKey('sch56_6m', $codes);
        $this->assertSame([], $y['upcoming']);
        // Interest on the tax: 100 days (1 February to 11 May, the payment day counting) at 7.75%, rounded down.
        $this->assertSame(intdiv($L * 775 * 100, 100 * 100 * 365), collect($y['obligations'])->firstWhere('kind', 'balancing')['interest_pence']);
    }

    public function test_what_if_you_file_and_pay_next_week_and_a_payment_plan(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $y = PenaltyEstimator::forYear('2024-25', null, ['filed_on' => ['2024-25' => '2026-11-30'], 'pay_all_on' => '2026-11-30']);
        $this->assertSame([], $y['upcoming'], 'Filing and paying before 1 February avoids the 12-month penalties');

        // Asked for a plan on 1 June 2026 while the return (due 31 January) was overdue, not agreed yet: HMRC don't
        // normally agree one then, so it holds nothing off and says why (DMBM803040).
        TaxYear::query()->where('label', '2024-25')->update(['plan_requested_on' => '2026-06-01']);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertArrayHasKey('sch56_6m', $this->codes($y), 'No plan while the return is overdue');
        $this->assertNull($y['plan']['state']);
        $this->assertContains('HMRC won’t normally agree a payment plan while the return for 2024-25 is overdue, so the plan holds off no late payment penalties. File the return, then ask HMRC again.', $y['actions']);

        // You've said HMRC agreed it all the same: your record wins, with a caveat, and it counts from the day you asked.
        TaxYear::query()->where('label', '2024-25')->update(['plan_agreed' => true]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('protected', $y['plan']['state']);
        $this->assertSame('2026-06-01', $y['plan']['counts_from']);
        $this->assertArrayNotHasKey('sch56_6m', $this->codes($y));
        $this->assertNotEmpty(collect($y['plan']['notes'])->first(fn ($n) => str_starts_with($n, 'HMRC don’t normally agree a payment plan while a return is overdue') && str_contains($n, 'check the date on HMRC’s letter')));
        $this->assertEmpty(collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'Can’t pay it all?')));

        // Not agreed, then filed on 1 July: the plan counts from then: before the 6-month trigger (end of 2 August), so
        // that one and the 12-month one are held off once it's agreed and kept; the 30-day one (3 March) was already
        // charged (Sch 56 para 10).
        TaxYear::query()->where('label', '2024-25')->update(['status' => 'filed', 'filed_on' => '2026-07-01', 'plan_agreed' => null]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('pending', $y['plan']['state']);
        $this->assertSame('2026-07-01', $y['plan']['counts_from']);
        TaxYear::query()->where('label', '2024-25')->update(['plan_agreed' => true]);
        $y = PenaltyEstimator::forYear('2024-25');
        $codes = $this->codes($y);
        $L = $y['liability_pence'];
        $this->assertSame(['2026-03-03', intdiv($L * 5, 100)], $codes['sch56_30d'], 'A plan doesn’t undo a penalty already charged');
        $this->assertArrayNotHasKey('sch56_6m', $codes, 'but it stops the later ones');
        $this->assertSame([], $y['upcoming']);
        $this->assertSame('protected', $y['plan']['state']);
        $this->assertSame('2026-06-01', $y['plan']['counts_from'], 'agreed: from the day you asked');
        $this->assertSame(intdiv($L * 5, 100), $y['plan']['protected_pence']);
    }

    public function test_unpaid_payments_on_account_are_penalised_inside_the_years_tax(): void
    {
        // 2023-24's return makes payments on account due for 2024-25 (31 January and 31 July 2025).
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000]);
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10', 'filed_profit_pence' => 4_000_000]);
        $this->tx('2025-01-20', -$this->liability('2023-24', 4_000_000), 'tax_payment'); // 2023-24's bill, on time

        $y = PenaltyEstimator::forYear('2024-25');
        $L = $y['liability_pence'];
        $this->assertSame(713_180, $L, '£40,000 profit in 2024-25: £5,486.00 income tax + £1,645.80 Class 4');
        $kinds = collect($y['obligations'])->keyBy('kind');
        // 2024-25's tax (lower Class 4) is less than the two payments on account, so once the return is filed HMRC cut
        // each to half of it (SAM1020); they carry interest from their own dates, but no penalty of their own.
        $this->assertSame(intdiv($L, 2), $kinds['poa1']['amount_pence']);
        $this->assertStringContainsString('cut to half', $kinds['poa1']['label']);
        $this->assertGreaterThan(0, $kinds['poa1']['interest_pence']);
        // But both were still unpaid on 31 January 2026, so they're inside the year's tax that's penalised (s59B(1);
        // SAM61390): 5% of £7,131.80 = £356.59 at each trigger, not £0.
        $this->assertSame(['sch56_30d' => ['2026-03-03', 35_659], 'sch56_6m' => ['2026-08-03', 35_659]], $this->codes($y));
        $this->assertSame(['sch56_12m' => ['2027-02-03', 35_659]], $this->codes($y, 'upcoming'));
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
        $this->assertSame('sch24_26_law', $y['regime'], 'In MTD from April 2026: the new rules are law for 2026-27');
        $codes = $this->codes($y);
        $this->assertSame(['2028-02-01', 0], $codes['points'], 'A late return earns a point, not £100');
        $L = $y['liability_pence'];
        // First year under the new rules and not paid within 30 days: 3% at day 15 and 3% at day 30, then 10% a year.
        $this->assertSame(['2028-03-02', intdiv($L * 3, 100)], $codes['sch26_a']);
        $this->assertSame(['2028-03-02', intdiv($L * 3, 100)], $codes['sch26_b']);
        // 10% a year on the whole amount from 2 March to 20 April 2028: 50 days, each at 10% ÷ 365 even in a leap year
        // (HMRC's worked example), in one segment rounded to the penny.
        $this->assertSame(['2028-03-02', BigDecimal::of($L)->multipliedBy(10 * 50)->dividedBy(100 * 365, 0, RoundingMode::HALF_UP)->toInt()], $codes['sch26_daily']);
        $this->assertArrayNotHasKey('sch55_initial', $codes);
    }

    public function test_the_tax_years_page_and_the_assistant_show_it(): void
    {
        $this->actingAs(User::factory()->create());
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.tax_year', '2024-25')->where('penalties.penalties.0.code', 'sch55_initial'));
        $this->get('/years')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.totals.penalties_pence', fn ($v) => $v > 0));

        // You tell it when you asked HMRC for a payment plan; the assistant can answer "what if I file and pay next week?".
        $this->patch('/years/2024-25', ['plan_requested_on' => '2026-06-01', 'plan_agreed' => true])->assertSessionHas('success');
        $this->assertSame('2026-06-01', TaxYear::query()->where('label', '2024-25')->first()->plan_requested_on?->format('Y-m-d'));
        $answer = app(AssistantTools::class)->run('penalties', ['tax_year' => '2024-25', 'file_on' => '2026-11-30', 'pay_all_on' => '2026-11-30']);
        $this->assertTrue($answer['estimate']);
        $this->assertSame([], $answer['years'][0]['coming_if_nothing_changes']);
        $this->assertNotEmpty($answer['years'][0]['already_due']);

        // The to-do list (and so the Telegram digest) carries it, most urgent first; it can only be snoozed.
        $item = collect(TodoList::build()['items'])->firstWhere('group', 'penalties');
        $this->assertStringStartsWith('Fines and interest for 2024-25: £', $item['title']);
        $this->assertSame(1, $item['priority']);
        $this->assertTrue(TodoList::snoozeOnly($item['key']));
    }
}
