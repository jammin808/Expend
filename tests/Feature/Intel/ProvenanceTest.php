<?php

namespace Tests\Feature\Intel;

use App\Domain\Ai\AssistantTools;
use App\Domain\Ai\TransactionAdvisor;
use App\Domain\Audit\AuditLog;
use App\Domain\Import\RevolutImport;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Backfills\Backfill;
use App\Domain\Intel\Backfills\ConfirmedBy;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Provenance;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Http\Controllers\WhyController;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobFind;
use App\Models\LinkProvenance;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Every automatic writer says why (M6): the registered method, its score, the evidence and the runner-ups, stored with
 * the lines it writes, or with a link in link_provenance; confirming records who confirmed; learning counts what you
 * accepted at half the weight of what you decided.
 */
class ProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private Account $revolut;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(AuditLog::CONTEXT_SETTING, true); // recording where each event came from is turned on
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        Setting::put('job_finder_auto', false);
        OwnAccounts::flush();
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
    }

    private function tx(Account $account, string $date, int $pence, string $description, ?string $counterparty = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => MerchantKey::from($description, $counterparty), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1,
        ]);
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function line(BankTransaction $tx): Allocation
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->firstOrFail();
    }

    // ---- income design v3: the invoice matcher and the payer's client ----------------------------------------------

    public function test_the_invoice_matchers_set_and_net_and_a_filled_in_client_say_why(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $writer = app(AllocationWriter::class);
        // ACME PAYROLL pays for Acme: two receipts you decided, each with a £30 fee taken off.
        $receipts = [];
        foreach (['2025-04-02' => 100000, '2025-04-09' => 50000] as $on => $gross) {
            $receipts[] = $r = $this->tx($this->starling, $on, $gross - 3000, 'ACME PAYROLL', 'ACME PAYROLL');
            $writer->decide($r, [['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'client_id' => $acme->id, 'amount_pence' => $gross],
                ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'client_id' => $acme->id, 'amount_pence' => -3000]]);
        }
        $invoice = fn (string $number, int $total) => Invoice::query()->create(['number' => $number, 'status' => 'issued', 'client_id' => $acme->id, 'currency' => 'GBP',
            'total_minor' => $total, 'issue_date' => '2025-05-20', 'bill_to_name' => 'Acme Events']);
        $a = $invoice('INV-41', 60000);
        $b = $invoice('INV-43', 75000);
        $c = $invoice('INV-50', 100000);
        MerchantRule::query()->create(['merchant_key' => 'ACME BONUS', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => true]);
        $bonusPayer = $this->tx($this->starling, '2025-04-03', 5000, 'ACME BONUS', 'ACME BONUS');
        $writer->decide($bonusPayer, [['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'client_id' => $acme->id, 'amount_pence' => 5000]]);

        $set = $this->tx($this->starling, '2025-06-02', 135000, 'ACME PAYROLL', 'ACME PAYROLL');
        $net = $this->tx($this->starling, '2025-06-03', 97000, 'ACME PAYROLL', 'ACME PAYROLL');
        $bonus = $this->tx($this->starling, '2025-06-04', 2500, 'ACME BONUS', 'ACME BONUS');
        app(Suggester::class)->suggestFor(collect([$set, $net, $bonus]));

        $s = $this->line($set);
        $this->assertSame(['suggest.invoice_set', Methods::version('suggest.invoice_set'), ['inv:'.$a->id, 'inv:'.$b->id], 3],
            [$s->method, $s->method_version, $s->evidence, $s->score], 'The set, and how many of the client’s invoices were searched');
        $n = $this->line($net);
        $this->assertSame(['suggest.invoice_net', ['inv:'.$c->id, 'tx:'.$receipts[1]->id, 'tx:'.$receipts[0]->id]], [$n->method, $n->evidence],
            'The invoice, and the receipts the usual fee was learned from (newest first)');
        // The rule's line names the payment its client was worked out from.
        $r = $this->line($bonus);
        $this->assertSame(['rule.owner', $acme->id, null], [$r->method, $r->client_id, $r->confirmed_at]);
        $this->assertContains('tx:'.$bonusPayer->id, $r->evidence);
    }

    // ---- the vocabulary ---------------------------------------------------------------------------------------------

    public function test_a_provenance_names_only_registered_methods_and_keeps_valid_refs(): void
    {
        $p = new Provenance('transfer.pair', ['tx:1', 'tx:1', 'not a ref', null, 'job:4'], 7, [['ref' => 'tx:9', 'score' => 5], ['ref' => 'nope'], ['ref' => 'tx:9', 'score' => 1]]);
        $this->assertSame(['tx:1', 'job:4'], $p->evidence);
        $this->assertSame([['ref' => 'tx:9', 'score' => 5]], $p->alternatives);
        $this->assertSame(['heuristic', 1], [$p->basis, $p->version]);

        foreach (Methods::names() as $method) {
            $this->assertSame($method, (new Provenance($method))->method);
            $this->assertContains(Methods::actor($method), AuditContext::ACTORS);
            $this->assertArrayHasKey(Methods::basis($method), Methods::BASES);
        }
        $this->expectException(InvalidArgumentException::class);
        new Provenance('guess.anything');
    }

    public function test_the_legacy_recogniser_names_lines_written_before_methods_were_stored(): void
    {
        $cases = [
            ['matcher', 'Matches invoice INV-1 for £1,200.00', null, 'suggest.invoice'],
            ['matcher', 'Matches Starling and Revolut: £500.00 moving between your accounts', null, 'transfer.pair'],
            ['matcher', 'Paid to you (your own name)', null, 'transfer.one_sided'],
            ['matcher', 'Currency exchange: £100.00 to €115.20, between your own pockets', null, 'revolut.exchange'],
            ['matcher', 'Looks like a payment to HMRC (personal tax, not a business expense)', null, 'suggest.hmrc'],
            ['matcher', 'Looks like a fine or penalty charge, which is never allowable', null, 'suggest.penalty'],
            ['matcher', 'Looks like car hire, usually a business expense. Check it was for work, and link the job', null, 'suggest.car_hire'],
            ['matcher', 'Check: Train ticket on Wed 14 May 2025, a job day', null, 'suggest.travel'],
            ['matcher', 'Like the 3 times you decided ACME', 'categoriser', 'categorise.merchant'],
            ['matcher', 'Like the other TESCO payments you decided (3 of 4)', null, 'categorise.similar'],
            ['matcher', 'The bank calls it “Eating out”', null, 'categorise.bank_hint'],
            ['matcher', 'Looks like a hotel, with no job that night: personal', 'categoriser', 'categorise.hint'],
            ['matcher', 'A business cost on Acme Awards’s dates', null, 'jobs.gather'],
            ['matcher', 'Premier Inn Leeds (Hotel, 1 night from 11 May 2025): Night of Sun 11 May: Business', null, 'travel.match'],
            ['ai', 'AI: a cable', null, 'ai.advisor'],
            ['ai', 'A cable for the job', null, 'ai.assistant'],
            ['you', 'Whatever', null, null],
        ];
        foreach ($cases as [$by, $reason, $marker, $method]) {
            $this->assertSame($method, Methods::legacy($by, $reason, $marker), $reason);
        }
    }

    // ---- every automatic writer says why ----------------------------------------------------------------------------

    public function test_every_automatic_writer_stores_a_registered_method(): void
    {
        $writer = app(AllocationWriter::class);
        // Transfers: a pair, and one side on its own.
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $alone = $this->tx($this->starling, '2025-06-20', -7000, 'To Alex Smith', 'ALEX SMITH');
        app(TransferPairer::class)->pairAll();
        app(TransferPairer::class)->suggestOneSided();

        // The Suggester's branches, a rule you saved, and the Categoriser.
        $invoice = Invoice::query()->create(['number' => 'INV-1', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 120000, 'issue_date' => '2025-05-20', 'bill_to_name' => 'Acme Events']);
        MerchantRule::query()->create(['merchant_key' => 'ADOBE', 'kind' => 'business_expense', 'category_id' => $this->cat('software'), 'business_pct' => 100]);
        $lines = [
            'income' => $this->tx($this->starling, '2025-06-05', 120000, 'ACME EVENTS LTD', 'ACME EVENTS LTD'),
            'hmrc' => $this->tx($this->starling, '2025-07-31', -50000, 'HMRC SELF ASSESSMENT', 'HMRC'),
            'fine' => $this->tx($this->starling, '2025-06-06', -6500, 'PCN WESTMINSTER', 'WESTMINSTER'),
            'hire' => $this->tx($this->starling, '2025-06-07', -9000, 'ENTERPRISE RENT-A-CAR', 'ENTERPRISE'),
            'train' => $this->tx($this->starling, '2025-06-08', -4500, 'TRAINLINE', 'TRAINLINE'),
            'rule' => $this->tx($this->starling, '2025-06-09', -1999, 'ADOBE', 'ADOBE'),
            'kit' => $this->tx($this->starling, '2025-06-10', -2500, 'SCREWFIX', 'SCREWFIX'),
        ];
        app(Suggester::class)->suggestFor(collect(array_values($lines)));

        // A stay lined up with the nights, the AI's suggestions, a currency exchange.
        $paid = $this->tx($this->starling, '2025-05-12', -8900, 'PREMIER INN', 'PREMIER INN');
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'PI-1', 'starts_on' => '2025-05-11', 'ends_on' => '2025-05-12',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'structured_data', 'bank_transaction_id' => $paid->id]);
        app(TravelMatcher::class)->suggest($stay);
        $advised = $this->tx($this->starling, '2025-06-11', -1200, 'CABLE CO', 'CABLE CO');
        app(TransactionAdvisor::class)->apply([['id' => $advised->id, 'kind' => 'personal', 'category_code' => null, 'job_id' => null, 'business_pct' => 100, 'reason' => 'A gift']],
            collect([$advised]), collect([['id' => $advised->id, 'jobs_around_date' => []]]));
        $asked = $this->tx($this->starling, '2025-06-12', -1300, 'GIFT SHOP', 'GIFT SHOP');
        app(AssistantTools::class)->run('propose_decision', ['transaction_id' => $asked->id, 'kind' => 'personal', 'reason' => 'You said it was a present']);
        $sold = $this->tx($this->revolut, '2025-06-13', -10000, 'Exchanged to EUR');
        $bought = $this->tx($this->starling, '2025-06-13', 9900, 'Exchanged from GBP');
        app(RevolutImport::class)->suggestExchange($sold->fresh(), $bought->fresh(), 100);

        $methods = [
            'transfer.pair' => [$out, $in], 'transfer.one_sided' => [$alone], 'suggest.invoice' => [$lines['income']], 'suggest.hmrc' => [$lines['hmrc']],
            'suggest.penalty' => [$lines['fine']], 'suggest.car_hire' => [$lines['hire']], 'suggest.travel' => [$lines['train']], 'rule.owner' => [$lines['rule']],
            'categorise.hint' => [$lines['kit']], 'travel.match' => [$paid], 'ai.advisor' => [$advised], 'ai.assistant' => [$asked], 'revolut.exchange' => [$sold, $bought],
        ];
        foreach ($methods as $method => $txs) {
            foreach ($txs as $tx) {
                $a = $this->line($tx);
                $this->assertSame([$method, Methods::version($method), Methods::basis($method)], [$a->method, $a->method_version, $a->basis], $tx->description);
            }
        }
        // Every line not made by you carries a registered method.
        $this->assertSame([], Allocation::query()->where('decided_by', '!=', 'you')->get()->reject(fn (Allocation $a) => $a->method && Methods::has($a->method))->pluck('reason')->all());

        // The evidence each relied on.
        $this->assertSame(['inv:'.$invoice->id], $this->line($lines['income'])->evidence);
        $this->assertSame(1, $this->line($lines['income'])->score, 'One invoice with that much still to come (suggest.invoice v2)');
        $this->assertSame(['tx:'.$in->id, 'tx:'.$out->id], $this->line($in)->evidence, 'Both sides: money in, then money out');
        $this->assertGreaterThan(0, $this->line($in)->score);
        $this->assertSame(['travel:'.$stay->id, 'tx:'.$paid->id], $this->line($paid)->evidence);
        $this->assertSame(['rule:'.MerchantRule::query()->value('id')], $this->line($lines['rule'])->evidence);
    }

    public function test_a_pair_keeps_its_strength_and_the_runner_up_it_beat(): void
    {
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo']);
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $near = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $far = $this->tx($monzo, '2025-06-05', 50000, 'Payment from Alex Smith');

        $this->assertSame(1, app(TransferPairer::class)->pairAll());

        $a = $this->line($out);
        $this->assertSame($near->id, $out->fresh()->transfer_pair_id, 'The nearer of two as sure');
        $this->assertSame('transfer.pair', $a->method);
        $this->assertSame([['ref' => 'tx:'.$far->id, 'score' => $a->score]], $a->alternatives, 'The runner-up: as sure, but further apart');
        $this->assertSame($a->alternatives, $this->line($near)->alternatives);
    }

    public function test_a_learned_category_keeps_how_many_agree_examples_and_the_others(): void
    {
        $writer = app(AllocationWriter::class);
        $hire = [];
        foreach (['2025-04-01', '2025-04-08', '2025-04-15'] as $date) {
            $hire[] = $tx = $this->tx($this->starling, $date, -9000, 'JB SOUND HIRE', 'JB SOUND HIRE');
            $writer->decide($tx, AllocationWriter::lines(-9000, 'business_expense', $this->cat('equipment')));
        }
        $other = $this->tx($this->starling, '2025-04-22', -1500, 'JB SOUND HIRE', 'JB SOUND HIRE');
        $writer->decide($other, AllocationWriter::lines(-1500, 'personal'));

        $waiting = $this->tx($this->starling, '2025-05-01', -9000, 'JB SOUND HIRE', 'JB SOUND HIRE');
        app(Categoriser::class)->suggestFor(collect([$waiting]));

        $a = $this->line($waiting);
        $this->assertSame(['categorise.merchant', 3, Categoriser::MARKER], [$a->method, $a->score, $a->matcher]);
        $this->assertEqualsCanonicalizing(array_map(fn ($t) => 'tx:'.$t->id, $hire), $a->evidence);
        $this->assertSame([['ref' => 'tx:'.$other->id, 'score' => 1]], $a->alternatives, '3 of 4: the other way you decided it, once');
        $this->assertStringContainsString('3 of the last 4', (string) $a->reason);
    }

    public function test_a_learned_rule_cites_its_learning(): void
    {
        $writer = app(AllocationWriter::class);
        foreach (['2025-04-01', '2025-05-01'] as $date) {
            $writer->decide($this->tx($this->starling, $date, -1999, 'ADOBE', 'ADOBE'), AllocationWriter::lines(-1999, 'business_expense', $this->cat('software')));
        }
        $waiting = $this->tx($this->starling, '2025-06-01', -1999, 'ADOBE', 'ADOBE');

        ['rule' => $rule] = app(RuleLearner::class)->accept('ADOBE');

        $a = $this->line($waiting);
        $learned = AuditEvent::query()->where('action', 'rule.learned')->sole();
        $this->assertSame(['rule.learned', 'rule', 'rule'], [$a->method, $a->decided_by, $a->confirmed_by]);
        $this->assertContains('rule:'.$rule->id, $a->evidence);
        $this->assertContains('audit:'.$learned->id.'@'.substr($learned->hash, 0, 8), $a->evidence);
        $this->assertSame('rule.learned', Methods::forRule($rule));
    }

    public function test_gathering_for_a_job_says_so(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'status' => 'done']);
        $kit = $this->tx($this->starling, '2025-05-14', -2350, 'SCREWFIX', 'SCREWFIX');
        app(AllocationWriter::class)->decide($kit, AllocationWriter::lines(-2350, 'business_expense', $this->cat('materials')), 'matcher', false,
            'Looks like kit, cable and consumables: usually a business cost', null, new Provenance('categorise.hint'));

        app(JobGatherer::class)->gather($job, false);

        $a = $this->line($kit);
        $this->assertSame(['jobs.gather', $job->id], [$a->method, $a->work_job_id]);
        $this->assertSame(['job:'.$job->id], $a->evidence);
    }

    /**
     * "Not this job" on a payment the search found (it promises the payment won't be suggested for the job again):
     * learning from your next decision on the same merchant must not put the job back.
     */
    public function test_a_payment_you_said_isnt_for_a_job_stays_off_it_when_learning_runs_again(): void
    {
        $this->actingAs(User::factory()->create());
        $acme = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);
        $gala = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-06-20', 'ends_on' => '2025-06-20', 'status' => 'done']);
        $kit = $this->tx($this->starling, '2025-05-14', -2350, 'SCREWFIX', 'SCREWFIX');
        app(JobGatherer::class)->gather($acme, false);
        $this->assertSame([$acme->id, 'categorise.hint'], [$this->line($kit)->work_job_id, $this->line($kit)->method], 'Suggested with the job of that day');

        $find = JobFind::query()->where('work_job_id', $acme->id)->where('type', 'payment')->where('ref', (string) $kit->id)->firstOrFail();
        $this->post('/job-finds/'.$find->id.'/reject')->assertRedirect();
        $a = $this->line($kit);
        $this->assertSame([null, 'Not for Acme Awards', 'matcher', null], [$a->work_job_id, $a->reason, $a->decided_by, $a->confirmed_at]);
        // Recorded as the search's own rewrite, citing the job: no longer the Categoriser's to learn over.
        $this->assertSame('jobs.gather', $a->method);
        $this->assertContains('job:'.$acme->id, $a->evidence);

        // You decide another Screwfix payment, for another job; then accept a third's suggestion in the Inbox.
        $other = $this->tx($this->starling, '2025-06-20', -1800, 'SCREWFIX', 'SCREWFIX');
        app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'work_job_id' => $gala->id, 'business_pct' => 100], collect([$other]));
        $third = $this->tx($this->starling, '2025-06-21', -900, 'SCREWFIX', 'SCREWFIX');
        app(Suggester::class)->suggestFor(collect([$third]));
        $this->post('/items/bank/'.$third->id.'/confirm')->assertRedirect();

        $a = $this->line($kit);
        $this->assertSame([null, 'Not for Acme Awards'], [$a->work_job_id, $a->reason], 'The job you said it isn’t for stays off');
    }

    // ---- links that aren't allocations ------------------------------------------------------------------------------

    public function test_a_job_finder_link_keeps_its_score_margin_and_runner_up(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $acme = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'location' => 'Leeds Arena']);
        $other = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $event = CalendarEvent::query()->create(['uid' => 'acme@cal', 'starts_at' => '2025-05-14 08:00:00', 'ends_at' => '2025-05-14 18:00:00', 'all_day' => false,
            'summary' => 'Acme Events: Acme Awards rig', 'location' => 'Leeds Arena']);

        app(JobFinder::class)->run('2025-26', false);

        $link = CalendarJobLink::query()->where('event_key', $event->key())->sole();
        $this->assertSame([$acme->id, 'rules'], [$link->work_job_id, $link->linked_by]);
        $why = LinkProvenance::query()->sole();
        $this->assertSame(['calendar_job', 'cal:'.$event->key(), 'job:'.$acme->id, 'jobs.calendar_rules'], [$why->link, $why->subject_ref, $why->object_ref, $why->method]);
        $this->assertGreaterThanOrEqual(5, $why->score);
        $this->assertSame('job:'.$other->id, $why->alternatives[0]['ref']);
        $this->assertGreaterThanOrEqual(3, $why->score - $why->alternatives[0]['score'], 'At least 3 clear of the runner-up');

        // Once you move the entry, the stored why no longer describes the link, so it's ignored.
        app(JobFinder::class)->linkCalendar($event, $other, 'you');
        $this->assertNull(LinkProvenance::current('calendar_job', 'cal:'.$event->key(), 'job:'.$other->id));
        $item = WhyController::explain('cal:'.$event->key())['items'][0];
        $this->assertSame(['decided', 'you', null], [$item['state'], $item['actor'], $item['method']]);
    }

    public function test_an_invoice_put_on_a_job_by_its_po_number_says_so(): void
    {
        $job = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-7781']);
        $invoice = Invoice::query()->create(['status' => 'draft', 'total_minor' => 0, 'po_number' => 'po 7781']);

        app(JobFinder::class)->followUp($job);

        $this->assertSame($job->id, $invoice->fresh()->work_job_id);
        $this->assertSame('matcher', AuditEvent::query()->where('action', 'invoice.job_linked')->sole()->actor);
        $why = LinkProvenance::current('invoice_job', 'inv:'.$invoice->id, 'job:'.$job->id);
        $this->assertSame(['jobs.invoice_po', 'exact'], [$why?->method, $why?->basis]);
    }

    // ---- confirming and correlations --------------------------------------------------------------------------------

    public function test_confirming_records_who_confirmed(): void
    {
        $this->actingAs(User::factory()->create());
        $writer = app(AllocationWriter::class);
        $tap = $this->tx($this->starling, '2025-06-11', -650, 'GREGGS', 'GREGGS');
        $writer->decide($tap, AllocationWriter::lines(-650, 'personal'), 'matcher', false, 'Looks like food or drink', null, new Provenance('categorise.hint'));
        $this->assertNull($this->line($tap)->confirmed_by);
        $this->post("/items/bank/{$tap->id}/confirm")->assertSessionHasNoErrors();

        $mine = $this->tx($this->starling, '2025-06-12', -350, 'PRET', 'PRET');
        $this->post("/items/bank/{$mine->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();

        $rule = MerchantRule::query()->create(['merchant_key' => 'ADOBE', 'kind' => 'business_expense', 'category_id' => $this->cat('software'), 'business_pct' => 100, 'auto_confirm' => true]);
        $ruled = $this->tx($this->starling, '2025-06-13', -1999, 'ADOBE', 'ADOBE');
        app(Suggester::class)->suggestFor(collect([$ruled]));

        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        app(TransferPairer::class)->pairAll();
        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'transfer', 'business_pct' => 100])->assertSessionHasNoErrors();

        $this->assertSame(['you', 'you', 'rule', 'you', 'matcher'], [
            $this->line($tap)->confirmed_by, $this->line($mine)->confirmed_by, $this->line($ruled)->confirmed_by, $this->line($out)->confirmed_by, $this->line($in)->confirmed_by,
        ]);
        $this->assertSame(['categorise.hint', 'transfer.pair'], [$this->line($tap)->method, $this->line($in)->method], 'Confirming keeps the method');
        $this->assertSame('rule.owner', $this->line($ruled)->method);
        $this->assertSame($rule->id, $this->line($ruled)->merchant_rule_id);
    }

    public function test_one_correlation_links_a_decision_to_its_partner_and_what_it_taught(): void
    {
        $this->actingAs(User::factory()->create());
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        app(TransferPairer::class)->pairAll();
        // Another payment to the same payee, with only the Categoriser's guess on it.
        $guessed = $this->tx($this->starling, '2025-06-20', -2000, 'Savings', 'A SMITH');
        app(AllocationWriter::class)->decide($guessed, AllocationWriter::lines(-2000, 'personal'), 'matcher', false, 'Looks like a gym: usually personal', null, new Provenance('categorise.hint'));

        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'transfer', 'business_pct' => 100])->assertSessionHasNoErrors();

        $decided = AuditEvent::query()->where('action', 'allocation.decided')->where('subject_id', $out->id)->sole();
        $partner = AuditEvent::query()->where('action', 'allocation.confirmed')->where('subject_id', $in->id)->sole();
        $taught = AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $guessed->id)->sole();
        $this->assertSame(['you', 'matcher', 'matcher'], [$decided->actor, $partner->actor, $taught->actor]);
        $this->assertNotNull($decided->correlation);
        $this->assertSame([$decided->correlation, $decided->correlation], [$partner->correlation, $taught->correlation]);
        $this->assertSame(['web', 'web', 'web'], [$decided->channel, $partner->channel, $taught->channel]);
        $this->assertTrue(AuditLog::verify()['ok']);

        // The history of your decision shows what it set off.
        $history = WhyController::explain('tx:'.$out->id)['items'][0]['history'];
        $knock = collect($history)->firstWhere('action', 'allocation.decided')['knock_on'];
        $this->assertEqualsCanonicalizing(['allocation.confirmed', 'allocation.cleared'], collect($knock)->pluck('action')->unique()->values()->all());
    }

    public function test_a_line_written_again_unchanged_still_records_nothing_and_snapshots_say_why(): void
    {
        $writer = app(AllocationWriter::class);
        $tx = $this->tx($this->starling, '2025-07-31', -50000, 'HMRC SELF ASSESSMENT', 'HMRC');
        $why = new Provenance('suggest.hmrc');
        $writer->decide($tx, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC', null, $why);
        $writer->decide($tx, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC', null, $why);

        $events = AuditEvent::query()->where('subject_id', $tx->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame(['tax_payment', 'suggest.hmrc', 'Looks like a payment to HMRC', null, null], [
            $events[0]->after[0]['kind'], $events[0]->after[0]['method'], $events[0]->after[0]['reason'], $events[0]->after[0]['invoice_id'], $events[0]->after[0]['merchant_rule_id'],
        ]);
    }

    // ---- learning from what you accepted ----------------------------------------------------------------------------

    public function test_the_categoriser_reads_the_method_first(): void
    {
        $writer = app(AllocationWriter::class);
        // Worded like the Categoriser's own, but the car hire matcher's: left alone.
        $notOurs = $this->tx($this->starling, '2025-06-01', -9000, 'JB SOUND HIRE', 'JB SOUND HIRE');
        $writer->decide($notOurs, AllocationWriter::lines(-9000, 'business_expense', $this->cat('materials')), 'matcher', false,
            'Looks like kit, cable and consumables: usually a business cost', null, new Provenance('suggest.car_hire'));
        // Worded like nothing it writes, but its own by method: taken back and suggested again.
        $ours = $this->tx($this->starling, '2025-06-02', -4000, 'JB SOUND HIRE', 'JB SOUND HIRE');
        $writer->decide($ours, AllocationWriter::lines(-4000, 'business_expense', $this->cat('materials')), 'matcher', false, 'Something', null, new Provenance('categorise.hint'));

        $decided = $this->tx($this->starling, '2025-05-20', -1500, 'JB SOUND HIRE', 'JB SOUND HIRE');
        $writer->decide($decided, AllocationWriter::lines(-1500, 'personal'));
        app(Categoriser::class)->learnFrom(collect([$decided->fresh()]));

        $this->assertSame(['suggest.car_hire', 'business_expense'], [$this->line($notOurs)->method, $this->line($notOurs)->kind]);
        $this->assertSame(['categorise.merchant', 'personal'], [$this->line($ours)->method, $this->line($ours)->kind]);
    }

    public function test_a_suggestion_you_accepted_counts_half_and_one_confirmed_on_its_own_not_at_all(): void
    {
        $writer = app(AllocationWriter::class);
        $accept = function (BankTransaction $tx, array $lines, ?string $by) use ($writer) {
            $writer->decide($tx, $lines, 'matcher', false, 'Suggested', null, new Provenance('categorise.hint'));
            AuditContext::with($by, fn () => $writer->confirm($tx));
        };
        // Yours: personal. Accepted: equipment. Yours counts 1, the accepted half: two thirds say personal.
        $writer->decide($this->tx($this->starling, '2025-04-01', -1500, 'JB SOUND HIRE', 'JB SOUND HIRE'), AllocationWriter::lines(-1500, 'personal'));
        $accept($this->tx($this->starling, '2025-04-08', -9000, 'JB SOUND HIRE', 'JB SOUND HIRE'), AllocationWriter::lines(-9000, 'business_expense', $this->cat('equipment')), null);
        $p = app(Categoriser::class)->propose($this->tx($this->starling, '2025-05-01', -2000, 'JB SOUND HIRE', 'JB SOUND HIRE'));
        $this->assertSame(['personal', 1, 2], [$p['lines'][0]['kind'], $p['n'], $p['of']]);

        // Confirmed by the matcher (the other side of a transfer you decided): not your choice, never learned.
        $accept($this->tx($this->starling, '2025-04-15', -800, 'PARTNER CO', 'PARTNER CO'), AllocationWriter::lines(-800, 'business_expense', $this->cat('equipment')), 'matcher');
        $accept($this->tx($this->starling, '2025-04-16', -800, 'PARTNER CO', 'PARTNER CO'), AllocationWriter::lines(-800, 'business_expense', $this->cat('equipment')), 'matcher');
        $this->assertNull(app(Categoriser::class)->propose($this->tx($this->starling, '2025-05-02', -800, 'PARTNER CO', 'PARTNER CO')));
        $this->assertNull(app(RuleLearner::class)->offer('PARTNER CO'));

        // Two accepted are worth one decision: enough to learn, not enough for a rule.
        $accept($this->tx($this->starling, '2025-04-15', -800, 'KIT CO', 'KIT CO'), AllocationWriter::lines(-800, 'business_expense', $this->cat('equipment')), null);
        $accept($this->tx($this->starling, '2025-04-16', -800, 'KIT CO', 'KIT CO'), AllocationWriter::lines(-800, 'business_expense', $this->cat('equipment')), null);
        $this->assertSame('business_expense', app(Categoriser::class)->propose($this->tx($this->starling, '2025-05-02', -800, 'KIT CO', 'KIT CO'))['lines'][0]['kind']);
        $this->assertNull(app(RuleLearner::class)->offer('KIT CO'));
    }

    // ---- the confirmed_by backfill ----------------------------------------------------------------------------------

    public function test_the_confirmed_by_backfill_fills_only_what_it_can_tell_and_is_idempotent(): void
    {
        $writer = app(AllocationWriter::class);
        $mine = $this->tx($this->starling, '2025-06-01', -100, 'A');
        $writer->decide($mine, AllocationWriter::lines(-100, 'personal'));
        $ruled = $this->tx($this->starling, '2025-06-02', -200, 'B');
        $writer->decide($ruled, AllocationWriter::lines(-200, 'personal'), 'rule', true, 'Your rule for B');
        $suggestedRule = $this->tx($this->starling, '2025-06-03', -300, 'C');
        $writer->decide($suggestedRule, AllocationWriter::lines(-300, 'personal'), 'rule', false, 'Your rule for C');
        $accepted = $this->tx($this->starling, '2025-06-04', -400, 'D');
        $writer->decide($accepted, AllocationWriter::lines(-400, 'personal'), 'matcher', false, 'Suggested');
        $writer->confirm($accepted);
        $waiting = $this->tx($this->starling, '2025-06-05', -500, 'E');
        $writer->decide($waiting, AllocationWriter::lines(-500, 'personal'), 'matcher', false, 'Suggested');
        // A rule that only suggests (it doesn't confirm on its own): its suggestions you accepted in the Inbox, one a
        // few seconds later, one in the same second.
        MerchantRule::query()->create(['merchant_key' => 'TRAINLINE', 'kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'business_pct' => 100, 'auto_confirm' => false]);
        $ruleAccepted = $this->tx($this->starling, '2025-06-06', -4500, 'TRAINLINE', 'TRAINLINE');
        $ruleAcceptedAtOnce = $this->tx($this->starling, '2025-06-07', -4600, 'TRAINLINE', 'TRAINLINE');
        app(Suggester::class)->suggestFor(collect([$ruleAccepted, $ruleAcceptedAtOnce]));
        $writer->confirm($ruleAcceptedAtOnce);
        $this->travel(5)->seconds();
        $writer->confirm($ruleAccepted);
        $this->assertSame(['you', 'you'], [$this->line($ruleAccepted)->confirmed_by, $this->line($ruleAcceptedAtOnce)->confirmed_by], 'Today: accepted by you');
        // As written before confirmed_by existed.
        DB::table('allocations')->update(['confirmed_by' => null]);
        $events = AuditEvent::query()->count();

        $backfill = new ConfirmedBy;
        $this->assertInstanceOf(Backfill::class, $backfill);
        $this->assertSame('confirmed_by', $backfill->name());
        $this->assertSame(['changed' => 1, 'done' => false], $backfill->run(1));
        $this->assertSame(['changed' => 1, 'done' => true], $backfill->run(1));
        $this->assertSame(['changed' => 0, 'done' => true], $backfill->run());

        $this->assertSame(['you', 'rule', null, null, null, null, null], array_map(fn ($t) => $this->line($t)->confirmed_by,
            [$mine, $ruled, $suggestedRule, $accepted, $waiting, $ruleAccepted, $ruleAcceptedAtOnce]), 'A rule’s suggestion you accepted isn’t put down to the rule');
        $this->assertSame($events, AuditEvent::query()->count(), 'Nothing about the decisions changed: nothing new in the history');

        // Found by the backfill command by its name, and run there the same way.
        DB::table('allocations')->update(['confirmed_by' => null]);
        $this->artisan('ledger:intel-backfill', ['name' => 'confirmed_by'])->assertSuccessful();
        $this->assertSame(['you', 'rule'], [$this->line($mine)->confirmed_by, $this->line($ruled)->confirmed_by]);
    }
}
