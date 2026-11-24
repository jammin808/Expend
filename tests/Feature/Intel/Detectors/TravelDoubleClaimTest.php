<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\TravelDoubleClaim;
use App\Domain\Intel\FindingDraft;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** travel.double_claim: mileage and a train the same job day, a second trip from home within a stay, fuel with simplified mileage. */
class TravelDoubleClaimTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-07-01 10:00:00');
        CarbonImmutable::setTestNow('2026-07-01 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, FindingDraft> */
    private function found(): array
    {
        return collect((new TravelDoubleClaim)->detect(new CycleContext))->keyBy('key')->all();
    }

    private function van(string $method = 'simplified'): Vehicle
    {
        return Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => $method]);
    }

    private function trip(Vehicle $v, WorkJob $job, string $on, float $miles = 80, string $from = 'Home'): MileageTrip
    {
        return MileageTrip::query()->create(['vehicle_id' => $v->id, 'work_job_id' => $job->id, 'trip_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'from_place' => $from, 'to_place' => 'NEC B40 1NT', 'miles' => $miles, 'purpose' => 'Return trip']);
    }

    private function booking(string $kind, WorkJob $job, string $from, ?string $to = null, bool $decided = true): TravelItem
    {
        return TravelItem::query()->create(['kind' => $kind, 'provider' => $kind === 'hotel' ? 'Premier Inn' : 'Trainline', 'place' => $kind === 'hotel' ? 'Premier Inn NEC' : null,
            'status' => 'booked', 'starts_on' => $from, 'ends_on' => $to, 'amount_minor' => 9000, 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id,
            'decision' => $decided ? 'business' : null, 'decided_at' => $decided ? now() : null]);
    }

    public function test_mileage_and_a_train_on_the_same_job_day(): void
    {
        $van = $this->van();
        $job = WorkJob::query()->create(['title' => 'NEC conference', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-04', 'status' => 'done', 'location' => 'NEC',
            'postcode' => 'B40 1NT']);
        $trip = $this->trip($van, $job, '2026-06-03'); // to the venue
        $train = $this->booking('rail', $job, '2026-06-03');
        $other = $this->trip($van, $job, '2026-06-04'); // no train that day

        $found = $this->found();
        $d = $found['double:trip:'.$trip->id];
        $this->assertSame(['travel.double_claim', 'tax', 'at_risk', 'certain', 'trip:'.$trip->id, 'job:'.$job->id], [$d->code, $d->group, $d->direction, $d->grade, $d->subjectRef, $d->scopeRef]);
        $this->assertSame(-4400, $d->amountPence, '80 miles at 55p: the claim at risk, in whole pence');
        $this->assertSame('Mileage and a train on 3 Jun 2026: NEC conference', $d->title);
        $this->assertContains('travel:'.$train->id, $d->evidence);
        $this->assertArrayNotHasKey('double:trip:'.$other->id, $found);

        // A train not decided by you yet isn't confirmed: nothing to say.
        $train->update(['decided_at' => null, 'decision' => null]);
        $this->assertArrayNotHasKey('double:trip:'.$trip->id, $this->found());
    }

    public function test_a_second_trip_from_home_within_a_stay(): void
    {
        $van = $this->van();
        $job = WorkJob::query()->create(['title' => 'Arena run', 'starts_on' => '2026-06-08', 'ends_on' => '2026-06-10', 'status' => 'done']);
        $this->booking('hotel', $job, '2026-06-08', '2026-06-10'); // nights of 8th and 9th
        $first = $this->trip($van, $job, '2026-06-08');
        $this->assertSame([], $this->found(), 'One return trip covers a stay');
        $second = $this->trip($van, $job, '2026-06-09');
        $found = $this->found();
        $this->assertArrayHasKey('double:trip:'.$second->id, $found, 'You woke up at the hotel on the 9th');
        $this->assertArrayNotHasKey('double:trip:'.$first->id, $found);
        $this->assertStringContainsString('woke up at Premier Inn NEC', $found['double:trip:'.$second->id]->detail);
        $this->assertSame('certain', $found['double:trip:'.$second->id]->grade);
    }

    public function test_fuel_with_simplified_mileage_but_not_for_a_hire_car(): void
    {
        $this->van();
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 'a.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $fuel = Category::query()->where('code', 'travel_fuel')->value('id');
        $line = function (string $on, int $pence) use ($acc, $batch, $fuel) {
            $t = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
                'description' => 'SHELL', 'merchant_key' => 'SHELL', 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
            app(AllocationWriter::class)->decide($t, AllocationWriter::lines($pence, 'business_expense', $fuel));

            return $t;
        };
        $a = $line('2026-05-02', -6000);
        $line('2026-05-20', -4500);
        $hired = WorkJob::query()->create(['title' => 'Glasgow', 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-20', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'car_hire', 'provider' => 'Enterprise', 'status' => 'booked', 'starts_on' => '2026-05-19', 'ends_on' => '2026-05-21', 'amount_minor' => 12000,
            'source' => 'manual', 'evidence' => [], 'work_job_id' => $hired->id]);

        $d = $this->found()['double:fuel:2026-27'];
        $this->assertSame([-6000, 'at_risk', 'certain', 'year:2026-27'], [$d->amountPence, $d->direction, $d->grade, $d->subjectRef], 'The hire car’s fuel is left out');
        $this->assertSame(['year:2026-27', 'tx:'.$a->id], $d->evidence);

        // A vehicle on actual costs: fuel is claimed for it, nothing to say.
        $this->van('actual');
        $this->assertSame([], $this->found());
    }

    public function test_a_drive_to_the_airport_then_a_flight_is_not_claimed_twice(): void
    {
        $van = $this->van();
        $job = WorkJob::query()->create(['title' => 'Dublin conference', 'starts_on' => '2026-06-05', 'ends_on' => '2026-06-06', 'status' => 'done', 'postcode' => 'D02 X285']);
        $flight = $this->booking('flight', $job, '2026-06-05');
        $flight->update(['provider' => 'Ryanair']);
        $toAirport = $this->trip($van, $job, '2026-06-05', 64);
        $toAirport->update(['to_place' => 'Stansted Airport CM24 1RW']);
        $this->assertSame([], $this->found(), 'Driving to the airport and flying on: both are claimed');

        // A trip that doesn't say where it went: something to check, not a certainty.
        $toAirport->update(['to_place' => 'Essex']);
        $d = $this->found()['double:trip:'.$toAirport->id];
        $this->assertSame('possible', $d->grade);
        $this->assertStringContainsString('Check the trip wasn’t to the station or airport', $d->detail);
    }

    public function test_a_return_ticket_covers_its_two_days_not_those_between(): void
    {
        $van = $this->van();
        $job = WorkJob::query()->create(['title' => 'Glasgow week', 'starts_on' => '2026-06-05', 'ends_on' => '2026-06-09', 'status' => 'done', 'postcode' => 'G3 8YW']);
        $this->booking('rail', $job, '2026-06-05', '2026-06-09');
        $between = $this->trip($van, $job, '2026-06-07');
        $between->update(['to_place' => 'SEC G3 8YW']);
        $this->assertArrayNotHasKey('double:trip:'.$between->id, $this->found(), 'A local drive mid-week isn’t the train journey');
        $back = $this->trip($van, $job, '2026-06-09');
        $back->update(['to_place' => 'SEC G3 8YW']);
        $this->assertSame('certain', $this->found()['double:trip:'.$back->id]->grade, 'The day the return came back');
    }

    public function test_a_trip_from_home_while_staying_at_a_hotel_not_linked_to_the_job(): void
    {
        $van = $this->van();
        $job = WorkJob::query()->create(['title' => 'NEC three days', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-07', 'status' => 'done', 'postcode' => 'B40 1NT']);
        Carbon::setTestNow('2026-11-01 10:00:00');
        CarbonImmutable::setTestNow('2026-11-01 10:00:00');
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'place' => 'Premier Inn NEC', 'status' => 'booked', 'starts_on' => '2026-10-05',
            'ends_on' => '2026-10-07', 'amount_minor' => 18000, 'source' => 'email', 'evidence' => [], 'work_job_id' => null]);
        $trips = array_map(fn ($d) => $this->trip($van, $job, $d), ['2026-10-05', '2026-10-06', '2026-10-07']);

        $found = $this->found();
        $this->assertArrayNotHasKey('double:trip:'.$trips[0]->id, $found);
        $d = $found['double:trip:'.$trips[1]->id];
        $this->assertSame('likely', $d->grade, 'The stay isn’t linked to the job');
        $this->assertStringContainsString('a stay not linked to this job', $d->detail);
        $this->assertContains('travel:'.$hotel->id, $d->evidence);
        $this->assertArrayHasKey('double:trip:'.$trips[2]->id, $found);
    }
}
