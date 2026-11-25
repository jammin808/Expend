<?php

namespace Tests\Feature\Jobs;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostRef;
use App\Domain\Clients\ClientIds;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\ChangedSince;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S10: a job's one to-do item as it moves on (Is it done?, then its invoice to check), the Done, Not yet and
 * Cancelled presses on the to-do page and in Telegram with their follow-up (Check the invoice, Undo), the flash 'undo'
 * the to-do page's toast uses, drafts that aren't a job's first invoice, costs charged on the client's form a month
 * ago, costs linked after the invoice went, and "Remind me Monday".
 */
class JobDoneFlowTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    /** @var list<array> what Telegram was asked to do */
    private array $telegram = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCharges('2026-06-10 10:00:00');
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_webhook_secret', 'secret');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => function (Request $r) {
            $this->telegram[] = ['method' => basename(parse_url($r->url(), PHP_URL_PATH))] + $r->data();

            return Http::response(['ok' => true, 'result' => ['message_id' => count($this->telegram)]]);
        }]);
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    private function booked(string $title = 'Arena load-in', string $on = '2026-06-08'): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => Client::query()->firstOrCreate(['name' => 'Acme Events'])->id, 'starts_on' => $on, 'ends_on' => $on,
            'fee_pence' => 65000, 'status' => 'booked']);
    }

    private function item(string $key): ?array
    {
        return collect(TodoList::build(100)['items'])->firstWhere('key', $key);
    }

    private function fp(string $code, string $id): ?string
    {
        return Fingerprints::of($code, $id);
    }

    /** A press in Telegram, on a message sent now (handled as the queued webhook job handles it; the queue is faked here). */
    private function press(string $data, string $queryId = 'q1'): void
    {
        Telegram::handleButton(['id' => $queryId, 'data' => $data, 'message' => ['message_id' => 7, 'date' => now()->getTimestamp(), 'chat' => ['id' => '42'], 'text' => 'Is it done?']],
            app(TodoActions::class));
    }

    private function edited(): ?array
    {
        return collect($this->telegram)->where('method', 'editMessageText')->last();
    }

    public function test_is_it_done_then_its_invoice_to_check_with_undo_in_the_toast(): void
    {
        $job = $this->booked();
        $item = $this->item('job_done:'.$job->id);
        $this->assertSame('Is Arena load-in done?', $item['title']);
        $this->assertSame('Acme Events · last day was Mon 8 Jun 2026. Marking it done gets its invoice ready to check.', $item['detail']);
        $this->assertSame(['job_done', 'job_not_yet', 'job_cancel'], array_column($item['actions'], 'code'));
        $this->assertNull($this->item('invoice:'.$job->id), 'One item per job: it replaces "Invoice <job>"');

        // Done, from the to-do page: the job is done, its draft made, and the toast offers Undo bound to what it left.
        $this->post('/todo/job_done/'.$job->id, ['fp' => $this->fp('job_done', (string) $job->id)])
            ->assertSessionHas('success', 'Marked done. Its invoice is ready to check.')
            ->assertSessionHas('undo', fn (array $u) => $u['code'] === 'job_reopen' && $u['id'] === (string) $job->id && $u['fp'] === $this->fp('job_reopen', (string) $job->id))
            ->assertSessionHas('follow_up', fn (array $f) => collect($f)->contains(fn ($b) => ($b['label'] ?? '') === 'Check the invoice' && str_contains($b['href'] ?? '', '/invoices/')));
        $draft = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->sole();
        $this->assertSame('done', $job->fresh()->status);
        $this->assertSame('you', AuditEvent::query()->where('action', 'job.done')->sole()->actor);

        $item = $this->item('invoice:'.$job->id);
        $this->assertSame('Check and issue the invoice for Arena load-in', $item['title']);
        $this->assertSame('Acme Events · Draft · 1 line · £650.00', $item['detail']);
        $this->assertSame('/invoices/'.$draft->id, $item['href']);
        $this->assertNull($this->item('job_done:'.$job->id));

        // The toast's Undo: back to booked, the untouched draft removed.
        $this->post('/todo/job_reopen/'.$job->id, ['fp' => $this->fp('job_reopen', (string) $job->id)])->assertSessionHas('success', 'Back to booked.');
        $this->assertSame(['booked', null], [$job->fresh()->status, $job->fresh()->done_at]);
        $this->assertNull(Invoice::query()->find($draft->id));
        $this->assertNotNull($this->item('job_done:'.$job->id));
    }

    public function test_the_page_shares_the_flash_undo(): void
    {
        $job = $this->booked();
        $this->withoutVite();
        $this->post('/todo/job_done/'.$job->id, ['fp' => $this->fp('job_done', (string) $job->id)]);
        $this->get('/todo')->assertOk()->assertInertia(fn ($page) => $page->where('flash.undo.code', 'job_reopen')->where('flash.undo.id', (string) $job->id)
            ->where('items', fn ($items) => collect($items)->every(fn ($i) => array_key_exists('snooze_reasons', $i))));
    }

    public function test_a_done_press_offered_on_other_dates_is_refused(): void
    {
        $job = $this->booked();
        $fp = $this->fp('job_done', (string) $job->id);
        $job->update(['ends_on' => '2026-06-09']);

        $this->expectException(ChangedSince::class);
        app(TodoActions::class)->run('job_done', (string) $job->id, null, $fp);
    }

    public function test_done_in_telegram_puts_check_the_invoice_and_undo_on_the_message(): void
    {
        $job = $this->booked();
        $this->press(Telegram::callbackData('job_done', (string) $job->id));

        $edit = $this->edited();
        $this->assertStringContainsString('✓ Marked done. Its invoice is ready to check.', $edit['text']);
        $buttons = collect($edit['reply_markup']['inline_keyboard'])->flatten(1);
        $this->assertSame(Telegram::callbackData('job_reopen', (string) $job->id), $buttons->firstWhere('text', 'Undo')['callback_data']);
        $draft = Invoice::query()->where('work_job_id', $job->id)->sole();
        $this->assertSame(url('/invoices/'.$draft->id), $buttons->firstWhere('text', 'Check the invoice')['url']);

        // Undo from the same message.
        $this->press($buttons->firstWhere('text', 'Undo')['callback_data'], 'q2');
        $this->assertSame('booked', $job->fresh()->status);
        $this->assertArrayNotHasKey('reply_markup', $this->edited(), 'Nothing more to offer: the buttons go');
    }

    public function test_not_yet_asks_again_in_three_days_and_can_be_undone(): void
    {
        $job = $this->booked();
        $this->press(Telegram::callbackData('job_not_yet', (string) $job->id));

        $this->assertStringContainsString('OK, I’ll ask again on Sat 13 Jun. If it has more days, add them and I’ll ask after the last one.', $this->edited()['text']);
        $row = TodoDismissal::query()->where('key', 'job_done:'.$job->id)->sole();
        $this->assertSame(['Not finished yet', '2026-06-13 00:00:00'], [$row->note, $row->until->format('Y-m-d H:i:s')]);
        $this->assertNull($this->item('job_done:'.$job->id));
        $this->assertNull($this->item('invoice:'.$job->id), 'Not finished: not to invoice either');
        $buttons = collect($this->edited()['reply_markup']['inline_keyboard'])->flatten(1);
        $this->assertSame(url('/jobs/'.$job->id.'#days'), $buttons->firstWhere('text', 'Add days')['url']);

        $this->press($buttons->firstWhere('text', 'Undo')['callback_data'], 'q2');
        $this->assertNotNull($this->item('job_done:'.$job->id), 'Asked again');
        $this->assertSame(['later' => 'Later', 'waiting' => 'Waiting to hear back', 'not_finished' => 'Not finished yet', 'other' => 'Other'],
            Dismissals::reasonsFor('job.done_due', true), 'The snooze reason is offered for "Is it done?" only');
        $this->assertArrayNotHasKey('not_finished', Dismissals::reasonsFor(null, true));
    }

    public function test_cancelled_asks_what_they_owe_then_undo_restores_it(): void
    {
        $job = $this->booked();
        $this->press(Telegram::callbackData('job_cancel', (string) $job->id));
        $this->assertStringContainsString('Do they owe you anything for Arena load-in?', $this->edited()['text']);
        $choices = collect($this->edited()['reply_markup']['inline_keyboard'])->flatten(1);
        $this->assertSame(['No', 'Just my costs', 'A cancellation fee…'], $choices->pluck('text')->all());
        $this->assertSame('booked', $job->fresh()->status, 'Asking changes nothing');

        $this->press($choices->firstWhere('text', 'No')['callback_data'], 'q2');
        $this->assertSame(['cancelled', 'none'], [$job->fresh()->status, $job->fresh()->cancel_charge]);
        $undo = collect($this->edited()['reply_markup']['inline_keyboard'])->flatten(1)->firstWhere('text', 'Undo');
        $this->assertSame(Telegram::callbackData('job_uncancel', (string) $job->id), $undo['callback_data']);
        $this->press($undo['callback_data'], 'q3');
        $this->assertSame('booked', $job->fresh()->status);
    }

    public function test_a_client_draft_and_a_follow_up_draft_get_their_own_items(): void
    {
        $acme = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($acme, 'mileage', 45);
        $job = $this->job($acme, '2026-06-03', null, ['fee_pence' => 65000]);
        $this->invoice($job, [['job:'.$job->id.'#fee', 65000, true, '1', 'fee']], 'issued', 'INV-0031', true, '2026-06-05');
        // A train linked after the invoice went: owed late, so "Invoice it now" makes the client's draft.
        $tx = $this->tx(-4500, '2026-06-04', 'TRAINLINE');
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)]);
        $ref = CostRef::toId("tx:{$tx->id}#j{$job->id}");

        $message = app(TodoActions::class)->run('cost_invoice_now', $ref, null, $this->fp('cost_invoice_now', $ref));
        $draft = Invoice::query()->where('status', 'draft')->whereNull('work_job_id')->sole();
        $this->assertSame('A draft for Acme Events is ready with it: check it and press Issue: '.url('/invoices/'.$draft->id), $message);
        $item = $this->item('draft:'.$draft->id);
        $this->assertSame('Draft invoice for Acme Events: ready to check', $item['title']);
        $this->assertStringStartsWith('Draft · ', $item['detail']);

        // "Draft the invoice" on a job already invoiced makes a follow-up draft only for what was linked late.
        $followUp = app(TodoActions::class)->run('job_invoice', (string) $job->id, null, $this->fp('job_invoice', (string) $job->id));
        $this->assertStringContainsString('already', $followUp, 'Its late train is on the client’s draft now: nothing late is left');
    }

    public function test_a_cost_claimed_a_month_ago_asks_whether_it_was_paid_back(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $tx = $this->tx(-4500, '2026-05-03', 'TRAINLINE');
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)]);
        $ref = "tx:{$tx->id}#j{$job->id}";
        app(CostCharges::class)->claimed($ref);
        $this->assertNull($this->item('claimed:'.CostRef::toId($ref)), 'Not before a month');

        $this->travelTo('2026-07-15 10:00:00');
        $item = $this->item('claimed:'.CostRef::toId($ref));
        $this->assertSame('Was TRAINLINE 3 May paid back?', $item['title']);
        $this->assertSame(['cost_paid_back', 'cost_not_paid'], array_column($item['actions'], 'code'));
        $this->assertSame(4500, $item['amount']);

        $id = CostRef::toId($ref);
        $this->assertSame('Not paid back: it stays your own cost.', app(TodoActions::class)->run('cost_not_paid', $id, null, $this->fp('cost_not_paid', $id)));
        $this->assertSame('not_paid', CostCharge::query()->where('cost_ref', $ref)->value('outcome'));
        $this->assertNull($this->item('claimed:'.$id));
    }

    public function test_remind_me_monday_sets_the_draft_aside_until_monday(): void
    {
        $job = $this->booked('Gala dinner', '2026-06-01');
        $job->update(['status' => 'done']);
        $draft = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);
        $this->assertNotNull($this->item('invoice:'.$job->id));

        // Wednesday 10 June: until Monday 15 June.
        $this->assertSame('OK, I’ll remind you on Monday 15 Jun.', app(TodoActions::class)->run('inv_draft_later', (string) $draft->id, null, $this->fp('inv_draft_later', (string) $draft->id)));
        $this->assertSame('2026-06-15 00:00:00', TodoDismissal::query()->where('key', 'invoice:'.$job->id)->value('until')?->format('Y-m-d H:i:s'));
        $this->assertNull($this->item('invoice:'.$job->id));
    }

    public function test_two_clients_are_the_same_or_not_never_snoozed(): void
    {
        $a = Client::query()->create(['name' => 'Acme Events']);
        $b = Client::query()->create(['name' => 'ACME Events Ltd']);
        $this->expectException(InvalidArgumentException::class);
        Dismissals::dismiss(ClientIds::dupeKey($a->id, $b->id), 7, 'Later');
    }

    public function test_a_job_long_finished_keeps_invoice_it_and_a_job_paid_by_income_drops_off(): void
    {
        $old = $this->booked('Old show', '2026-03-01');
        $this->assertNull($this->item('job_done:'.$old->id), 'Finished more than 60 days ago: not asked');
        $this->assertSame('Invoice Old show', $this->item('invoice:'.$old->id)['title']);

        $paid = $this->booked('Paid show', '2026-06-05');
        $this->decide($this->tx(65000, '2026-06-09', 'ACME EVENTS'), [$this->income(65000, $paid)]);
        $this->assertNull($this->item('job_done:'.$paid->id), 'Paid for its work: nothing to ask');
        $this->assertNull($this->item('invoice:'.$paid->id));

        // A client who pays without an invoice from you: nothing until 45 days have gone by.
        $none = Client::query()->create(['name' => 'Beta Productions', 'invoice_route' => 'none']);
        $quiet = WorkJob::query()->create(['title' => 'Beta show', 'client_id' => $none->id, 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-20', 'status' => 'done', 'fee_pence' => 30000]);
        $this->assertNull($this->item('invoice:'.$quiet->id));
        $this->travelTo(CarbonImmutable::parse('2026-07-10 10:00:00'));
        $this->assertSame('Beta Productions hasn’t paid for Beta show yet', $this->item('invoice:'.$quiet->id)['title']);
    }
}
