<?php

namespace Tests\Feature;

use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\FilingDates;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\PenaltyRegime;
use App\Domain\Tax\Submissions;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Tax\TaxSavings;
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
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the independent checks found after the penalty stages, each as the settled rules have it: when HMRC can charge
 * a late payment penalty, a determination's window as the last day for your own return, telling HMRC in time with no
 * notice yet, case A for a late notice, the law's Sch 26 rates beside the announced ones, an exemption from Making Tax
 * Digital by year, HMRC's interest start under s59B(3), penalties that fall on one day, unknown tax, the to-do list and
 * the assistant's view of all that, and how much the assistant is sent.
 */
class PenaltyReviewTest extends TestCase
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

    /** A payment nobody has decided on yet. */
    private function undecided(string $date, int $pence): void
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => 'CARD PAYMENT '.$this->n, 'merchant_key' => 'CARD'.$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1, 'review_status' => 'unreviewed']);
    }

    private function sent(string $label, int $quarter, ?string $on): void
    {
        Submissions::sync($label);
        TaxSubmission::query()->where('tax_year', $label)->where('kind', 'quarterly')->orderBy('period_start')->get()[$quarter - 1]->update(['submitted_on' => $on]);
    }

    private function liability(string $label, int $profit): int
    {
        $e = TaxEstimator::estimate($label, $profit);

        return $e['income_tax']['total_pence'] + $e['class4_pence'] + $e['class2_pence'];
    }

    private function item(array $year, string $code, string $list = 'penalties'): ?array
    {
        return collect($year[$list])->firstWhere('code', $code);
    }

    private function codes(array $year, string $list = 'penalties'): array
    {
        return collect($year[$list])->mapWithKeys(fn ($p) => [$p['code'] => [$p['date'], $p['amount_pence']]])->all();
    }

    private function kept(): array
    {
        return collect(TodoList::build()['items'])->keyBy('key')->all();
    }

    // ---- When HMRC can charge a late payment penalty (and so when interest on it starts) ----

    public function test_late_payment_penalties_on_an_unfiled_return_wait_for_it_and_carry_no_interest(): void
    {
        // 2023-24 unfiled, notice assumed, no determination: there's no figure for the tax, so HMRC can't charge them yet.
        $this->tx('2023-09-10', 4_000_000, 'business_income', 'turnover');
        TaxYear::track('2023-24');
        $y = PenaltyEstimator::forYear('2023-24');
        foreach (['sch56_30d', 'sch56_6m', 'sch56_12m'] as $code) {
            $p = $this->item($y, $code);
            $this->assertNotNull($p, $code);
            $this->assertTrue($p['awaits_return'], $code);
            $this->assertNull($p['charged_on']);
            $this->assertTrue($p['estimate'], 'it rests on a return not yet filed');
            $this->assertStringContainsString('HMRC charge it once you file (or they make a determination), back to this date', $p['assumption']);
        }
        // Interest only on the tax (and the £100 and other late filing penalties), none on the late payment penalties.
        $labels = collect($y['penalties'])->filter(fn ($p) => str_starts_with($p['code'], 'sch56'))->pluck('label')->all();
        $this->assertSame([], collect($y['obligations'])->whereIn('label', $labels)->all());

        // With HMRC's determination of 1 October 2025, they can: from then (interest 30 days after).
        TaxYear::query()->where('label', '2023-24')->update(['determination_on' => '2025-10-01']);
        $y = PenaltyEstimator::forYear('2023-24');
        $p = $this->item($y, 'sch56_30d');
        $this->assertFalse($p['awaits_return']);
        $this->assertSame('2025-10-01', $p['charged_on']);
        $this->assertSame('2025-10-31', collect($y['obligations'])->firstWhere('label', $p['label'])['due']);
        $this->assertStringContainsString('HMRC’s determination of 1 October 2025 counts as your return', $p['assumption']);
    }

    public function test_a_late_payment_penalty_on_a_late_return_is_charged_once_it_is_filed(): void
    {
        // 2023-24 filed 16 June 2025: the 30-day penalty arose on 3 March 2025, but can't be charged before the return.
        $this->tx('2023-09-10', 4_000_000, 'business_income', 'turnover');
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-06-16', 'filed_profit_pence' => 4_000_000]);
        $this->tx('2025-04-10', -100_000, 'tax_payment');
        $this->tx('2025-09-01', -200_000, 'tax_payment');
        $this->tx('2026-03-01', -300_000, 'tax_payment');

        $y = PenaltyEstimator::forYear('2023-24');
        $p = $this->item($y, 'sch56_30d');
        $this->assertSame(['2025-03-03', '2025-06-16'], [$p['date'], $p['charged_on']]);
        $this->assertFalse($p['estimate']);
        $this->assertSame('2025-07-16', collect($y['obligations'])->firstWhere('label', $p['label'])['due'], 'interest from 30 days after it’s charged');
        // The 6-month one arose after the return was in: charged on its own date.
        $six = $this->item($y, 'sch56_6m');
        $this->assertSame($six['date'], $six['charged_on']);
    }

    public function test_with_no_notice_the_late_payment_penalties_are_estimates_resting_on_registering_and_filing(): void
    {
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'not_given']);
        $y = PenaltyEstimator::forYear('2024-25');
        foreach (['sch56_30d', 'sch56_6m'] as $code) {
            $p = $this->item($y, $code);
            $this->assertTrue($p['estimate']);
            $this->assertTrue($p['awaits_return']);
            $this->assertStringContainsString('Assumes you register and file, so the tax counts as due from 31 January 2026', $p['assumption']);
            $this->assertStringContainsString('If HMRC assess the tax themselves instead, late payment penalties run from 30 days after their assessment.', $p['assumption']);
        }
        $this->assertTrue($this->item($y, 'sch56_12m', 'upcoming')['estimate']);
        // Interest on the tax still runs from 31 January (Sch 53 para 3(3)); none on these penalties.
        $this->assertSame(['balancing'], collect($y['obligations'])->pluck('kind')->all());
    }

    public function test_the_first_new_rules_penalty_is_charged_after_day_30(): void
    {
        // A volunteer from 2025-26, 2026-27 filed on time, nothing paid: amount A arises on day 16 (15 February 2028),
        // but HMRC charge it after day 30 (CH193140), so interest on it runs from 30 days after 2 March 2028.
        Setting::put('mtd_volunteer_from', '2025-26');
        $this->tx('2026-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2026-27')->update(['status' => 'filed', 'filed_on' => '2028-01-10']);
        $this->at('2028-06-01');
        $y = PenaltyEstimator::forYear('2026-27');
        $a = $this->item($y, 'sch26_a');
        $this->assertSame(['2028-02-16', '2028-03-02'], [$a['date'], $a['charged_on']]);
        $this->assertSame('2028-04-01', collect($y['obligations'])->firstWhere('label', $a['label'])['due']);
    }

    // ---- The last day for your own return once HMRC have made a determination (s28C(5), s34A(2)(b)) ----

    public function test_a_determination_after_the_four_years_keeps_the_return_open_until_its_window_ends(): void
    {
        // 2022-23: s34A would end it on 5 April 2027, but HMRC's determination of 15 December 2026 can be replaced until
        // 14 December 2027 (12 months from it), so the return can still be filed then.
        $this->tx('2022-09-10', 3_000_000, 'business_income', 'turnover');
        $this->undecided('2022-10-10', -5_000);
        TaxYear::track('2022-23')->update(['determination_on' => '2026-12-15']);
        $this->at('2027-06-01');

        $y = PenaltyEstimator::forYear('2022-23');
        $this->assertSame(['2027-12-14', '2027-12-14', false], [$y['own_return_last_date'], $y['determination_last_date'], $y['too_late_to_file']]);
        $this->assertSame('File the return as soon as you can. You can only file it yourself until 14 December 2027, the last day it can replace HMRC’s determination.', $y['actions'][0]);
        $this->assertContains('HMRC made a determination of the tax on 15 December 2026. Your return replaces it only if it reaches them by 14 December 2027; after that the determination stands as the tax for the year.', $y['actions']);
        $this->assertEmpty(collect($y['actions'])->first(fn ($a) => str_contains($a, 'can no longer be filed')));
        $finding = collect(TaxSavings::review('2022-23')['items'])->firstWhere('id', 'missed-claims-deadlines')['finding'];
        $this->assertStringContainsString('include them in the return when you file it, by 14 December 2027 to replace HMRC’s determination', $finding);
    }

    public function test_a_determination_window_that_closed_before_the_four_years_means_it_stands(): void
    {
        // 2022-23, determination 1 June 2025: replaceable only until 30 January 2027 (3 years from 31 January 2024),
        // before the s34A date of 5 April 2027. On 15 February 2027 it stands: no "file it" advice.
        $this->tx('2022-09-10', 3_000_000, 'business_income', 'turnover');
        $this->undecided('2022-10-10', -5_000);
        TaxYear::track('2022-23')->update(['determination_on' => '2025-06-01']);
        $this->at('2027-02-15');

        $y = PenaltyEstimator::forYear('2022-23');
        $this->assertSame(['2027-01-30', true], [$y['own_return_last_date'], $y['too_late_to_file']]);
        $this->assertStringStartsWith('HMRC made a determination of the tax for 2022-23 on 1 June 2025. The time to replace it with your own return ended on 30 January 2027, so the determination stands', $y['actions'][0]);
        $this->assertStringContainsString('special relief (TMA 1970 Schedule 1AB paragraph 3A)', $y['actions'][0]);
        $this->assertEmpty(collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'File the return')));

        // The to-do list (and Telegram) use the first action, and the deadline reminder says it's too late.
        $items = $this->kept();
        $this->assertStringContainsString('so the determination stands', $items['penalty:2022-23']['detail']);
        $this->assertSame('Tax return 2022-23: too late to file it yourself', $items['deadline:2022-23']['title']);
        $this->assertStringContainsString('ended on 30 January 2027, so it stands as the tax for the year', $items['deadline:2022-23']['detail']);
        $finding = collect(TaxSavings::review('2022-23')['items'])->firstWhere('id', 'missed-claims-deadlines')['finding'];
        $this->assertStringContainsString('the time to replace HMRC’s determination for 2022-23 with your own return ended on 30 January 2027, so the determination stands', $finding);
    }

    public function test_a_year_past_the_four_years_is_not_a_return_to_work_through(): void
    {
        // 2021-22 unfiled, no determination: s34A ended it on 5 April 2026.
        $this->tx('2021-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2021-22');
        $items = $this->kept();
        $this->assertSame('Tax return 2021-22: too late to file it yourself', $items['deadline:2021-22']['title']);
        $this->assertStringNotContainsString('Work through the year', $items['deadline:2021-22']['detail']);
        $this->assertStringContainsString('ended on 5 April 2026', $items['deadline:2021-22']['detail']);

        $returns = collect(app(AssistantTools::class)->run('deadlines', [])['tax_returns'])->keyBy('tax_year');
        $this->assertSame([true, '2026-04-05'], [$returns['2021-22']['too_late_to_file'], $returns['2021-22']['own_return_last']]);
        $this->assertStringContainsString('can no longer be filed', $returns['2021-22']['too_late_note']);
    }

    // ---- Telling HMRC by 5 October (TMA s7) and failure to notify (FA 2008 Sch 41) ----

    public function test_told_hmrc_in_time_with_no_notice_yet_means_no_failure_to_notify(): void
    {
        // 2025-26: registered by 5 October 2026, but no notice to file has come by 24 November 2026.
        $this->tx('2025-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2025-26')->update(['notice_to_file' => 'not_given', 'told_hmrc_by_5_october' => true]);
        $y = PenaltyEstimator::forYear('2025-26');
        $this->assertNull($this->item($y, 'sch41', 'upcoming'));
        $this->assertNull($this->item($y, 'sch41'));
        $this->assertFalse($y['notify_hmrc']);
        $this->assertTrue($y['told_no_notice']);
        $this->assertEmpty(collect($y['actions'])->first(fn ($a) => str_contains($a, 'register for Self Assessment')));
        $this->assertStringStartsWith('You told HMRC by 5 October 2026 that you need to file for 2025-26, so there’s no penalty for telling them late.', $y['actions'][0]);
        // The notice will come after 31 October, so the tax is due 3 months after it (s59B(3)): taken as if it came
        // today, the last day of the 3 months beginning 24 November 2026, an estimate.
        $this->assertSame('2027-02-23', $y['balancing_due']);
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_contains($a, 'taken as 23 February 2027, as if it came today')));
        $this->assertSame('2027-03-26', $this->item($y, 'sch56_30d', 'upcoming')['date']);
        $this->assertTrue($this->item($y, 'sch56_30d', 'upcoming')['estimate']);

        $items = $this->kept();
        $this->assertArrayNotHasKey('penalty:2025-26', $items, 'nothing to tell HMRC, nothing due yet');
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.no_notice', true)->where('years.0.told_hmrc', true));
    }

    public function test_a_late_notice_stays_case_a_because_hmrc_knew_by_its_date(): void
    {
        // 2024-25: notice given 10 November 2025, not said whether HMRC were told by 5 October. HMRC knew by the notice
        // date, less than 12 months after the tax first went unpaid (1 February 2026): case A for good (Sch 41 para 13(3)).
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'given', 'notice_date' => '2025-11-10']);
        $L = $this->liability('2024-25', 3_000_000);
        foreach (['2026-11-24', '2027-03-01'] as $day) {
            $this->at($day);
            $p = $this->item(PenaltyEstimator::forYear('2024-25'), 'sch41');
            $this->assertSame(['min_pence' => 0, 'max_pence' => intdiv($L * 30, 100)], $p['range'], $day);
            $this->assertStringContainsString('Taken as if you registered yourself, before HMRC contacted you', $p['why']);
            $this->assertStringNotContainsString('Tell HMRC yourself', $p['why']);
            $this->assertStringStartsWith('Assumes you registered yourself, before HMRC contacted you', $p['assumption']);
        }
    }

    public function test_the_assistant_reports_a_failure_to_notify_range_without_failing(): void
    {
        // A year with a Sch 41 range, asked about alone and with every year.
        $this->tx('2025-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2025-26')->update(['notice_to_file' => 'not_given']);
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'given', 'notice_date' => '2025-11-10']);
        $L = $this->liability('2025-26', 3_000_000);
        foreach ([['tax_year' => '2025-26'], []] as $in) {
            $answer = app(AssistantTools::class)->run('penalties', $in);
            $year = collect($answer['years'])->firstWhere('tax_year', '2025-26');
            $sch41 = collect($year['coming_if_nothing_changes'])->first(fn ($p) => isset($p['range']));
            $this->assertSame(Money::format(intdiv($L * 30, 100)), $sch41['range']['max']['formatted']);
            $this->assertSame(0, $sch41['range']['min']['pence']);
        }
        // The late notice with "told" unknown has one too.
        $late = collect(app(AssistantTools::class)->run('penalties', ['tax_year' => '2024-25'])['years'][0]['already_due'])->first(fn ($p) => isset($p['range']));
        $this->assertNotNull($late);

        // The to-do list and the savings review don't present the 0% floor as the cost.
        $item = $this->kept()['penalty:2025-26'];
        $this->assertSame('Tell HMRC you need to file for 2025-26', $item['title']);
        $this->assertArrayNotHasKey('amount', array_filter($item, fn ($v) => $v !== null && $v !== 0));
        $this->assertStringContainsString('The penalty for telling HMRC late can be up to '.Money::format(intdiv($L * 30, 100), 'GBP', false), $item['detail']);
        $fines = collect(TaxSavings::review('2025-26')['items'])->firstWhere('id', 'penalties-and-interest');
        $this->assertStringContainsString('and a penalty of up to '.Money::format(intdiv($L * 30, 100), 'GBP', false).' for telling HMRC late', $fines['finding']);
        // Beside it, what a payment plan does: the day you ask counts (SAM61380), not agreeing it before day 30.
        $this->assertStringContainsString('Asking HMRC for a payment plan by day 30, and keeping to the plan they agree, avoids all three Sch 56 penalties (asking later avoids the later ones); interest still runs.', $fines['risks']);
    }

    // ---- s59B(3): HMRC's interest start for a late notice ----

    public function test_interest_on_tax_due_under_s59b3_runs_from_hmrcs_date(): void
    {
        // Told HMRC by 5 October 2025; notice 10 November 2025. The tax is due 9 February 2026 (the law, and the date
        // late payment penalties run from); HMRC's system starts interest at the notice + 3 months + 7 days: 17 February.
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'given', 'notice_date' => '2025-11-10', 'told_hmrc_by_5_october' => true]);
        $y = PenaltyEstimator::forYear('2024-25');
        $balancing = collect($y['obligations'])->firstWhere('kind', 'balancing');
        $this->assertSame(['2026-02-09', '2026-02-17'], [$balancing['due'], $balancing['interest_from']]);
        $this->assertSame('2026-02-18', $balancing['interest_segments'][0]['from'], 'the first day of interest is the day after');
        $this->assertSame('2026-03-12', $this->item($y, 'sch56_30d')['date'], 'penalties from the statutory date: 9 February + 31 days');
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_contains($a, 'Interest is worked out from 17 February 2026, the date HMRC’s system uses') && str_contains($a, 'since the law would let HMRC charge it from 9 February 2026')));
    }

    // ---- Several late payment penalties on one day ----

    public function test_paying_in_time_avoids_every_penalty_that_falls_on_the_next_date(): void
    {
        // In Making Tax Digital from April 2026, 2026-27 is the first year under the new rules: A and B both arise on
        // day 31 (2 March 2028) because of the 30-day easement.
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2026-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20', 'filed_profit_pence' => 6_000_000]);
        $this->tx('2026-01-20', -$this->liability('2024-25', 6_000_000), 'tax_payment');
        TaxYear::track('2026-27')->update(['status' => 'filed', 'filed_on' => '2028-01-20', 'filed_profit_pence' => 3_000_000]);
        $this->at('2028-02-20');

        $y = PenaltyEstimator::forYear('2026-27');
        $L = $y['liability_pence'];
        $three = intdiv($L * 3, 100);
        $this->assertSame(['sch26_a' => ['2028-03-02', $three], 'sch26_b' => ['2028-03-02', $three]], $this->codes($y, 'upcoming'));
        $pay = collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'Pay the '));
        $this->assertStringContainsString('before 2 March 2028 to avoid '.Money::format(2 * $three).' of late payment penalties', $pay);
        $this->assertStringContainsString('(£'.number_format(2 * $three / 100, 2).')', $this->kept()['penalty:2026-27']['detail']);
    }

    public function test_extra_tax_from_an_amendment_due_with_the_rest_counts_in_the_next_penalty(): void
    {
        // 2024-25 amended on 1 December 2025: its extra tax is due on 31 January 2026 too, so both 30-day penalties
        // arise on 3 March 2026.
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'amending', 'filed_on' => '2025-10-20', 'amended_on' => '2025-12-01', 'original_liability_pence' => 200_000]);
        $this->at('2026-02-20');
        $y = PenaltyEstimator::forYear('2024-25');
        $L = $y['liability_pence'];
        $both = intdiv(200_000 * 5, 100) + intdiv(($L - 200_000) * 5, 100);
        $this->assertSame(['2026-03-03', '2026-03-03'], [$this->item($y, 'sch56_30d', 'upcoming')['date'], $this->item($y, 'sch56_inc_30d', 'upcoming')['date']]);
        $this->assertStringContainsString('before 3 March 2026 to avoid '.Money::format($both).' of late payment penalties', collect($y['actions'])->first(fn ($a) => str_starts_with($a, 'Pay the ')));
        // The normal date wins here, so that's what the amendment's note says.
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_contains($a, 'That’s due on 31 January 2026, the normal date, because that’s later than 30 days after HMRC got the amendment (1 December 2025)')));
    }

    // ---- Sch 26 rates for 2027-28 under the new rules by law ----

    public function test_a_digitally_obligated_persons_2027_28_tax_uses_the_rates_in_the_law(): void
    {
        config(['tax.years.2027-28' => config('tax.years.2026-27'), 'tax.years.2028-29' => config('tax.years.2026-27')]);
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2027-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20', 'filed_profit_pence' => 6_000_000]);
        TaxYear::track('2027-28')->update(['status' => 'filed', 'filed_on' => '2029-01-20', 'filed_profit_pence' => 3_000_000]);
        $this->at('2029-04-10');
        $y = PenaltyEstimator::forYear('2027-28');
        $L = $y['liability_pence'];
        $this->assertSame(intdiv($L * 3, 100), $this->item($y, 'sch26_a')['amount_pence'], '3% (SI 2025/589): the 4% isn’t law');
        $this->assertSame(intdiv($L * 3, 100), $this->item($y, 'sch26_b')['amount_pence']);
        $this->assertSame(intdiv($L * 4, 100), collect($y['announced']['penalties'])->firstWhere('code', 'sch26_a')['amount_pence']);
        $this->assertSame(array_sum(array_column($y['penalties'], 'amount_pence')), $y['penalties_pence']);
    }

    // ---- Exemption from Making Tax Digital, by year ----

    public function test_an_exemption_from_2027_28_keeps_a_digitally_obligated_person_on_the_new_rules(): void
    {
        $this->tx('2024-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2026-05-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2026-27');
        $this->assertSame('sch24_26_law', PenaltyRegime::for('2026-27'));

        // Exempt from 2027-28: still on Sch 24/26 for 2026-27 and later (SI 2026/370 reg 3(2)); no quarterly updates
        // from 2027-28, so the 2-point threshold then (group 1A).
        Setting::put('mtd_exempt_from', '2027-28');
        $this->assertSame(['sch24_26_law', 'sch24_26_law'], [PenaltyRegime::for('2026-27'), PenaltyRegime::for('2027-28')]);
        $this->assertSame([true, false], [PenaltyRegime::mandated('2026-27'), PenaltyRegime::mandated('2027-28')]);
        $this->assertSame('2026-27', PenaltyRegime::firstMandatedYear());
        $y = PenaltyEstimator::forYear('2026-27');
        $this->assertSame('sch24_26_law', $y['regime']);
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_contains($a, 'exempt from Making Tax Digital from 2027-28') && str_contains($a, 'still apply to you by law')));

        // Exempt from 2026-27: back to Sch 55/56 (GOV.UK).
        Setting::put('mtd_exempt_from', '2026-27');
        $this->assertSame(['sch55_56', 'sch24_26_announced'], [PenaltyRegime::for('2026-27'), PenaltyRegime::for('2027-28')]);
        $this->assertFalse(collect(app(AssistantTools::class)->run('deadlines', [])['making_tax_digital'])->firstWhere('based_on', '2024-25')['applies']);

        // From 2027-28, with HMRC treating the earlier requirements as never imposed (Sch A1 para 14(1A)/15(3)): never one.
        Setting::put('mtd_exempt_from', '2027-28');
        Setting::put('mtd_exempt_never_required', true);
        $this->assertSame(['sch55_56', false], [PenaltyRegime::for('2026-27'), PenaltyRegime::mandated('2026-27')]);
    }

    // ---- Tax that isn't worked out (Scottish income tax, or no rates) ----

    public function test_unknown_tax_is_labelled_not_shown_as_nil(): void
    {
        Setting::put('scottish_taxpayer', true);
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25');
        $y = PenaltyEstimator::forYear('2024-25');
        $this->assertSame([false, 'scottish'], [$y['estimable'], $y['unestimable']]);
        $six = $this->item($y, 'sch55_6m');
        $this->assertSame('Six months late: £300.00 now, maybe more when you file', $six['label']);
        $this->assertTrue($six['estimate']);
        $this->assertStringContainsString('Expend doesn’t work out Scottish income tax', $six['assumption']);
        $this->assertStringContainsString('not worked out here', $six['why']);
        $this->assertNotEmpty(collect($y['assumptions'])->first(fn ($a) => str_contains($a, 'That doesn’t mean it’s nil')));

        // No notice: the failure-to-notify penalty is shown, not worked out, rather than dropped.
        TaxYear::query()->where('label', '2024-25')->update(['notice_to_file' => 'not_given']);
        $y = PenaltyEstimator::forYear('2024-25');
        $sch41 = $this->item($y, 'sch41');
        $this->assertTrue($sch41['amount_unknown']);
        $this->assertSame(0, $sch41['amount_pence']);
        $this->assertStringContainsString('up to 30% of the tax unpaid on 31 January 2026 (not worked out)', $sch41['label']);
        $this->assertTrue($y['notify_hmrc']);
        $tool = app(AssistantTools::class)->run('penalties', ['tax_year' => '2024-25'])['years'][0];
        $this->assertSame('not worked out (Scottish income tax)', $tool['tax_for_year']);
        $this->assertTrue($tool['already_due'][0]['amount_not_worked_out']);
    }

    // ---- The to-do list ----

    public function test_an_estimated_deadline_says_so_in_the_to_do_list(): void
    {
        // Notice 10 November 2025: due 17 February 2026 the way HMRC's system works it out (an estimate).
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2024-25')->update(['notice_to_file' => 'given', 'notice_date' => '2025-11-10']);
        $item = $this->kept()['deadline:2024-25'];
        $this->assertSame('Tax return 2024-25: overdue since 17 February 2026 (estimated)', $item['title']);
        $this->assertStringContainsString('the date printed on HMRC’s notice is the one that counts', $item['detail']);
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.deadline', '2026-02-17')->where('years.0.deadline_basis', 'estimate'));

        // A notice only assumed: the reminder says what it assumes.
        TaxYear::query()->where('label', '2024-25')->update(['notice_to_file' => null, 'notice_date' => null]);
        $item = $this->kept()['deadline:2024-25'];
        $this->assertSame('Tax return 2024-25: overdue since 31 January 2026', $item['title']);
        $this->assertStringContainsString('Assumes HMRC sent you a notice to file by 31 July 2025', $item['detail']);
    }

    public function test_a_quarterly_update_with_no_sent_date_is_not_called_late_and_announced_points_say_so(): void
    {
        // In Making Tax Digital from April 2027 (2025-26 income over £30,000): the new penalties are only announced for
        // 2027-28. No "sent on" date for the first two updates on 20 November 2027.
        $this->tx('2025-09-10', 4_000_000, 'business_income', 'turnover');
        TaxYear::track('2027-28');
        $this->at('2027-11-20');
        $item = $this->kept()['submission:2027-28:q1'];
        $this->assertSame('Quarterly update 2027-28 (6 Apr to 5 Jul 2027): no sent date recorded (due 7 August 2027)', $item['title']);
        $this->assertStringStartsWith('If late: no point, because late quarterly updates in your first year', $item['detail']);
        $this->assertStringContainsString('(Under the announced rules, which aren’t law yet.)', $item['detail']);
        $this->assertStringContainsString('At 4 points each late submission costs £200.', $item['detail']);
    }

    public function test_a_paper_return_can_be_amended_until_12_months_after_its_filing_date(): void
    {
        // Filed on paper on 20 October 2025: the filing date is 31 October 2025, so it can be amended until 31 October
        // 2026 (TMA s9ZA). Everything says the same date; loss claims still run from 31 January.
        $this->tx('2024-09-10', 3_000_000, 'business_income', 'turnover');
        $this->undecided('2024-10-10', -5_000);
        TaxYear::track('2024-25')->update(['status' => 'amending', 'filed_on' => '2025-10-20', 'filed_method' => 'paper']);
        $this->at('2026-09-15');
        $this->assertSame('Tax return 2024-25: amend by 31 October 2026', $this->kept()['deadline:2024-25:amend']['title']);
        $returns = collect(app(AssistantTools::class)->run('deadlines', [])['tax_returns'])->keyBy('tax_year');
        $this->assertSame('2026-10-31', $returns['2024-25']['amend_by']);
        $finding = collect(TaxSavings::review('2024-25')['items'])->firstWhere('id', 'missed-claims-deadlines')['finding'];
        $this->assertStringContainsString('you can amend it until 31 October 2026', $finding);
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.deadline', '2026-10-31'));

        // 29 February + 12 months is 28 February (a notice of 22 November 2027 gives HMRC's 29 February 2028).
        TaxYear::track('2026-27')->update(['status' => 'filed', 'notice_to_file' => 'given', 'notice_date' => '2027-11-22']);
        $this->assertSame('2029-02-28', FilingDates::amendBy('2026-27')->format('Y-m-d'));
    }

    // ---- The assistant: what it's sent ----

    public function test_the_penalties_answer_for_six_years_in_making_tax_digital_fits_what_the_model_is_sent(): void
    {
        $this->tx('2022-09-10', 6_000_000, 'business_income', 'turnover');
        $this->tx('2023-09-10', 6_000_000, 'business_income', 'turnover');
        TaxYear::track('2022-23')->update(['status' => 'filed', 'filed_on' => '2024-05-01', 'plan_requested_on' => '2024-05-10', 'plan_agreed' => true, 'plan_broken_on' => '2024-09-01']);
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-03-10']);
        foreach (['2024', '2025', '2026', '2027'] as $y) {
            $this->tx($y.'-09-10', 6_000_000, 'business_income', 'turnover');
        }
        TaxYear::track('2024-25')->update(['status' => 'amending', 'filed_on' => '2026-01-10', 'original_liability_pence' => 500_000,
            'plan_requested_on' => '2026-02-10', 'plan_agreed' => true, 'plan_broken_on' => '2026-06-01']);
        TaxYear::track('2025-26')->update(['plan_requested_on' => '2027-02-10']);
        TaxYear::track('2026-27');
        foreach ([1, 2, 3, 4] as $q) {
            $this->sent('2026-27', $q, Submissions::quarters('2026-27')[$q - 1]['due_on']->format('Y-m-d'));
        }
        $this->sent('2027-28', 1, '2027-08-20');
        $this->sent('2027-28', 2, '2027-11-30');
        $this->at('2028-02-20');

        $answer = app(AssistantTools::class)->run('penalties', []);
        $json = json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertLessThan(Assistant::TOOL_RESULT_MAX, mb_strlen($json));
        $this->assertSame($json, Assistant::fit($answer), 'sent whole');
        $this->assertNotEmpty($answer['assumptions_for_every_year'], 'shared assumptions given once');
        $this->assertNotNull($answer['late_submission_points']['law']);
    }

    public function test_an_answer_too_long_to_send_loses_whole_years_from_the_oldest_end(): void
    {
        $output = ['as_of' => '2026-11-24', 'years' => array_map(fn ($i) => ['tax_year' => (2020 + $i).'-'.(21 + $i), 'detail' => str_repeat('x', 400)], range(0, 5)), 'help' => ['keep']];
        $fitted = Assistant::fit($output, null, 1_500);
        $this->assertLessThanOrEqual(1_500, mb_strlen($fitted));
        $back = json_decode($fitted, true);
        $this->assertIsArray($back, 'still valid JSON');
        $this->assertSame('2025-26', end($back['years'])['tax_year'], 'the newest year kept');
        $this->assertNotSame('2020-21', $back['years'][0]['tax_year']);
        $this->assertStringContainsString('(the oldest)', $back['truncated']);
        $this->assertSame(['keep'], $back['help']);
        // Nothing to drop whole: still valid JSON that says it was cut.
        $one = json_decode(Assistant::fit(['text' => str_repeat('y', 5_000)], null, 1_000), true);
        $this->assertArrayHasKey('truncated', $one);
    }

    // ---- How often the settings are read ----

    public function test_the_estimate_reads_each_setting_once(): void
    {
        foreach (['2022', '2023', '2024', '2025', '2026'] as $y) {
            $this->tx($y.'-09-10', 3_000_000, 'business_income', 'turnover');
        }
        TaxYear::track('2022-23')->update(['status' => 'filed', 'filed_on' => '2024-01-10']);
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-10']);
        TaxYear::track('2024-25');
        DB::flushQueryLog();
        DB::enableQueryLog();
        PenaltyEstimator::all();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $settings = preg_grep('/from [`"]settings[`"]/i', $log);
        $this->assertLessThanOrEqual(8, count($settings), 'each setting read once, not once per year');

        // The year page runs it more than once (penalties, the savings review): a fixed number of queries.
        $this->actingAs(User::factory()->create());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/years/2024-25')->assertOk();
        $page = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(320, $page);
    }
}
