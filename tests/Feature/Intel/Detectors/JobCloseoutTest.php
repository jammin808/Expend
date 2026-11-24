<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\JobCloseout;
use App\Domain\Intel\RefResolver;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoList;
use App\Models\Client;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * job.closeout: one verdict per finished job, from its PO, its invoices and the bookings you decided to recharge, which
 * says what the "less than its PO" and "recharge" items said for it, never counting them twice.
 */
class JobCloseoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** A PO read from its document: £1,200 in all, its total found word for word in it. */
    private function po(WorkJob $job, int $total = 120000, bool $checked = true): Document
    {
        return Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'.$job->id), 'stored_path' => 'x', 'kind' => 'po',
            'status' => 'extracted', 'work_job_id' => $job->id, 'job_linked_by' => 'you', 'email_subject' => 'PO-77 Arena',
            'extraction' => ['po' => ['po_number' => 'PO-77', 'currency' => 'GBP', 'stated_total_minor' => $total, 'total_verified' => $checked, 'bill_to_name' => 'Acme Events',
                'bill_to_address' => null, 'bill_to_email' => null, 'lines' => [['description' => 'Crew', 'quantity' => 1, 'unit_price_minor' => $total, 'amount_minor' => $total, 'quote' => 'Crew']]]]]);
    }

    /** The invoice drafted from the PO, changed to £1,050, then issued. */
    private function invoice(WorkJob $job, Document $po, string $fee = '1050.00', bool $issue = true): Invoice
    {
        $service = app(InvoiceService::class);
        $invoice = $service->draftFromDocument($po);
        $service->updateDraft($invoice, [], [['description' => 'Crew day', 'quantity' => '1', 'unit_price' => $fee]]);
        $invoice->refresh();
        if ($issue) {
            $invoice = $service->issue($invoice, '2026-06-04');
        }

        return $invoice->refresh();
    }

    private function recharge(WorkJob $job, int $amount = 18000, string $provider = 'Premier Inn', string $currency = 'GBP'): TravelItem
    {
        return TravelItem::query()->create(['kind' => 'hotel', 'provider' => $provider, 'place' => 'Premier Inn Arena', 'status' => 'booked', 'starts_on' => '2026-06-02', 'ends_on' => '2026-06-03',
            'amount_minor' => $amount, 'currency' => $currency, 'payment' => 'paid', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'decision' => 'recharge',
            'decided_at' => now()]);
    }

    /** @return array<string, array> every item on the list, above and below the fold, by key */
    private function items(): array
    {
        $list = TodoList::build(100);

        return collect([...$list['items'], ...$list['below'], ...TodoList::belowFold(100)['items']])->keyBy('key')->all();
    }

    public function test_the_arena_example_end_to_end(): void
    {
        // PO £1,200, invoiced £1,050, and a £180 hotel to recharge that isn't on the invoice: £330 owed to you, once.
        $job = $this->job();
        $po = $this->po($job);
        $invoice = $this->invoice($job, $po);
        $hotel = $this->recharge($job);
        $this->assertSame([105000, 120000, 'issued'], [$invoice->total_minor, $invoice->po_total_minor, $invoice->status]);

        $items = $this->items();
        $this->assertArrayNotHasKey('underbilled:'.$invoice->id, $items, 'Said by the close-out now');
        $this->assertArrayNotHasKey('recharge:'.$hotel->id, $items);
        $item = $items['closeout:'.$job->id];
        $this->assertSame(['closeout', 1, 33000, 'GBP', 'owed_to_you', 'strong', 'job.closeout'],
            [$item['group'], $item['priority'], $item['amount'], $item['currency'], $item['direction'], $item['grade'], $item['code']]);
        $this->assertSame('Arena load-in: £330.00 not invoiced', $item['title']);
        $this->assertStringContainsString('PO £1,200.00, invoiced £1,050.00: £150.00 short.', $item['detail']);
        $this->assertStringContainsString('To recharge and not on the invoice: Hotel Premier Inn Arena 2 Jun £180.00.', $item['detail']);
        $this->assertFalse($item['below_fold'], 'It says what was on the list before, so it keeps its place');
        $this->assertSame([], $item['actions'], 'Invoiced already: void and reissue, or invoice the rest');
        $this->assertSame('/invoices/'.$invoice->id, $item['href']);
        $this->assertContains('doc:'.$po->id.'#f=po_total@'.substr($po->sha256, 0, 8), $item['why']);
        $this->assertContains('inv:'.$invoice->id, $item['why']);
        $this->assertContains('travel:'.$hotel->id, $item['why']);
        foreach (RefResolver::resolveMany($item['why']) as $ref => $r) {
            $this->assertSame('ok', $r['state'], $ref);
        }

        // Recorded once: one finding of £330, none for the two kinds it says.
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', 'closeout:'.$job->id)->sole();
        $this->assertSame([33000, null, 'owed_to_you', 'strong', 'job:'.$job->id, 'job:'.$job->id], [$f->amount_pence, $f->currency, $f->direction, $f->grade, $f->subject_ref, $f->scope_ref]);
        $this->assertSame(0, Finding::query()->whereIn('key', ['underbilled:'.$invoice->id, 'recharge:'.$hotel->id])->count());
        // The close-out's £330 and the invoice still to send (£1,050): nothing counted twice.
        $this->assertSame(['GBP' => 138000], collect(FindingsRegistry::summary()['by_direction'])->firstWhere('direction', 'owed_to_you')['open']);
    }

    public function test_the_items_it_replaces_are_superseded_citing_it_never_dealt_with_twice(): void
    {
        // Under way: the two items as before.
        $job = $this->job('Arena load-in', '2026-06-12');
        $po = $this->po($job);
        $invoice = $this->invoice($job, $po);
        $hotel = $this->recharge($job);
        FindingsRegistry::sync();
        $this->assertSame(2, Finding::query()->whereIn('key', ['underbilled:'.$invoice->id, 'recharge:'.$hotel->id])->whereNull('resolved_at')->count());

        // Finished: one close-out says both, and they're superseded by it.
        $this->at('2026-06-20');
        FindingsRegistry::sync();
        $closeout = Finding::query()->where('key', 'closeout:'.$job->id)->sole();
        foreach (['underbilled:'.$invoice->id, 'recharge:'.$hotel->id] as $key) {
            $old = Finding::query()->where('key', $key)->sole();
            $this->assertSame(['superseded', 'resolved', 'fnd:'.$closeout->id], [$old->resolution, $old->state, $old->resolution_ref], $key);
        }
        $summary = FindingsRegistry::summary();
        $this->assertSame(['GBP' => 138000], collect($summary['by_direction'])->firstWhere('direction', 'owed_to_you')['open'], 'The close-out and the invoice to send');
        $this->assertSame([], collect($summary['by_direction'])->firstWhere('direction', 'owed_to_you')['resolved'], 'Superseded is not dealt with');
    }

    public function test_a_recharge_on_the_invoice_is_closed_out_and_its_item_goes(): void
    {
        $job = $this->job();
        $po = $this->po($job, 105000);
        $invoice = app(InvoiceService::class)->draftFromDocument($po);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Crew day', 'quantity' => '1', 'unit_price' => '1050.00'],
            ['description' => 'Hotel: Premier Inn, 2 nights', 'quantity' => '1', 'unit_price' => '180.00']]);
        app(InvoiceService::class)->issue($invoice->refresh(), '2026-06-04');
        $hotel = $this->recharge($job);

        $items = $this->items();
        $this->assertArrayNotHasKey('closeout:'.$job->id, $items, 'Everything is on the invoice (the hotel on a line of its amount)');
        $this->assertArrayNotHasKey('recharge:'.$hotel->id, $items, 'Checked: it is on the invoice');
    }

    public function test_a_finished_job_with_no_invoice_offers_the_draft_and_counts_its_po(): void
    {
        $job = $this->job();
        $po = $this->po($job, 80000, checked: false);
        $hotel = $this->recharge($job, 9500, 'Travelodge');

        $item = $this->items()['closeout:'.$job->id];
        $this->assertSame([89500, 'likely', 1], [$item['amount'], $item['grade'], $item['priority']], 'PO not found word for word in it: likely');
        $this->assertStringContainsString('PO £800.00 and no invoice yet.', $item['detail']);
        $this->assertStringContainsString('wasn’t found word for word', $item['detail']);
        $this->assertSame([['code' => 'job_invoice', 'id' => (string) $job->id, 'label' => 'Draft the invoice', 'style' => 'default']], $item['actions']);
        $this->assertContains('travel:'.$hotel->id, $item['why']);

        // The action is the usual one: a draft for the job, and the close-out stays until it's right.
        $this->post('/todo/job_invoice/'.$job->id, ['fp' => Fingerprints::of('job_invoice', (string) $job->id)])->assertSessionHas('success');
        $this->assertSame(1, Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->count());
    }

    public function test_a_close_out_of_its_own_starts_below_the_fold(): void
    {
        // Only the PO document says anything: nothing was on the list for it, so it's new, and starts low.
        $job = $this->job();
        $this->po($job);
        $list = TodoList::build(100);
        $this->assertNotContains('closeout:'.$job->id, array_column($list['items'], 'key'));
        $below = collect($list['below'])->firstWhere('key', 'closeout:'.$job->id);
        $this->assertSame(['info', true], [$below['severity'], $below['below_fold']]);
        $this->assertSame(2, $below['priority']);
        $this->assertArrayNotHasKey('closeout', $list['counts'], 'Not counted with the list');
        $this->assertSame(1, $list['below_counts']['closeout']);

        FindingsRegistry::sync();
        $this->assertSame('info', Finding::query()->where('key', 'closeout:'.$job->id)->value('severity'));
    }

    public function test_a_recharge_in_another_currency_stays_as_it_was(): void
    {
        $job = $this->job();
        $po = $this->po($job);
        $invoice = $this->invoice($job, $po, '1200.00');
        $euros = $this->recharge($job, 15000, 'Ibis Paris', 'EUR');

        $items = $this->items();
        $this->assertArrayNotHasKey('closeout:'.$job->id, $items, 'Invoiced in full in pounds');
        $this->assertSame([15000, 'EUR'], [$items['recharge:'.$euros->id]['amount'], $items['recharge:'.$euros->id]['currency']], 'Can’t be added to pounds: listed as before');
        $closeout = new JobCloseout;
        $closeout->detect(new CycleContext);
        $this->assertArrayNotHasKey('recharge:'.$euros->id, $closeout->subsumed(), 'Never said by the close-out');
        $this->assertArrayNotHasKey('underbilled:'.$invoice->id, $items);
    }
}
