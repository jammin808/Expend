<?php

namespace Tests\Feature\Intel;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Domain\Travel\Places;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * M1, the first discoveries: payments to HMRC not recorded as tax, mileage not claimed for finished jobs, and filed
 * returns your records no longer match. All three only read; the one action (record as tax) is your own decision.
 */
class FirstDiscoveriesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->at('2026-11-24');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow($date.' 10:00:00');
        CarbonImmutable::setTestNow($date.' 10:00:00');
    }

    private function tx(string $date, int $pence, string $description, ?string $counterparty = null): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => strtoupper($description), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    /** @return array<string, array> the list's items by key */
    private function items(): array
    {
        return collect(TodoList::build(100)['items'])->keyBy('key')->all();
    }

    /** The item whose key starts with this ("mileage:2026-27:", whose last part is the jobs it's about), or null. */
    private function item(string $prefix): ?array
    {
        return collect(TodoList::build(100)['items'])->first(fn ($i) => str_starts_with($i['key'], $prefix));
    }

    private function decide(BankTransaction $tx, string $kind, ?int $categoryId = null): BankTransaction
    {
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, $kind, $categoryId));

        return $tx;
    }

    private function place(string $postcode, float $lat, float $lng): void
    {
        DB::table('places')->insert(['postcode' => $postcode, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- Payments to HMRC not recorded as tax ----

    public function test_a_payment_to_hmrc_decided_as_personal_is_listed_and_one_tap_records_it_as_tax(): void
    {
        $decisions = app(DecisionService::class);
        $personal = $this->tx('2026-01-30', -250_000, 'HMRC SELF ASSESSMENT', 'HMRC');
        $decisions->apply(['kind' => 'personal'], collect([$personal]));
        $tax = $this->tx('2026-07-31', -120_000, 'H M R C SA PAYMENT');
        $decisions->apply(['kind' => 'tax_payment'], collect([$tax]));
        $undecided = $this->tx('2026-08-03', -5_000, 'HMRC NDDS 1234');
        $this->tx('2026-08-04', -5_000, 'TESCO STORES'); // not HMRC
        $this->tx('2026-08-05', 40_000, 'HMRC REFUND'); // money in: not a payment

        $list = TodoList::build(100);
        $items = collect($list['items'])->keyBy('key');
        $item = $items['hmrc:'.$personal->id];
        $this->assertSame(['tax', 2], [$item['group'], $item['priority']]);
        $this->assertSame('Payment to HMRC not recorded as tax: £2,500.00 on 30 Jan 2026', $item['title']);
        $this->assertStringContainsString('Recorded as personal', $item['detail']);
        $this->assertSame(-250_000, $item['amount']);
        $this->assertSame([['code' => 'tx_tax', 'id' => (string) $personal->id, 'label' => 'Record as tax', 'style' => 'default']], $item['actions']);
        $this->assertFalse($items->has('hmrc:'.$tax->id), 'Recorded as tax already');
        $this->assertStringContainsString('Not decided yet', $items['hmrc:'.$undecided->id]['detail']);
        $this->assertSame(3, $list['checked']['hmrc'], 'Every payment to HMRC was looked at');

        $this->post('/todo/tx_tax/'.$personal->id)->assertSessionHas('success');
        $line = Allocation::query()->where('bank_transaction_id', $personal->id)->sole();
        $this->assertSame(['tax_payment', 'you'], [$line->kind, $line->decided_by]);
        $this->assertNotNull($line->confirmed_at);
        $this->assertSame('you', AuditEvent::query()->where('subject_type', 'BankTransaction')->where('subject_id', $personal->id)->latest('id')->value('actor'));
        $this->assertArrayNotHasKey('hmrc:'.$personal->id, $this->items());

        // Pressed again (a Telegram button): nothing changes.
        $this->post('/todo/tx_tax/'.$personal->id)->assertSessionHas('success', 'Already dealt with: the £2,500.00 payment to HMRC is recorded as tax.');
        // Not a payment to HMRC: the button can't be used on another line.
        $other = $this->tx('2026-08-06', -1_000, 'COSTA COFFEE');
        $this->post('/todo/tx_tax/'.$other->id)->assertSessionHas('error');
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $other->id)->count());
    }

    public function test_a_split_payment_to_hmrc_lists_only_the_part_not_recorded_as_tax_and_is_never_replaced(): void
    {
        // £2,500 of your own tax and £100 of someone else's bill, paid together.
        $tx = $this->tx('2026-01-30', -260_000, 'HMRC SELF ASSESSMENT');
        app(AllocationWriter::class)->decide($tx, [['kind' => 'tax_payment', 'amount_pence' => -250_000], ['kind' => 'personal', 'amount_pence' => -10_000]]);

        $item = $this->items()['hmrc:'.$tx->id];
        $this->assertSame('Part of a payment to HMRC not recorded as tax: £100.00 of £2,600.00 on 30 Jan 2026', $item['title']);
        $this->assertSame(-10_000, $item['amount']);
        $this->assertStringContainsString('Split: £2,500.00 as tax, £100.00 as personal', $item['detail']);
        $this->assertSame([], $item['actions'], 'One tap would undo the split');

        $this->post('/todo/tx_tax/'.$tx->id)->assertSessionHas('error');
        $this->assertSame(['personal', 'tax_payment'], Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('kind')->pluck('kind')->all());
    }

    public function test_a_payment_to_hm_revenue_and_customs_is_found(): void
    {
        $tx = $this->decide($this->tx('2026-01-30', -90_000, 'BILL PAYMENT', 'HM Revenue & Customs'), 'personal');
        $other = $this->decide($this->tx('2026-01-31', -2_000, 'CHM REVENUE MANAGEMENT'), 'personal');

        $items = $this->items();
        $this->assertSame('Payment to HMRC not recorded as tax: £900.00 on 30 Jan 2026', $items['hmrc:'.$tx->id]['title']);
        $this->assertArrayNotHasKey('hmrc:'.$other->id, $items);
    }

    public function test_the_monday_digest_and_the_findings_history_include_them(): void
    {
        $tx = $this->tx('2026-01-30', -250_000, 'HMRC SELF ASSESSMENT');
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$tx]));
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->artisan('ledger:telegram-digest')->assertSuccessful();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'Payment to HMRC not recorded as tax: £2,500.00 on 30 Jan 2026')
            && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 't|tx_tax|'.$tx->id);

        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'hmrc:'.$tx->id)->sole();
        $this->assertSame(['tax', '2026-11-24'], [$finding->group, $finding->first_seen_at->format('Y-m-d')]);
        $this->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p->where('by_group', fn ($groups) => collect($groups)->contains(fn ($g) => $g['group'] === 'tax' && $g['label'] === 'Tax records to check')));
    }

    public function test_in_a_locked_year_there_is_only_the_link(): void
    {
        $tx = $this->tx('2025-01-31', -90_000, 'HMRC SELF ASSESSMENT');
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$tx]));
        TaxYear::track('2024-25')->update(['locked_at' => now()]);

        $item = $this->items()['hmrc:'.$tx->id];
        $this->assertSame([], $item['actions']);
        $this->assertStringContainsString('2024-25 is locked', $item['detail']);
        $this->post('/todo/tx_tax/'.$tx->id)->assertSessionHas('error');
        $this->assertSame('personal', Allocation::query()->where('bank_transaction_id', $tx->id)->value('kind'));
    }

    public function test_an_old_telegram_button_never_replaces_a_decision_made_since(): void
    {
        $tx = $this->tx('2026-01-30', -250_000, 'HMRC SELF ASSESSMENT');
        $offered = now()->subHour();
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$tx]));

        $this->assertThrows(fn () => app(TodoActions::class)->run('tx_tax', (string) $tx->id, $offered), \InvalidArgumentException::class, 'decided since');
        $this->assertSame('personal', Allocation::query()->where('bank_transaction_id', $tx->id)->value('kind'));
    }

    // ---- Mileage not claimed ----

    public function test_a_finished_job_driven_to_with_no_mileage_is_listed_with_its_miles_and_the_years_rate(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781); // Reading: about 36 miles in a straight line
        $this->place('LS2 8BY', 53.8035, -1.5487);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $drove = WorkJob::query()->create(['title' => 'Reading conference', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA', 'location' => 'Hexagon']);
        $train = WorkJob::query()->create(['title' => 'Leeds by train', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'postcode' => 'LS2 8BY']);
        TravelItem::query()->create(['kind' => 'rail', 'provider' => 'LNER', 'status' => 'booked', 'starts_on' => '2026-10-14', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $train->id]);
        WorkJob::query()->create(['title' => 'Next month', 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-10', 'postcode' => 'RG1 1AA']); // not finished
        Http::fake();

        $list = TodoList::build(100);
        $item = collect($list['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
        $miles = round(Places::miles('SW1A 1AA', 'RG1 1AA') * 1.25, 1) * 2; // a return trip, straight line × 1.25
        $this->assertSame(['claims', 2], [$item['group'], $item['priority']]);
        $this->assertSame('1 finished job with no mileage: about '.number_format($miles).' miles, about £'.number_format(round($miles * 55) / 100).' to claim', $item['title']);
        $this->assertSame((int) round($miles * 55), $item['amount'], '55p a mile in 2026-27');
        $this->assertStringContainsString('Reading conference', $item['detail']);
        $this->assertStringNotContainsString('Leeds', $item['detail'], 'Reached by train: no mileage');
        $this->assertSame('/mileage?year=2026-27', $item['href']);
        $this->assertFalse($item['snooze_only'], 'Kept by its jobs: "Not relevant" hides these, and a new job brings it back');
        $this->assertSame(2, $list['checked']['jobs'], 'Both finished jobs were looked at');
        Http::assertNothingSent();

        // Miles already recorded this year count towards the 10,000: what's over it is at 25p.
        $van = Vehicle::query()->sole();
        MileageTrip::query()->create(['vehicle_id' => $van->id, 'trip_on' => '2026-05-01', 'tax_year' => '2026-27', 'from_place' => 'Home', 'to_place' => 'Tour', 'miles' => '9990.0', 'purpose' => 'Tour']);
        $item = $this->item('mileage:2026-27:');
        $this->assertSame((int) round(10 * 55 + ($miles - 10) * 25), $item['amount']);

        // Added on the Mileage page: nothing left to claim.
        $this->post('/mileage/from-jobs', ['year' => '2026-27', 'vehicle_id' => $van->id, 'job_ids' => [$drove->id]])->assertSessionHas('success');
        $this->assertNull($this->item('mileage:2026-27:'));
    }

    public function test_a_job_reached_by_hire_car_has_no_mileage_to_claim(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $hired = WorkJob::query()->create(['title' => 'Leeds arena', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'postcode' => 'RG1 1AA']);
        TravelItem::query()->create(['kind' => 'car_hire', 'provider' => 'Acme Car Hire', 'status' => 'booked', 'starts_on' => '2026-10-14', 'source' => 'manual', 'evidence' => [],
            'work_job_id' => $hired->id, 'decision' => 'business', 'decided_at' => now()]);
        Http::fake();

        $this->assertNull($this->item('mileage:2026-27:'), 'The hire and its fuel are claimed instead: no mileage on top');
        $this->assertSame([], JobTrips::proposals('2026-27'), 'Not offered on the Mileage page either');

        WorkJob::query()->create(['title' => 'Reading conference', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA']);
        $item = $this->item('mileage:2026-27:');
        $this->assertStringStartsWith('1 finished job with no mileage', $item['title']);
        $this->assertStringContainsString('Reading conference', $item['detail']);
        $this->assertStringNotContainsString('Leeds', $item['detail']);
        $this->assertStringContainsString('1 job you went to by hire car is left out', $item['detail']);

        // A venue not looked up yet is counted only if you may have driven there: not one you reached by hire car.
        $this->post('/mileage/from-jobs', ['year' => '2026-27', 'vehicle_id' => Vehicle::query()->value('id'), 'job_ids' => [WorkJob::query()->where('title', 'Reading conference')->value('id')]]);
        $hired->update(['postcode' => 'M1 1AE']);
        $this->assertNull($this->item('mileage:2026-27:'));
        Http::assertNothingSent();
    }

    public function test_with_a_threshold_for_each_vehicle_only_that_vehicles_miles_count(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781);
        $old = Vehicle::query()->create(['name' => 'Old van', 'type' => 'car_van', 'method' => 'simplified']);
        Vehicle::query()->create(['name' => 'New van', 'type' => 'car_van', 'method' => 'simplified']); // first by name: the Mileage page offers it
        MileageTrip::query()->create(['vehicle_id' => $old->id, 'trip_on' => '2026-05-01', 'tax_year' => '2026-27', 'from_place' => 'Home', 'to_place' => 'Tour', 'miles' => '12000.0', 'purpose' => 'Tour']);
        WorkJob::query()->create(['title' => 'Reading conference', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA']);
        $miles = round(Places::miles('SW1A 1AA', 'RG1 1AA') * 1.25, 1) * 2;

        // All your cars and vans share the 10,000 miles: the old van's 12,000 used them up.
        $this->assertSame((int) round($miles * 25), $this->item('mileage:2026-27:')['amount']);

        // Each vehicle has its own: the new van, which the trips would go on, has used none.
        Setting::put('mileage_threshold_scope', 'per_vehicle');
        $item = $this->item('mileage:2026-27:');
        $this->assertSame((int) round($miles * 55), $item['amount']);
        $this->assertStringContainsString('Valued as trips on New van', $item['detail']);
    }

    public function test_not_relevant_leaves_those_jobs_out_and_a_new_job_brings_it_back(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        WorkJob::query()->create(['title' => 'Crew minibus show', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA']);
        WorkJob::query()->create(['title' => 'Lift from a friend', 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-08', 'postcode' => 'RG1 1AA']);
        FindingsRegistry::sync();

        $first = $this->item('mileage:2026-27:');
        $this->assertStringStartsWith('2 finished jobs', $first['title']);
        $this->post('/todo/dismiss', ['key' => $first['key']])->assertSessionHas('success', 'Marked not relevant: those 2 jobs won’t be listed for mileage again.');
        $this->assertNull($this->item('mileage:2026-27:'));
        $event = AuditEvent::query()->where('action', 'todo.dismissed')->sole();
        $this->assertSame(['you', WorkJob::query()->orderBy('id')->pluck('id')->all()], [$event->actor, $event->after['no_mileage_for_jobs']]);

        // Undo puts them back; marked again, they stay out.
        $this->post('/todo/dismiss', ['key' => $first['key'], 'undo' => true])->assertSessionHas('success');
        $this->assertSame($first['key'], $this->item('mileage:2026-27:')['key']);
        $this->post('/todo/dismiss', ['key' => $first['key']]);
        FindingsRegistry::sync();
        $this->assertSame('dismissed', Finding::query()->where('key', $first['key'])->value('resolution'));

        // A job that finishes later is listed on its own.
        $this->at('2026-12-01');
        WorkJob::query()->create(['title' => 'Reading conference', 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-30', 'postcode' => 'RG1 1AA']);
        $item = $this->item('mileage:2026-27:');
        $this->assertNotSame($first['key'], $item['key']);
        $this->assertStringStartsWith('1 finished job with no mileage', $item['title']);
        $this->assertStringContainsString('Reading conference', $item['detail']);
        $this->assertStringNotContainsString('Lift from a friend', $item['detail']);

        // Still open when another job finishes: the same finding, now about both.
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', $item['key'])->sole();
        $this->at('2026-12-03');
        WorkJob::query()->create(['title' => 'Basingstoke gala', 'starts_on' => '2026-12-02', 'ends_on' => '2026-12-02', 'postcode' => 'RG1 1AA']);
        FindingsRegistry::sync();
        $now = $this->item('mileage:2026-27:');
        $this->assertStringStartsWith('2 finished jobs', $now['title']);
        $this->assertSame($now['key'], $finding->fresh()->key, 'Followed to its new key, not called dealt with');
        $this->assertNull($finding->fresh()->resolved_at);
    }

    public function test_the_mileage_is_kept_until_what_it_is_made_from_changes(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $jobs = function (int $n, int $from) {
            for ($i = 0; $i < $n; $i++) {
                $day = CarbonImmutable::parse('2026-06-01')->addDays($from + $i)->format('Y-m-d');
                WorkJob::query()->create(['title' => 'Show '.($from + $i), 'starts_on' => $day, 'ends_on' => $day, 'postcode' => 'RG1 1AA', 'po_number' => 'PO-'.($from + $i)]);
            }
        };
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            TodoList::build();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        Http::fake();

        $jobs(5, 0);
        TodoList::build();
        $few = $count();
        $jobs(60, 5);
        $this->assertStringStartsWith('65 finished jobs', $this->item('mileage:2026-27:')['title'], 'A new job is seen at once');
        $many = $count();
        $this->assertSame($few, $many, "Built again with nothing changed: {$few} queries for 5 jobs, {$many} for 65");

        // Changed elsewhere (the queue, the scheduler), which this process doesn't see written: seen by the data's fingerprint.
        DB::table('mileage_trips')->insert(['vehicle_id' => Vehicle::query()->value('id'), 'work_job_id' => WorkJob::query()->value('id'), 'trip_on' => '2026-06-01', 'tax_year' => '2026-27',
            'from_place' => 'Home', 'to_place' => 'RG1 1AA', 'miles' => '90.0', 'purpose' => 'Show', 'created_at' => now(), 'updated_at' => now()]);
        (new \ReflectionProperty(TodoList::class, 'mileageChanged'))->setValue(null, false);
        $this->assertStringStartsWith('64 finished jobs', $this->item('mileage:2026-27:')['title']);
        Http::assertNothingSent();
    }

    public function test_without_a_home_postcode_it_asks_for_one_and_never_looks_a_postcode_up(): void
    {
        WorkJob::query()->create(['title' => 'Reading conference', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA']);
        Http::fake();

        $setup = $this->item('mileage:setup:');
        $this->assertSame('Add your home postcode to find mileage to claim', $setup['title']);
        $this->assertNull($this->item('mileage:2026-27:'));

        // A postcode not looked up yet: the list points to the Mileage page rather than looking it up itself.
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->assertNull($this->item('mileage:setup:'));
        $item = $this->item('mileage:2026-27:');
        $this->assertSame('1 finished job may have mileage to claim', $item['title']);
        $this->assertArrayNotHasKey('amount', $item);
        Http::assertNothingSent();
    }

    public function test_only_vehicles_on_actual_costs_means_no_mileage_to_claim(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'actual']);
        WorkJob::query()->create(['title' => 'Reading conference', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'RG1 1AA']);

        $this->assertNull($this->item('mileage:2026-27:'));
    }

    // ---- Filed returns your records no longer match ----

    /** 2025-26, filed, with a year of statements in Expend (lines in its first month and its last) and these figures. */
    private function filedYear(int $turnover, int $expenses): TaxYear
    {
        $this->decide($this->tx('2025-04-12', -350, 'COSTA COFFEE'), 'personal');
        $this->decide($this->tx('2026-03-20', -420, 'COSTA COFFEE'), 'personal');
        $in = $this->tx('2025-06-10', $turnover, 'ACME EVENTS LTD');
        $this->decide($in, 'business_income', Category::query()->where('code', 'turnover')->value('id'));
        $out = $this->tx('2025-06-11', -$expenses, 'CABLE SUPPLIES');
        $this->decide($out, 'business_expense', Category::query()->where('kind', 'expense')->where('is_allowable', true)->whereNotNull('parent_id')->value('id'));

        return TaxYear::track('2025-26', 'filed');
    }

    /** @return list<array> the filed-return items */
    private function drift(): array
    {
        return collect(TodoList::build(100)['items'])->filter(fn ($i) => str_starts_with($i['key'], 'drift:'))->values()->all();
    }

    public function test_a_filed_year_whose_figures_changed_by_a_pound_or_more_is_listed_with_the_date_to_amend_by(): void
    {
        $year = $this->filedYear(3_000_000, 500_000);
        // Filed before £640 of costs turned out to be personal: the records now show £640 more profit.
        $year->update(['filed_on' => '2026-01-20', 'filed_turnover_pence' => 3_000_000, 'filed_expenses_pence' => 564_000, 'filed_profit_pence' => 2_436_000]);

        $list = TodoList::build(100);
        $item = collect($list['items'])->first(fn ($i) => str_starts_with($i['key'], 'drift:2025-26:'));
        $this->assertSame('2025-26 profit is £640.00 higher than the return you filed: amend by 31 January 2028', $item['title']);
        $this->assertSame(['tax', 2], [$item['group'], $item['priority']]);
        $this->assertSame(-64_000, $item['amount'], 'You may owe more');
        $this->assertStringContainsString('allowable expenses £5,640.00', $item['detail']);
        $this->assertStringContainsString('allowable expenses £5,000.00', $item['detail']);
        $this->assertSame(1, $list['checked']['years']);
        $this->assertFalse($item['snooze_only'], 'Kept by its difference: "Not relevant" sets this one aside');

        // Within 60 days of the date to amend by, it comes first.
        $this->at('2027-12-15');
        $this->assertSame(1, $this->item('drift:2025-26:')['priority']);

        // Past it: no date to amend by in the title, and what to do instead.
        $this->at('2028-02-10');
        $item = $this->item('drift:2025-26:');
        $this->assertSame('2025-26 profit is £640.00 higher than the return you filed', $item['title']);
        $this->assertStringContainsString('ended on 31 January 2028', $item['detail']);
    }

    public function test_a_difference_under_a_pound_and_a_year_with_no_records_are_left_alone(): void
    {
        $year = $this->filedYear(3_000_000, 500_000);
        $summary = ReturnSummary::for('2025-26');
        $year->update(['filed_turnover_pence' => 3_000_000, 'filed_expenses_pence' => 500_050, 'filed_profit_pence' => $summary['net_profit_pence'] - 50]);
        // The year before you started: its figures were typed in at setup, and Expend holds nothing for it.
        TaxYear::track('2023-24', 'filed')->update(['filed_turnover_pence' => 2_000_000, 'filed_expenses_pence' => 100_000, 'filed_profit_pence' => 1_900_000]);

        $list = TodoList::build(100);
        $this->assertEmpty(collect($list['items'])->filter(fn ($i) => str_starts_with($i['key'], 'drift:')));
        $this->assertSame(1, $list['checked']['years'], 'Only the year with records is compared');
    }

    public function test_the_year_before_you_started_is_not_set_against_its_first_few_days_of_statements(): void
    {
        // Setup tracks the year before the first as filed, with the figures you typed in.
        TaxYear::track('2025-26');
        TaxYear::track('2024-25', 'filed')->update(['filed_turnover_pence' => 3_000_000, 'filed_expenses_pence' => 500_000, 'filed_profit_pence' => 2_500_000]);
        // The first statement runs from 1 April: its first five days fall in 2024-25.
        $this->decide($this->tx('2025-04-03', -350, 'COSTA COFFEE'), 'personal');
        $this->decide($this->tx('2025-04-04', 120_000, 'ACME EVENTS LTD'), 'business_income', Category::query()->where('code', 'turnover')->value('id'));

        $list = TodoList::build(100);
        $this->assertSame([], collect($list['items'])->filter(fn ($i) => str_starts_with($i['key'], 'drift:'))->all(), 'A few days of records are no comparison with a whole year');
        $this->assertSame(0, $list['checked']['years']);
    }

    public function test_rounding_to_whole_pounds_is_not_a_difference(): void
    {
        // £30,000.75 in and £5,000.40 out, filed in whole pounds: income rounded down, expenses up.
        $year = $this->filedYear(3_000_075, 500_040);
        $year->update(['filed_on' => '2026-06-01', 'filed_turnover_pence' => 3_000_000, 'filed_expenses_pence' => 500_100, 'filed_profit_pence' => 2_499_900]);
        $this->assertSame([], $this->drift());
        // Or to the nearest pound.
        $year->update(['filed_turnover_pence' => 3_000_100, 'filed_expenses_pence' => 500_000, 'filed_profit_pence' => 2_500_100]);
        $this->assertSame([], $this->drift());

        // £2 more profit than rounding could give is a difference.
        $year->update(['filed_turnover_pence' => 3_000_000, 'filed_expenses_pence' => 500_100, 'filed_profit_pence' => 2_499_700]);
        $item = $this->drift()[0];
        $this->assertSame('2025-26 profit is £3.35 higher than the return you filed: amend by 31 January 2028', $item['title']);

        // "Not relevant" sets this difference aside; a new one comes back.
        FindingsRegistry::sync();
        $this->post('/todo/dismiss', ['key' => $item['key']])->assertSessionHas('success', 'Marked not relevant.');
        $this->assertSame([], $this->drift());
        FindingsRegistry::sync();
        $more = $this->tx('2025-08-01', 50_000, 'ACME EVENTS LTD');
        $this->decide($more, 'business_income', Category::query()->where('code', 'turnover')->value('id'));
        $again = $this->drift()[0];
        $this->assertNotSame($item['key'], $again['key']);
        $this->assertStringStartsWith('2025-26 profit is £503.35 higher', $again['title']);
    }

    public function test_expenses_left_blank_are_not_read_as_the_trading_allowance(): void
    {
        // The records match the turnover and profit filed; the expenses were left blank on the year page.
        $year = $this->filedYear(4_000_000, 800_000);
        $year->update(['filed_turnover_pence' => 4_000_000, 'filed_expenses_pence' => null, 'filed_profit_pence' => 3_200_000]);
        $this->assertSame([], $this->drift());

        // A real difference is still found, on the usual basis.
        $year->update(['filed_profit_pence' => 3_100_000]);
        $item = $this->drift()[0];
        $this->assertStringStartsWith('2025-26 profit is £1,000.00 higher', $item['title']);
        $this->assertStringNotContainsString('trading allowance', $item['detail']);

    }

    public function test_expenses_left_blank_on_a_return_that_took_the_trading_allowance(): void
    {
        // Profit is turnover less the £1,000 allowance: that's what was filed, whatever the £100 of costs.
        $year = $this->filedYear(300_000, 10_000);
        $year->update(['filed_turnover_pence' => 300_000, 'filed_expenses_pence' => null, 'filed_profit_pence' => 200_000]);
        $this->assertSame([], $this->drift());

        $this->decide($this->tx('2025-07-01', 50_000, 'ACME EVENTS LTD'), 'business_income', Category::query()->where('code', 'turnover')->value('id'));
        $item = $this->drift()[0];
        $this->assertStringStartsWith('2025-26 profit is £500.00 higher', $item['title']);
        $this->assertStringContainsString('trading allowance', $item['detail']);
    }

    public function test_with_payments_still_to_decide_it_says_to_decide_them_first(): void
    {
        $year = $this->filedYear(3_000_000, 500_000);
        $year->update(['filed_on' => '2026-01-20', 'filed_turnover_pence' => 3_000_000, 'filed_expenses_pence' => 500_000, 'filed_profit_pence' => 2_500_000]);
        $this->tx('2025-09-01', 64_000, 'ACME EVENTS LTD'); // imported since, not decided
        $this->decide($this->tx('2025-09-02', 64_000, 'ACME EVENTS LTD'), 'business_income', Category::query()->where('code', 'turnover')->value('id'));

        $this->at('2027-12-15');
        $item = $this->drift()[0];
        $this->assertSame('2025-26 figures differ from the return you filed, with 1 payment still to decide', $item['title']);
        $this->assertSame(2, $item['priority'], 'Not first while the year isn’t finished');
        $this->assertArrayNotHasKey('amount', $item);
        $this->assertStringContainsString('Decide it first', $item['detail']);
    }

    public function test_a_return_that_took_the_trading_allowance_is_compared_the_same_way(): void
    {
        $year = $this->filedYear(300_000, 10_000);
        $year->update(['filed_turnover_pence' => 300_000, 'filed_expenses_pence' => 0, 'filed_profit_pence' => 200_000]);
        $this->assertSame([], $this->drift(), 'Turnover less the £1,000 allowance is what was filed');

        $more = $this->tx('2025-07-01', 50_000, 'ACME EVENTS LTD');
        $this->decide($more, 'business_income', Category::query()->where('code', 'turnover')->value('id'));
        $item = $this->drift()[0];
        $this->assertStringStartsWith('2025-26 profit is £500.00 higher', $item['title']);
        $this->assertStringContainsString('trading allowance', $item['detail']);
    }

    // ---- The list as a whole ----

    public function test_the_page_shows_what_was_checked_and_the_list_stays_quick(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $this->place('SW1A 1AA', 51.501, -0.1416);
        $this->place('RG1 1AA', 51.4545, -0.9781);
        for ($i = 0; $i < 8; $i++) {
            WorkJob::query()->create(['title' => 'Show '.$i, 'starts_on' => '2026-10-0'.($i + 1), 'ends_on' => '2026-10-0'.($i + 1), 'postcode' => 'RG1 1AA']);
            app(DecisionService::class)->apply(['kind' => 'personal'], collect([$this->tx('2026-0'.($i + 1).'-28', -10_000, 'HMRC SELF ASSESSMENT')]));
        }
        $this->filedYear(3_000_000, 500_000)->update(['filed_profit_pence' => 1]);
        Http::fake();

        $this->get('/todo')->assertOk()->assertInertia(fn ($p) => $p
            ->where('checked', ['hmrc' => 8, 'jobs' => 8, 'years' => 1])
            ->where('groups.tax', 'Tax records to check')->where('groups.claims', 'Expenses you could claim'));

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            TodoList::build();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $before = $count();
        // A set bound for the whole list (with the checks of JobTrips, which looks at each job's travel and postcodes).
        $this->assertLessThan(175, $before, "The list took {$before} queries");
        // However many payments to HMRC there are, they're found in the same few queries.
        for ($i = 0; $i < 8; $i++) {
            app(DecisionService::class)->apply(['kind' => 'personal'], collect([$this->tx('2026-0'.($i + 1).'-27', -10_000, 'HMRC SELF ASSESSMENT')]));
        }
        $this->assertSame($before, $count());
        $this->assertSame(16, TodoList::build()['checked']['hmrc']);
        Http::assertNothingSent();
    }
}
