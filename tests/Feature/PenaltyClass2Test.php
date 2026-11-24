<?php

namespace Tests\Feature;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Tax\TaxEstimator;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Compulsory Class 2 National Insurance, which is part of the balancing payment (so of the late filing and late
 * payment penalties and the interest) for 2022-23 and 2023-24: payable only on profits over the lower profits
 * threshold (£11,908, £12,570), treated as paid from £6,725 up to it, for each week of self-employment (SSCBA 1992
 * s11). 2021-22 was payable on profits of, or over, the small profits threshold.
 */
class PenaltyClass2Test extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

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

    private function tx(string $date, int $pence, string $kind, ?string $code = null): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $kind.' '.$this->n, 'merchant_key' => strtoupper($kind).$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $code ? Category::query()->where('code', $code)->value('id') : null));

        return $tx;
    }

    public function test_class_2_is_payable_only_on_profits_over_the_lower_profits_threshold(): void
    {
        // Exactly at the threshold: treated as paid, nothing to pay.
        $at = TaxEstimator::estimate('2022-23', 1_190_800);
        $this->assertSame(0, $at['class2_pence']);
        $this->assertNull($at['class2_weeks']);
        $this->assertNotEmpty(array_filter($at['notes'], fn ($n) => str_contains($n, 'No Class 2 to pay') && str_contains($n, '£11,908.00') && str_contains($n, '£6,725.00')));
        // A penny over: 52 weeks at £3.15 = £163.80.
        $this->assertSame(16_380, TaxEstimator::estimate('2022-23', 1_190_801)['class2_pence']);

        $this->assertSame(0, TaxEstimator::estimate('2023-24', 1_257_000)['class2_pence']);
        $this->assertSame(17_940, TaxEstimator::estimate('2023-24', 1_257_001)['class2_pence'], '52 weeks at £3.45');

        // Below the small profits threshold: nothing payable and no "treated as paid" note.
        $low = TaxEstimator::estimate('2023-24', 500_000);
        $this->assertSame(0, $low['class2_pence']);
        $this->assertSame([], array_values(array_filter($low['notes'], fn ($n) => str_contains($n, 'Class 2'))));

        // 2021-22: payable on profits of, or over, the small profits threshold (£6,515).
        $this->assertSame(305 * 52, TaxEstimator::estimate('2021-22', 651_500)['class2_pence']);
        $this->assertSame(0, TaxEstimator::estimate('2021-22', 651_499)['class2_pence']);

        // None compulsory from 2024-25.
        $this->assertSame(0, TaxEstimator::estimate('2024-25', 3_000_000)['class2_pence']);
    }

    public function test_weeks_of_self_employment_come_from_settings_or_a_full_year(): void
    {
        $full = TaxEstimator::estimate('2022-23', 3_000_000);
        $this->assertSame([16_380, 52, false], [$full['class2_pence'], $full['class2_weeks'], $full['class2_weeks_set']]);
        $this->assertNotEmpty(array_filter($full['notes'], fn ($n) => str_contains($n, '52 weeks (a full year) is assumed') && str_contains($n, 'aged 16 to State Pension age')));

        // Self-employed for 30 weeks of 2022-23; 2023-24 not set, so a full year.
        Setting::put('class2_weeks', ['2022-23' => 30]);
        $part = TaxEstimator::estimate('2022-23', 3_000_000);
        $this->assertSame([315 * 30, 30, true], [$part['class2_pence'], $part['class2_weeks'], $part['class2_weeks_set']]);
        $this->assertNotEmpty(array_filter($part['notes'], fn ($n) => str_contains($n, '30 weeks, as set in Settings')));
        $this->assertSame(17_940, TaxEstimator::estimate('2023-24', 3_000_000)['class2_pence']);
        $this->assertSame($part['income_tax']['total_pence'] + $part['class4_pence'] + 315 * 30, $part['total_pence']);

        // No weeks at all (not self-employed that year): nothing.
        Setting::put('class2_weeks', ['2023-24' => 0]);
        $this->assertSame(0, TaxEstimator::estimate('2023-24', 3_000_000)['class2_pence']);

        // Nonsense in the setting is ignored (a full year).
        Setting::put('class2_weeks', ['2023-24' => 80]);
        $this->assertSame(['weeks' => 52, 'set' => false], TaxEstimator::class2Weeks('2023-24'));
    }

    public function test_the_penalties_and_interest_use_the_same_class_2(): void
    {
        // 2022-23 profit exactly at the threshold, filed on time, unpaid: the tax penalised has no Class 2 in it.
        $this->tx('2022-09-10', 1_190_800, 'business_income', 'turnover');
        TaxYear::track('2022-23')->update(['status' => 'filed', 'filed_on' => '2024-01-20']);
        $e = TaxEstimator::estimate('2022-23', 1_190_800);
        $y = PenaltyEstimator::forYear('2022-23');
        $this->assertSame($e['income_tax']['total_pence'] + $e['class4_pence'], $y['liability_pence']);
        $this->assertSame([], array_values(array_filter($y['assumptions'], fn ($a) => str_contains($a, 'Class 2'))));
    }

    public function test_the_penalties_say_what_class_2_rests_on(): void
    {
        $this->tx('2023-09-10', 3_000_000, 'business_income', 'turnover');
        TaxYear::track('2023-24')->update(['status' => 'filed', 'filed_on' => '2025-01-20']);
        Setting::put('class2_weeks', ['2023-24' => 40]);
        $e = TaxEstimator::estimate('2023-24', 3_000_000);

        $y = PenaltyEstimator::forYear('2023-24');
        $this->assertSame($e['income_tax']['total_pence'] + $e['class4_pence'] + 345 * 40, $y['liability_pence']);
        $note = array_values(array_filter($y['assumptions'], fn ($a) => str_contains($a, 'Class 2')))[0] ?? '';
        $this->assertStringContainsString('£138.00 of Class 2 National Insurance for 40 weeks, as set in Settings.', $note);
        $this->assertStringContainsString('repealed from 6 April 2024', $note);
        // The late payment penalties are on the tax including that Class 2.
        $this->assertSame(intdiv($y['liability_pence'] * 5, 100), collect($y['penalties'])->firstWhere('code', 'sch56_30d')['amount_pence']);
    }

    public function test_settings_save_the_weeks_for_each_class_2_year(): void
    {
        $this->actingAs(User::factory()->create());
        $base = ['home_postcode' => '', 'overnight_near_miles' => 12, 'overnight_far_miles' => 40, 'early_start' => '08:00', 'late_finish' => '22:00',
            'mileage_threshold_scope' => 'all_vehicles', 'scottish_taxpayer' => false, 'own_names' => '', 'own_other_accounts' => ''];

        $this->get('/settings/ledger')->assertInertia(fn ($page) => $page->where('class2_years', ['2021-22', '2022-23', '2023-24'])->where('settings.class2_weeks', []));

        // An empty box means a full year; years without compulsory Class 2 are dropped.
        $this->put('/settings/ledger', $base + ['class2_weeks' => ['2022-23' => '30', '2023-24' => '', '2024-25' => '10']])->assertSessionHas('success');
        $this->assertSame(['2022-23' => 30], Setting::get('class2_weeks'));

        $this->put('/settings/ledger', $base + ['class2_weeks' => ['2022-23' => '60']])->assertSessionHasErrors('class2_weeks.2022-23');
        $this->put('/settings/ledger', $base + ['class2_weeks' => ['2022-23' => '-1']])->assertSessionHasErrors('class2_weeks.2022-23');
        $this->assertSame(['2022-23' => 30], Setting::get('class2_weeks'), 'unchanged by a bad entry');

        // A save that doesn't send the weeks leaves them alone; clearing the box goes back to a full year.
        $this->put('/settings/ledger', $base)->assertSessionHas('success');
        $this->assertSame(['2022-23' => 30], Setting::get('class2_weeks'));
        $this->put('/settings/ledger', $base + ['class2_weeks' => ['2022-23' => '']])->assertSessionHas('success');
        $this->assertSame([], Setting::get('class2_weeks'));
    }
}
