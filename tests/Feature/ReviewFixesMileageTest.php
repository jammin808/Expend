<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobDays;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\TaxGuide;
use App\Domain\Travel\TravelMatcher;
use App\Models\AuditEvent;
use App\Models\JobDay;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Review fixes for adding mileage from jobs: locked years, jobs still to come, other jobs' travel and the vehicle used. */
class ReviewFixesMileageTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-23 12:00:00');
        $this->actingAs(User::factory()->create());
        Setting::put('home_postcode', 'SW1A 1AA');
        $places = [
            'SW1A 1AA' => [51.501, -0.1416], 'M2 3GX' => [53.4767, -2.2469], 'E16 1XL' => [51.5085, 0.0295],
            'LS2 8BY' => [53.8035, -1.5487], 'B5 4BU' => [52.4775, -1.8980],
        ];
        foreach ($places as $pc => [$lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->car = Vehicle::query()->create(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified']);
    }

    /** R2, R3: a locked year's mileage can't change, so its jobs aren't offered and a direct post is refused. */
    public function test_jobs_in_a_locked_year_are_not_offered_and_cannot_be_added(): void
    {
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);
        $job = WorkJob::query()->create(['title' => 'Filed-year show', 'starts_on' => '2024-06-10', 'ends_on' => '2024-06-10', 'postcode' => 'M2 3GX', 'status' => 'done']);

        $this->assertSame([], JobTrips::proposals('2024-25'));
        $this->get('/mileage?year=2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('fromJobs', [])->where('locked', true));
        $this->post('/mileage/from-jobs', ['year' => '2024-25', 'vehicle_id' => $this->car->id, 'job_ids' => [$job->id]])->assertStatus(422);
        $this->assertSame(0, JobTrips::add('2024-25', $this->car->id, [$job->id]), 'The domain refuses too, not just the controller');
        $this->assertSame(0, MileageTrip::query()->count());
    }

    /** R2: a job that runs over the end of an open year into a locked one only gets trips on the open year's days. */
    public function test_days_that_fall_in_a_locked_year_are_left_out(): void
    {
        TaxYear::track('2025-26', 'filed')->update(['locked_at' => now()]);
        $job = WorkJob::query()->create(['title' => 'Easter build', 'starts_on' => '2025-04-04', 'ends_on' => '2025-04-07', 'postcode' => 'E16 1XL', 'status' => 'done']);

        $p = collect(JobTrips::proposals('2024-25'))->firstWhere('job_id', $job->id);
        $this->assertSame(['2025-04-04', '2025-04-05'], $p['trip_dates']);
        $this->assertSame(2, $p['trips']);
        $this->assertStringContainsString('locked', $p['why']);

        $this->post('/mileage/from-jobs', ['year' => '2024-25', 'vehicle_id' => $this->car->id, 'job_ids' => [$job->id]])->assertSessionHas('success');
        $this->assertSame(['2024-25'], MileageTrip::query()->pluck('tax_year')->unique()->values()->all());
        $this->assertSame(2, MileageTrip::query()->count());
    }

    /** R2 (follow-up): a job that began in a locked year is offered on the next year's page, with only that year's days. */
    public function test_a_job_running_from_a_locked_year_into_an_open_one_is_offered_with_its_open_days(): void
    {
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);
        $job = WorkJob::query()->create(['title' => 'Easter build', 'starts_on' => '2025-04-04', 'ends_on' => '2025-04-08', 'postcode' => 'E16 1XL', 'status' => 'done']);

        $this->assertSame([], JobTrips::proposals('2024-25'), 'A locked year still offers nothing');
        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertNotNull($p, 'Offered on the open year\'s page');
        $this->assertSame(['2025-04-06', '2025-04-07', '2025-04-08'], $p['trip_dates']);
        $this->assertSame(3, $p['trips']);
        $this->assertStringContainsString('(2 in a locked tax year left out)', $p['why']);
        $this->get('/mileage?year=2025-26')->assertInertia(fn ($page) => $page->where('fromJobs.0.job_id', $job->id));

        $this->post('/mileage/from-jobs', ['year' => '2025-26', 'vehicle_id' => $this->car->id, 'job_ids' => [$job->id]])->assertSessionHas('success');
        $this->assertSame(['2025-26'], MileageTrip::query()->pluck('tax_year')->unique()->values()->all());
        $this->assertSame(3, MileageTrip::query()->count());
        $this->assertSame([], JobTrips::proposals('2025-26'), 'Not offered again once added');
    }

    /** R2 (follow-up): a rig in a locked year and a de-rig days later in the next year: the de-rig trip can still be added. */
    public function test_a_derig_in_an_open_year_after_a_rig_in_a_locked_one_is_offered(): void
    {
        $job = WorkJob::query()->create(['title' => 'Spring Expo', 'starts_on' => '2025-04-04', 'ends_on' => '2025-04-04', 'postcode' => 'M2 3GX', 'status' => 'done']);
        JobDays::add($job, '2025-04-08', 'derig');
        $this->assertSame('2025-04-08', $job->fresh()->ends_on->format('Y-m-d'));

        // While both years are open it's offered once, on the page of the year it starts in, with both visits.
        $this->assertSame(['2025-04-04', '2025-04-08'], collect(JobTrips::proposals('2024-25'))->firstWhere('job_id', $job->id)['trip_dates']);
        $this->assertNull(collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id), 'Not offered twice');

        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);
        $this->assertSame([], JobTrips::proposals('2024-25'));
        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertSame(['2025-04-08'], $p['trip_dates']);
        $this->assertSame(1, $p['trips']);
    }

    /** R3 (same cause): an existing trip can't be moved out of a locked year either. */
    public function test_a_trip_in_a_locked_year_cannot_be_moved_out_of_it(): void
    {
        $trip = MileageTrip::query()->create([
            'vehicle_id' => $this->car->id, 'trip_on' => '2024-06-10', 'tax_year' => '2024-25', 'from_place' => 'Home', 'to_place' => 'Manchester', 'miles' => '400.0',
        ]);
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);

        $this->patch("/mileage/{$trip->id}", [
            'vehicle_id' => $this->car->id, 'trip_on' => '2025-06-10', 'from_place' => 'Home', 'to_place' => 'Manchester', 'miles' => '400',
        ])->assertStatus(422);
        $this->assertSame('2024-25', $trip->fresh()->tax_year);
        $this->assertSame('2024-06-10', $trip->fresh()->trip_on->format('Y-m-d'));
    }

    /** R8: only jobs whose last day has been worked are offered, so no trips are dated in the future. */
    public function test_jobs_still_to_come_or_under_way_are_not_offered(): void
    {
        $future = WorkJob::query()->create(['title' => 'Future show', 'starts_on' => '2027-02-10', 'ends_on' => '2027-02-12', 'postcode' => 'E16 1XL']);
        $running = WorkJob::query()->create(['title' => 'Running now', 'starts_on' => '2026-11-22', 'ends_on' => '2026-11-24', 'postcode' => 'M2 3GX']);
        $rigged = WorkJob::query()->create(['title' => 'Rigged, de-rig to come', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20', 'postcode' => 'B5 4BU']);
        JobDay::query()->create(['work_job_id' => $rigged->id, 'on_date' => '2026-11-27', 'kind' => 'derig', 'source' => 'you']);
        $today = WorkJob::query()->create(['title' => 'Ends today', 'starts_on' => '2026-11-23', 'ends_on' => '2026-11-23', 'postcode' => 'LS2 8BY']);
        $past = WorkJob::query()->create(['title' => 'Done last week', 'starts_on' => '2026-11-16', 'ends_on' => '2026-11-16', 'postcode' => 'LS2 8BY']);

        $this->assertSame(['Done last week', 'Ends today'], collect(JobTrips::proposals('2026-27'))->pluck('title')->all());

        $this->post('/mileage/from-jobs', ['year' => '2026-27', 'vehicle_id' => $this->car->id, 'job_ids' => [$future->id, $running->id, $rigged->id, $today->id, $past->id]])
            ->assertSessionHas('success');
        $this->assertSame(0, MileageTrip::query()->whereDate('trip_on', '>', '2026-11-23')->count(), 'No trip dated after today');
        $this->assertSame([$today->id, $past->id], MileageTrip::query()->orderBy('work_job_id')->pluck('work_job_id')->sort()->values()->all());

        // Once the de-rig is done, the job is offered with both visits.
        $this->travelTo('2026-11-28 09:00:00');
        $p = collect(JobTrips::proposals('2026-27'))->firstWhere('job_id', $rigged->id);
        $this->assertSame(['2026-11-20', '2026-11-27'], $p['trip_dates']);
    }

    /** R21 (a): a train linked to the day before's job says nothing about how you got to this one. */
    public function test_another_jobs_train_does_not_hide_a_job_you_drove_to(): void
    {
        $leeds = WorkJob::query()->create(['title' => 'Leeds by train', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'postcode' => 'LS2 8BY', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'rail', 'provider' => 'LNER', 'status' => 'booked', 'starts_on' => '2025-08-01', 'source' => 'manual', 'evidence' => [],
            'work_job_id' => $leeds->id, 'job_linked_by' => 'you', 'decision' => 'business']);
        $brum = WorkJob::query()->create(['title' => 'Birmingham drive', 'starts_on' => '2025-08-02', 'ends_on' => '2025-08-04', 'postcode' => 'B5 4BU', 'status' => 'done']);

        $rows = collect(JobTrips::proposals('2025-26'));
        $this->assertNull($rows->firstWhere('job_id', $leeds->id), 'Still left out: you went by train');
        $p = $rows->firstWhere('job_id', $brum->id);
        $this->assertNotNull($p, 'The job you drove to is offered');
        $this->assertSame(3, $p['trips']);
    }

    /** R21 (b): a stay you marked personal, or one linked to another job, isn't a stay for this job. */
    public function test_personal_stays_and_other_jobs_stays_do_not_count_as_staying_nearby(): void
    {
        $excel = WorkJob::query()->create(['title' => 'ExCeL 3 days', 'starts_on' => '2025-09-10', 'ends_on' => '2025-09-12', 'postcode' => 'E16 1XL', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Edinburgh Hotel', 'status' => 'booked', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-10',
            'source' => 'manual', 'evidence' => [], 'decision' => 'personal']);
        $leeds = WorkJob::query()->create(['title' => 'Leeds 2 days', 'starts_on' => '2025-09-14', 'ends_on' => '2025-09-15', 'postcode' => 'LS2 8BY', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Leeds Hotel', 'status' => 'booked', 'starts_on' => '2025-09-13', 'ends_on' => '2025-09-16',
            'source' => 'manual', 'evidence' => [], 'work_job_id' => $leeds->id, 'job_linked_by' => 'rules']);
        // A train you took for yourself the day before doesn't mean you went to the job by train.
        $drive = WorkJob::query()->create(['title' => 'Manchester drive', 'starts_on' => '2025-10-20', 'ends_on' => '2025-10-20', 'postcode' => 'M2 3GX', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'rail', 'provider' => 'Avanti', 'status' => 'booked', 'starts_on' => '2025-10-19', 'source' => 'manual', 'evidence' => [], 'decision' => 'personal']);

        $rows = collect(JobTrips::proposals('2025-26'));
        $p = $rows->firstWhere('job_id', $excel->id);
        $this->assertSame(3, $p['trips']);
        $this->assertStringNotContainsString('stayed', $p['why']);
        $this->assertSame(1, $rows->firstWhere('job_id', $leeds->id)['trips'], 'Its own stay still counts');
        $this->assertNotNull($rows->firstWhere('job_id', $drive->id));
    }

    /** R21 (follow-up): one hotel covering back-to-back jobs is linked to only one of them, but you stayed there for both. */
    public function test_a_stay_shared_by_back_to_back_jobs_counts_for_both(): void
    {
        $a = WorkJob::query()->create(['title' => 'Show A build', 'starts_on' => '2025-09-10', 'ends_on' => '2025-09-12', 'postcode' => 'M2 3GX', 'status' => 'done']);
        $b = WorkJob::query()->create(['title' => 'Show B', 'starts_on' => '2025-09-13', 'ends_on' => '2025-09-16', 'postcode' => 'M2 3GX', 'status' => 'done']);
        $hotel = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Manchester Hotel', 'status' => 'booked', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-17',
            'source' => 'manual', 'evidence' => []]);
        app(TravelMatcher::class)->suggest($hotel);
        $linked = (int) $hotel->fresh()->work_job_id;
        $this->assertContains($linked, [$a->id, $b->id], 'Linked to one of the two jobs');

        $rows = collect(JobTrips::proposals('2025-26'));
        foreach ([$a, $b] as $job) {
            $p = $rows->firstWhere('job_id', $job->id);
            $this->assertSame(1, $p['trips'], $job->title);
            $this->assertSame('One return trip; you stayed nearby', $p['why'], $job->title);
        }
    }

    /** R21 (follow-up): checking in to the next job's hotel on the evening of this job's last day isn't staying near this job. */
    public function test_the_next_jobs_hotel_from_the_evening_of_the_last_day_does_not_count(): void
    {
        $london = WorkJob::query()->create(['title' => 'ExCeL 3 days', 'starts_on' => '2025-09-10', 'ends_on' => '2025-09-12', 'postcode' => 'E16 1XL', 'status' => 'done']);
        $manchester = WorkJob::query()->create(['title' => 'Manchester 3 days', 'starts_on' => '2025-09-13', 'ends_on' => '2025-09-15', 'postcode' => 'M2 3GX', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Manchester Hotel', 'status' => 'booked', 'starts_on' => '2025-09-12', 'ends_on' => '2025-09-16',
            'source' => 'manual', 'evidence' => [], 'work_job_id' => $manchester->id, 'job_linked_by' => 'rules']);

        $rows = collect(JobTrips::proposals('2025-26'));
        $p = $rows->firstWhere('job_id', $london->id);
        $this->assertSame(3, $p['trips']);
        $this->assertSame('3 days without a stay: a return trip each day', $p['why']);
        $this->assertSame(1, $rows->firstWhere('job_id', $manchester->id)['trips']);
    }

    /** R21 (c): an unlinked stay that began days before the job and runs through it is a stay nearby. */
    public function test_an_unlinked_stay_that_covers_the_job_counts_even_if_it_began_days_before(): void
    {
        $job = WorkJob::query()->create(['title' => 'Birmingham 3 days', 'starts_on' => '2025-10-10', 'ends_on' => '2025-10-12', 'postcode' => 'B5 4BU', 'status' => 'done']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Hub Hotel', 'status' => 'booked', 'starts_on' => '2025-10-07', 'ends_on' => '2025-10-13', 'source' => 'manual', 'evidence' => []]);

        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertSame(1, $p['trips']);
        $this->assertSame('One return trip; you stayed nearby', $p['why']);
    }

    /** R21: for a rig and a de-rig days apart, an unlinked train in the gap between them isn't the journey to the job. */
    public function test_a_train_between_a_rig_and_a_derig_does_not_hide_the_job(): void
    {
        $job = WorkJob::query()->create(['title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'postcode' => 'M2 3GX', 'status' => 'done']);
        JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => '2025-11-07', 'kind' => 'derig', 'source' => 'you']);
        $job->update(['ends_on' => '2025-11-07']);
        TravelItem::query()->create(['kind' => 'rail', 'provider' => 'LNER', 'status' => 'booked', 'starts_on' => '2025-11-05', 'source' => 'manual', 'evidence' => []]);

        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertNotNull($p);
        $this->assertSame(['2025-11-03', '2025-11-07'], $p['trip_dates']);
    }

    /** R20: the trips go on the vehicle you chose, and the message and audit log say which. */
    public function test_trips_go_on_the_chosen_vehicle_and_say_which(): void
    {
        Vehicle::query()->create(['name' => 'Motorbike', 'type' => 'motorcycle', 'method' => 'simplified']);
        $van = Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $job = WorkJob::query()->create(['title' => 'Manchester show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-10', 'postcode' => 'M2 3GX', 'status' => 'done']);

        $this->post('/mileage/from-jobs', ['year' => '2025-26', 'vehicle_id' => $van->id, 'job_ids' => [$job->id]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Van'));
        $this->assertSame($van->id, MileageTrip::query()->sole()->vehicle_id);
        $entry = AuditEvent::query()->where('action', 'mileage.from_jobs')->sole();
        $this->assertSame($van->id, $entry->after['vehicle_id']);
    }

    /** R49: GOV.UK says you get an estimate of your tax bill after each quarterly update; only payment doesn't happen. */
    public function test_the_tax_guide_says_an_update_gives_you_an_estimate_but_no_payment(): void
    {
        $rules = implode(' ', TaxGuide::topic('mtd_itsa')['rules']);
        $this->assertStringNotContainsString('No tax is calculated', $rules);
        $this->assertStringContainsString('No tax is paid with an update', $rules);
        $this->assertStringContainsString('estimate of your tax bill', $rules);
    }
}
