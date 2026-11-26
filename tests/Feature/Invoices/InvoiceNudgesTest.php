<?php

namespace Tests\Feature\Invoices;

use App\Console\Commands\InvoiceNudges;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobStatus;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\ChangedSince;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Jobs\CheckInvoiceEmails;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\InvoiceEmail;
use App\Models\Setting;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Build slice S10: the invoice nudges on Telegram (InvoiceNudges, replacing the ledger:invoice-nudges closure). They
 * only tell you: a reminder is made only when you press for one (the owner's answer), never on a schedule. Overdue at
 * 3, 14 and 30 days then weekly; not sent on day 1 and day 3 then weekly; the deleted Gmail draft once; a draft waiting
 * 2 days after Done then weekly; "can't see Gmail" once; quiet hours and job days; one-tap Send only when safe.
 */
class InvoiceNudgesTest extends TestCase
{
    use InvoiceEmailFixtures;
    use RefreshDatabase;

    /** @var list<array> the messages sent to Telegram */
    private array $messages = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Telegram's answers first: the fake Gmail answers everything else.
        Http::fake(['api.telegram.org/*' => function (Request $r) {
            if (str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/sendMessage')) {
                $this->messages[] = $r->data();
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => count($this->messages) + 1]]);
        }]);
        $this->setUpInvoiceEmails();
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        $this->at('2026-06-05 10:00');
    }

    /** London wall-clock time. */
    private function at(string $london): void
    {
        $this->travelTo(CarbonImmutable::parse($london, 'Europe/London'));
    }

    private function nudge(): void
    {
        $this->artisan('ledger:invoice-nudges')->assertSuccessful();
    }

    /** Issued on 5 June (due 5 July) and put in Gmail; sent from there when $send. */
    private function issued(?WorkJob $job = null, bool $send = true): Invoice
    {
        $emails = app(InvoiceEmails::class);
        $invoice = $emails->issueToGmail($this->draftInvoice($job), '2026-06-05')['invoice'];
        if ($send) {
            $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
            $this->assertSame('sent', $emails->check(InvoiceEmails::open($invoice)));
        }

        return $invoice->fresh();
    }

    /** @return list<array{0:string, 1:?string}> each button of a message: its text, and its press (or link) */
    private static function buttons(array $message): array
    {
        return collect($message['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->map(fn ($b) => [$b['text'], $b['callback_data'] ?? $b['url'] ?? null])->all();
    }

    private function about(string $needle): array
    {
        return array_values(array_filter($this->messages, fn (array $m) => str_contains($m['text'], $needle)));
    }

    public function test_overdue_at_3_14_and_30_days_then_weekly_and_it_never_writes_a_reminder(): void
    {
        $invoice = $this->issued();
        $made = count($this->gmail->created);

        $this->at('2026-07-07 09:13');
        $this->nudge();
        $this->assertSame([], $this->about('overdue'), '2 days overdue: not yet');

        $this->at('2026-07-08 09:13');
        $this->nudge();
        $sent = $this->about('overdue');
        $this->assertCount(1, $sent);
        $this->assertSame('Invoice INV-0001 to Acme Events is 3 days overdue (£650.00).', $sent[0]['text']);
        $this->assertSame([['Make a reminder', Telegram::callbackData('inv_chase', (string) $invoice->id)]], self::buttons(['reply_markup' => ['inline_keyboard' => [$sent[0]['reply_markup']['inline_keyboard'][0]]]]));
        $this->assertSame([['They’ve paid', Telegram::callbackData('inv_paid_wait', (string) $invoice->id)], ['Not yet', Telegram::callbackData('inv_chase_later', (string) $invoice->id)]],
            self::buttons(['reply_markup' => ['inline_keyboard' => [$sent[0]['reply_markup']['inline_keyboard'][1]]]]));
        $this->assertSame($made, count($this->gmail->created), 'Telling you never writes a reminder draft');
        $this->assertSame(0, InvoiceEmail::query()->where('kind', 'reminder')->count());

        foreach (['2026-07-09', '2026-07-15'] as $day) {
            $this->at($day.' 09:13');
            $this->nudge();
        }
        $this->assertCount(1, $this->about('overdue'), 'Nothing between the stages');
        $this->at('2026-07-19 09:13');
        $this->nudge();
        $this->assertCount(2, $this->about('overdue'), 'At 14 days');
        $this->at('2026-08-04 09:13');
        $this->nudge();
        $this->assertCount(3, $this->about('overdue'), 'At 30 days');
        $this->at('2026-08-10 09:13');
        $this->nudge();
        $this->assertCount(3, $this->about('overdue'));
        $this->at('2026-08-11 09:13');
        $this->nudge();
        $this->assertCount(4, $this->about('overdue'), 'Then weekly');
        $this->assertSame(0, InvoiceEmail::query()->where('kind', 'reminder')->count(), 'Never a reminder on a schedule');
        $this->assertNull(AuditEvent::query()->where('action', 'invoice.reminder_drafted')->first());
    }

    public function test_make_a_reminder_is_your_press_and_then_send_it_is_offered(): void
    {
        $invoice = $this->issued();
        $this->at('2026-07-08 09:13');

        // Your press: the reminder is written as your Gmail draft (ChaseLadder::make, one tap), nothing sent.
        $message = app(TodoActions::class)->run('inv_chase', (string) $invoice->id, null, Fingerprints::of('inv_chase', (string) $invoice->id));
        $this->assertSame('A reminder for INV-0001 (3 days overdue) is in your Gmail drafts, ready to send.', $message);
        $row = InvoiceEmails::open($invoice, 'reminder');
        $this->assertSame(['reminder', 'drafted'], [$row->kind, $row->status]);
        $this->assertSame('you', AuditEvent::query()->where('action', 'invoice.reminder_drafted')->sole()->actor);

        // The next nudge offers Send it: the draft is exactly as Expend made it, to the address the invoice went to.
        $this->nudge();
        $sent = $this->about('overdue')[0];
        $this->assertStringEndsWith('A reminder is in your Gmail drafts.', $sent['text']);
        $this->assertSame(['Send it', Telegram::callbackData('inv_chase_send', (string) $invoice->id)], self::buttons($sent)[0]);

        // Edited in Gmail: no one-tap Send (the to-do list and the press check again).
        $this->gmail->edit($row->gmail_draft_id, [], 'My own words');
        try {
            app(InvoiceEmails::class)->sendOneTap($invoice->fresh(), 'reminder');
            $this->fail('A one-tap Send of an edited reminder is refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Not sent: ', $e->getMessage());
        }
        $this->assertSame(1, count($this->gmail->sent()), 'Only the invoice itself went');
    }

    public function test_not_sent_on_day_1_and_day_3_then_weekly_with_send_it_only_when_safe(): void
    {
        $first = $this->issued();
        // A second invoice to the same client, left in Gmail drafts: the address an earlier invoice went to, so one tap is safe.
        $invoice = $this->issued($this->job('Gala dinner', po: false), send: false);

        $this->at('2026-06-05 18:00');
        $this->nudge();
        $this->assertSame([], $this->about('not sent'), 'Not on the day it was issued');
        $this->at('2026-06-06 09:13');
        $this->nudge();
        $day1 = $this->about('not sent');
        $this->assertCount(1, $day1);
        $this->assertSame('INV-0002 (Gala dinner) is issued but not sent: it’s in your Gmail drafts.', $day1[0]['text']);
        $buttons = self::buttons($day1[0]);
        $this->assertSame(['Send it', Telegram::callbackData('inv_gmail_send', (string) $invoice->id)], $buttons[0]);
        $this->assertSame('Open in Gmail', $buttons[1][0]);
        $this->assertStringStartsWith(Telegram::GMAIL, $buttons[1][1]);
        $this->assertSame(['I sent it myself', Telegram::callbackData('inv_sent_self', (string) $invoice->id)], $buttons[2]);
        $this->assertSame(['Open the invoice', url('/invoices/'.$invoice->id)], $buttons[3]);

        foreach (['2026-06-07' => 1, '2026-06-08' => 2, '2026-06-09' => 2, '2026-06-14' => 2, '2026-06-15' => 3] as $day => $count) {
            $this->at($day.' 09:13');
            $this->nudge();
            $this->assertCount($count, $this->about('not sent'), $day);
        }

        // One tap from Telegram: checked again, live, then sent exactly as Expend made it.
        $this->assertSame('Invoice INV-0002 is on its way to accounts@acme.example.test.', app(TodoActions::class)->run('inv_gmail_send', (string) $invoice->id, null, Fingerprints::of('inv_gmail_send', (string) $invoice->id)));
        $this->assertNotNull($invoice->fresh()->sent_at);
        $this->assertNotNull($first->fresh()->sent_at);
        $this->at('2026-06-22 09:13');
        $this->nudge();
        $this->assertCount(3, $this->about('not sent'), 'Sent: no more');
    }

    public function test_an_invoice_first_seen_long_after_it_was_issued_goes_weekly(): void
    {
        $this->issued(send: false);
        // First looked at three weeks after it was issued (made before this release): told once, then weekly.
        foreach (['2026-06-26' => 1, '2026-06-27' => 1, '2026-07-02' => 1, '2026-07-03' => 2] as $day => $count) {
            $this->at($day.' 09:13');
            $this->nudge();
            $this->assertCount($count, $this->about('not sent'), $day);
        }
    }

    public function test_the_first_invoice_to_an_address_is_never_one_tap(): void
    {
        $invoice = $this->issued(send: false);
        $this->at('2026-06-06 09:13');
        $this->nudge();
        $buttons = array_column(self::buttons($this->about('not sent')[0]), 0);
        $this->assertNotContains('Send it', $buttons, 'No earlier invoice to this client went to that address');
        $this->assertContains('I sent it myself', $buttons);
        $this->assertNull($invoice->fresh()->sent_at);
    }

    public function test_quiet_hours_and_job_days(): void
    {
        $this->issued(send: false);
        $this->at('2026-06-06 22:00');
        $this->artisan('ledger:invoice-nudges')->expectsOutput('Quiet hours (21:00 to 08:00): nothing sent.')->assertSuccessful();
        $this->assertSame([], $this->messages);

        // A job day: "not sent" waits, at most 2 days.
        WorkJob::query()->create(['title' => 'Festival', 'client_id' => $this->client()->id, 'starts_on' => '2026-06-06', 'ends_on' => '2026-06-09', 'status' => 'booked']);
        $this->at('2026-06-06 09:13');
        $this->nudge();
        $this->assertSame([], $this->about('not sent'), 'A job day: it waits');
        $this->at('2026-06-07 09:13');
        $this->nudge();
        $this->assertSame([], $this->about('not sent'));
        $this->at('2026-06-08 09:13');
        $this->nudge();
        $this->assertCount(1, $this->about('not sent'), 'Two days is the most it waits');
    }

    public function test_a_deleted_gmail_draft_is_said_once(): void
    {
        $invoice = $this->issued(send: false);
        $this->gmail->deleteByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $this->assertSame('deleted', app(InvoiceEmails::class)->check(InvoiceEmails::open($invoice)));

        $this->at('2026-06-06 09:13');
        $this->nudge();
        $deleted = $this->about('deleted without being sent');
        $this->assertCount(1, $deleted);
        $this->assertSame('The Gmail draft for INV-0001 was deleted without being sent.', $deleted[0]['text']);
        $this->assertSame([['Put it back in Gmail', Telegram::callbackData('inv_to_gmail', (string) $invoice->id)], ['I sent it another way', Telegram::callbackData('inv_sent_self', (string) $invoice->id)]],
            self::buttons($deleted[0]));
        $this->assertSame([], $this->about('not sent'), 'One message for it that day');
        $this->at('2026-06-13 09:13');
        $this->nudge();
        $this->assertCount(1, $this->about('deleted without being sent'), 'Once');
    }

    public function test_cant_see_gmail_is_said_once_and_not_sent_waits_meanwhile(): void
    {
        $this->issued(send: false);
        Setting::put(InvoiceEmails::BLIND_SETTING, ['since' => '2026-06-05T12:00:00+00:00', 'why' => 'Google ended the connection']);
        $this->at('2026-06-06 09:13');
        $this->nudge();
        $blind = $this->about('can’t see your Gmail');
        $this->assertCount(1, $blind);
        $this->assertSame([['Reconnect Gmail', url('/settings/integrations#gmail')]], self::buttons($blind[0]));
        $this->assertTrue(InvoiceEmails::cantSee()['told']);
        $this->assertSame([], $this->about('not sent'), 'Nothing says it wasn’t sent while Expend can’t see');
        $this->at('2026-06-08 09:13');
        $this->nudge();
        $this->assertCount(1, $this->about('can’t see your Gmail'), 'Once');
    }

    public function test_a_draft_waiting_after_done_two_days_then_weekly(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $this->client()->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-04',
            'fee_pence' => 65000, 'status' => 'booked']);
        $this->at('2026-06-05 10:00');
        app(JobStatus::class)->markDone($job);
        $draft = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->sole();

        foreach (['2026-06-06' => 0, '2026-06-07' => 1, '2026-06-08' => 1, '2026-06-13' => 1, '2026-06-14' => 2] as $day => $count) {
            $this->at($day.' 09:13');
            $this->nudge();
            $this->assertCount($count, $this->about('still a draft'), $day);
        }
        $message = $this->about('still a draft')[0];
        $this->assertSame('The invoice for Arena load-in is still a draft.', $message['text']);
        $this->assertSame([['Check the invoice', url('/invoices/'.$draft->id)], ['Remind me Monday', Telegram::callbackData('inv_draft_later', (string) $draft->id)]], self::buttons($message));
    }

    public function test_a_reminder_taken_out_of_your_drafts_is_said_once(): void
    {
        $invoice = $this->issued();
        $this->at('2026-07-08 09:13');
        app(TodoActions::class)->run('inv_chase', (string) $invoice->id, null, Fingerprints::of('inv_chase', (string) $invoice->id));
        $this->income(65000, $invoice->job, $invoice, confirmed: false);
        (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), app(ChaseLadder::class));

        $this->at('2026-07-09 09:13');
        $this->nudge();
        $this->assertCount(1, $this->about('The reminder for INV-0001 was taken out of your drafts: a payment may be for it.'));
        $this->nudge();
        $this->assertCount(1, $this->about('taken out of your drafts'), 'Once');
    }

    public function test_the_to_do_items_follow_the_email_and_old_buttons_are_refused_once_it_is_in_gmail(): void
    {
        $this->issued();
        $draft = $this->draftInvoice($this->job('Gala dinner', po: false));
        $invoice = app(InvoiceService::class)->issue($draft->fresh(), '2026-06-05')->fresh();
        $this->at('2026-06-06 09:13');
        $item = fn () => collect(TodoList::build(100)['items'])->firstWhere('key', 'unsent:'.$invoice->id);

        // Issued, not in Gmail yet: put it there (or email it from Expend).
        $this->assertSame('£650.00 to Acme Events: issued but not in Gmail yet', $item()['detail']);
        $this->assertSame(['inv_to_gmail', 'inv_send'], array_column($item()['actions'], 'code'));
        $old = Fingerprints::of('inv_send', (string) $invoice->id);

        // Put it in Gmail: "Email it" isn't offered while its draft is open, and the old button is refused.
        $this->assertSame('Invoice INV-0002 is in your Gmail drafts, ready to send.',
            app(TodoActions::class)->run('inv_to_gmail', (string) $invoice->id, null, Fingerprints::of('inv_to_gmail', (string) $invoice->id)));
        $this->assertSame('£650.00 to Acme Events: it’s in your Gmail drafts', $item()['detail']);
        // "Pick a date" (stage 4 lead decision): I sent it myself means today; another day is picked on the invoice.
        $this->assertSame([['inv_gmail_send', 'Send it'], ['inv_sent_self', 'I sent it myself'], ['link', 'Pick a date']], array_map(fn ($a) => [$a['code'], $a['label']], $item()['actions']));
        $this->expectException(ChangedSince::class);
        app(TodoActions::class)->run('inv_send', (string) $invoice->id, null, $old);
    }

    public function test_the_overdue_item_offers_make_a_reminder_with_gmail(): void
    {
        $invoice = $this->issued();
        $this->at('2026-07-10 09:13');
        $item = collect(TodoList::build(100)['items'])->firstWhere('key', 'invoice_overdue:'.$invoice->id);
        $this->assertSame([['inv_chase', (string) $invoice->id, 'Make a reminder']], array_map(fn ($a) => [$a['code'], $a['id'], $a['label']], $item['actions']));
        app(TodoActions::class)->run('inv_chase', (string) $invoice->id, null, Fingerprints::of('inv_chase', (string) $invoice->id));
        $item = collect(TodoList::build(100)['items'])->firstWhere('key', 'invoice_overdue:'.$invoice->id);
        $this->assertStringEndsWith('A reminder is in your Gmail drafts.', $item['detail']);
        $this->assertSame([['inv_chase_send', 'Send the reminder']], array_map(fn ($a) => [$a['code'], $a['label']], $item['actions']));
    }

    public function test_the_schedule_tells_and_checks_but_never_writes_a_reminder(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $needle) => $events->first(fn (Event $e) => str_contains((string) $e->command, $needle) || ($e instanceof CallbackEvent && $e->description === $needle));

        $nudges = $find('ledger:invoice-nudges');
        $this->assertSame(['13 9 * * *', 'Europe/London', true], [$nudges->expression, (string) $nudges->timezone, $nudges->withoutOverlapping]);
        $sync = $find('ledger:sync-drafts');
        $this->assertSame(['Europe/London', true], [(string) $sync->timezone, $sync->withoutOverlapping]);
        $this->assertStringNotContainsString('--before-rollback', (string) $sync->command);
        $check = $find('invoice-emails-check');
        $this->assertNotNull($check);
        $this->assertSame(['23 * * * *', true], [$check->expression, $check->withoutOverlapping]);
        $this->assertNull($events->first(fn (Event $e) => str_contains(strtolower((string) ($e->description ?? '').' '.(string) $e->command), 'chase')), 'Nothing scheduled makes a reminder');
        $this->assertDoesNotMatchRegularExpression('/use App\\\\Domain\\\\Invoices\\\\ChaseLadder;|ChaseLadder::class|new ChaseLadder/', (string) file_get_contents(base_path('routes/console.php')),
            'The schedule never reaches ChaseLadder');
        $this->assertSame(1, $events->filter(fn (Event $e) => str_contains((string) $e->command, 'ledger:invoice-nudges'))->count(), 'Scheduled once');
        $this->assertSame('ledger:invoice-nudges', (new InvoiceNudges)->getName());
    }

    public function test_an_invoice_sent_late_is_overdue_from_when_its_terms_run_out_after_it_was_sent(): void
    {
        $emails = app(InvoiceEmails::class);
        $invoice = $emails->issueToGmail($this->draftInvoice(), '2026-06-05')['invoice']; // due 5 July (30 days)
        // Sent from Gmail on 1 July: the client has its 30 days from then (31 July).
        $this->at('2026-07-01 10:00');
        $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        $emails->check(InvoiceEmails::open($invoice));

        $this->at('2026-07-08 09:13');
        $this->nudge();
        $this->assertSame([], $this->about('overdue'), 'Not overdue until 31 July');
        $this->assertNull(collect(TodoList::build(100)['items'])->firstWhere('key', 'invoice_overdue:'.$invoice->id));

        $this->at('2026-08-03 09:13'); // 3 days after 31 July
        $this->nudge();
        $this->assertSame('Invoice INV-0001 to Acme Events is 3 days overdue (£650.00).', $this->about('overdue')[0]['text'] ?? null);
        $item = collect(TodoList::build(100)['items'])->firstWhere('key', 'invoice_overdue:'.$invoice->id);
        // The due date printed on the invoice (as its page says), then when it became overdue: one date each, not
        // "due 31 Jul" for an invoice dated due 5 July.
        $this->assertStringContainsString('due 5 Jul 2026, sent late so overdue since 31 Jul 2026 (3 days)', $item['detail']);
        $this->assertSame('2026-07-31', $item['due_on']);
    }

    public function test_the_weekly_overdue_nudge_counts_london_days_not_seconds(): void
    {
        $this->issued();
        $this->at('2026-08-04 09:13:40'); // 30 days overdue
        $this->nudge();
        $this->at('2026-08-11 09:13:05'); // a week later, the run gets to it 35 seconds sooner
        $this->nudge();
        $this->assertCount(2, $this->about('overdue'));
        $this->at('2026-08-17 09:13');
        $this->nudge();
        $this->assertCount(2, $this->about('overdue'), 'Not twice in a week');
    }

    public function test_a_dry_run_leaves_the_cant_see_gmail_message_for_the_real_run(): void
    {
        $this->issued(send: false);
        Setting::put(InvoiceEmails::BLIND_SETTING, ['since' => '2026-06-05T12:00:00+00:00', 'why' => 'Google ended the connection']);
        $this->at('2026-06-06 09:13');
        $this->artisan('ledger:invoice-nudges', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame([], $this->messages);
        $this->assertFalse((bool) (InvoiceEmails::cantSee()['told'] ?? false));

        $this->nudge();
        $this->assertCount(1, $this->about('can’t see your Gmail'));
    }

    public function test_the_monday_digest_offers_send_it_only_when_one_tap_is_safe_and_a_refused_press_says_so(): void
    {
        $invoice = $this->issued(send: false); // the first invoice to Acme: no earlier invoice went to that address
        $item = collect(TodoList::build(100)['items'])->firstWhere('key', 'unsent:'.$invoice->id);
        $this->assertNotContains('inv_gmail_send', array_column($item['actions'], 'code'));
        $this->assertContains('inv_sent_self', array_column($item['actions'], 'code'));

        // A stale [Send it] pressed anyway: refused, and said as a refusal (Telegram's ⚠, the web's error).
        try {
            app(TodoActions::class)->run('inv_gmail_send', (string) $invoice->id, null, Fingerprints::of('inv_gmail_send', (string) $invoice->id));
            $this->fail('Refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringStartsWith('Not sent: no earlier invoice to this client went to that address', $e->getMessage());
        }
        $this->assertNull($invoice->fresh()->sent_at);

        // The next invoice to that address can go with one tap.
        $this->gmail->sendByHand(InvoiceEmails::open($invoice)->gmail_draft_id);
        app(InvoiceEmails::class)->check(InvoiceEmails::open($invoice));
        $second = $this->issued($this->job('Gala dinner', [], false), send: false);
        $item = collect(TodoList::build(100)['items'])->firstWhere('key', 'unsent:'.$second->id);
        $this->assertContains('inv_gmail_send', array_column($item['actions'], 'code'));
    }

    public function test_at_most_one_message_per_invoice_a_day_counting_a_reminder_taken_out(): void
    {
        $invoice = $this->issued();
        $this->at('2026-07-08 09:13');
        $this->nudge();
        app(TodoActions::class)->run('inv_chase', (string) $invoice->id, null, Fingerprints::of('inv_chase', (string) $invoice->id));
        // A payment that may be for it comes in: Expend takes its own reminder out.
        $this->at('2026-07-18 10:23');
        $this->income(65000, $invoice->job, $invoice, confirmed: false, on: '2026-07-18');
        (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), app(ChaseLadder::class));

        $this->messages = [];
        $this->at('2026-07-19 09:13'); // 14 days overdue
        $this->nudge();
        $this->assertSame(['The reminder for INV-0001 was taken out of your drafts: a payment may be for it.'], array_column($this->about('INV-0001'), 'text'));
        $this->at('2026-07-20 09:13');
        $this->nudge();
        $this->assertCount(2, $this->about('INV-0001'), 'The overdue nudge the next day');
    }

    public function test_not_sent_messages_come_back_once_gmail_answers_again_with_no_draft_open(): void
    {
        $first = $this->issued(send: false);
        $this->gmail->status = 401; // Google ends the connection
        (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), app(ChaseLadder::class));
        $this->assertNotNull(InvoiceEmails::cantSee());

        // Reconnected; you say you sent INV-0001 yourself, and issue INV-0002 with Issue only: no draft is open.
        $this->gmail->status = null;
        $this->at('2026-06-06 12:00');
        app(TodoActions::class)->run('inv_sent_self', (string) $first->id, null, Fingerprints::of('inv_sent_self', (string) $first->id));
        $second = app(InvoiceService::class)->issue($this->draftInvoice($this->job('Gala dinner', [], false))->fresh(), '2026-06-06')->fresh();
        $this->assertSame(0, InvoiceEmail::query()->open()->count());

        // While Gmail still says no, the flag stays; the first hourly check it answers clears it.
        $this->gmail->status = 401;
        (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), app(ChaseLadder::class));
        $this->assertNotNull(InvoiceEmails::cantSee());
        $this->gmail->status = null;
        (new CheckInvoiceEmails)->handle(app(InvoiceEmails::class), app(ChaseLadder::class));
        $this->assertNull(InvoiceEmails::cantSee());

        $this->at('2026-06-07 09:13');
        $this->nudge();
        $this->assertCount(1, $this->about($second->number));
    }

    public function test_a_follow_up_draft_isnt_nudged_as_the_jobs_invoice_still_a_draft(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $this->client()->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-04',
            'fee_pence' => 65000, 'status' => 'booked']);
        app(JobStatus::class)->markDone($job);
        $first = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->sole();
        app(InvoiceService::class)->issue($first->fresh(), '2026-06-05');
        $followUp = app(InvoiceService::class)->draftForJob($job->fresh());
        $this->assertNotSame($first->id, $followUp->id);

        $this->at('2026-06-20 09:13');
        $this->nudge();
        $this->assertSame([], $this->about('is still a draft'));
        $this->assertNotNull(collect(TodoList::build(100)['items'])->firstWhere('key', 'draft:'.$followUp->id), 'It is the Monday digest’s draft: item');
    }
}
