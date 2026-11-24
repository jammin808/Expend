<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantTools;
use App\Domain\Banks\StarlingSync;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Export\YearExporter;
use App\Domain\Import\Importer;
use App\Domain\Import\LineDetails;
use App\Domain\Import\NormalizedRow;
use App\Domain\Import\RevolutImport;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Tax\Explainer;
use App\Domain\Tax\YearChecks;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Domain\Travel\TravelMatcher;
use App\Domain\Verification\Verifier;
use App\Http\Presenters\Present;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobDay;
use App\Models\JobFind;
use App\Models\Setting;
use App\Models\StatementBalance;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * The changes each fixer asked of files they didn't own, made together: the transfers to-do, an exchange's fee as a
 * bank charge that never breaks its pair, totals worked out line by line, learning from accepted suggestions, own money
 * and overheads kept off a job, a job's evidence from an email's own words, the currency the shop charged, amounts in
 * each currency's own units, held and undone statements, and Starling's feed.
 */
class NewFixesIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private Account $revolut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Setting::put('own_names', ['Alex Smith']);
        Setting::put('job_finder_auto', false);
        OwnAccounts::flush();
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821', 'currency' => 'GBP']);
    }

    private function tx(Account $account, string $date, int $pence, string $description, ?string $counterparty = null, array $extra = []): BankTransaction
    {
        static $n = 0;
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create($extra + [
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => strtoupper($counterparty ?: $description), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $account->id.$date.$pence.$description.$counterparty.(++$n)), 'occurrence' => 1,
        ]);
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function lines(BankTransaction $tx): array
    {
        return $tx->allocations()->with('category')->orderBy('id')->get()->map(fn (Allocation $a) => [$a->kind, $a->amount_pence, $a->category?->code])->all();
    }

    private function exchangeFile(): string
    {
        ExchangeRate::query()->create(['currency' => 'EUR', 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'units_per_gbp' => '1.150000', 'source' => 'hmrc_monthly']);
        $path = tempnam(sys_get_temp_dir(), 'rv').'.csv';
        file_put_contents($path, "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n"
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,500.00,0.00,GBP,COMPLETED,500.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,-100.00,1.00,GBP,COMPLETED,399.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from GBP,115.20,0.00,EUR,COMPLETED,115.20\n");

        return $path;
    }

    // ---------------------------------------------------------------- transfers: the to-do (N74, P4, T7)

    public function test_the_transfers_to_do_shows_both_ways_carries_its_cut_off_and_lists_money_in_one_by_one(): void
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $topUp = $this->tx($this->starling, '2025-07-02', -10000, 'REVOLUT*TOP-UP');
        $alone = $this->tx($this->starling, '2025-08-02', 20000, 'From personal', 'ALEX SMITH');
        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided();

        $list = TodoList::build(50);
        $item = collect($list['items'])->firstWhere('key', 'transfers');
        $this->assertSame('Confirm 3 transfers between your accounts', $item['title']);
        $this->assertStringContainsString('£600.00 out and £500.00 in', $item['detail']);
        $this->assertStringNotContainsString('tax', $item['detail'], 'Confirming them does change what counts');
        $this->assertSame((string) TransferPairer::confirmable()['up_to'], $item['actions'][0]['id'], 'The button carries the last suggestion shown');
        $this->assertNotNull(collect($list['items'])->firstWhere('key', 'tx:'.$alone->id), 'Money paid in on its own is listed to decide one by one');

        // A transfer suggested after the list was built is never confirmed by its button.
        $later = $this->tx($this->starling, '2025-09-02', -7000, 'Transfer to savings space Holiday');
        $pairer->suggestOneSided();
        app(TodoActions::class)->run('transfers_ok', $item['actions'][0]['id']);
        foreach ([$out, $in, $topUp] as $tx) {
            $this->assertSame('confirmed', $tx->fresh()->review_status);
        }
        $this->assertSame(['suggested', 'suggested'], [$later->fresh()->review_status, $alone->fresh()->review_status]);

        TodoList::forget();
        $list = TodoList::build(50);
        $this->assertSame('Confirm 1 transfer between your accounts', collect($list['items'])->firstWhere('key', 'transfers')['title']);
        $this->assertNotNull(collect($list['items'])->firstWhere('key', 'tx:'.$alone->id));
    }

    // ---------------------------------------------------------------- N65: an exchange's fee is a bank charge

    public function test_an_exchange_fee_is_split_off_and_left_for_you_to_check_without_breaking_the_pair(): void
    {
        Http::fake();
        app(RevolutImport::class)->commit($this->revolut->fresh(), $this->exchangeFile(), 'rv.csv');
        $out = BankTransaction::query()->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('description', 'Exchanged from GBP')->sole();
        $this->assertSame([['transfer', -10000, null], ['business_expense', -100, 'finance']], $this->lines($out));
        $this->assertSame([['transfer', 10000, null]], $this->lines($in));

        // Confirm all takes the side bought only; the side sold is listed with every line it suggests.
        $list = TodoList::build(50);
        $this->assertStringContainsString('£100.00 in', collect($list['items'])->firstWhere('key', 'transfers')['detail']);
        $this->assertStringContainsString('Transfer between my accounts + Business expense: Bank, credit card', collect($list['items'])->firstWhere('key', 'tx:'.$out->id)['detail']);
        app(TodoActions::class)->run('transfers_ok', (string) TransferPairer::confirmable()['up_to']);
        $this->assertSame(['suggested', 'confirmed'], [$out->fresh()->review_status, $in->fresh()->review_status]);
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id]);
    }

    public function test_splitting_a_fee_off_a_paired_transfer_by_hand_keeps_the_pair(): void
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        app(TransferPairer::class)->pairAll();
        $this->assertSame($in->id, $out->fresh()->transfer_pair_id);

        app(AllocationWriter::class)->decide($out->fresh(), [
            ['kind' => 'transfer', 'amount_pence' => -49900], ['kind' => 'business_expense', 'category_id' => $this->cat('finance'), 'amount_pence' => -100],
        ], 'you', true);

        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id], 'A fee split off keeps the pair');
        $this->assertSame('confirmed', $in->fresh()->review_status, 'Its other side, wholly a transfer, is confirmed with it');
        $this->assertFalse(AuditEvent::query()->where('action', 'transfer.unpaired')->exists());
    }

    // ---------------------------------------------------------------- N108 / P3: totals line by line

    public function test_the_transactions_page_and_the_assistant_total_a_split_line_by_line(): void
    {
        $this->actingAs(User::factory()->create());
        $x = $this->tx($this->revolut, '2025-06-02', -100500, 'Exchanged to EUR');
        app(AllocationWriter::class)->decide($x, [
            ['kind' => 'transfer', 'amount_pence' => -100000], ['kind' => 'business_expense', 'category_id' => $this->cat('finance'), 'amount_pence' => -500],
        ], 'you', true);
        $this->tx($this->revolut, '2025-06-03', -2000, 'Exchanged lunch');

        $this->get('/transactions?q=Exchanged')->assertInertia(fn ($p) => $p->where('totals.count', 2)
            ->where('totals.out_pence', -2500)->where('totals.transfer_out_pence', -100000)->where('totals.transfer_count', 1));

        $found = app(AssistantTools::class)->run('search_transactions', ['query' => 'Exchanged']);
        $this->assertSame([-2500, -100000, 1], [$found['total_out']['pence'], $found['between_your_own_accounts']['out']['pence'], $found['between_your_own_accounts']['count']]);
    }

    // ---------------------------------------------------------------- categoriser: marker, learning, rules, fees

    public function test_a_guess_made_at_import_is_the_categorisers_and_accepting_one_teaches_like_deciding(): void
    {
        $first = $this->tx($this->starling, '2025-06-02', -3000, 'GRIP AND GAFFER');
        app(AllocationWriter::class)->decide($first, AllocationWriter::lines(-3000, 'business_expense', $this->cat('materials')), 'matcher', false, 'Matching');
        $waiting = $this->tx($this->starling, '2025-06-09', -2000, 'GRIP AND GAFFER');

        // Accepting from the to-do list (or Telegram) teaches like deciding: the other waiting payment follows.
        $message = app(TodoActions::class)->run('tx_accept', (string) $first->id);
        $this->assertStringContainsString('Updated the suggestion for 1 other waiting payment', $message);
        $a = $waiting->fresh()->allocations()->sole();
        $this->assertSame(['business_expense', Categoriser::MARKER], [$a->kind, $a->matcher]);

        // A guess the Suggester makes (an import) is marked as the Categoriser's.
        $third = $this->tx($this->starling, '2025-06-16', -1000, 'GRIP AND GAFFER');
        app(Suggester::class)->suggestFor(collect([$third]));
        $this->assertSame(Categoriser::MARKER, $third->fresh()->allocations()->value('matcher'));
    }

    public function test_the_rule_offer_counts_accepted_suggestions_and_a_split_typed_by_amounts(): void
    {
        foreach (['2025-06-02', '2025-07-02'] as $i => $date) {
            $tx = $this->tx($this->starling, $date, -10000, 'PHONECO');
            $lines = [['kind' => 'business_expense', 'category_id' => $this->cat('phone_internet'), 'amount_pence' => -6000, 'business_use_pct' => null],
                ['kind' => 'personal', 'amount_pence' => -4000]];
            if ($i === 0) {
                app(AllocationWriter::class)->decide($tx, $lines, 'you', true);
            } else {
                app(AllocationWriter::class)->decide($tx, $lines, 'matcher', false, 'Suggested');
                app(AllocationWriter::class)->confirm($tx);
            }
        }

        $offer = app(RuleLearner::class)->offer('PHONECO');
        $this->assertNotNull($offer, 'An accepted suggestion counts as your choice');
        $this->assertSame([2, 60], [$offer['count'], $offer['business_pct']], 'The share comes from the amounts');
    }

    public function test_revolut_fees_are_suggested_as_a_bank_charge(): void
    {
        Http::fake();
        $path = tempnam(sys_get_temp_dir(), 'rv').'.csv';
        file_put_contents($path, "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n"
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,500.00,0.00,GBP,COMPLETED,500.00\n"
            ."Fee,Current,2026-03-05 09:00:00,2026-03-05 09:00:00,Revolut Plan Fee,-7.99,0.00,GBP,COMPLETED,492.01\n");
        app(RevolutImport::class)->commit($this->revolut->fresh(), $path, 'rv.csv');

        $fee = BankTransaction::query()->where('description', 'Revolut Plan Fee')->sole();
        $this->assertSame('FEE', $fee->bank_category);
        $this->assertSame([['business_expense', -799, 'finance']], $this->lines($fee));
        $this->assertStringContainsString('The bank calls it', (string) $fee->allocations()->value('reason'));
    }

    // ---------------------------------------------------------------- jobs: own money, overheads, evidence

    public function test_own_money_is_never_a_jobs_fee_and_an_overhead_stays_off_the_job(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done', 'fee_pence' => 50000]);
        $own = $this->tx($this->starling, '2025-05-20', 50000, 'From personal', 'ALEX SMITH');
        $adobe = $this->tx($this->starling, '2025-05-14', -1999, 'ADOBE CREATIVE CLOUD', 'ADOBE');
        app(Suggester::class)->suggestFor(collect([$adobe]));
        $this->assertSame('software', $adobe->fresh()->allocations()->first()->category->code);

        app(JobGatherer::class)->gather($job);

        $this->assertFalse(JobFind::query()->where('type', 'income')->where('ref', (string) $own->id)->exists(), 'Your own money paid in is never the fee');
        $this->assertNull($adobe->fresh()->allocations()->value('work_job_id'), 'An overhead isn’t given to the job');
        $find = JobFind::query()->where('type', 'payment')->where('ref', (string) $adobe->id)->sole();
        $this->assertSame('possible', $find->status);
        $this->assertStringContainsString('An overhead', $find->detail);

        $this->actingAs(User::factory()->create());
        $near = collect($this->get('/jobs/'.$job->id)->assertOk()->viewData('page')['props']['near'])->pluck('id');
        $this->assertNotContains($own->id, $near, 'Nor is it offered on the job page');
    }

    public function test_a_jobs_evidence_is_its_emails_own_words_and_names_it_outright(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'location' => 'London', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);

        // Sent on the job's day, mentioning a vague venue and a word of the title: not evidence.
        $sent = "From: Bob <bob@example.com>\nTo: me@example.com\nDate: Wed, 14 May 2025 10:00:00 +0100\nSubject: Catch up\n\nThe awards in London were great.";
        $this->assertFalse(Verifier::jobEvidence($sent, $job)['ok']);
        $this->assertFalse(Verifier::jobEvidence('Wed 14 May 2025: the awards in London', $job)['ok']);
        // The date in its subject, and the client named: evidence.
        $sheet = "From: Acme <crew@acme.example>\nTo: me@example.com\nDate: Mon, 5 May 2025 09:00:00 +0100\nSubject: Call sheet Wed 14 May 2025\n\nAcme Events: crew call 07:00.";
        $this->assertTrue(Verifier::jobEvidence($sheet, $job)['ok']);
        // The whole title (two words or more) names it too.
        $this->assertTrue(Verifier::jobEvidence('Acme Awards rig, Wed 14 May 2025', $job)['ok']);
    }

    public function test_an_html_only_email_keeps_its_quoted_headers_on_lines_of_their_own(): void
    {
        $raw = "From: Me <me@example.com>\r\nTo: Acme <crew@acme.example>\r\nDate: Wed, 14 May 2025 18:00:00 +0100\r\nSubject: Re: Rig\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
            .'<html><body><p>Thanks&nbsp;&amp; see you then</p><div>From: Acme<br>Sent: Friday, 2 May 2025 09:00<br>To: Me<br>Subject: Rig</div><p>Old text</p></body></html>';
        $text = DocumentIntake::emailText($raw);
        $this->assertStringContainsString("Thanks & see you then\nFrom: Acme\nSent: Friday, 2 May 2025 09:00\n", $text);
        $this->assertStringNotContainsString('2 May 2025', JobGatherer::withoutHeaders($text), 'The quoted header is recognised and left out');
    }

    public function test_the_job_form_refuses_a_job_longer_than_any_and_the_calendar_page_knows_each_jobs_days(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/jobs', ['title' => 'Typo', 'starts_on' => '2025-01-01', 'ends_on' => '2026-12-31', 'status' => 'booked'])
            ->assertSessionHasErrors(['ends_on' => 'A job can cover at most 400 days. Make it separate jobs.']);

        $job = WorkJob::query()->create(['title' => 'Rig and de-rig', 'starts_on' => now()->format('Y-m-d'), 'ends_on' => now()->addDays(4)->format('Y-m-d'), 'status' => 'booked']);
        JobDay::query()->where('work_job_id', $job->id)->delete();
        foreach ([0, 4] as $d) {
            JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => now()->addDays($d)->format('Y-m-d'), 'kind' => 'work', 'source' => 'you']);
        }
        $page = $this->get('/jobs/calendar')->assertOk()->viewData('page')['props'];
        $this->assertSame([now()->format('Y-m-d'), now()->addDays(4)->format('Y-m-d')], collect($page['jobs'])->firstWhere('id', $job->id)['days']);
        $this->assertSame([], Present::jobsNear(now()->addDays(2)->format('Y-m-d')), 'A day between them isn’t near the job');
        $this->assertSame([$job->id], array_column(Present::jobsNear(now()->addDays(4)->format('Y-m-d')), 'id'));
    }

    public function test_an_issued_invoice_found_for_a_job_offers_no_undo(): void
    {
        $this->actingAs(User::factory()->create());
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done', 'po_number' => 'PO-7781']);
        $invoice = Invoice::query()->create(['number' => 'INV-0007', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 50000, 'issue_date' => '2025-05-20', 'work_job_id' => $job->id, 'po_number' => 'PO-7781']);
        app(JobGatherer::class)->gather($job);

        $find = collect($this->get('/jobs/'.$job->id)->assertOk()->viewData('page')['props']['finds'])->firstWhere('ref', (string) $invoice->id);
        $this->assertSame(['linked', 'issued'], [$find['status'], $find['locked']]);
    }

    public function test_job_search_emails_arent_counted_as_bookings_waiting(): void
    {
        $this->actingAs(User::factory()->create());
        GmailMessage::query()->create(['message_id' => 'm1', 'search' => 'job', 'subject' => 'Rig times', 'sent_at' => now()->subDays(3), 'status' => 'stored']);
        GmailMessage::query()->create(['message_id' => 'm2', 'search' => 'hotels', 'subject' => 'Your stay', 'sent_at' => now()->subDays(3), 'status' => 'stored']);
        $this->get('/travel')->assertInertia(fn ($p) => $p->where('gmail.waiting', 1));
    }

    // ---------------------------------------------------------------- currencies: own units, the shop's currency

    public function test_the_currency_the_shop_charged_is_kept_shown_and_cant_be_edited(): void
    {
        $row = new NormalizedRow(postedOn: CarbonImmutable::parse('2026-03-10'), transactionOn: null, description: 'B&H PHOTO', counterparty: null, reference: null,
            amountPence: -6664, balanceAfterPence: null, originalAmountMinor: -7664, originalCurrency: 'EUR', bankCategory: null, raw: [], line: 2,
            merchantAmountMinor: -9000, merchantCurrency: 'USD');
        $path = tempnam(sys_get_temp_dir(), 'x').'.csv';
        file_put_contents($path, 'x');
        $batch = app(Importer::class)->store($this->revolut, [$row], $path, 'x.csv', [], ['status' => 'ok', 'notes' => null], '2026-03-10', '2026-03-10');
        $tx = BankTransaction::query()->where('import_batch_id', $batch->id)->sole();
        $this->assertSame([-9000, 'USD'], [$tx->merchant_amount_minor, $tx->merchant_currency]);

        $shown = Present::tx($tx->load('account'));
        $this->assertSame([-9000, 'USD'], [$shown['merchant_amount_minor'], $shown['merchant_currency']]);
        $this->assertStringContainsString('Paid $90.00 (€76.64 from Revolut)', implode(' ', Explainer::payment($tx)['notes']));
        $this->assertStringContainsString('paid $90.00 (€76.64)', TodoList::howPaid($tx));

        $this->expectException(LogicException::class);
        $tx->update(['merchant_currency' => 'CAD']);
    }

    public function test_amounts_in_other_currencies_are_read_in_their_own_units(): void
    {
        // The rate worked out from the line itself, and a stored rate read back without its decimal point.
        $ramen = $this->tx($this->revolut, '2026-03-10', -789, 'ICHIRAN RAMEN', null, ['original_amount_minor' => -1500, 'original_currency' => 'JPY']);
        $this->assertSame('190.1141', Present::tx($ramen)['fx']['units_per_gbp']);
        $this->assertSame('190', Money::rate('190'));
        $this->assertStringContainsString('Paid JPY 1,500;', implode(' ', Explainer::payment($ramen)['notes']));

        // A receipt read in hundredths of a yen finds its line.
        Document::query()->create(['source' => 'upload', 'original_name' => 'r.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'sha256' => str_repeat('c', 64), 'stored_path' => 'x',
            'kind' => 'receipt', 'status' => 'extracted', 'received_at' => '2026-03-11 10:00:00',
            'extraction' => ['receipt' => ['merchant' => 'Ichiran', 'date' => '2026-03-10', 'amount_minor' => 150000, 'currency' => 'JPY']]]);
        $this->actingAs(User::factory()->create());
        $this->assertSame(['Receipt: Ichiran'], collect($this->getJson("/items/bank/{$ramen->id}")->assertOk()->json('links'))->where('type', 'receipt')->pluck('label')->all());

        // A booking in hundredths of a yen finds its card line.
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Ichiran Inn', 'reference' => 'Y-1', 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-11',
            'place' => 'Ichiran Inn Tokyo', 'amount_minor' => 150000, 'currency' => 'JPY', 'payment' => 'paid', 'source' => 'structured_data']);
        $this->assertSame($ramen->id, app(TravelMatcher::class)->findPayment($stay)?->id);

        // A yen invoice paid in yen counts as paid in full.
        $invoice = Invoice::query()->create(['number' => 'INV-0099', 'status' => 'issued', 'currency' => 'JPY', 'total_minor' => 150000, 'issue_date' => '2026-03-01']);
        $fee = $this->tx($this->revolut, '2026-03-12', 789, 'TOKYO CLIENT', null, ['original_amount_minor' => 1500, 'original_currency' => 'JPY']);
        $lines = AllocationWriter::lines(789, 'business_income', $this->cat('turnover'));
        $lines[0]['invoice_id'] = $invoice->id;
        app(AllocationWriter::class)->decide($fee, $lines, 'you', true);
        $this->assertSame(150000, InvoicePayments::received([$invoice->fresh()])[$invoice->id]['paid']);

        // A balance typed in for a yen pocket is kept in whole yen.
        $yen = Account::query()->create(['name' => 'Revolut JPY', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'JPY', 'parent_account_id' => $this->revolut->id]);
        $this->post("/accounts/{$yen->id}/balances", ['as_of' => '2026-03-31', 'balance' => '17,500'])->assertSessionHasNoErrors();
        $this->assertSame(17500, (int) StatementBalance::query()->where('account_id', $yen->id)->value('balance_pence'));
    }

    public function test_the_year_spreadsheet_keeps_where_the_sterling_came_from_and_what_the_shop_charged(): void
    {
        $eur = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $this->revolut->id]);
        $this->tx($eur, '2026-03-10', -10000, 'EXCHANGE LEG', null, ['original_amount_minor' => -11520, 'original_currency' => 'EUR', 'fx_source' => 'exchange']);
        $this->tx($eur, '2026-03-11', -6664, 'B&H PHOTO', null, ['original_amount_minor' => -7664, 'original_currency' => 'EUR', 'merchant_amount_minor' => -9000, 'merchant_currency' => 'USD']);

        $path = tempnam(sys_get_temp_dir(), 'yx').'.xlsx';
        YearExporter::write('2025-26', $path);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() === 'Transactions') {
                foreach ($sheet->getRowIterator() as $r) {
                    $rows[] = $r->toArray();
                }
            }
        }
        $reader->close();
        @unlink($path);
        $head = array_flip($rows[0]);
        $by = fn (string $d) => collect($rows)->first(fn ($r) => ($r[$head['Description']] ?? null) === $d);
        $this->assertSame('The exchange it came from', $by('EXCHANGE LEG')[$head['Rate used']]);
        $this->assertSame([-90, 'USD'], [$by('B&H PHOTO')[$head['Paid amount']], $by('B&H PHOTO')[$head['Paid currency']]]);
    }

    // ---------------------------------------------------------------- held, discarded and restored statements

    public function test_held_exchange_sides_are_suggested_on_accept_freed_on_discard_and_re_paired_on_restore(): void
    {
        Http::fake();
        // Restore: the sides differ by the fee, so they're known as one exchange's two sides.
        $batches = app(RevolutImport::class)->commit($this->revolut->fresh(), $this->exchangeFile(), 'rv.csv');
        $out = BankTransaction::query()->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('description', 'Exchanged from GBP')->sole();
        $gbpBatch = collect($batches)->firstWhere('account_id', $this->revolut->id);
        app(Importer::class)->revert($gbpBatch, 'Wrong file');
        $this->assertNull($in->fresh()->transfer_pair_id);
        app(Importer::class)->restore($gbpBatch->fresh(), 'Right file after all');
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id]);

        // A held statement: accepted, its exchange side is suggested as the exchange (with the other side paired).
        $broken = str_replace('13923.36', '13900.00', (string) file_get_contents(base_path('tests/fixtures/revolut_business.csv')));
        $file = tempnam(sys_get_temp_dir(), 'rb').'.csv';
        file_put_contents($file, $broken);
        $business = Account::query()->create(['name' => 'Revolut Business', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $held = collect(app(RevolutImport::class)->commit($business->fresh(), $file, 'rb.csv'))->firstWhere('status', 'quarantined');
        $leg = BankTransaction::withoutGlobalScope('active')->where('import_batch_id', $held->id)->whereNotNull('transfer_pair_id')->sole();
        $partner = BankTransaction::query()->find($leg->transfer_pair_id);
        $this->assertSame('transfer', $partner->allocations()->value('kind'));

        app(Importer::class)->discard($held, 'Wrong balance');
        $this->assertNull($partner->fresh()->transfer_pair_id, 'Freed for the corrected statement');
        $this->assertTrue(AuditEvent::query()->where('action', 'transfer.unpaired')->where('subject_id', $partner->id)->exists());
        $this->assertFalse($partner->fresh()->allocations()->where('reason', 'like', '%between your own pockets%')->exists(), 'The pair’s suggestion is taken back');

        $held2 = collect(app(RevolutImport::class)->commit($business->fresh(), $file, 'rb2.csv'))->firstWhere('status', 'quarantined');
        app(Importer::class)->accept($held2, 'Checked by hand');
        $leg2 = BankTransaction::query()->where('import_batch_id', $held2->id)->whereNotNull('transfer_pair_id')->sole();
        $this->assertSame('transfer', $leg2->allocations()->value('kind'));
        $this->assertStringContainsString('between your own pockets', (string) $leg2->allocations()->value('reason'));
    }

    // ---------------------------------------------------------------- Starling's feed, local times, checks

    public function test_starling_lines_get_their_time_country_and_currency_from_its_feed(): void
    {
        Setting::put('starling_token', 'good-token-1234567890abcdef');
        Setting::put('starling_accounts', ['uid-1' => ['account_id' => $this->starling->id, 'name' => 'Business', 'currency' => 'GBP', 'synced_to' => null]]);
        $csv = "Date,Counter Party,Reference,Type,Amount (GBP),Balance (GBP),Spending Category,Notes\n"
            .now()->subDays(3)->format('d/m/Y').",Bistro Lyon,BISTRO LYON,CARD,-20.00,980.00,EATING_OUT,\n"
            .now()->subDays(2)->format('d/m/Y').",Screwfix,SCREWFIX,CARD,-15.00,965.00,SHOPPING,\n";
        $at = now('Europe/London')->subDays(3)->setTime(21, 40)->utc()->format('Y-m-d\TH:i:s.000\Z');
        Http::fake(function (HttpRequest $r) use ($csv, $at) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/api/v2/accounts') => Http::response(['accounts' => [['accountUid' => 'uid-1', 'defaultCategory' => 'cat-1', 'currency' => 'GBP']]]),
                str_contains($path, '/statement/downloadForDateRange') => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
                str_contains($path, '/feed/account/uid-1/category/cat-1/transactions-between') => Http::response(['feedItems' => [
                    ['feedItemUid' => 'f1', 'amount' => ['currency' => 'GBP', 'minorUnits' => 2000], 'sourceAmount' => ['currency' => 'EUR', 'minorUnits' => 2300],
                        'direction' => 'OUT', 'transactionTime' => $at, 'status' => 'SETTLED', 'counterPartyName' => 'Bistro Lyon', 'country' => 'FR'],
                ]]),
                default => Http::response('', 404),
            };
        });
        app(StarlingSync::class)->sync();

        $bistro = BankTransaction::query()->where('counterparty', 'Bistro Lyon')->sole();
        $this->assertSame(['21:40', 'FR', -2300, 'EUR'], [$bistro->transacted_time, $bistro->place, $bistro->original_amount_minor, $bistro->original_currency]);
        $screwfix = BankTransaction::query()->where('counterparty', 'Screwfix')->sole();
        $this->assertSame([null, null], [$screwfix->transacted_time, $screwfix->original_currency], 'No feed item: as the statement has it');
    }

    public function test_a_local_time_column_gives_the_time_and_an_empty_main_account_isnt_missing_statements(): void
    {
        $this->assertSame('00:30', LineDetails::from(['Date started (UTC)' => '2026-04-05', 'Date started (America/New_York)' => '2026-04-05 19:30:00'], null, CarbonImmutable::parse('2026-04-06'))['time']);

        $main = Account::query()->create(['name' => 'Revolut Business', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $operating = Account::query()->create(['name' => 'Revolut Operating', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP', 'parent_account_id' => $main->id]);
        $this->tx($operating, '2025-06-02', -1000, 'Coffee');
        $names = collect(YearChecks::coverage('2025-26')['accounts'])->pluck('name');
        $this->assertContains('Revolut Operating', $names);
        $this->assertNotContains('Revolut Business', $names, 'Its statements are its pockets’');
    }

    public function test_the_history_page_only_records_again_when_something_changed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/todo/history')->assertOk();
        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            // The one-query fingerprint (a UNION over every table) is how it knows nothing changed.
            $queries += str_contains($q->sql, 'work_jobs') && ! str_contains($q->sql, 'UNION ALL') ? 1 : 0;
        });
        $this->get('/todo/history')->assertOk();
        $this->assertSame(0, $queries, 'Nothing changed: the findings aren’t worked out again');
    }
}
