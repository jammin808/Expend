<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantTools;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\FilingDates;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\PenaltyRegime;
use App\Domain\Tax\Submissions;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxSubmission;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\Money;
use App\Support\Months;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Facts, dates and regimes behind the penalties: the notice to file and its date, filing dates and the payment date
 * they give, failure to notify where there's no notice, which penalty rules apply, HMRC's time limits, and the advice.
 * Expected dates come from the settled rules (legislation.gov.uk, GOV.UK, HMRC manuals).
 */
class PenaltyFactsTest extends TestCase
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

    private function codes(array $year, string $list = 'penalties'): array
    {
        return collect($year[$list])->mapWithKeys(fn ($p) => [$p['code'] => [$p['date'], $p['amount_pence']]])->all();
    }

    private function item(array $year, string $code, string $list = 'penalties'): ?array
    {
        return collect($year[$list])->firstWhere('code', $code);
    }

    private function ymd(?CarbonImmutable $d): ?string
    {
        return $d?->format('Y-m-d');
    }

    // ---- Month arithmetic (the corresponding-date rule) ----

    public function test_statutory_periods_use_the_corresponding_date_and_never_overflow(): void
    {
        $d = fn (string $s) => CarbonImmutable::parse($s);
        $end = fn (string $s, int $n) => Months::periodEnd($d($s), $n)->format('Y-m-d');

        $this->assertSame('2026-07-31', $end('2026-02-01', 6), '6 months beginning 1 February end 31 July');
        $this->assertSame('2026-01-31', $end('2025-11-01', 3));
        $this->assertSame('2026-02-14', $end('2025-11-15', 3), 's59B(3): notice 15 Nov 2025 gives 14 Feb 2026');
        $this->assertSame('2026-02-28', $end('2025-11-30', 3), 's59B(3): notice 30 Nov 2025 gives 28 Feb 2026');
        $this->assertSame('2027-02-28', $end('2026-08-31', 6), '31 Aug + 6 months ends 28 Feb (not 27 Feb)');
        $this->assertSame('2028-02-29', $end('2027-08-31', 6), 'and 29 Feb in a leap year');
        $this->assertSame('2025-05-29', $end('2024-11-30', 6));
        $this->assertSame('2026-01-30', $end('2024-01-31', 24), 'Sch 55 Date A for 2022-23');
        $this->assertSame('2026-02-28', $end('2024-03-01', 24), 'Sch 56 Date A for 2022-23');
        $this->assertSame('2027-03-01', Months::after($d('2026-11-30'), 3)->format('Y-m-d'), '30 Nov + 3 months starts 1 March, not 2 March');
        $this->assertSame('2028-02-29', Months::add($d('2028-01-31'), 1)->format('Y-m-d'));
    }

    // ---- Filing dates from the notice to file (TMA s8) ----

    public function test_filing_dates_follow_the_notice_to_file(): void
    {
        $year = TaxYear::track('2024-25');
        $dates = fn (array $facts) => FilingDates::for('2024-25', tap($year->fresh())->update($facts)->fresh());
        $none = ['notice_to_file' => null, 'notice_date' => null, 'deferred_to' => null, 'filing_date_override' => null, 'filed_method' => null, 'filed_on' => null, 'status' => 'outstanding'];

        // Not known: a notice by 31 July is assumed, giving the usual dates.
        $d = $dates($none);
        $this->assertSame('assumed', $d['notice']);
        $this->assertSame(['2026-01-31', '2025-10-31', '2026-01-31', 'law'], [$this->ymd($d['online']), $this->ymd($d['paper']), $this->ymd($d['filing_date']), $d['filing_basis']]);

        // A late notice: HMRC's system date of notice + 3 months + 7 days, an estimate (SAM121025's examples).
        $d = $dates(['notice_to_file' => 'given', 'notice_date' => '2025-08-10'] + $none);
        $this->assertSame(['2026-01-31', '2025-11-17', 'estimate'], [$this->ymd($d['online']), $this->ymd($d['paper']), $d['filing_basis']]);
        $d = $dates(['notice_to_file' => 'given', 'notice_date' => '2025-11-10'] + $none);
        $this->assertSame(['2026-02-17', '2026-02-17'], [$this->ymd($d['online']), $this->ymd($d['paper'])]);
        $this->assertStringContainsString('estimate', implode(' ', $d['notes']));
        $d = $dates(['notice_to_file' => 'given', 'notice_date' => '2025-10-28'] + $none);
        $this->assertSame('2026-02-04', $this->ymd($d['online']), 'a notice in late October gives an early-February online date');

        // A deferral moves the paper date, and the online date never falls before it; the date on HMRC's notice wins.
        $d = $dates(['deferred_to' => '2026-02-28'] + $none);
        $this->assertSame(['2026-02-28', '2026-02-28', 'deferred'], [$this->ymd($d['online']), $this->ymd($d['paper']), $d['filing_basis']]);
        $d = $dates(['notice_to_file' => 'given', 'notice_date' => '2025-11-10', 'filing_date_override' => '2026-02-20'] + $none);
        $this->assertSame(['2026-02-20', 'notice'], [$this->ymd($d['filing_date']), $d['filing_basis']]);

        // A return delivered on paper is measured against the paper date; an unfiled one against the online date.
        $d = $dates(['filed_method' => 'paper', 'filed_on' => '2025-12-01', 'status' => 'filed'] + $none);
        $this->assertSame('2025-10-31', $this->ymd($d['filing_date']));
        $d = $dates(['filed_method' => 'paper'] + $none);
        $this->assertSame('2026-01-31', $this->ymd($d['filing_date']));

        // No notice: no filing date at all, and the duty to tell HMRC by 5 October.
        $d = $dates(['notice_to_file' => 'not_given'] + $none);
        $this->assertNull($d['filing_date']);
        $this->assertTrue($d['missed_notify']);
        $this->assertSame('2025-10-05', $this->ymd($d['notify_by']));
    }

    public function test_the_balancing_payment_moves_only_under_s59b3(): void
    {
        $year = TaxYear::track('2024-25');
        $dates = fn (array $facts) => FilingDates::for('2024-25', tap($year->fresh())->update($facts)->fresh());

        $d = $dates(['notice_to_file' => 'given', 'notice_date' => '2025-11-15', 'told_hmrc_by_5_october' => true]);
        $this->assertSame(['2026-02-14', 'law'], [$this->ymd($d['balancing_due']), $d['balancing_basis']], 'told HMRC by 5 October, notice after 31 October');
        $d = $dates(['notice_date' => '2025-11-30']);
        $this->assertSame('2026-02-28', $this->ymd($d['balancing_due']));
        $this->assertFalse($d['missed_notify']);

        // Not known whether you told HMRC by 5 October: 31 January is assumed, and said so.
        $d = $dates(['told_hmrc_by_5_october' => null]);
        $this->assertSame(['2026-01-31', 'assumed'], [$this->ymd($d['balancing_due']), $d['balancing_basis']]);
        $this->assertStringContainsString('it’s due on 28 February 2026 instead', implode(' ', $d['notes']));
        $this->assertTrue($d['missed_notify'], 'a notice after 5 October doesn’t undo a failure to tell HMRC');
        $d = $dates(['told_hmrc_by_5_october' => false]);
        $this->assertSame(['2026-01-31', 'law'], [$this->ymd($d['balancing_due']), $d['balancing_basis']]);
    }

    public function test_the_last_days_to_file_your_own_return(): void
    {
        $this->assertSame('2026-04-05', $this->ymd(FilingDates::for('2021-22')['own_return_last']), 's34A: 2021-22 closed on 5 April 2026');
        $d = FilingDates::for('2022-23');
        $this->assertSame('2027-04-05', $this->ymd($d['own_return_last']));
        $this->assertSame('2027-01-30', $this->ymd($d['determination_last']), 's28C(5): 3 years beginning with 31 January 2024');

        TaxYear::track('2024-25')->update(['notice_to_file' => 'given', 'notice_date' => '2025-11-10']);
        $d = FilingDates::for('2024-25');
        $this->assertSame('2029-02-08', $this->ymd($d['determination_last']), 's28C(6): filing date 9 February 2026 for a notice after 31 October');
        $this->assertSame('2029-04-05', $this->ymd($d['own_return_last']));
    }

    // ---- The penalties that follow ----

    public function test_a_late_notice_moves_the_late_filing_and_payment_penalties(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'given', 'notice_date' => '2025-11-10', 'told_hmrc_by_5_october' => true]);
        $L = $this->liability('2024-25', 3_000_000);

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame(['2026-02-17', 'estimate', '2026-02-09'], [$y['deadline'], $y['deadline_basis'], $y['balancing_due']]);
        $codes = $this->codes($y);
        $this->assertSame(['2026-02-18', 10_000], $codes['sch55_initial']);
        $this->assertTrue($this->item($y, 'sch55_initial')['estimate']);
        $this->assertStringContainsString('estimated from the date of HMRC’s notice', $this->item($y, 'sch55_initial')['assumption']);
        $this->assertSame(['2026-05-18', 90_000], $codes['sch55_daily'], 'from the end of the 3 months beginning with 18 February');
        $this->assertSame(['2026-08-18', 30_000], $codes['sch55_6m']);
        $this->assertSame(['2027-02-18', 30_000], $this->codes($y, 'upcoming')['sch55_12m']);
        $this->assertSame(['2026-03-12', intdiv($L * 5, 100)], $codes['sch56_30d'], 'T1 is 30 days after the s59B(3) date of 9 February');
        $this->assertArrayNotHasKey('sch41', $codes, 'told HMRC by 5 October: no failure to notify');

        // Not known whether HMRC were told by 5 October: 31 January stands (an estimate), and failure to notify applies.
        TaxYear::query()->where('label', '2024-25')->update(['told_hmrc_by_5_october' => null]);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame('2026-01-31', $y['balancing_due']);
        $this->assertSame('2026-03-03', $this->item($y, 'sch56_30d')['date']);
        $this->assertTrue($this->item($y, 'sch56_30d')['estimate']);
        $this->assertSame(['min_pence' => 0, 'max_pence' => intdiv($L * 30, 100)], $this->item($y, 'sch41')['range']);
    }

    public function test_no_notice_to_file_means_a_failure_to_notify_penalty_not_late_filing(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'not_given', 'notice_date' => null]);
        $L = $this->liability('2024-25', 3_000_000);

        $y = PenaltyEstimator::forYear('2024-25');
        $codes = $this->codes($y);
        $this->assertEmpty(array_filter(array_keys($codes + $this->codes($y, 'upcoming')), fn ($c) => str_starts_with($c, 'sch55') || $c === 'points'), 'no late filing penalty without a notice');
        $this->assertNull($y['deadline']);
        $this->assertFalse($y['return_needed']);
        $this->assertTrue($y['notify_hmrc']);
        // Tax unpaid on 31 January 2026; HMRC can't yet have known for 12 months, so telling them now can mean 0%.
        $sch41 = $this->item($y, 'sch41');
        $this->assertSame(['2026-02-01', 0, 'due', true], [$sch41['date'], $sch41['amount_pence'], $sch41['status'], $sch41['estimate']]);
        $this->assertSame(['min_pence' => 0, 'max_pence' => intdiv($L * 30, 100)], $sch41['range']);
        $this->assertStringContainsString('You hadn’t told HMRC by 5 October 2025', $sch41['why']);
        $this->assertStringContainsString('Assumes you tell HMRC yourself', $sch41['assumption']);
        // The tax is still due on 31 January, so late payment penalties and interest still run.
        $this->assertSame(['2026-03-03', intdiv($L * 5, 100)], $codes['sch56_30d']);
        $this->assertStringStartsWith('Tell HMRC now that you need to file a return for 2024-25', $y['actions'][0]);
        $this->assertStringContainsString('by 31 January 2027 can bring the penalty down to nothing', $y['actions'][0]);
    }

    public function test_failure_to_notify_more_than_12_months_on_is_at_least_10_percent(): void
    {
        // 2023-24: the tax was first unpaid on 1 February 2025, more than 12 months ago (case B).
        $this->tx('2023-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2023-24')->update(['notice_to_file' => 'not_given']);
        $y = PenaltyEstimator::forYear('2023-24');
        $L23 = $this->liability('2023-24', 3_000_000);
        $this->assertSame(intdiv($L23 * 10, 100), $this->item($y, 'sch41')['amount_pence']);
        $this->assertStringContainsString('can bring the penalty down to as little as 10% of the tax that was unpaid on 31 January 2025 (it can be up to 30%; HMRC decide)', $y['actions'][0]);
    }

    public function test_failure_to_notify_is_nil_when_the_tax_is_paid_by_31_january(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'not_given']);
        $this->tx('2026-01-20', -$this->liability('2024-25', 3_000_000), 'tax_payment');

        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertNull($this->item($y, 'sch41'));
        $this->assertSame([], $y['penalties']);
        $this->assertTrue($y['notify_hmrc'], 'you still need to tell HMRC');
    }

    public function test_the_need_to_tell_hmrc_counts_other_business_income_with_turnover(): void
    {
        $this->tx('2024-09-10', 60_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'not_given']);
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertFalse($y['chargeable'], '£600 is inside the £1,000 trading allowance');
        $this->assertFalse($y['notify_hmrc']);

        $this->tx('2024-10-10', 60_000, 'business_income', 'other_income');
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertTrue($y['chargeable'], '£600 of sales and £600 of other business income is over £1,000');
        $this->assertTrue($y['notify_hmrc']);
    }

    public function test_before_and_after_5_october_with_no_notice(): void
    {
        $this->tx('2025-09-10', 2_000_000, 'business_income', 'turnover');
        $this->tx('2026-06-10', 2_000_000, 'business_income', 'turnover');
        TaxYear::track('2025-26')->update(['notice_to_file' => 'not_given']);
        TaxYear::track('2026-27')->update(['notice_to_file' => 'not_given']);

        // 2026-27: not late yet.
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertNull($this->item($y, 'sch41', 'upcoming'));
        $this->assertStringStartsWith('Tell HMRC by 5 October 2027 that you need to file a return for 2026-27', $y['actions'][0]);

        // 2025-26: 5 October 2026 has passed; the penalty depends on what's unpaid on 31 January 2027.
        $y = PenaltyEstimator::forYear('2025-26');
        $L = $this->liability('2025-26', 2_000_000);
        $sch41 = $this->item($y, 'sch41', 'upcoming');
        $this->assertSame(['2027-02-01', 0], [$sch41['date'], $sch41['amount_pence']]);
        $this->assertSame(intdiv($L * 30, 100), $sch41['range']['max_pence']);
        $this->assertStringContainsString('Paying it all by 31 January 2027 means no penalty', $sch41['why']);
        $this->assertStringContainsString('Pay the year’s tax by 31 January 2027 and there’s no penalty for telling them late', $y['actions'][0]);
    }

    // ---- Which rules apply ----

    public function test_the_regime_follows_the_law_and_marks_announced_rules(): void
    {
        $this->assertSame('sch55_56', PenaltyRegime::for('2023-24'));
        $this->assertSame('sch55_56', PenaltyRegime::for('2026-27'));
        $this->assertSame('sch24_26_announced', PenaltyRegime::for('2027-28'), 'everyone from 2027-28 is announced, not law');

        // An accepted volunteer is under the new rules by law from their year (never before 2024-25).
        Setting::put('mtd_volunteer_from', '2025-26');
        $this->assertSame('sch55_56', PenaltyRegime::for('2024-25'));
        $this->assertSame('sch24_26_law', PenaltyRegime::for('2025-26'));
        $this->assertSame('sch24_26_law', PenaltyRegime::for('2027-28'));
        $this->assertSame('2025-26', PenaltyRegime::firstNewRulesYear());
        Setting::put('mtd_volunteer_from', null);

        // In MTD from April 2026: 2024-25 qualifying income MORE than £50,000, turnover and other income together.
        $this->tx('2024-09-10', 5_000_000, 'business_income', 'turnover');
        $this->assertSame('sch55_56', PenaltyRegime::for('2026-27'), 'exactly £50,000 is exempt');
        $this->tx('2024-10-10', 100, 'business_income', 'other_income');
        $this->assertSame('sch24_26_law', PenaltyRegime::for('2026-27'));
        $this->assertSame('sch24_26_law', PenaltyRegime::for('2027-28'));
        $this->assertSame('sch55_56', PenaltyRegime::for('2025-26'), 'the April 2026 cohort is on the old rules for 2025-26');
        $this->assertSame('2026-27', PenaltyRegime::firstNewRulesYear());

        // Exempt from MTD: back to the old rules for 2026-27.
        Setting::put('mtd_exempt', true);
        $this->assertSame('sch55_56', PenaltyRegime::for('2026-27'));
        $this->assertSame('sch24_26_announced', PenaltyRegime::for('2027-28'));
    }

    public function test_announced_years_show_the_law_with_the_new_rules_beside_it_as_an_estimate(): void
    {
        config(['tax.years.2027-28' => config('tax.years.2026-27')]);
        $this->tx('2027-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2027-28');
        $this->at('2029-03-20');

        $y = PenaltyEstimator::forYear('2027-28');
        $L = $y['liability_pence'];
        $this->assertGreaterThan(0, $L);
        $this->assertSame('sch24_26_announced', $y['regime']);
        $this->assertStringContainsString('aren’t law yet', $y['regime_text']);
        // The law today: Schedules 55 and 56.
        $codes = $this->codes($y);
        $this->assertSame(['2029-02-01', 10_000], $codes['sch55_initial']);
        $this->assertSame(['2029-03-03', intdiv($L * 5, 100)], $codes['sch56_30d']);
        $this->assertArrayNotHasKey('points', $codes);
        $this->assertSame(array_sum(array_column($y['penalties'], 'amount_pence')), $y['penalties_pence']);
        // The announced rules, beside them and never in the totals: a point, and 4% + 4% after the first-year 30 days.
        $alt = collect($y['announced']['penalties'])->keyBy('code');
        $this->assertSame(0, $alt['points']['amount_pence']);
        $this->assertSame(['2029-03-03', intdiv($L * 4, 100)], [$alt['sch26_a']['date'], $alt['sch26_a']['amount_pence']]);
        $this->assertSame(intdiv($L * 4, 100), $alt['sch26_b']['amount_pence']);
        foreach ($alt as $p) {
            $this->assertTrue($p['estimate']);
            $this->assertStringStartsWith('Announced, not yet law', $p['assumption']);
        }
        $this->assertStringContainsString('aren’t law yet', implode(' ', $y['assumptions']));
    }

    // ---- HMRC's time limits for charging penalties ----

    public function test_penalties_past_hmrcs_time_limit_are_labelled(): void
    {
        $this->tx('2022-09-10', 3_000_000, 'business_income', 'turnover');
        $this->tx('2023-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2022-23');
        TaxYear::track('2023-24');

        // 2022-23, never filed: Date A has passed for Sch 55 (30 January 2026) and Sch 56 (28 February 2026).
        $y = PenaltyEstimator::forYear('2022-23');
        foreach (['sch55_initial', 'sch55_daily', 'sch55_6m', 'sch55_12m'] as $code) {
            $p = $this->item($y, $code);
            $this->assertSame('2026-01-30', $p['date_a'], $code);
            $this->assertSame('Only if HMRC charged it by 30 January 2026. If they didn’t, it can be charged for 12 months after you file.', $p['time_limit'], $code);
        }
        $this->assertSame('2026-02-28', $this->item($y, 'sch56_30d')['date_a']);
        $this->assertStringStartsWith('Only if HMRC charged it by 28 February 2026.', $this->item($y, 'sch56_30d')['time_limit']);
        // They still count: HMRC normally charge them in time.
        $this->assertGreaterThan(0, $y['penalties_pence']);

        // 2023-24: Date A is 30 January 2027, still ahead.
        $p = $this->item(PenaltyEstimator::forYear('2023-24'), 'sch55_initial');
        $this->assertSame(['2027-01-30', null], [$p['date_a'], $p['time_limit']]);

        // Filed late: 12 months from the filing (Date B) has also passed.
        TaxYear::query()->where('label', '2022-23')->update(['status' => 'filed', 'filed_on' => '2024-03-01']);
        $p = $this->item(PenaltyEstimator::forYear('2022-23'), 'sch55_initial');
        $this->assertSame('Only if HMRC charged it by about 30 January 2026; after that it can no longer be charged.', $p['time_limit']);
    }

    // ---- What's charged now and what's added on filing (SAM61240) ----

    public function test_unfiled_six_and_twelve_month_penalties_are_300_now_and_the_rest_on_filing(): void
    {
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $L = $this->liability('2024-25', 6_000_000);
        $full = intdiv($L * 5, 100);
        $this->assertGreaterThan(30_000, $full);

        $y = PenaltyEstimator::forYear('2024-25');
        $six = $this->item($y, 'sch55_6m');
        $this->assertSame(['2026-08-01', 30_000, $full - 30_000], [$six['date'], $six['amount_pence'], $six['on_filing_pence']]);
        $this->assertSame('Six months late: £300.00 now, '.Money::format($full - 30_000).' more when you file', $six['label']);
        $twelve = $this->item($y, 'sch55_12m', 'upcoming');
        $this->assertSame(['2027-02-01', 30_000, $full - 30_000], [$twelve['date'], $twelve['amount_pence'], $twelve['on_filing_pence']]);
        $this->assertSame($full - 30_000, $y['on_filing_pence']);
        $this->assertSame('File the return as soon as you can: if it isn’t in by the end of 31 January 2027, a twelve-month penalty of '.Money::format($full)
            .' follows (5% of the tax, at least £300).', $y['actions'][0]);

        // Filed: the whole 5% is charged.
        $y = PenaltyEstimator::forYear('2024-25', null, ['filed_on' => ['2024-25' => '2026-11-30']]);
        $six = $this->item($y, 'sch55_6m');
        $this->assertSame([$full, 0], [$six['amount_pence'], $six['on_filing_pence']]);
    }

    public function test_sam61230_daily_penalties_count_the_day_the_return_arrives(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $daily = fn (string $filed) => $this->codes(PenaltyEstimator::forYear('2024-25', null, ['filed_on' => ['2024-25' => $filed]]))['sch55_daily'] ?? null;

        $this->assertSame(['2026-05-01', 47 * 1_000], $daily('2026-06-16'), 'SAM61230 example 1: 1 May to 16 June is 47 days, £470');
        $this->assertSame(['2026-05-01', 1_000], $daily('2026-05-01'));
        $this->assertNull($daily('2026-04-30'));
        $this->assertSame(['2026-05-01', 90_000], $daily('2026-11-30'), 'never more than 90 days');
    }

    // ---- What to do ----

    public function test_actions_for_new_rules_years_say_points_not_100_or_10_a_day(): void
    {
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2026-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-10']);
        TaxYear::track('2026-27');

        $this->at('2027-12-01');
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame('File the return by 31 January 2028 to avoid a late submission point.', $y['actions'][0]);

        // The 2027-28 quarterly updates went on time, so the late return is the only failure that earns a point.
        Submissions::sync('2027-28');
        TaxSubmission::query()->where('tax_year', '2027-28')->get()->each(fn ($s) => $s->update(['submitted_on' => $s->due_on->format('Y-m-d') < '2028-03-01' ? $s->due_on : null]));
        $this->at('2028-03-01');
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame('File the return as soon as you can. Being late has earned a late submission point (you have 1 of 4 now); at 4 points each late submission costs £200. '
            .'Until it’s filed your points can’t all go: everything due in the last 24 months has to be sent.', $y['actions'][0]);
        $this->assertStringContainsString('At 4 points (the threshold for people in Making Tax Digital)', $this->item($y, 'points')['why']);
        foreach ($y['actions'] as $a) {
            $this->assertStringNotContainsString('£10', $a);
            $this->assertStringNotContainsString('£100', $a);
        }
    }

    public function test_actions_for_years_too_old_to_file_and_the_determination_deadline(): void
    {
        $this->tx('2021-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2022-09-10', 2_000_000, 'business_income', 'turnover');
        TaxYear::track('2021-22');
        TaxYear::track('2022-23');

        $y = PenaltyEstimator::forYear('2021-22');
        $this->assertTrue($y['too_late_to_file']);
        $this->assertStringContainsString('ended on 5 April 2026, so it can no longer be filed in the usual way', $y['actions'][0]);
        foreach ($y['actions'] as $a) {
            $this->assertStringStartsNotWith('File the return', $a);
        }
        $this->assertStringContainsString('more once HMRC assess the tax', $this->item($y, 'sch55_6m')['label']);
        $this->assertStringContainsString('they can only charge it once they assess the tax for the year', $this->item($y, 'sch55_initial')['time_limit']);

        // 2022-23 can still be filed, and a determination can only be replaced by 30 January 2027.
        $y = PenaltyEstimator::forYear('2022-23');
        $this->assertSame('File the return as soon as you can. You can only file it yourself until 5 April 2027.', $y['actions'][0]);
        $this->assertNotEmpty(array_filter($y['actions'], fn ($a) => str_contains($a, 'your return must reach them by 30 January 2027 to replace it')));
    }

    public function test_daily_penalty_and_payment_plan_wording(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $y = PenaltyEstimator::forYear('2024-25');

        $why = $this->item($y, 'sch55_daily')['why'];
        $this->assertStringContainsString('counted up to and including the day HMRC get your return', $why);
        $this->assertStringContainsString('HMRC must give notice of the start date, usually in the £100 penalty notice', $why);
        $plan = collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'Can’t pay it all?'));
        foreach (['£30,000 or less', 'your returns are filed (including this one)', 'no other tax debts or HMRC payment plans', 'Direct Debit', 'within 12 months', 'Interest still runs'] as $words) {
            $this->assertStringContainsString($words, $plan);
        }
        $this->assertStringContainsString('Assumes HMRC sent you a notice to file by 31 July 2025', implode(' ', $y['assumptions']));
    }

    public function test_years_too_old_to_file_show_only_while_something_is_owed(): void
    {
        $this->tx('2021-09-10', 300_000, 'business_income', 'turnover');
        TaxYear::track('2021-22', 'filed');
        TaxYear::track('2022-23');

        $this->assertNull(PenaltyEstimator::forYear('2021-22'), 'filed, no tax, and past its time limit');
        $this->assertNotNull(PenaltyEstimator::forYear('2022-23'), 'still fileable');
    }

    // ---- Owner inputs, the to-do list and the assistant ----

    public function test_the_year_page_saves_the_notice_facts(): void
    {
        $this->actingAs(User::factory()->create());
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');

        $this->patch('/years/2024-25', ['notice_to_file' => 'given', 'notice_date' => '2025-11-10', 'filed_method' => 'online', 'told_hmrc_by_5_october' => true])->assertSessionHas('success');
        $year = TaxYear::query()->where('label', '2024-25')->first();
        $this->assertSame(['given', '2025-11-10', 'online', true], [$year->notice_to_file, $year->notice_date->format('Y-m-d'), $year->filed_method, $year->told_hmrc_by_5_october]);
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('year.deadline', '2026-02-17')
            ->where('penalties.deadline', '2026-02-17')->where('penalties.balancing_due', '2026-02-09')->where('penalties.notice.notice_date', '2025-11-10'));

        $this->patch('/years/2024-25', ['notice_date' => '2025-03-01'])->assertSessionHasErrors('notice_date');
        $this->patch('/years/2024-25', ['notice_to_file' => 'maybe'])->assertSessionHasErrors('notice_to_file');
        $this->patch('/years/2024-25', ['filed_method' => 'fax'])->assertSessionHasErrors('filed_method');

        // No notice: no date, no deadline on any page. Having said you told HMRC by 5 October, there's nothing to tell.
        $this->patch('/years/2024-25', ['notice_to_file' => 'not_given'])->assertSessionHas('success');
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('penalties.notify_hmrc', false)->where('penalties.told_no_notice', true));
        // Not told: the to-do is to tell HMRC.
        $this->patch('/years/2024-25', ['told_hmrc_by_5_october' => null])->assertSessionHas('success');
        $this->assertNull(TaxYear::query()->where('label', '2024-25')->value('notice_date'));
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('year.deadline', null)->where('penalties.notify_hmrc', true));
        $this->get('/years')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.deadline', null));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.no_notice', true)->where('years.0.overdue', false));

        // Back to "don't know".
        $this->patch('/years/2024-25', ['notice_to_file' => null])->assertSessionHas('success');
        $this->assertNull(TaxYear::query()->where('label', '2024-25')->value('notice_to_file'));
    }

    public function test_settings_save_the_making_tax_digital_status(): void
    {
        $this->actingAs(User::factory()->create());
        $base = ['home_postcode' => '', 'overnight_near_miles' => 12, 'overnight_far_miles' => 40, 'early_start' => '08:00', 'late_finish' => '22:00',
            'mileage_threshold_scope' => 'all_vehicles', 'scottish_taxpayer' => false, 'own_names' => '', 'own_other_accounts' => ''];

        Setting::put('mtd_exempt', true); // the old tick, with no year: read as exempt from 2026-27
        $this->assertSame('2026-27', PenaltyRegime::exemptFrom());
        $this->put('/settings/ledger', $base + ['mtd_volunteer_from' => '2025-26', 'mtd_exempt_from' => '2027-28', 'mtd_exempt_never_required' => true])->assertSessionHas('success');
        $this->assertSame(['2025-26', '2027-28', true, false], [Setting::get('mtd_volunteer_from'), Setting::get('mtd_exempt_from'), Setting::get('mtd_exempt_never_required'), Setting::get('mtd_exempt')]);
        $this->assertSame('sch24_26_law', PenaltyRegime::for('2025-26'));
        $this->put('/settings/ledger', $base + ['mtd_exempt_from' => '2027'])->assertSessionHasErrors('mtd_exempt_from');
        $this->put('/settings/ledger', $base + ['mtd_exempt_from' => '', 'mtd_exempt_never_required' => true])->assertSessionHas('success');
        $this->assertSame([null, false], [Setting::get('mtd_exempt_from'), Setting::get('mtd_exempt_never_required')], 'never required means nothing without a year');
        $this->assertNull(PenaltyRegime::exemptFrom());

        $this->put('/settings/ledger', $base + ['mtd_volunteer_from' => '2023-24'])->assertSessionHasErrors('mtd_volunteer_from');
        $this->put('/settings/ledger', $base + ['mtd_volunteer_from' => ''])->assertSessionHas('success');
        $this->assertNull(PenaltyRegime::volunteerFrom());
    }

    public function test_the_to_do_list_and_the_assistant_carry_the_notice(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        $this->tx('2025-06-10', 500_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        TaxYear::track('2025-26')->update(['notice_to_file' => 'not_given']);

        $items = collect(TodoList::build()['items'])->keyBy('key');
        $this->assertSame('Tell HMRC you need to file for 2025-26', $items['penalty:2025-26']['title']);
        $this->assertArrayNotHasKey('deadline:2025-26', $items->all(), 'no notice, no return due');

        $answer = app(AssistantTools::class)->run('penalties', []);
        $years = collect($answer['years'])->keyBy('tax_year');
        $this->assertSame('none sent', $years['2025-26']['notice_to_file']);
        $this->assertNull($years['2024-25']['must_tell_hmrc'], 'a notice is assumed for 2024-25');
        $this->assertSame('by 2026-10-05', $years['2025-26']['must_tell_hmrc']);
        $this->assertStringStartsWith('not known', $years['2024-25']['notice_to_file']);
        $this->assertTrue($years['2024-25']['already_due'][0]['estimate'], 'rests on the assumed notice');
        $this->assertStringContainsString('notice to file', $years['2024-25']['already_due'][0]['assumption']);
    }
}
