<?php

namespace Tests\Feature\Invoices;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * The invoice page left open while the draft's lines change (a cost the job's sync added): it sends the lines as it
 * showed them (lines_version) and the last change it showed (seen_up_to), so a save or an issue from it is refused
 * rather than unticking, or issuing, a line it never showed. The page then starts again from the lines as they are.
 */
class InvoicePageStaleTest extends TestCase
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

    /** Sent the way the page sends it: JSON from Inertia, back to the page it came from. */
    private function inertia(string $method, string $uri, array $body, string $from): TestResponse
    {
        return $this->from($from)->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** What the page shows: its lines (ticked), lines_version and seen_up_to. */
    private function page(Invoice $draft): array
    {
        $props = [];
        $this->get("/invoices/{$draft->id}")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $props = $page->toArray()['props']['invoice'];
        });

        return $props;
    }

    public function test_a_page_left_open_while_the_sync_added_a_cost_neither_saves_nor_issues(): void
    {
        $client = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($client, 'at_cost');
        $job = $this->job($client, '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = app(InvoiceService::class)->draftForJob($job);
        $shown = $this->page($draft);
        $this->assertSame([InvoiceDraftSync::linesVersion($draft), InvoiceDraftSync::lastChange($draft)], [$shown['lines_version'], $shown['seen_up_to']]);
        $form = ['bill_to_name' => 'Acme Events', 'currency' => 'GBP', 'notes' => 'Payment due within 14 days.', 'lines_version' => $shown['lines_version'],
            'lines' => collect($shown['lines'])->map(fn (array $l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price']])->all()];

        // A taxi is linked, and the draft brought up to date, while the page is open.
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        app(InvoiceService::class)->draftForJob($job->fresh());
        $ref = "tx:{$taxi->id}#j{$job->id}";

        $this->inertia('PATCH', "/invoices/{$draft->id}", $form, "/invoices/{$draft->id}")
            ->assertSessionHas('error', 'The draft’s lines changed since you opened it: check them and save again.');
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', $ref)->sole();
        $this->assertSame([true, null], [(bool) $line->included, $line->ticked_by], 'The taxi stays ticked: the page never showed it');
        $this->assertSame('Payment due within 30 days.', $draft->fresh()->notes, 'Nothing saved');

        $this->inertia('POST', "/invoices/{$draft->id}/issue", ['lines_version' => $shown['lines_version'], 'seen_up_to' => $shown['seen_up_to']], "/invoices/{$draft->id}")
            ->assertSessionHas('error', 'The lines changed since you opened the issue sheet: check them and issue again.');
        $this->assertSame('draft', $draft->fresh()->status);

        // The page as it is now shows the taxi, and issues.
        $now = $this->page($draft);
        $this->assertContains('Taxi: UBER, 3 Jun 2026 (at cost)', collect($now['lines'])->pluck('description')->all());
        $this->inertia('POST', "/invoices/{$draft->id}/issue", ['lines_version' => $now['lines_version'], 'seen_up_to' => $now['seen_up_to']], "/invoices/{$draft->id}")
            ->assertSessionHas('success');
        $this->assertSame(['issued', 67340], [$draft->fresh()->status, (int) $draft->fresh()->total_minor]);
    }

    public function test_a_form_that_doesnt_say_what_it_showed_leaves_a_line_the_sync_added_since_alone(): void
    {
        $client = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($client, 'at_cost');
        $job = $this->job($client, '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = app(InvoiceService::class)->draftForJob($job);
        $form = $draft->fresh()->lines()->get()->map(fn (InvoiceLine $l) => ['description' => $l->description, 'quantity' => (string) $l->quantity,
            'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', '')])->all();
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        app(InvoiceService::class)->draftForJob($job->fresh());

        // A page from before (no lines_version) saves what it had open.
        app(InvoiceService::class)->updateDraft($draft->fresh(), ['notes' => 'Payment due within 14 days.'], $form);

        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', "tx:{$taxi->id}#j{$job->id}")->sole();
        $this->assertSame([true, null, 67340], [(bool) $line->included, $line->ticked_by, (int) $draft->fresh()->total_minor]);
    }

    public function test_the_page_sends_what_it_showed_and_starts_again_when_the_lines_change(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/invoices/show.tsx'));

        $this->assertStringContainsString('<InvoicePage key={props.invoice.lines_version ?? props.invoice.status} {...props} />', $source);
        $this->assertStringContainsString('lines_version: invoice.lines_version,', $source);
        $this->assertStringContainsString('{ lines_version: invoice.lines_version, seen_up_to: invoice.seen_up_to }', $source);
    }
}
