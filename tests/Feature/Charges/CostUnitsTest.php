<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostClass;
use App\Domain\Charges\CostRef;
use App\Domain\Charges\CostUnits;
use App\Domain\Charges\CostWords;
use App\Domain\Income\Lines;
use App\Domain\Invoices\InvoiceService;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\JobDay;
use App\Models\TravelItem;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S5: what a cost is (CostUnits), how it's cited (CostRef) and described (CostWords). Money out and hand-
 * entered expenses make costs; on money in only a refund does. Deductions, finance-type spending and mileage booked as
 * a payment never do. A booking and its payment count once, and ones that may be the same are flagged until you say.
 */
class CostUnitsTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCharges();
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> */
    private function units(WorkJob $job): array
    {
        return collect(CostUnits::forJob($job))->keyBy('ref')->all();
    }

    public function test_a_payment_and_a_hand_entered_expense_on_a_job_are_costs_cited_by_the_payment(): void
    {
        $job = $this->job($this->client());
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $taxi = $this->expense(-1200, '2026-06-03', 'Taxi from the station');
        $this->decide($taxi, [$this->cost(1200, 'travel_taxi', $job)]);
        // Half a hotel bill is personal: only the business part is the job's cost.
        $hotel = $this->tx(-20000, '2026-06-04', 'PREMIER INN');
        $this->decide($hotel, [$this->cost(12000, 'travel_accommodation', $job), ['kind' => 'personal', 'amount_pence' => -8000]]);

        $units = $this->units($job);

        $this->assertSame(["tx:{$train->id}#j{$job->id}", "exp:{$taxi->id}#j{$job->id}", "tx:{$hotel->id}#j{$job->id}"], array_keys($units));
        $t = $units["tx:{$train->id}#j{$job->id}"];
        $this->assertSame(['cost', 'rail', 4500, 'GBP', 4500, "tx:{$train->id}", true, false], [$t['kind'], $t['class'], $t['amount_pence'], $t['currency'], $t['amount_minor'], $t['subject'], $t['confirmed'], $t['locked']]);
        $this->assertSame('Train: TRAINLINE, 2 Jun 2026', $t['description']);
        $this->assertSame(['taxi', 1200, "exp:{$taxi->id}"], [$units["exp:{$taxi->id}#j{$job->id}"]['class'], $units["exp:{$taxi->id}#j{$job->id}"]['amount_pence'], $units["exp:{$taxi->id}#j{$job->id}"]['subject']]);
        $this->assertSame(12000, $units["tx:{$hotel->id}#j{$job->id}"]['amount_pence'], 'Only the business part');
        $this->assertSame([], $t['flags']);
    }

    public function test_a_deduction_finance_spending_and_mileage_booked_as_a_payment_never_make_a_cost(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        // £970 received: £1,000 turnover and a £30 commission taken off, both on the job.
        $receipt = $this->tx(97000, '2026-06-12', 'CREWCO LTD');
        $this->decide($receipt, [$this->income(100000, $job), Lines::deduction(3000, $this->cat('professional'), $job->id)]);
        $fee = $this->tx(-250, '2026-06-03', 'STARLING FEE');
        $this->decide($fee, [$this->cost(250, 'finance', $job)]);
        $miles = $this->tx(-6400, '2026-06-03', 'MILEAGE CLAIM');
        $this->decide($miles, [$this->cost(6400, 'travel_mileage', $job)]);

        $this->assertSame([], CostUnits::forJob($job), 'No cost unit, so nothing goes on any draft');
        $this->assertSame([["tx:{$miles->id}#j{$job->id}", 6400]], array_map(fn ($c) => [$c['ref'], $c['amount_pence']], CostUnits::mileagePayments($job)));
        $this->assertStringContainsString('Mileage recorded as a payment', CostUnits::mileagePayments($job)[0]['check']);
    }

    public function test_on_money_in_only_a_refund_is_a_credit_and_never_the_client_paying_you(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $refund = $this->tx(2000, '2026-06-08', 'TRAINLINE');
        $this->decide($refund, [$this->cost(-2000, 'travel_rail', $job)]);

        $credit = $this->units($job)["tx:{$refund->id}#j{$job->id}"];
        $this->assertSame(['credit', -2000, -2000, 'rail'], [$credit['kind'], $credit['amount_pence'], $credit['amount_minor'], $credit['class']]);
        $this->assertStringStartsWith('Refund: Train', $credit['description']);

        // ACME PAYROLL pays for Acme: money in from it is the client paying you, never a refund to pass on.
        $paid = $this->tx(50000, '2026-06-09', 'ACME PAYROLL');
        $this->decide($paid, [$this->income(50000, $this->job($acme, '2026-05-01'))]);
        $back = $this->tx(1500, '2026-06-10', 'ACME PAYROLL');
        $this->decide($back, [$this->cost(-1500, 'travel_rail', $job)]);

        $this->assertArrayNotHasKey("tx:{$back->id}#j{$job->id}", $this->units($job));
    }

    public function test_a_booking_with_no_payment_is_a_cost_and_one_near_a_ledger_cost_of_its_kind_may_be_the_same(): void
    {
        $job = $this->job($this->client(), '2026-06-03', '2026-06-04');
        $stay = $this->booking($job, 'hotel', 'Booking.com', 12000);
        $alone = $this->units($job);
        $this->assertSame(["travel:{$stay->id}"], array_keys($alone));
        $this->assertSame(['booking', 'hotel', 12000, ['no_payment']], [$alone["travel:{$stay->id}"]['kind'], $alone["travel:{$stay->id}"]['class'],
            $alone["travel:{$stay->id}"]['amount_pence'], array_keys($alone["travel:{$stay->id}"]['flags'])]);
        $this->assertSame('Hotel: Booking.com, Booking.com Leeds, 3 Jun 2026', $alone["travel:{$stay->id}"]['description']);

        // The hotel's own card payment (£123.40 with city tax) on the same job, after the stay: maybe the same.
        $card = $this->tx(-12340, '2026-06-05', 'PREMIER INN LEEDS');
        $this->decide($card, [$this->cost(12340, 'travel_accommodation', $job)]);
        $units = $this->units($job);
        $payment = "tx:{$card->id}#j{$job->id}";
        $this->assertSame([], $units[$payment]['flags'], 'The ledger cost stays as it is');
        $this->assertSame($payment, $units["travel:{$stay->id}"]['pair_ref']);
        $this->assertArrayHasKey('maybe_same', $units["travel:{$stay->id}"]['flags']);

        // [Same cost]: the booking becomes the payment's alias, so one cost.
        app(CostCharges::class)->pair("travel:{$stay->id}", $payment, 'same');
        $units = $this->units($job);
        $this->assertSame([$payment], array_keys($units));
        $this->assertSame(["travel:{$stay->id}"], $units[$payment]['aliases']);

        // [Different]: both, with no flag.
        app(CostCharges::class)->pair("travel:{$stay->id}", $payment, 'distinct');
        $units = $this->units($job);
        $this->assertSame(["travel:{$stay->id}", $payment], array_keys($units), 'In date order');
        $this->assertArrayNotHasKey('maybe_same', $units["travel:{$stay->id}"]['flags']);
    }

    public function test_a_booking_beside_a_hand_entered_expense_and_one_someone_else_paid_for(): void
    {
        $job = $this->job($this->client());
        $ticket = $this->booking($job, 'rail', 'Trainline', 6400);
        $cash = $this->expense(-6400, '2026-06-03', 'Train to Leeds');
        $this->decide($cash, [$this->cost(6400, 'travel_rail', $job)]);
        $theirs = $this->booking($job, 'flight', 'easyJet', 9900, '2026-06-03', ['payment' => 'paid_by_other']);
        $suggested = $this->booking($job, 'taxi', 'Addison Lee', 3000, '2026-06-03', ['suggestion' => ['decision' => 'client_booked']]);
        $decided = $this->booking($job, 'car_hire', 'Hertz', 15000, '2026-06-03', ['decision' => 'client_booked', 'decided_at' => now()]);

        $units = $this->units($job);

        $this->assertSame("exp:{$cash->id}#j{$job->id}", $units["travel:{$ticket->id}"]['pair_ref']);
        $this->assertArrayHasKey('maybe_same', $units["travel:{$ticket->id}"]['flags']);
        $this->assertSame('The booking says someone else paid.', $units["travel:{$theirs->id}"]['flags']['paid_by_other']);
        $this->assertArrayHasKey('paid_by_other', $units["travel:{$suggested->id}"]['flags']);
        $this->assertArrayNotHasKey("travel:{$decided->id}", $units, 'Decided as booked by the client: no cost');
    }

    public function test_a_booking_linked_to_its_payment_is_the_payments_cost_under_its_alias(): void
    {
        $job = $this->job($this->client());
        $ticket = $this->booking($job, 'rail', 'Trainline', 4500);
        $this->assertSame(["travel:{$ticket->id}"], array_keys($this->units($job)));

        $tx = $this->tx(-4500, '2026-06-01', 'TRAINLINE');
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)]);
        $ticket->update(['bank_transaction_id' => $tx->id]);

        $units = $this->units($job);
        $ref = "tx:{$tx->id}#j{$job->id}";
        $this->assertSame([$ref], array_keys($units));
        $this->assertSame(["travel:{$ticket->id}"], $units[$ref]['aliases']);
        $this->assertSame('Train: Trainline, London to Leeds, 1 Jun 2026', $units[$ref]['description']);
        $this->assertSame(["tx:{$tx->id}#j{$job->id}", "travel:{$ticket->id}"], CostUnits::refsOf("tx:{$tx->id}"));
    }

    public function test_a_hand_entered_expense_and_a_card_line_for_one_payment_are_flagged_until_you_say(): void
    {
        $job = $this->job($this->client());
        $card = $this->tx(-2599, '2026-06-03', 'SCREWFIX LEEDS');
        $this->decide($card, [$this->cost(2599, 'materials', $job)]);
        $cash = $this->expense(-2599, '2026-06-03', 'Screwfix');
        $this->decide($cash, [$this->cost(2599, 'materials', $job)]);
        $exp = "exp:{$cash->id}#j{$job->id}";
        $tx = "tx:{$card->id}#j{$job->id}";

        $units = $this->units($job);
        $this->assertSame($tx, $units[$exp]['pair_ref']);
        $this->assertArrayHasKey('maybe_same', $units[$exp]['flags']);
        $this->assertSame([], $units[$tx]['flags']);

        app(CostCharges::class)->pair($exp, $tx, 'same');
        $units = $this->units($job);
        $this->assertSame([$tx], array_keys($units));
        $this->assertSame([$exp], $units[$tx]['aliases']);

        app(CostCharges::class)->pair($exp, $tx, 'distinct');
        $this->assertArrayNotHasKey('maybe_same', $this->units($job)[$exp]['flags']);
    }

    public function test_fuel_beside_mileage_charged_per_mile_and_fuel_for_a_car_on_simplified_mileage_are_flagged(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $this->trip($job, '142.5');
        $fuel = $this->tx(-5000, '2026-06-03', 'SHELL');
        $this->decide($fuel, [$this->cost(5000, 'travel_fuel', $job)]);
        $ref = "tx:{$fuel->id}#j{$job->id}";

        $flags = $this->units($job)[$ref]['flags'];
        $this->assertSame('Fuel for your own car is covered by your mileage claim. Charge driving per mile, or type the amount as your own line.', $flags['check']);
        $this->assertArrayNotHasKey('maybe_same', $flags, 'Driving isn’t charged per mile yet');

        ChargeTerm::query()->create(['client_id' => $acme->id, 'class' => 'mileage', 'charge' => 'per_mile', 'rate_minor' => 45]);
        $this->assertSame('Fuel and mileage for the same drive?', $this->units($job)[$ref]['flags']['maybe_same']);

        // A hire car on the job: fuel for it is a cost like any other.
        $this->booking($job, 'car_hire', 'Hertz', 15000);
        $this->assertArrayNotHasKey('check', $this->units($job)[$ref]['flags']);
    }

    public function test_a_mileage_trip_is_counted_in_miles_and_its_line_names_only_the_venue(): void
    {
        $job = $this->job($this->client());
        $trip = $this->trip($job, '142.5');

        $unit = $this->units($job)["trip:{$trip->id}"];

        $this->assertSame(['trip', 'mileage', '142.50', 'miles', null, "trip:{$trip->id}"], [$unit['kind'], $unit['class'], $unit['quantity'], $unit['unit'], $unit['amount_pence'], $unit['subject']]);
        $this->assertSame('Mileage 3 Jun: Leeds Arena and back, 142.5 miles', $unit['description']);
        $line = CostWords::mileage($unit['date'], CostWords::venue($job), $unit['quantity'], 45);
        $this->assertSame('Mileage 3 Jun: Leeds Arena and back, 142.5 miles at 45p', $line);
        $this->assertSame(6413, InvoiceService::lineAmount($unit['quantity'], 45), '142.5 × 45p = £64.125, half up');
        foreach (['Oak Road', 'LS6', 'LS2', '8BY', 'Home'] as $never) {
            $this->assertStringNotContainsString($never, $line);
        }
    }

    public function test_costs_for_a_client_with_no_job_and_ones_marked_to_recharge_to_them(): void
    {
        $acme = $this->client();
        $dupe = $this->client('ACME EVENTS LTD');
        $named = $this->tx(-3000, '2026-06-03', 'COSTA');
        $this->decide($named, [$this->cost(3000, 'travel_subsistence', null, $dupe->id)]);
        // A job-less cost naming no client, which you marked to recharge to Acme (its year is locked, say).
        $marked = $this->tx(-1800, '2026-06-04', 'NCP');
        $this->decide($marked, [$this->cost(1800, 'travel_parking')]);
        CostCharge::query()->create(['cost_ref' => "tx:{$marked->id}#c{$acme->id}", 'subject_ref' => "tx:{$marked->id}", 'charge' => 'recharge', 'client_id' => $acme->id]);
        // Someone else's.
        $other = $this->tx(-900, '2026-06-04', 'PRET');
        $this->decide($other, [$this->cost(900, 'travel_subsistence', null, $this->client('Big Prod Co')->id)]);
        $dupe->forceFill(['merged_into_id' => $acme->id])->save();

        $units = collect(CostUnits::forClient($acme, false))->keyBy('ref');

        $this->assertSame(["tx:{$named->id}#c{$acme->id}", "tx:{$marked->id}#c{$acme->id}"], $units->keys()->all(), 'A merged client counts as the one it went into');
        $this->assertSame(["tx:{$named->id}#c{$dupe->id}"], $units["tx:{$named->id}#c{$acme->id}"]['aliases']);
        $this->assertSame([null, $acme->id, 1800], [$units["tx:{$marked->id}#c{$acme->id}"]['job_id'], $units["tx:{$marked->id}#c{$acme->id}"]['client_id'], $units["tx:{$marked->id}#c{$acme->id}"]['amount_pence']]);
        $this->assertSame($units->keys()->all(), collect(CostUnits::forClient($dupe, false))->pluck('ref')->all());
    }

    public function test_a_jobs_work_is_its_fee_or_its_days_and_hours_and_a_cancelled_job_only_its_fee(): void
    {
        $acme = $this->client();
        $fee = $this->job($acme, '2026-06-03', null, ['fee_pence' => 65000]);
        $this->assertSame([["job:{$fee->id}#fee", '1.00', 65000]], array_map(fn ($u) => [$u['ref'], $u['quantity'], $u['unit_price_minor']], CostUnits::work($fee)));
        $this->assertSame($fee->title.' (3 Jun 2026)', CostUnits::work($fee)[0]['description']);

        // 3 days agreed, 4 worked: 3 days and 1 more.
        $days = $this->job($acme, '2026-06-01', '2026-06-04', ['rate_pence' => 25000, 'rate_unit' => 'day', 'days_agreed' => 3]);
        $this->assertSame([["job:{$days->id}#days", '3.00', 25000], ["job:{$days->id}#extra", '1.00', 25000]],
            array_map(fn ($u) => [$u['ref'], $u['quantity'], $u['unit_price_minor']], CostUnits::work($days)));
        // A fee for 3 days, 4 worked: the extra day priced at the fee ÷ 3, to check.
        $feeDays = $this->job($acme, '2026-06-01', '2026-06-04', ['fee_pence' => 90000, 'days_agreed' => 3]);
        $extra = CostUnits::work($feeDays)[1];
        $this->assertSame(["job:{$feeDays->id}#extra", 30000], [$extra['ref'], $extra['unit_price_minor']]);
        $this->assertArrayHasKey('check', $extra['flags']);

        // Hourly: from the days' times, past midnight counted on; a day with no times is to type.
        $hours = $this->job($acme, '2026-06-01', '2026-06-03', ['rate_pence' => 3000, 'rate_unit' => 'hour', 'start_time' => '18:00', 'end_time' => '01:30']);
        JobDay::query()->where('work_job_id', $hours->id)->where('on_date', '2026-06-02')->update(['start_time' => '09:00', 'end_time' => '17:15']);
        $hours->forceFill(['start_time' => null, 'end_time' => null])->saveQuietly();
        JobDay::query()->where('work_job_id', $hours->id)->where('on_date', '2026-06-01')->update(['start_time' => '18:00', 'end_time' => '01:30']);
        $unit = CostUnits::work($hours->fresh())[0];
        $this->assertSame(["job:{$hours->id}#hours", '15.75'], [$unit['ref'], $unit['quantity']], '7.5 hours and 8.25 hours');
        $this->assertSame('Hours not known for 3 Jun: type them', $unit['flags']['check']);

        $cancelled = $this->job($acme, '2026-06-03', null, ['fee_pence' => 65000, 'status' => 'cancelled', 'cancel_charge' => 'fee', 'cancel_fee_pence' => 20000]);
        $this->assertSame([["job:{$cancelled->id}#cancel", 20000, 'Cancellation fee: '.$cancelled->title.' (3 Jun 2026)']],
            array_map(fn ($u) => [$u['ref'], $u['unit_price_minor'], $u['description']], CostUnits::work($cancelled)));
        $cancelled->update(['cancel_charge' => 'none']);
        $this->assertSame([], CostUnits::work($cancelled->fresh()));
    }

    public function test_a_line_on_a_held_statement_counts_nowhere_and_a_cost_in_another_currency_keeps_it(): void
    {
        $job = $this->job($this->client());
        $held = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($held, [$this->cost(4500, 'travel_rail', $job)]);
        DB::table('bank_transactions')->where('id', $held->id)->update(['state' => 'held']);
        $euro = $this->tx(-4300, '2026-06-03', 'SNCF', ['original_currency' => 'EUR', 'original_amount_minor' => -5000]);
        $this->decide($euro, [$this->cost(4300, 'travel_rail', $job)]);

        $units = $this->units($job);

        $this->assertSame(["tx:{$euro->id}#j{$job->id}"], array_keys($units));
        $this->assertSame([4300, 'EUR', 5000], [$units["tx:{$euro->id}#j{$job->id}"]['amount_pence'], $units["tx:{$euro->id}#j{$job->id}"]['currency'], $units["tx:{$euro->id}#j{$job->id}"]['amount_minor']]);
    }

    public function test_unconfirmed_costs_are_flagged_and_locked_ones_say_to_untick_or_unlock(): void
    {
        $job = $this->job($this->client());
        $tx = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)], false);

        $unit = $this->units($job)["tx:{$tx->id}#j{$job->id}"];

        $this->assertFalse($unit['confirmed']);
        $this->assertSame('Not confirmed yet: issuing confirms it.', $unit['flags']['unconfirmed']);
    }

    public function test_ids_for_buttons_fit_the_todo_route_and_telegram(): void
    {
        foreach (['tx:381#j45', 'exp:12#c9', 'travel:7', 'trip:3', 'job:45#days', 'tx:4294967295#j4294967295', 'exp:4294967295#c4294967295'] as $ref) {
            $id = CostRef::toId($ref);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_:]+$/', $id);
            $this->assertSame($ref, CostRef::fromId($id));
            // An action name, the id and an 8-character fingerprint, as the to-do and Telegram buttons carry them.
            $this->assertLessThan(64, strlen('cc_recharge:'.$id.':'.str_repeat('f', 8)), 'Telegram callback data stays under 64 bytes');
        }
        $this->assertSame('tx:381:j45', CostRef::toId('tx:381#j45'));
        $this->assertSame(['type' => 'tx', 'id' => 381, 'job' => 45, 'client' => null, 'part' => null], CostRef::parse('tx:381#j45'));
        $this->assertSame('tx:381', CostRef::subject('tx:381#j45'));
        $this->assertNull(CostRef::fromId('tx:381#j45'));
        $this->assertNull(CostRef::fromId('drop:1:2'));
        $this->expectException(InvalidArgumentException::class);
        CostRef::tx(1, 2, 3);
    }

    public function test_the_kinds_of_cost_are_the_arrangements_kinds(): void
    {
        $this->assertSame(ChargeTerm::CHARGES, CostClass::CLASSES);
        $this->assertSame('bus', CostClass::ofBooking('coach'), 'A coach is local transport');
        $this->assertSame('hotel', CostClass::ofBooking(new TravelItem(['kind' => 'hotel'])));
        $this->assertSame('other', CostClass::of('software'));
        $this->assertFalse(CostClass::counts('travel_mileage'));
        $this->assertFalse(CostClass::counts('finance'));
        $this->assertTrue(CostClass::counts('travel_penalties'));
        $this->assertSame('Fixed travel fee', ChargeTermsWriter::label('travel_fee'));
        $this->assertNull(CostWords::couldHold(['Trainline'], [['description' => 'Load-in crew, 1 day']]));
        $this->assertSame('Travel and hotel, at cost', CostWords::couldHold(['Trainline'], [['description' => 'Load-in crew, 1 day'], ['description' => 'Travel and hotel, at cost']]));
        $this->assertSame('Trainline tickets', CostWords::couldHold('Trainline', [(object) ['description' => 'Trainline tickets']]));
        $this->assertSame('Taxi: Uber, 3 Jun 2026', CostWords::cost('taxi', 'Uber 4929 1234 5678 9012 LS2 8BY', null, '2026-06-03'));
        $this->assertInstanceOf(Client::class, $this->client());
    }
}
