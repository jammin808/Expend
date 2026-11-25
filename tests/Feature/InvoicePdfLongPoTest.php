<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

/**
 * The draft invoice form takes a PO number of up to 100 characters (as jobs do). Whatever its length, the invoice
 * PDF the client gets shows all of it and keeps the issue and due dates on the page: a long PO number wraps in its
 * cell instead of pushing the dates off the right-hand edge.
 */
class InvoicePdfLongPoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('business_name', 'Alex Smith');
        Setting::put('business_address', "1 Example Road\nLeeds LS1 1AA");
        Setting::put('business_email', 'alex@example.test');
        Setting::put('invoice_terms_days', 30);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
        $this->travelTo('2026-03-12 10:00:00');
    }

    /** Sent the way resources/js/pages/invoices/show.tsx sends it: JSON from Inertia, back to the page. */
    private function inertia(string $method, string $uri, array $body, string $from): TestResponse
    {
        return $this->from($from)->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** A draft saved with this PO number through the page's form, then (unless only previewed) issued with the page's button. */
    private function invoiceWithPo(string $po, bool $issue = true): Invoice
    {
        $invoice = Invoice::query()->create(['status' => 'draft', 'currency' => 'GBP', 'bill_to_name' => 'Acme Events Ltd', 'notes' => 'Payment due within 30 days.']);
        $this->inertia('PATCH', "/invoices/{$invoice->id}", [
            'bill_to_name' => 'Acme Events Ltd', 'bill_to_address' => "1 High Street\nLeeds LS1 1AA", 'bill_to_email' => 'accounts@acme.test',
            'po_number' => $po, 'currency' => 'GBP', 'notes' => 'Payment due within 30 days.', 'work_job_id' => '', 'client_id' => '',
            'lines' => [['description' => 'Show day technician', 'quantity' => '1', 'unit_price' => '450.00', 'source_quote' => null]],
        ], "/invoices/{$invoice->id}")->assertSessionHasNoErrors();
        $this->assertSame($po, $invoice->fresh()->po_number);

        if ($issue) {
            $this->inertia('POST', "/invoices/{$invoice->id}/issue", [], "/invoices/{$invoice->id}")->assertSessionHas('success');
        }

        return $invoice->fresh();
    }

    /** The PDF as the page's PDF (or Preview PDF) button gets it. */
    private function pdfOf(Invoice $invoice): string
    {
        $response = $this->get("/invoices/{$invoice->id}/pdf")->assertOk();
        $pdf = $invoice->pdf_path ? $response->streamedContent() : $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);

        return $pdf;
    }

    /**
     * Each piece of text on the first page, where it starts and ends (in points from the left) and the page's width.
     *
     * @return array{0: list<array{text:string, x:float, right:float}>, 1: float}
     */
    private function textOnPage(string $pdf): array
    {
        $page = (new Parser)->parseContent($pdf)->getPages()[0];
        $width = (float) ($page->getDetails()['MediaBox'][2] ?? 595.28);
        $metrics = (new Dompdf)->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');

        $pieces = [];
        foreach ($page->getDataTm() as $item) {
            [$tm, $text] = $item;
            $size = (float) ($item[3] ?? 10.5);
            $x = (float) $tm[4];
            // Measured in the regular face: bold text (only the headings and the total) is a little wider than this.
            $pieces[] = ['text' => $text, 'x' => $x, 'right' => $x + $metrics->getTextWidth($text, $font, $size)];
        }

        return [$pieces, $width];
    }

    private function assertAllOnThePage(array $pieces, float $width, string $po): void
    {
        foreach ($pieces as $p) {
            $this->assertGreaterThanOrEqual(0, $p['x'], "'{$p['text']}' starts off the page");
            $this->assertLessThanOrEqual($width, $p['right'], "'{$p['text']}' runs off the right of the page (PO: {$po})");
        }
    }

    public static function longPoNumbers(): array
    {
        return [
            '100 characters with no spaces' => [substr(str_repeat('PO-4500123456/', 8), 0, 100)],
            '100 characters with spaces' => [substr('PO 4500123456 / Cost centre MKT-EVENTS-2026 / Acme Awards Leeds / Show crew, rigging and get-out work', 0, 100)],
            // No space, dash or slash to wrap at, in the widest letter: only a break mid-word keeps this on the page.
            '100 characters with nothing to break at' => [str_repeat('W', 100)],
        ];
    }

    #[DataProvider('longPoNumbers')]
    public function test_an_issued_invoice_with_a_100_character_po_number_shows_all_of_it_and_keeps_the_dates_on_the_page(string $po): void
    {
        $this->assertSame(100, mb_strlen($po));
        $invoice = $this->invoiceWithPo($po);
        $this->assertSame('2026-03-12', $invoice->issue_date->format('Y-m-d'));

        [$pieces, $width] = $this->textOnPage($this->pdfOf($invoice));
        $texts = array_column($pieces, 'text');

        $this->assertContains('12 Mar 2026', $texts, 'The issue date is on the invoice');
        $this->assertContains('11 Apr 2026', $texts, 'The due date is on the invoice');
        // All of it, wrapped over as many lines as it needs.
        $this->assertStringContainsString(preg_replace('/\s+/', '', $po), preg_replace('/\s+/', '', implode('', $texts)));
        $this->assertAllOnThePage($pieces, $width, $po);
        // The dates sit in the right-hand half, beside "Bill to", not pushed past it.
        foreach (['12 Mar 2026', '11 Apr 2026'] as $date) {
            $this->assertGreaterThan($width / 2, $pieces[array_search($date, $texts, true)]['x']);
        }
    }

    public function test_the_draft_preview_with_a_100_character_po_number_keeps_everything_on_the_page(): void
    {
        $po = self::longPoNumbers()['100 characters with spaces'][0];
        [$pieces, $width] = $this->textOnPage($this->pdfOf($this->invoiceWithPo($po, issue: false)));

        $this->assertStringContainsString(preg_replace('/\s+/', '', $po), preg_replace('/\s+/', '', implode('', array_column($pieces, 'text'))));
        $this->assertAllOnThePage($pieces, $width, $po);
    }

    public function test_a_short_po_number_still_sits_on_one_line(): void
    {
        [$pieces, $width] = $this->textOnPage($this->pdfOf($this->invoiceWithPo('PO-4512')));
        $texts = array_column($pieces, 'text');

        $this->assertContains('PO-4512', $texts);
        $this->assertContains('12 Mar 2026', $texts);
        $this->assertContains('11 Apr 2026', $texts);
        $this->assertAllOnThePage($pieces, $width, 'PO-4512');
    }
}
