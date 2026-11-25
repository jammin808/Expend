<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Charges\CostCharges;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\JobCloseout;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * job.closeout and your decisions: what you snoozed or marked not relevant stays so, a close-out you hid comes back only
 * with what's new, and what it can't account for (a recharge with no amount, a shortfall in another currency) stays on
 * the list as it was. Also: income recorded for a job invoiced outside Expend, one line for several recharges, and a
 * line that only names the provider. Version 3 (build slice S6): your decision about a cost ("Not charged") takes it out.
 */
class CloseoutDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('job_finder_auto', false);
        $this->at('2026-06-10');
        $this->actingAs(User::factory()->create());
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

    private function job(string $title = 'Arena load-in', string $on = '2026-06-03'): WorkJob
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);

        return WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => 'done', 'po_number' => 'PO-77']);
    }

    private function po(WorkJob $job, int $total = 120000, string $currency = 'GBP'): Document
    {
        return Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'.$job->id.$currency), 'stored_path' => 'x', 'kind' => 'po',
            'status' => 'extracted', 'work_job_id' => $job->id, 'job_linked_by' => 'you', 'email_subject' => 'PO-77 Arena',
            'extraction' => ['po' => ['po_number' => 'PO-77', 'currency' => $currency, 'stated_total_minor' => $total, 'total_verified' => true, 'bill_to_name' => 'Acme Events',
                'bill_to_address' => null, 'bill_to_email' => null, 'lines' => [['description' => 'Crew', 'quantity' => 1, 'unit_price_minor' => $total, 'amount_minor' => $total, 'quote' => 'Crew']]]]]);
    }

    /** @param  list<array{0:string, 1:string}>  $lines  [description, unit price] */
    private function invoice(Document $po, array $lines = [['Crew day', '1050.00']]): Invoice
    {
        $service = app(InvoiceService::class);
        $invoice = $service->draftFromDocument($po);
        $service->updateDraft($invoice, [], array_map(fn ($l) => ['description' => $l[0], 'quantity' => '1', 'unit_price' => $l[1]], $lines));

        return $service->issue($invoice->refresh(), '2026-06-04')->refresh();
    }

    private function recharge(WorkJob $job, ?int $amount = 18000, string $provider = 'Premier Inn', string $kind = 'hotel', array $extra = []): TravelItem
    {
        return TravelItem::query()->create($extra + ['kind' => $kind, 'provider' => $provider, 'place' => $provider.' Arena', 'status' => 'booked', 'starts_on' => '2026-06-02', 'ends_on' => '2026-06-03',
            'amount_minor' => $amount, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'decision' => 'recharge',
            'decided_at' => now()]);
    }

    /** @return array<string, array> every item on the list, above and below the fold, by key */
    private function items(): array
    {
        $list = TodoList::build(100);

        return collect([...$list['items'], ...$list['below'], ...TodoList::belowFold(100)['items']])->keyBy('key')->all();
    }

    /** The Arena example: PO £1,200, invoiced £1,050, a £180 hotel to recharge not on the invoice. */
    private function arena(string $on = '2026-06-03'): array
    {
        $job = $this->job('Arena load-in', $on);
        $invoice = $this->invoice($this->po($job));
        $hotel = $this->recharge($job);

        return [$job, $invoice, $hotel];
    }

    public function test_items_you_marked_not_relevant_never_come_back_as_a_close_out(): void
    {
        // Before the job was finished, you set both aside: the client agreed the lower fee and paid the hotel.
        [$job, $invoice, $hotel] = $this->arena('2026-06-12');
        FindingsRegistry::sync();
        Dismissals::dismiss('underbilled:'.$invoice->id, null, 'Already sorted');
        Dismissals::dismiss('recharge:'.$hotel->id, null, 'Already sorted');
        FindingsRegistry::sync();

        $this->at('2026-06-20');
        $items = $this->items();
        $this->assertArrayNotHasKey('closeout:'.$job->id, $items, 'Nothing you didn’t set aside is owed');
        $this->assertArrayNotHasKey('underbilled:'.$invoice->id, $items);
        $this->assertArrayNotHasKey('recharge:'.$hotel->id, $items);
        $list = TodoList::build();
        $this->assertArrayNotHasKey('closeout', $list['counts']);
        $this->assertArrayNotHasKey('revenue', $list['counts']);

        FindingsRegistry::sync();
        $this->assertSame(0, Finding::query()->where('key', 'closeout:'.$job->id)->count());
        foreach (['underbilled:'.$invoice->id, 'recharge:'.$hotel->id] as $key) {
            $this->assertSame(['dismissed', 'dismissed'], [Finding::query()->where('key', $key)->value('resolution'), Finding::query()->where('key', $key)->value('state')], $key);
        }
    }

    public function test_one_part_set_aside_leaves_the_rest_in_the_close_out(): void
    {
        [$job, $invoice, $hotel] = $this->arena();
        Dismissals::dismiss('underbilled:'.$invoice->id, null, 'Already sorted');

        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame([18000, 'Arena load-in: £180.00 not invoiced', ['recharge:'.$hotel->id]], [$item['amount'], $item['title'], $item['subsumes']]);
        $this->assertStringNotContainsString('short', $item['detail']);
        $closeout = new JobCloseout;
        $closeout->detect(new CycleContext);
        $this->assertArrayNotHasKey('underbilled:'.$invoice->id, $closeout->subsumed(), 'Left as you left it');
    }

    public function test_a_snoozed_recharge_stays_snoozed_and_joins_the_close_out_when_the_snooze_ends(): void
    {
        [$job, $invoice, $hotel] = $this->arena('2026-06-12');
        FindingsRegistry::sync();
        Dismissals::dismiss('recharge:'.$hotel->id, 14, 'Later');
        FindingsRegistry::sync();

        $this->at('2026-06-20');
        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame(15000, $item['amount'], 'Only what’s short of the PO while the snooze runs');
        FindingsRegistry::sync();
        $this->assertSame(['snoozed', 'snoozed'], [Finding::query()->where('key', 'recharge:'.$hotel->id)->value('resolution'), Finding::query()->where('key', 'recharge:'.$hotel->id)->value('state')],
            'Your snooze is kept, not superseded');

        $this->at('2026-06-27');
        $this->assertSame(33000, $this->items()['closeout:'.$job->id]['amount'], 'The snooze has ended: back in the close-out');
        FindingsRegistry::sync();
        $old = Finding::query()->where('key', 'recharge:'.$hotel->id)->sole();
        $this->assertSame(['superseded', 'fnd:'.Finding::query()->where('key', 'closeout:'.$job->id)->value('id')], [$old->resolution, $old->resolution_ref]);
    }

    public function test_a_close_out_you_hid_comes_back_only_with_what_is_new(): void
    {
        [$job] = $this->arena();
        FindingsRegistry::sync();
        $this->assertSame('Marked not relevant.', Dismissals::dismiss('closeout:'.$job->id, null, 'Already sorted'));
        $this->assertTrue(TodoDismissal::query()->where('key', 'like', 'closeout:'.$job->id.':%')->exists(), 'Kept by its parts as you saw them');
        $this->assertArrayNotHasKey('closeout:'.$job->id, $this->items());
        FindingsRegistry::sync();
        $this->assertSame('dismissed', Finding::query()->where('key', 'closeout:'.$job->id)->value('state'));

        // A booking decided as a recharge later: the close-out comes back with only that.
        $taxi = $this->recharge($job, 4500, 'Addison Lee', 'taxi');
        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame([4500, 'Arena load-in: £45.00 not invoiced'], [$item['amount'], $item['title']]);
        $this->assertStringContainsString('Addison Lee', $item['detail']);
        $this->assertStringNotContainsString('short', $item['detail']);
        $this->assertArrayNotHasKey('recharge:'.$taxi->id, $this->items(), 'Said by the close-out');
        FindingsRegistry::sync();
        $this->assertSame(['open', 4500], [Finding::query()->where('key', 'closeout:'.$job->id)->value('state'), (int) Finding::query()->where('key', 'closeout:'.$job->id)->value('amount_pence')]);

        // Undo brings all of it back.
        Dismissals::undo('closeout:'.$job->id);
        $this->assertSame(37500, $this->items()['closeout:'.$job->id]['amount']);
        $this->assertSame(0, TodoDismissal::query()->where('key', 'like', 'closeout:%')->count());
    }

    public function test_a_snoozed_close_out_is_recorded_as_snoozed_not_dealt_with(): void
    {
        [$job] = $this->arena();
        FindingsRegistry::sync();
        Dismissals::dismiss('closeout:'.$job->id, 7, 'Later');
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', 'closeout:'.$job->id)->sole();
        $this->assertSame(['snoozed', 'snoozed'], [$f->resolution, $f->state]);
        $this->assertArrayNotHasKey('closeout:'.$job->id, $this->items());

        $this->at('2026-06-18');
        $this->assertSame(33000, $this->items()['closeout:'.$job->id]['amount']);
        FindingsRegistry::sync();
        $this->assertSame('open', Finding::query()->where('key', 'closeout:'.$job->id)->value('state'));
    }

    public function test_a_close_out_no_longer_there_cannot_be_hidden_for_good(): void
    {
        $job = $this->job();
        $this->expectExceptionMessage('changed since you saw it');
        Dismissals::dismiss('closeout:'.$job->id, null, 'Already sorted');
    }

    public function test_a_job_invoiced_outside_expend_counts_the_income_recorded_for_it(): void
    {
        $job = $this->job();
        $this->po($job);
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $pay = function (int $pence) use ($acc, $batch, $job) {
            $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-08', 'tax_year' => UkTaxYear::fromDate('2026-06-08')->label(),
                'description' => 'ACME EVENTS LTD', 'counterparty' => 'ACME EVENTS LTD', 'merchant_key' => 'ACME EVENTS LTD', 'amount_pence' => $pence, 'raw' => [],
                'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
            app(AllocationWriter::class)->decide($tx, [['kind' => 'business_income', 'category_id' => Category::query()->where('code', 'turnover')->value('id'),
                'amount_pence' => $pence, 'work_job_id' => $job->id]]);
        };

        $pay(100000);
        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame(20000, $item['amount'], 'The £1,000 that came in was invoiced some other way');
        $this->assertStringContainsString('PO £1,200.00, and £1,000.00 received for the job with no invoice issued in Expend: £200.00 short.', $item['detail']);

        $pay(20000);
        $this->assertArrayNotHasKey('closeout:'.$job->id, $this->items(), 'Paid in full: nothing owed');
    }

    public function test_not_relevant_on_invoicing_a_job_sets_its_po_shortfall_aside(): void
    {
        $job = $this->job();
        $this->po($job);
        $this->assertSame(120000, $this->items()['closeout:'.$job->id]['amount']);
        Dismissals::dismiss('invoice:'.$job->id, null, 'Already sorted');
        $this->assertArrayNotHasKey('closeout:'.$job->id, $this->items());
    }

    public function test_a_recharge_with_no_amount_or_cancelled_stays_on_the_list(): void
    {
        $job = $this->job();
        $this->invoice($this->po($job, 105000));
        $unknown = $this->recharge($job, null, 'Travelodge', 'hotel', ['payment' => 'pay_at_property']);
        $cancelled = $this->recharge($job, 9000, 'Trainline', 'rail', ['status' => 'cancelled']);

        $items = $this->items();
        $this->assertSame('Recharge Travelodge to the client', $items['recharge:'.$unknown->id]['title'] ?? null, 'No amount read: still a reminder');
        $this->assertArrayHasKey('recharge:'.$cancelled->id, $items, 'Listed as it was before');
        $this->assertArrayNotHasKey('closeout:'.$job->id, $items);
        $closeout = new JobCloseout;
        $closeout->detect(new CycleContext);
        $this->assertArrayNotHasKey('recharge:'.$unknown->id, $closeout->subsumed());

        FindingsRegistry::sync();
        $this->assertSame('open', Finding::query()->where('key', 'recharge:'.$unknown->id)->value('state'), 'Not recorded as dealt with');
    }

    public function test_one_invoice_line_for_two_recharges(): void
    {
        $job = $this->job();
        $this->invoice($this->po($job, 105000), [['Crew day', '1050.00'], ['Travel and hotel (recharged at cost)', '180.00']]);
        $train = $this->recharge($job, 7000, 'Trainline', 'rail');
        $hotel = $this->recharge($job, 11000, 'Travelodge');

        $items = $this->items();
        $this->assertArrayNotHasKey('closeout:'.$job->id, $items, 'The two are on the one line');
        $this->assertArrayNotHasKey('recharge:'.$train->id, $items);
        $this->assertArrayNotHasKey('recharge:'.$hotel->id, $items);
    }

    public function test_a_line_that_only_names_the_provider_is_something_to_check_not_billed(): void
    {
        $job = $this->job('Metropole awards');
        $invoice = $this->invoice($this->po($job, 105000), [['Crew day, Hilton Birmingham Metropole', '1050.00']]);
        $room = $this->recharge($job, 14500, 'Hilton');

        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame([14500, 'likely', 'Metropole awards: £145.00 may not be invoiced', 1], [$item['amount'], $item['grade'], $item['title'], $item['priority']]);
        $this->assertStringContainsString('no line of the invoice is for that amount', $item['detail']);
        $this->assertStringContainsString('Check it’s on the invoice', $item['detail']);
        $this->assertArrayNotHasKey('recharge:'.$room->id, $this->items());
        $this->assertSame('/invoices/'.$invoice->id, $item['href']);
    }

    public function test_a_shortfall_in_a_second_currency_stays_as_its_own_item(): void
    {
        $job = $this->job('Paris show');
        $prep = $this->invoice($this->po($job, 40000), [['Prep day', '400.00']]);
        $euros = $this->po($job, 120000, 'EUR');
        $show = $this->invoice($euros);
        $this->assertSame(['GBP', 'EUR', 120000, 105000], [$prep->currency ?: 'GBP', $show->currency, $show->po_total_minor, $show->total_minor]);

        $items = $this->items();
        $this->assertSame([15000, 'EUR'], [$items['underbilled:'.$show->id]['amount'] ?? null, $items['underbilled:'.$show->id]['currency'] ?? null], 'Listed as before: €150 less than its PO');
    }

    public function test_not_charged_on_a_cost_takes_it_out_of_the_close_out(): void
    {
        [$job, , $hotel] = $this->arena();
        app(CostCharges::class)->decide('travel:'.$hotel->id, 'no_charge');

        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame([15000, 'Arena load-in: £150.00 not invoiced'], [$item['amount'], $item['title']], 'Only what’s short of the PO');
        $this->assertStringNotContainsString('Premier Inn', $item['detail']);
        $this->assertArrayNotHasKey('recharge:'.$hotel->id, $this->items(), 'Your decision stands: no reminder either');
    }
}
