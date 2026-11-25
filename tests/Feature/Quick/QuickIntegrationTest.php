<?php

namespace Tests\Feature\Quick;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Import\Importer;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Steps\RelateReceipts;
use App\Domain\Intel\Detectors\CostNoReceipt;
use App\Domain\Intel\Detectors\DupeCrossSource;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Mileage\JobTrips;
use App\Domain\Verification\Verifier;
use App\Jobs\ReadDocument;
use App\Models\Allocation;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\MerchantRule;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Where quick add meets the rest of Expend (the changes asked of other files: skipped until they're in): receipts
 * matched after every import, before the guesses and above a rule's suggestion; the precedence; the cash-and-card check
 * owning its pairs; fares from the phone stopping a job's mileage; phone photos as receipts for their own items; no
 * second read of a phone photo; and the nightly step.
 */
class QuickIntegrationTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
        $this->needsIntegration();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    private function card(array $over = []): QuickEntry
    {
        $f = $this->fields(array_merge(['paid_with' => 'business', 'account_id' => $this->starling->id], $over));
        $this->send($f)->assertCreated();

        return $this->entry($f['client_key']);
    }

    public function test_the_receipt_ranks_between_a_rule_and_you(): void
    {
        $this->assertSame(8, AllocationWriter::OWNER);
        $this->assertSame(7, AllocationWriter::rank('receipt.quick', 'matcher'));
        $this->assertTrue(AllocationWriter::outranks('receipt.quick', 'matcher', 'rule.learned', 'rule'));
        $this->assertTrue(AllocationWriter::outranks(null, 'you', 'receipt.quick', 'matcher'));
    }

    public function test_an_import_matches_waiting_receipts_after_pairing_and_over_a_rules_suggestion(): void
    {
        $e = $this->card(['spent_on' => '2026-11-24']);
        $this->assertSame('waiting', $e->fresh()->status);
        MerchantRule::query()->create(['merchant_key' => 'NCP LEEDS', 'kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence'), 'business_pct' => 100,
            'auto_confirm' => false]);
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');

        app(Importer::class)->afterImport(ImportBatch::query()->findOrFail($line->import_batch_id));

        $this->assertSame([$line->id, 'attached'], [(int) $e->fresh()->bank_transaction_id, $e->fresh()->status]);
        $a = Allocation::query()->where('bank_transaction_id', $line->id)->sole();
        $this->assertSame(['receipt.quick', $this->cat('travel_parking'), false], [$a->method, (int) $a->category_id, $a->confirmed_at !== null]);
    }

    public function test_a_rule_that_confirms_by_itself_waits_for_you_on_a_line_with_your_receipt(): void
    {
        foreach (['2026-11-01', '2026-11-08', '2026-11-15'] as $d) {
            $old = $this->line($d, -900, 'NCP LEEDS');
            app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_parking')], collect([$old]));
        }
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $this->card(['what' => 'NCP', 'what_source' => 'typed', 'category_id' => $this->cat('travel_subsistence')]);
        $this->assertSame('receipt.quick', Allocation::query()->where('bank_transaction_id', $line->id)->sole()->method);

        app(RuleLearner::class)->accept('NCP LEEDS');

        $a = Allocation::query()->where('bank_transaction_id', $line->id)->sole();
        $this->assertSame(['receipt.quick', null], [$a->method, $a->confirmed_at], 'Outranked: the rule never confirms over your receipt');
    }

    public function test_a_cash_expense_from_the_phone_is_left_to_its_own_check(): void
    {
        $this->send($this->fields(['what' => 'NCP Leeds', 'what_source' => 'typed']))->assertCreated();
        $this->line('2026-11-25', -1250, 'NCP LEEDS');

        $this->assertSame([], (new DupeCrossSource)->detect(new CycleContext), 'quick.maybe_card has it, so it isn’t found twice');
        $this->assertArrayHasKey('quick.maybe_card', $this->findings());
    }

    public function test_a_train_fare_from_the_phone_is_a_journey_and_a_taxi_isnt(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked', 'postcode' => 'LS1 8BY']);
        $this->send($this->fields(['what' => 'Taxi', 'category_id' => $this->cat('travel_taxi'), 'work_job_id' => $job->id]))->assertCreated();
        $this->assertSame([], JobTrips::journeys(collect([$job->id => $job])));

        $this->send($this->fields(['what' => 'Train', 'amount' => '64.20', 'category_id' => $this->cat('travel_rail'), 'work_job_id' => $job->id]))->assertCreated();
        $this->assertSame([$job->id => ['rail']], JobTrips::journeys(collect([$job->id => $job])));
    }

    public function test_your_phone_photos_are_the_receipts_of_their_own_items_only(): void
    {
        Setting::put(CostNoReceipt::THRESHOLD_SETTING, 100);
        [$file, $photo] = $this->photo();
        $cash = $this->fields(['photo' => $photo]);
        $this->send($cash)->assertCreated();
        $this->sendPhoto($cash['client_key'], $file)->assertOk();
        // A card line of the same amount, decided as a business cost: the cash photo is never its receipt.
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_parking')], collect([$line]));

        $missing = collect((new CostNoReceipt)->detect(new CycleContext))->pluck('key')->all();
        $this->assertNotContains('noreceipt:exp:'.$this->entry($cash['client_key'])->manual_expense_id, $missing, 'The cash expense has its photo');
        $this->assertTrue(collect($missing)->contains(fn ($k) => str_contains($k, (string) $line->id)), 'The card line has no receipt of its own');
    }

    public function test_a_phone_photo_is_checked_against_what_you_typed_never_read_twice(): void
    {
        Setting::put('ai_extraction', 'on');
        Setting::put('anthropic_api_key', 'test-key');
        $ai = new FakeAiClient([FakeAiClient::json(['kind' => 'receipt', 'summary' => 'A parking receipt', 'facts' => [], 'job_link' => null,
            'receipt' => ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1520, 'currency' => 'GBP', 'quote' => 'TOTAL 15.20']])]);
        $this->app->instance(AiClient::class, $ai);
        Queue::fake();
        [$file, $photo] = $this->photo();
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        $doc = Document::query()->sole();
        Queue::assertPushedOn('reads', ReadDocument::class);

        (new ReadDocument($doc->id))->handle(app(DocumentReader::class), app(Verifier::class));

        $this->assertCount(1, $ai->requests, 'One read: no second read of the photo');
        $entry = $this->entry($f['client_key']);
        $this->assertSame('differs', $entry->read_check['status']);
        $this->assertSame('receipt', $doc->fresh()->kind);
        $this->assertArrayHasKey('quick.photo_differs', $this->findings());
    }

    public function test_the_nightly_step_is_in_the_plan_after_the_transfers(): void
    {
        $plan = CycleRunner::PLANS['nightly'];
        $this->assertSame(RelateReceipts::class, CycleRunner::STEPS[RelateReceipts::KEY]);
        $this->assertSame(array_search('relate.transfers', $plan, true) + 1, array_search(RelateReceipts::KEY, $plan, true));

        $e = $this->card(['spent_on' => '2026-11-24']);
        $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $out = app(RelateReceipts::class)->run(new CycleContext);
        $this->assertSame(1, $out->counts['attached'] ?? null);
        $this->assertSame('attached', $e->fresh()->status);
    }
}
