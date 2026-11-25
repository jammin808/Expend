<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\CostNoReceipt;
use App\Domain\Intel\Detectors\DupeCrossSource;
use App\Domain\Intel\Detectors\IncomeUnlinked;
use App\Domain\Intel\Detectors\ReceiptUnmatched;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * receipt.unmatched, cost.no_receipt (both by Intel\ReceiptMatch, the transaction page's own rule), income.unlinked and
 * dupe.cross_source.
 */
class ReceiptsAndLinksTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-07-01 10:00:00');
        CarbonImmutable::setTestNow('2026-07-01 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tx(string $on, int $pence, string $name, array $extra = []): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n),
            'occurrence' => 1] + $extra);
    }

    private function receipt(int $amount, ?string $on, string $received, string $merchant = 'Screwfix', string $currency = 'GBP', ?int $parent = null): Document
    {
        return Document::query()->create(['source' => 'email', 'original_name' => 'receipt.pdf', 'sha256' => hash('sha256', 'r'.++$this->n), 'stored_path' => 'x', 'kind' => 'receipt',
            'status' => 'extracted', 'received_at' => $received.' 09:00:00', 'parent_id' => $parent, 'text' => 'SECRET RECEIPT TEXT',
            'extraction' => ['receipt' => ['merchant' => $merchant, 'date' => $on, 'amount_minor' => $amount, 'currency' => $currency, 'quote' => 'Total']]]);
    }

    private function decide(BankTransaction|ManualExpense $t, string $kind, ?string $category = null, array $extra = []): void
    {
        app(AllocationWriter::class)->decide($t, [['kind' => $kind, 'category_id' => $category ? Category::query()->where('code', $category)->value('id') : null,
            'amount_pence' => $t->amount_pence] + $extra]);
    }

    /** @return array<string, FindingDraft> */
    private function found(object $detector): array
    {
        return collect($detector->detect(new CycleContext))->keyBy('key')->all();
    }

    public function test_receipts_with_no_payment_and_costs_with_no_receipt_agree_with_the_transaction_page(): void
    {
        $this->tx('2026-04-10', -1000, 'First line');
        $this->tx('2026-06-30', -1000, 'Last line');
        // Matched by amount and the date on it (3 days apart), though forwarded weeks later.
        $paid = $this->tx('2026-05-12', -23450, 'SCREWFIX');
        $this->decide($paid, 'business_expense', 'materials');
        $matched = $this->receipt(23450, '2026-05-09', '2026-06-20');
        // Paid with a card that isn't imported: no payment.
        $lonely = $this->receipt(8999, '2026-05-15', '2026-05-15', 'Thomann');
        // An email and its attachment, both read as the same receipt: one receipt.
        $email = $this->receipt(4100, '2026-05-20', '2026-05-20', 'Argos');
        $this->receipt(4100, '2026-05-20', '2026-05-20', 'Argos', parent: $email->id);
        // From before your statements begin: can't be matched.
        $this->receipt(5000, '2026-03-01', '2026-03-01');
        // A cost over £100 with no receipt; one under it; one a receipt goes with; one a bank charge.
        $big = $this->tx('2026-05-20', -15000, 'THOMANN');
        $this->decide($big, 'business_expense', 'equipment');
        $small = $this->tx('2026-05-21', -9000, 'PRET');
        $this->decide($small, 'business_expense', 'travel_subsistence');
        $fee = $this->tx('2026-05-22', -20000, 'BANK FEE');
        $this->decide($fee, 'business_expense', 'finance');

        // The transaction page and the detectors use one rule.
        $this->assertSame([$matched->id], ReceiptMatch::forPayment($paid)->pluck('id')->all());
        $links = $this->getJson('/items/bank/'.$paid->id)->assertOk()->json('links');
        $this->assertSame(['/documents/'.$matched->id], collect($links)->where('type', 'receipt')->pluck('href')->values()->all());
        $this->assertSame([$matched->id], array_map(fn ($d) => $d->id, ReceiptMatch::among(ReceiptMatch::byAmount(ReceiptMatch::receipts()), $paid)));

        $unmatched = $this->found(new ReceiptUnmatched);
        $this->assertSame(['receipt:'.$lonely->id, 'receipt:'.$email->id], array_keys($unmatched));
        $d = $unmatched['receipt:'.$lonely->id];
        $this->assertSame(['receipt.unmatched', -8999, 'unrecorded', 'likely', 'year:2026-27'], [$d->code, $d->amountPence, $d->direction, $d->grade, $d->scopeRef]);
        $this->assertSame('doc:'.$lonely->id.'#f=receipt@'.substr($lonely->sha256, 0, 8), $d->subjectRef);
        $this->assertSame('Receipt with no payment: £89.99 at Thomann on 15 May 2026', $d->title);

        $costs = $this->found(new CostNoReceipt);
        $this->assertSame(['noreceipt:'.$big->id], array_keys($costs), 'Over £100, no receipt, not a bank charge');
        $c = $costs['noreceipt:'.$big->id];
        $this->assertSame([-15000, 'none', 'likely', 'tx:'.$big->id, 'year:2026-27'], [$c->amountPence, $c->direction, $c->grade, $c->subjectRef, $c->scopeRef]);

        // The threshold is a setting: at £50, the £90 lunch needs one too. A locked year is left alone.
        Setting::put('intel_receipt_threshold_pence', 5000);
        $this->assertSame(['noreceipt:'.$big->id, 'noreceipt:'.$small->id], array_keys($this->found(new CostNoReceipt)));
        TaxYear::track('2026-27')->update(['locked_at' => now()]);
        $this->assertSame([], $this->found(new CostNoReceipt));
        $this->assertSame([], $this->found(new ReceiptUnmatched));
    }

    public function test_income_with_no_client_job_or_invoice(): void
    {
        $job = WorkJob::query()->create(['title' => 'Gala', 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
        $loose = $this->tx('2026-05-20', 105000, 'ACME EVENTS LTD');
        $this->decide($loose, 'business_income', 'turnover');
        $onJob = $this->tx('2026-05-21', 50000, 'ACME EVENTS LTD');
        $this->decide($onJob, 'business_income', 'turnover', ['work_job_id' => $job->id]);
        $interest = $this->tx('2026-05-22', 300, 'INTEREST');
        $this->decide($interest, 'business_income', 'other_income');
        $suggested = $this->tx('2026-05-23', 20000, 'STAGE CO');
        app(AllocationWriter::class)->decide($suggested, AllocationWriter::lines(20000, 'business_income', Category::query()->where('code', 'turnover')->value('id')), 'matcher', false);

        // Version 2 (income design v3): income for a client with no job is done once the client is set.
        $client = Client::query()->create(['name' => 'Acme Events']);
        $forClient = $this->tx('2026-05-24', 40000, 'ACME EVENTS LTD');
        $this->decide($forClient, 'business_income', 'turnover', ['client_id' => $client->id]);

        $found = $this->found(new IncomeUnlinked);
        $this->assertSame(['unlinked:'.$loose->id], array_keys($found), 'Only confirmed turnover with no client, job or invoice');
        $d = $found['unlinked:'.$loose->id];
        $this->assertSame(['income.unlinked', 105000, 'none', 'certain', 'tx:'.$loose->id, 'records', 2], [$d->code, $d->amountPence, $d->direction, $d->grade, $d->subjectRef, $d->group, $d->detectorVersion]);
        $this->assertSame('Income with no client, job or invoice: £1,050.00 from ACME EVENTS LTD on 20 May 2026', $d->title);
        $this->assertStringContainsString('Set the client, job or invoice', $d->detail);
        $this->assertSame('/transactions?year=2026-27&unassigned=1&q=ACME+EVENTS+LTD', $d->href);
    }

    public function test_a_cash_expense_also_on_a_card(): void
    {
        $cash = ManualExpense::query()->create(['spent_on' => '2026-05-14', 'tax_year' => '2026-27', 'description' => 'Screwfix Birmingham', 'merchant_key' => 'SCREWFIX BIRMINGHAM',
            'paid_with' => 'cash', 'amount_pence' => -4599]);
        $this->decide($cash, 'business_expense', 'materials');
        $card = $this->tx('2026-05-15', -4599, 'SCREWFIX DIRECT');
        // Same amount, another merchant; and the right merchant a week later: neither.
        $this->tx('2026-05-14', -4599, 'TESCO STORES');
        $this->tx('2026-05-22', -4599, 'SCREWFIX DIRECT');

        $found = $this->found(new DupeCrossSource);
        $d = $found['xdupe:'.$cash->id];
        $this->assertSame(['dupe.cross_source', -4599, 'at_risk', 'likely', 'duplicates'], [$d->code, $d->amountPence, $d->direction, $d->grade, $d->group]);
        $this->assertStringStartsWith('exp:'.$cash->id.'@', $d->subjectRef);
        $this->assertSame([$d->subjectRef, 'tx:'.$card->id], $d->evidence);

        // Decided as personal on the statement: not claimed twice.
        $this->decide($card, 'personal');
        $this->assertSame([], $this->found(new DupeCrossSource));
    }
}
