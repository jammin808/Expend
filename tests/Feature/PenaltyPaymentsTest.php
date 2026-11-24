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
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Payments and the penalties on them: what's penalised (the year's tax less what's been paid towards it), payment
 * plans (the day you asked, agreed and kept), the new rules' yearly-rate penalty, payments on account, interest on
 * penalties, and amended returns. Expected figures come from the settled rules (legislation.gov.uk, GOV.UK, HMRC
 * manuals) and their worked examples.
 */
class PenaltyPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->at('2026-11-24');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow($date.' 10:00:00');
        CarbonImmutable::setTestNow($date.' 10:00:00');
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

    private function liability(string $label, int $profit): int
    {
        $e = TaxEstimator::estimate($label, $profit);

        return $e['income_tax']['total_pence'] + $e['class4_pence'] + $e['class2_pence'];
    }

    private function relevant(string $label, int $profit): int
    {
        $e = TaxEstimator::estimate($label, $profit);

        return $e['income_tax']['total_pence'] + $e['class4_pence'];
    }

    private function codes(array $year, string $list = 'penalties'): array
    {
        return collect($year[$list])->mapWithKeys(fn ($p) => [$p['code'] => [$p['date'], $p['amount_pence']]])->all();
    }

    /** balance × rate% × days ÷ 365, rounded to the penny: one segment of the yearly-rate penalty. */
    private function segment(int $balance, int $pct, int $days): int
    {
        return BigDecimal::of($balance)->multipliedBy($pct * $days)->dividedBy(100 * 365, 0, RoundingMode::HALF_UP)->toInt();
    }

    /** 2024-25, filed on time, nothing paid: Sch 56 triggers end on 2 March 2026, 2 August 2026 and 2 February 2027. */
    private function unpaid202425(): int
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20']);

        return $this->liability('2024-25', 3_000_000);
    }

    private function plan(string $label, array $facts): void
    {
        TaxYear::query()->where('label', $label)->update($facts);
    }

    // ---- What's penalised ----

    public function test_each_late_payment_penalty_is_on_what_is_unpaid_at_its_own_trigger(): void
    {
        // CH155180's pattern: nothing paid by the end of the 30 days, part paid before the 6-month trigger, more before
        // the 12-month one. 2023-24's tax was due 31 January 2025: triggers end 2 March 2025, 2 August 2025, 2 February 2026.
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000]);
        $L = $this->liability('2023-24', 4_000_000);
        $this->tx('2025-05-02', -200_000, 'tax_payment');
        $this->tx('2025-09-20', -300_000, 'tax_payment');

        $y = PenaltyEstimator::forYear('2023-24');
        $this->assertSame([
            'sch56_30d' => ['2025-03-03', intdiv($L * 5, 100)],
            'sch56_6m' => ['2025-08-03', intdiv(($L - 200_000) * 5, 100)],
            'sch56_12m' => ['2026-02-03', intdiv(($L - 500_000) * 5, 100)],
        ], $this->codes($y));
    }

    public function test_a_payment_on_account_paid_late_but_before_the_first_trigger_drops_out(): void
    {
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000]);
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10', 'filed_profit_pence' => 4_000_000]);
        $this->tx('2025-01-20', -$this->liability('2023-24', 4_000_000), 'tax_payment');
        $L = $this->liability('2024-25', 4_000_000);
        // Both of 2024-25's payments on account paid on 20 February 2026, after 31 January but before the end of 2 March.
        $this->tx('2026-02-20', -$L, 'tax_payment');

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame([], $y['penalties']);
        $this->assertSame([], $y['upcoming']);
        // Interest still ran on each from its own date.
        $kinds = collect($y['obligations'])->keyBy('kind');
        $this->assertGreaterThan(0, $kinds['poa1']['interest_pence']);
        $this->assertGreaterThan(0, $kinds['poa2']['interest_pence']);
    }

    // ---- Payment plans: Sch 56 para 10 (SAM61380) ----

    public function test_a_plan_asked_for_by_the_30_day_trigger_holds_off_all_three(): void
    {
        $L = $this->unpaid202425();
        $this->plan('2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => true]);

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame([], $this->codes($y));
        $this->assertSame([], $y['upcoming'], 'the 12-month one too, while the plan is kept');
        $this->assertSame('protected', $y['plan']['state']);
        $this->assertSame(2 * intdiv($L * 5, 100), $y['plan']['protected_pence'], 'the two that would have arisen by now');
        $this->assertSame(0, $y['penalties_pence']);
        $this->assertGreaterThan(0, $y['interest_pence'], 'interest still runs');
        $this->assertNotEmpty(collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'Keep to your payment plan')));
        $this->assertContains('Assumes the plan runs until the tax is paid off. Add the date of the last payment if you know it.', $y['plan']['notes']);
    }

    public function test_what_a_plan_holds_off_depends_on_the_day_you_asked(): void
    {
        $L = $this->unpaid202425();
        $five = intdiv($L * 5, 100);

        // Asked after the 30-day trigger but by the 6-month one: the first stands.
        $this->plan('2024-25', ['plan_requested_on' => '2026-03-10', 'plan_agreed' => true]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame(['sch56_30d' => ['2026-03-03', $five]], $this->codes($y));
        $this->assertSame([], $y['upcoming']);

        // Asked on the last day of the 6-month trigger still counts (on or before T2).
        $this->plan('2024-25', ['plan_requested_on' => '2026-08-02']);
        $this->assertSame(['sch56_30d' => ['2026-03-03', $five]], $this->codes(PenaltyEstimator::forYear('2024-25')));

        // Asked after the 6-month trigger: the first two stand, the 12-month one is held off.
        $this->plan('2024-25', ['plan_requested_on' => '2026-09-01']);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame(['sch56_30d' => ['2026-03-03', $five], 'sch56_6m' => ['2026-08-03', $five]], $this->codes($y));
        $this->assertSame([], $y['upcoming']);
    }

    public function test_a_plan_not_agreed_yet_holds_nothing_off_but_says_what_it_would_cancel(): void
    {
        $L = $this->unpaid202425();
        $this->plan('2024-25', ['plan_requested_on' => '2026-02-20']);

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('pending', $y['plan']['state']);
        $this->assertArrayHasKey('sch56_30d', $this->codes($y));
        $this->assertArrayHasKey('sch56_6m', $this->codes($y));
        $this->assertSame(2 * intdiv($L * 5, 100), $y['plan']['pending_pence']);
        $this->assertContains('You asked HMRC for a payment plan on 20 February 2026. Once they agree it, say so here: if you keep to it, '
            .Money::format(2 * intdiv($L * 5, 100)).' of the late payment penalties above are cancelled.', $y['actions']);

        // Refused: nothing held off, and it says so.
        $this->plan('2024-25', ['plan_agreed' => false]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertNull($y['plan']['state']);
        $this->assertContains('You’ve said HMRC didn’t agree the plan, so it holds off no penalties.', $y['plan']['notes']);
        $this->assertNotEmpty(collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'Can’t pay it all?')));
    }

    public function test_a_broken_plan_puts_what_it_held_off_at_risk(): void
    {
        $L = $this->unpaid202425();
        $five = intdiv($L * 5, 100);
        $this->plan('2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => true, 'plan_broken_on' => '2026-10-01']);

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('at_risk', $y['plan']['state']);
        // A breach doesn't end the protection by itself: HMRC can charge what the plan holds off only by notice (Sch 56
        // para 10(3)). With no end date the deferral runs until the tax is paid, so the 12-month penalty (3 February
        // 2027) would be held off too, and at risk: none of it is in the totals.
        $this->assertSame(['sch56_30d_at_risk' => ['2026-03-03', $five], 'sch56_6m_at_risk' => ['2026-08-03', $five], 'sch56_12m_at_risk' => ['2027-02-03', $five]], $this->codes($y, 'at_risk'));
        $this->assertSame(3 * $five, $y['at_risk_pence']);
        $this->assertSame(2 * $five, $y['plan']['at_risk_pence'], 'what can be charged now');
        $this->assertSame($five, $y['plan']['at_risk_later_pence']);
        $this->assertSame([], $y['penalties']);
        $this->assertSame([], $y['upcoming']);
        $this->assertSame(0, $y['penalties_pence']);
        foreach ($y['at_risk'] as $p) {
            $this->assertTrue($p['estimate']);
            $this->assertSame('at_risk', $p['status']);
        }
        $this->assertStringStartsWith('Your payment plan for 2024-25 was broken on 1 October 2026, so HMRC can now charge the '.Money::format(2 * $five)
            .' of late payment penalties it held off, and '.Money::format($five).' more that would arise later while it’s in place', $y['actions'][0]);

        // The to-do list and the assistant carry it.
        $item = collect(TodoList::build()['items'])->firstWhere('key', 'penalty:2024-25');
        $this->assertStringContainsString(Money::format(2 * $five, 'GBP', false).' more that your broken payment plan held off can now be charged', $item['detail']);
        $answer = app(AssistantTools::class)->run('penalties', ['tax_year' => '2024-25']);
        $this->assertCount(3, $answer['years'][0]['at_risk_if_hmrc_charge_them']);
        $this->assertSame('2026-02-20', $answer['years'][0]['payment_plan']['asked_hmrc_on']);
    }

    public function test_penalties_arising_after_a_breach_but_inside_the_plan_are_at_risk_not_due(): void
    {
        // Asked 20 February 2026 (before the 30-day trigger), agreed to run to 31 December 2026, broken 15 June 2026,
        // £1,000 paid on each of 20 March, 20 April and 20 May. The 6-month penalty arises on 3 August 2026: after the
        // breach but inside the deferral period, so it's held off until HMRC send a notice (Sch 56 para 10(2)-(3)).
        $L = $this->unpaid202425();
        foreach (['2026-03-20', '2026-04-20', '2026-05-20'] as $d) {
            $this->tx($d, -100_000, 'tax_payment');
        }
        $this->plan('2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => true, 'plan_ends_on' => '2026-12-31', 'plan_broken_on' => '2026-06-15']);

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('at_risk', $y['plan']['state']);
        $this->assertSame(['sch56_30d_at_risk' => ['2026-03-03', intdiv($L * 5, 100)], 'sch56_6m_at_risk' => ['2026-08-03', intdiv(($L - 300_000) * 5, 100)]], $this->codes($y, 'at_risk'));
        $this->assertSame([], $y['penalties'], 'nothing charged without HMRC’s notice');
        $this->assertSame(0, $y['penalties_pence']);
        // The 12-month penalty arises on 3 February 2027, after the plan's end: charged as normal if still unpaid.
        $this->assertSame(['sch56_12m' => ['2027-02-03', intdiv(($L - 300_000) * 5, 100)]], $this->codes($y, 'upcoming'));

        // Once HMRC's notice is recorded they're charged, from the notice (interest 30 days after), and the plan holds
        // nothing off from then.
        $this->plan('2024-25', ['plan_notice_on' => '2026-09-10']);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('charged', $y['plan']['state']);
        $this->assertSame([], $y['at_risk']);
        $codes = $this->codes($y);
        $this->assertSame(['2026-03-03', intdiv($L * 5, 100)], $codes['sch56_30d']);
        $this->assertSame(['2026-08-03', intdiv(($L - 300_000) * 5, 100)], $codes['sch56_6m']);
        $this->assertSame('2026-09-10', collect($y['penalties'])->firstWhere('code', 'sch56_30d')['charged_on']);
        $this->assertSame(intdiv($L * 5, 100) + intdiv(($L - 300_000) * 5, 100), $y['plan']['charged_pence']);
        $penalty = collect($y['obligations'])->firstWhere('label', collect($y['penalties'])->firstWhere('code', 'sch56_30d')['label']);
        $this->assertSame('2026-10-10', $penalty['due'], 'interest from 30 days after HMRC’s notice');
        $this->assertNotEmpty(collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'HMRC charged the ')));
    }

    public function test_a_plan_that_ended_with_tax_unpaid_is_broken(): void
    {
        $L = $this->unpaid202425();
        $five = intdiv($L * 5, 100);
        $this->plan('2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => true, 'plan_ends_on' => '2026-10-31']);

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('at_risk', $y['plan']['state']);
        $this->assertSame('2026-11-01', $y['plan']['broken_on']);
        $this->assertSame(2 * $five, $y['at_risk_pence']);
        $this->assertNotEmpty(collect($y['plan']['notes'])->first(fn ($n) => str_starts_with($n, 'The plan ended on 31 October 2026 with')));
    }

    public function test_a_plan_covers_only_its_amount_and_penalties_before_it_ends(): void
    {
        $L = $this->unpaid202425();
        // £1,000 in the plan: the rest of the unpaid tax is penalised as normal.
        $this->plan('2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => true, 'plan_amount_pence' => 100_000, 'plan_ends_on' => '2026-12-31']);
        $y = PenaltyEstimator::forYear('2024-25');
        $rest = intdiv(($L - 100_000) * 5, 100);
        $this->assertSame(['sch56_30d' => ['2026-03-03', $rest], 'sch56_6m' => ['2026-08-03', $rest]], $this->codes($y));
        $this->assertSame(2 * (intdiv($L * 5, 100) - $rest), $y['plan']['protected_pence']);
        // The 12-month penalty arises on 3 February 2027, after the plan ends: charged as normal on all of it.
        $this->assertSame(['sch56_12m' => ['2027-02-03', intdiv($L * 5, 100)]], $this->codes($y, 'upcoming'));
        $this->assertContains('The plan covers £1,000.00; late payment penalties on anything else unpaid are charged as normal.', $y['plan']['notes']);
    }

    // ---- Payment plans and the new rules: Sch 26 paras 4-9 ----

    /** A volunteer from 2025-26, so 2026-27 is their second year under the new rules: 15 days, not 30. */
    private function newRules202627(): int
    {
        Setting::put('mtd_volunteer_from', '2025-26');
        $this->tx('2026-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2026-27')->update(['status' => 'filed', 'filed_on' => '2028-01-10']);
        $this->at('2028-06-01');

        return $this->liability('2026-27', 3_000_000);
    }

    public function test_the_new_rules_charge_each_part_on_what_is_unpaid_then(): void
    {
        // The settled example: £4,000 paid on day 20, nothing more, no plan. Due 31 January 2028: day 15 is 15 February,
        // day 30 is 1 March (2028 is a leap year), the yearly rate from 2 March.
        $L = $this->newRules202627();
        $this->tx('2028-02-20', -400_000, 'tax_payment');

        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame('sch24_26_law', $y['regime']);
        $codes = $this->codes($y);
        $this->assertSame(['2028-02-16', intdiv($L * 3, 100)], $codes['sch26_a'], '3% of what was unpaid at day 15');
        $this->assertSame(['2028-03-02', intdiv(($L - 400_000) * 3, 100)], $codes['sch26_b'], '3% of what was unpaid at day 30');
        // 10% a year on the £(L - 4,000) from 2 March to 1 June 2028: 92 days, each at 10% ÷ 365.
        $this->assertSame(['2028-03-02', $this->segment($L - 400_000, 10, 92)], $codes['sch26_daily']);
        $daily = collect($y['penalties'])->firstWhere('code', 'sch26_daily');
        $this->assertSame('accruing', $daily['status']);
        $this->assertTrue($daily['estimate']);
        $this->assertStringContainsString('10% ÷ 365', $daily['assumption']);
    }

    public function test_a_plan_under_the_new_rules_depends_on_the_day_you_made_proposals(): void
    {
        $L = $this->newRules202627();

        // Proposals by day 15 that led to an agreed plan: no penalty at all.
        TaxYear::query()->where('label', '2026-27')->update(['plan_requested_on' => '2028-02-10', 'plan_agreed' => true]);
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame([], $this->codes($y));
        $this->assertSame(intdiv($L * 3, 100) * 2 + $this->segment($L, 10, 92), $y['plan']['protected_pence']);

        // Days 16 to 30: amount A only; no B and no yearly rate.
        TaxYear::query()->where('label', '2026-27')->update(['plan_requested_on' => '2028-02-20']);
        $this->assertSame(['sch26_a' => ['2028-02-16', intdiv($L * 3, 100)]], $this->codes(PenaltyEstimator::forYear('2026-27')));

        // Day 40 (11 March): A and B, and the yearly rate up to the day before (2 to 10 March, 9 days; CH193140).
        TaxYear::query()->where('label', '2026-27')->update(['plan_requested_on' => '2028-03-11']);
        $y = PenaltyEstimator::forYear('2026-27');
        $codes = $this->codes($y);
        $this->assertSame(intdiv($L * 3, 100), $codes['sch26_a'][1]);
        $this->assertSame(intdiv($L * 3, 100), $codes['sch26_b'][1]);
        $this->assertSame(['2028-03-02', $this->segment($L, 10, 9)], $codes['sch26_daily']);
        $this->assertSame('due', collect($y['penalties'])->firstWhere('code', 'sch26_daily')['status'], 'stopped growing once the plan was asked for');
    }

    public function test_a_broken_plan_under_the_new_rules_puts_what_it_held_off_at_risk(): void
    {
        $L = $this->newRules202627();
        TaxYear::query()->where('label', '2026-27')->update(['plan_requested_on' => '2028-02-10', 'plan_agreed' => true, 'plan_broken_on' => '2028-03-21']);

        $y = PenaltyEstimator::forYear('2026-27');
        // Without HMRC's notice (Sch 26 paras 7 and 9) the protection stands, the days after the breach included: all of
        // it is at risk, nothing is charged.
        $this->assertSame(['sch26_a_at_risk', 'sch26_b_at_risk', 'sch26_daily_at_risk'], array_keys($this->codes($y, 'at_risk')));
        $this->assertSame([], $this->codes($y));
        $this->assertSame($this->segment($L, 10, 92), $this->codes($y, 'at_risk')['sch26_daily_at_risk'][1], '2 March to 1 June 2028');
    }

    public function test_a_broken_plan_asked_for_on_day_40_charges_only_the_days_before_it(): void
    {
        // Day 40 is 11 March 2028: A, B and the yearly rate for 2 to 10 March (9 days) are charged; the yearly rate from
        // 11 March is held off, and at risk once the plan breaks (1 June), until HMRC give notice under para 9.
        $L = $this->newRules202627();
        TaxYear::query()->where('label', '2026-27')->update(['plan_requested_on' => '2028-03-11', 'plan_agreed' => true, 'plan_ends_on' => '2028-12-31', 'plan_broken_on' => '2028-06-01']);
        $this->at('2028-11-24');

        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame(['2028-03-02', $this->segment($L, 10, 9)], $this->codes($y)['sch26_daily']);
        // 11 March to 24 November 2028: 259 days.
        $this->assertSame($this->segment($L, 10, 9 + 259) - $this->segment($L, 10, 9), $this->codes($y, 'at_risk')['sch26_daily_at_risk'][1]);

        // HMRC's notice: charged as if there'd been no plan, on the actual balances (para 9).
        TaxYear::query()->where('label', '2026-27')->update(['plan_notice_on' => '2028-07-01']);
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame(['2028-03-02', $this->segment($L, 10, 9 + 259)], $this->codes($y)['sch26_daily']);
        $this->assertSame([], $y['at_risk']);
    }

    public function test_announced_rates_and_the_first_year_window_are_labelled(): void
    {
        // In MTD from April 2026 (2024-25 income over £50,000): the new rules are law for 2027-28, but its 4% rates
        // are only announced: the law (SI 2025/589) still says 3%. So the figures in the totals use 3%, and the 4%
        // ones are shown beside them, out of the totals, as an estimate.
        config(['tax.years.2027-28' => config('tax.years.2026-27')]);
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2027-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10']);
        TaxYear::track('2027-28')->update(['status' => 'filed', 'filed_on' => '2029-01-10']);
        $this->at('2029-03-20');

        $y = PenaltyEstimator::forYear('2027-28');
        $this->assertSame('sch24_26_law', $y['regime']);
        $a = collect($y['penalties'])->firstWhere('code', 'sch26_a');
        $this->assertSame(intdiv($y['liability_pence'] * 3, 100), $a['amount_pence'], 'the rate in the law');
        $this->assertFalse($a['estimate']);
        // 2026-27 was the first year under the new rules, so 2027-28 has 15 days (the 30 days are given once).
        $this->assertSame('2029-02-16', $a['date']);
        $this->assertSame('2029-03-03', $a['charged_on'], 'HMRC charge the first penalty after day 30 (CH193140)');
        $alt = collect($y['announced']['penalties'])->firstWhere('code', 'sch26_a');
        $this->assertSame(intdiv($y['liability_pence'] * 4, 100), $alt['amount_pence']);
        $this->assertTrue($alt['estimate']);
        $this->assertStringStartsWith('Uses the 4% rates announced for 2027-28, which aren’t law yet (the law says 3%).', $alt['assumption']);
        $this->assertStringContainsString('4% late payment rates announced for 2027-28 (not yet law)', $y['announced']['regime_text']);
        $this->assertSame(array_sum(array_column($y['penalties'], 'amount_pence')), $y['penalties_pence'], 'the announced figures stay out of the totals');

        $this->at('2028-04-20');
        $first = PenaltyEstimator::forYear('2026-27');
        $this->assertNotEmpty(collect($first['assumptions'])->first(fn ($x) => str_starts_with($x, 'Your first year under the new penalty rules') && str_contains($x, 'HMRC practice, given once, not law')));
    }

    public function test_the_yearly_rate_stops_at_date_a(): void
    {
        // Unpaid for years: HMRC must charge it by Date A, the last day of 2 years beginning with the due date (30 January
        // 2030 for 31 January 2028), so it runs for days 31 to 730: 700 days.
        $L = $this->newRules202627();
        $this->at('2030-06-01');
        $y = PenaltyEstimator::forYear('2026-27');
        $daily = collect($y['penalties'])->firstWhere('code', 'sch26_daily');
        $this->assertSame($this->segment($L, 10, 700), $daily['amount_pence']);
        $this->assertSame('due', $daily['status']);

        // The settled figure: £10,000 unpaid from 2 March 2028 to 30 January 2030 is £1,917.81.
        $r = PenaltyEstimator::yearlyRatePenalty(fn () => 1_000_000, CarbonImmutable::parse('2028-03-02'), CarbonImmutable::parse('2030-01-30'), '10');
        $this->assertSame(191_781, $r['pence']);
    }

    public function test_the_yearly_rate_uses_each_days_opening_balance_in_rounded_segments(): void
    {
        // CH193160: £1,000, £166.67 paid on day 40 and on day 70, the rest on day 101, at 10% a year. Days 31-40 at
        // £1,000 (£2.74), 41-70 at £833.33 (£6.85), 71-101 at £666.66 (£5.66): £15.25. (HMRC's page counts 32 days in
        // the last segment and shows £15.43; the settled rules say not to use that.)
        $due = CarbonImmutable::parse('2026-01-31');
        $unpaid = fn (CarbonImmutable $d) => match (true) {
            $d->lt($due->addDays(40)) => 100_000,
            $d->lt($due->addDays(70)) => 83_333,
            $d->lt($due->addDays(101)) => 66_666,
            default => 0,
        };
        $r = PenaltyEstimator::yearlyRatePenalty($unpaid, $due->addDays(31), $due->addDays(200), '10');
        $this->assertSame(1_525, $r['pence']);
        $this->assertSame($due->addDays(101)->format('Y-m-d'), $r['last_day']->format('Y-m-d'));
    }

    // ---- Payments on account (s59A; Sch 53; SALF305) ----

    public function test_payments_on_account_need_a_return_or_determination_for_the_year_before(): void
    {
        TaxYear::track('2023-24')->update(['filed_profit_pence' => 4_000_000]); // not filed
        TaxYear::track('2024-25')->update(['filed_profit_pence' => 4_000_000]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertNull(collect($y['obligations'])->firstWhere('kind', 'poa1'), 'no return for 2023-24, no payments on account');
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_starts_with($a, 'No payments on account for 2024-25: HMRC only ask for them once the return for 2023-24')));

        // HMRC's determination for 2023-24 counts as its return (s28C(3)).
        TaxYear::query()->where('label', '2023-24')->update(['determination_on' => '2025-06-01']);
        $this->assertNotNull(collect(PenaltyEstimator::forYear('2024-25')['obligations'])->firstWhere('kind', 'poa1'));

        // Filed late, they're due from the original dates all the same, with interest from then (s59A(4A)).
        TaxYear::query()->where('label', '2023-24')->update(['determination_on' => null, 'status' => 'filed', 'filed_on' => '2026-03-01']);
        $kinds = collect(PenaltyEstimator::forYear('2024-25')['obligations'])->keyBy('kind');
        $this->assertSame('2025-01-31', $kinds['poa1']['due']);
        $this->assertSame('2025-07-31', $kinds['poa2']['due']);
        $this->assertGreaterThan(0, $kinds['poa1']['interest_pence']);
    }

    public function test_interest_runs_on_payments_on_account_as_charged_until_the_return_is_filed(): void
    {
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000]);
        TaxYear::track('2024-25')->update(['filed_profit_pence' => 3_000_000]); // not filed yet
        $this->tx('2025-01-20', -$this->liability('2023-24', 4_000_000), 'tax_payment');
        $charged = intdiv($this->relevant('2023-24', 4_000_000), 2);
        $half = intdiv($this->relevant('2024-25', 3_000_000), 2);
        $this->assertLessThan($charged, $half);

        $before = collect(PenaltyEstimator::forYear('2024-25')['obligations'])->keyBy('kind');
        $this->assertSame($charged, $before['poa1']['amount_pence'], 'as charged, while the return isn’t in');

        TaxYear::query()->where('label', '2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20']);
        $after = collect(PenaltyEstimator::forYear('2024-25')['obligations'])->keyBy('kind');
        $this->assertSame($half, $after['poa1']['amount_pence'], 'cut to half this year’s figure once filed (SAM1020)');
        $this->assertLessThan($before['poa1']['interest_pence'], $after['poa1']['interest_pence']);
    }

    public function test_an_overpaid_first_payment_on_account_counts_towards_the_second_from_31_july(): void
    {
        // SALF305: what's paid on the first above the amount needed counts towards the second from 31 July.
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000]);
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20', 'filed_profit_pence' => 3_000_000]);
        $this->tx('2025-01-20', -$this->liability('2023-24', 4_000_000), 'tax_payment');
        $half = intdiv($this->relevant('2024-25', 3_000_000), 2);
        $this->tx('2025-01-31', -2 * $half, 'tax_payment'); // both, on the first's date

        $kinds = collect(PenaltyEstimator::forYear('2024-25')['obligations'])->keyBy('kind');
        $this->assertSame($half, $kinds['poa2']['paid_pence']);
        $this->assertSame(0, $kinds['poa2']['interest_pence']);
        $this->assertSame(0, $kinds['poa1']['interest_pence']);
    }

    // ---- Interest on penalties ----

    public function test_interest_on_daily_penalties_runs_from_30_days_after_they_are_charged(): void
    {
        // Filed 11 May 2026: 11 days, charged when the return arrived, so interest from 10 June (SAM61230).
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-05-11']);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('2026-05-11', collect($y['penalties'])->firstWhere('code', 'sch55_daily')['charged_on']);
        $this->assertSame('2026-06-10', collect($y['obligations'])->firstWhere('label', 'Daily penalties: £10 × 11 days')['due']);

        // Never filed: charged at day 90 (29 July 2026), interest from 28 August, not from 31 May.
        TaxYear::query()->where('label', '2024-25')->update(['status' => 'outstanding', 'filed_on' => null]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('2026-08-28', collect($y['obligations'])->firstWhere('label', 'Daily penalties: £10 × 90 days')['due']);

        // Still growing on 15 June: not charged yet, so no interest on it.
        $this->at('2026-06-15');
        $daily = collect(PenaltyEstimator::forYear('2024-25')['obligations'])->first(fn ($o) => str_starts_with($o['label'], 'Daily penalties'));
        $this->assertSame('2026-08-28', $daily['due']);
        $this->assertSame(0, $daily['interest_pence']);
    }

    // ---- Amended returns (TMA 1970 Sch 3ZA; Sch 56 item 19; Sch 53 para 3) ----

    public function test_extra_tax_from_an_amendment_is_penalised_from_its_own_date(): void
    {
        // The settled example: 2023-24 amended, HMRC got it on 10 June 2025. The extra tax is due 10 July 2025, T1 is
        // 9 August, the penalty date 10 August; interest on it still runs from 31 January 2025.
        $L0 = $this->liability('2023-24', 3_000_000);
        TaxYear::track('2023-24')->update(['status' => 'amending', 'filed_on' => '2025-01-10', 'filed_profit_pence' => 4_000_000,
            'amended_on' => '2025-06-10', 'original_liability_pence' => $L0]);
        $L = $this->liability('2023-24', 4_000_000);
        $extra = $L - $L0;
        $this->tx('2025-01-20', -$L0, 'tax_payment'); // the original bill, on time

        $y = PenaltyEstimator::forYear('2023-24');
        $this->assertSame([
            'sch56_inc_30d' => ['2025-08-10', intdiv($extra * 5, 100)],
            'sch56_inc_6m' => ['2026-01-10', intdiv($extra * 5, 100)],
            'sch56_inc_12m' => ['2026-07-10', intdiv($extra * 5, 100)],
        ], $this->codes($y), 'nothing on the original tax, paid on time');
        $this->assertSame(['amended_on' => '2025-06-10', 'original_liability_pence' => $L0, 'increase_pence' => $extra, 'increase_due' => '2025-07-10', 'assumed_sent_today' => false], $y['amendment']);
        $balancing = collect($y['obligations'])->firstWhere('kind', 'balancing');
        $this->assertSame('2025-01-31', $balancing['due'], 'interest on the extra tax from 31 January');
        $this->assertSame($extra, $balancing['outstanding_pence']);
        $this->assertGreaterThan(0, $balancing['interest_pence']);

        // Paid by the end of 9 August (the later of 2 March and 60 days after HMRC got it): no penalty.
        $this->tx('2025-08-09', -$extra, 'tax_payment');
        $this->assertSame([], PenaltyEstimator::forYear('2023-24')['penalties']);
    }

    public function test_an_amendment_not_sent_yet_is_worked_out_as_if_sent_today(): void
    {
        $L0 = $this->liability('2024-25', 3_000_000);
        TaxYear::track('2024-25')->update(['status' => 'amending', 'filed_on' => '2026-01-10', 'filed_profit_pence' => 4_000_000, 'original_liability_pence' => $L0]);
        $this->tx('2026-01-20', -$L0, 'tax_payment');

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('2026-12-24', $y['amendment']['increase_due'], '30 days after today');
        $this->assertTrue($y['amendment']['assumed_sent_today']);
        $this->assertSame([], $y['penalties']);
        $next = collect($y['upcoming'])->firstWhere('code', 'sch56_inc_30d');
        $this->assertSame('2027-01-24', $next['date']);
        $this->assertTrue($next['estimate']);
        $this->assertStringStartsWith('Assumes you send the amended return today (24 November 2026)', $next['assumption']);

        // Without the original figure, all the tax is treated as on the original return, and it says so.
        TaxYear::query()->where('label', '2024-25')->update(['original_liability_pence' => null]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertArrayHasKey('sch56_30d', $this->codes($y));
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_starts_with($a, 'You’re amending this return. Late payment penalties are worked out as if all the tax was on the original return')));
    }

    // ---- Saving the facts ----

    public function test_the_year_page_saves_the_payment_plan_and_amendment(): void
    {
        $this->actingAs(User::factory()->create());
        $this->unpaid202425();

        $this->patch('/years/2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => '1', 'plan_ends_on' => '2027-01-31', 'plan_broken_on' => '', 'plan_amount' => '1,250.50'])
            ->assertSessionHas('success');
        $year = TaxYear::query()->where('label', '2024-25')->first();
        $this->assertSame('2026-02-20', $year->plan_requested_on->format('Y-m-d'));
        $this->assertTrue($year->plan_agreed);
        $this->assertSame('2027-01-31', $year->plan_ends_on->format('Y-m-d'));
        $this->assertNull($year->plan_broken_on);
        $this->assertSame(125_050, $year->plan_amount_pence);

        // A plan can't be broken before it was asked for, or in the future; amounts must be amounts.
        $this->patch('/years/2024-25', ['plan_broken_on' => '2026-01-01'])->assertSessionHasErrors('plan_broken_on');
        $this->patch('/years/2024-25', ['plan_broken_on' => '2026-12-01'])->assertSessionHasErrors('plan_broken_on');
        $this->patch('/years/2024-25', ['plan_amount' => '12,50'])->assertSessionHas('error');
        $this->patch('/years/2024-25', ['plan_requested_on' => '2027-01-01'])->assertSessionHasErrors('plan_requested_on');
        // HMRC's notice charging what a broken plan held off: not before the request, not in the future.
        $this->patch('/years/2024-25', ['plan_broken_on' => '2026-06-15', 'plan_notice_on' => '2026-09-10'])->assertSessionHas('success');
        $this->assertSame('2026-09-10', $year->fresh()->plan_notice_on->format('Y-m-d'));
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.plan_facts.plan_notice_on', '2026-09-10')->where('penalties.plan.state', 'charged'));
        $this->patch('/years/2024-25', ['plan_notice_on' => '2026-01-01'])->assertSessionHasErrors('plan_notice_on');
        $this->patch('/years/2024-25', ['plan_notice_on' => '2026-12-01'])->assertSessionHasErrors('plan_notice_on');

        // Clearing the request clears the plan.
        $this->patch('/years/2024-25', ['plan_requested_on' => ''])->assertSessionHas('success');
        $year->refresh();
        $this->assertNull($year->plan_requested_on);
        $this->assertNull($year->plan_agreed);
        $this->assertNull($year->plan_ends_on);
        $this->assertNull($year->plan_notice_on);
        $this->assertNull($year->plan_amount_pence);

        $this->patch('/years/2024-25', ['amended_on' => '2026-06-10', 'original_liability' => '3,000.00', 'determination_on' => ''])->assertSessionHas('success');
        $year->refresh();
        $this->assertSame('2026-06-10', $year->amended_on->format('Y-m-d'));
        $this->assertSame(300_000, $year->original_liability_pence);
        $this->patch('/years/2024-25', ['amended_on' => '2025-01-01'])->assertSessionHasErrors('amended_on');

        // The page carries the plan for the form.
        $this->patch('/years/2024-25', ['plan_requested_on' => '2026-02-20', 'plan_agreed' => '1'])->assertSessionHas('success');
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.plan_facts.plan_requested_on', '2026-02-20')
            ->where('penalties.plan.state', 'protected')->where('penalties.plan_facts.plan_agreed', true));
    }

    public function test_the_old_plan_date_becomes_the_request_date_agreed(): void
    {
        $migration = require database_path('migrations/2026_12_03_000002_add_payment_plan_and_amendment_facts_to_tax_years.php');
        TaxYear::track('2024-25');
        TaxYear::track('2023-24');
        $migration->down();
        DB::table('tax_years')->where('label', '2024-25')->update(['payment_plan_from' => '2026-06-01']);

        $migration->up();
        $year = TaxYear::query()->where('label', '2024-25')->first();
        $this->assertSame('2026-06-01', $year->plan_requested_on->format('Y-m-d'));
        $this->assertTrue($year->plan_agreed);
        $other = TaxYear::query()->where('label', '2023-24')->first();
        $this->assertNull($other->plan_requested_on);
        $this->assertNull($other->plan_agreed);
        $this->assertArrayNotHasKey('payment_plan_from', $year->getAttributes());
    }

    public function test_rolling_back_keeps_only_a_plan_agreed_and_kept_as_the_old_plan_date(): void
    {
        // The old field meant "HMRC agreed a payment plan from" and held penalties off: a refused, pending or broken plan
        // mustn't come back as one.
        $migration = require database_path('migrations/2026_12_03_000002_add_payment_plan_and_amendment_facts_to_tax_years.php');
        TaxYear::track('2024-25')->update(['plan_requested_on' => '2026-02-20', 'plan_agreed' => true]);
        TaxYear::track('2023-24')->update(['plan_requested_on' => '2025-02-20', 'plan_agreed' => false]);
        TaxYear::track('2022-23')->update(['plan_requested_on' => '2024-02-20', 'plan_agreed' => true, 'plan_broken_on' => '2024-06-01']);
        TaxYear::track('2021-22')->update(['plan_requested_on' => '2023-02-20']);

        $migration->down();
        $this->assertSame(['2021-22' => null, '2022-23' => null, '2023-24' => null, '2024-25' => '2026-02-20'],
            DB::table('tax_years')->orderBy('label')->pluck('payment_plan_from', 'label')->map(fn ($d) => $d ? substr((string) $d, 0, 10) : null)->all());
        $migration->up();
    }
}
