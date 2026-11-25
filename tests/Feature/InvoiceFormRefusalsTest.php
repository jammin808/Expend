<?php

namespace Tests\Feature;

use App\Domain\Invoices\InvoiceDraftSync;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The invoice page (resources/js/pages/invoices/show.tsx): the draft form, and the Issue, Email, Void and Delete
 * buttons. When the server refuses one, the page shows why: under the email and PO number boxes, or as a message,
 * in plain words rather than Laravel's "The lines.1.description field is required."
 */
class InvoiceFormRefusalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('business_name', 'Alex Smith');
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('invoice_terms_days', 30);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
    }

    /** Sent the way the page sends it: JSON from Inertia, back to the page it came from. */
    private function inertia(string $method, string $uri, array $body, string $from): TestResponse
    {
        return $this->from($from)->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    private function draft(): Invoice
    {
        $invoice = Invoice::query()->create(['status' => 'draft', 'currency' => 'GBP', 'bill_to_name' => 'Acme Events Ltd', 'notes' => 'Payment due within 30 days.']);
        $this->inertia('PATCH', "/invoices/{$invoice->id}", $this->form(), "/invoices/{$invoice->id}")->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    /**
     * The draft form as the page fills it (useForm in invoices/show.tsx), with one line. Ported to the per-line form
     * (build slice S9): a line the draft has carries its id, the version the page showed and its tick; a new line has
     * none, and the form carries the lines version it opened with.
     */
    private function form(array $overrides = [], array $lines = []): array
    {
        $invoice = Invoice::query()->latest('id')->first();
        $first = $invoice ? $invoice->allLines()->orderBy('sort')->orderBy('id')->first() : null;
        $line = $first
            ? ['id' => $first->id, 'version' => InvoiceDraftSync::version($first), 'description' => 'Show day technician', 'quantity' => '1', 'unit_price' => '450.00', 'source_quote' => null, 'included' => true]
            : ['id' => null, 'version' => null, 'description' => 'Show day technician', 'quantity' => '1', 'unit_price' => '450.00', 'source_quote' => null, 'included' => true];

        return $overrides + [
            'lines_version' => $invoice ? InvoiceDraftSync::linesVersion($invoice) : null,
            'bill_to_name' => 'Acme Events Ltd', 'bill_to_address' => "1 High Street\nLeeds LS1 1AA", 'bill_to_email' => 'accounts@acme.test',
            'po_number' => 'PO-4512', 'currency' => 'GBP', 'notes' => 'Payment due within 30 days.', 'work_job_id' => '', 'client_id' => '',
            'lines' => [$line, ...array_map(fn (array $l) => $l + ['id' => null, 'version' => null, 'included' => true], $lines)],
        ];
    }

    private function save(Invoice $invoice, array $form): TestResponse
    {
        return $this->inertia('PATCH', "/invoices/{$invoice->id}", $form, "/invoices/{$invoice->id}")->assertRedirect("/invoices/{$invoice->id}");
    }

    private function issued(): Invoice
    {
        $invoice = $this->draft();
        $this->inertia('POST', "/invoices/{$invoice->id}/issue", [], "/invoices/{$invoice->id}")->assertSessionHas('success');

        return $invoice->fresh();
    }

    public function test_voiding_with_too_short_too_long_or_no_reason_says_so_plainly_and_changes_nothing(): void
    {
        $invoice = $this->issued();
        $page = "/invoices/{$invoice->id}";

        $this->inertia('POST', "{$page}/void", ['reason' => 'no'], $page)->assertRedirect($page)
            ->assertSessionHasErrors(['reason' => 'Say why you are voiding this invoice in a few more words (3 characters at least).']);
        // OK pressed with nothing typed, or with spaces only (which reach the server empty).
        foreach (['', '   '] as $nothing) {
            $this->inertia('POST', "{$page}/void", ['reason' => $nothing], $page)
                ->assertSessionHasErrors(['reason' => 'Say why you are voiding this invoice, in a few words.']);
        }
        $this->inertia('POST', "{$page}/void", ['reason' => str_repeat('a', 251)], $page)
            ->assertSessionHasErrors(['reason' => 'The reason is too long (250 characters at most).']);

        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertSame(0, AuditEvent::query()->where('action', 'invoice.voided')->count());

        $this->inertia('POST', "{$page}/void", ['reason' => 'Wrong PO number'], $page)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame('Wrong PO number', AuditEvent::query()->where('action', 'invoice.voided')->sole()->reason);
    }

    public function test_a_line_added_and_left_empty_says_which_line_and_what_it_needs(): void
    {
        $invoice = $this->draft();
        // "Add line" gives { description: '', quantity: '1', unit_price: '', source_quote: null }.
        $this->save($invoice, $this->form([], [['description' => '', 'quantity' => '1', 'unit_price' => '', 'source_quote' => null]]))
            ->assertSessionHasErrors([
                'lines.1.description' => 'Line 2 needs a description.',
                'lines.1.unit_price' => 'Line 2 needs a price, like 450.00.',
            ]);
        $this->assertSame(1, $invoice->allLines()->count());
    }

    public function test_a_quantity_that_is_missing_not_a_number_or_zero_says_so_plainly(): void
    {
        $invoice = $this->draft();
        $line = fn (string $qty) => [['description' => 'Get-out', 'quantity' => $qty, 'unit_price' => '150.00', 'source_quote' => null]];

        $this->save($invoice, $this->form([], $line('')))->assertSessionHasErrors(['lines.1.quantity' => 'Line 2 needs a quantity, like 1 or 0.5.']);
        $this->save($invoice, $this->form([], $line('one')))->assertSessionHasErrors(['lines.1.quantity' => 'The quantity on line 2 must be a number, like 1 or 0.5.']);
        $this->save($invoice, $this->form([], $line('0')))->assertSessionHasErrors(['lines.1.quantity' => 'The quantity on line 2 must be 0.01 or more.']);
        $this->assertSame(1, $invoice->allLines()->count());
    }

    public function test_text_that_is_too_long_says_which_box_and_the_limit(): void
    {
        $invoice = $this->draft();

        $this->save($invoice, $this->form(['bill_to_name' => str_repeat('a', 201)]))
            ->assertSessionHasErrors(['bill_to_name' => '“Bill to” is too long (200 characters at most).']);
        $this->save($invoice, $this->form(['bill_to_address' => str_repeat('a', 1001)]))
            ->assertSessionHasErrors(['bill_to_address' => 'The address is too long (1,000 characters at most).']);
        $this->save($invoice, $this->form(['notes' => str_repeat('a', 2001)]))
            ->assertSessionHasErrors(['notes' => 'The notes are too long (2,000 characters at most).']);
        $this->save($invoice, $this->form([], [['description' => str_repeat('a', 501), 'quantity' => '1', 'unit_price' => '1.00', 'source_quote' => null]]))
            ->assertSessionHasErrors(['lines.1.description' => 'The description on line 2 is too long (500 characters at most).']);
        $this->save($invoice, $this->form(['po_number' => str_repeat('P', 101)]))
            ->assertSessionHasErrors(['po_number' => 'The PO number is too long (100 characters at most).']);

        $this->assertSame('Acme Events Ltd', $invoice->fresh()->bill_to_name);
    }

    public function test_a_job_or_client_deleted_since_the_page_opened_says_so_plainly(): void
    {
        $invoice = $this->draft();

        $this->save($invoice, $this->form(['work_job_id' => '999']))
            ->assertSessionHasErrors(['work_job_id' => 'That job no longer exists. Choose another, or None.']);
        $this->save($invoice, $this->form(['client_id' => '999']))
            ->assertSessionHasErrors(['client_id' => 'That client no longer exists. Choose another, or None.']);
    }

    public function test_a_line_changed_since_the_page_opened_or_one_from_the_job_left_out_says_so_plainly(): void
    {
        $invoice = $this->draft();
        $stale = $this->form();
        // Changed in another tab since this page opened: the save is refused, nothing changes.
        $line = $invoice->allLines()->first();
        $line->forceFill(['description' => 'Changed in another tab'])->save();
        $this->save($invoice, $stale)->assertSessionHas('error', 'The draft’s lines changed since you opened it: check them and save again.');
        $this->assertSame('Changed in another tab', $line->fresh()->description);

        // A line from the job can't be left out of the form: untick it instead.
        $line->forceFill(['source' => 'fee', 'source_ref' => 'job:1#fee'])->save();
        $typed = $invoice->allLines()->create(['description' => 'Get-out', 'quantity' => '1', 'unit_price_minor' => 15000, 'amount_minor' => 15000, 'sort' => 1]);
        $form = $this->form();
        $form['lines'] = [['id' => $typed->id, 'version' => InvoiceDraftSync::version($typed->fresh()), 'description' => 'Get-out', 'quantity' => '1', 'unit_price' => '150.00',
            'source_quote' => null, 'included' => true]];
        $this->save($invoice, $form)->assertSessionHas('error', '“Changed in another tab” comes from the job: untick it instead of removing it.');
        $this->assertSame(2, $invoice->allLines()->count());

        // Unticked on the page: kept on the draft, left off the invoice, as yours.
        $form = $this->form();
        $form['lines'][0]['description'] = 'Changed in another tab';
        $form['lines'][0]['included'] = false;
        $form['lines'][] = ['id' => $typed->id, 'version' => InvoiceDraftSync::version($typed->fresh()), 'description' => 'Get-out', 'quantity' => '1', 'unit_price' => '150.00',
            'source_quote' => null, 'included' => true];
        $this->save($invoice, $form)->assertSessionHas('success', 'Draft saved.');
        $this->assertSame([false, 'you'], [(bool) $line->fresh()->included, $line->fresh()->ticked_by]);
        $this->assertSame(15000, $invoice->fresh()->total_minor);
    }

    public function test_the_invoice_page_shows_every_refusal(): void
    {
        $source = (string) file_get_contents(resource_path('js/pages/invoices/show.tsx'));

        // The PO number's own error sits under its box, like the email's.
        $this->assertStringContainsString('aria-invalid={!!form.errors.po_number}', $source);
        $this->assertStringContainsString('{form.errors.po_number && <p className="text-sm text-rose-600">{form.errors.po_number}</p>}', $source);

        // Issue, Email, Void and Delete each say why when the server refuses.
        $calls = [...$this->calls($source, 'router.post('), ...$this->calls($source, 'router.delete('), ...$this->calls($source, 'router.patch('),
            ...$this->calls($source, 'router.put(')];
        $this->assertCount(4, $calls);
        foreach ($calls as $call) {
            $this->assertStringContainsString('onError', $call, $call);
        }
        $void = collect($calls)->first(fn ($c) => str_contains($c, '/void`'));
        $this->assertNotNull($void);
        $this->assertStringContainsString('errors.reason', $void);
        // Only Cancel (null) skips the request: OK with an empty box goes to the server and gets its "Say why" message.
        $this->assertMatchesRegularExpression('/window\.prompt\(\'Why are you voiding this invoice\?\'\);\s*(\/\/[^\n]*\s*)?if \(reason !== null\)\s*router\.post\(\s*`\/invoices\/\$\{invoice\.id\}\/void`/', $source);
    }

    /** Every call to $start in $source, from its name to its closing bracket (strings skipped). */
    private function calls(string $source, string $start): array
    {
        $found = [];
        $offset = 0;
        while (($at = strpos($source, $start, $offset)) !== false) {
            $depth = 0;
            $quote = null;
            for ($i = strpos($source, '(', $at); $i < strlen($source); $i++) {
                $c = $source[$i];
                if ($quote !== null) {
                    if ($c === '\\') {
                        $i++;
                    } elseif ($c === $quote) {
                        $quote = null;
                    }

                    continue;
                }
                if (in_array($c, ["'", '"', '`'], true)) {
                    $quote = $c;
                } elseif ($c === '(') {
                    $depth++;
                } elseif ($c === ')' && --$depth === 0) {
                    break;
                }
            }
            $found[] = substr($source, $at, $i - $at + 1);
            $offset = $i;
        }

        return $found;
    }
}
