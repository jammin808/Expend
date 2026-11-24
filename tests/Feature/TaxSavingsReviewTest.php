<?php

namespace Tests\Feature;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Tax\TaxSavings;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The ways-to-pay-less-tax review: years in progress, claim deadlines, pension relief, the headline and what counts as claimed. */
class TaxSavingsReviewTest extends TestCase
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

    private function tx(string $date, int $pence, string $kind, ?string $code = null, string $by = 'you', bool $confirm = true): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $kind.' '.$this->n, 'merchant_key' => strtoupper($kind).$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $code ? Category::query()->where('code', $code)->value('id') : null), $by, $confirm);

        return $tx;
    }

    /** A payment nobody has decided on yet. */
    private function undecided(string $date, int $pence): void
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => 'CARD PAYMENT '.$this->n, 'merchant_key' => 'CARD'.$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1, 'review_status' => 'unreviewed']);
    }

    private function cash(string $date, int $pence, string $code): void
    {
        $spent = ManualExpense::query()->create(['spent_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(), 'description' => 'Taxi '.$date,
            'merchant_key' => 'TAXI', 'paid_with' => 'cash', 'amount_pence' => $pence]);
        app(AllocationWriter::class)->decide($spent, AllocationWriter::lines($pence, 'business_expense', Category::query()->where('code', $code)->value('id')));
    }

    private function item(array $review, string $id): ?array
    {
        return collect($review['items'])->firstWhere('id', $id);
    }

    /** Income tax only, as the pension relief works it out. */
    private function incomeTax(string $label, int $profit): int
    {
        return TaxEstimator::estimate($label, $profit)['income_tax']['total_pence'];
    }

    // ---- A year that isn't over (TS-1, EA-2) ----

    public function test_a_year_in_progress_is_flagged_and_payments_on_account_use_a_labelled_projection(): void
    {
        $this->tx('2025-06-10', 4_000_000, 'business_income', 'turnover');
        $this->tx('2026-06-10', 2_000_000, 'business_income', 'turnover');
        $this->tx('2026-07-10', -50_000, 'business_expense', 'software');

        $review = TaxSavings::review('2026-27');
        $this->assertTrue($review['in_progress']);
        $this->assertStringStartsWith('This year isn’t over: figures are from your records so far', $review['note']);
        $this->assertStringContainsString('estimates', $review['note']);

        // 6 April to 24 November is 233 of the year's 365 days: the profit so far is scaled up and said to be a projection.
        $projected = (int) round(1_950_000 * 365 / 233);
        $est = TaxEstimator::estimate('2026-27', $projected);
        $poa = $this->item($review, 'payments-on-account');
        $this->assertNotNull($poa);
        $this->assertSame('check', $poa['status']);
        $this->assertStringContainsString('first 7 months point to a full-year profit of about '.Money::format($projected, 'GBP', false).' (a simple projection', $poa['finding']);
        $this->assertStringContainsString('about '.Money::format(intdiv($est['income_tax']['total_pence'] + $est['class4_pence'], 2), 'GBP', false).' each', $poa['finding']);
        $this->assertStringContainsString('Confirm your expected full-year profit first', $poa['finding']);

        // Checks that need the whole year wait for it: £500 of costs so far isn't a reason to take the trading allowance.
        $this->assertNull($this->item($review, 'voluntary-class-2'));
        $this->assertNull($this->item($review, 'trading-allowance-comparison'));

        $done = TaxSavings::review('2025-26');
        $this->assertFalse($done['in_progress']);
        $this->assertStringNotContainsString('isn’t over', $done['note']);
    }

    public function test_no_projection_without_confirmed_income(): void
    {
        $this->tx('2025-06-10', 4_000_000, 'business_income', 'turnover');

        // Nothing for 2026-27 yet: a projection of nothing would say the payments could be cut to £0.00.
        $this->assertNull($this->item(TaxSavings::review('2026-27'), 'payments-on-account'));

        // Income imported and suggested, but not confirmed, isn't in the figures either.
        foreach (['2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10', '2026-11-10'] as $day) {
            $this->tx($day, 350_000, 'business_income', 'turnover', 'matcher', false);
        }
        $this->assertSame(7, ReturnSummary::for('2026-27')['pending']['suggested_count']);
        $this->assertNull($this->item(TaxSavings::review('2026-27'), 'payments-on-account'));

        // Some confirmed, the rest still waiting: the projection would still be too low, so it waits too.
        $this->tx('2026-04-20', 350_000, 'business_income', 'turnover');
        $this->assertNull($this->item(TaxSavings::review('2026-27'), 'payments-on-account'));

        // A finished year before its deadline: the same, as its tax would only look lower.
        $this->at('2027-05-01');
        $this->assertNull($this->item(TaxSavings::review('2026-27'), 'payments-on-account'));
    }

    public function test_a_loss_so_far_is_not_scaled_up_or_called_a_profit(): void
    {
        $this->tx('2025-06-10', 4_000_000, 'business_income', 'turnover');
        $this->tx('2026-06-10', 100_000, 'business_income', 'turnover');
        $this->tx('2026-07-10', -500_000, 'business_expense', 'software');

        $poa = $this->item(TaxSavings::review('2026-27'), 'payments-on-account');
        $this->assertSame('check', $poa['status']);
        $this->assertStringContainsString('Your records for the first 7 months show a loss so far (£4,000.00).', $poa['finding']);
        $this->assertStringContainsString('If you expect the whole year to make a loss or a much smaller profit', $poa['finding']);
        $this->assertStringContainsString('Confirm your expected full-year profit first', $poa['finding']);
        $this->assertStringNotContainsString('£0.00 each', $poa['finding']);
        $this->assertStringNotContainsString('profit like that', $poa['finding']);
        $this->assertStringNotContainsString('projection', $poa['finding']);
    }

    public function test_early_in_a_year_a_few_weeks_of_records_drive_nothing(): void
    {
        $this->at('2026-05-20');
        $this->tx('2025-06-10', 4_000_000, 'business_income', 'turnover');
        $this->tx('2026-05-01', 300_000, 'business_income', 'turnover');

        $review = TaxSavings::review('2026-27');
        $this->assertTrue($review['in_progress']);
        // Under three full months: no projection, so no advice to cut payments on account.
        $this->assertNull($this->item($review, 'payments-on-account'));
        $this->assertNull($this->item($review, 'voluntary-class-2'));
        $this->assertNull($this->item($review, 'trading-allowance-comparison'));
    }

    public function test_a_loss_so_far_is_only_information_and_booked_days_still_to_come_are_not_counted(): void
    {
        $this->tx('2026-05-01', 100_000, 'business_income', 'turnover');
        $this->tx('2026-05-02', -300_000, 'business_expense', 'software');
        // Five of these eleven days have happened by 24 November.
        WorkJob::query()->create(['title' => 'Winter tour', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-30']);

        $review = TaxSavings::review('2026-27');
        $loss = $this->item($review, 'loss-relief');
        $this->assertSame('info', $loss['status']);
        $this->assertStringContainsString('a loss so far of £2,000.00', $loss['finding']);
        $this->assertStringStartsWith('5 of your 5 job days so far in 2026-27', $this->item($review, 'itinerant-travel')['finding']);
    }

    public function test_a_stay_still_going_counts_only_the_nights_so_far(): void
    {
        $this->tx('2026-05-01', 1_000_000, 'business_income', 'turnover');
        $job = WorkJob::query()->create(['title' => 'Winter tour', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-30']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Tour hotel', 'status' => 'booked', 'work_job_id' => $job->id,
            'starts_on' => '2026-11-20', 'ends_on' => '2026-11-30', 'source' => 'manual', 'evidence' => []]);

        // Ten nights are booked; on 24 November the nights of the 20th to the 23rd have passed.
        $meals = $this->item(TaxSavings::review('2026-27'), 'meals-s57a');
        $this->assertStringStartsWith('You stayed away for 4 nights on jobs so far but no meals are claimed.', $meals['finding']);

        // Once the year is over, the whole stay counts.
        $this->at('2027-05-01');
        $this->assertStringStartsWith('You stayed away for 10 nights on jobs but', $this->item(TaxSavings::review('2026-27'), 'meals-s57a')['finding']);
    }

    public function test_only_the_nights_inside_the_year_count(): void
    {
        $this->tx('2025-05-01', 1_000_000, 'business_income', 'turnover');
        $job = WorkJob::query()->create(['title' => 'Easter festival', 'starts_on' => '2025-04-02', 'ends_on' => '2025-04-09']);
        // Nights of 2 to 8 April 2025: the 2nd to the 5th are in 2024-25, the 6th to the 8th in 2025-26.
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Festival hotel', 'status' => 'booked', 'work_job_id' => $job->id,
            'starts_on' => '2025-04-02', 'ends_on' => '2025-04-09', 'source' => 'manual', 'evidence' => []]);

        $this->assertStringStartsWith('You stayed away for 3 nights on jobs but', $this->item(TaxSavings::review('2025-26'), 'meals-s57a')['finding']);
        $this->assertStringStartsWith('You stayed away for 4 nights on jobs but', $this->item(TaxSavings::review('2024-25'), 'meals-s57a')['finding']);
    }

    public function test_pension_relief_in_a_year_in_progress_is_worded_as_so_far(): void
    {
        $this->tx('2026-06-10', 8_000_000, 'business_income', 'turnover');

        $pension = $this->item(TaxSavings::review('2026-27'), 'pension-contributions');
        $this->assertStringStartsWith('On your profit so far this year', $pension['finding']);
        $this->assertStringContainsString('paid by 5 April 2027', $pension['finding']);
    }

    // ---- Payments on account: the claim deadline (TS-2) ----

    public function test_payments_on_account_are_not_suggested_after_the_claim_deadline(): void
    {
        $this->tx('2023-06-10', 5_000_000, 'business_income', 'turnover');
        $this->tx('2024-06-10', 2_000_000, 'business_income', 'turnover');

        // A claim to reduce 2024-25's payments had to be made before 31 January 2026.
        $this->assertNull($this->item(TaxSavings::review('2024-25'), 'payments-on-account'));
    }

    public function test_payments_on_account_are_suggested_before_the_deadline_and_not_once_the_return_is_filed(): void
    {
        $this->tx('2024-06-10', 5_000_000, 'business_income', 'turnover');
        $this->tx('2025-06-10', 2_000_000, 'business_income', 'turnover');

        $poa = $this->item(TaxSavings::review('2025-26'), 'payments-on-account');
        $this->assertSame('action', $poa['status']);
        $this->assertStringContainsString('before 31 January 2027', $poa['finding']);

        TaxYear::track('2025-26', 'filed');
        $this->assertNull($this->item(TaxSavings::review('2025-26'), 'payments-on-account'));

        // A filing date counts as filed too, whatever the status says.
        TaxYear::query()->where('label', '2025-26')->update(['status' => 'ready_to_file', 'filed_on' => '2026-10-01']);
        $this->assertNull($this->item(TaxSavings::review('2025-26'), 'payments-on-account'));
    }

    // ---- VAT: the rolling test is about now (TS-3) ----

    public function test_the_vat_check_is_only_on_the_current_year_at_todays_threshold(): void
    {
        $this->tx('2026-06-10', 8_700_000, 'business_income', 'turnover');

        // 2023-24's £85,000 threshold doesn't apply to the last 12 months, and that page isn't about now.
        $this->assertNull($this->item(TaxSavings::review('2023-24'), 'vat-review'));
        $this->assertNull($this->item(TaxSavings::review('2025-26'), 'vat-review'));

        $vat = $this->item(TaxSavings::review('2026-27'), 'vat-review');
        $this->assertSame('check', $vat['status']);
        $this->assertStringContainsString('(£90,000.00)', $vat['finding']);
    }

    // ---- Pension relief (TS-4, EA-3) ----

    public function test_pension_relief_above_the_additional_rate_is_capped_at_the_annual_allowance(): void
    {
        $this->tx('2025-06-10', 15_000_000, 'business_income', 'turnover');

        $pension = $this->item(TaxSavings::review('2025-26'), 'pension-contributions');
        // £150,000 - £50,270 = £99,730 would get more than 20%, but only £60,000 fits the annual allowance.
        $gross = 6_000_000;
        $relief = $this->incomeTax('2025-26', 15_000_000) - $this->incomeTax('2025-26', 15_000_000 - $gross) - intdiv($gross * 20, 100);
        $this->assertSame(1_827_100, $relief);
        $this->assertSame($relief, $pension['saving_pence']);
        $this->assertStringContainsString('up to £60,000.00 gross', $pension['finding']);
        $this->assertStringContainsString('40%, about 60% (where your personal allowance is being withdrawn) and 45%', $pension['finding']);
        $this->assertStringContainsString('£60,000 annual allowance', $pension['finding']);
        $this->assertStringContainsString('carried forward', $pension['finding']);
        $this->assertStringNotContainsString('taxed at the higher rate', $pension['finding']);
    }

    public function test_pension_relief_in_the_taper_band_counts_the_restored_allowance(): void
    {
        $this->tx('2025-06-10', 11_000_000, 'business_income', 'turnover');

        $pension = $this->item(TaxSavings::review('2025-26'), 'pension-contributions');
        $gross = 11_000_000 - (1_257_000 + 3_770_000);
        $this->assertSame(1_394_600, $this->incomeTax('2025-26', 11_000_000) - $this->incomeTax('2025-26', 11_000_000 - $gross) - intdiv($gross * 20, 100));
        $this->assertSame(1_394_600, $pension['saving_pence']);
        $this->assertStringContainsString('up to £59,730.00 gross', $pension['finding']);
        $this->assertStringContainsString('40% and about 60%', $pension['finding']);
        $this->assertStringNotContainsString('45%', $pension['finding']);
        $this->assertStringNotContainsString('annual allowance', $pension['finding']);
    }

    // ---- Scottish taxpayers (TS-5, EA-4) ----

    public function test_a_scottish_taxpayer_gets_no_rest_of_uk_rates_or_figures(): void
    {
        Setting::put('scottish_taxpayer', true);
        $this->tx('2025-06-10', 11_000_000, 'business_income', 'turnover');

        $review = TaxSavings::review('2025-26');
        $pension = $this->item($review, 'pension-contributions');
        $this->assertSame('check', $pension['status']);
        $this->assertNull($pension['saving_pence']);
        $this->assertStringContainsString('Scottish rate', $pension['finding']);
        $this->assertStringNotContainsString('40%', $pension['finding']);

        $taper = $this->item($review, 'personal-allowance-and-hicbc');
        $this->assertStringNotContainsString('60%', $taper['finding']);
        $this->assertStringContainsString('£125,140', $taper['finding']);
        $this->assertSame(0, $review['total_saving_pence']);
    }

    public function test_a_scottish_taxpayer_below_the_rest_of_uk_higher_rate_still_hears_about_pension_relief(): void
    {
        Setting::put('scottish_taxpayer', true);
        // £48,000 is under the rest-of-UK higher rate but over the Scottish one.
        $this->tx('2025-06-10', 4_800_000, 'business_income', 'turnover');

        $this->assertSame('check', $this->item(TaxSavings::review('2025-26'), 'pension-contributions')['status']);
    }

    // ---- The trading allowance (TS-6) ----

    public function test_the_trading_allowance_is_capped_at_income_and_never_offered_in_a_loss_year(): void
    {
        // A £300 loss: the allowance can't make a loss, so claiming it would only lose the £300.
        $this->tx('2025-06-10', 50_000, 'business_income', 'turnover');
        $this->tx('2025-07-10', -80_000, 'business_expense', 'software');
        $review = TaxSavings::review('2025-26');
        $this->assertNull($this->item($review, 'trading-allowance-comparison'));
        $this->assertNotNull($this->item($review, 'loss-relief'));
        $this->assertFalse(ReturnSummary::for('2025-26')['trading_allowance']['better']);

        // £900 of income and £800 of costs: the allowance is £900 here, so it adds £100, not £200.
        $this->tx('2024-06-10', 90_000, 'business_income', 'turnover');
        $this->tx('2024-07-10', -80_000, 'business_expense', 'software');
        $ta = $this->item(TaxSavings::review('2024-25'), 'trading-allowance-comparison');
        $this->assertSame(10_000, $ta['deduction_pence']);
        $this->assertStringContainsString('(£900.00, as it can’t be more than your income)', $ta['finding']);
        $this->assertTrue(ReturnSummary::for('2024-25')['trading_allowance']['better']);
    }

    // ---- Voluntary Class 2 in a loss year (TS-7, EA-5) ----

    public function test_voluntary_class_2_is_offered_in_a_loss_year(): void
    {
        $this->tx('2025-06-10', 300_000, 'business_income', 'turnover');
        $this->tx('2025-07-10', -400_000, 'business_expense', 'software');

        $review = TaxSavings::review('2025-26');
        $this->assertNotNull($this->item($review, 'loss-relief'));
        $c2 = $this->item($review, 'voluntary-class-2');
        $this->assertNotNull($c2);
        $this->assertStringStartsWith('You made a loss, so your profit is under the small profits threshold', $c2['finding']);
    }

    // ---- Loss and amend deadlines (TS-8) ----

    public function test_a_loss_after_the_sideways_deadline_can_only_be_carried_forward(): void
    {
        $this->tx('2023-06-10', 300_000, 'business_income', 'turnover');
        $this->tx('2023-07-10', -500_000, 'business_expense', 'software');
        $this->undecided('2023-08-10', -1_000);
        TaxYear::track('2023-24', 'filed');

        $review = TaxSavings::review('2023-24');
        $loss = $this->item($review, 'loss-relief');
        $this->assertSame('check', $loss['status']);
        $this->assertStringContainsString('The deadline to set it against other income or carry it back was 31 January 2026', $loss['finding']);
        $this->assertStringContainsString('only be carried forward', $loss['finding']);
        $this->assertStringNotContainsString('this year or last year', $loss['finding']);

        $missed = $this->item($review, 'missed-claims-deadlines');
        $this->assertSame('action', $missed['status']);
        $this->assertStringContainsString('The deadline to amend the return was 31 January 2026', $missed['finding']);
        $this->assertStringContainsString('overpaid tax (overpayment relief) until 5 April 2028', $missed['finding']);
        $this->assertStringNotContainsString('you can amend', $missed['finding']);
    }

    public function test_a_loss_inside_the_deadline_gives_the_claim_date(): void
    {
        $this->tx('2024-06-10', 300_000, 'business_income', 'turnover');
        $this->tx('2024-07-10', -500_000, 'business_expense', 'software');
        $this->undecided('2024-08-10', -1_000);

        $review = TaxSavings::review('2024-25');
        $loss = $this->item($review, 'loss-relief');
        $this->assertSame('action', $loss['status']);
        $this->assertStringContainsString('this year or last year (claim by 31 January 2027)', $loss['finding']);
        $this->assertStringNotContainsString('cash basis', $loss['finding']);
        // "Worth asking an accountant" marks the item; an item that doesn't say so has no needs_accountant at all.
        $this->assertTrue($loss['needs_accountant']);
        $this->assertArrayNotHasKey('needs_accountant', $this->item($review, 'missed-claims-deadlines'));
        // 2024-25 isn't filed yet (no record of it): the payments go in the return, which can be amended after.
        $this->assertSame('1 payment in 2024-25 is still undecided. Any that are business costs reduce your tax: include them in the return when you file it. After filing you can amend it until 31 January 2027.',
            $this->item($review, 'missed-claims-deadlines')['finding']);

        TaxYear::track('2024-25', 'filed');
        $this->assertStringContainsString('left out of the return reduce your tax: you can amend it until 31 January 2027, and claim back overpaid tax until 5 April 2029',
            $this->item(TaxSavings::review('2024-25'), 'missed-claims-deadlines')['finding']);
    }

    public function test_a_cash_basis_loss_before_2024_25_says_it_could_only_be_carried_forward(): void
    {
        $this->at('2025-01-15');
        $this->tx('2023-06-10', 300_000, 'business_income', 'turnover');
        $this->tx('2023-07-10', -500_000, 'business_expense', 'software');

        $loss = $this->item(TaxSavings::review('2023-24'), 'loss-relief');
        $this->assertSame('action', $loss['status']);
        $this->assertStringContainsString('claim by 31 January 2026', $loss['finding']);
        $this->assertStringContainsString('Before 2024-25, a loss worked out on the cash basis', $loss['finding']);
    }

    public function test_once_both_deadlines_have_passed_undecided_payments_are_only_information(): void
    {
        $this->tx('2021-06-10', 300_000, 'business_income', 'turnover');
        $this->undecided('2021-08-10', -1_000);
        TaxYear::track('2021-22', 'filed');

        $missed = $this->item(TaxSavings::review('2021-22'), 'missed-claims-deadlines');
        $this->assertSame('info', $missed['status']);
        $this->assertStringContainsString('(31 January 2024) and to claim back overpaid tax (5 April 2026) have passed', $missed['finding']);
    }

    public function test_a_return_not_yet_filed_takes_the_undecided_payments_whatever_the_date(): void
    {
        // Neither year has a return in (no record, or one that isn't filed): it's late, not closed.
        $this->tx('2023-06-10', 2_000_000, 'business_income', 'turnover');
        $this->undecided('2023-08-10', -1_000);
        $this->undecided('2023-09-10', -2_000);
        $this->tx('2021-06-10', 300_000, 'business_income', 'turnover');
        $this->undecided('2021-08-10', -1_000);
        TaxYear::track('2021-22', 'ready_to_file');

        $review = TaxSavings::review('2023-24');
        $missed = $this->item($review, 'missed-claims-deadlines');
        $this->assertSame('action', $missed['status']);
        $this->assertSame('2 payments in 2023-24 are still undecided. Any that are business costs reduce your tax: include them in the return when you file it.', $missed['finding']);
        $this->assertStringNotContainsString('amend', $missed['finding']);
        $this->assertStringNotContainsString('overpaid', $missed['finding']);
        // The same review says the return is late, so the two agree.
        $this->assertStringContainsString('File the return', $this->item($review, 'penalties-and-interest')['finding']);

        // 2021-22 is past the time limit for filing your own return (5 April 2026, TMA s34A): neither part says to file it.
        $review = TaxSavings::review('2021-22');
        $missed = $this->item($review, 'missed-claims-deadlines');
        $this->assertSame('info', $missed['status']);
        $this->assertSame('1 payment in 2021-22 is still undecided. Any that are business costs would have reduced your tax, but the time limit for filing your own return for 2021-22 ended on 5 April 2026. Get advice from an accountant before you contact HMRC.', $missed['finding']);
        $fines = $this->item($review, 'penalties-and-interest')['finding'];
        $this->assertStringContainsString('ended on 5 April 2026, so it can no longer be filed in the usual way', $fines);
        $this->assertStringNotContainsString('File the return', $fines);
    }

    // ---- The headline (TS-9, EA-1) ----

    public function test_the_headline_leaves_out_fines_and_counts_the_trading_allowance_once(): void
    {
        $this->tx('2025-06-10', 4_200_000, 'business_income', 'turnover');
        $this->tx('2025-07-10', -60_000, 'business_expense', 'software');

        $review = TaxSavings::review('2025-26');
        $this->assertSame('26', $review['marginal_pct']);
        $ta = $this->item($review, 'trading-allowance-comparison');
        $home = $this->item($review, 'use-of-home');
        $this->assertSame(10_400, $ta['saving_pence']);
        $this->assertSame(8_112, $home['saving_pence']);

        // The return isn't due until 31 January 2027: the fines are what happens if nothing is filed, not a saving.
        $fines = $this->item($review, 'penalties-and-interest');
        $this->assertNotNull($fines);
        $this->assertNull($fines['saving_pence']);
        $this->assertSame(0, $review['penalties_to_avoid_pence']);

        // The trading allowance replaces actual expenses, so it's the allowance or the home claim, whichever is more.
        $this->assertSame(10_400, $review['total_saving_pence']);
    }

    public function test_the_headline_adds_pension_relief_to_the_larger_of_the_allowance_and_new_claims(): void
    {
        $this->tx('2025-06-10', 7_000_000, 'business_income', 'turnover');
        $this->tx('2025-07-10', -95_000, 'business_expense', 'software');

        $review = TaxSavings::review('2025-26');
        $pension = $this->item($review, 'pension-contributions')['saving_pence'];
        $home = $this->item($review, 'use-of-home')['saving_pence'];
        $ta = $this->item($review, 'trading-allowance-comparison')['saving_pence'];
        $this->assertGreaterThan($ta, $home);
        $this->assertSame($pension + $home, $review['total_saving_pence']);
    }

    public function test_fines_already_due_are_returned_on_their_own(): void
    {
        $this->tx('2023-06-10', 2_000_000, 'business_income', 'turnover');

        $review = TaxSavings::review('2023-24');
        $due = PenaltyEstimator::forYear('2023-24');
        $this->assertGreaterThan(0, $due['penalties_pence']);
        $this->assertSame($due['penalties_pence'] + $due['interest_pence'], $review['penalties_to_avoid_pence']);
        $this->assertNull($this->item($review, 'penalties-and-interest')['saving_pence']);
        // The headline is only the trading allowance (no costs claimed at all), not the fines.
        $this->assertSame($this->item($review, 'trading-allowance-comparison')['saving_pence'], $review['total_saving_pence']);
    }

    // ---- What counts as claimed (TS-10) ----

    public function test_unconfirmed_suggestions_hide_nothing_and_cash_fares_count_as_travel(): void
    {
        $this->tx('2025-06-10', 500_000, 'business_income', 'turnover');
        $this->tx('2025-07-01', -3_000, 'business_expense', 'phone_internet', 'matcher', false);
        $this->tx('2025-07-02', -2_600, 'business_expense', 'use_of_home', 'ai', false);
        WorkJob::query()->create(['title' => 'Arena load-in', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-04']);

        $review = TaxSavings::review('2025-26');
        // Suggestions aren't in the return until confirmed, so nothing is claimed yet.
        $this->assertNotNull($this->item($review, 'phone-broadband'));
        $this->assertNotNull($this->item($review, 'use-of-home'));
        $this->assertStringStartsWith('4 of your 4 job days', $this->item($review, 'itinerant-travel')['finding']);

        // Taxis paid in cash on each job day are travel costs, so no day is left without one.
        foreach (['2025-08-01', '2025-08-02', '2025-08-03', '2025-08-04'] as $day) {
            $this->cash($day, -1_500, 'travel_taxi');
        }
        $this->assertNull($this->item(TaxSavings::review('2025-26'), 'itinerant-travel'));
    }

    // ---- Sources (F8) ----

    public function test_sources_keep_the_note_when_a_link_opens_another_page(): void
    {
        $review = TaxSavings::review('2025-26');
        $status = collect([...$review['items'], ...$review['more']])->firstWhere('id', 'employment-status-evidence');
        $lorimer = collect($status['sources'])->firstWhere('title', 'Hall (HM Inspector of Taxes) v Lorimer');
        $this->assertNotNull($lorimer);
        $this->assertStringContainsString('Atholl House', $lorimer['source_note']);
        // Sources without a note don't carry an empty one.
        foreach ($status['sources'] as $s) {
            $this->assertTrue(! array_key_exists('source_note', $s) || $s['source_note'] !== '');
        }
    }

    // ---- The year page ----

    public function test_the_year_page_carries_the_flag_and_the_server_total(): void
    {
        $this->actingAs(User::factory()->create());
        $this->tx('2026-06-10', 2_000_000, 'business_income', 'turnover');

        $this->get('/years/2026-27')->assertOk()->assertInertia(fn ($p) => $p
            ->where('savings.in_progress', true)
            ->where('savings.total_saving_pence', fn ($v) => is_int($v))
            ->where('savings.penalties_to_avoid_pence', 0)
            ->etc());
    }
}
