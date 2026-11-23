<?php

namespace Tests\Feature\Domain;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\ReturnSummary;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The financial core can't be corrupted by a bad statement, an undo, racing audit writes or float maths. */
class CoreHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function starling(): Account
    {
        return Account::query()->firstOrCreate(['name' => 'Starling Business'], ['kind' => 'bank', 'provider' => 'starling']);
    }

    private function import(Account $account, string $fixture)
    {
        $path = base_path("tests/fixtures/{$fixture}");

        return app(Importer::class)->commit($account, $path, $fixture, ProfileDetector::detect(CsvReader::read($path)['headers'], $account->kind));
    }

    private function all()
    {
        return BankTransaction::withoutGlobalScope('active');
    }

    public function test_a_statement_whose_balance_doesnt_add_up_is_held_and_counts_nowhere(): void
    {
        $batch = $this->import($this->starling(), 'starling_broken.csv');

        $this->assertSame(['quarantined', 'failed'], [$batch->status, $batch->balance_check]);
        $this->assertSame(0, BankTransaction::count(), 'Nothing reaches the Inbox, transactions or tax figures');
        $this->assertGreaterThan(0, $this->all()->where('state', 'quarantined')->count(), 'but the rows are kept for review');
        $this->assertSame(0, Allocation::query()->count(), 'and no suggestions are made');
    }

    public function test_a_held_statement_can_be_accepted_with_a_reason_or_discarded(): void
    {
        $this->actingAs(User::factory()->create());
        $held = $this->import($this->starling(), 'starling_broken.csv');
        $rows = $this->all()->where('import_batch_id', $held->id)->count();

        $this->post("/imports/{$held->id}/accept", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/imports/{$held->id}/accept", ['reason' => 'Checked against the PDF statement'])->assertSessionHas('success');
        $this->assertSame($rows, BankTransaction::query()->where('import_batch_id', $held->id)->count());
        $this->assertSame('committed', $held->fresh()->status);
        $this->assertStringContainsString('Checked against the PDF', $held->fresh()->review_note);
        $this->assertSame('import.accepted', AuditEvent::query()->latest('id')->value('action'));

        $other = Account::create(['name' => 'Other', 'kind' => 'bank', 'provider' => 'starling']);
        $second = $this->import($other, 'starling_broken.csv');
        $this->post("/imports/{$second->id}/discard", ['reason' => 'Wrong file'])->assertSessionHas('success');
        $this->assertSame(0, BankTransaction::query()->where('import_batch_id', $second->id)->count());
        $this->assertSame($rows, $this->all()->where('import_batch_id', $second->id)->where('state', 'reversed')->count(), 'Discarded rows are kept');
    }

    public function test_undoing_an_import_keeps_its_rows_and_decisions_and_restoring_brings_them_back(): void
    {
        $batch = $this->import($this->starling(), 'starling.csv');
        $tx = BankTransaction::query()->where('amount_pence', '<', 0)->first();
        $cat = Category::query()->where('code', 'software')->value('id');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_expense', $cat), 'you');
        $label = $tx->tax_year;
        $this->assertNotSame(0, ReturnSummary::for($label)['net_profit_pence']);

        app(Importer::class)->revert($batch, 'Imported into the wrong account');

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame(0, ReturnSummary::for($label)['net_profit_pence'], 'Undone rows count nowhere');
        $this->assertSame('reversed', $this->all()->find($tx->id)->state, 'but are kept');
        $this->assertNotNull(Allocation::withoutGlobalScope('not_voided')->where('bank_transaction_id', $tx->id)->value('voided_at'), 'and your decision is voided, not deleted');

        // The same statement can be imported again without clashing with the kept rows...
        $again = $this->import($this->starling(), 'starling.csv');
        $this->assertSame($batch->imported_count, $again->imported_count);
        app(Importer::class)->revert($again, 'Testing again');

        // ...and the original restored, decision and all.
        $this->assertSame($batch->imported_count, app(Importer::class)->restore($batch->fresh(), 'It was right after all'));
        $this->assertSame('business_expense', BankTransaction::query()->find($tx->id)->allocations()->sole()->kind);
        $this->assertNotSame(0, ReturnSummary::for($label)['net_profit_pence']);
    }

    public function test_restore_skips_rows_imported_again_since(): void
    {
        $batch = $this->import($this->starling(), 'starling.csv');
        app(Importer::class)->revert($batch, 'Undo');
        $this->import($this->starling(), 'starling.csv');

        $this->assertSame(0, app(Importer::class)->restore($batch->fresh(), 'Restore'));
        $this->assertSame($batch->imported_count, BankTransaction::count(), 'No duplicates');
    }

    public function test_audit_writes_chain_through_a_locked_head_and_a_deleted_tail_is_caught(): void
    {
        DB::transaction(function () {
            AuditLog::record('test.one', 'Test');
            DB::transaction(fn () => AuditLog::record('test.two', 'Test'));
        });
        AuditLog::record('test.three', 'Test');

        $events = AuditEvent::query()->orderBy('id')->get();
        $this->assertSame($events->last()->hash, DB::table('audit_heads')->value('hash'));
        foreach ($events->skip(1)->values() as $i => $event) {
            $this->assertSame($events[$i]->hash, $event->prev_hash, 'Each event chains onto the one before');
        }
        $this->assertTrue(AuditLog::verify()['ok']);

        DB::table('audit_events')->where('id', $events->last()->id)->delete();
        $this->assertFalse(AuditLog::verify()['ok'], 'Removing the newest event is detected');
    }

    public function test_invoice_lines_use_exact_decimal_arithmetic(): void
    {
        // 2.55 × £3.33 = £8.4915 exactly, which rounds to £8.49; 1.005 isn't a valid quantity (2 decimals at most).
        $this->assertSame(849, InvoiceService::lineAmount('2.55', 333));
        $this->assertSame(8333, InvoiceService::lineAmount('2.5', 3333), 'Half a penny rounds up');
        $this->assertSame(1001, InvoiceService::lineAmount('1.01', 991));
        $this->assertNull(InvoiceService::quantity('1.005'));
        $this->assertNull(InvoiceService::quantity('0'));
        $this->assertNull(InvoiceService::quantity('1e3'));

        $invoice = Invoice::query()->create(['status' => 'draft', 'total_minor' => 0]);
        app(InvoiceService::class)->updateDraft($invoice, [], [
            ['description' => 'Show day', 'quantity' => '2.55', 'unit_price' => '3.33'],
            ['description' => 'Prep', 'quantity' => '0.1', 'unit_price' => '0.07'],
        ]);
        $this->assertSame([849, 1], $invoice->fresh()->lines()->orderBy('sort')->pluck('amount_minor')->all());
        $this->assertSame(850, $invoice->fresh()->total_minor);
    }

    public function test_restoring_an_undone_card_statement_re_pairs_its_bill_payment(): void
    {
        $bank = $this->starling();
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->import($bank, 'starling.csv');
        $card = $this->import($amex, 'amex.csv');
        $out = BankTransaction::query()->where('description', 'like', '%AMEX PAYMENT%')->sole();
        $in = BankTransaction::query()->where('description', 'like', 'PAYMENT RECEIVED%')->sole();
        $this->assertSame($in->id, $out->transfer_pair_id);

        app(Importer::class)->revert($card, 'Wrong file');
        $this->assertNull($out->fresh()->transfer_pair_id, 'The bank side is freed');

        app(Importer::class)->restore($card->fresh(), 'Right file after all');
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id], 'Paired again');
    }
}
