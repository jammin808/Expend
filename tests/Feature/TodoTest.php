<?php

namespace Tests\Feature;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoList;
use App\Mail\InvoiceEmail;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
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
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        $this->actingAs(User::factory()->create());
    }

    /**
     * Issued on the given day, due 30 days later; sent that day when $sent (S10: an invoice not sent is listed to send,
     * never as overdue).
     */
    private function issuedInvoice(string $issued, ?string $email = 'accounts@client.example', bool $sent = false): Invoice
    {
        $job = WorkJob::query()->create(['title' => 'Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Client Ltd', 'bill_to_email' => $email]);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);
        $invoice = app(InvoiceService::class)->issue($invoice->fresh(), $issued)->fresh();
        if ($sent) {
            $invoice->forceFill(['sent_at' => $issued.' 10:00:00', 'sent_to' => $email])->save();
        }

        return $invoice->fresh();
    }

    public function test_one_list_most_urgent_first_with_one_tap_actions(): void
    {
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($account, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));
        $overdue = $this->issuedInvoice('2025-05-15', sent: true);
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
        $shown = Fingerprints::of('cand_add', (string) $candidate->id);
        $this->post("/todo/cand_add/{$candidate->id}", ['fp' => $shown])->assertSessionHas('success');
        $this->assertSame('Maybe a job', WorkJob::query()->latest('id')->value('title'));
        $tx = BankTransaction::query()->where('amount_pence', '<', 0)->where('review_status', '!=', 'confirmed')->first();
        app(AllocationWriter::class)->clear($tx);
        $this->post("/todo/tx_personal/{$tx->id}", ['fp' => Fingerprints::of('tx_personal', (string) $tx->id)])->assertSessionHas('success');
        $this->assertSame('personal', $tx->fresh()->allocations()->sole()->kind);
        $this->post("/todo/cand_add/{$candidate->id}", ['fp' => $shown])->assertSessionHas('error', 'That’s already been dealt with.');
        $this->post('/todo/drop_tables/1')->assertNotFound();

        // An accepted suggestion is confirmed.
        $other = BankTransaction::query()->where('amount_pence', '<', 0)->where('review_status', '!=', 'confirmed')->where('id', '!=', $tx->id)->first();
        app(AllocationWriter::class)->decide($other, AllocationWriter::lines($other->amount_pence, 'business_expense', Category::query()->where('code', 'software')->value('id')), 'rule');
        $this->post("/todo/tx_accept/{$other->id}", ['fp' => Fingerprints::of('tx_accept', (string) $other->id)])->assertSessionHas('success');
        $this->assertNotNull($other->fresh()->allocations()->sole()->confirmed_at);

        // Chasing the overdue invoice emails a reminder with the PDF.
        Mail::fake();
        $this->post("/todo/inv_chase/{$overdue->id}", ['fp' => Fingerprints::of('inv_chase', (string) $overdue->id)])->assertSessionHas('success');
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
        // The invoice nudges keep quiet at night (21:00 to 08:00 London): a morning, as they're scheduled.
        $this->travelTo(CarbonImmutable::parse('2025-07-14 09:13:00', 'Europe/London'));
        $invoice = $this->issuedInvoice('2025-05-15', sent: true);
        $candidate = JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);

        $this->artisan('ledger:telegram-digest')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'setWebhook') && in_array('callback_query', $r['allowed_updates'], true));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], 'Maybe a job')
            && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === Telegram::callbackData('cand_add', (string) $candidate->id));

        // A press from your chat acts; from anyone else it doesn't.
        $press = fn (string $chat, string $data) => $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret')->postJson('/hooks/telegram', ['callback_query' => [
            'id' => 'q1', 'data' => $data, 'message' => ['message_id' => 7, 'chat' => ['id' => $chat], 'text' => 'Possible job: Maybe a job'],
        ]])->assertOk();
        $dismiss = Telegram::callbackData('cand_dismiss', (string) $candidate->id);
        $press('99', $dismiss);
        $this->assertSame('open', $candidate->fresh()->status);
        $press('42', $dismiss);
        $this->assertSame('dismissed', $candidate->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], '✓ Dismissed'));

        $this->artisan('ledger:invoice-nudges')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], "Invoice {$invoice->number}") && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === Telegram::callbackData('inv_chase', (string) $invoice->id));
        $this->assertNotNull($invoice->fresh()->overdue_nudged_at);
    }

    public function test_money_you_may_be_owed_and_snoozing(): void
    {
        // A job still under way: a finished job's PO and recharges are its close-out instead (M4: JobCloseoutTest).
        $on = today()->addDays(5);
        $job = WorkJob::query()->create(['title' => 'Gala', 'starts_on' => $on->format('Y-m-d'), 'ends_on' => $on->copy()->addDays(500)->format('Y-m-d')]);
        $under = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Client', 'po_total_minor' => 180000]);
        app(InvoiceService::class)->updateDraft($under, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '1500.00']]);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => $on->copy()->subDay()->format('Y-m-d'), 'ends_on' => $on->format('Y-m-d'),
            'amount_minor' => 8900, 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'decision' => 'recharge', 'decided_at' => now()]);

        $list = TodoList::build();
        $keys = array_column($list['items'], 'key');
        $this->assertContains('underbilled:'.$under->id, $keys);
        $this->assertSame(30000, collect($list['items'])->firstWhere('key', 'underbilled:'.$under->id)['amount'], '£1,800 PO, £1,500 invoiced');
        $this->assertNotEmpty(preg_grep('/^recharge:/', $keys));

        // Snooze hides it for a while; "not relevant" until you undo it.
        $this->post('/todo/dismiss', ['key' => 'underbilled:'.$under->id, 'days' => 7])->assertSessionHas('success');
        $this->assertNotContains('underbilled:'.$under->id, array_column(TodoList::build()['items'], 'key'));
        $this->travel(8)->days();
        $this->assertContains('underbilled:'.$under->id, array_column(TodoList::build()['items'], 'key'));
        $this->post('/todo/dismiss', ['key' => 'underbilled:'.$under->id]);
        $this->travel(400)->days();
        $this->assertNotContains('underbilled:'.$under->id, array_column(TodoList::build()['items'], 'key'));
        $this->post('/todo/dismiss', ['key' => 'underbilled:'.$under->id, 'undo' => true]);
        $this->assertContains('underbilled:'.$under->id, array_column(TodoList::build()['items'], 'key'));
    }
}
