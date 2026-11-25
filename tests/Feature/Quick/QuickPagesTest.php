<?php

namespace Tests\Feature\Quick;

use App\Domain\Quick\ReceiptAttacher;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Where the app's own pages show what came from the phone: the Inbox card's receipt link, the drawer's photo with
 * "Not this payment's receipt", "Remove this expense" and "It was the business card", the job page's waiting receipts
 * and "Add expense for this job".
 */
class QuickPagesTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
        $this->needsIntegration();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    /** A business-card receipt with its photo, kept from the phone. */
    private function cardReceipt(array $over = []): QuickEntry
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['paid_with' => 'business', 'account_id' => $this->starling->id, 'photo' => $photo] + $over);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();

        return $this->entry($f['client_key']);
    }

    public function test_the_inbox_card_links_the_receipt_photo_on_its_line(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->cardReceipt();
        $this->assertSame(['attached', $line->id], [$e->status, (int) $e->bank_transaction_id]);

        $this->get('/inbox')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('inbox', false)
            ->where('items.0.id', $line->id)
            ->where('items.0.receipt.quick_id', $e->id)
            ->where('items.0.receipt.document_id', (int) $e->document_id)
            ->where('items.0.receipt.href', '/quick/entries/'.$e->id));
    }

    public function test_a_line_with_no_receipt_photo_says_so_in_the_inbox(): void
    {
        $this->line('2026-11-25', -990, 'TESCO');

        $this->get('/inbox')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('inbox', false)
            ->where('items.0.receipt', null));
    }

    public function test_the_drawer_offers_not_this_payments_receipt_and_it_waits_again(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->cardReceipt();

        $links = $this->getJson('/items/bank/'.$line->id)->assertOk()->json('links');
        $photo = collect($links)->firstWhere('quick_id', $e->id);
        $this->assertSame(['/quick/entries/'.$e->id.'/detach', '/documents/'.$e->document_id.'/file', []],
            [$photo['detach_href'], $photo['file'], $photo['actions']]);

        $this->post($photo['detach_href'])->assertSessionHas('success');

        $this->assertSame(['waiting', null], [$e->fresh()->status, $e->fresh()->bank_transaction_id]);
        $this->assertSame([], $line->fresh()->allocations()->get()->all(), 'the receipt’s suggestion is taken back');
    }

    public function test_the_drawer_removes_a_phone_expense_only_with_the_buttons_fingerprint(): void
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        $expense = ManualExpense::query()->sole();

        $links = $this->getJson('/items/manual/'.$expense->id)->assertOk()->json('links');
        $actions = collect(collect($links)->firstWhere('type', 'receipt')['actions'])->keyBy('label');
        $this->assertSame(['Remove this expense', 'It was the business card'], $actions->keys()->all());
        $remove = $actions['Remove this expense'];

        $this->post('/todo/'.$remove['code'].'/'.$remove['id'], [])->assertSessionHas('error');
        $this->assertNotNull($expense->fresh(), 'a press without its fingerprint is refused');

        $this->post('/todo/'.$remove['code'].'/'.$remove['id'], ['fp' => $remove['fp']])->assertSessionHas('success');

        $this->assertNull($expense->fresh());
        $this->assertSame('withdrawn', $this->entry($f['client_key'])->status);
    }

    public function test_the_job_page_lists_its_receipts_still_waiting_for_their_bank_line(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);
        $e = $this->cardReceipt(['work_job_id' => $job->id, 'job_source' => 'you']);
        $this->assertSame('waiting', $e->status);

        $this->get('/jobs/'.$job->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('jobs/show', false)
            ->has('quick_waiting', 1)
            ->where('quick_waiting.0.id', $e->id)
            ->where('quick_waiting.0.words', '£12.50 parking')
            ->where('quick_waiting.0.href', '/quick/entries/'.$e->id));

        $this->line('2026-11-26', -1250, 'NCP LEEDS');
        app(ReceiptAttacher::class)->sweep();
        $this->assertSame('attached', $e->fresh()->status);

        $this->get('/jobs/'.$job->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('jobs/show', false)
            ->has('quick_waiting', 0));
    }
}
