<?php

namespace Tests\Feature;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\TodoList;
use App\Mail\InvoiceEmail;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TodoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('business_name', 'Test Freelancer');
        $this->actingAs(User::factory()->create());
    }

    /** Issued on the given day, due 30 days later. */
    private function issuedInvoice(string $issued, ?string $email = 'accounts@client.example'): Invoice
    {
        $job = WorkJob::query()->create(['title' => 'Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Client Ltd', 'bill_to_email' => $email]);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        return app(InvoiceService::class)->issue($invoice->fresh(), $issued)->fresh();
    }

    public function test_one_list_most_urgent_first_with_one_tap_actions(): void
    {
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($account, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));
        $overdue = $this->issuedInvoice('2025-05-15');
        WorkJob::query()->create(['title' => 'Unbilled show', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-01']);
        JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);

        $list = TodoList::build();
        $this->assertSame('income', $list['items'][0]['group'], 'Unpaid invoices come first');
        $this->assertSame(['inv_chase'], array_column($list['items'][0]['actions'], 'code'));
        $this->assertGreaterThan(0, $list['counts']['payments']);
        $this->assertSame(1, $list['counts']['jobs']);
        $this->assertContains('Invoice Unbilled show', array_column($list['items'], 'title'));

        $this->get('/todo')->assertOk()->assertInertia(fn ($p) => $p->where('total', $list['total']));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('todo.total', $list['total']));

        // One tap: a possible job becomes a job; a payment is marked personal.
        $candidate = JobCandidate::query()->sole();
        $this->post("/todo/cand_add/{$candidate->id}")->assertSessionHas('success');
        $this->assertSame('Maybe a job', WorkJob::query()->latest('id')->value('title'));
        $tx = BankTransaction::query()->where('amount_pence', '<', 0)->whereDoesntHave('allocations')->first();
        $this->post("/todo/tx_personal/{$tx->id}")->assertSessionHas('success');
        $this->assertSame('personal', $tx->fresh()->allocations()->sole()->kind);
        $this->post("/todo/cand_add/{$candidate->id}")->assertSessionHas('error', 'That’s already been dealt with.');
        $this->post('/todo/drop_tables/1')->assertNotFound();

        // An accepted suggestion is confirmed.
        $other = BankTransaction::query()->where('amount_pence', '<', 0)->whereDoesntHave('allocations')->first();
        app(AllocationWriter::class)->decide($other, AllocationWriter::lines($other->amount_pence, 'business_expense', Category::query()->where('code', 'software')->value('id')), 'rule');
        $this->post("/todo/tx_accept/{$other->id}")->assertSessionHas('success');
        $this->assertNotNull($other->fresh()->allocations()->sole()->confirmed_at);

        // Chasing the overdue invoice emails a reminder with the PDF.
        Mail::fake();
        $this->post("/todo/inv_chase/{$overdue->id}")->assertSessionHas('success');
        Mail::assertQueued(InvoiceEmail::class, fn (InvoiceEmail $m) => $m->reminder && $m->hasTo('accounts@client.example'));
        $this->assertSame(1, $overdue->fresh()->reminder_count);
    }

    public function test_an_issued_invoice_can_be_emailed_from_its_page(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'), null);
        $this->assertContains('unsent:'.$invoice->id, array_column(TodoList::build()['items'], 'key'));

        $this->post("/invoices/{$invoice->id}/send", [])->assertSessionHas('error', fn ($m) => str_contains($m, 'email address'));
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'pay@client.example'])->assertSessionHas('success');

        Mail::assertQueued(InvoiceEmail::class, function (InvoiceEmail $m) use ($invoice) {
            $m->assertHasAttachedData((string) Storage::disk('local')->get($invoice->pdf_path), $invoice->number.'.pdf', ['mime' => 'application/pdf']);

            return ! $m->reminder && $m->hasTo('pay@client.example');
        });
        $this->assertSame('pay@client.example', $invoice->fresh()->sent_to);
        $this->assertNotContains('unsent:'.$invoice->id, array_column(TodoList::build()['items'], 'key'));
    }

    public function test_telegram_digest_buttons_and_overdue_nudges(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_webhook_secret', 'secret');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $invoice = $this->issuedInvoice('2025-05-15');
        $candidate = JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);

        $this->artisan('ledger:telegram-digest')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'setWebhook') && in_array('callback_query', $r['allowed_updates'], true));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'Maybe a job')
            && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 't|cand_add|'.$candidate->id);

        // A press from your chat acts; from anyone else it doesn't.
        $press = fn (string $chat, string $data) => $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret')->postJson('/hooks/telegram', ['callback_query' => [
            'id' => 'q1', 'data' => $data, 'message' => ['message_id' => 7, 'chat' => ['id' => $chat], 'text' => 'Possible job: Maybe a job'],
        ]])->assertOk();
        $press('99', 't|cand_dismiss|'.$candidate->id);
        $this->assertSame('open', $candidate->fresh()->status);
        $press('42', 't|cand_dismiss|'.$candidate->id);
        $this->assertSame('dismissed', $candidate->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], '✓ Dismissed'));

        $this->artisan('ledger:invoice-nudges')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], "Invoice {$invoice->number}") && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 't|inv_chase|'.$invoice->id);
        $this->assertNotNull($invoice->fresh()->overdue_nudged_at);
    }
}
