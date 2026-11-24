<?php

namespace Tests\Feature;

use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobFinder;
use App\Domain\Matching\Suggester;
use App\Domain\Todo\TodoList;
use App\Domain\Verification\Verifier;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Review fixes for the to-do list: paid invoices, counts, snoozes, amended returns, currencies, calendar checks, speed. */
class ReviewFixesTodoListTest extends TestCase
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
        return WorkJob::query()->create(['title' => $title, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1']);
    }

    /** Issued on the given day, due 30 days later. */
    private function issuedInvoice(WorkJob $job, string $amount = '450.00', string $currency = 'GBP', string $issued = '2025-05-15'): Invoice
    {
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'currency' => $currency, 'bill_to_name' => 'Client Ltd', 'bill_to_email' => 'accounts@client.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => $amount]]);

        return app(InvoiceService::class)->issue($invoice->fresh(), $issued)->fresh();
    }

    /** A payment in your statements, waiting to be decided. */
    private function payment(int $pence, string $name, ?string $currency = null, ?int $originalMinor = null, string $on = '2025-06-20'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'a.csv', 'file_sha256' => 'x', 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'p'.$this->n,
            'original_currency' => $currency, 'original_amount_minor' => $originalMinor]);
    }

    /** Decide it in the Inbox as business income for the job, the way the owner does (not by picking the invoice). */
    private function decideIncome(BankTransaction $tx, WorkJob $job): void
    {
        $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'business_income', 'category_id' => Category::query()->where('code', 'turnover')->value('id'), 'work_job_id' => $job->id])
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $tx->fresh()->review_status);
    }

    private function item(string $key, ?array $list = null): ?array
    {
        return collect(($list ?? TodoList::build())['items'])->firstWhere('key', $key);
    }

    // ---------------------------------------------------------------- R5: paid invoices

    public function test_an_invoice_paid_by_income_decided_on_its_job_is_not_listed_or_chased(): void
    {
        $job = $this->job();
        $invoice = $this->issuedInvoice($job);
        $this->assertSame(['inv_chase'], array_column($this->item('invoice_overdue:'.$invoice->id)['actions'], 'code'), 'Unpaid before the payment');

        $this->decideIncome($this->payment(45000, 'Client Ltd'), $job);

        $list = TodoList::build();
        $this->assertNull($this->item('invoice_overdue:'.$invoice->id, $list), 'Paid: not "unpaid", no reminder');
        $this->assertNull($this->item('unsent:'.$invoice->id, $list), 'The client paid it, so it isn’t waiting to be sent');
        $this->assertSame(['paid' => 45000, 'elsewhere' => 0, 'waiting' => 0], TodoList::received([$invoice])[$invoice->id]);
    }

    public function test_a_short_payment_is_part_paid_without_a_no_payment_reminder(): void
    {
        $job = $this->job();
        $invoice = $this->issuedInvoice($job);
        $this->decideIncome($this->payment(44980, 'Client Ltd'), $job);

        $item = $this->item('invoice_overdue:'.$invoice->id);
        $this->assertSame("Invoice {$invoice->number} part-paid", $item['title']);
        $this->assertStringContainsString('£449.80 of £450.00 received', $item['detail']);
        $this->assertStringContainsString('£0.20 still to come', $item['detail']);
        $this->assertSame([], $item['actions'], 'The reminder email says no payment has been seen');
        $this->assertSame(20, $item['amount']);
    }

    public function test_no_reminder_while_a_payment_that_may_be_for_it_waits_in_the_inbox(): void
    {
        $job = $this->job();
        $invoice = $this->issuedInvoice($job);
        $tx = $this->payment(45000, 'Client Ltd');
        app(Suggester::class)->suggestFor(collect([$tx]));
        $this->assertSame($invoice->id, $tx->allocations()->sole()->invoice_id, 'The matcher suggested it for the invoice');

        $item = $this->item('invoice_overdue:'.$invoice->id);
        $this->assertSame([], $item['actions']);
        $this->assertSame('/inbox', $item['href']);
        $this->assertStringContainsString('£450.00 that may be for it is waiting in the Inbox', $item['detail']);
        $this->assertSame(['paid' => 0, 'elsewhere' => 0, 'waiting' => 45000], TodoList::received([$invoice])[$invoice->id]);

        // Accepted: paid.
        $this->post("/todo/tx_accept/{$tx->id}")->assertSessionHas('success');
        $this->assertNull($this->item('invoice_overdue:'.$invoice->id));
    }

    public function test_job_income_pays_its_invoices_oldest_first(): void
    {
        $job = $this->job();
        $first = $this->issuedInvoice($job, '450.00', 'GBP', '2025-05-15');
        $second = $this->issuedInvoice($job, '300.00', 'GBP', '2025-06-15');
        $this->decideIncome($this->payment(45000, 'Client Ltd'), $job);

        $list = TodoList::build();
        $this->assertNull($this->item('invoice_overdue:'.$first->id, $list));
        $this->assertSame("Invoice {$second->number} unpaid", $this->item('invoice_overdue:'.$second->id, $list)['title']);
    }

    public function test_a_euro_invoice_counts_euros_received_and_never_guesses_from_pounds(): void
    {
        // Paid into the euro pocket: €1,000.00, £850.00 by the bank's conversion.
        $job = $this->job('Berlin');
        $invoice = $this->issuedInvoice($job, '1000.00', 'EUR');
        $this->decideIncome($this->payment(85000, 'Client GmbH', 'EUR', 100000), $job);
        $this->assertNull($this->item('invoice_overdue:'.$invoice->id));
        $this->assertSame(['paid' => 100000, 'elsewhere' => 0, 'waiting' => 0], TodoList::received([$invoice])[$invoice->id]);

        // Paid in pounds: can't be measured in euros, so it asks you to check rather than chasing the client.
        $other = $this->job('Vienna');
        $eur = $this->issuedInvoice($other, '1000.00', 'EUR');
        $this->decideIncome($this->payment(85000, 'Client AG'), $other);
        $item = $this->item('invoice_overdue:'.$eur->id);
        $this->assertSame("Invoice {$eur->number}: check it’s been paid", $item['title']);
        $this->assertStringContainsString('€1,000.00 from Client Ltd', $item['detail']);
        $this->assertStringContainsString('£850.00 came in for this job in another currency', $item['detail']);
        $this->assertSame([], $item['actions']);
    }

    public function test_on_a_job_with_pound_and_euro_invoices_euros_pay_the_euro_invoice(): void
    {
        $job = $this->job('Mixed');
        $pounds = $this->issuedInvoice($job, '450.00', 'GBP', '2025-05-15');
        $euros = $this->issuedInvoice($job, '1000.00', 'EUR', '2025-05-20');
        // €1,000.00 into the euro pocket (£850.00 by the bank's conversion), decided as income for the job.
        $this->decideIncome($this->payment(85000, 'Client GmbH', 'EUR', 100000), $job);

        $received = TodoList::received([$pounds, $euros]);
        $this->assertSame(['paid' => 0, 'elsewhere' => 0, 'waiting' => 0], $received[$pounds->id], 'The pound invoice is still unpaid');
        $this->assertSame(['paid' => 100000, 'elsewhere' => 0, 'waiting' => 0], $received[$euros->id]);
        $list = TodoList::build();
        $this->assertNull($this->item('invoice_overdue:'.$euros->id, $list));
        $this->assertSame("Invoice {$pounds->number} unpaid", $this->item('invoice_overdue:'.$pounds->id, $list)['title']);

        // Then £450.00 comes in: both are paid.
        $this->decideIncome($this->payment(45000, 'Client GmbH', on: '2025-06-21'), $job);
        $this->assertSame([45000, 100000], array_column(TodoList::received([$pounds, $euros]), 'paid'));
    }

    public function test_a_pound_invoice_takes_foreign_money_left_once_invoices_in_that_currency_are_paid(): void
    {
        $job = $this->job('Mixed');
        $euros = $this->issuedInvoice($job, '500.00', 'EUR', '2025-05-15');
        $pounds = $this->issuedInvoice($job, '400.00', 'GBP', '2025-05-20');
        // €1,000.00 (£850.00): €500.00 pays the euro invoice, and what's left (£425.00) covers the pound one.
        $this->decideIncome($this->payment(85000, 'Client GmbH', 'EUR', 100000), $job);

        $received = TodoList::received([$euros, $pounds]);
        $this->assertSame(50000, $received[$euros->id]['paid']);
        $this->assertSame(40000, $received[$pounds->id]['paid']);
    }

    public function test_an_unsent_invoice_part_paid_by_a_deposit_is_still_to_be_sent(): void
    {
        $job = $this->job();
        // A £100.00 deposit decided as income for the job, then the £450.00 invoice issued and never emailed.
        $this->decideIncome($this->payment(10000, 'Client Ltd', on: today()->subDays(10)->format('Y-m-d')), $job);
        $invoice = $this->issuedInvoice($job, issued: today()->subDays(3)->format('Y-m-d'));
        $this->assertSame(['paid' => 10000, 'elsewhere' => 0, 'waiting' => 0], TodoList::received([$invoice])[$invoice->id]);

        $item = $this->item('unsent:'.$invoice->id);
        $this->assertNotNull($item, 'Only part of it has come in: the client still needs the invoice');
        $this->assertSame("Send invoice {$invoice->number}", $item['title']);
        $this->assertStringContainsString('£100.00 already received', $item['detail']);
        $this->assertSame(['inv_send'], array_column($item['actions'], 'code'));
        $this->assertSame(35000, $item['amount']);

        // Paid in full: the client has had it.
        $this->decideIncome($this->payment(35000, 'Client Ltd', on: today()->subDay()->format('Y-m-d')), $job);
        $this->assertNull($this->item('unsent:'.$invoice->id));
    }

    // ---------------------------------------------------------------- R26: currencies

    public function test_invoice_amounts_are_shown_in_the_invoice_currency(): void
    {
        $job = $this->job('Paris');
        $draft = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'currency' => 'EUR', 'total_minor' => 0, 'bill_to_name' => 'Client SA', 'po_total_minor' => 100000]);
        app(InvoiceService::class)->updateDraft($draft, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '800.00']]);
        $issued = $this->issuedInvoice($this->job('Lyon'), '1000.00', 'EUR');

        $list = TodoList::build();
        $under = $this->item('underbilled:'.$draft->id, $list);
        $this->assertSame('Draft invoice is €200.00 less than its PO', $under['title']);
        $this->assertStringContainsString('The PO authorises €1,000.00 and the invoice totals €800.00', $under['detail']);
        $this->assertSame('EUR', $under['currency']);
        $overdue = $this->item('invoice_overdue:'.$issued->id, $list);
        $this->assertStringStartsWith('€1,000.00 from Client Ltd', $overdue['detail']);
        $this->assertSame([100000, 'EUR'], [$overdue['amount'], $overdue['currency']]);
        $this->assertStringStartsWith('€1,000.00 to Client Ltd', $this->item('unsent:'.$issued->id, $list)['detail']);
    }

    // ---------------------------------------------------------------- R17, R31: snoozed payments

    public function test_payments_count_only_what_is_still_waiting_and_hidden(): void
    {
        $a = $this->payment(-1200, 'Shop A');
        $b = $this->payment(-1300, 'Shop B');
        $c = $this->payment(-1400, 'Shop C');
        $this->post('/todo/dismiss', ['key' => 'tx:'.$a->id])->assertSessionHas('success');
        $this->post('/todo/dismiss', ['key' => 'tx:'.$b->id])->assertSessionHas('success');
        $this->post("/todo/tx_personal/{$a->id}")->assertSessionHas('success');
        $this->post("/todo/tx_personal/{$b->id}")->assertSessionHas('success');

        $list = TodoList::build();
        $this->assertSame(1, $list['counts']['payments'], 'Only C is waiting');
        $this->assertSame(['tx:'.$c->id], array_column(array_values(array_filter($list['items'], fn ($i) => $i['group'] === 'payments')), 'key'));
        $this->assertSame(array_sum($list['counts']), $list['total']);
        $this->assertGreaterThanOrEqual(1, $list['total']);
    }

    public function test_snoozing_the_payments_shown_shows_the_next_ones(): void
    {
        foreach (range(1, 8) as $i) {
            $this->payment(-1000 - $i, 'Shop '.$i, on: '2025-06-'.(10 + $i));
        }
        $shown = collect(TodoList::build(5)['items'])->where('group', 'payments')->pluck('key');
        $this->assertCount(5, $shown);
        foreach ($shown as $key) {
            $this->post('/todo/dismiss', ['key' => $key, 'days' => 7])->assertSessionHas('success');
        }

        $list = TodoList::build(5);
        $left = collect($list['items'])->where('group', 'payments')->pluck('key');
        $this->assertCount(3, $left, 'The other three show');
        $this->assertEmpty($left->intersect($shown));
        $this->assertSame(3, $list['counts']['payments']);
        $this->assertCount(3, collect(TodoList::build(3)['items'])->where('group', 'payments'));
    }

    /** Merchants you decided the same way twice (business software). */
    private function ruleCandidates(int $merchants): void
    {
        $software = Category::query()->where('code', 'software')->value('id');
        for ($m = 1; $m <= $merchants; $m++) {
            foreach ([1, 2] as $k) {
                $tx = $this->payment(-999, 'App '.$m, on: '2025-06-0'.$k);
                $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'business_expense', 'category_id' => $software])->assertSessionHasNoErrors();
            }
        }
    }

    public function test_rule_offers_are_all_counted_and_snoozing_one_shows_the_next(): void
    {
        $this->ruleCandidates(4);

        $lists = collect([1, 3, 5, 15])->map(fn ($n) => TodoList::build($n));
        $this->assertSame([4, 4, 4, 4], $lists->map(fn ($l) => $l['counts']['rules'] ?? 0)->all(), 'All offers counted, however many are shown');
        $total = $lists[0]['total'];
        $this->assertSame([$total, $total, $total, $total], $lists->pluck('total')->all());
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('todo.total', $total)->where('nav.todo', $total));

        $first = collect(TodoList::build(1)['items'])->firstWhere('group', 'rules');
        $this->post('/todo/dismiss', ['key' => $first['key'], 'days' => 7])->assertSessionHas('success');
        $next = collect(TodoList::build(1)['items'])->firstWhere('group', 'rules');
        $this->assertNotNull($next, 'Another offer shows');
        $this->assertNotSame($first['key'], $next['key']);
        $this->assertSame(3, TodoList::build(1)['counts']['rules']);
    }

    // ---------------------------------------------------------------- R18: a return being amended

    public function test_a_filed_return_being_amended_is_not_overdue(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00'));
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $year = TaxYear::track('2024-25', 'filed');
        $year->update(['filed_on' => '2025-12-01', 'locked_at' => now()]);
        $this->assertNull($this->item('deadline:2024-25'));

        $this->post('/years/2024-25/lock', ['reason' => 'Correct a receipt'])->assertSessionHas('success');
        $this->assertSame('amending', $year->fresh()->status);

        $this->assertEmpty(collect(TodoList::build()['items'])->where('group', 'deadline'), 'Filed on time: not overdue');
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.overdue', false)->where('years.0.deadline', '2027-01-31'));

        // As the last day for amending it gets close, that's the date shown.
        $this->travelTo(CarbonImmutable::parse('2027-01-10 10:00'));
        $this->assertSame('Tax return 2024-25: amend by 31 January 2027', $this->item('deadline:2024-25:amend')['title']);
        $this->travelTo(CarbonImmutable::parse('2027-02-02 10:00'));
        $this->assertEmpty(collect(TodoList::build()['items'])->where('group', 'deadline'));
    }

    public function test_the_amend_by_reminder_shows_until_the_end_of_the_last_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00'));
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        TaxYear::track('2024-25', 'filed')->update(['filed_on' => '2025-12-01', 'locked_at' => now()]);
        $this->post('/years/2024-25/lock', ['reason' => 'Correct a receipt'])->assertSessionHas('success');

        foreach (['2027-01-30 10:00', '2027-01-31 00:00:01', '2027-01-31 10:00', '2027-01-31 23:59'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at));
            $this->assertSame('Tax return 2024-25: amend by 31 January 2027', $this->item('deadline:2024-25:amend')['title'] ?? null, $at);
            $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.amending', true)->where('years.0.amend_open', true)
                ->where('years.0.overdue', false)->where('years.0.deadline', '2027-01-31'));
        }

        // The window has closed: nothing to do, and the dashboard isn't told it's due.
        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:00:01'));
        $this->assertNull($this->item('deadline:2024-25:amend'));
        $this->travelTo(CarbonImmutable::parse('2027-03-01 10:00'));
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.amending', true)->where('years.0.amend_open', false)->where('years.0.overdue', false));
    }

    public function test_a_return_is_due_not_overdue_on_the_deadline_day_itself(): void
    {
        Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        TaxYear::track('2025-26', 'open');

        $this->travelTo(CarbonImmutable::parse('2027-01-31 15:00'));
        $this->assertSame('Tax return 2025-26: due 31 January 2027', $this->item('deadline:2025-26')['title']);
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.overdue', false)->where('years.0.amending', false));

        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:00:01'));
        $this->assertSame('Tax return 2025-26: overdue since 31 January 2027', $this->item('deadline:2025-26')['title']);
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('years.0.overdue', true));
    }

    // ---------------------------------------------------------------- R33: calendar link checks

    public function test_a_failed_check_on_a_calendar_link_goes_when_the_link_does(): void
    {
        $job = WorkJob::query()->create(['title' => 'Awards night', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $event = CalendarEvent::query()->create(['uid' => 'e1@cal', 'starts_at' => '2025-05-14 18:00:00', 'ends_at' => '2025-05-14 23:00:00', 'summary' => 'Awards night']);
        $finder = app(JobFinder::class);
        $link = $finder->linkCalendar($event, $job, 'rules', 'Same day and title');
        $event->update(['summary' => 'Dentist']);
        $this->assertNotSame('verified', app(Verifier::class)->calendarLink($link)->status);
        $key = 'check:calendar_link:'.$link->id;
        $this->assertStringStartsWith('Check a calendar link', $this->item($key)['title']);

        // Unlinked: the check was about a link that no longer puts the entry on a job.
        $finder->unlinkCalendar($event);
        $this->assertNull($this->item($key));

        // Removed altogether ("Undo not a job").
        $finder->markNotJob($event);
        $finder->forgetCalendar($event);
        $this->assertNull($this->item($key));
    }

    // ---------------------------------------------------------------- R6, R32: speed, and one list for the dashboard and badge

    /**
     * Years of history: payments over many merchants, decided by you, finished jobs with documents, invoices paid
     * through matched payments, and failed checks.
     */
    private function seedHistory(int $txs = 3000, int $merchants = 120, int $jobs = 300, int $docs = 600, int $invoices = 250, int $checks = 100): void
    {
        $now = now()->format('Y-m-d H:i:s');
        $software = Category::query()->where('code', 'software')->value('id');
        $turnover = Category::query()->where('code', 'turnover')->value('id');
        $account = DB::table('accounts')->insertGetId(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $batch = DB::table('import_batches')->insertGetId(['account_id' => $account, 'original_filename' => 'a.csv', 'file_sha256' => 'x', 'stored_path' => 'x', 'status' => 'committed', 'created_at' => $now, 'updated_at' => $now]);
        $start = CarbonImmutable::today()->subYears(2);

        $tx = function (int $i, int $amount, string $merchant, string $status) use ($account, $batch, $start, $now) {
            $day = $start->addDays($i % 700);

            return ['account_id' => $account, 'import_batch_id' => $batch, 'posted_on' => $day->format('Y-m-d'), 'tax_year' => UkTaxYear::fromDate($day)->label(),
                'description' => $merchant, 'counterparty' => $merchant, 'merchant_key' => $merchant, 'amount_pence' => $amount, 'raw' => '{}', 'fingerprint' => 'f'.$i,
                'review_status' => $status, 'state' => 'active', 'created_at' => $now, 'updated_at' => $now];
        };
        $rows = [];
        for ($i = 1; $i <= $txs; $i++) {
            $rows[] = $tx($i, -1000 - $i, 'MERCHANT '.($i % $merchants), $i % 10 === 0 ? 'unreviewed' : 'confirmed');
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('bank_transactions')->insert($chunk);
        }
        DB::statement("INSERT INTO allocations (bank_transaction_id, tax_year, kind, category_id, amount_pence, business_use_pct, decided_by, confirmed_at, created_at, updated_at)
            SELECT id, tax_year, 'business_expense', {$software}, amount_pence, 100, 'you', '{$now}', '{$now}', '{$now}' FROM bank_transactions WHERE review_status = 'confirmed'");

        $jobRows = [];
        for ($j = 1; $j <= $jobs; $j++) {
            $day = CarbonImmutable::today()->subDays(10 + $j);
            $jobRows[] = ['title' => 'Show '.$j, 'starts_on' => $day->format('Y-m-d'), 'ends_on' => $day->format('Y-m-d'), 'status' => 'booked',
                'po_number' => $j % 2 ? 'PO-'.$j : null, 'po_not_required' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('work_jobs')->insert($jobRows);
        $jobIds = DB::table('work_jobs')->orderBy('id')->pluck('id')->all();

        $docRows = [];
        for ($d = 1; $d <= $docs; $d++) {
            $docRows[] = ['source' => 'email', 'original_name' => "po-{$d}.pdf", 'sha256' => 'd'.$d, 'stored_path' => 'x', 'kind' => $d % 3 ? 'other' : 'po',
                'status' => 'read', 'work_job_id' => $jobIds[$d % count($jobIds)], 'extraction' => json_encode(['po' => ['po_number' => $d % 3 ? null : 'PO-D'.$d], 'notes' => str_repeat('x', 200)]),
                'received_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($docRows, 200) as $chunk) {
            DB::table('documents')->insert($chunk);
        }

        for ($n = 1; $n <= $invoices; $n++) {
            $issued = CarbonImmutable::today()->subDays(400 - $n);
            $invoice = DB::table('invoices')->insertGetId(['number' => 'INV-'.$n, 'work_job_id' => $jobIds[$n - 1], 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000,
                'issue_date' => $issued->format('Y-m-d'), 'due_date' => $issued->addDays(30)->format('Y-m-d'), 'issued_at' => $now, 'bill_to_name' => 'Client', 'bill_to_email' => 'a@client.example',
                'created_at' => $now, 'updated_at' => $now]);
            $id = DB::table('bank_transactions')->insertGetId($tx($txs + $n, 45000, 'CLIENT '.$n, 'confirmed'));
            DB::table('allocations')->insert(['bank_transaction_id' => $id, 'tax_year' => UkTaxYear::fromDate($start->addDays(($txs + $n) % 700))->label(), 'kind' => 'business_income',
                'category_id' => $turnover, 'work_job_id' => $jobIds[$n - 1], 'invoice_id' => $invoice, 'amount_pence' => 45000, 'decided_by' => 'matcher', 'confirmed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }

        $docIds = DB::table('documents')->orderBy('id')->limit($checks)->pluck('id');
        DB::table('verifications')->insert($docIds->map(fn ($id) => ['subject_type' => 'document', 'subject_id' => $id, 'status' => 'mismatch', 'method' => 'text', 'checks' => '[]',
            'summary' => 'The total doesn’t match the document', 'checked_at' => $now, 'created_at' => $now, 'updated_at' => $now])->all());
    }

    /** @return array{0:int, 1:list<string>, 2:mixed} how many queries it ran, which, and what it returned */
    private function queries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $out = $fn();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return [count($log), $log, $out];
    }

    public function test_building_the_list_does_not_query_per_item_and_the_dashboard_and_badge_share_it(): void
    {
        $this->seedHistory();

        // Before: 994 queries a build (500 invoice sums, 100 document finds, ...); 2,031 for a cold dashboard and
        // 1,037 for every one after.
        [$n, $log] = $this->queries(fn () => TodoList::build(1));
        $this->assertLessThan(450, $n, 'What remains is mostly the rule offers (RuleLearner, about 6 queries a merchant)');
        $this->assertEmpty(preg_grep('/sum\([`"]amount_pence[`"]\)/i', $log), 'No sum per invoice');
        $this->assertEmpty(preg_grep('/from [`"]documents[`"] where [`"]documents[`"]\.[`"]id[`"] = \?/i', $log), 'No find per failed check');

        // The dashboard and the menu badge use one kept list: built once when it's needed, then only read.
        Cache::flush();
        [$cold, , $first] = $this->queries(fn () => $this->get('/dashboard')->assertOk());
        $this->assertLessThan($n + 80, $cold, 'Built once for both the dashboard and the badge');
        $props = $first->viewData('page')['props'];
        $this->assertSame($props['todo']['total'], $props['nav']['todo']);
        [$warm] = $this->queries(fn () => $this->get('/dashboard')->assertOk());
        $this->assertLessThan(80, $warm);
        [$page] = $this->queries(fn () => $this->get('/inbox')->assertOk());
        $this->assertLessThan(100, $page);
        [$again] = $this->queries(fn () => TodoList::cached(1));
        $this->assertLessThanOrEqual(4, $again);
    }

    /** The Inbox's follow-up request after each decision: only the menu badge and the message are asked for. */
    private function inboxPartial(): array
    {
        $version = app(HandleInertiaRequests::class)->version(request()) ?? '';
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('/inbox', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Inertia-Partial-Component' => 'inbox', 'X-Inertia-Partial-Data' => 'nav,flash'])->assertOk();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return [count($log), $response->json('props.nav.todo'), $log];
    }

    public function test_the_menu_badge_does_not_rebuild_the_list_after_each_inbox_decision(): void
    {
        $this->ruleCandidates(3);
        $txs = collect(range(1, 4))->map(fn ($i) => $this->payment(-1500 - $i, 'Shop '.$i, on: '2025-07-0'.$i));
        $software = Category::query()->where('code', 'software')->value('id');
        [$build] = $this->queries(fn () => TodoList::build(1));
        $this->get('/dashboard')->assertOk();
        $before = Cache::get(TodoList::CACHE_KEY)['total'];

        // Deciding payment after payment: the badge keeps the last total for a while rather than building the list each time.
        foreach ($txs->take(3) as $tx) {
            $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'business_expense', 'category_id' => $software])->assertSessionHasNoErrors();
            [$n, $badge, $log] = $this->inboxPartial();
            $this->assertSame($before, $badge);
            // Building (or even checking) the kept list always starts by reading what's snoozed and the fingerprint.
            $this->assertSame([], array_values(preg_grep('/todo_dismissals|job_candidates| UNION ALL /i', $log)), 'The list isn’t built for the badge');
            $this->assertLessThan($build, $n);
        }
        $this->assertNull(Cache::get(TodoList::CACHE_KEY), 'Not built again since the first decision');

        // The dashboard always shows the list as it is now, and the badge on it agrees.
        $page = $this->get('/dashboard')->assertOk()->viewData('page')['props'];
        $this->assertSame($before - 3, $page['todo']['total']);
        $this->assertSame($page['todo']['total'], $page['nav']['todo']);

        // Elsewhere, the badge catches up within a couple of minutes.
        $this->post("/items/bank/{$txs[3]->id}/decide", ['kind' => 'business_expense', 'category_id' => $software])->assertSessionHasNoErrors();
        $this->assertSame($before - 3, $this->inboxPartial()[1]);
        $this->travel(3)->minutes();
        $this->assertSame($before - 4, $this->inboxPartial()[1]);
        $this->assertSame($before - 4, TodoList::build()['total']);
    }

    public function test_the_kept_list_is_built_afresh_when_anything_it_shows_changes(): void
    {
        Mail::fake();
        $invoice = $this->issuedInvoice($this->job(), issued: today()->subDays(5)->format('Y-m-d'));
        $keys = fn () => array_column(TodoList::cached()['items'], 'key');
        $this->assertContains('unsent:'.$invoice->id, $keys());

        // Emailed from the invoice's page.
        $this->post("/invoices/{$invoice->id}/send", [])->assertSessionHas('success');
        $this->assertNotContains('unsent:'.$invoice->id, $keys());

        // A new possible job, a payment, a snooze, and a new day.
        $candidate = JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);
        $this->assertContains('candidate:'.$candidate->id, $keys());
        $tx = $this->payment(-2500, 'Cafe');
        $this->assertContains('tx:'.$tx->id, $keys());
        $this->post('/todo/dismiss', ['key' => 'tx:'.$tx->id, 'days' => 1]);
        $this->assertNotContains('tx:'.$tx->id, $keys());
        $this->travel(2)->days();
        $this->assertContains('tx:'.$tx->id, $keys());

        // A to-do action forgets the kept list.
        $this->post("/todo/tx_personal/{$tx->id}")->assertSessionHas('success');
        $this->assertNotContains('tx:'.$tx->id, $keys());
        $this->assertSame(TodoList::build()['total'], TodoList::cached()['total']);
    }
}
