<?php

namespace Tests\Feature\Invoices;

use App\Domain\Invoices\InvoiceService;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Build slice S1: invoice lines and invoices as stored.
 *  - A line can be written or deleted only while its invoice's stored row says draft, whatever a model in memory
 *    says: issued, paid and void invoices keep their lines exactly.
 *  - Invoice::lines() is the ticked lines (what's charged: the PDF, the total, issuing); allLines() is every line.
 *  - Income v3's invoice guard: closing (closed_*) only while issued; replaced_by_id only on a void invoice, or in the
 *    save that voids it.
 */
class InvoiceLineGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FIXED = 'Lines of an issued invoice can’t change. Void it and issue a new one.';

    protected function setUp(): void
    {
        parent::setUp();
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Storage::fake('local');
    }

    public function test_lines_change_only_while_the_invoice_is_a_draft(): void
    {
        $invoice = $this->draft();
        $fee = $this->line($invoice, 'Arena load-in (3 Jun 2026)', 65000);
        $taxi = $this->line($invoice, 'Taxi', 1820, sort: 1);
        $fee->update(['description' => 'Arena load-in, 3 June']);
        $taxi->delete();
        $this->line($invoice, 'Parking', 1200, sort: 1);

        $service = app(InvoiceService::class);
        $issued = $service->issue($invoice->fresh(), '2026-06-05');
        $before = $this->stored($issued);

        foreach (['issued' => $issued, 'void' => null, 'paid' => null] as $status => $subject) {
            if ($status === 'void') {
                $service->void($issued->fresh(), 'Wrong PO number');
            }
            if ($status === 'paid') {
                // A legacy 'paid' invoice, as the release before could leave one.
                $subject = $this->draft();
                $this->line($subject, 'Show day', 50000);
                $subject->update(['status' => 'issued']);
                $subject->update(['status' => 'paid']);
            }
            $subject = ($subject ?? $issued)->fresh();
            $line = $subject->allLines()->firstOrFail();
            $snapshot = $this->stored($subject);

            $this->assertFixed(fn () => $line->update(['description' => 'Changed']), "{$status}: change a line");
            $this->assertFixed(fn () => $line->forceFill(['included' => false])->save(), "{$status}: untick a line");
            $this->assertFixed(fn () => $line->delete(), "{$status}: delete a line");
            $this->assertFixed(fn () => $subject->allLines()->create(['description' => 'Extra', 'quantity' => 1, 'unit_price_minor' => 100, 'amount_minor' => 100]), "{$status}: add a line");
            $this->assertSame($snapshot, $this->stored($subject), "{$status}: nothing changed");
        }
        $this->assertSame($before, $this->stored($issued), 'The issued lines are as they were issued');
    }

    public function test_the_guard_reads_the_stored_status_not_the_model_in_memory(): void
    {
        $invoice = $this->draft();
        $this->line($invoice, 'Arena load-in (3 Jun 2026)', 65000);

        // A page loaded before the invoice was issued elsewhere can't change its lines.
        $stale = Invoice::query()->findOrFail($invoice->id);
        app(InvoiceService::class)->issue($invoice->fresh(), '2026-06-05');
        $this->assertSame('draft', $stale->status);
        $this->assertFixed(fn () => $stale->allLines()->create(['description' => 'Late taxi', 'quantity' => 1, 'unit_price_minor' => 1820, 'amount_minor' => 1820]), 'stale draft');

        // A draft whose status is changed in memory only is still a draft.
        $draft = $this->draft();
        $draft->status = 'issued';
        $line = $this->line($draft, 'Show day', 50000);
        $this->assertSame(1, $draft->allLines()->count());

        // Moving a line off an issued invoice changes that invoice too.
        $issuedLine = $stale->allLines()->firstOrFail();
        $this->assertFixed(fn () => $issuedLine->update(['invoice_id' => $draft->id]), 'move a line off an issued invoice');
        $this->assertFixed(fn () => $line->update(['invoice_id' => $stale->id]), 'move a line onto an issued invoice');
    }

    /**
     * Stage 1 check: a draft page loaded before the invoice was issued elsewhere can't save the invoice (its lines
     * removed, its total set to 0) or issue it again: the stored status decides, under a lock.
     */
    public function test_a_draft_loaded_before_it_was_issued_can_neither_save_nor_issue_it(): void
    {
        $invoice = $this->draft();
        $this->line($invoice, 'Arena load-in (3 Jun 2026)', 65000);
        $stale = Invoice::query()->findOrFail($invoice->id);
        $service = app(InvoiceService::class);
        $issued = $service->issue($invoice->fresh(), '2026-06-05');
        $before = $this->stored($issued);
        $refused = function (callable $do): ?string {
            try {
                $do();
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return null;
        };

        $says = 'This invoice has been issued and can’t be changed. Void it and issue a new one.';
        $this->assertSame($says, $refused(fn () => $service->updateDraft($stale, [], [])), 'saved with no lines');
        $this->assertSame($says, $refused(fn () => $service->updateDraft($stale, ['bill_to_name' => 'Beta Crew'], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '500.00']])));
        $this->assertSame($says, $refused(fn () => $service->issue($stale, '2026-06-06')), 'issued again');
        $after = $issued->fresh();
        $this->assertSame([$before, 65000, 'Acme Events', $issued->number, '2026-06-05'],
            [$this->stored($after), $after->total_minor, $after->bill_to_name, $after->number, $after->issue_date->format('Y-m-d')]);

        // The model's own guard reads the stored status too.
        $this->assertGuard(fn () => $stale->forceFill(['total_minor' => 0])->save(), 'Issued invoices can\'t be edited (total_minor). Void it and issue a new one.');
        $this->assertSame(65000, $issued->fresh()->total_minor);
    }

    public function test_lines_are_the_ticked_lines_and_all_lines_every_line(): void
    {
        $invoice = $this->draft();
        $this->line($invoice, 'Taxi', 1820, sort: 2);
        $this->line($invoice, 'Arena load-in (3 Jun 2026)', 65000);
        $this->line($invoice, 'Hotel (left off)', 12000, sort: 1, included: false);

        $fresh = $invoice->fresh(['lines', 'allLines']);
        $this->assertSame(['Arena load-in (3 Jun 2026)', 'Taxi'], $fresh->lines->pluck('description')->all());
        $this->assertSame(['Arena load-in (3 Jun 2026)', 'Hotel (left off)', 'Taxi'], $fresh->allLines->pluck('description')->all());
        $this->assertSame(66820, (int) $invoice->lines()->sum('amount_minor'));

        // A line made with none of the new fields reads as a ticked typed line with nothing of yours changed.
        $typed = $invoice->allLines()->where('description', 'Taxi')->sole();
        $this->assertSame([true, null, null, false, false, null, null], [$typed->included, $typed->source, $typed->source_ref, $typed->own_text, $typed->own_amount, $typed->ticked_by, $typed->flag]);

        // The PDF shows only what's charged.
        $html = view('pdf.invoice', ['invoice' => $invoice->fresh('lines'), 'business' => ['name' => 'Alex Smith', 'address' => null, 'email' => null, 'bank' => null]])->render();
        $this->assertStringContainsString('Arena load-in (3 Jun 2026)', $html);
        $this->assertStringNotContainsString('Hotel (left off)', $html);

        // Nothing ticked is nothing to issue, whatever the stored total says.
        $only = $this->draft();
        $this->line($only, 'Hotel (left off)', 12000, included: false);
        $only->update(['total_minor' => 12000]);
        try {
            app(InvoiceService::class)->issue($only->fresh(), '2026-06-05');
            $this->fail('Issued with no ticked line');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Add at least one line before issuing.', $e->getMessage());
        }
        $this->assertSame('draft', $only->fresh()->status);
    }

    public function test_a_line_only_takes_known_sources_flags_and_your_tick(): void
    {
        $invoice = $this->draft();
        $ok = $invoice->allLines()->create(['description' => 'Taxi 13 Mar', 'quantity' => 1, 'unit_price_minor' => 1820, 'amount_minor' => 1820,
            'source' => 'cost', 'source_ref' => 'tx:381#j45', 'source_job_id' => 45, 'ticked_by' => 'you', 'own_text' => true, 'flag' => 'maybe_same',
            'source_amount_minor' => 1820, 'source_quantity' => '1.5']);
        $ok = $ok->fresh();
        $this->assertSame(['cost', 'tx:381#j45', 45, 'you', true, false, 'maybe_same', 1820, '1.50'],
            [$ok->source, $ok->source_ref, $ok->source_job_id, $ok->ticked_by, $ok->own_text, $ok->own_amount, $ok->flag, $ok->source_amount_minor, $ok->source_quantity]);

        foreach ([['source' => 'magic'], ['flag' => 'odd'], ['ticked_by' => 'policy']] as $bad) {
            try {
                $ok->update($bad);
                $this->fail('Took '.json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame('cost', $ok->fresh()->source);
    }

    public function test_closing_is_only_for_an_issued_invoice_and_a_replacement_only_for_a_void_one(): void
    {
        $service = app(InvoiceService::class);
        $draft = $this->draft();
        $this->line($draft, 'Show day', 50000);
        $this->assertGuard(fn () => $draft->fresh()->update(['closed_minor' => 1000, 'closed_reason' => 'short_paid', 'closed_at' => now()]), 'Only an issued invoice can be closed or reopened.');
        $this->assertGuard(fn () => $draft->fresh()->update(['replaced_by_id' => 99]), 'Only a void invoice can say which invoice replaced it.');

        $issued = $service->issue($draft->fresh(), '2026-06-05');
        $issued->update(['closed_minor' => 1000, 'closed_reason' => 'short_paid', 'closed_at' => '2026-07-10 10:00:00']);
        $this->assertSame([1000, 'short_paid', '2026-07-10'], [$issued->fresh()->closed_minor, $issued->fresh()->closed_reason, $issued->fresh()->closed_at->format('Y-m-d')]);
        $issued->update(['closed_minor' => null, 'closed_reason' => null, 'closed_at' => null]); // reopened
        $this->assertNull($issued->fresh()->closed_minor);
        $this->assertGuard(fn () => $issued->fresh()->update(['closed_minor' => 500, 'closed_reason' => 'because']), "An invoice can't be closed for 'because'.");
        $this->assertGuard(fn () => $issued->fresh()->update(['replaced_by_id' => 99]), 'Only a void invoice can say which invoice replaced it.');
        $this->assertGuard(fn () => $issued->fresh()->update(['total_minor' => 1]), "Issued invoices can't be edited (total_minor). Void it and issue a new one.");

        $next = $this->draft();
        $this->line($next, 'Show day', 50000);
        $next = $service->issue($next->fresh(), '2026-06-06');

        // Voided and pointed at its replacement in one save; or voided first and pointed later.
        $issued->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong amount', 'replaced_by_id' => $next->id]);
        $void = $issued->fresh();
        $this->assertSame($next->id, $void->replaced_by_id);
        $this->assertSame($next->id, $void->replacedBy->id);
        $void->update(['replaced_by_id' => null]);
        $void->update(['replaced_by_id' => $next->id]);
        $this->assertSame($next->id, $void->fresh()->replaced_by_id);
        $this->assertGuard(fn () => $void->fresh()->update(['closed_minor' => 1]), 'Only an issued invoice can be closed or reopened.');

        // A legacy 'paid' invoice is neither closed nor replaced.
        $paid = $this->draft();
        $this->line($paid, 'Show day', 50000);
        $paid->update(['status' => 'issued']);
        $paid->update(['status' => 'paid']);
        $this->assertGuard(fn () => $paid->fresh()->update(['closed_minor' => 1, 'closed_reason' => 'other']), 'Only an issued invoice can be closed or reopened.');
        $this->assertGuard(fn () => $paid->fresh()->update(['replaced_by_id' => $next->id]), 'Only a void invoice can say which invoice replaced it.');
    }

    private function draft(): Invoice
    {
        return Invoice::query()->create(['status' => 'draft', 'bill_to_name' => 'Acme Events', 'total_minor' => 0, 'notes' => 'Payment due within 30 days.']);
    }

    private function line(Invoice $invoice, string $description, int $amount, int $sort = 0, bool $included = true): InvoiceLine
    {
        $line = $invoice->allLines()->create(['description' => $description, 'quantity' => 1, 'unit_price_minor' => $amount, 'amount_minor' => $amount, 'sort' => $sort, 'included' => $included]);
        // As InvoiceService does: the total is what the ticked lines charge.
        Invoice::query()->whereKey($invoice->id)->where('status', 'draft')->update(['total_minor' => (int) $invoice->lines()->sum('amount_minor')]);

        return $line;
    }

    /** @return list<array<string, mixed>> */
    private function stored(Invoice $invoice): array
    {
        return InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => [$l->id, $l->description, (string) $l->quantity, $l->unit_price_minor, $l->amount_minor, $l->sort, $l->included])->all();
    }

    private function assertFixed(callable $write, string $case): void
    {
        try {
            $write();
        } catch (LogicException $e) {
            $this->assertSame(self::FIXED, $e->getMessage(), $case);

            return;
        }
        $this->fail("Not refused: {$case}");
    }

    private function assertGuard(callable $write, string $message): void
    {
        try {
            $write();
        } catch (LogicException $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail("Not refused: {$message}");
    }
}
