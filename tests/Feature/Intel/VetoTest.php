<?php

namespace Tests\Feature\Intel;

use App\Domain\Ai\TransactionAdvisor;
use App\Domain\Intel\Provenance;
use App\Domain\Intel\Vetoes;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\Suggester;
use App\Domain\Matching\TransferPairer;
use App\Domain\Mileage\JobTrips;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoList;
use App\Domain\Travel\TravelMatcher;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobFind;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rejection memory (M7): what you said no to isn't proposed again by anything automatic, and you can still choose it
 * yourself; each "no" is narrow (that payment, that shape or link, that method), visible, and can be lifted, audited.
 */
class VetoTest extends TestCase
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

    /** A finished job a drive from home, with the postcodes known (nothing is looked up). */
    private function drivableJob(): WorkJob
    {
        Setting::put('home_postcode', 'LS2 8BY');
        foreach (['LS2 8BY' => [53.8035, -1.5487], 'M2 3GX' => [53.4767, -2.2469]] as $pc => [$lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);

        return WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'postcode' => 'M2 3GX', 'location' => 'Manchester Central', 'status' => 'done']);
    }

    /** @return list<string> what the payment says now: kind and decided_by of each line */
    private function says(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()->map(fn (Allocation $a) => $a->kind.':'.$a->decided_by)->all();
    }

    public function test_a_cleared_food_or_drink_suggestion_isnt_proposed_again_by_the_categoriser_transfers_or_the_ai_but_you_can_still_choose_it(): void
    {
        $pret = $this->tx('2025-06-12', -650, 'PRET A MANGER LONDON');
        app(Categoriser::class)->suggestFor(collect([$pret]));
        $suggested = Allocation::query()->where('bank_transaction_id', $pret->id)->sole();
        $this->assertSame(['personal', 'matcher', 'categorise.hint'], [$suggested->kind, $suggested->decided_by, $suggested->method]);
        $this->assertStringStartsWith('Looks like food or drink', (string) $suggested->reason);

        // "Not this one" in the Inbox, with a reason.
        $this->post("/items/bank/{$pret->id}/clear", ['reason_code' => 'not_mine'])->assertSessionHas('success');
        $veto = Veto::query()->sole();
        $this->assertSame(['allocation_shape', 'tx:'.$pret->id, null, null, 'block', 'not_mine'], [$veto->relation, $veto->subject_ref, $veto->object_ref, $veto->method, $veto->effect, $veto->reason_code]);
        $this->assertSame(Vetoes::shape([['kind' => 'personal']]), $veto->shape);
        $recorded = AuditEvent::query()->where('action', 'veto.recorded')->sole();
        $this->assertSame(['you', 'BankTransaction', $pret->id, 'web'], [$recorded->actor, $recorded->subject_type, $recorded->subject_id, $recorded->channel]);

        // The next night: the categoriser, the transfer passes and the AI advisor all leave it alone.
        app(Categoriser::class)->suggestFor(collect([$pret->fresh()]));
        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $pairer->suggestOneSided();
        $items = collect([['id' => $pret->id, 'jobs_around_date' => []]]);
        $r = app(TransactionAdvisor::class)->apply([['id' => $pret->id, 'kind' => 'personal', 'category_code' => null, 'job_id' => null, 'business_pct' => 100, 'reason' => 'A sandwich']],
            collect([$pret->fresh()]), $items);
        $this->assertSame(['suggested' => 0, 'skipped' => 1], $r);
        $this->assertSame([], $this->says($pret));
        $this->assertSame('unreviewed', $pret->fresh()->review_status);

        // Something else is still proposed (the veto is about that shape only), and you can still choose it yourself.
        $this->assertTrue(app(AllocationWriter::class)->decide($pret->fresh(), AllocationWriter::lines(-650, 'business_expense', $this->cat('travel_subsistence')), 'ai', false, 'AI: a work lunch',
            null, new Provenance('ai.advisor')));
        $this->post("/items/bank/{$pret->id}/decide", ['kind' => 'personal'])->assertSessionHas('success');
        $this->assertSame(['personal:you'], $this->says($pret));
    }

    public function test_a_cleared_transfer_suggestion_isnt_suggested_or_paired_again(): void
    {
        $savings = Account::query()->create(['name' => 'Starling Savings', 'kind' => 'bank']);
        $out = $this->tx('2025-06-12', -10000, 'ALEX SMITH', 'Alex Smith');
        $in = $this->tx('2025-06-12', 10000, 'ALEX SMITH', 'Alex Smith', $savings);
        $pairer = app(TransferPairer::class);
        $pairer->pairAll();
        $this->assertSame($in->id, $out->fresh()->transfer_pair_id);

        // You decide one side isn't a transfer: unpaired, and remembered as a pair you said no to.
        $this->post("/items/bank/{$out->id}/decide", ['kind' => 'personal'])->assertSessionHas('success');
        $this->assertNull($out->fresh()->transfer_pair_id);
        $pair = Veto::query()->where('relation', 'transfer_pair')->sole();
        $this->assertSame(['tx:'.min($out->id, $in->id), 'tx:'.max($out->id, $in->id), 'unpaired'], [$pair->subject_ref, $pair->object_ref, $pair->reason_code]);

        // Sent back to the Inbox later: the two are never paired again.
        $this->post("/items/bank/{$out->id}/clear")->assertSessionHas('success');
        $pairer->pairAll();
        $this->assertNull($out->fresh()->transfer_pair_id);
        $this->assertNull($in->fresh()->transfer_pair_id);
        $this->assertFalse(Vetoes::allows('transfer_pair', 'tx:'.$in->id, 'tx:'.$out->id), 'Either way round');

        // A one-sided transfer suggestion you clear isn't made again.
        $pairer->suggestOneSided();
        $this->assertSame(['transfer:matcher'], $this->says($in));
        $this->post("/items/bank/{$in->id}/clear", ['reason_code' => 'other'])->assertSessionHas('success');
        $pairer->suggestOneSided();
        $pairer->pairAll();
        $this->assertSame([], $this->says($in));
    }

    public function test_the_inbox_undo_creates_no_veto_and_takes_back_the_one_your_decision_made(): void
    {
        $pret = $this->tx('2025-06-12', -650, 'PRET A MANGER LONDON');
        app(Categoriser::class)->suggestFor(collect([$pret]));

        // Accepted, then Undo: nothing said no to.
        $this->post("/items/bank/{$pret->id}/confirm", ['quiet' => true]);
        $this->post("/items/bank/{$pret->id}/clear", ['reason' => 'Undo from Inbox', 'undo' => true, 'quiet' => true]);
        $this->assertSame(0, Veto::query()->count());

        // Decided differently: the suggestion is remembered as not wanted...
        app(Categoriser::class)->suggestFor(collect([$pret->fresh()]));
        $this->post("/items/bank/{$pret->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence')]);
        $veto = Veto::query()->sole();
        $this->assertSame('changed', $veto->reason_code);
        // ...and Undo straight away puts that back too, audited.
        $this->post("/items/bank/{$pret->id}/clear", ['reason' => 'Undo from Inbox', 'undo' => true, 'quiet' => true]);
        $this->assertNotNull($veto->fresh()->lifted_at);
        $this->assertSame(1, Veto::query()->count(), 'Undo made none of its own');
        $this->assertSame(['you', 'Undone from the Inbox'], array_values(AuditEvent::query()->where('action', 'veto.lifted')->sole()->only(['actor', 'reason'])));
        app(Categoriser::class)->suggestFor(collect([$pret->fresh()]));
        $this->assertSame(['personal:matcher'], $this->says($pret), 'Suggested again, as before');
    }

    public function test_lifting_a_veto_is_audited_and_lets_it_be_suggested_again(): void
    {
        $pret = $this->tx('2025-06-12', -650, 'PRET A MANGER LONDON');
        app(Categoriser::class)->suggestFor(collect([$pret]));
        $this->post("/items/bank/{$pret->id}/clear", ['reason_code' => 'wrong_category']);
        $veto = Veto::query()->sole();

        // Shown in the why-panel, with what was said no to, and liftable from there (bound to what was shown).
        $why = $this->getJson('/why?ref=tx:'.$pret->id)->assertOk()->json('said_no');
        $this->assertSame([$veto->id, 'Not: Personal', 'Wrong category'], [$why[0]['id'], $why[0]['what'], $why[0]['reason']]);
        $this->post('/todo/veto_lift/'.$veto->id, ['fp' => $why[0]['fp']])->assertSessionHas('success');

        $this->assertNotNull($veto->fresh()->lifted_at);
        $lifted = AuditEvent::query()->where('action', 'veto.lifted')->sole();
        $this->assertSame(['you', 'web', 'BankTransaction', $pret->id], [$lifted->actor, $lifted->channel, $lifted->subject_type, $lifted->subject_id]);
        $this->assertSame([], $this->getJson('/why?ref=tx:'.$pret->id)->json('said_no'));
        app(Categoriser::class)->suggestFor(collect([$pret->fresh()]));
        $this->assertSame(['personal:matcher'], $this->says($pret));

        // Lifted already: a second press of the same button says so (not that it changed) and changes nothing.
        $this->post('/todo/veto_lift/'.$veto->id, ['fp' => $why[0]['fp']])->assertSessionHas('error', 'That’s already been dealt with.');
        $this->assertSame(1, AuditEvent::query()->where('action', 'veto.lifted')->count());
    }

    public function test_a_payment_unlinked_from_a_booking_isnt_found_for_it_again(): void
    {
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'confirmed', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'email']);
        $paid = $this->tx('2025-06-09', -8900, 'PREMIER INN LEEDS');
        $matcher = app(TravelMatcher::class);
        $matcher->suggest($hotel);
        $this->assertSame($paid->id, $hotel->fresh()->bank_transaction_id);

        // "Booked by the client": the payment isn't this stay's.
        $matcher->decide($hotel->fresh(), 'client_booked', null, null, null);
        $this->assertNull($hotel->fresh()->bank_transaction_id);
        $this->assertSame(['travel_payment', 'travel:'.$hotel->id, 'tx:'.$paid->id], array_values(Veto::query()->sole()->only(['relation', 'subject_ref', 'object_ref'])));

        // Reopened and lined up again: that payment isn't found for it.
        $this->post("/travel/{$hotel->id}/reopen");
        $this->assertNull($hotel->fresh()->bank_transaction_id);
        $this->assertSame([], $this->says($paid));
    }

    public function test_an_invoice_rejected_on_a_job_stays_unlinked_after_a_gather_and_a_follow_up(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'po_number' => 'PO-4471', 'status' => 'done']);
        $invoice = Invoice::query()->forceCreate(['client_id' => $client->id, 'status' => 'draft', 'po_number' => 'PO 4471', 'currency' => 'GBP', 'total_minor' => 45000]);
        $finder = app(JobFinder::class);
        $this->assertSame(1, $finder->followUp($job)['invoices'], 'Same PO number');
        $this->assertSame($job->id, $invoice->fresh()->work_job_id);

        // "Not this job" on the job's list.
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('work_job_id', $job->id)->where('type', 'invoice')->sole();
        app(JobGatherer::class)->reject($find);
        $this->assertNull($invoice->fresh()->work_job_id);
        $veto = Veto::query()->sole();
        $this->assertSame(['invoice_job', 'inv:'.$invoice->id, 'job:'.$job->id], [$veto->relation, $veto->subject_ref, $veto->object_ref]);

        // A gather and a follow-up: still not linked, even with the job's own list of rejections gone.
        app(JobGatherer::class)->gather($job, false);
        $finder->followUp($job);
        $this->assertNull($invoice->fresh()->work_job_id);
        JobFind::query()->delete();
        $finder->followUp($job);
        $this->assertNull($invoice->fresh()->work_job_id);

        // Lifted: "Same PO number" may link it again (and the job's list forgets the rejection).
        Vetoes::lift($veto);
        $this->assertSame(1, $finder->followUp($job)['invoices']);
    }

    public function test_didnt_drive_leaves_a_job_off_the_mileage_page_until_put_back(): void
    {
        $job = $this->drivableJob();
        $this->assertSame([$job->id], array_column(JobTrips::proposals('2025-26'), 'job_id'));

        $this->post('/mileage/not-driven', ['job_id' => $job->id])->assertSessionHas('success');
        $this->assertSame([], JobTrips::proposals('2025-26'));
        $this->assertSame([$job->id], array_column(JobTrips::leftOut('2025-26'), 'job_id'));
        $this->get('/mileage?year=2025-26')->assertInertia(fn ($page) => $page->where('fromJobs', [])->where('notDriven.0.job_id', $job->id));
        $this->assertSame(['mileage_day', 'job:'.$job->id, null, 'no_drive'], array_values(Veto::query()->sole()->only(['relation', 'subject_ref', 'object_ref', 'reason_code'])));

        $this->post("/mileage/not-driven/{$job->id}/undo")->assertSessionHas('success');
        $this->assertSame([$job->id], array_column(JobTrips::proposals('2025-26'), 'job_id'));
        $this->assertSame(1, AuditEvent::query()->where('action', 'veto.lifted')->count());
    }

    public function test_jobs_marked_not_relevant_on_the_to_do_list_arent_offered_on_the_mileage_page(): void
    {
        $job = $this->drivableJob();
        $key = collect(TodoList::build(50)['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2025-26:'))['key'] ?? null;
        $this->assertNotNull($key);

        Dismissals::dismiss($key, null, 'Not a problem');
        $this->assertSame([], JobTrips::proposals('2025-26'), 'You said you didn’t drive to it');
        $this->assertFalse(Vetoes::allows('mileage_day', 'job:'.$job->id), 'Read through the to-do list’s own store');

        // Put back from the Mileage page: the to-do list's "not relevant" goes too, audited.
        $this->post("/mileage/not-driven/{$job->id}/undo")->assertSessionHas('success');
        $this->assertSame([$job->id], array_column(JobTrips::proposals('2025-26'), 'job_id'));
        $this->assertTrue(Vetoes::allows('mileage_day', 'job:'.$job->id));
    }

    public function test_undoing_a_rule_remembers_it_for_rules_and_stops_the_offer(): void
    {
        foreach (['2025-05-01', '2025-05-08'] as $d) {
            $t = $this->tx($d, -1200, 'ADOBE CREATIVE CLOUD');
            app(AllocationWriter::class)->decide($t, AllocationWriter::lines(-1200, 'business_expense', $this->cat('software')));
        }
        $waiting = $this->tx('2025-06-01', -1200, 'ADOBE CREATIVE CLOUD');
        $learner = app(RuleLearner::class);
        $rule = $learner->accept($waiting->merchant_key)['rule'];
        $this->assertSame(['business_expense:rule'], $this->says($waiting));

        $learner->undo($rule);
        $this->assertSame([], $this->says($waiting));
        $veto = Veto::query()->sole();
        $this->assertSame(['allocation_shape', 'tx:'.$waiting->id, 'rule.learned', 'rule_undone'], [$veto->relation, $veto->subject_ref, $veto->method, $veto->reason_code]);
        $this->assertNull($learner->offer($waiting->merchant_key), 'Not offered again');
        $this->assertContains($waiting->merchant_key, (array) Setting::get('rule_offers_declined'));

        // Narrow: it holds back rules only. A suggestion by matching is still made.
        $this->assertFalse(Vetoes::allows('allocation_shape', 'tx:'.$waiting->id, null, $veto->shape, 'rule.learned'));
        $this->assertTrue(Vetoes::allows('allocation_shape', 'tx:'.$waiting->id, null, $veto->shape, 'categorise.merchant'));
        app(Categoriser::class)->suggestFor(collect([$waiting->fresh()]));
        $this->assertSame(['business_expense:matcher'], $this->says($waiting));
    }

    public function test_precedence_an_open_suggestion_is_never_replaced_by_one_it_outranks(): void
    {
        $writer = app(AllocationWriter::class);
        $tx = $this->tx('2025-06-12', -4500, 'ENTERPRISE RENT-A-CAR');
        $hire = $this->cat('travel_car_hire');
        $rule = MerchantRule::query()->create(['merchant_key' => $tx->merchant_key, 'kind' => 'business_expense', 'category_id' => $hire, 'business_pct' => 100, 'auto_confirm' => false]);
        $this->assertTrue($writer->decide($tx, AllocationWriter::lines(-4500, 'business_expense', $hire), 'rule', false, 'Your rule', $rule->id, new Provenance('rule.owner', ['rule:'.$rule->id])));

        // A matcher's guess or the AI's never replaces your rule's suggestion.
        $this->assertFalse($writer->decide($tx->fresh(), AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks like', null, new Provenance('categorise.hint')));
        $this->assertFalse($writer->decide($tx->fresh(), AllocationWriter::lines(-4500, 'personal'), 'ai', false, 'AI: personal', null, new Provenance('ai.advisor')));
        $this->assertSame(['business_expense:rule'], $this->says($tx));

        // Lower replaced by higher: an AI guess gives way to the categoriser's, and that to a pattern's.
        $other = $this->tx('2025-06-13', -4500, 'SOMEWHERE');
        $this->assertTrue($writer->decide($other, AllocationWriter::lines(-4500, 'personal'), 'ai', false, 'AI: personal', null, new Provenance('ai.advisor')));
        $this->assertTrue($writer->decide($other->fresh(), AllocationWriter::lines(-4500, 'business_expense', $hire), 'matcher', false, 'Looks like car hire', null, new Provenance('categorise.hint')));
        $this->assertTrue($writer->decide($other->fresh(), AllocationWriter::lines(-4500, 'business_expense', $hire), 'matcher', false, 'Looks like car hire, usually', null, new Provenance('suggest.car_hire')));
        $this->assertFalse($writer->decide($other->fresh(), AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks like', null, new Provenance('categorise.hint')));
        $this->assertSame('suggest.car_hire', Allocation::query()->where('bank_transaction_id', $other->id)->value('method'));
        $this->assertTrue(AllocationWriter::outranks('suggest.invoice', 'matcher', 'transfer.one_sided', 'matcher'));
        $this->assertTrue(AllocationWriter::outranks(null, 'rule', 'suggest.invoice', 'matcher'));
    }

    public function test_an_ask_veto_lets_it_be_proposed_but_never_confirmed_on_its_own(): void
    {
        $tx = $this->tx('2025-06-12', -1200, 'ADOBE CREATIVE CLOUD');
        $lines = AllocationWriter::lines(-1200, 'business_expense', $this->cat('software'));
        Vetoes::record('allocation_shape', 'tx:'.$tx->id, null, Vetoes::shape($lines), null, 'ask');
        $rule = MerchantRule::query()->create(['merchant_key' => $tx->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->cat('software'), 'business_pct' => 100, 'auto_confirm' => true]);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines, 'rule', true, 'Your rule', $rule->id, new Provenance('rule.owner')));
        $this->assertSame(['suggested', null], [$tx->fresh()->review_status, Allocation::query()->where('bank_transaction_id', $tx->id)->value('confirmed_at')]);
    }

    public function test_a_rules_job_applies_only_within_that_jobs_dates(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11', 'status' => 'done']);
        $during = $this->tx('2025-06-11', -3000, 'KIT HIRE LTD');
        $later = $this->tx('2025-07-20', -3000, 'KIT HIRE LTD');
        MerchantRule::query()->create(['merchant_key' => $during->merchant_key, 'kind' => 'business_expense', 'category_id' => $this->cat('equipment'), 'work_job_id' => $job->id,
            'business_pct' => 100, 'auto_confirm' => false]);
        app(Suggester::class)->suggestFor(collect([$during, $later]));

        $this->assertSame($job->id, Allocation::query()->where('bank_transaction_id', $during->id)->value('work_job_id'));
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $later->id)->value('work_job_id'), 'Outside the job’s dates: the rule applies, without the job');
        $this->assertSame('business_expense', Allocation::query()->where('bank_transaction_id', $later->id)->value('kind'));
        // Learning from a later decision doesn't take it for a changed rule and suggest it again.
        $this->assertSame(0, app(Categoriser::class)->learnFrom(collect([$this->tx('2025-07-21', -3000, 'KIT HIRE LTD')])));
    }

    public function test_proposals_list_automatic_links_and_not_this_one_remembers_it(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11', 'po_number' => 'PO-4471', 'status' => 'done']);
        $other = WorkJob::query()->create(['title' => 'Arena show', 'client_id' => $client->id, 'starts_on' => '2025-06-12', 'ends_on' => '2025-06-12', 'status' => 'done']);
        $invoice = Invoice::query()->forceCreate(['client_id' => $client->id, 'status' => 'draft', 'po_number' => 'PO 4471', 'currency' => 'GBP', 'total_minor' => 45000]);
        app(JobFinder::class)->followUp($job);
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'confirmed', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11',
            'place' => 'Premier Inn Leeds', 'amount_minor' => 8900, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'email']);
        $paid = $this->tx('2025-06-09', -8900, 'PREMIER INN LEEDS');
        app(TravelMatcher::class)->suggest($hotel);
        $this->assertSame($paid->id, $hotel->fresh()->bank_transaction_id);
        // A link that weighed a runner-up shows it ("Also possible").
        (new Provenance('jobs.travel', ['travel:'.$hotel->id], 6, [['ref' => 'job:'.$other->id, 'score' => 4]]))->recordLink('travel_job', 'travel:'.$hotel->id, 'job:'.$job->id);

        $this->withoutVite(); // a new page: the built assets don't have it until the next build
        $this->get('/intel/proposals')->assertOk()->assertInertia(fn ($page) => $page->component('intel/proposals', false)
            ->where('proposals', function ($cards) use ($invoice, $hotel, $paid, $other) {
                $cards = collect($cards);
                $inv = $cards->firstWhere('link', 'invoice_job');
                $pay = $cards->firstWhere('link', 'travel_payment');
                $stay = $cards->firstWhere('link', 'travel_job');

                return $inv && $inv['subject'] === 'inv:'.$invoice->id && $inv['method'] === 'the same PO number as the job'
                    && $pay && $pay['object'] === 'tx:'.$paid->id && $pay['can_accept'] === false
                    && $stay && $stay['subject'] === 'travel:'.$hotel->id && $stay['alternatives'][0]['ref'] === 'job:'.$other->id && $stay['alternatives'][0]['behind'] === 2;
            })->where('reasons', Vetoes::REASONS));

        // Not this one: the stay's payment is unlinked, its matcher suggestion taken back, and remembered.
        $this->post('/intel/proposals', ['link' => 'travel_payment', 'subject' => 'travel:'.$hotel->id, 'object' => 'tx:'.$paid->id, 'answer' => 'reject', 'reason_code' => 'not_mine'])
            ->assertSessionHas('success');
        $this->assertNull($hotel->fresh()->bank_transaction_id);
        $this->assertSame([], $this->says($paid));
        $this->assertFalse(Vetoes::allows('travel_payment', 'travel:'.$hotel->id, 'tx:'.$paid->id));
        app(TravelMatcher::class)->suggest($hotel->fresh());
        $this->assertNull($hotel->fresh()->bank_transaction_id, 'Not found for it again');

        // Not this one on the invoice: off the job, and "Same PO number" doesn't put it back.
        $this->post('/intel/proposals', ['link' => 'invoice_job', 'subject' => 'inv:'.$invoice->id, 'object' => 'job:'.$job->id, 'answer' => 'reject', 'reason_code' => 'wrong_job'])
            ->assertSessionHas('success');
        $this->assertNull($invoice->fresh()->work_job_id);
        app(JobFinder::class)->followUp($job);
        $this->assertNull($invoice->fresh()->work_job_id);

        // Accepting the stay's job makes it yours: no longer listed. A stale answer is refused.
        $this->post('/intel/proposals', ['link' => 'travel_job', 'subject' => 'travel:'.$hotel->id, 'object' => 'job:'.$job->id, 'answer' => 'accept'])->assertSessionHas('success');
        $this->assertSame('you', $hotel->fresh()->job_linked_by);
        $this->post('/intel/proposals', ['link' => 'travel_job', 'subject' => 'travel:'.$hotel->id, 'object' => 'job:'.$other->id, 'answer' => 'reject'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'changed since'));
        $this->get('/intel/proposals')->assertInertia(fn ($page) => $page->where('proposals', fn ($cards) => collect($cards)->where('link', 'travel_job')->isEmpty()));
    }

    public function test_vetoes_are_narrow_and_only_hold_back_automation(): void
    {
        $a = $this->tx('2025-06-12', -650, 'PRET A MANGER LONDON');
        $b = $this->tx('2025-06-13', -650, 'PRET A MANGER LONDON');
        $categoriser = app(Categoriser::class);
        $categoriser->suggestFor(collect([$a, $b]));
        $this->post("/items/bank/{$a->id}/clear", ['reason_code' => 'not_mine']);

        // Another payment from the same place is still suggested.
        $this->assertSame(['personal:matcher'], $this->says($b));
        // Fingerprints of a lift exist only while it's there to lift.
        $veto = Veto::query()->sole();
        $this->assertNotNull(Fingerprints::of('veto_lift', (string) $veto->id));
        $this->assertSame(Vetoes::about('tx:'.$b->id), []);
        $this->assertCount(1, Vetoes::about('tx:'.$a->id));
    }
}
