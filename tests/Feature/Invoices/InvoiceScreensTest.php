<?php

namespace Tests\Feature\Invoices;

use App\Domain\Gmail\GmailClient;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Mail\InvoiceEmail as InvoiceMail;
use App\Models\AuditEvent;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice F2 (the invoice screens), the server side of it: "I sent it myself" asks when it went (today unless you
 * pick another day, never before it was issued or after today), and its terms count from then; a reminder can only
 * follow a sent invoice, on every path that makes or sends one (the page's Make a reminder, the to-do list's and
 * Telegram's button, and Expend's own email); and what the rebuilt page reads (payments to link, the first day it can
 * have gone, late costs, the client's one-tap arrangements, the lookups for a payment's drawer).
 */
class InvoiceScreensTest extends TestCase
{
    use InvoiceEmailFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInvoiceEmails();
        $this->travelTo('2026-06-05 10:00:00');
    }

    private function props(Invoice $invoice): array
    {
        $page = $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('invoices/show', false))->viewData('page');

        return json_decode(json_encode($page['props']), true);
    }

    /** Sent the way an Inertia page sends it: JSON, back to the page. */
    private function visit(string $method, string $uri, array $body, Invoice $invoice): TestResponse
    {
        return $this->from("/invoices/{$invoice->id}")->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** Issued on 5 June (Expend issued it then), not sent, not in Gmail. */
    private function unsent(): Invoice
    {
        Setting::put('gmail_scopes', []);
        $invoice = $this->issuedInvoice();
        Setting::put('gmail_scopes', [GmailClient::SCOPE]);

        return $invoice->fresh();
    }

    public function test_i_sent_it_myself_takes_the_day_it_went_and_its_terms_count_from_then(): void
    {
        $invoice = $this->unsent();
        $this->assertSame('2026-06-05', $this->props($invoice)['issued']['sent_from']);
        $this->travelTo('2026-07-20 09:00:00');

        // Sent on 10 June, said six weeks later: marked as sent that day (midday, UK time), not today.
        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['on' => '2026-06-10'], $invoice)
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Invoice INV-0001 is marked as sent on 10 Jun 2026.');
        $fresh = $invoice->fresh();
        $this->assertSame('2026-06-10 11:00:00', $fresh->sent_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10', AuditEvent::query()->where('action', 'invoice.marked_sent')->sole()->after['sent_on']);
        $this->assertSame('2026-06-10 11:00:00', InvoiceEmail::query()->where('invoice_id', $invoice->id)->sole()->sent_at->utc()->format('Y-m-d H:i:s'));
        // Due 5 July as printed; sent on 10 June with 30 days' terms, so overdue from 10 July: 10 days on 20 July.
        $this->assertSame(10, ChaseLadder::daysOverdue($fresh, now()));
    }

    public function test_a_day_still_to_come_before_it_was_issued_or_not_a_day_is_refused_and_nothing_changes(): void
    {
        $invoice = $this->unsent();
        $this->travelTo('2026-06-20 09:00:00');
        $events = AuditEvent::query()->count();

        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['on' => '2026-06-21'], $invoice)
            ->assertSessionHasErrors(['on' => 'That day hasn’t come yet: pick today or a day before.']);
        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['on' => '2026-06-04'], $invoice)
            ->assertSessionHasErrors(['on' => 'It can’t have gone before it was issued on 5 Jun 2026: pick that day or later.']);
        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['on' => '20/06/2026'], $invoice)
            ->assertSessionHasErrors(['on' => 'Pick the day it went.']);
        $this->assertNull($invoice->fresh()->sent_at);
        $this->assertSame($events, AuditEvent::query()->count());

        // The domain refuses it too, whoever asks.
        $this->expectExceptionMessage('That day hasn’t come yet: pick today or a day before.');
        app(InvoiceEmails::class)->markSentSelf($invoice, null, '2026-06-21');
    }

    /** Back-dated in the issue sheet (dated 1 June, made on 5 June): the refusal names both days, not two "issued" dates. */
    public function test_a_back_dated_invoice_says_the_day_expend_made_it_is_the_first_it_can_have_gone(): void
    {
        $invoice = app(InvoiceService::class)->issue($this->draftInvoice()->fresh(), '2026-06-01')->fresh();
        $this->assertSame(['2026-06-01', '2026-06-05'], [$invoice->issue_date->format('Y-m-d'), InvoiceEmails::issuedOn($invoice)]);
        $this->travelTo('2026-06-20 09:00:00');

        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['on' => '2026-06-02'], $invoice)
            ->assertSessionHasErrors(['on' => 'It’s dated 1 Jun 2026, but Expend only made it on 5 Jun 2026: pick 5 Jun or later.']);
        $this->assertNull($invoice->fresh()->sent_at);
    }

    public function test_the_day_it_was_issued_is_never_before_it_was_issued_and_today_is_now(): void
    {
        $invoice = $this->unsent();
        $issuedAt = $invoice->issued_at->copy();
        $this->travelTo('2026-06-05 18:00:00');

        // Today: now, worded as before.
        $this->visit('POST', "/invoices/{$invoice->id}/sent-self", ['on' => '2026-06-05'], $invoice)
            ->assertSessionHas('success', 'Invoice INV-0001 is marked as sent.');
        $this->assertSame('2026-06-05 18:00:00', $invoice->fresh()->sent_at->utc()->format('Y-m-d H:i:s'));

        // Issued in the afternoon and said to have gone that day, the next morning: when it was issued, not midday.
        $this->travelTo('2026-06-05 15:30:00');
        $other = $this->issuedInvoice($this->job('Second show', [], false));
        $this->travelTo('2026-06-06 08:00:00');
        app(InvoiceEmails::class)->markSentSelf($other->fresh(), null, '2026-06-05');
        $this->assertSame('2026-06-05 15:30:00', $other->fresh()->sent_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($issuedAt->lte($invoice->fresh()->sent_at));
    }

    public function test_the_to_do_lists_i_sent_it_myself_is_today_and_pick_a_date_opens_the_invoice(): void
    {
        $invoice = app(InvoiceEmails::class)->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        $this->travelTo('2026-06-08 09:00:00');
        TodoList::forget();
        $item = collect(TodoList::build(100)['items'])->firstWhere('key', 'unsent:'.$invoice->id);
        $this->assertNotNull($item);
        $this->assertContains(['link', 'Pick a date', '/invoices/'.$invoice->id.'#sent'],
            array_map(fn ($a) => [$a['code'], $a['label'], $a['href'] ?? null], $item['actions']));

        app(TodoActions::class)->run('inv_sent_self', (string) $invoice->id, null, Fingerprints::of('inv_sent_self', (string) $invoice->id));
        $this->assertSame('2026-06-08', $invoice->fresh()->sent_at->format('Y-m-d'));
    }

    public function test_make_a_reminder_on_the_page_refuses_an_invoice_never_sent(): void
    {
        $invoice = $this->unsent();
        $this->travelTo('2026-07-20 09:00:00');

        $this->postJson("/invoices/{$invoice->id}/reminder")->assertStatus(422)->assertJsonPath('message', 'Send the invoice first: a reminder can only follow it.');
        $this->visit('POST', "/invoices/{$invoice->id}/reminder", [], $invoice)->assertSessionHas('error', 'Send the invoice first: a reminder can only follow it.');
        // Without Gmail too (it would otherwise say to connect Gmail).
        Setting::put('gmail_scopes', []);
        $this->postJson("/invoices/{$invoice->id}/reminder")->assertStatus(422)->assertJsonPath('message', 'Send the invoice first: a reminder can only follow it.');
        $this->assertSame(0, InvoiceEmail::query()->where('kind', 'reminder')->count());
        $this->assertFalse($this->props($invoice)['issued']['can_remind']);
    }

    public function test_the_to_do_and_telegram_reminder_button_refuses_an_invoice_never_sent(): void
    {
        Mail::fake();
        $invoice = $this->unsent();
        $this->travelTo('2026-07-20 09:00:00');
        foreach ([true, false] as $gmail) {
            Setting::put('gmail_scopes', $gmail ? [GmailClient::SCOPE] : []);
            try {
                app(TodoActions::class)->run('inv_chase', (string) $invoice->id, null, Fingerprints::of('inv_chase', (string) $invoice->id));
                $this->fail('A reminder was made for an invoice never sent.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Send the invoice first: a reminder can only follow it.', $e->getMessage());
            }
        }
        $this->assertSame(0, InvoiceEmail::query()->where('kind', 'reminder')->count());
        Mail::assertNothingQueued();
        $this->assertSame(0, (int) $invoice->fresh()->reminder_count);
    }

    public function test_expend_never_emails_a_reminder_for_an_invoice_never_sent(): void
    {
        Mail::fake();
        Setting::put('mail_method', 'server');
        $invoice = $this->unsent();
        $this->travelTo('2026-07-20 09:00:00');

        try {
            app(InvoiceMailer::class)->send($invoice->fresh(), 'accounts@acme.example.test', true);
            $this->fail('A reminder was emailed for an invoice never sent.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Send the invoice first: a reminder can only follow it.', $e->getMessage());
        }
        $this->from("/invoices/{$invoice->id}")->post("/invoices/{$invoice->id}/send", ['to' => 'accounts@acme.example.test', 'reminder' => true])
            ->assertSessionHas('error', 'Send the invoice first: a reminder can only follow it.');
        Mail::assertNothingQueued(InvoiceMail::class);
        $this->assertSame([0, null], [(int) $invoice->fresh()->reminder_count, $invoice->fresh()->reminded_at]);

        // Once it's marked as sent, the overdue reminder goes.
        app(InvoiceEmails::class)->markSentSelf($invoice->fresh(), null, '2026-06-05');
        app(InvoiceMailer::class)->send($invoice->fresh(), 'accounts@acme.example.test', true);
        $this->assertSame(1, (int) $invoice->fresh()->reminder_count);
    }

    public function test_the_page_reads_payments_to_link_late_costs_and_the_one_tap_arrangements(): void
    {
        // A draft for a client with no arrangement: the one-tap answers, tied to the arrangement as it is.
        $draft = $this->draftInvoice();
        $props = $this->props($draft);
        $this->assertSame(['at_cost', 'mileage', 'in_rate'], array_column($props['draft']['client']['terms']['presets'], 'key'));
        $this->assertSame('Everything at cost', $props['draft']['client']['terms']['presets'][0]['label']);
        $this->assertSame(16, strlen($props['draft']['client']['terms']['fp']));
        // The issue sheet shows the due date: 30 days unless Settings or the notes ("within 14 days") say otherwise.
        $this->assertSame(30, $props['draft']['terms_days']);
        $this->assertNotEmpty($props['lookups']['categories']);
        $this->assertArrayHasKey('clients', $props['lookups']);
        $this->put("/clients/{$props['draft']['client']['id']}/terms", ['fp' => $props['draft']['client']['terms']['fp'], 'preset' => 'at_cost'])->assertSessionHas('success');
        $this->assertNull($this->props($draft)['draft']['client']['terms']);

        // Issued and sent; money in from the client waits; a taxi is linked to the job afterwards.
        $invoice = app(InvoiceEmails::class)->issueToGmail($draft->fresh(), '2026-06-05')['invoice'];
        app(InvoiceEmails::class)->markSentSelf($invoice->fresh());
        $job = WorkJob::query()->find($invoice->work_job_id);
        // Acme paid you before, from ACME EVENTS: Expend knows that payer is theirs.
        $this->income(30000, $this->job('Earlier show', [], false), null, true, '2026-05-01');
        $this->travelTo('2026-06-20 10:00:00');
        $tx = $this->income(65000, $job, null, false, '2026-06-19');
        $tx->allocations()->delete();
        $tx->forceFill(['review_status' => 'unreviewed'])->save();
        $taxi = $this->income(-1820, $job, null, true, '2026-06-18');
        $taxi->allocations()->delete();
        app(AllocationWriter::class)->decide($taxi, AllocationWriter::lines(-1820, 'business_expense',
            (int) Category::query()->where('code', 'travel_taxi')->value('id'), $job->id), 'you', true);

        $issued = $this->props($invoice->fresh())['issued'];
        $this->assertSame([$tx->id], array_column($issued['to_link'], 'id'));
        $this->assertSame('2026-06-05', $issued['sent_from']);
        $this->assertCount(1, $issued['late_costs']);
        $late = $issued['late_costs'][0];
        $this->assertSame(1820, $late['amount_minor']);
        $this->assertSame(['cost_invoice_now', 'cost_no_charge'], array_column($late['actions'], 'code'));
        $this->assertSame(Fingerprints::of('cost_no_charge', $late['id']), $late['actions'][1]['fp']);
        // Not charged, as the to-do list does it: gone from the invoice's late costs.
        $this->from("/invoices/{$invoice->id}")->post("/todo/cost_no_charge/{$late['id']}", ['fp' => $late['actions'][1]['fp']])->assertSessionHas('success');
        $this->assertSame([], $this->props($invoice->fresh())['issued']['late_costs']);
    }

    public function test_the_invoice_list_says_what_is_sent_and_how_many_changes_a_draft_has(): void
    {
        $sent = app(InvoiceEmails::class)->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice'];
        app(InvoiceEmails::class)->markSentSelf($sent->fresh());
        $draft = $this->draftInvoice($this->job('Second show', [], false));
        WorkJob::query()->find($draft->work_job_id)->forceFill(['fee_pence' => 70000])->save();
        $this->props($draft); // the sync follows the job's fee: a change since you last looked

        $page = $this->get('/invoices')->assertOk()->viewData('page');
        $props = json_decode(json_encode($page['props']), true);
        $rows = collect($props['invoices'])->keyBy('id');
        $this->assertTrue($rows[$sent->id]['sent']);
        $this->assertFalse($rows[$draft->id]['sent']);
        $this->assertSame([(string) $draft->id => 1], $props['changes']);
    }
}
