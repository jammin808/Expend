<?php

namespace Tests\Feature\Invoices;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\JobCloseout;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S6: drafts and POs.
 *  - A PO that arrives after Done converts the job's open draft: its lines replace the work, the costs and your own
 *    lines stay, and it's listed as a change. Never a second draft.
 *  - A job's draft is made from its one PO when there is one; otherwise it carries the job's PO number.
 *  - A PO with a line for travel or expenses leaves the costs unticked; extra days on a PO draft are only a question.
 *  - The ticked total over the PO's warns; JobCloseout compares the PO with the work lines only.
 */
class DraftFromPoTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->startCharges();
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    private function service(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function acme(): Client
    {
        $client = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($client, 'at_cost');

        return $client;
    }

    /** @param  list<array{0:string, 1:int}>  $lines */
    private function po(WorkJob $job, int $total, array $lines = [['Crew', 100000]]): Document
    {
        return Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'.$job->id.$total), 'stored_path' => 'x', 'kind' => 'po',
            'status' => 'extracted', 'work_job_id' => $job->id, 'job_linked_by' => 'you',
            'extraction' => ['po' => ['po_number' => 'PO-4471', 'currency' => 'GBP', 'stated_total_minor' => $total, 'total_verified' => true, 'bill_to_name' => 'Acme Events',
                'bill_to_address' => "2 Dock Road\nLondon", 'bill_to_email' => 'accounts@example.test',
                'lines' => array_map(fn (array $l) => ['description' => $l[0], 'quantity' => 1, 'unit_price_minor' => $l[1], 'amount_minor' => $l[1], 'quote' => $l[0]], $lines)]]]);
    }

    /** @return list<array{0:?string, 1:string, 2:int, 3:bool}> */
    private static function lines(Invoice $invoice): array
    {
        return InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => [$l->source, $l->description, (int) $l->amount_minor, (bool) $l->included])->all();
    }

    public function test_a_po_after_done_converts_the_open_draft(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 90000]);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $draft = $this->service()->draftForJob($job);
        $this->service()->updateDraft($draft->fresh(), [], [
            ...InvoiceLine::query()->where('invoice_id', $draft->id)->orderBy('sort')->get()->map(fn (InvoiceLine $l) => ['id' => $l->id, 'description' => $l->description,
                'quantity' => (string) $l->quantity, 'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', '')])->all(),
            ['description' => 'Extra cable', 'quantity' => '1', 'unit_price' => '12.00'],
        ]);

        $converted = $this->service()->draftFromDocument($this->po($job, 100000));

        $this->assertSame($draft->id, $converted->id, 'The same draft, never a second one');
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame([
            ['po', 'Crew', 100000, true],
            ['cost', 'Train: TRAINLINE, 2 Jun 2026 (at cost)', 4500, true],
            [null, 'Extra cable', 1200, true],
        ], self::lines($converted));
        $converted->refresh();
        $this->assertSame(['PO-4471', 100000, 'accounts@example.test'], [$converted->po_number, $converted->po_total_minor, $converted->bill_to_email]);
        $this->assertNotNull($converted->document_id);
        $this->assertSame('doc:'.$converted->document_id.'#l0', InvoiceLine::query()->where('invoice_id', $converted->id)->where('source', 'po')->sole()->source_ref);
        $this->assertContains('invoice.converted', InvoiceDraftSync::changes($converted)->pluck('action')->all(), 'Listed as a change since you looked');
        $this->assertSame('you', AuditEvent::query()->where('action', 'invoice.converted')->sole()->actor);

        // Over the PO: a warning, not a block.
        $this->assertSame(105700, $converted->total_minor);
        $this->assertContains('£57.00 of costs aren’t on PO PO-4471: check Acme Events accepts them on this PO',
            array_column($this->service()->checks($converted)['warnings'], 'message'));
    }

    public function test_a_jobs_draft_comes_from_its_one_po_or_carries_its_po_number(): void
    {
        $client = $this->acme();
        $withPo = $this->job($client, '2026-06-03', null, ['fee_pence' => 90000, 'days_agreed' => 1, 'rate_pence' => 90000, 'rate_unit' => 'day']);
        $this->po($withPo, 100000, [['Crew', 100000], ['Travel expenses', 5000]]);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $withPo)]);
        $draft = $this->service()->draftForJob($withPo);
        $this->assertSame([
            ['po', 'Crew', 100000, true],
            ['po', 'Travel expenses', 5000, true],
            // The PO has a line for travel: the train is left for you to check, never added on top.
            ['cost', 'Train: TRAINLINE, 2 Jun 2026 (at cost)', 4500, false],
        ], self::lines($draft));
        $this->assertSame('maybe_same', InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'cost')->sole()->flag);

        // Without a PO: the job's PO number.
        $plain = $this->job($client, '2026-06-04', null, ['fee_pence' => 50000, 'po_number' => 'PO-9001']);
        $this->assertSame('PO-9001', $this->service()->draftForJob($plain)->po_number);
    }

    public function test_extra_days_on_a_po_draft_are_only_a_question(): void
    {
        $job = $this->job($this->acme(), '2026-06-01', '2026-06-04', ['rate_pence' => 30000, 'rate_unit' => 'day', 'days_agreed' => 3]);
        $this->po($job, 90000, [['Crew, 3 days', 90000]]);
        $draft = $this->service()->draftForJob($job);
        $this->assertSame([
            ['po', 'Crew, 3 days', 90000, true],
            ['extra', '1 more day than agreed: '.$job->title, 30000, false],
        ], self::lines($draft));
    }

    public function test_the_closeout_compares_the_po_with_the_work_lines_only(): void
    {
        // A £1,000 PO, £900 of work and £150 of travel on the invoice: £100 short, not £50 over.
        $job = $this->job($this->acme(), '2026-06-03');
        $draft = $this->service()->draftFromDocument($this->po($job, 100000));
        $train = $this->tx(-15000, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(15000, 'travel_rail', $job)]);
        app(InvoiceDraftSync::class)->sync($draft);
        $po = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'po')->sole();
        $rows = InvoiceLine::query()->where('invoice_id', $draft->id)->orderBy('sort')->get()->map(fn (InvoiceLine $l) => ['id' => $l->id, 'description' => $l->description,
            'quantity' => (string) $l->quantity, 'unit_price' => $l->id === $po->id ? '900.00' : number_format($l->unit_price_minor / 100, 2, '.', '')])->all();
        $this->service()->updateDraft($draft->fresh(), [], $rows);
        // Issued from the sheet, which shows the train the sync added.
        $issued = $this->service()->issue($draft->fresh(), '2026-06-05', ['lines_version' => InvoiceDraftSync::linesVersion($draft), 'seen_up_to' => InvoiceDraftSync::lastChange($draft)]);
        $this->assertSame(105000, $issued->total_minor);

        $found = collect((new JobCloseout)->detect(new CycleContext))->keyBy('key');
        $closeout = $found['closeout:'.$job->id] ?? null;
        $this->assertNotNull($closeout);
        $this->assertSame([10000, $job->title.': £100.00 not invoiced'], [$closeout->amountPence, $closeout->title]);
        $this->assertStringContainsString('PO £1,000.00, invoiced £900.00: £100.00 short.', $closeout->detail);
    }
}
