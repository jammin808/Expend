<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\CostNoReceipt;
use App\Domain\Intel\Detectors\DupeCrossSource;
use App\Domain\Intel\Detectors\IncomeUnlinked;
use App\Domain\Intel\Detectors\RuleDrift;
use App\Domain\Intel\FindingDraft;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Travel\TravelMatcher;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What the detectors must not call a problem: a rule-made payment put on a job (or a stay settled on the Travel page)
 * isn't overriding the rule; money paid back to a client isn't income; two different car parks or service stations
 * aren't one cost claimed twice; a supplier's invoice read like a receipt is the receipt.
 */
class DetectorRefinementsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-12-01 10:00:00');
        CarbonImmutable::setTestNow('2026-12-01 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tx(string $on, int $pence, string $name): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    private function category(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    /** @return array<string, FindingDraft> */
    private function found(object $detector): array
    {
        return collect($detector->detect(new CycleContext))->keyBy('key')->all();
    }

    private function job(string $title, string $on): WorkJob
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);

        return WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => 'done']);
    }

    public function test_putting_rule_made_payments_on_jobs_is_not_changing_the_rule(): void
    {
        $fuel = $this->category('travel_fuel');
        $rule = MerchantRule::query()->forceCreate(['merchant_key' => 'SHELL', 'kind' => 'business_expense', 'category_id' => $fuel, 'business_pct' => 100, 'auto_confirm' => true]);
        $writer = app(AllocationWriter::class);
        foreach (['2026-10-01', '2026-10-20'] as $i => $on) {
            $t = $this->tx($on, -6000, 'SHELL');
            $writer->decide($t, AllocationWriter::lines(-6000, 'business_expense', $fuel), 'rule', true, 'Your rule for SHELL', $rule->id);
            // The job page's "add to the job's costs" (JobGatherer): the rule's own lines, with the job added, as you.
            $writer->decide($t->refresh(), AllocationWriter::lines(-6000, 'business_expense', $fuel, $this->job('Show '.$i, $on)->id), 'you', true, 'Linked to Show '.$i);
        }
        $this->assertSame([], $this->found(new RuleDrift));

        // Changing what it decided still counts.
        foreach (['2026-11-01', '2026-11-08'] as $on) {
            $t = $this->tx($on, -6000, 'SHELL');
            $writer->decide($t, AllocationWriter::lines(-6000, 'business_expense', $fuel), 'rule', true, 'Your rule for SHELL', $rule->id);
            $writer->decide($t->refresh(), AllocationWriter::lines(-6000, 'personal'));
        }
        $this->assertArrayHasKey('rule_drift:'.$rule->id, $this->found(new RuleDrift));
    }

    public function test_settling_stays_on_the_travel_page_is_not_changing_the_rule(): void
    {
        $hotel = $this->category('travel_accommodation');
        $rule = MerchantRule::query()->forceCreate(['merchant_key' => 'PREMIER INN', 'kind' => 'business_expense', 'category_id' => $hotel, 'business_pct' => 100, 'auto_confirm' => true]);
        $writer = app(AllocationWriter::class);
        foreach (['2026-10-01', '2026-10-20'] as $i => $on) {
            $t = $this->tx($on, -8900, 'PREMIER INN');
            $writer->decide($t, AllocationWriter::lines(-8900, 'business_expense', $hotel), 'rule', true, 'Your rule for PREMIER INN', $rule->id);
            $job = $this->job('Show '.$i, $on);
            $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'place' => 'Premier Inn', 'status' => 'booked', 'starts_on' => $on,
                'ends_on' => CarbonImmutable::parse($on)->addDay()->format('Y-m-d'), 'amount_minor' => 8900, 'currency' => 'GBP', 'source' => 'manual', 'evidence' => [],
                'work_job_id' => $job->id, 'bank_transaction_id' => $t->id]);
            app(TravelMatcher::class)->decide($stay, 'business', $job->id, null, null);
        }
        $this->assertSame([], $this->found(new RuleDrift));
    }

    public function test_money_paid_back_to_a_client_is_not_unlinked_income(): void
    {
        $refund = $this->tx('2026-10-10', -5000, 'ACME EVENTS REFUND');
        app(AllocationWriter::class)->decide($refund, AllocationWriter::lines(-5000, 'business_income', $this->category('turnover')));
        $income = $this->tx('2026-10-11', 30000, 'ACME EVENTS LTD');
        app(AllocationWriter::class)->decide($income, AllocationWriter::lines(30000, 'business_income', $this->category('turnover')));

        $this->assertSame(['unlinked:'.$income->id], array_keys($this->found(new IncomeUnlinked)));
    }

    public function test_income_for_a_client_is_assigned_and_only_a_splits_loose_part_is_flagged(): void
    {
        $turnover = $this->category('turnover');
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $old = Client::query()->create(['name' => 'Acme Events Ltd']);
        $old->forceFill(['merged_into_id' => $acme->id])->save();
        $job = WorkJob::query()->create(['title' => 'Gala', 'client_id' => $acme->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01', 'status' => 'done']);
        $writer = app(AllocationWriter::class);
        // For a client with no job (owner answer: done once the client is set), and for a client merged into another.
        $writer->decide($this->tx('2026-10-12', 30000, 'ACME EVENTS LTD'), [['kind' => 'business_income', 'category_id' => $turnover, 'client_id' => $acme->id, 'amount_pence' => 30000]]);
        $writer->decide($this->tx('2026-10-13', 20000, 'ACME EVENTS LTD'), [['kind' => 'business_income', 'category_id' => $turnover, 'client_id' => $old->id, 'amount_pence' => 20000]]);
        // A refund paid back to a client, with the client set: not income either way.
        $writer->decide($this->tx('2026-10-14', -5000, 'ACME EVENTS REFUND'), [['kind' => 'business_income', 'category_id' => $turnover, 'client_id' => $acme->id, 'amount_pence' => -5000]]);
        // Part for the job, part assigned to nothing: flagged for the loose part only.
        $split = $this->tx('2026-10-15', 100000, 'STAGE CO');
        $writer->decide($split, [
            ['kind' => 'business_income', 'category_id' => $turnover, 'work_job_id' => $job->id, 'amount_pence' => 70000],
            ['kind' => 'business_income', 'category_id' => $turnover, 'amount_pence' => 30000],
        ]);

        $found = $this->found(new IncomeUnlinked);
        $this->assertSame(['unlinked:'.$split->id], array_keys($found));
        $this->assertSame([30000, 'Income with no client, job or invoice: £300.00 of £1,000.00 from STAGE CO on 15 Oct 2026'], [$found['unlinked:'.$split->id]->amountPence, $found['unlinked:'.$split->id]->title]);
        $this->assertStringStartsWith('Part of this payment is assigned, but £300.00 of it isn’t', $found['unlinked:'.$split->id]->detail);
    }

    public function test_two_car_parks_or_service_stations_are_not_one_cost_claimed_twice(): void
    {
        $writer = app(AllocationWriter::class);
        $cash = function (string $what, int $pence, string $category) use ($writer) {
            $e = ManualExpense::query()->create(['spent_on' => '2026-10-10', 'tax_year' => '2026-27', 'description' => $what, 'merchant_key' => strtoupper($what), 'paid_with' => 'cash',
                'amount_pence' => $pence]);
            $writer->decide($e, AllocationWriter::lines($pence, 'business_expense', $this->category($category)));

            return $e;
        };
        $card = function (string $name, int $pence, string $category) use ($writer) {
            $t = $this->tx('2026-10-10', $pence, $name);
            $writer->decide($t, AllocationWriter::lines($pence, 'business_expense', $this->category($category)));
        };
        $services = $cash('Welcome Break services', -850, 'travel_subsistence');
        $card('MOTO SERVICES', -850, 'travel_subsistence');
        $parking = $cash('Parking', -600, 'travel_parking');
        $card('NCP PARKING', -600, 'travel_parking');
        $screwfix = $cash('Screwfix Birmingham', -4599, 'materials');
        $card('SCREWFIX DIRECT', -4599, 'materials');

        $found = $this->found(new DupeCrossSource);
        $this->assertArrayNotHasKey('xdupe:'.$services->id, $found, 'Welcome Break and Moto are two merchants');
        $this->assertSame('likely', $found['xdupe:'.$screwfix->id]->grade, 'Named the same');
        // "Parking" says only what kind of cost it was: something to check, no more.
        $this->assertSame('possible', $found['xdupe:'.$parking->id]->grade);
        $this->assertStringContainsString('yours doesn’t say where it was', $found['xdupe:'.$parking->id]->detail);
        $this->assertSame([null, 'likely', 'possible', null], [
            DupeCrossSource::merchantMatch('WELCOME BREAK SERVICES', 'MOTO SERVICES'), DupeCrossSource::merchantMatch('NCP', 'NCP PARKING'),
            DupeCrossSource::merchantMatch('PARKING', 'NCP PARKING'), DupeCrossSource::merchantMatch('PARKING', 'SHELL'),
        ]);
    }

    public function test_a_suppliers_invoice_read_like_a_receipt_is_the_receipt(): void
    {
        $t = $this->tx('2026-10-10', -24900, 'THOMANN');
        app(AllocationWriter::class)->decide($t, AllocationWriter::lines(-24900, 'business_expense', $this->category('equipment')));
        $this->assertArrayHasKey('noreceipt:'.$t->id, $this->found(new CostNoReceipt));

        Document::query()->create(['source' => 'email', 'original_name' => 'Invoice-123.pdf', 'sha256' => hash('sha256', 'thomann'), 'stored_path' => 'x', 'kind' => 'invoice',
            'status' => 'extracted', 'received_at' => '2026-10-10 12:00:00', 'email_subject' => 'Your invoice',
            'extraction' => ['receipt' => ['merchant' => 'Thomann', 'date' => '2026-10-10', 'amount_minor' => 24900, 'currency' => 'GBP']]]);
        $this->assertArrayNotHasKey('noreceipt:'.$t->id, $this->found(new CostNoReceipt));
    }
}
