<?php

namespace Tests\Feature;

use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Domain\Travel\TravelMatcher;
use App\Http\Controllers\InvoiceController;
use App\Jobs\ReadDocument;
use App\Mail\InvoiceEmail;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** Review fixes for the one-tap to-do actions, Telegram buttons and invoice emailing. */
class ReviewFixesTodoActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Queue worker options: run until the queue is empty. The worker stops once this process uses 128MB unless told
     * otherwise, and in the full suite it already does, so it would stop after one job; the limit is lifted.
     */
    private const WORKER = ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 0];

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

    /** Issued on the given day, due 30 days later. */
    private function issuedInvoice(string $issued, ?string $email = 'accounts@client.example'): Invoice
    {
        $job = WorkJob::query()->create(['title' => 'Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Client Ltd', 'bill_to_email' => $email]);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        return app(InvoiceService::class)->issue($invoice->fresh(), $issued)->fresh();
    }

    private function payment(string $date, int $pence, string $description): BankTransaction
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling Business'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'merchant_key' => strtoupper(strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $date.$pence.$description), 'occurrence' => 1,
        ]);
    }

    private function pairTelegram(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_webhook_secret', 'secret');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    /** A press on a button in a message Telegram says was sent at $sentAt (by default, now). */
    private function press(string $data, int $messageId = 7, string $queryId = 'q1', ?CarbonInterface $sentAt = null): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret')->postJson('/hooks/telegram', ['callback_query' => [
            'id' => $queryId, 'data' => $data,
            'message' => ['message_id' => $messageId, 'date' => ($sentAt ?? now())->getTimestamp(), 'chat' => ['id' => '42'], 'text' => 'To do'],
        ]])->assertOk();
    }

    /** Emails go through the database queue to a mail server that refuses them, like a wrong mailbox setting. */
    private function failingMailbox(): void
    {
        config(['queue.default' => 'database']);
        Setting::put('mail_method', 'smtp');
        Setting::put('smtp_host', '127.0.0.1');
        Setting::put('smtp_port', 1);
        Setting::put('smtp_username', 'me@business.example');
        Setting::put('smtp_password', 'wrong');
    }

    private function work(): void
    {
        $this->artisan('queue:work', self::WORKER);
    }

    private static function alerted(string $contains): \Closure
    {
        return fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], $contains);
    }

    private static function answered(string $queryId, string $contains): \Closure
    {
        return fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && $r['callback_query_id'] === $queryId && str_contains($r['text'], $contains);
    }

    // R9

    public function test_a_reminder_is_never_sent_for_an_invoice_that_has_been_paid(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2025-05-15');
        $receipt = $this->payment('2025-07-01', 45000, 'CLIENT LTD INV');
        app(AllocationWriter::class)->decide($receipt, [[
            'kind' => 'business_income', 'category_id' => Category::query()->where('kind', 'income')->value('id'), 'amount_pence' => 45000, 'invoice_id' => $invoice->id,
        ]], 'you');
        $this->assertSame(45000, $invoice->paidMinor());
        // Sent (a reminder can only follow a sent invoice: stage 4 lead decision), then paid.
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'accounts@client.example'])->save();

        // The old "Send a reminder" button from before it was paid.
        $message = app(TodoActions::class)->run('inv_chase', (string) $invoice->id);
        $this->assertStringContainsString('paid in full', $message);
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'accounts@client.example', 'reminder' => true])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'paid in full'));

        Mail::assertNothingQueued();
        $this->assertSame(0, (int) $invoice->fresh()->reminder_count);
    }

    // R16

    public function test_email_it_pressed_again_or_on_an_old_message_never_emails_the_client_twice(): void
    {
        Mail::fake();
        $this->pairTelegram();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));

        // Monday's digest offered "Email it"; then it was emailed from the invoice page. The digest's button, pressed
        // twice, carries the fingerprint of the invoice as it was offered (not yet emailed), so it's refused (M7); a
        // button offered after it was emailed says so.
        $digest = Telegram::callbackData('inv_send', (string) $invoice->id);
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'pay@client.example'])->assertSessionHas('success');
        $this->press($digest, 7, 'q1');
        $this->press($digest, 7, 'q2');
        $this->press(Telegram::callbackData('inv_send', (string) $invoice->id), 3, 'q3');
        $this->post("/todo/inv_send/{$invoice->id}", ['fp' => Fingerprints::of('inv_send', (string) $invoice->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'already emailed'));

        Mail::assertQueued(InvoiceEmail::class, 1);
        Http::assertSent(self::answered('q1', 'This changed since: open it in Expend'));
        Http::assertSent(self::answered('q2', 'This changed since: open it in Expend'));
        Http::assertSent(self::answered('q3', 'already emailed to pay@client.example'));
        $this->assertSame('pay@client.example', $invoice->fresh()->sent_to);
    }

    public function test_send_a_reminder_pressed_twice_sends_one_reminder(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2025-05-15');
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'accounts@client.example'])->save();
        $actions = app(TodoActions::class);

        $actions->run('inv_chase', (string) $invoice->id);
        $again = $actions->run('inv_chase', (string) $invoice->id);
        $this->assertStringContainsString('another wasn’t sent', $again);
        Mail::assertQueued(InvoiceEmail::class, 1);
        $this->assertSame(1, (int) $invoice->fresh()->reminder_count);

        // A week later, the next nudge's button sends the next reminder.
        $this->travel(8)->days();
        $actions->run('inv_chase', (string) $invoice->id);
        Mail::assertQueued(InvoiceEmail::class, 2);
        $this->assertSame(2, (int) $invoice->fresh()->reminder_count);

        // From the invoice page you can still send one whenever you choose.
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'accounts@client.example', 'reminder' => true])->assertSessionHas('success');
        Mail::assertQueued(InvoiceEmail::class, 3);
    }

    // R16, R25

    public function test_draft_the_invoice_twice_makes_one_draft_and_none_once_the_job_is_invoiced(): void
    {
        $this->pairTelegram();
        $job = WorkJob::query()->create(['title' => 'Awards night', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'fee_pence' => 50000,
            'client_id' => Client::query()->create(['name' => 'Acme Events'])->id, 'status' => 'done']);
        $drafts = fn () => Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->count();

        // Two taps on the same button while the first waits: one draft, the same answer twice.
        $button = Telegram::callbackData('job_invoice', (string) $job->id);
        $this->press($button, 11, 'q1');
        $this->press($button, 11, 'q2');
        $this->assertSame(1, $drafts());
        Http::assertSent(self::answered('q1', 'is ready'));
        Http::assertSent(self::answered('q2', 'is ready'));

        // In the app, or an old button on another message: still one draft.
        $this->post("/todo/job_invoice/{$job->id}", ['fp' => Fingerprints::of('job_invoice', (string) $job->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'already has a draft invoice'));
        $this->press($button, 4, 'q3'); // offered before the draft: refused as changed since
        Http::assertSent(self::answered('q3', 'This changed since'));
        $this->assertSame(1, $drafts());

        // Once it's issued, no new full-fee draft.
        app(InvoiceService::class)->issue(Invoice::query()->where('work_job_id', $job->id)->sole());
        $message = app(TodoActions::class)->run('job_invoice', (string) $job->id);
        $this->assertStringContainsString('already invoiced', $message);
        $this->assertSame(0, $drafts());
        $this->assertSame(1, Invoice::query()->where('work_job_id', $job->id)->count());
    }

    public function test_other_one_tap_actions_are_safe_to_repeat(): void
    {
        $job = WorkJob::query()->create(['title' => 'Conference', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01']);
        $actions = app(TodoActions::class);
        $actions->run('job_no_po', (string) $job->id);
        $this->assertStringContainsString('already marked', $actions->run('job_no_po', (string) $job->id));
        $this->assertSame(1, AuditEvent::query()->where('action', 'job.updated')->where('subject_id', $job->id)->count());

        $tx = $this->payment('2025-06-02', -1200, 'ADOBE SUBSCRIPTION');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-1200, 'business_expense', Category::query()->where('code', 'software')->value('id')), 'matcher', false);
        $actions->run('tx_accept', (string) $tx->id);
        $this->assertStringContainsString('Already dealt with', $actions->run('tx_accept', (string) $tx->id));
        $this->assertSame(1, AuditEvent::query()->where('action', 'allocation.confirmed')->where('subject_id', $tx->id)->count());
    }

    // R23

    public function test_an_old_accept_on_a_stay_never_replaces_your_own_decision(): void
    {
        $a = WorkJob::query()->create(['title' => 'Job A', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-12']);
        $b = WorkJob::query()->create(['title' => 'Job B', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11']);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'P1', 'status' => 'booked', 'starts_on' => '2025-06-10',
            'ends_on' => '2025-06-13', 'amount_minor' => 27000, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'manual', 'evidence' => [],
            'suggestion' => ['decision' => 'business', 'job_id' => $a->id]]);
        $this->assertContains('stay:'.$stay->id, array_column(TodoList::build()['items'], 'key'));

        // You decide it yourself in Stays & travel, then tap Monday's Accept (or Personal).
        app(TravelMatcher::class)->decide($stay, 'mixed', $b->id, 2, 'Third night was a holiday');
        $this->post("/todo/stay_accept/{$stay->id}", ['fp' => Fingerprints::of('stay_accept', (string) $stay->id)])->assertSessionHas('error', 'That’s already been dealt with.');
        $this->post("/todo/stay_personal/{$stay->id}", ['fp' => Fingerprints::of('stay_personal', (string) $stay->id)])->assertSessionHas('error', 'That’s already been dealt with.');

        $this->assertSame(['decision' => 'mixed', 'work_job_id' => $b->id, 'business_nights' => 2, 'note' => 'Third night was a holiday'], $stay->fresh()->only(['decision', 'work_job_id', 'business_nights', 'note']));
    }

    public function test_an_old_personal_button_never_replaces_a_payment_you_decided(): void
    {
        $tx = $this->payment('2025-06-02', -4500, 'SCREWFIX LEEDS');
        $tools = Category::query()->where('kind', 'expense')->value('id');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-4500, 'business_expense', $tools), 'you');

        $this->post("/todo/tx_personal/{$tx->id}", ['fp' => Fingerprints::of('tx_personal', (string) $tx->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'Already dealt with'));
        $line = $tx->fresh()->allocations()->sole();
        $this->assertSame(['business_expense', $tools], [$line->kind, $line->category_id]);

        // Waiting for you: Personal still works.
        $other = $this->payment('2025-06-03', -900, 'CAFE NERO');
        $this->post("/todo/tx_personal/{$other->id}", ['fp' => Fingerprints::of('tx_personal', (string) $other->id)])->assertSessionHas('success', 'Marked personal: CAFE NERO.');
        $this->assertSame('personal', $other->fresh()->allocations()->sole()->kind);
    }

    // R24

    public function test_an_invoice_email_that_fails_to_send_is_not_left_marked_as_sent(): void
    {
        config(['queue.default' => 'database']);
        Setting::put('mail_method', 'smtp');
        Setting::put('smtp_host', '127.0.0.1');
        Setting::put('smtp_port', 1); // nothing listens here: the send fails, like a wrong mailbox setting
        Setting::put('smtp_username', 'me@business.example');
        Setting::put('smtp_password', 'wrong');
        $this->pairTelegram();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));

        $this->post("/invoices/{$invoice->id}/send", ['to' => 'pay@client.example'])->assertSessionHas('success');
        $this->assertNotNull($invoice->fresh()->sent_at, 'Marked while queued, so a second press can see it');
        $this->artisan('queue:work', self::WORKER);

        $fresh = $invoice->fresh();
        $this->assertNull($fresh->sent_at);
        $this->assertNull($fresh->sent_to);
        $this->assertContains('unsent:'.$invoice->id, array_column(TodoList::build()['items'], 'key'));
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'invoice.send_failed')->where('subject_id', $invoice->id)->exists());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && str_contains($r['text'], "Invoice {$invoice->number} couldn’t be emailed to pay@client.example"));
        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoice.email_failed.to', 'pay@client.example')->where('invoice.email_failed.reminder', false));

        // A resend or a reminder that fails puts back what was there before.
        $invoice->forceFill(['sent_at' => '2025-01-02 09:00:00', 'sent_to' => 'old@client.example', 'reminded_at' => null, 'reminder_count' => 0])->save();
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'new@client.example'])->assertSessionHas('success');
        $this->artisan('queue:work', self::WORKER);
        $this->assertSame(['2025-01-02 09:00:00', 'old@client.example'], [$invoice->fresh()->sent_at->format('Y-m-d H:i:s'), $invoice->fresh()->sent_to]);

        $this->travel(40)->days();
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'old@client.example', 'reminder' => true])->assertSessionHas('success');
        $this->assertSame(1, (int) $invoice->fresh()->reminder_count);
        $this->artisan('queue:work', self::WORKER);
        $this->assertSame([0, null], [(int) $invoice->fresh()->reminder_count, $invoice->fresh()->reminded_at]);
        $this->assertTrue(AuditEvent::query()->where('action', 'invoice.reminder_failed')->exists());
    }

    public function test_an_email_that_sends_stays_marked_as_sent(): void
    {
        config(['queue.default' => 'database']);
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'pay@client.example'])->assertSessionHas('success');
        $this->artisan('queue:work', self::WORKER);

        $this->assertSame('pay@client.example', $invoice->fresh()->sent_to);
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoice.email_failed', null));
    }

    // R27

    public function test_approve_on_a_document_that_was_already_read_changes_nothing(): void
    {
        Queue::fake();
        Setting::put('ai_extraction', 'on');
        $make = fn (string $status) => Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', $status), 'stored_path' => 'x',
            'kind' => 'po', 'status' => $status]);
        $read = $make('extracted');

        $this->post("/todo/doc_approve/{$read->id}", ['fp' => Fingerprints::of('doc_approve', (string) $read->id)])->assertSessionHas('error', 'That’s already been dealt with.');
        $this->assertSame('extracted', $read->fresh()->status);
        Queue::assertNotPushed(ReadDocument::class);

        $held = $make('held');
        $this->post("/todo/doc_approve/{$held->id}", ['fp' => Fingerprints::of('doc_approve', (string) $held->id)])->assertSessionHas('success');
        $this->assertSame('new', $held->fresh()->status);
    }

    // R34

    public function test_invoices_issued_before_emailing_existed_are_recorded_as_sent(): void
    {
        $old = $this->issuedInvoice('2026-10-01');
        $chased = $this->issuedInvoice('2026-09-01');
        $emailed = $this->issuedInvoice('2026-10-05');
        $newer = $this->issuedInvoice('2026-11-24');
        $void = $this->issuedInvoice('2026-10-02');
        app(InvoiceService::class)->void($void, 'Wrong client');
        $set = fn (Invoice $i, array $v) => DB::table('invoices')->where('id', $i->id)->update($v);
        $set($old, ['issued_at' => '2026-10-01 09:30:00']);
        $set($chased, ['issued_at' => '2026-09-01 11:00:00', 'reminded_at' => '2026-10-15 09:00:00', 'reminder_count' => 1, 'overdue_nudged_at' => '2026-10-14 09:13:00']);
        $set($emailed, ['issued_at' => '2026-10-05 08:00:00', 'sent_at' => '2026-11-23 23:30:00', 'sent_to' => 'pay@client.example']);
        $set($newer, ['issued_at' => '2026-11-24 10:00:00']);
        $set($void, ['issued_at' => '2026-10-02 10:00:00']);
        $this->assertContains('unsent:'.$old->id, array_column(TodoList::build()['items'], 'key'));

        (require database_path('migrations/2026_11_29_000101_todo_actions_mark_earlier_invoices_sent.php'))->up();

        $this->assertSame(['2026-10-01 09:30:00', null], [$old->fresh()->sent_at->format('Y-m-d H:i:s'), $old->fresh()->sent_to]);
        $c = $chased->fresh();
        $this->assertSame(['2026-09-01 11:00:00', '2026-10-15 09:00:00', 1, '2026-10-14 09:13:00'],
            [$c->sent_at->format('Y-m-d H:i:s'), $c->reminded_at->format('Y-m-d H:i:s'), (int) $c->reminder_count, $c->overdue_nudged_at->format('Y-m-d H:i:s')]);
        $this->assertSame(['2026-11-23 23:30:00', 'pay@client.example'], [$emailed->fresh()->sent_at->format('Y-m-d H:i:s'), $emailed->fresh()->sent_to]);
        $this->assertNull($newer->fresh()->sent_at, 'Issued once emailing existed: it may still be waiting for you to send it');
        $this->assertNull($void->fresh()->sent_at);
        $this->assertSame('void', $void->fresh()->status);

        $keys = array_column(TodoList::build()['items'], 'key');
        $this->assertNotContains('unsent:'.$old->id, $keys);
        $this->assertNotContains('unsent:'.$chased->id, $keys);
        $this->assertContains('unsent:'.$newer->id, $keys);
        $this->assertEqualsCanonicalizing([$old->number, $chased->number], AuditEvent::query()->where('action', 'invoice.marked_sent')->sole()->after['numbers']);
        $this->assertSame(0, $old->fresh()->paidMinor());

        // Running it again changes nothing.
        (require database_path('migrations/2026_11_29_000101_todo_actions_mark_earlier_invoices_sent.php'))->up();
        $this->assertSame(1, AuditEvent::query()->where('action', 'invoice.marked_sent')->count());
    }

    // R35

    public function test_todo_from_telegram_turns_on_button_presses_for_a_bot_paired_earlier(): void
    {
        $this->pairTelegram();
        $this->assertNull(Setting::get('telegram_buttons_on'));
        JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret')->postJson('/hooks/telegram', ['message' => [
            'message_id' => 1, 'chat' => ['id' => '42', 'type' => 'private'], 'text' => '/todo',
        ]])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'setWebhook') && in_array('callback_query', $r['allowed_updates'], true));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && isset($r['reply_markup']));
        $this->assertTrue((bool) Setting::get('telegram_buttons_on'));
    }

    // R37

    public function test_an_invoice_is_not_overdue_on_its_due_date(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2026-10-24');
        $invoice->forceFill(['sent_at' => '2026-10-24 10:00:00', 'sent_to' => 'accounts@client.example'])->save();
        $due = $invoice->due_date->format('Y-m-d');

        $this->travelTo($due.' 10:00:00');
        $this->assertFalse(InvoiceController::row($invoice->fresh())['overdue']);
        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoice.overdue', false));
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'accounts@client.example', 'reminder' => true])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'too early for a reminder'));
        Mail::assertNothingQueued();

        $this->travelTo($invoice->due_date->addDay()->format('Y-m-d').' 00:00:01');
        $this->assertTrue(InvoiceController::row($invoice->fresh())['overdue']);
        $this->assertStringContainsString('on its way', app(InvoiceMailer::class)->send($invoice->fresh(), null, true));
    }

    // R48

    public function test_a_mistyped_address_comes_back_in_plain_english(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));

        $this->post("/invoices/{$invoice->id}/send", ['to' => 'a@client.example, b@client.example'])
            ->assertSessionHasErrors(['to' => 'That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.']);
        Mail::assertNothingQueued();
        $this->assertNull($invoice->fresh()->sent_at);
    }

    public function test_stale_buttons_report_already_dealt_with_in_telegram(): void
    {
        $this->pairTelegram();
        $doc = Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted']);
        $this->press(Telegram::callbackData('doc_approve', (string) $doc->id), 20, 'q9');
        Http::assertSent(self::answered('q9', 'Already dealt with.'));
        $this->assertSame('extracted', $doc->fresh()->status);

        $this->expectException(ModelNotFoundException::class);
        app(TodoActions::class)->run('doc_approve', (string) $doc->id);
    }

    public function test_a_failed_press_can_be_tried_again(): void
    {
        $this->pairTelegram();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'), null);
        Mail::fake();

        $button = Telegram::callbackData('inv_send', (string) $invoice->id);
        $this->press($button, 30, 'q1');
        Http::assertSent(self::answered('q1', 'There’s no email address for the client on this invoice'));
        $this->assertThrows(fn () => app(InvoiceMailer::class)->send($invoice->fresh(), null, false, true), InvalidArgumentException::class);

        DB::table('invoices')->where('id', $invoice->id)->update(['bill_to_email' => 'pay@client.example']);
        $this->press($button, 30, 'q2');
        Http::assertSent(self::answered('q2', 'on its way to pay@client.example'));
        Mail::assertQueued(InvoiceEmail::class, 1);
    }

    // Second round: problems found with the first fixes.

    // R16: the once-check is tied to when the button was offered, not to a week (the nudges are a week apart).

    public function test_next_weeks_reminder_button_sends_even_when_pressed_earlier_in_the_day(): void
    {
        Mail::fake();
        $this->pairTelegram();
        $this->travelTo('2026-11-02 09:13:00');
        $invoice = $this->issuedInvoice('2026-10-01'); // due 31 Oct
        $invoice->forceFill(['sent_at' => '2026-10-01 10:00:00', 'sent_to' => 'accounts@client.example'])->save();

        $digest = Telegram::callbackData('inv_chase', (string) $invoice->id); // Monday's digest, at 08:47
        $this->artisan('ledger:invoice-nudges');
        $nudge = $invoice->fresh()->overdue_nudged_at;
        $this->travelTo('2026-11-02 10:00:00');
        $this->press(Telegram::callbackData('inv_chase', (string) $invoice->id), 50, 'q1', $nudge);
        Http::assertSent(self::answered('q1', 'is on its way'));

        // Monday's digest went before that reminder: its button, pressed afterwards, doesn't send another (since M7 it's
        // refused as changed since: the invoice was chased after it was offered).
        $this->press($digest, 49, 'q2', Carbon::parse('2026-11-02 08:47:00'));
        Http::assertSent(self::answered('q2', 'This changed since'));
        $this->assertSame(1, (int) $invoice->fresh()->reminder_count);

        // Next week's nudge, pressed earlier in the day than last week's reminder went: it sends.
        $this->travelTo('2026-11-09 09:13:01');
        $this->artisan('ledger:invoice-nudges');
        $this->travelTo('2026-11-09 09:20:00');
        $this->press(Telegram::callbackData('inv_chase', (string) $invoice->id), 60, 'q3', $invoice->fresh()->overdue_nudged_at);
        Http::assertSent(self::answered('q3', 'is on its way'));
        $this->assertSame(2, (int) $invoice->fresh()->reminder_count);
        Mail::assertQueued(InvoiceEmail::class, 2);
    }

    public function test_send_a_reminder_on_the_to_do_page_catches_a_double_click_but_not_next_week(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2025-05-15');
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'accounts@client.example'])->save();

        $this->post("/todo/inv_chase/{$invoice->id}", ['fp' => Fingerprints::of('inv_chase', (string) $invoice->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'is on its way'));
        $this->post("/todo/inv_chase/{$invoice->id}", ['fp' => Fingerprints::of('inv_chase', (string) $invoice->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'another wasn’t sent'));
        $this->assertSame(1, (int) $invoice->fresh()->reminder_count);

        $this->travel(6)->days();
        $this->travel(23)->hours();
        $this->post("/todo/inv_chase/{$invoice->id}", ['fp' => Fingerprints::of('inv_chase', (string) $invoice->id)])->assertSessionHas('success', fn ($m) => str_contains($m, 'is on its way'));
        $this->assertSame(2, (int) $invoice->fresh()->reminder_count);
        Mail::assertQueued(InvoiceEmail::class, 2);
    }

    // R9: an old reminder button checks what has come in the way the to-do list does.

    public function test_an_old_reminder_button_never_chases_a_payment_recorded_against_the_job(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2025-05-15');
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'accounts@client.example'])->save();
        $receipt = $this->payment('2025-07-01', 45000, 'CLIENT LTD GALA');
        // As "Recorded as income for this job" does: business income on the job, no invoice picked.
        app(AllocationWriter::class)->decide($receipt, AllocationWriter::lines(45000, 'business_income', Category::query()->where('kind', 'income')->value('id'), $invoice->work_job_id), 'you');
        $this->assertSame(45000, $invoice->paidMinor(), 'Income recorded for the job pays its invoice');

        $this->assertStringContainsString('paid in full', app(TodoActions::class)->run('inv_chase', (string) $invoice->id));
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'accounts@client.example', 'reminder' => true])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'paid in full'));
        Mail::assertNothingQueued();
    }

    public function test_an_old_reminder_button_waits_when_something_has_come_in(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2025-05-15');
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'accounts@client.example'])->save();
        $income = Category::query()->where('kind', 'income')->value('id');

        // A matching payment linked by the matcher, waiting in the Inbox.
        $receipt = $this->payment('2025-07-01', 45000, 'CLIENT LTD INV');
        app(AllocationWriter::class)->decide($receipt, [['kind' => 'business_income', 'category_id' => $income, 'amount_pence' => 45000, 'invoice_id' => $invoice->id]], 'matcher', false);
        $message = app(TodoActions::class)->run('inv_chase', (string) $invoice->id);
        $this->assertStringContainsString('waiting in the Inbox', $message);
        Mail::assertNothingQueued();

        // Part of it paid: the reminder email would say no payment has been seen, so the button doesn't send it.
        $receipt->allocations()->delete();
        $part = $this->payment('2025-07-02', 20000, 'CLIENT LTD PART');
        app(AllocationWriter::class)->decide($part, AllocationWriter::lines(20000, 'business_income', $income, $invoice->work_job_id), 'you');
        $message = app(TodoActions::class)->run('inv_chase', (string) $invoice->id);
        $this->assertStringContainsString('£200.00 of it has come in', $message);
        Mail::assertNothingQueued();
        $this->assertSame(0, (int) $invoice->fresh()->reminder_count);

        // From the invoice page you can still choose to send one.
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'accounts@client.example', 'reminder' => true])->assertSessionHas('success');
        Mail::assertQueued(InvoiceEmail::class, 1);
    }

    // R24: two emails queued before the worker runs, that both fail.

    public function test_two_queued_emails_that_both_fail_leave_the_invoice_unsent(): void
    {
        $this->failingMailbox();
        $this->pairTelegram();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));

        $this->post("/invoices/{$invoice->id}/send", ['to' => 'first@client.example'])->assertSessionHas('success');
        $this->travel(5)->seconds();
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'second@client.example'])->assertSessionHas('success');
        $this->work();

        $fresh = $invoice->fresh();
        $this->assertSame([null, null], [$fresh->sent_at, $fresh->sent_to]);
        $this->assertSame(2, DB::table('failed_jobs')->count());
        $this->assertContains('unsent:'.$invoice->id, array_column(TodoList::build()['items'], 'key'));
        // The first alert came while the second email still held the mark; the second one undid it.
        Http::assertSent(fn (Request $r) => self::alerted('couldn’t be emailed to first@client.example')($r) && str_contains($r['text'], 'A later email, to second@client.example')
            && ! str_contains($r['text'], 'isn’t marked as sent'));
        Http::assertSent(fn (Request $r) => self::alerted('couldn’t be emailed to second@client.example')($r) && str_contains($r['text'], 'It isn’t marked as sent.'));
    }

    public function test_two_queued_reminders_count_only_what_was_sent(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice('2025-05-15');
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'accounts@client.example'])->save();
        $mailer = app(InvoiceMailer::class);

        $mailer->send($invoice->fresh(), null, true);
        $this->travel(5)->seconds();
        $mailer->send($invoice->fresh(), null, true);
        $second = $invoice->fresh()->reminded_at->format('Y-m-d H:i:s');
        $this->assertSame(2, (int) $invoice->fresh()->reminder_count);
        [$one, $two] = Mail::queued(InvoiceEmail::class)->all();

        // The first fails, the second sends: one reminder sent, and the page doesn't ask you to send it again.
        $one->failed(new RuntimeException('Mailbox password rejected'));
        $this->assertSame([1, $second], [(int) $invoice->fresh()->reminder_count, $invoice->fresh()->reminded_at->format('Y-m-d H:i:s')]);
        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoice.email_failed', null));

        // Had the second failed too: none sent.
        $two->failed(new RuntimeException('Mailbox password rejected'));
        $this->assertSame([0, null], [(int) $invoice->fresh()->reminder_count, $invoice->fresh()->reminded_at]);
        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoice.email_failed.reminder', true));
    }

    public function test_two_emails_queued_in_the_same_second_are_told_apart(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));
        $this->freezeSecond();
        $mailer = app(InvoiceMailer::class);
        $mailer->send($invoice->fresh(), 'first@client.example');
        $mailer->send($invoice->fresh(), 'second@client.example');
        [$one, $two] = Mail::queued(InvoiceEmail::class)->all();
        $this->assertNotSame($one->claimedAt, $two->claimedAt);

        // The first fails: the second, still on its way, keeps the mark.
        $one->failed(new RuntimeException('Mailbox password rejected'));
        $this->assertSame('second@client.example', $invoice->fresh()->sent_to);
        $two->failed(new RuntimeException('Mailbox password rejected'));
        $this->assertSame([null, null], [$invoice->fresh()->sent_at, $invoice->fresh()->sent_to]);
    }

    public function test_the_failure_alert_says_what_the_invoice_now_shows(): void
    {
        $this->failingMailbox();
        $this->pairTelegram();
        $invoice = $this->issuedInvoice('2025-05-15');

        // An email queued before this was tracked (nothing to put back): it still shows as sent.
        $invoice->forceFill(['sent_at' => '2025-05-15 10:00:00', 'sent_to' => 'pay@client.example'])->save();
        Mail::to('pay@client.example')->queue(new InvoiceEmail($invoice->fresh()));
        $this->work();
        $this->assertSame('pay@client.example', $invoice->fresh()->sent_to);
        Http::assertSent(fn (Request $r) => self::alerted('couldn’t be emailed to pay@client.example')($r) && str_contains($r['text'], 'It still shows as emailed to pay@client.example on 15 May 2025')
            && ! str_contains($r['text'], 'isn’t marked as sent'));

        // A resend that fails: back to the earlier email.
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'new@client.example'])->assertSessionHas('success');
        $this->work();
        Http::assertSent(fn (Request $r) => self::alerted('couldn’t be emailed to new@client.example')($r) && str_contains($r['text'], 'It shows the earlier email again, to pay@client.example on 15 May 2025.'));

        // A reminder that fails: the invoice is still sent, the reminder isn't counted.
        $this->post("/invoices/{$invoice->id}/send", ['to' => 'pay@client.example', 'reminder' => true])->assertSessionHas('success');
        $this->work();
        $this->assertSame([0, 'pay@client.example'], [(int) $invoice->fresh()->reminder_count, $invoice->fresh()->sent_to]);
        Http::assertSent(fn (Request $r) => self::alerted('The reminder for invoice '.$invoice->number.' couldn’t be emailed')($r) && str_contains($r['text'], 'The reminder isn’t counted.')
            && ! str_contains($r['text'], 'isn’t marked as sent'));
    }

    // R48: addresses the 'email' rule lets through but that can't be emailed.

    public function test_an_address_with_no_ending_comes_back_in_plain_english(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice(today()->format('Y-m-d'));

        foreach (['accounts@client', 'joe @client.example'] as $typo) {
            $this->post("/invoices/{$invoice->id}/send", ['to' => $typo])
                ->assertSessionHasErrors(['to' => 'That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.']);
        }
        Mail::assertNothingQueued();

        // One saved on the invoice before this was checked: say so, rather than asking you to add one.
        DB::table('invoices')->where('id', $invoice->id)->update(['bill_to_email' => 'accounts@client']);
        $this->assertThrows(fn () => app(InvoiceMailer::class)->send($invoice->fresh()), InvalidArgumentException::class, 'The client’s email address on the invoice, accounts@client, doesn’t look right');

        // A draft's address is checked the same way.
        $draft = Invoice::query()->create(['status' => 'draft', 'total_minor' => 0, 'currency' => 'GBP']);
        $this->patch("/invoices/{$draft->id}", ['bill_to_email' => 'accounts@client', 'currency' => 'GBP'])
            ->assertSessionHasErrors(['bill_to_email' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.']);
    }
}
