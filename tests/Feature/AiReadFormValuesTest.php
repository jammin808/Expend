<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Documents\DocumentIntake;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * What the AI reads from a document fills forms: the draft invoice from a PO, and "This looks like a new job" on the
 * document's page. Whatever it read, those forms must save as they load: a value that's too long is cut to fit, an
 * email address that isn't one usable address is left out (and you're told), and an end date before the start isn't
 * kept. When a save is refused anyway, the page gets the reasons in plain words to show.
 */
class AiReadFormValuesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    /** 63 characters: a PO number with its cost centre and event, as some POs give it. */
    private const LONG_PO = 'PO 4500123456 / Cost centre MKT-EVENTS-2026 / Acme Awards Leeds';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('anthropic_api_key', 'test-key');
        Setting::put('ai_extraction', 'on_demand');
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
        $this->actingAs(User::factory()->create());
    }

    private function pdf(string $html): string
    {
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->render();

        return $pdf->output();
    }

    /** A document stored and read the real way, with the AI's answer scripted. */
    private function read(string $html, string $name, array $answer): Document
    {
        $doc = app(DocumentIntake::class)->store($this->pdf($html), $name, 'application/pdf', 'upload');
        $this->ai->responses = [FakeAiClient::json($answer + [
            'kind' => 'other', 'summary' => 'A document.', 'job_link' => ['job_id' => null, 'reason' => null], 'new_job' => null,
            'facts' => [], 'po' => null, 'receipt' => null, 'opportunity' => null,
        ])];

        return app(DocumentReader::class)->read($doc);
    }

    private function readPo(array $po): Document
    {
        return $this->read('<h1>Purchase Order</h1><p>Acme Events Ltd. Show day technician 1 x 450.00 450.00. Total 450.00 GBP.</p>', 'po.pdf', [
            'kind' => 'po', 'summary' => 'A PO from Acme Events.',
            'po' => $po + [
                'po_number' => 'PO-4512', 'bill_to_name' => 'Acme Events Ltd', 'bill_to_address' => "1 High Street\nLeeds LS1 1AA", 'bill_to_email' => null,
                'currency' => 'GBP', 'payment_terms_days' => 30, 'total' => ['amount_minor' => 45000, 'quote' => 'Total 450.00 GBP'],
                'lines' => [['description' => 'Show day technician', 'quantity' => 1, 'unit_price_minor' => 45000, 'amount_minor' => 45000, 'quote' => 'Show day technician 1 x 450.00 450.00']],
            ],
        ]);
    }

    private function readJobInvite(array $newJob): Document
    {
        return $this->read('<p>Acme Awards at The O2, Peninsula Square, SE10 0DX, London on 12 March 2026. Client: Acme Events.</p>', 'booking.pdf', [
            'kind' => 'job_invite', 'summary' => 'A booking for the Acme Awards.',
            'new_job' => $newJob + ['title' => 'Acme Awards', 'client' => 'Acme Events', 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-12',
                'venue' => 'The O2', 'postcode' => 'SE10 0DX', 'quote' => 'Acme Awards at The O2'],
        ]);
    }

    /** Sent the way the page sends it: JSON from Inertia, back to the page it came from. */
    private function inertia(string $method, string $uri, array $body, string $from): TestResponse
    {
        return $this->from($from)->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    private function props(string $uri, string $component): array
    {
        $page = $this->get($uri)->assertOk()->assertInertia(fn ($p) => $p->component($component, false))->viewData('page');

        return json_decode(json_encode($page['props']), true);
    }

    /** The draft invoice form exactly as resources/js/pages/invoices/show.tsx fills it from the page. */
    private function invoiceForm(Invoice $invoice): array
    {
        $i = $this->props("/invoices/{$invoice->id}", 'invoices/show')['invoice'];

        return [
            'bill_to_name' => $i['bill_to_name'] ?? '', 'bill_to_address' => $i['bill_to_address'] ?? '', 'bill_to_email' => $i['bill_to_email'] ?? '',
            'po_number' => $i['po_number'] ?? '', 'currency' => $i['currency'], 'notes' => $i['notes'] ?? '',
            'work_job_id' => $i['work_job_id'] ? (string) $i['work_job_id'] : '', 'client_id' => $i['client_id'] ? (string) $i['client_id'] : '',
            'lines' => array_map(fn ($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
                'source_quote' => $l['source_quote']], $i['lines']),
        ];
    }

    /** "This looks like a new job" exactly as resources/js/pages/documents/show.tsx fills it from the page. */
    private function newJobForm(Document $doc): array
    {
        $j = $this->props("/documents/{$doc->id}", 'documents/show')['document']['extraction']['new_job'];
        $this->assertNotNull($j, 'The page offers to create the job');

        return [
            'title' => $j['title'] ?? '', 'client' => $j['client'] ?? '', 'starts_on' => $j['starts_on'] ?? '',
            'ends_on' => $j['ends_on'] ?? $j['starts_on'] ?? '', 'location' => $j['venue'] ?? '', 'postcode' => $j['postcode'] ?? '',
        ];
    }

    private function draftFrom(Document $doc): Invoice
    {
        $response = $this->inertia('POST', "/documents/{$doc->id}/invoice", [], "/documents/{$doc->id}");
        $invoice = Invoice::query()->where('document_id', $doc->id)->latest('id')->firstOrFail();
        $response->assertRedirect("/invoices/{$invoice->id}");

        return $invoice;
    }

    public function test_a_draft_from_a_po_with_two_addresses_and_a_long_po_number_saves_as_the_page_sends_it(): void
    {
        $doc = $this->readPo(['po_number' => self::LONG_PO, 'bill_to_email' => 'accounts@acme.test, producer@acme.test']);
        $invoice = $this->draftFrom($doc);
        $this->assertStringContainsString('accounts@acme.test, producer@acme.test', (string) session('success'), 'You’re told the address was left out');

        $this->assertNull($invoice->bill_to_email, 'Two addresses aren’t one the invoice can be emailed to, so it’s left out');
        $this->assertSame(self::LONG_PO, $invoice->po_number, 'A PO number up to 100 characters is kept whole, as on jobs');

        $form = $this->invoiceForm($invoice);
        $form['notes'] = 'Payment due within 30 days. Thank you.';
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $form, "/invoices/{$invoice->id}")
            ->assertSessionHasNoErrors()->assertRedirect("/invoices/{$invoice->id}");
        $this->assertSame('Draft saved.', session('success'));
        $this->assertSame('Payment due within 30 days. Thank you.', $invoice->fresh()->notes);
    }

    public function test_a_single_good_address_from_the_po_is_kept(): void
    {
        $invoice = $this->draftFrom($this->readPo(['bill_to_email' => ' accounts@acme.test ']));

        $this->assertSame('accounts@acme.test', $invoice->bill_to_email);
        $this->assertStringNotContainsString('left out', (string) session('success'));
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $this->invoiceForm($invoice), "/invoices/{$invoice->id}")->assertSessionHasNoErrors();
    }

    public function test_an_address_without_an_ending_is_left_out(): void
    {
        $invoice = $this->draftFrom($this->readPo(['bill_to_email' => 'accounts@acme']));

        $this->assertNull($invoice->bill_to_email, 'accounts@acme is refused when sending, so it isn’t put on the invoice');
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $this->invoiceForm($invoice), "/invoices/{$invoice->id}")->assertSessionHasNoErrors();
    }

    public function test_overlong_po_details_are_cut_to_what_the_invoice_form_takes(): void
    {
        $doc = $this->readPo([
            'po_number' => 'PO '.str_repeat('4500123456 ', 15), 'bill_to_name' => 'Acme Events '.str_repeat('Holdings ', 30),
            'bill_to_address' => str_repeat("1 High Street, Leeds LS1 1AA\n", 50), 'bill_to_email' => str_repeat('a', 200).'@acme.test',
        ]);
        $po = $doc->extraction['po'];
        $this->assertLessThanOrEqual(100, mb_strlen($po['po_number']), 'Stored no longer than a PO number anywhere else');
        $this->assertLessThanOrEqual(200, mb_strlen($po['bill_to_name']));
        $this->assertLessThanOrEqual(1000, mb_strlen($po['bill_to_address']));
        $this->assertStringContainsString("\n", $po['bill_to_address'], 'An address keeps its lines');

        $invoice = $this->draftFrom($doc);
        $this->assertNull($invoice->bill_to_email, 'Too long to be an address');
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $this->invoiceForm($invoice), "/invoices/{$invoice->id}")->assertSessionHasNoErrors();
    }

    public function test_a_po_read_before_this_was_fixed_still_makes_a_draft_that_saves(): void
    {
        // Read and stored as the AI gave it, before readings were cut to fit.
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'old-po.pdf', 'mime' => 'application/pdf', 'size' => 1,
            'sha256' => str_repeat('d', 64), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted', 'extraction' => ['po' => [
                'po_number' => str_repeat('PO-4500123456 ', 10), 'bill_to_name' => str_repeat('Acme Events ', 30), 'bill_to_address' => str_repeat('1 High Street ', 100),
                'bill_to_email' => 'accounts@acme.test; producer@acme.test', 'currency' => 'GBP', 'payment_terms_days' => 30, 'stated_total_minor' => 45000,
                'lines' => [['description' => 'Show day', 'quantity' => '1.00', 'unit_price_minor' => 45000, 'amount_minor' => 45000, 'quote' => 'Show day 450.00']],
            ]]]);

        $invoice = $this->draftFrom($doc);
        $this->assertNull($invoice->bill_to_email);
        $this->assertLessThanOrEqual(100, mb_strlen($invoice->po_number));
        $this->assertLessThanOrEqual(200, mb_strlen($invoice->bill_to_name));
        $this->assertLessThanOrEqual(1000, mb_strlen($invoice->bill_to_address));
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $this->invoiceForm($invoice), "/invoices/{$invoice->id}")->assertSessionHasNoErrors();
    }

    public function test_the_draft_invoice_takes_a_po_number_as_long_as_a_job_does(): void
    {
        $invoice = $this->draftFrom($this->readPo([]));
        $form = $this->invoiceForm($invoice);

        $form['po_number'] = str_repeat('P', 100);
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $form, "/invoices/{$invoice->id}")->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('P', 100), $invoice->fresh()->po_number);

        $form['po_number'] = str_repeat('P', 101);
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $form, "/invoices/{$invoice->id}")->assertSessionHasErrors('po_number');
    }

    public function test_a_new_job_with_a_postcode_and_town_together_is_created_as_the_page_fills_it(): void
    {
        $doc = $this->readJobInvite(['postcode' => 'SE10 0DX, London']);
        $form = $this->newJobForm($doc);
        $this->assertSame('SE10 0DX', $form['postcode'], 'The postcode is taken out of what was read');

        $this->inertia('POST', "/documents/{$doc->id}/job", $form, "/documents/{$doc->id}")->assertSessionHasNoErrors();
        $job = WorkJob::query()->sole();
        $this->assertSame('SE10 0DX', $job->postcode);
        $this->assertSame($job->id, $doc->fresh()->work_job_id);
    }

    public function test_a_new_job_with_a_long_client_venue_and_postcode_is_cut_to_fit(): void
    {
        $doc = $this->readJobInvite([
            'client' => 'Acme Events '.str_repeat('International ', 12), 'venue' => 'The O2, '.str_repeat('Peninsula Square, ', 15),
            'postcode' => 'Greenwich Peninsula',
        ]);
        $form = $this->newJobForm($doc);
        $this->assertLessThanOrEqual(120, mb_strlen($form['client']));
        $this->assertLessThanOrEqual(200, mb_strlen($form['location']));
        $this->assertSame('', $form['postcode'], 'Words that aren’t a postcode are left out, not cut to 10 characters');
        $this->assertDoesNotMatchRegularExpression('/[\s,]$/', $form['location'], 'Cut neatly, without a trailing comma');

        $this->inertia('POST', "/documents/{$doc->id}/job", $form, "/documents/{$doc->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, WorkJob::query()->count());
    }

    public function test_a_new_job_read_as_ending_before_it_starts_is_offered_as_one_day(): void
    {
        $doc = $this->readJobInvite(['starts_on' => '2026-03-12', 'ends_on' => '2026-03-11']);
        $form = $this->newJobForm($doc);
        $this->assertSame('2026-03-12', $form['ends_on']);

        $this->inertia('POST', "/documents/{$doc->id}/job", $form, "/documents/{$doc->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, WorkJob::query()->count());
    }

    public function test_a_new_job_read_as_lasting_longer_than_a_job_can_is_offered_as_one_day(): void
    {
        $doc = $this->readJobInvite(['starts_on' => '2026-03-12', 'ends_on' => '2028-03-12']);
        $form = $this->newJobForm($doc);
        $this->assertSame('2026-03-12', $form['ends_on']);

        $this->inertia('POST', "/documents/{$doc->id}/job", $form, "/documents/{$doc->id}")->assertSessionHasNoErrors();
        $this->assertNull(session('error'));
        $this->assertSame(1, WorkJob::query()->count());
    }

    public function test_when_the_new_job_is_refused_the_page_gets_the_reasons_in_plain_words(): void
    {
        // A date the reader couldn't use arrives empty: the form says what to fill in instead of doing nothing.
        $doc = $this->readJobInvite(['starts_on' => '12 March 2026', 'ends_on' => null]);
        $form = $this->newJobForm($doc);
        $this->assertSame('', $form['starts_on']);

        $this->inertia('POST', "/documents/{$doc->id}/job", $form, "/documents/{$doc->id}")
            ->assertRedirect("/documents/{$doc->id}")
            ->assertSessionHasErrors(['starts_on' => 'Add the date the job starts.', 'ends_on' => 'Add the date the job ends (the same date for a one-day job).']);
        $this->assertSame(0, WorkJob::query()->count());

        $form = ['starts_on' => '2026-03-12', 'ends_on' => '2026-03-11', 'postcode' => 'SE10 0DX London'] + $form;
        $this->inertia('POST', "/documents/{$doc->id}/job", $form, "/documents/{$doc->id}")
            ->assertSessionHasErrors(['ends_on' => 'The job can’t end before it starts.', 'postcode' => 'A postcode is 10 characters at most, like SE10 0DX.']);
        $this->assertSame(0, WorkJob::query()->count());
    }

    public function test_the_document_page_shows_why_the_new_job_was_not_created(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/documents/show.tsx'));

        $this->assertTrue(str_contains($page, 'newJob.errors'), 'The new-job form shows its errors');
    }
}
