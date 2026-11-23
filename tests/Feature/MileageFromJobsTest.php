<?php

namespace Tests\Feature;

use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MileageFromJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_trips_are_proposed_for_jobs_driven_to_and_added_in_one_go(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('home_postcode', 'SW1A 1AA');
        foreach (['SW1A 1AA' => [51.501, -0.1416], 'M2 3GX' => [53.4767, -2.2469], 'E16 1XL' => [51.5085, 0.0295], 'LS2 8BY' => [53.8035, -1.5487]] as $pc => [$lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
        $car = Vehicle::query()->create(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified']);
        $manchester = WorkJob::query()->create(['title' => 'Manchester show', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-11', 'postcode' => 'M2 3GX', 'location' => 'Manchester Central']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-06-10', 'ends_on' => '2025-06-12', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $manchester->id]);
        $excel = WorkJob::query()->create(['title' => 'ExCeL build', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'E16 1XL']);
        $leeds = WorkJob::query()->create(['title' => 'Leeds by train', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'postcode' => 'LS2 8BY']);
        TravelItem::query()->create(['kind' => 'rail', 'provider' => 'LNER', 'status' => 'booked', 'starts_on' => '2025-08-01', 'source' => 'manual', 'evidence' => []]);

        $this->get('/mileage?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p
            ->where('fromJobs', fn ($rows) => collect($rows)->pluck('title')->all() === ['Manchester show', 'ExCeL build'])
            ->where('fromJobs.0.trips', 1)->where('fromJobs.1.trips', 3));

        $this->post('/mileage/from-jobs', ['year' => '2025-26', 'vehicle_id' => $car->id, 'job_ids' => [$manchester->id, $excel->id, $leeds->id]])->assertSessionHas('success');
        $this->assertSame(1, MileageTrip::query()->where('work_job_id', $manchester->id)->count(), 'One return trip when you stayed');
        $this->assertSame(3, MileageTrip::query()->where('work_job_id', $excel->id)->count(), 'A return trip each day without a stay');
        $this->assertSame(0, MileageTrip::query()->where('work_job_id', $leeds->id)->count(), 'Not for a job reached by train');
        $trip = MileageTrip::query()->where('work_job_id', $manchester->id)->sole();
        $this->assertEqualsWithDelta(2 * 1.25 * 163, (float) $trip->miles, 12, 'About 2 × 1.25 × the straight-line distance');
        $this->assertStringContainsString('estimated', $trip->purpose);

        $this->get('/mileage?year=2025-26')->assertInertia(fn ($p) => $p->where('fromJobs', []));
    }
}
