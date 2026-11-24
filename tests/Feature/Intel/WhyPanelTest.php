<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Provenance;
use App\Domain\Jobs\JobFinder;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Http\Controllers\WhyController;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The why-panel (GET /why?ref=…, docs/intelligence-cycle.md 7.4) explains a rule's line, a matcher's line and a job
 * finder's calendar link, each with its method, evidence and history; it only reads.
 */
class WhyPanelTest extends TestCase
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
        $this->actingAs(User::factory()->create());
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

    private function why(string $ref): array
    {
        return $this->getJson('/why?ref='.urlencode($ref))->assertOk()->json('items');
    }

    /** A calendar entry the job finder put on Acme Awards, beating Spring Gala on the same day. */
    private function calendarLink(): array
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $acme = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'location' => 'Leeds Arena']);
        $gala = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $event = CalendarEvent::query()->create(['uid' => 'acme@cal', 'starts_at' => '2025-05-14 08:00:00', 'ends_at' => '2025-05-14 18:00:00', 'all_day' => false,
            'summary' => 'Acme Events: Acme Awards rig', 'location' => 'Leeds Arena']);
        app(JobFinder::class)->run('2025-26', false);

        return [$event, $acme, $gala];
    }

    public function test_a_rule_line_says_which_rule_and_its_history(): void
    {
        $rule = MerchantRule::query()->create(['merchant_key' => 'TRAINLINE', 'kind' => 'business_expense', 'category_id' => Category::query()->where('code', 'travel_rail')->value('id'), 'business_pct' => 100]);
        $tx = $this->tx($this->starling, '2025-06-08', -4500, 'TRAINLINE', 'TRAINLINE');
        app(Suggester::class)->suggestFor(collect([$tx]));

        $item = $this->why('tx:'.$tx->id)[0];
        $this->assertSame('Suggested by your rule for TRAINLINE.', $item['headline']);
        $this->assertSame(['suggested', 'rule', 'your rule'], [$item['state'], $item['actor'], $item['actor_label']]);
        $this->assertSame(['rule.owner', 'rule', true], [$item['method']['name'], $item['method']['basis'], $item['method']['recorded']]);
        $this->assertSame([['ref' => 'rule:'.$rule->id, 'label' => 'Your rule for TRAINLINE', 'href' => '/settings/rules', 'exists' => true, 'changed' => false]], $item['evidence']);
        $this->assertSame(['Suggested', 'your rule'], [$item['history'][0]['label'], $item['history'][0]['actor_label']]);
        $this->assertSame('unmeasured', $item['grade']['grade']);
    }

    public function test_a_matcher_line_shows_its_score_and_the_runner_up(): void
    {
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo']);
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        $far = $this->tx($monzo, '2025-06-05', 50000, 'Payment from Alex Smith');
        app(TransferPairer::class)->pairAll();

        $item = $this->why('tx:'.$out->id)[0];
        $this->assertStringStartsWith('Suggested by matching: both sides of money moving between your accounts', $item['headline']);
        $this->assertSame('transfer.pair', $item['method']['name']);
        $this->assertGreaterThan(0, $item['score']['value']);
        $this->assertSame(0, $item['score']['margin'], 'The runner-up was as sure, only further apart');
        $this->assertSame(['tx:'.$in->id], array_column($item['evidence'], 'ref'), 'Its own line isn’t cited as evidence for itself');
        $this->assertSame('tx:'.$far->id, $item['alternatives'][0]['ref']);
        $this->assertStringContainsString('Monzo', $item['alternatives'][0]['label']);
        $this->assertSame('Paired as a transfer', collect($item['history'])->firstWhere('action', 'transfer.paired')['label']);

        // Once you decide its other side, the panel says the matcher confirmed it because of you, and when.
        $this->post("/items/bank/{$in->id}/decide", ['kind' => 'transfer', 'business_pct' => 100])->assertSessionHasNoErrors();
        $item = $this->why('tx:'.$out->id)[0];
        $this->assertStringContainsString('confirmed by matching because you decided the other side', $item['headline']);
        $this->assertSame(['Confirmed', 'matching', 'on the web'], [$item['history'][0]['label'], $item['history'][0]['actor_label'], $item['history'][0]['channel_label']]);
    }

    public function test_a_job_finder_calendar_link_shows_its_method_score_and_evidence(): void
    {
        [$event, $acme, $gala] = $this->calendarLink();

        $item = $this->why('cal:'.$event->key())[0];
        $this->assertSame(['linked', 'matcher'], [$item['state'], $item['actor']]);
        $this->assertStringContainsString('was put on Acme Awards', $item['headline']);
        $this->assertStringContainsString('by matching: the job finder', $item['headline']);
        $this->assertSame(['jobs.calendar_rules', true], [$item['method']['name'], $item['method']['recorded']]);
        $this->assertGreaterThanOrEqual(3, $item['score']['margin']);
        $this->assertSame(['job:'.$acme->id], array_column($item['evidence'], 'ref'));
        $this->assertSame(['job:'.$gala->id], array_column($item['alternatives'], 'ref'));
        $this->assertStringContainsString('Looks like work', $item['facts'][0]);
        $this->assertSame(['Link changed', 'matching'], [$item['history'][0]['label'], $item['history'][0]['actor_label']]);
        $this->assertStringContainsString('mentions Acme Events', (string) $item['reason']);
    }

    public function test_a_line_from_before_methods_were_stored_is_explained_from_its_wording(): void
    {
        $tx = $this->tx($this->starling, '2025-07-31', -50000, 'HMRC SELF ASSESSMENT', 'HMRC');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');

        $item = $this->why('tx:'.$tx->id)[0];
        $this->assertSame(['suggest.hmrc', false], [$item['method']['name'], $item['method']['recorded']]);
        $this->assertStringContainsString('before Expend kept the method', $item['method']['note']);
        $this->assertNull($item['score']);
    }

    public function test_your_own_decision_is_certain_and_an_undecided_line_says_so(): void
    {
        $tx = $this->tx($this->starling, '2025-06-10', -350, 'PRET A MANGER', 'PRET A MANGER');
        $this->assertSame(['none', 'Not decided yet.'], [$this->why('tx:'.$tx->id)[0]['state'], $this->why('tx:'.$tx->id)[0]['headline']]);

        $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();
        $item = $this->why('tx:'.$tx->id)[0];
        $this->assertSame(['decided', 'Decided by you.', 'certain'], [$item['state'], $item['headline'], $item['grade']['grade']]);
        $this->assertSame('on the web', $item['history'][0]['channel_label']);
    }

    public function test_the_drawer_names_what_its_panels_explain(): void
    {
        [$event, $acme] = $this->calendarLink();
        $tx = $this->tx($this->starling, '2025-05-14', -2350, 'SCREWFIX', 'SCREWFIX');

        $why = $this->getJson("/items/bank/{$tx->id}")->assertOk()->json('why');
        $this->assertSame('tx:'.$tx->id, $why['decision']);
        $this->assertSame([['ref' => 'cal:'.$event->key(), 'summary' => 'Acme Events: Acme Awards rig', 'job' => 'Acme Awards', 'linked_by' => 'rules']], $why['calendar']);
    }

    public function test_only_you_can_ask_and_only_for_something_there(): void
    {
        $tx = $this->tx($this->starling, '2025-06-10', -350, 'PRET A MANGER', 'PRET A MANGER');
        $this->getJson('/why?ref=tx:999')->assertNotFound();
        $this->getJson('/why?ref=not-a-ref')->assertNotFound();
        $this->getJson('/why?ref=set:telegram_bot_token')->assertNotFound();
        $this->getJson('/why')->assertUnprocessable();

        auth()->logout();
        $this->get('/why?ref=tx:'.$tx->id)->assertRedirect('/login');
    }

    public function test_the_read_model_never_writes(): void
    {
        [$event, $acme] = $this->calendarLink();
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith');
        app(TransferPairer::class)->pairAll();
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'po.pdf', 'sha256' => str_repeat('b', 64), 'stored_path' => 'documents/b.pdf', 'status' => 'extracted']);
        app(JobFinder::class)->linkDocument($doc, $acme, 'rules', true, new Provenance('jobs.document', ['doc:'.$doc->id, 'job:'.$acme->id], 10));
        $paid = $this->tx($this->starling, '2025-05-15', -8900, 'PREMIER INN', 'PREMIER INN');
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'PI-1', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'structured_data']);
        app(TravelMatcher::class)->suggest($stay);
        $invoice = Invoice::query()->create(['status' => 'draft', 'total_minor' => 0, 'work_job_id' => $acme->id]);

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        foreach (['tx:'.$out->id, 'tx:'.$paid->id, 'cal:'.$event->key(), 'doc:'.$doc->id, 'travel:'.$stay->id, 'inv:'.$invoice->id] as $ref) {
            $this->assertNotNull(WhyController::explain($ref), $ref);
        }
        $this->assertSame([], $writes);

        // What they say: the document linked with its score, the stay's job and its payment.
        $doc = WhyController::explain('doc:'.$doc->id)['items'][0];
        $this->assertSame(['jobs.document', 10], [$doc['method']['name'], $doc['score']['value']]);
        $travel = WhyController::explain('travel:'.$stay->id)['items'];
        $this->assertSame(['travel_job', 'travel_payment'], array_column($travel, 'about'));
        $this->assertSame(['travel.match', 'tx:'.$paid->id], [$travel[1]['method']['name'], $travel[1]['evidence'][0]['ref']]);
        $this->assertSame('decided', WhyController::explain('inv:'.$invoice->id)['items'][0]['state'], 'Put on the job when it was made: yours');
    }

    public function test_a_rule_suggestion_you_accepted_isnt_said_to_be_confirmed_by_the_rule(): void
    {
        $travel = Category::query()->where('code', 'travel_rail')->value('id');
        MerchantRule::query()->create(['merchant_key' => 'TRAINLINE', 'kind' => 'business_expense', 'category_id' => $travel, 'business_pct' => 100, 'auto_confirm' => false]);
        MerchantRule::query()->create(['merchant_key' => 'ADOBE', 'kind' => 'business_expense', 'category_id' => $travel, 'business_pct' => 100, 'auto_confirm' => true]);
        $accepted = $this->tx($this->starling, '2025-06-08', -4500, 'TRAINLINE', 'TRAINLINE');
        $own = $this->tx($this->starling, '2025-06-09', -1999, 'ADOBE', 'ADOBE');
        app(Suggester::class)->suggestFor(collect([$accepted, $own]));
        $this->travel(5)->seconds();
        app(AllocationWriter::class)->confirm($accepted); // your tap in the Inbox
        $this->assertSame('Decided by your rule for TRAINLINE, and accepted by you.', $this->why('tx:'.$accepted->id)[0]['headline']);
        $this->assertSame('Decided by your rule for ADOBE, confirmed by the rule on its own.', $this->why('tx:'.$own->id)[0]['headline']);

        // As both read when written before who confirmed was kept.
        DB::table('allocations')->update(['confirmed_by' => null]);
        $item = $this->why('tx:'.$accepted->id)[0];
        $this->assertSame('Decided by your rule for TRAINLINE, and confirmed.', $item['headline'], 'Confirmed later: not by the rule on its own');
        $this->assertSame('Decided by your rule for ADOBE, confirmed by the rule on its own.', $this->why('tx:'.$own->id)[0]['headline'], 'Confirmed as it was made');
    }

    public function test_a_document_the_ai_reader_put_on_a_job_says_the_ai_did(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-7781']);
        $doc = Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'stored_path' => 'x', 'sha256' => str_repeat('b', 64), 'status' => 'extracted',
            'work_job_id' => $job->id, 'job_linked_by' => 'ai']);

        $item = $this->why('doc:'.$doc->id)[0];
        $this->assertSame(['ai', 'AI', 'doc.read', false], [$item['actor'], $item['actor_label'], $item['method']['name'], $item['method']['recorded']]);
        $this->assertStringContainsString('was put on Acme Awards · 14 May 2025 by AI: a document read with AI', $item['headline']);
    }

    public function test_a_link_you_took_off_isnt_put_down_to_matching(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'reference' => 'PI-1', 'starts_on' => '2025-05-20', 'ends_on' => '2025-05-21',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'unknown', 'source' => 'structured_data', 'work_job_id' => $job->id, 'job_linked_by' => 'rules']);
        $finder = app(JobFinder::class);
        // The search takes it off (its days no longer fit the job): matching's doing.
        $finder->linkTravel($stay, null, 'rules', new Provenance('jobs.gather', ['travel:'.$stay->id, 'job:'.$job->id]));
        $item = $this->why('travel:'.$stay->id)[0];
        $this->assertSame(['taken_back', 'matcher', 'jobs.gather'], [$item['state'], $item['actor'], $item['method']['name']]);

        // You put it on the job, then take it off yourself.
        $finder->linkTravel($stay->fresh(), $job, 'you');
        $finder->linkTravel($stay->fresh(), null, 'you');
        $item = $this->why('travel:'.$stay->id)[0];
        $this->assertSame(['decided', 'you', null], [$item['state'], $item['actor'], $item['method']]);
        $this->assertSame('You took Hotel: Premier Inn Leeds off Acme Awards · 14 May 2025.', $item['headline']);
        $this->assertSame('certain', $item['grade']['grade']);

        // The same for a calendar entry: taken off by the search, then on and off again by you.
        $event = CalendarEvent::query()->create(['uid' => 'gig@cal', 'starts_at' => '2025-05-14 08:00:00', 'ends_at' => '2025-05-14 18:00:00', 'all_day' => false, 'summary' => 'Acme Awards rig']);
        $finder->linkCalendar($event, $job, 'rules');
        $finder->unlinkAutomatic($event, $job, 'Not one of the job’s days now');
        $this->assertSame('matcher', $this->why('cal:'.$event->key())[0]['actor']);
        $finder->linkCalendar($event, $job, 'you');
        $finder->unlinkCalendar($event, $job->id);
        $item = $this->why('cal:'.$event->key())[0];
        $this->assertSame(['decided', 'you'], [$item['state'], $item['actor']]);
        $this->assertStringStartsWith('You took “Acme Awards rig” off Acme Awards', $item['headline']);

        // An entry you said isn't for the job, that was never on it, wasn't taken off anything.
        $never = CalendarEvent::query()->create(['uid' => 'other@cal', 'starts_at' => '2025-05-14 19:00:00', 'ends_at' => '2025-05-14 22:00:00', 'all_day' => false, 'summary' => 'Dinner']);
        $finder->rejectCalendarFor($never, $job->id);
        $item = $this->why('cal:'.$never->key())[0];
        $this->assertSame(['none', '“Dinner” isn’t on a job.'], [$item['state'], $item['headline']]);
    }

    /**
     * A line suggested during a big upload (one action, hundreds of events) and later decided by you: the knock-ons of
     * your decision still show, and the panel's queries don't grow with how many knock-ons there are.
     */
    public function test_the_knock_ons_of_your_decision_show_after_a_big_upload_in_a_few_queries(): void
    {
        $w = app(AllocationWriter::class);
        $ours = $this->tx($this->starling, '2025-05-14', -1000, 'ACME SHOP', 'ACME SHOP');
        $others = [];
        AuditContext::with('matcher', function () use ($w, $ours, &$others) {
            $w->decide($ours, AllocationWriter::lines(-1000, 'personal'), 'matcher', false, 'Looks like personal');
            for ($i = 0; $i < 120; $i++) {
                $t = $this->tx($this->starling, '2025-05-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT), -100 - $i, 'SHOP '.$i, 'SHOP '.$i);
                $w->decide($t, AllocationWriter::lines(-100 - $i, 'personal'), 'matcher', false, 'Looks like personal');
                $others[] = $t;
            }
        }, 'web', AuditContext::newCorrelation());

        // Later you decide it, and the same action confirms six other lines (as the other side of a transfer would be).
        $decision = AuditContext::newCorrelation();
        AuditContext::with(null, function () use ($w, $ours, $others) {
            $w->decide($ours->fresh(), AllocationWriter::lines(-1000, 'personal'), 'you', true, 'Mine');
            foreach (array_slice($others, 0, 6) as $t) {
                AuditContext::with('matcher', fn () => $w->confirm($t->fresh()));
            }
        }, 'web', $decision);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $history = WhyController::explain('tx:'.$ours->id)['items'][0]['history'];
        $decided = collect($history)->firstWhere('correlation', $decision);
        $this->assertSame('allocation.decided', $decided['action']);
        $this->assertCount(6, $decided['knock_on'], 'Every line confirmed because of your decision');
        $this->assertSame('Starling Business · 1 May 2025 · SHOP 0 · −£1.00', $decided['knock_on'][0]['subject']);
        $this->assertCount(10, collect($history)->firstWhere('action', 'allocation.suggested')['knock_on'], 'The upload’s first 10');
        $this->assertLessThanOrEqual(12, $queries, 'Labels are looked up by kind, not one by one');
    }
}
