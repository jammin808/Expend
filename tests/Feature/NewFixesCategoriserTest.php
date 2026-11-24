<?php

namespace Tests\Feature;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\Suggester;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Review fixes for the Categoriser: what it suggests, what it learns from, and what it may change. */
class NewFixesCategoriserTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        $this->bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
    }

    private function tx(string $merchant, int $pence = -2500, string $date = '2025-06-10', ?string $bankCategory = null, ?string $counterparty = 'same', ?string $type = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $cp = $counterparty === 'same' ? $merchant : $counterparty;

        return BankTransaction::query()->create(['account_id' => $this->bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $merchant, 'counterparty' => $cp, 'merchant_key' => MerchantKey::from($merchant, $cp), 'amount_pence' => $pence, 'bank_category' => $bankCategory,
            'payment_type' => $type, 'raw' => [], 'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1]);
    }

    private function cat(string $code): int
    {
        return Category::query()->where('code', $code)->value('id');
    }

    private function you(BankTransaction $tx, string $kind, ?string $code = null, int $pct = 100, ?int $job = null): void
    {
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, $kind, $code ? $this->cat($code) : null, $job, $pct));
    }

    private function s(BankTransaction $tx): ?array
    {
        $lines = $tx->fresh()->allocations()->orderBy('id')->get();
        $a = $lines->first();

        return $a ? ['kind' => $a->kind, 'category' => $a->category_id ? Category::query()->find($a->category_id)->code : null, 'by' => $a->decided_by, 'job' => $a->work_job_id,
            'reason' => $a->reason, 'invoice' => $a->invoice_id, 'pct' => $a->business_use_pct, 'amounts' => $lines->pluck('amount_pence')->all(), 'kinds' => $lines->pluck('kind')->all(),
            'matcher' => $a->matcher, 'confirmed' => $a->confirmed_at !== null] : null;
    }

    /** As a new statement does: the whole chain. */
    private function suggest(BankTransaction ...$txs): void
    {
        app(Suggester::class)->suggestFor(collect($txs));
    }

    private function place(string $postcode, float $lat, float $lng): void
    {
        DB::table('places')->insert(['postcode' => $postcode, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function leedsHome(): void
    {
        Setting::put('home_postcode', 'LS1 4AP');
        $this->place('LS1 4AP', 53.7965, -1.5478);
    }

    // N3: learning and the nightly pass go through the whole Suggester chain.

    public function test_learning_after_a_decision_applies_the_rule_you_just_made(): void
    {
        $this->actingAs(User::factory()->create());
        $this->you($this->tx('GRIP CO', -2000, '2025-05-01'), 'personal');
        $this->you($this->tx('GRIP CO', -2000, '2025-05-08'), 'personal');
        $waiting = $this->tx('GRIP CO', -2000, '2025-06-01');
        app(Categoriser::class)->suggestFor(collect([$waiting]));
        $this->assertSame('personal', $this->s($waiting)['kind']);
        $now = $this->tx('GRIP CO', -2000, '2025-06-08');

        $this->post("/items/bank/{$now->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'business_pct' => 100, 'make_rule' => true])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Updated the suggestion for 1 other waiting payment from there') && ! str_contains($m, 'the same'));

        $s = $this->s($waiting);
        $this->assertSame(['business_expense', 'materials', 'rule'], [$s['kind'], $s['category'], $s['by']], 'Your new rule, not the older personal decisions');
        $this->assertSame('Your rule for GRIP', $s['reason']);
    }

    public function test_the_nightly_pass_uses_your_rules_and_open_invoices(): void
    {
        $screwfix = $this->tx('SCREWFIX', -3000, '2025-05-01');
        MerchantRule::query()->create(['merchant_key' => 'SCREWFIX', 'kind' => 'personal', 'business_pct' => 100]);
        $fee = $this->tx('ACME EVENTS LTD', 120000, '2025-06-01', 'INCOME');
        foreach (['BIG STAGE CO', 'NORTH LIGHTS', 'ZED AUDIO'] as $i => $m) {
            $this->you($this->tx($m, 50000, '2025-05-0'.($i + 1), 'INCOME'), 'business_income', 'turnover');
        }
        $invoice = Invoice::query()->create(['number' => 'INV-1', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 120000, 'issue_date' => '2025-05-20', 'bill_to_name' => 'Acme']);

        Artisan::call('ledger:categorise');

        $this->assertSame(['personal', 'rule', 'Your rule for SCREWFIX'], [$this->s($screwfix)['kind'], $this->s($screwfix)['by'], $this->s($screwfix)['reason']]);
        $this->assertSame($invoice->id, $this->s($fee)['invoice'], 'Matched to the invoice, so it counts as paid once you confirm it');
        $this->assertStringStartsWith('Matches invoice INV-1', $this->s($fee)['reason']);
    }

    // N4: learned meals, hotels and fuel are judged against the day.

    public function test_a_lunch_decided_as_business_while_away_does_not_make_lunches_at_home_business(): void
    {
        $this->actingAs(User::factory()->create());
        $this->leedsHome();
        $this->place('G1 1AA', 55.8609, -4.2514);
        WorkJob::query()->create(['title' => 'Glasgow arena', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'G1 1AA', 'status' => 'done']);
        $home = $this->tx('PRET A MANGER', -650, '2025-06-10');
        app(Categoriser::class)->suggestFor(collect([$home]));
        $this->assertSame('personal', $this->s($home)['kind']);

        $away = $this->tx('PRET A MANGER', -650, '2025-07-02');
        $this->post("/items/bank/{$away->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence'), 'business_pct' => 100]);

        $this->assertSame('personal', $this->s($home)['kind'], 'Still a lunch with no job that day');
        $this->assertStringContainsString('no job that day', $this->s($home)['reason']);
        $later = $this->tx('PRET A MANGER', -650, '2025-08-20');
        $this->suggest($later);
        $this->assertSame('personal', $this->s($later)['kind']);
        $awayAgain = $this->tx('PRET A MANGER', -700, '2025-07-03');
        $this->suggest($awayAgain);
        $this->assertSame(['business_expense', 'travel_subsistence'], [$this->s($awayAgain)['kind'], $this->s($awayAgain)['category']]);
        $this->assertStringStartsWith('Like the 1 time you decided PRET A MANGER', $this->s($awayAgain)['reason']);
    }

    public function test_lunches_decided_personal_at_home_do_not_make_a_meal_away_overnight_personal(): void
    {
        $this->leedsHome();
        $this->place('G1 1AA', 55.8609, -4.2514);
        $this->you($this->tx('GREGGS', -450, '2025-05-01'), 'personal');
        $this->you($this->tx('GREGGS', -450, '2025-05-08'), 'personal');
        $job = WorkJob::query()->create(['title' => 'Glasgow arena', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'G1 1AA', 'status' => 'done']);
        $away = $this->tx('GREGGS', -450, '2025-07-02');
        $this->suggest($away);

        $this->assertSame(['business_expense', 'travel_subsistence', $job->id], [$this->s($away)['kind'], $this->s($away)['category'], $this->s($away)['job']]);
        $this->assertStringStartsWith('Unlike the 2 times you decided GREGGS', $this->s($away)['reason']);
    }

    public function test_fuel_learned_from_a_hire_car_is_not_suggested_with_simplified_mileage(): void
    {
        DB::table('vehicles')->insert(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified', 'created_at' => now(), 'updated_at' => now()]);
        $this->you($this->tx('SHELL KINGS CROSS', -6000, '2025-05-01'), 'business_expense', 'travel_fuel');
        $t = $this->tx('SHELL KINGS CROSS', -5000, '2025-06-01');
        $this->suggest($t);

        $this->assertSame('personal', $this->s($t)['kind'], 'Simplified mileage already covers fuel: claiming it too would count it twice');
        $this->assertStringContainsString('simplified mileage', $this->s($t)['reason']);
    }

    // N5: money in is turnover only from an invoice or the same payer.

    public function test_money_in_is_not_turnover_just_because_the_bank_calls_it_income(): void
    {
        foreach (['ACME EVENTS LTD', 'BIG STAGE CO', 'NORTH LIGHTS'] as $i => $m) {
            $this->you($this->tx($m, 120000, '2025-05-0'.($i + 1), 'INCOME'), 'business_income', 'turnover');
        }
        $refund = $this->tx('HMRC', 30000, '2025-06-01', 'INCOME');
        $friend = $this->tx('J BLOGGS', 2000, '2025-06-02', 'INCOME');
        $again = $this->tx('ACME EVENTS LTD', 90000, '2025-06-03', 'INCOME');
        $this->suggest($refund, $friend, $again);

        $this->assertNull($this->s($refund));
        $this->assertNull($this->s($friend));
        $this->assertSame('turnover', $this->s($again)['category'], 'The same client paying again is still learned');
    }

    public function test_a_tax_refund_is_never_suggested_as_turnover_even_from_the_same_payer(): void
    {
        $this->you($this->tx('HMRC', 25000, '2025-05-01'), 'business_income', 'turnover');
        $refund = $this->tx('HMRC', 30000, '2025-06-01');
        $this->assertNull(app(Categoriser::class)->propose($refund));
    }

    // N19 (and N27): other matchers' suggestions are never claimed or overwritten.

    public function test_learning_leaves_a_penalty_and_a_car_hire_suggestion_alone(): void
    {
        $this->actingAs(User::factory()->create());
        WorkJob::query()->create(['title' => 'Leeds show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-12', 'status' => 'done']);
        $pcn = $this->tx('WESTMINSTER CC PCN', -6500, '2025-06-01', null, 'WESTMINSTER CC');
        $hire = $this->tx('HERTZ UK', -25000, '2025-06-10');
        $this->suggest($pcn, $hire);
        $before = [$this->s($pcn), $this->s($hire)];
        $this->assertSame('travel_penalties', $before[0]['category']);
        $this->assertSame('travel_car_hire', $before[1]['category']);
        $this->assertNull($before[0]['matcher'], 'Not the Categoriser’s');

        $parking = $this->tx('WESTMINSTER CC PARKING', -400, '2025-06-02', null, 'WESTMINSTER CC');
        $this->post("/items/bank/{$parking->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('travel_parking'), 'business_pct' => 100]);
        $holiday = $this->tx('HERTZ UK', -30000, '2025-08-01');
        $this->post("/items/bank/{$holiday->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);

        $this->assertSame($before[0], $this->s($pcn), 'A fine stays a fine (never allowable)');
        $this->assertSame($before[1], $this->s($hire));
    }

    public function test_the_categorisers_old_guesses_are_suggested_again_through_the_whole_chain(): void
    {
        $fee = $this->tx('ACME EVENTS LTD', 120000, '2025-06-01', 'INCOME');
        $lunch = $this->tx('PRET A MANGER', -650);
        $hmrc = $this->tx('HMRC SELF ASSESSMENT', -50000);
        $writer = app(AllocationWriter::class);
        // As the nightly pass used to: the bank's category, ignoring the open invoice.
        $writer->decide($fee, AllocationWriter::lines(120000, 'business_income', $this->cat('turnover')), 'matcher', false, 'You usually decide the bank’s “Income” payments this way (3 of 3)');
        $writer->decide($lunch, AllocationWriter::lines(-650, 'personal'), 'matcher', false, 'Looks like food or drink, with no job that day: personal');
        $writer->decide($hmrc, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');
        $invoice = Invoice::query()->create(['number' => 'INV-7', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 120000, 'issue_date' => '2025-05-20', 'bill_to_name' => 'Acme']);
        $hmrcBefore = $this->s($hmrc);

        $migration = require base_path('database/migrations/2026_12_01_000420_categoriser_suggestion_marker.php');
        $migration->down();
        $migration->up();

        $this->assertNull($this->s($fee));
        $this->assertSame('unreviewed', $fee->fresh()->review_status);
        $this->assertSame('system', AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $fee->id)->value('actor'));
        $this->assertNull($this->s($lunch));
        $this->assertSame($hmrcBefore, $this->s($hmrc), 'Another matcher’s suggestion is left alone');

        Artisan::call('ledger:categorise'); // as the deploy does straight after migrating
        $this->assertSame($invoice->id, $this->s($fee)['invoice']);
        $this->assertSame(['personal', Categoriser::MARKER], [$this->s($lunch)['kind'], $this->s($lunch)['matcher']]);
    }

    // N25 and N70: similar merchants are alike only when they really are.

    public function test_a_shared_first_word_does_not_make_unrelated_merchants_alike(): void
    {
        foreach (['2025-04-01', '2025-04-15', '2025-05-01'] as $d) {
            $this->you($this->tx('VIRGIN MEDIA', -4000, $d), 'business_expense', 'phone_internet', 50);
            $this->you($this->tx('BRITISH AIRWAYS', -14000, $d), 'business_expense', 'travel_flights');
        }
        $this->you($this->tx('TESCO PFS 4567', -5000, '2025-04-01'), 'business_expense', 'travel_fuel');
        $this->you($this->tx('TESCO PFS 1234', -5000, '2025-05-01'), 'business_expense', 'travel_fuel');
        $this->you($this->tx('TESCO PFS 9876', -5000, '2025-05-02'), 'business_expense', 'travel_fuel');
        $gym = $this->tx('VIRGIN ACTIVE', -6000, '2025-06-01');
        $gas = $this->tx('BRITISH GAS', -9000, '2025-06-01');
        $groceries = $this->tx('TESCO STORES 3021', -8000, '2025-06-01');
        $this->suggest($gym, $gas, $groceries);

        $this->assertSame('personal', $this->s($gym)['kind']);
        $this->assertStringContainsString('a gym', $this->s($gym)['reason']);
        $this->assertNull($this->s($gas));
        $this->assertSame('personal', $this->s($groceries)['kind']);
        $this->assertStringContainsString('supermarket', $this->s($groceries)['reason']);
    }

    public function test_payments_to_different_people_do_not_share_decisions(): void
    {
        foreach (['2025-04-01', '2025-04-15', '2025-05-01'] as $d) {
            $this->you($this->tx('PAUL SMITH', -2000, $d), 'personal');
        }
        $this->you($this->tx('PAUL SMITH LTD', -2000, '2025-05-02'), 'personal');
        $crew = $this->tx('PAUL JONES', -15000, '2025-06-01');
        $this->assertNull(Categoriser::token('PAUL JONES'));
        $this->assertNull(app(Categoriser::class)->propose($crew));
    }

    // N26: the common-merchant list doesn't catch unrelated merchants.

    public function test_the_merchant_list_skips_venues_games_energy_and_people(): void
    {
        WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'status' => 'done']);
        $venue = $this->tx('O2 ACADEMY BRIXTON', -900, '2025-06-01');
        $arena = $this->tx('THE O2', -1200, '2025-06-01');
        $xbox = $this->tx('MICROSOFT*XBOX GAME PASS', -1099, '2025-06-01', null, 'Microsoft');
        $energy = $this->tx('SHELL ENERGY', -8500, '2025-06-10');
        $crew = $this->tx('LEON JONES', -15000, '2025-06-10');
        $mobile = $this->tx('TESCO MOBILE', -1500, '2025-06-03');
        $services = $this->tx('MOTO TODDINGTON', -1200, '2025-06-10');
        $o2 = $this->tx('O2', -3000, '2025-06-03');
        $this->suggest($venue, $arena, $xbox, $energy, $crew, $mobile, $services, $o2);

        $this->assertNull($this->s($venue), 'A venue, not the phone network');
        $this->assertNull($this->s($arena));
        $this->assertSame('personal', $this->s($xbox)['kind']);
        $this->assertNull($this->s($energy), 'A household energy bill, not fuel');
        $this->assertNull($this->s($crew), 'Paying crew, not a restaurant');
        $this->assertStringContainsString('phone or internet', $this->s($mobile)['reason']);
        $this->assertStringStartsWith('Check: Looks like a motorway service station', $this->s($services)['reason']);
        $this->assertStringContainsString('phone or internet', $this->s($o2)['reason']);
    }

    // N28: hotels paid at checkout or in advance.

    public function test_a_hotel_paid_at_checkout_or_ahead_is_for_you_to_check_not_personal(): void
    {
        $this->leedsHome();
        $this->place('G1 1AA', 55.8609, -4.2514);
        $job = WorkJob::query()->create(['title' => 'Glasgow arena', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'G1 1AA', 'status' => 'done']);
        $checkout = $this->tx('PREMIER INN GLASGOW', -28500, '2025-07-04');
        $advance = $this->tx('PREMIER INN GLASGOW', -28500, '2025-06-01');
        $holiday = $this->tx('PREMIER INN BATH', -9000, '2025-02-01');
        $flight = $this->tx('SKYWAYS 1234', -12000, '2025-05-20', 'TRAVEL-AIRLINE');
        $this->suggest($checkout, $advance, $holiday, $flight);

        foreach ([$checkout, $advance] as $t) {
            $this->assertSame(['business_expense', 'travel_accommodation', $job->id], [$this->s($t)['kind'], $this->s($t)['category'], $this->s($t)['job']]);
            $this->assertStringStartsWith('Check: Looks like a hotel', $this->s($t)['reason']);
        }
        $this->assertStringContainsString('checkout', $this->s($checkout)['reason']);
        $this->assertStringContainsString('ahead of Glasgow arena', $this->s($advance)['reason']);
        $this->assertSame('personal', $this->s($holiday)['kind'], 'No job anywhere near');
        // A flight doesn't name the town: paying ahead is only a note, personal until you say it was for the job.
        $this->assertSame(['personal', null], [$this->s($flight)['kind'], $this->s($flight)['job']]);
        $this->assertStringStartsWith('Check: The bank calls it', $this->s($flight)['reason']);
        $this->assertStringContainsString('booked ahead for Glasgow arena', $this->s($flight)['reason']);
    }

    // N29: away overnight means a stay or a long way from home.

    public function test_meals_on_a_job_near_home_are_personal_and_with_a_hotel_stay_business(): void
    {
        $this->leedsHome();
        $this->place('LS2 7EY', 53.8008, -1.5491);
        $this->place('S1 2BP', 53.3811, -1.4701);
        WorkJob::query()->create(['title' => 'Leeds conference', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'LS2 7EY', 'status' => 'done']);
        $day2 = $this->tx('GREGGS', -450, '2025-07-02');
        $gig = WorkJob::query()->create(['title' => 'Sheffield gig', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'postcode' => 'S1 2BP', 'status' => 'done', 'start_time' => '09:00', 'end_time' => '23:30']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-07-31', 'ends_on' => '2025-08-01', 'source' => 'manual', 'work_job_id' => $gig->id, 'decision' => 'business']);
        $dinner = $this->tx('NANDOS', -1800, '2025-07-31');
        $this->suggest($day2, $dinner);

        $this->assertSame('personal', $this->s($day2)['kind'], 'You slept at home');
        $this->assertStringContainsString('but not while away overnight', $this->s($day2)['reason']);
        $this->assertSame(['business_expense', 'travel_subsistence', $gig->id], [$this->s($dinner)['kind'], $this->s($dinner)['category'], $this->s($dinner)['job']]);
        $this->assertStringContainsString('the day before Sheffield gig', $this->s($dinner)['reason'], 'The day’s own wording, not "a working day"');
    }

    // N30: overheads aren't a job's costs.

    public function test_overheads_paid_on_a_job_day_are_not_attached_to_the_job(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'status' => 'done']);
        $adobe = $this->tx('ADOBE CREATIVE CLD', -5698, '2025-06-10');
        $hiscox = $this->tx('HISCOX', -24000, '2025-06-10');
        $kit = $this->tx('SCREWFIX', -3000, '2025-06-10');
        $this->suggest($adobe, $hiscox, $kit);

        $this->assertNull($this->s($adobe)['job']);
        $this->assertNull($this->s($hiscox)['job']);
        $this->assertSame($job->id, $this->s($kit)['job'], 'Kit bought on the day is the job’s');

        WorkJob::query()->create(['title' => 'Other show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11', 'status' => 'done']);
        $both = $this->tx('SCREWFIX', -1000, '2025-06-10');
        app(Categoriser::class)->suggestFor(collect([$both]));
        $this->assertNull($this->s($both)['job'], 'Two jobs that day: you choose');
        $this->assertStringContainsString('2 jobs that day', $this->s($both)['reason']);
    }

    public function test_a_learned_cost_gets_a_job_only_if_yours_had_one(): void
    {
        WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'status' => 'done']);
        $this->you($this->tx('LX STAGE HIRE', -12000, '2025-05-01'), 'business_expense', 'equipment');
        $t = $this->tx('LX STAGE HIRE', -9000, '2025-06-10');
        $this->suggest($t);
        $this->assertNull($this->s($t)['job']);
    }

    // N31: transfers aren't learned per merchant.

    public function test_a_transfer_decision_is_not_learned_for_the_merchant(): void
    {
        $this->you($this->tx('PAYPAL', -10000, '2025-05-01'), 'transfer');
        $t = $this->tx('PAYPAL', -3999, '2025-06-01');
        $this->assertNull(app(Categoriser::class)->propose($t));
    }

    // N38: splits keep their share.

    public function test_a_split_typed_by_amounts_is_learned_with_its_share(): void
    {
        $this->actingAs(User::factory()->create());
        $waiting = $this->tx('GRIPCO MOBILE', -5000, '2025-06-01');
        $bill = $this->tx('GRIPCO MOBILE', -5000, '2025-05-01');
        $this->post("/items/bank/{$bill->id}/decide", ['lines' => [
            ['kind' => 'business_expense', 'category_id' => $this->cat('phone_internet'), 'amount_pence' => -3000],
            ['kind' => 'personal', 'amount_pence' => -2000],
        ]]);

        $s = $this->s($waiting);
        $this->assertSame(['business_expense', 'personal'], $s['kinds']);
        $this->assertSame([-3000, -2000], $s['amounts']);
        $this->assertSame(60, $s['pct']);
    }

    // N57: accepting a suggestion is a choice too.

    public function test_accepting_suggestions_teaches_like_deciding_them(): void
    {
        $this->actingAs(User::factory()->create());
        $writer = app(AllocationWriter::class);
        foreach (['2025-04-01', '2025-04-15', '2025-05-01'] as $d) {
            $t = $this->tx('JB SOUND HIRE', -9000, $d);
            $writer->decide($t, AllocationWriter::lines(-9000, 'business_expense', $this->cat('equipment')), 'ai', false, 'An equipment hire');
            $this->post("/items/bank/{$t->id}/confirm");
        }
        $next = $this->tx('JB SOUND HIRE', -9000, '2025-06-01');
        $this->assertStringStartsWith('Like the 3 times you decided JB SOUND HIRE', app(Categoriser::class)->propose($next)['reason']);

        // A rule's confirmation isn't your choice.
        $rule = MerchantRule::query()->create(['merchant_key' => 'RULED CO', 'kind' => 'personal', 'business_pct' => 100]);
        $ruled = $this->tx('RULED CO', -1000, '2025-04-01');
        $writer->decide($ruled, AllocationWriter::lines(-1000, 'personal'), 'rule', true, 'Your rule for RULED CO', $rule->id);
        $rule->delete();
        $this->assertNull(app(Categoriser::class)->propose($this->tx('RULED CO', -1000, '2025-06-01')));
    }

    public function test_accepting_one_updates_the_others_waiting(): void
    {
        $this->actingAs(User::factory()->create());
        $writer = app(AllocationWriter::class);
        $earlier = $this->tx('JB SOUND HIRE', -7000, '2025-04-01');
        $writer->decide($earlier, AllocationWriter::lines(-7000, 'business_expense', $this->cat('equipment')), 'ai', false, 'An equipment hire');
        $accepted = $this->tx('JB SOUND HIRE', -9000, '2025-05-01');
        $writer->decide($accepted, AllocationWriter::lines(-9000, 'business_expense', $this->cat('equipment')), 'ai', false, 'An equipment hire');
        $waiting = $this->tx('JB SOUND HIRE', -4000, '2025-06-01');

        // An accepted suggestion counts half a decision of yours: the first acceptance alone teaches nothing.
        $this->post("/items/bank/{$earlier->id}/confirm")->assertSessionHas('success', fn ($m) => ! str_contains($m, 'Updated the suggestion'));
        $this->assertSame(0, $waiting->fresh()->allocations()->count());
        $this->post("/items/bank/{$accepted->id}/confirm")->assertSessionHas('success', fn ($m) => str_contains($m, 'Updated the suggestion for 1 other waiting payment'));
        $this->assertSame(['business_expense', 'equipment', Categoriser::MARKER], [$this->s($waiting)['kind'], $this->s($waiting)['category'], $this->s($waiting)['matcher']]);
    }

    // N71 (and N79): a suggestion that no longer holds is taken away, not left with a reason that isn't true.

    public function test_a_guess_your_decisions_no_longer_support_goes_back_to_undecided(): void
    {
        $this->actingAs(User::factory()->create());
        $waiting = [$this->tx('GRIP AND GAFFER', -1500, '2025-06-02'), $this->tx('GRIP AND GAFFER', -2000, '2025-06-09')];
        $first = $this->tx('GRIP AND GAFFER', -3000, '2025-06-01');
        $this->post("/items/bank/{$first->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'business_pct' => 100]);
        $this->assertSame('materials', $this->s($waiting[1])['category']);

        $this->post("/items/bank/{$waiting[0]->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);

        $this->assertNull($this->s($waiting[1]), 'One each way: no guess');
        $this->assertSame('unreviewed', $waiting[1]->fresh()->review_status);
        $this->assertSame('allocation.cleared', AuditEvent::query()->where('subject_type', 'BankTransaction')->where('subject_id', $waiting[1]->id)->latest('id')->value('action'));
    }

    // N77: phone and internet aren't the whole bill by default.

    public function test_phone_and_internet_are_not_suggested_as_wholly_business(): void
    {
        $vodafone = $this->tx('VODAFONE LTD', -3000, '2025-06-03');
        $this->suggest($vodafone);
        $this->assertNotSame('business_expense', $this->s($vodafone)['kind']);
        $this->assertStringStartsWith('Check: Looks like phone or internet', $this->s($vodafone)['reason']);

        $this->you($this->tx('PLUSNET', -3000, '2025-05-01'), 'business_expense', 'phone_internet', 40);
        $ee = $this->tx('EE LIMITED', -2500, '2025-06-04');
        app(Categoriser::class)->suggestFor(collect([$ee]));
        $this->assertSame([40, [-1000, -1500]], [$this->s($ee)['pct'], $this->s($ee)['amounts']], 'Your own split of the last bill');
        $this->assertStringStartsWith('Check:', $this->s($ee)['reason']);
    }

    // N78: unreadable merchants don't learn from each other.

    public function test_unreadable_merchants_do_not_learn_from_each_other(): void
    {
        $this->you($this->tx('12345678', -2000, '2025-05-01', null, null), 'business_expense', 'materials');
        $t = $this->tx('99887766', -9000, '2025-06-01', null, null);
        $this->assertSame('UNKNOWN', $t->merchant_key);
        $this->assertNull(app(Categoriser::class)->propose($t));
    }

    // N79: an unchanged suggestion isn't rewritten on every decision.

    public function test_unchanged_suggestions_are_not_rewritten_on_each_decision(): void
    {
        $this->actingAs(User::factory()->create());
        $txs = [];
        for ($i = 1; $i <= 12; $i++) {
            $txs[] = $this->tx('COSTA COFFEE', -300, sprintf('2025-06-%02d', $i));
        }
        $this->post("/items/bank/{$txs[0]->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);
        $this->assertSame('personal', $this->s($txs[5])['kind']);
        $ids = Allocation::query()->where('bank_transaction_id', $txs[5]->id)->pluck('id')->all();
        $audit = AuditEvent::query()->count();

        $this->post("/items/bank/{$txs[1]->id}/decide", ['kind' => 'personal', 'business_pct' => 100])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'Updated'));

        $this->assertSame($ids, Allocation::query()->where('bank_transaction_id', $txs[5]->id)->pluck('id')->all(), 'Not deleted and written again');
        $this->assertSame($audit + 1, AuditEvent::query()->count(), 'Only your own decision is recorded');
        $this->assertStringStartsWith('Like the 2 times you decided COSTA COFFEE', $this->s($txs[5])['reason'], 'The wording keeps up');
    }

    // Recheck P1: guesses the import made (the Suggester, not through Categoriser::suggestFor) follow your decisions.

    /** A guess without the marker: as the Suggester has written them, and as guesses from before the marker are. */
    private function unmark(BankTransaction $tx): void
    {
        Allocation::query()->where('bank_transaction_id', $tx->id)->update(['matcher' => null]);
    }

    public function test_an_import_guess_follows_your_next_decision(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->tx('SCREWFIX', -3000, '2025-06-01');
        $b = $this->tx('SCREWFIX', -2000, '2025-06-02');
        app(Suggester::class)->suggestFor(collect([$a, $b]), match: false); // as an import does
        $this->assertSame('business_expense', $this->s($b)['kind']);

        $this->post("/items/bank/{$a->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);

        $this->assertSame(['personal', Categoriser::MARKER], [$this->s($b)['kind'], $this->s($b)['matcher']]);
        $this->assertStringStartsWith('Like the 1 time you decided SCREWFIX', $this->s($b)['reason']);
    }

    public function test_an_import_guess_your_decisions_no_longer_support_follows_them(): void
    {
        $this->actingAs(User::factory()->create());
        $this->you($this->tx('GRIP AND GAFFER', -3000, '2025-05-01'), 'business_expense', 'materials');
        $waiting = $this->tx('GRIP AND GAFFER', -2000, '2025-06-20');
        app(Suggester::class)->suggestFor(collect([$waiting]), match: false);
        $this->assertSame('materials', $this->s($waiting)['category']);
        $this->unmark($waiting);

        foreach (['2025-06-01', '2025-06-08'] as $d) {
            $t = $this->tx('GRIP AND GAFFER', -1500, $d);
            $this->post("/items/bank/{$t->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);
        }

        $this->assertSame('personal', $this->s($waiting)['kind']);
        $this->assertStringStartsWith('Like 2 of the last 3 times you decided GRIP AND GAFFER', $this->s($waiting)['reason']);
    }

    public function test_an_import_guess_follows_the_rule_you_just_made(): void
    {
        $this->actingAs(User::factory()->create());
        $this->you($this->tx('GRIP CO', -2000, '2025-05-01'), 'personal');
        $this->you($this->tx('GRIP CO', -2000, '2025-05-08'), 'personal');
        $waiting = $this->tx('GRIP CO', -2000, '2025-06-01');
        app(Suggester::class)->suggestFor(collect([$waiting]), match: false);
        $this->assertSame('personal', $this->s($waiting)['kind']);
        $this->unmark($waiting);
        $now = $this->tx('GRIP CO', -2000, '2025-06-08');

        $this->post("/items/bank/{$now->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'business_pct' => 100, 'make_rule' => true])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Updated the suggestion for 1 other waiting payment'));

        $this->assertSame(['business_expense', 'materials', 'rule', 'Your rule for GRIP'], [$this->s($waiting)['kind'], $this->s($waiting)['category'], $this->s($waiting)['by'], $this->s($waiting)['reason']]);
    }

    public function test_another_matchers_suggestion_worded_alike_is_still_left_alone(): void
    {
        $this->actingAs(User::factory()->create());
        $hmrc = $this->tx('HMRC SELF ASSESSMENT', -50000, '2025-06-01', null, 'HMRC');
        $this->suggest($hmrc);
        $before = $this->s($hmrc);
        $this->assertStringStartsWith('Looks like a payment to HMRC', $before['reason']);
        $other = $this->tx('HMRC SELF ASSESSMENT', -40000, '2025-06-02', null, 'HMRC');

        $this->post("/items/bank/{$other->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);

        $this->assertSame($before, $this->s($hmrc));
    }

    // Recheck P2: a meal on an ordinary day's job is personal unless there's evidence you were away overnight.

    public function test_meals_on_a_day_job_with_no_sign_of_a_night_away_stay_personal(): void
    {
        $this->leedsHome();
        $this->place('YO1 7HH', 53.9600, -1.0873); // about 22 miles
        $york = WorkJob::query()->create(['title' => 'York gig', 'starts_on' => '2025-07-10', 'ends_on' => '2025-07-10', 'postcode' => 'YO1 7HH', 'status' => 'done', 'start_time' => '10:00', 'end_time' => '18:00']);
        $calendar = WorkJob::query()->create(['title' => 'Calendar job', 'starts_on' => '2025-07-20', 'ends_on' => '2025-07-20', 'status' => 'done']);
        foreach (['2025-05-01', '2025-05-08', '2025-05-15'] as $d) {
            $this->you($this->tx('GREGGS', -450, $d), 'personal');
        }
        $pret = $this->tx('PRET A MANGER', -650, '2025-07-10');
        $greggs = $this->tx('GREGGS', -450, '2025-07-20');
        $this->suggest($pret, $greggs);

        $this->assertSame(['personal', null], [$this->s($pret)['kind'], $this->s($pret)['job']], 'A day job 22 miles away: no sign of a night away');
        $this->assertStringStartsWith('Check: Looks like food or drink, on a working day of York gig', $this->s($pret)['reason']);
        $this->assertStringContainsString('personal unless you stayed away overnight', $this->s($pret)['reason']);
        $this->assertSame('personal', $this->s($greggs)['kind'], 'Your own choice, on a job with no postcode');
        $this->assertStringContainsString('Like the 3 times you decided GREGGS', $this->s($greggs)['reason']);
        $this->assertNotNull($york->id + $calendar->id);
    }

    public function test_a_meal_between_two_working_days_at_a_middling_distance_is_business_to_check(): void
    {
        $this->leedsHome();
        $this->place('YO1 7HH', 53.9600, -1.0873);
        $job = WorkJob::query()->create(['title' => 'York festival', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-03', 'postcode' => 'YO1 7HH', 'status' => 'done']);
        $lunch = $this->tx('PRET A MANGER', -650, '2025-08-02');
        $this->suggest($lunch);

        $this->assertSame(['business_expense', 'travel_subsistence', $job->id], [$this->s($lunch)['kind'], $this->s($lunch)['category'], $this->s($lunch)['job']]);
        $this->assertStringStartsWith('Check: ', $this->s($lunch)['reason']);
        $this->assertStringContainsString('allowable only if you were away overnight', $this->s($lunch)['reason']);
    }

    // Recheck P3: paying a hotel or flight ahead of a job is a note, unless it names the job's town.

    public function test_a_holiday_hotel_or_flight_before_a_job_is_not_suggested_business(): void
    {
        $job = WorkJob::query()->create(['title' => 'Corporate AGM', 'starts_on' => '2025-07-20', 'ends_on' => '2025-07-20', 'status' => 'done']);
        $hotel = $this->tx('PREMIER INN BATH', -9000, '2025-06-01');
        $flight = $this->tx('EASYJET HOLIDAYS', -40000, '2025-05-10', 'TRAVEL-AIRLINE');
        $this->suggest($hotel, $flight);

        foreach ([$hotel, $flight] as $t) {
            $this->assertSame(['personal', null], [$this->s($t)['kind'], $this->s($t)['job']]);
            $this->assertStringStartsWith('Check: ', $this->s($t)['reason']);
            $this->assertStringContainsString('maybe booked ahead for Corporate AGM (job '.$job->id.') on 20 Jul: personal unless it was for that job', $this->s($t)['reason']);
        }
    }

    public function test_a_learned_business_hotel_does_not_make_a_holiday_hotel_business_before_a_job(): void
    {
        $this->leedsHome();
        $this->place('G1 1AA', 55.8609, -4.2514);
        $this->place('M1 1AA', 53.4808, -2.2426); // about 36 miles
        $glasgow = WorkJob::query()->create(['title' => 'Glasgow arena', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'G1 1AA', 'status' => 'done']);
        $this->you($this->tx('PREMIER INN', -9000, '2025-07-02'), 'business_expense', 'travel_accommodation', 100, $glasgow->id);
        $gig = WorkJob::query()->create(['title' => 'Manchester gig', 'starts_on' => '2025-09-20', 'ends_on' => '2025-09-20', 'postcode' => 'M1 1AA', 'status' => 'done']);
        $holiday = $this->tx('PREMIER INN', -9000, '2025-08-15');
        $forTheGig = $this->tx('PREMIER INN MANCHESTER', -8000, '2025-08-15');
        $this->suggest($holiday, $forTheGig);

        $this->assertSame(['personal', null], [$this->s($holiday)['kind'], $this->s($holiday)['job']]);
        $this->assertStringStartsWith('Check: Unlike the 1 time you decided PREMIER INN, maybe booked ahead for Manchester gig', $this->s($holiday)['reason']);
        $this->assertSame(['business_expense', 'travel_accommodation', $gig->id], [$this->s($forTheGig)['kind'], $this->s($forTheGig)['category'], $this->s($forTheGig)['job']], 'It names the job’s town');
        $this->assertStringContainsString('naming Manchester', $this->s($forTheGig)['reason']);
    }

    // Recheck P4 (checker 1): a payee you decide as a transfer is left alone, never guessed as spending.

    public function test_a_payee_you_decide_as_a_transfer_is_not_guessed_as_spending(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['2025-04-01', '2025-04-15', '2025-05-01'] as $d) {
            $this->you($this->tx('CHASE SAVER', -20000, $d, 'PAYMENTS', 'same', 'FASTER PAYMENT'), 'transfer');
        }
        foreach (['PAUL SMITH', 'DAVE JONES', 'SARAH BROWN'] as $i => $p) {
            $this->you($this->tx($p, -15000, '2025-05-0'.($i + 2), 'PAYMENTS', 'same', 'FASTER PAYMENT'), 'business_expense', 'subcontractors');
        }
        $this->assertNull(app(Categoriser::class)->propose($this->tx('CHASE SAVER', -30000, '2025-06-01', 'PAYMENTS', 'same', 'FASTER PAYMENT')));

        // A guess waiting from before goes back to you when you decide another as a transfer.
        $waiting = $this->tx('MONZO POT', -5000, '2025-06-02', 'GENERAL', 'same', 'CARD PAYMENT');
        app(AllocationWriter::class)->decide($waiting, AllocationWriter::lines(-5000, 'business_expense', $this->cat('materials')), 'matcher', false, 'Like the 1 time you decided MONZO POT');
        $pot = $this->tx('MONZO POT', -5000, '2025-06-03', 'GENERAL', 'same', 'CARD PAYMENT');
        $this->post("/items/bank/{$pot->id}/decide", ['kind' => 'transfer', 'business_pct' => 100]);

        $this->assertNull($this->s($waiting), 'Not spending: left for you and the transfer check');
        $this->assertSame('unreviewed', $waiting->fresh()->review_status);
    }

    // Recheck P5 (checker 2): payments to people, and catch-all bank categories, don't share decisions.

    public function test_payments_to_people_do_not_share_decisions_through_the_banks_category(): void
    {
        foreach (['PAUL SMITH', 'DAVE JONES', 'SARAH BROWN'] as $i => $p) {
            $this->you($this->tx($p, -15000, '2025-05-0'.($i + 1), 'EXPENSES', 'same', 'FASTER PAYMENT'), 'business_expense', 'subcontractors');
        }
        $rent = $this->tx('MRS J LANDLORD', -90000, '2025-06-01', 'EXPENSES', 'same', 'FASTER PAYMENT');
        $this->suggest($rent);

        $this->assertNull($this->s($rent), 'Your landlord isn’t crew');
    }

    public function test_a_catch_all_bank_category_is_not_learned(): void
    {
        foreach (['ACME HIRE LTD', 'ZED AUDIO HIRE', 'BIG STAGE LTD'] as $i => $m) {
            $this->you($this->tx($m, -30000, '2025-05-1'.($i + 1), 'PAYMENTS', 'same', 'DIRECT DEBIT'), 'business_expense', 'equipment');
        }
        $council = $this->tx('LEEDS CITY COUNCIL', -15000, '2025-06-01', 'PAYMENTS', 'same', 'DIRECT DEBIT');
        $this->suggest($council);

        $this->assertNull($this->s($council), 'The bank puts all sorts under “Payments”');
    }

    // Recheck P6/P7: listed merchants named after people keep their hint; people named like merchants don't get it.

    public function test_listed_merchants_named_after_people_keep_their_hint(): void
    {
        $this->leedsHome();
        $this->place('G1 1AA', 55.8609, -4.2514);
        WorkJob::query()->create(['title' => 'Glasgow arena', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'G1 1AA', 'status' => 'done']);
        $tim = $this->tx('TIM HORTONS 4021', -450, '2025-07-02', null, 'same', 'CARD PAYMENT');
        $timNoType = $this->tx('TIM HORTONS', -450, '2025-07-02');
        $leon = $this->tx('LEON VICTORIA', -900, '2025-07-02', null, 'same', 'CARD PAYMENT');
        $gym = $this->tx('DAVID LLOYD', -6000, '2025-06-01');
        $companies = $this->tx('COMPANIES HOUSE', -1300, '2025-06-01', null, 'same', 'FASTER PAYMENT');
        $crew = $this->tx('LEON JONES', -15000, '2025-07-02');
        $costa = $this->tx('SARAH COSTA', -15000, '2025-07-02', null, 'same', 'FASTER PAYMENT');
        $engineer = $this->tx('DAVID LLOYD', -20000, '2025-06-02', null, 'same', 'FASTER PAYMENT');
        $this->suggest($tim, $timNoType, $leon, $gym, $companies, $crew, $costa, $engineer);

        foreach ([$tim, $timNoType, $leon] as $t) {
            $this->assertSame(['business_expense', 'travel_subsistence'], [$this->s($t)['kind'], $this->s($t)['category']], $t->description);
            $this->assertStringStartsWith('Looks like food or drink, on a working day of Glasgow arena', $this->s($t)['reason']);
        }
        $this->assertSame('personal', $this->s($gym)['kind']);
        $this->assertStringContainsString('a gym', $this->s($gym)['reason']);
        $this->assertSame(['business_expense', 'training'], [$this->s($companies)['kind'], $this->s($companies)['category']]);
        $this->assertNull($this->s($crew), 'Crew, not a LEON restaurant');
        $this->assertNull($this->s($costa), 'Crew, not a Costa café');
        $this->assertNull($this->s($engineer), 'Paid to David Lloyd’s own account: a person');
    }

    // Recheck P5/P8 (and its needs_other): clearing an outdated guess is recorded as the matcher's, not yours.

    public function test_clearing_an_outdated_guess_is_recorded_as_the_matchers(): void
    {
        if ((new \ReflectionMethod(AllocationWriter::class, 'clear'))->getNumberOfParameters() < 3) {
            $this->markTestSkipped('Needs AllocationWriter::clear($source, $reason, $actor): see needs_other');
        }
        $this->actingAs(User::factory()->create());
        $this->you($this->tx('GRIP AND GAFFER', -3000, '2025-05-01'), 'business_expense', 'materials');
        $waiting = $this->tx('GRIP AND GAFFER', -2000, '2025-06-20');
        $this->suggest($waiting);
        $t = $this->tx('GRIP AND GAFFER', -1500, '2025-06-01');
        $this->post("/items/bank/{$t->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);

        $cleared = AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_type', 'BankTransaction')->where('subject_id', $waiting->id)->latest('id')->first();
        $this->assertNotNull($cleared);
        $this->assertSame('matcher', $cleared->actor);
    }

    // Recheck P6 (checker 2): waiting lines follow a rule you change.

    public function test_waiting_lines_follow_a_rule_you_change(): void
    {
        $this->actingAs(User::factory()->create());
        MerchantRule::query()->create(['merchant_key' => 'GRIP', 'kind' => 'personal', 'business_pct' => 100]);
        $waiting = $this->tx('GRIP CO', -2000, '2025-06-01');
        $this->suggest($waiting);
        $this->assertSame(['personal', 'rule'], [$this->s($waiting)['kind'], $this->s($waiting)['by']]);
        $ids = Allocation::query()->where('bank_transaction_id', $waiting->id)->pluck('id')->all();

        // The same decision as the rule: nothing to change.
        $same = $this->tx('GRIP CO', -2000, '2025-06-04');
        $this->post("/items/bank/{$same->id}/decide", ['kind' => 'personal', 'business_pct' => 100, 'make_rule' => true]);
        $this->assertSame($ids, Allocation::query()->where('bank_transaction_id', $waiting->id)->pluck('id')->all());

        $now = $this->tx('GRIP CO', -2000, '2025-06-08');
        $this->post("/items/bank/{$now->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'business_pct' => 100, 'make_rule' => true]);

        $this->assertSame(['business_expense', 'materials', 'rule', 'Your rule for GRIP'], [$this->s($waiting)['kind'], $this->s($waiting)['category'], $this->s($waiting)['by'], $this->s($waiting)['reason']]);
        $this->assertSame('Your rule for GRIP changed: suggested again', AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $waiting->id)->value('reason'));
    }
}
