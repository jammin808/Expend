<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantTools;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobFinder;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Suggester;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Domain\Verification\Verifier;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The changes each review fixer asked for in files they didn't own: what has come in for an invoice counted the same
 * way everywhere, amounts kept in their own currency, amended returns and deadline days, calendar checks that go with
 * their link, rule offers worked out together, and the smaller follow-ups.
 */
class ReviewFixesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('business_name', 'Test Freelancer');
        $this->actingAs(User::factory()->create());
    }

    private function job(string $title = 'Gala'): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-'.$title]);
    }

    /** Issued on the given day, due 30 days later. */
    private function issuedInvoice(WorkJob $job, string $amount = '450.00', string $currency = 'GBP', string $issued = '2025-05-15', bool $sent = true): Invoice
    {
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'currency' => $currency, 'bill_to_name' => 'Client Ltd', 'bill_to_email' => 'accounts@client.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => $amount]]);
        $invoice = app(InvoiceService::class)->issue($invoice->fresh(), $issued)->fresh();
        if ($sent) {
            $invoice->forceFill(['sent_at' => $issued.' 10:00:00', 'sent_to' => 'accounts@client.example'])->save();
        }

        return $invoice->fresh();
    }

    private function payment(int $pence, string $name, string $on = '2025-07-01'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'a.csv', 'file_sha256' => 'x', 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'i'.$this->n]);
    }

    private function turnover(): int
    {
        return (int) Category::query()->where('code', 'turnover')->value('id');
    }

    /** Decided in the Inbox as business income for the job, without picking the invoice. */
    private function decideIncome(BankTransaction $tx, WorkJob $job): void
    {
        $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'business_income', 'category_id' => $this->turnover(), 'work_job_id' => $job->id])->assertSessionHasNoErrors();
    }

    private function telegram(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_webhook_secret', 'secret');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    /** @return list<array{text:string, buttons:list<string>}> the messages sent to Telegram */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'sendMessage'))
            ->map(fn ($pair) => ['text' => (string) $pair[0]['text'], 'buttons' => array_column($pair[0]['reply_markup']['inline_keyboard'][0] ?? [], 'callback_data')])->values()->all();
    }

    private function queries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    // ---------------------------------------------------------------- what has come in for an invoice, everywhere

    public function test_an_invoice_paid_by_income_for_its_job_shows_as_paid_everywhere(): void
    {
        $job = $this->job();
        $invoice = $this->issuedInvoice($job);
        $this->decideIncome($this->payment(45000, 'CLIENT LTD'), $job);

        $this->assertSame(45000, $invoice->paidMinor());
        $this->get('/invoices')->assertOk()->assertInertia(fn ($p) => $p->where('invoices.0.status', 'paid')->where('invoices.0.paid_minor', 45000)->where('invoices.0.overdue', false));
        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoice.status', 'paid'));
        $this->get("/jobs/{$job->id}")->assertOk()->assertInertia(fn ($p) => $p->where('invoices.0.status', 'paid')->where('invoices.0.paid_minor', 45000));

        $tools = app(AssistantTools::class);
        $row = $tools->run('invoices', [])['invoices'][0];
        $this->assertSame([45000, false], [$row['paid']['pence'], $row['overdue']]);
        $this->assertSame([], $tools->run('deadlines', [])['overdue_invoices']);
    }

    public function test_income_linked_to_a_voided_invoice_counts_for_the_one_that_replaced_it(): void
    {
        $job = $this->job();
        $first = $this->issuedInvoice($job);
        $tx = $this->payment(45000, 'CLIENT LTD');
        app(AllocationWriter::class)->decide($tx, [[
            'kind' => 'business_income', 'category_id' => $this->turnover(), 'work_job_id' => $job->id, 'amount_pence' => 45000, 'invoice_id' => $first->id,
        ]], 'you');
        app(InvoiceService::class)->void($first->fresh(), 'Wrong address');
        $second = $this->issuedInvoice($job, issued: '2025-05-20');

        // The same answer whether the void one is looked at too or not.
        $this->assertSame(45000, InvoicePayments::received([$second])[$second->id]['paid']);
        $both = InvoicePayments::received([$first->fresh(), $second]);
        $this->assertSame([45000, 45000], [$both[$first->id]['paid'], $both[$second->id]['paid']], 'Shown on the void one, and paying the one that replaced it');
        $this->get('/invoices')->assertOk()->assertInertia(fn ($p) => $p->where('invoices.0.id', $second->id)->where('invoices.0.status', 'paid')->where('invoices.1.status', 'void'));
        $this->assertNull(collect(TodoList::build()['items'])->firstWhere('key', 'invoice_overdue:'.$second->id));
    }

    public function test_the_overdue_nudge_counts_what_has_come_in_like_the_to_do_list(): void
    {
        $this->telegram();
        $paidJob = $this->job('Paid');
        $paid = $this->issuedInvoice($paidJob);
        $this->decideIncome($this->payment(45000, 'PAID LTD'), $paidJob);
        $partJob = $this->job('Part');
        $part = $this->issuedInvoice($partJob);
        $this->decideIncome($this->payment(10000, 'PART LTD'), $partJob);
        $waitingJob = $this->job('Waiting');
        $waiting = $this->issuedInvoice($waitingJob);
        app(AllocationWriter::class)->decide($this->payment(45000, 'WAITING LTD'), AllocationWriter::lines(45000, 'business_income', $this->turnover(), $waitingJob->id), 'matcher', false, 'Looks like it');
        $unpaid = $this->issuedInvoice($this->job('Unpaid'));

        $this->artisan('ledger:invoice-nudges')->expectsOutput('Nudged about 3 overdue invoices.')->assertSuccessful();

        $sent = collect($this->sent())->keyBy(fn ($m) => preg_match('/Invoice (\S+)/', $m['text'], $x) ? $x[1] : '');
        $this->assertArrayNotHasKey($paid->number, $sent->all(), 'Paid in full through its job: not chased');
        $this->assertStringContainsString('£100.00 of it has come in, so £350.00 is still to come.', $sent[$part->number]['text']);
        $this->assertSame([], $sent[$part->number]['buttons'], 'The reminder says no payment has been seen, so it isn’t offered');
        $this->assertStringContainsString('waiting in the Inbox', $sent[$waiting->number]['text']);
        $this->assertSame([], $sent[$waiting->number]['buttons']);
        $this->assertSame([Telegram::callbackData('inv_chase', (string) $unpaid->id)], $sent[$unpaid->number]['buttons']);
        $this->assertNull($paid->fresh()->overdue_nudged_at);
    }

    public function test_the_matcher_doesnt_match_a_payment_to_an_invoice_already_paid_through_its_job(): void
    {
        $job = $this->job();
        $invoice = $this->issuedInvoice($job);
        $this->decideIncome($this->payment(45000, 'CLIENT LTD'), $job);
        $again = $this->payment(45000, 'ANOTHER CLIENT', '2025-08-01');

        app(Suggester::class)->suggestFor(collect([$again]));

        $this->assertNull($again->allocations()->whereNotNull('invoice_id')->first(), "Invoice {$invoice->number} is already paid");
    }

    // ---------------------------------------------------------------- the assistant, and the year page

    public function test_the_assistant_reads_dates_deadlines_and_currencies_as_the_pages_do(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00'));
        $tools = app(AssistantTools::class);
        $dueToday = $this->issuedInvoice($this->job('Today'), issued: today()->subDays(30)->format('Y-m-d'));
        $euros = $this->issuedInvoice($this->job('Paris'), '1000.00', 'EUR', issued: '2026-09-01');
        $rows = collect($tools->run('invoices', [])['invoices'])->keyBy('invoice_id');
        $this->assertSame(today()->format('Y-m-d'), $rows[$dueToday->id]['due']);
        $this->assertFalse($rows[$dueToday->id]['overdue'], 'On its due date it’s still on time');
        $this->assertTrue($rows[$euros->id]['overdue']);
        $this->assertSame('€1,000.00', $rows[$euros->id]['total']['formatted']);

        // A return filed and unlocked to amend isn't overdue; it can be amended until a year after the deadline.
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        TaxYear::track('2024-25', 'filed')->update(['filed_on' => '2025-12-01', 'locked_at' => now()]);
        $this->post('/years/2024-25/lock', ['reason' => 'Correct a receipt'])->assertSessionHas('success');
        $returns = collect($tools->run('deadlines', [])['tax_returns'])->keyBy('tax_year');
        $this->assertSame(['amending', false, '2027-01-31'], [$returns['2024-25']['status'], $returns['2024-25']['overdue'], $returns['2024-25']['amend_by']]);
        $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00'));
        $this->assertArrayNotHasKey('2024-25', collect($tools->run('deadlines', [])['tax_returns'])->keyBy('tax_year')->all(), 'Too late to amend: nothing left to do');
    }

    public function test_the_assistants_calendar_is_in_uk_time(): void
    {
        CalendarEvent::query()->create(['uid' => 'a@cal', 'starts_at' => '2025-07-01 08:00:00', 'ends_at' => '2025-07-01 17:00:00', 'summary' => 'Rig day']);
        CalendarEvent::query()->create(['uid' => 'b@cal', 'starts_at' => '2025-06-30 23:30:00', 'ends_at' => '2025-07-01 01:00:00', 'summary' => 'Late get-in']);
        CalendarEvent::query()->create(['uid' => 'c@cal', 'starts_at' => '2025-07-01 00:00:00', 'ends_at' => '2025-07-02 00:00:00', 'all_day' => true, 'summary' => 'Show']);
        CalendarEvent::query()->create(['uid' => 'd@cal', 'starts_at' => '2025-07-01 23:30:00', 'ends_at' => '2025-07-02 01:00:00', 'summary' => 'Next day, UK time']);

        $out = app(AssistantTools::class)->run('calendar', ['date_from' => '2025-07-01', 'date_to' => '2025-07-01']);

        $this->assertSame([
            ['2025-07-01 00:30', '2025-07-01 02:00', 'Late get-in'],
            ['2025-07-01', '2025-07-01', 'Show'],
            ['2025-07-01 09:00', '2025-07-01 18:00', 'Rig day'],
        ], array_map(fn ($e) => [$e['when'], $e['until'], $e['what']], $out['calendar']));
    }

    public function test_a_return_is_due_not_overdue_on_its_deadline_day_and_not_when_being_amended(): void
    {
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        TaxYear::track('2025-26');
        $this->travelTo(CarbonImmutable::parse('2027-01-31 10:00'));
        $this->get('/years/2025-26')->assertOk()->assertInertia(fn ($p) => $p->where('year.overdue', false));
        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:00:01'));
        $this->get('/years/2025-26')->assertOk()->assertInertia(fn ($p) => $p->where('year.overdue', true));

        TaxYear::track('2024-25', 'filed')->update(['filed_on' => '2025-12-01', 'locked_at' => now()]);
        $this->post('/years/2024-25/lock', ['reason' => 'Correct a receipt'])->assertSessionHas('success');
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('year.status', 'amending')->where('year.overdue', false));
    }

    // ---------------------------------------------------------------- amounts in their own currency

    public function test_the_telegram_digest_and_the_findings_history_keep_each_amount_in_its_currency(): void
    {
        $this->telegram();
        $euros = $this->issuedInvoice($this->job('Paris'), '1000.00', 'EUR');
        $pounds = $this->issuedInvoice($this->job('Leeds'));

        Telegram::sendTodo('42');
        $texts = array_column($this->sent(), 'text');
        $this->assertNotEmpty(preg_grep('/^Invoice '.preg_quote($euros->number, '/').' unpaid \(€1,000\.00\)/', $texts));
        $this->assertNotEmpty(preg_grep('/^Invoice '.preg_quote($pounds->number, '/').' unpaid \(£450\.00\)/', $texts));

        FindingsRegistry::sync();
        $this->assertSame('EUR', Finding::query()->where('key', 'invoice_overdue:'.$euros->id)->value('currency'));
        $this->assertNull(Finding::query()->where('key', 'invoice_overdue:'.$pounds->id)->value('currency'));
        $summary = FindingsRegistry::summary();
        $owed = collect($summary['by_direction'])->firstWhere('direction', 'owed_to_you');
        $this->assertSame(['EUR' => 100000, 'GBP' => 45000], $owed['open'], 'Euros aren’t added into the pounds');
        $this->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p->where('by_direction', fn ($d) => collect($d)->firstWhere('direction', 'owed_to_you')['open'] === ['EUR' => 100000, 'GBP' => 45000]));
        $this->assertSame('EUR', collect($summary['recent'])->firstWhere('key', 'invoice_overdue:'.$euros->id)['currency']);
    }

    public function test_findings_kept_before_get_the_currency_of_what_they_are_about(): void
    {
        $euros = $this->issuedInvoice($this->job('Paris'), '1000.00', 'EUR');
        $pounds = $this->issuedInvoice($this->job('Leeds'));
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Hilton', 'status' => 'booked', 'starts_on' => '2025-05-13', 'ends_on' => '2025-05-14',
            'amount_minor' => 30000, 'currency' => 'USD', 'source' => 'manual', 'evidence' => []]);
        $pocket = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR']);
        $rows = ['invoice_overdue:'.$euros->id => 100000, 'unsent:'.$pounds->id => 45000, 'recharge:'.$stay->id => 30000, 'gap:'.$pocket->id.':2025-05-01:-5000' => -5000, 'no_po:1' => null];
        foreach ($rows as $key => $amount) {
            DB::table('findings')->insert(['key' => $key, 'group' => 'income', 'title' => 'x', 'amount_pence' => $amount, 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('findings')->update(['currency' => null]);

        (require database_path('migrations/2026_11_29_000901_integration_findings_currency.php'))->up();

        $this->assertSame(['invoice_overdue:'.$euros->id => 'EUR', 'unsent:'.$pounds->id => null, 'recharge:'.$stay->id => 'USD', 'gap:'.$pocket->id.':2025-05-01:-5000' => 'EUR', 'no_po:1' => null],
            DB::table('findings')->pluck('currency', 'key')->all());
    }

    // ---------------------------------------------------------------- calendar link checks go with the link

    public function test_a_calendar_links_check_goes_with_the_link(): void
    {
        $job = $this->job('Awards');
        $event = CalendarEvent::query()->create(['uid' => 'e1@cal', 'starts_at' => '2025-05-14 18:00:00', 'ends_at' => '2025-05-14 23:00:00', 'summary' => 'Awards night']);
        $finder = app(JobFinder::class);
        $link = $finder->linkCalendar($event, $job, 'rules', 'Same day and title');
        $event->update(['summary' => 'Dentist']);
        app(Verifier::class)->calendarLink($link);
        $this->assertNotNull(Verification::for('calendar_link', $link->id));

        $finder->unlinkCalendar($event);
        $this->assertNull(Verification::for('calendar_link', $link->id), '“Not this job”: nothing left to check');

        $link = $finder->linkCalendar($event, $job, 'you');
        app(Verifier::class)->calendarLink($link);
        $this->assertNotNull(Verification::for('calendar_link', $link->id));
        $finder->markNotJob($event);
        $this->assertNull(Verification::for('calendar_link', $link->id), '“Not a job”');

        $link = $finder->linkCalendar($event, $job, 'you');
        app(Verifier::class)->calendarLink($link);
        $finder->forgetCalendar($event);
        $this->assertNull(Verification::for('calendar_link', $link->id), 'Link removed');
    }

    public function test_recheck_on_a_calendar_link_that_no_longer_links_a_job_clears_the_old_check(): void
    {
        $job = $this->job('Awards');
        $event = CalendarEvent::query()->create(['uid' => 'e1@cal', 'starts_at' => '2025-05-14 18:00:00', 'summary' => 'Awards night']);
        $link = app(JobFinder::class)->markNotJob($event);
        $stale = fn (int $id) => Verification::query()->create(['subject_type' => 'calendar_link', 'subject_id' => $id, 'status' => 'mismatch', 'method' => 'calendar',
            'checks' => [], 'summary' => 'Doesn’t match', 'checked_at' => now()]);
        $stale($link->id);
        $stale(9999);

        $this->assertSame('Nothing to check any more.', app(TodoActions::class)->run('recheck', 'calendar_link:'.$link->id));
        $this->assertSame('Nothing to check any more.', app(TodoActions::class)->run('recheck', 'calendar_link:9999'));
        $this->assertSame(0, Verification::query()->where('subject_type', 'calendar_link')->count());

        // Left over from before: removed by the repair, except a check of a link that still puts an entry on a job.
        $onJob = app(JobFinder::class)->linkCalendar(CalendarEvent::query()->create(['uid' => 'e2@cal', 'starts_at' => '2025-05-14 09:00:00', 'summary' => 'Awards rig']), $job, 'you');
        $stale($link->id);
        $stale(9999);
        $stale($onJob->id);
        (require database_path('migrations/2026_11_29_000902_integration_drop_stale_calendar_checks.php'))->up();
        $this->assertSame([$onJob->id], Verification::query()->where('subject_type', 'calendar_link')->pluck('subject_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_calendar_entries_saved_one_by_one_keep_their_key(): void
    {
        $event = CalendarEvent::query()->create(['uid' => 'x@cal', 'recurrence_id' => '2025-05-14 18:00:00', 'starts_at' => '2025-05-15 18:00:00', 'summary' => 'Moved']);

        $this->assertSame(CalendarEvent::keyFor('x@cal', '2025-05-14 18:00:00'), $event->fresh()->event_key);
    }

    // ---------------------------------------------------------------- rule offers, worked out together

    public function test_rule_offers_take_the_same_few_queries_however_many_merchants(): void
    {
        $software = Category::query()->where('code', 'software')->value('id');
        $merchants = function (int $from, int $to) use ($software) {
            for ($m = $from; $m <= $to; $m++) {
                foreach (['2025-06-01', '2025-06-02'] as $on) {
                    $this->post('/items/bank/'.$this->payment(-999 - $m, 'App '.$m, $on)->id.'/decide', ['kind' => 'business_expense', 'category_id' => $software])->assertSessionHasNoErrors();
                }
            }
        };
        $merchants(1, 3);
        $this->payment(-1003, 'App 3', '2025-07-01'); // one waiting
        $this->payment(2000, 'App 3', '2025-07-02'); // a refund: the other way round, so not counted

        $learner = app(RuleLearner::class);
        $few = $this->queries(fn () => $learner->offers(PHP_INT_MAX));
        $merchants(4, 15);
        $offers = null;
        $many = $this->queries(function () use ($learner, &$offers) {
            $offers = $learner->offers(PHP_INT_MAX);
        });

        $this->assertCount(15, $offers);
        $this->assertSame($few, $many, 'No queries for each merchant');
        $this->assertLessThan(15, $many);
        // The same offers as looking at each merchant on its own.
        $this->assertEquals(array_map(fn ($o) => $learner->offer($o['merchant_key']), $offers), $offers);
        $this->assertSame(1, collect($offers)->firstWhere('merchant_key', 'APP 3')['waiting']);
        $this->assertCount(3, $learner->offers(3));
    }

    // ---------------------------------------------------------------- the kept to-do list, and the smaller follow-ups

    public function test_the_kept_list_isnt_built_again_minutes_after_visiting_the_to_do_page(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $this->issuedInvoice($this->job());
        $this->get('/todo')->assertOk();
        $this->travel(3)->minutes();

        $this->assertLessThanOrEqual(4, $this->queries(fn () => TodoList::cached(3)), 'Nothing changed: the list kept from the to-do page is used');
    }

    public function test_a_reminder_button_isnt_offered_again_the_day_one_went(): void
    {
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00'));
        $invoice = $this->issuedInvoice($this->job());
        $item = fn () => collect(TodoList::build()['items'])->firstWhere('key', 'invoice_overdue:'.$invoice->id);
        $this->assertSame(['inv_chase'], array_column($item()['actions'], 'code'));

        $this->post("/todo/inv_chase/{$invoice->id}", ['fp' => Fingerprints::of('inv_chase', (string) $invoice->id)])->assertSessionHas('success');
        $this->assertSame([], $item()['actions']);
        $this->assertStringContainsString('Reminder sent 20 Nov 2026, 10:00.', $item()['detail']);

        $this->travel(25)->hours();
        $this->assertSame(['inv_chase'], array_column($item()['actions'], 'code'));
    }

    public function test_which_to_dos_can_only_be_snoozed_is_on_the_items(): void
    {
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        TaxYear::track('2024-25');
        $items = collect(TodoList::build()['items'])->keyBy('key');

        $this->assertTrue($items['deadline:2024-25']['snooze_only']);
        // The page carries it too. (Not necessarily first: within a priority the list is ranked by what's at stake, so
        // the year's fines, with money on them, can come before the return itself.)
        $this->get('/todo')->assertOk()->assertInertia(fn ($p) => $p->where('items', fn ($items) => collect($items)->firstWhere('key', 'deadline:2024-25')['snooze_only'] === true));
    }

    public function test_an_action_that_says_why_it_cant_be_done_isnt_logged_as_an_error(): void
    {
        Exceptions::fake();

        $this->post('/todo/recheck/bogus:1')->assertSessionHas('error', 'Unknown check.');

        Exceptions::assertNotReported(InvalidArgumentException::class);
    }

    public function test_learned_rules_show_which_way_they_apply(): void
    {
        $software = Category::query()->where('code', 'software')->value('id');
        foreach (['2025-06-01', '2025-06-02'] as $on) {
            $this->post('/items/bank/'.$this->payment(-5698, 'Adobe', $on)->id.'/decide', ['kind' => 'business_expense', 'category_id' => $software])->assertSessionHasNoErrors();
        }
        $refund = $this->payment(5698, 'Adobe', '2025-07-01');
        app(RuleLearner::class)->accept('ADOBE');

        $this->get('/settings/rules')->assertOk()->assertInertia(fn ($p) => $p->where('rules.0.direction', 'out'));
        $this->assertSame('Adobe already has a rule, for money out only. Change it in Settings › Rules.', app(TodoActions::class)->run('rule_make', (string) $refund->id));
    }

    public function test_a_rule_you_made_yourself_keeps_confirming_on_its_own_when_you_change_it(): void
    {
        $software = Category::query()->where('code', 'software')->value('id');
        $other = Category::query()->where('kind', 'expense')->where('id', '!=', $software)->value('id');
        $this->post('/items/bank/'.$this->payment(-999, 'Notion', '2025-06-01')->id.'/decide', ['kind' => 'business_expense', 'category_id' => $software, 'make_rule' => true]);
        $rule = MerchantRule::query()->sole();
        $this->patch("/settings/rules/{$rule->id}", ['auto_confirm' => true])->assertSessionHas('success');

        $this->post('/items/bank/'.$this->payment(-999, 'Notion', '2025-07-01')->id.'/decide', ['kind' => 'business_expense', 'category_id' => $other, 'make_rule' => true]);

        $rule = $rule->fresh();
        $this->assertSame([(int) $other, true, null], [(int) $rule->category_id, $rule->auto_confirm, $rule->direction], 'What you set in Settings › Rules stays as you set it');
    }

    public function test_the_kept_list_is_forgotten_by_rule_and_to_do_actions(): void
    {
        $this->issuedInvoice($this->job());
        TodoList::build();
        $this->assertNotNull(Cache::get(TodoList::CACHE_KEY));

        $this->post('/rules/learn/decline', ['merchant_key' => 'NOBODY']);
        $this->assertNull(Cache::get(TodoList::CACHE_KEY));
        $this->assertGreaterThan(4, $this->queries(fn () => TodoList::cached()), 'Built afresh');
    }
}
