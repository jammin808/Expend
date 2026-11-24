<?php

namespace Tests\Feature\Intel;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AssistantTools;
use App\Domain\Ai\TransactionAdvisor;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Jobs\JobFinder;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Travel\TravelMatcher;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobDay;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Rejection memory after review (M7): saying no is bound to what you were shown, takes with it what the suggestion
 * linked (a transfer's other side, a stay), and is undone by the Inbox's Undo; it never holds back your own decisions,
 * another invoice of the same amount, the assistant acting on your words, or what the reasons and counts say.
 */
class VetoReviewTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Setting::put('own_names', ['Alex Smith']);
        Setting::put('ai_suggestions', true);
        OwnAccounts::flush();
        Carbon::setTestNow('2025-07-01 09:00:00');
        $this->bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(string $date, int $pence, string $description, ?string $counterparty = null, ?Account $account = null): BankTransaction
    {
        $account ??= $this->bank;
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

    /** @return list<string> what the payment says now: kind and decided_by of each line */
    private function says(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()->map(fn (Allocation $a) => $a->kind.':'.$a->decided_by)->all();
    }

    /** @return list<int> the ids of the lines a payment shows now */
    private function shown(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** "Alex Smith £100 to Savings", both sides imported and paired. @return array{0:BankTransaction, 1:BankTransaction} */
    private function pair(): array
    {
        $savings = Account::query()->create(['name' => 'Starling Savings', 'kind' => 'bank']);
        $out = $this->tx('2025-06-12', -10000, 'ALEX SMITH', 'Alex Smith');
        $in = $this->tx('2025-06-12', 10000, 'ALEX SMITH', 'Alex Smith', $savings);
        app(TransferPairer::class)->pairAll();
        $this->assertSame($in->id, $out->fresh()->transfer_pair_id);

        return [$out, $in];
    }

    public function test_the_inbox_undo_after_deciding_one_side_of_a_pair_takes_back_the_pair_you_said_no_to(): void
    {
        [$out, $in] = $this->pair();
        $pairer = app(TransferPairer::class);

        // Personal by mistake, then the Inbox's Undo: unpaired, then put back as it was.
        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'personal', 'quiet' => true]);
        $this->assertNull($out->fresh()->transfer_pair_id);
        $this->assertSame(1, Veto::query()->active()->where('relation', 'transfer_pair')->count());
        $this->post("/items/bank/{$out->id}/clear", ['reason' => 'Undo from Inbox', 'undo' => true, 'quiet' => true]);
        $this->assertSame([], Veto::query()->active()->get(['relation', 'subject_ref', 'object_ref'])->toArray(), 'Nothing said no to');
        $pairer->pairAll();
        $this->assertSame($in->id, $out->fresh()->transfer_pair_id, 'Paired again, as before the mistake');
        $this->assertSame(['transfer:matcher'], $this->says($in));

        // Undoing a decision on one side never takes back what a later decision on the other side said: you decide
        // this side's a transfer, then the other side isn't (which unpairs them), then undo this side.
        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'transfer', 'quiet' => true]);
        $this->post("/items/bank/{$in->id}/decide", ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'quiet' => true]);
        $pairVeto = Veto::query()->active()->where('relation', 'transfer_pair')->sole();
        $this->assertNull($out->fresh()->transfer_pair_id);
        $this->post("/items/bank/{$out->id}/clear", ['reason' => 'Undo from Inbox', 'undo' => true, 'quiet' => true]);
        $this->assertNull($pairVeto->fresh()->lifted_at, 'The other side’s “not a transfer” stands');
    }

    public function test_not_this_on_one_side_of_a_suggested_pair_unpairs_both_and_isnt_paired_again(): void
    {
        [$out, $in] = $this->pair();
        $this->post("/items/bank/{$out->id}/clear", ['reason_code' => 'not_mine', 'shown' => $this->shown($out), 'quiet' => true])->assertSessionHasNoErrors();

        $this->assertNull($out->fresh()->transfer_pair_id);
        $this->assertNull($in->fresh()->transfer_pair_id);
        $this->assertSame([], $this->says($in), 'The other side’s “matches this payment” is taken back');
        $pair = Veto::query()->where('relation', 'transfer_pair')->sole();
        $this->assertSame([...Vetoes::pairRefs($out->id, $in->id), 'not_mine'], [$pair->subject_ref, $pair->object_ref, $pair->reason_code]);
        $this->assertSame(['transfer.unpaired', 'you'], [AuditEvent::query()->where('subject_id', $out->id)->where('action', 'transfer.unpaired')->value('action'),
            AuditEvent::query()->where('subject_id', $out->id)->where('action', 'transfer.unpaired')->value('actor')]);

        // The night: the two are never paired again, and this side isn't suggested as a transfer again.
        app(TransferPairer::class)->pairAll();
        app(TransferPairer::class)->suggestOneSided();
        $this->assertNull($out->fresh()->transfer_pair_id);
        $this->assertNull($in->fresh()->transfer_pair_id);
        $this->assertSame([], $this->says($out));
    }

    public function test_a_transfer_you_decide_yourself_after_saying_no_is_still_paired_with_its_other_side(): void
    {
        $savings = Account::query()->create(['name' => 'Starling Savings', 'kind' => 'bank']);
        $out = $this->tx('2025-06-12', -10000, 'ALEX SMITH', 'Alex Smith');
        $pairer = app(TransferPairer::class);
        $pairer->suggestOneSided();
        $this->assertSame(['transfer:matcher'], $this->says($out));

        // "Not this" by mistake, then you decide it's a transfer yourself; then the savings statement arrives.
        $this->post("/items/bank/{$out->id}/clear", ['reason_code' => 'other', 'quiet' => true]);
        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'transfer', 'quiet' => true]);
        $in = $this->tx('2025-06-12', 10000, 'ALEX SMITH', 'Alex Smith', $savings);
        $pairer->pairAll();

        $this->assertSame($in->id, $out->fresh()->transfer_pair_id, 'Your transfer is linked to its other side');
        $this->assertSame(['transfer:you'], $this->says($out), 'Your decision stands');

        // Automation still doesn't propose it for a line that's open.
        $open = $this->tx('2025-06-20', -5000, 'ALEX SMITH', 'Alex Smith');
        $pairer->suggestOneSided();
        $this->post("/items/bank/{$open->id}/clear", ['reason_code' => 'other', 'shown' => $this->shown($open), 'quiet' => true]);
        $this->tx('2025-06-20', 5000, 'ALEX SMITH', 'Alex Smith', $savings);
        $pairer->pairAll();
        $this->assertNull($open->fresh()->transfer_pair_id);
    }

    public function test_saying_no_to_one_invoice_lets_another_of_the_same_amount_be_matched_or_the_payment_be_guessed(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $beta = Client::query()->create(['name' => 'Beta Productions']);
        $first = Invoice::query()->forceCreate(['client_id' => $acme->id, 'number' => 'INV-101', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-01']);
        $second = Invoice::query()->forceCreate(['client_id' => $beta->id, 'number' => 'INV-102', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-02']);
        $pay = $this->tx('2025-06-20', 45000, 'BETA PRODUCTIONS LTD', 'Beta Productions Ltd');
        app(Categoriser::class)->suggestFor(collect([$pay]));
        $this->assertSame($first->id, Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'));

        $this->post("/items/bank/{$pay->id}/clear", ['reason_code' => 'wrong_job', 'shown' => $this->shown($pay), 'quiet' => true]);
        app(Categoriser::class)->suggestFor(collect([$pay->fresh()]));
        $this->assertSame($second->id, Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'), 'The other £450 invoice is tried');
        $this->assertSame(1, (int) Allocation::query()->where('bank_transaction_id', $pay->id)->value('score'), 'One invoice left for that amount');

        // No to that one too: the payment isn't left with nothing; the other ways of suggesting have their turn.
        $this->post("/items/bank/{$pay->id}/clear", ['reason_code' => 'wrong_job', 'shown' => $this->shown($pay), 'quiet' => true]);
        MerchantRule::query()->create(['merchant_key' => $pay->merchant_key, 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => false]);
        app(Categoriser::class)->suggestFor(collect([$pay->fresh()]));
        $this->assertSame(['business_income:rule'], $this->says($pay));
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'));
    }

    public function test_a_rules_job_goes_only_on_that_jobs_own_days_and_never_on_a_day_of_another_job(): void
    {
        $tour = WorkJob::query()->create(['title' => 'Arena tour', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-30', 'status' => 'done']);
        $tour->days()->delete();
        JobDay::query()->create(['work_job_id' => $tour->id, 'on_date' => '2025-06-01']);
        JobDay::query()->create(['work_job_id' => $tour->id, 'on_date' => '2025-06-30']);
        WorkJob::query()->create(['title' => 'Conference AV', 'starts_on' => '2025-06-15', 'ends_on' => '2025-06-15', 'status' => 'done']);
        WorkJob::query()->create(['title' => 'Awards night', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-01', 'status' => 'booked']);
        $rig = $this->tx('2025-06-01', -3000, 'KIT HIRE LTD');
        $before = $this->tx('2025-05-31', -3000, 'KIT HIRE LTD');
        $between = $this->tx('2025-06-10', -3000, 'KIT HIRE LTD');
        $otherJob = $this->tx('2025-06-15', -3000, 'KIT HIRE LTD');
        $dayAfterOnAnotherJob = $this->tx('2025-07-01', -3000, 'KIT HIRE LTD');
        MerchantRule::query()->create(['merchant_key' => $rig->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->cat('equipment'), 'work_job_id' => $tour->id,
            'business_pct' => 100, 'auto_confirm' => false]);
        app(Suggester::class)->suggestFor(collect([$rig, $before, $between, $otherJob, $dayAfterOnAnotherJob]));

        $job = fn (BankTransaction $t) => Allocation::query()->where('bank_transaction_id', $t->id)->value('work_job_id');
        $this->assertSame([$tour->id, $tour->id], [$job($rig), $job($before)], 'Its day, and the day before (travel there)');
        $this->assertNull($job($between), 'Between the rig and the de-rig weeks later: not near it');
        $this->assertNull($job($otherJob), 'A day you worked another job');
        $this->assertNull($job($dayAfterOnAnotherJob), 'The day after it, when another job is on that day');
        $this->assertSame(['business_expense:rule'], $this->says($between), 'The rule still applies, with no job');
    }

    public function test_saving_an_invoice_match_with_a_note_keeps_the_invoice_and_says_no_to_nothing(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $invoice = Invoice::query()->forceCreate(['client_id' => $acme->id, 'number' => 'INV-101', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-01']);
        $pay = $this->tx('2025-06-20', 45000, 'ACME EVENTS LTD', 'Acme Events Ltd');
        app(Categoriser::class)->suggestFor(collect([$pay]));
        $this->assertSame($invoice->id, Allocation::query()->where('bank_transaction_id', $pay->id)->value('invoice_id'));

        $this->post("/items/bank/{$pay->id}/decide", ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'note' => 'Arena', 'shown' => $this->shown($pay), 'quiet' => true]);
        $this->assertSame(0, Veto::query()->count());
        $line = Allocation::query()->where('bank_transaction_id', $pay->id)->sole();
        $this->assertSame([$invoice->id, 'you', 'Arena'], [(int) $line->invoice_id, $line->decided_by, $line->note], 'Still the invoice’s payment, now your decision');

        // Deciding it's something else is saying it isn't that invoice's.
        $other = $this->tx('2025-06-21', 45000, 'ACME EVENTS LTD', 'Acme Events Ltd');
        $second = Invoice::query()->forceCreate(['client_id' => $acme->id, 'number' => 'INV-102', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-01']);
        app(Categoriser::class)->suggestFor(collect([$other]));
        $this->post("/items/bank/{$other->id}/decide", ['kind' => 'loan', 'shown' => $this->shown($other), 'quiet' => true]);
        $this->assertSame(['invoice_payment', 'inv:'.$second->id, 'changed'], array_values(Veto::query()->sole()->only(['relation', 'object_ref', 'reason_code'])));
    }

    public function test_not_this_and_deciding_are_bound_to_the_suggestion_you_were_shown(): void
    {
        $tx = $this->tx('2025-06-12', -4500, 'ENTERPRISE RENT-A-CAR');
        $writer = app(AllocationWriter::class);
        $writer->decide($tx, AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks personal', null, new Provenance('categorise.merchant'));
        $shown = collect($this->get('/inbox')->assertOk()->viewData('page')['props']['items'])->firstWhere('id', $tx->id);
        $this->assertSame('personal', $shown['allocations'][0]['kind']);
        $ids = array_column($shown['allocations'], 'id');

        // Meanwhile car hire replaces it (it outranks the categoriser's guess); the owner says "Not this" to "Personal".
        $carHire = AllocationWriter::lines(-4500, 'business_expense', $this->cat('travel_car_hire'));
        $this->assertTrue($writer->decide($tx->fresh(), $carHire, 'matcher', false, 'Looks like car hire', null, new Provenance('suggest.car_hire')));
        $this->post("/items/bank/{$tx->id}/clear", ['reason_code' => 'wrong_category', 'shown' => $ids, 'quiet' => true])
            ->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertSame(0, Veto::query()->count(), 'Nothing you never saw is said no to');
        $this->assertSame(['business_expense:matcher'], $this->says($tx), 'Nothing cleared');

        // Pressed on what it shows now, it does.
        $this->post("/items/bank/{$tx->id}/clear", ['reason_code' => 'wrong_category', 'shown' => $this->shown($tx), 'quiet' => true])->assertSessionMissing('error');
        $this->assertSame(Vetoes::shape($carHire), Veto::query()->sole()->shape);

        // A decision made on a card that's out of date is saved (it's yours) but says no to nothing it wasn't shown.
        $writer->decide($tx->fresh(), AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks personal', null, new Provenance('categorise.merchant'));
        $stale = $this->shown($tx);
        $writer->decide($tx->fresh(), AllocationWriter::lines(-4500, 'business_expense', $this->cat('travel_car_hire'), null, 50), 'matcher', false, 'Part car hire', null, new Provenance('suggest.car_hire'));
        $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence'), 'shown' => $stale, 'quiet' => true]);
        $this->assertSame(['business_expense:you'], $this->says($tx));
        $this->assertSame(1, Veto::query()->count(), 'The suggestion it replaced unseen isn’t said no to');
    }

    public function test_not_this_in_the_inbox_on_a_stays_payment_takes_it_off_the_stay(): void
    {
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'confirmed', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'email']);
        $paid = $this->tx('2025-06-09', -8900, 'PREMIER INN LEEDS');
        app(TravelMatcher::class)->suggest($hotel);
        $this->assertSame($paid->id, $hotel->fresh()->bank_transaction_id);

        $this->post("/items/bank/{$paid->id}/clear", ['reason_code' => 'not_mine', 'shown' => $this->shown($paid), 'quiet' => true]);
        $this->assertNull($hotel->fresh()->bank_transaction_id, 'The stay no longer claims the payment you said no to');
        $this->assertSame(['travel_payment', 'travel:'.$hotel->id, 'tx:'.$paid->id, 'not_mine'],
            array_values(Veto::query()->where('relation', 'travel_payment')->sole()->only(['relation', 'subject_ref', 'object_ref', 'reason_code'])));
        $this->assertSame('you', AuditEvent::query()->where('action', 'travel.payment_unlinked')->sole()->actor);
        app(TravelMatcher::class)->suggest($hotel->fresh());
        $this->assertNull($hotel->fresh()->bank_transaction_id, 'Not found for it again');
    }

    public function test_the_assistant_acting_on_your_words_replaces_a_guess_but_never_a_matchers_finding(): void
    {
        $pret = $this->tx('2025-06-12', -650, 'PRET A MANGER LONDON');
        app(Categoriser::class)->suggestFor(collect([$pret]));
        $this->assertSame('categorise.hint', Allocation::query()->where('bank_transaction_id', $pret->id)->value('method'));
        $tools = app(AssistantTools::class);
        $out = $tools->run('propose_decision', ['transaction_id' => $pret->id, 'kind' => 'business_expense', 'category_code' => 'travel_subsistence', 'reason' => 'You said it was lunch on the Leeds job']);
        $this->assertTrue($out['ok'] ?? false);
        $this->assertSame(['business_expense:ai'], $this->says($pret));

        // An invoice match outranks it: refused, and the writer says why.
        $acme = Client::query()->create(['name' => 'Acme Events']);
        Invoice::query()->forceCreate(['client_id' => $acme->id, 'number' => 'INV-101', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-01']);
        $pay = $this->tx('2025-06-20', 45000, 'ACME EVENTS LTD', 'Acme Events Ltd');
        app(Categoriser::class)->suggestFor(collect([$pay]));
        $writer = app(AllocationWriter::class);
        $this->assertFalse($writer->decide($pay, AllocationWriter::lines(45000, 'loan'), 'ai', false, 'You said it was a loan', null, new Provenance('ai.assistant')));
        $this->assertSame('outranked', $writer->refused);
        $this->assertSame(['business_income:matcher'], $this->says($pay));
        $this->assertTrue($writer->decide($pret->fresh(), AllocationWriter::lines(-650, 'personal'), 'ai', false, 'AI: lunch', null, new Provenance('ai.assistant')));
        $this->assertNull($writer->refused);
    }

    public function test_suggest_with_ai_sends_the_payments_you_said_no_to_after_the_others(): void
    {
        $old1 = $this->tx('2025-05-02', -650, 'PRET A MANGER LONDON');
        $old2 = $this->tx('2025-05-03', -720, 'PRET A MANGER LEEDS');
        $new = $this->tx('2025-06-20', -1200, 'SCREWFIX DIRECT');
        foreach ([$old1, $old2] as $t) {
            Vetoes::record('allocation_shape', 'tx:'.$t->id, null, Vetoes::shape([['kind' => 'personal']]), null, 'block', 'not_mine');
        }
        $ai = new FakeAiClient([FakeAiClient::json(['suggestions' => []]), FakeAiClient::json(['suggestions' => []])]);
        $this->app->instance(AiClient::class, $ai);
        app(TransactionAdvisor::class)->suggest(null, 2);
        app(TransactionAdvisor::class)->suggest(null, 5);
        $sent = array_map(fn ($req) => array_column(json_decode(substr($req['messages'][0]['content'], strpos($req['messages'][0]['content'], "Transactions:\n") + 14), true), 'id'), $ai->requests);
        $this->assertSame([$new->id, $old1->id], $sent[0], 'The newer line first, then the oldest you said no to');
        $this->assertSame([$new->id, $old1->id, $old2->id], $sent[1], 'Still sent when there’s room: another answer may fit');
    }

    public function test_always_do_this_counts_only_the_payments_it_would_decide(): void
    {
        $vetoed = $this->tx('2025-06-02', -650, 'PRET A MANGER LONDON');
        $waiting = $this->tx('2025-06-03', -650, 'PRET A MANGER LONDON');
        app(Categoriser::class)->suggestFor(collect([$vetoed, $waiting]));
        $this->post("/items/bank/{$vetoed->id}/clear", ['reason_code' => 'not_business', 'shown' => $this->shown($vetoed), 'quiet' => true]);
        foreach (['2025-06-04', '2025-06-05'] as $date) {
            $this->post('/items/bank/'.$this->tx($date, -650, 'PRET A MANGER LONDON')->id.'/decide', ['kind' => 'personal', 'quiet' => true]);
        }
        $learner = app(RuleLearner::class);
        $offer = $learner->offer($vetoed->merchant_key);
        $this->assertSame(1, $offer['waiting']);
        $this->assertStringContainsString('apply it to the 1 waiting', $offer['text']);
        $this->assertSame(1, collect($learner->offers())->firstWhere('merchant_key', $vetoed->merchant_key)['waiting'], 'The to-do list counts the same');
        $this->assertSame(1, $learner->accept($vetoed->merchant_key)['applied']);
        $this->assertSame([], $this->says($vetoed));
    }

    public function test_links_to_check_lists_only_draft_invoices_and_keeps_why_you_said_no(): void
    {
        $this->withoutVite();
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11', 'po_number' => 'PO-4471', 'status' => 'done']);
        $draft = Invoice::query()->forceCreate(['client_id' => $client->id, 'status' => 'draft', 'po_number' => 'PO 4471', 'currency' => 'GBP', 'total_minor' => 45000]);
        $issued = Invoice::query()->forceCreate(['client_id' => $client->id, 'status' => 'draft', 'po_number' => 'PO-4471', 'currency' => 'GBP', 'total_minor' => 30000]);
        app(JobFinder::class)->followUp($job);
        $this->assertSame([$job->id, $job->id], [(int) $draft->fresh()->work_job_id, (int) $issued->fresh()->work_job_id]);
        $issued->update(['status' => 'issued', 'number' => 'INV-0001']);
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'confirmed', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'email']);
        app(TravelMatcher::class)->suggest($hotel);
        $this->assertSame([$job->id, 'rules'], [(int) $hotel->fresh()->work_job_id, $hotel->fresh()->job_linked_by]);

        $cards = collect($this->get('/intel/proposals')->assertOk()->viewData('page')['props']['proposals']);
        $this->assertSame(['inv:'.$draft->id], $cards->where('link', 'invoice_job')->pluck('subject')->values()->all(), 'Issuing it was accepting it there');

        // The reason you pick is kept: in the veto, and in the link's history.
        $this->post('/intel/proposals', ['link' => 'invoice_job', 'subject' => 'inv:'.$draft->id, 'object' => 'job:'.$job->id, 'answer' => 'reject', 'reason_code' => 'wrong_job'])
            ->assertSessionHas('success');
        $this->assertSame(['wrong_job', 'Wrong job'], array_values(Veto::query()->where('relation', 'invoice_job')->sole()->only(['reason_code', 'reason'])));
        $this->assertSame('Not this job: wrong job', AuditEvent::query()->where('action', 'job.find_rejected')->sole()->reason);
        $this->post('/intel/proposals', ['link' => 'travel_job', 'subject' => 'travel:'.$hotel->id, 'object' => 'job:'.$job->id, 'answer' => 'reject', 'reason_code' => 'not_business'])
            ->assertSessionHas('success');
        $this->assertSame('Not this job: not for work', AuditEvent::query()->where('action', 'travel.job_linked')->where('actor', 'you')->sole()->reason);
    }

    public function test_the_inbox_says_what_else_a_learned_guess_weighed_in_plain_words(): void
    {
        $writer = app(AllocationWriter::class);
        foreach (['2025-04-01', '2025-04-08', '2025-04-15'] as $date) {
            $writer->decide($this->tx($date, -9000, 'JB SOUND HIRE'), AllocationWriter::lines(-9000, 'business_expense', $this->cat('equipment')));
        }
        $writer->decide($this->tx('2025-04-22', -1500, 'JB SOUND HIRE'), AllocationWriter::lines(-1500, 'personal'));
        $waiting = $this->tx('2025-05-01', -9000, 'JB SOUND HIRE');
        app(Categoriser::class)->suggestFor(collect([$waiting]));

        $item = collect($this->get('/inbox?status=suggested')->assertOk()->viewData('page')['props']['items'])->firstWhere('id', $waiting->id);
        $alt = $item['suggestion_why']['alternatives'][0];
        $this->assertSame(['Personal', '1 of your last 4'], [$alt['label'], $alt['note']], 'The other way you decided it, not another payment');

        // Another record (a transfer's runner-up) is named, with how far behind in points.
        $tx = $this->tx('2025-06-12', -10000, 'ALEX SMITH', 'Alex Smith');
        $runnerUp = $this->tx('2025-06-13', 10000, 'ALEX SMITH', 'Alex Smith');
        $writer->decide($tx, AllocationWriter::lines(-10000, 'transfer'), 'matcher', false, 'Matches savings', null,
            new Provenance('transfer.pair', [], 8, [['ref' => 'tx:'.$runnerUp->id, 'score' => 5]]));
        $item = collect($this->get('/inbox?status=suggested')->viewData('page')['props']['items'])->firstWhere('id', $tx->id);
        $this->assertStringContainsString('13 Jun 2025', $item['suggestion_why']['alternatives'][0]['label']);
        $this->assertSame('3 points behind', $item['suggestion_why']['alternatives'][0]['note']);
    }
}
