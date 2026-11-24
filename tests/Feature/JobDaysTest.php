<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Mileage\JobTrips;
use App\Domain\Travel\TravelDays;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\ImportBatch;
use App\Models\JobDay;
use App\Models\JobFind;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A job covers the days it really covers: a three-day show, or a rig day and a de-rig days later. */
class JobDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
    }

    private function dates(WorkJob $job): array
    {
        return $job->fresh()->workDates();
    }

    private function event(string $summary, string $start, string $end): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => false, 'summary' => $summary, 'location' => 'Hall']);
    }

    public function test_a_new_job_covers_its_dates_and_follows_changes_to_them(): void
    {
        $job = WorkJob::query()->create(['title' => 'Three-day show', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-05', 'status' => 'booked']);
        $this->assertSame(['2025-11-03', '2025-11-04', '2025-11-05'], $this->dates($job));

        $job->update(['ends_on' => '2025-11-06']);
        $this->assertSame(['2025-11-03', '2025-11-04', '2025-11-05', '2025-11-06'], $this->dates($job), 'Days without a break stay without one');
    }

    public function test_a_rig_day_and_a_de_rig_days_later_are_one_job(): void
    {
        $rig = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 20:00:00');
        $derig = $this->event('De-rig: Winter Expo', '2025-11-07 07:00:00', '2025-11-07 13:00:00');
        $this->post('/calendar/job', ['key' => $rig->key(), 'date' => '2025-11-03', 'title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'then' => 'job']);
        $job = WorkJob::query()->sole();
        $this->assertSame('rig', JobDay::query()->value('kind'));

        $this->post('/calendar/link-job', ['key' => $derig->key(), 'date' => '2025-11-07', 'job_id' => $job->id])->assertRedirect('/jobs/'.$job->id);
        $this->assertSame(['2025-11-03', '2025-11-07'], $this->dates($job));
        $this->assertSame(['2025-11-03', '2025-11-07'], [$job->fresh()->starts_on->format('Y-m-d'), $job->fresh()->ends_on->format('Y-m-d')]);
        $this->assertSame(['rig', 'derig'], JobDay::query()->orderBy('on_date')->pluck('kind')->all());
        $this->assertSame('07:00', JobDay::query()->whereDate('on_date', '2025-11-07')->value('start_time'));

        // The days in between aren't working days; the day before the de-rig is a possible travel day.
        $days = app(TravelDays::class);
        $this->assertSame('business', $days->day('2025-11-07')['verdict']);
        $this->assertSame('personal', $days->day('2025-11-05')['verdict']);
        $this->assertSame('review', $days->day('2025-11-06')['verdict']);
        $this->assertSame('personal', $days->night('2025-11-04')['verdict']);

        // Unlinking the de-rig takes its day off again.
        app(JobFinder::class)->unlinkCalendar($derig->fresh() ?? $derig, $job->id);
        $this->assertSame(['2025-11-03'], $this->dates($job));
    }

    public function test_nights_between_working_days_are_business_and_costs_are_looked_at_only_on_its_days(): void
    {
        $job = WorkJob::query()->create(['title' => 'Conference', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-05', 'status' => 'booked']);
        $this->assertSame('business', app(TravelDays::class)->night('2025-11-03')['verdict']);

        JobDay::query()->whereDate('on_date', '2025-11-04')->delete(); // not needed on the middle day after all
        $this->assertNotSame('business', app(TravelDays::class)->night('2025-11-03')['verdict'], 'No longer between two working days');

        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $make = fn (string $date, string $what) => BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => '2025-26',
            'description' => $what, 'counterparty' => $what, 'merchant_key' => $what, 'amount_pence' => -1500, 'raw' => [], 'fingerprint' => md5($date.$what), 'occurrence' => 1]);
        $onDay = $make('2025-11-05', 'CABLE SHOP');
        $farAway = $make('2025-11-12', 'GROCER');
        app(JobGatherer::class)->gather($job->fresh(), false);
        $this->assertTrue(JobFind::query()->where('type', 'payment')->where('ref', (string) $onDay->id)->exists());
        $this->assertFalse(JobFind::query()->where('type', 'payment')->where('ref', (string) $farAway->id)->exists());
    }

    public function test_mileage_is_a_return_trip_for_each_day_worked(): void
    {
        Setting::put('home_postcode', 'LS1 4AP');
        foreach (['LS1 4AP' => [53.7965, -1.5478], 'M1 1AA' => [53.4808, -2.2426]] as $pc => [$lat, $lng]) {
            \Illuminate\Support\Facades\DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
        $job = WorkJob::query()->create(['title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'postcode' => 'M1 1AA', 'status' => 'done']);
        JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => '2025-11-07', 'kind' => 'derig', 'source' => 'you']);
        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertSame(['2025-11-03', '2025-11-07'], $p['trip_dates']);
        $this->assertSame(2, $p['trips']);
    }

    public function test_days_can_be_added_changed_and_removed_on_the_job(): void
    {
        $job = WorkJob::query()->create(['title' => 'Show', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'status' => 'booked']);
        $this->post("/jobs/{$job->id}/days", ['date' => '2025-11-01', 'kind' => 'rig', 'start_time' => '09:00'])->assertSessionHas('success');
        $this->assertSame(['2025-11-01', '2025-11-03'], $this->dates($job));
        $this->assertSame('2025-11-01', $job->fresh()->starts_on->format('Y-m-d'));

        $rig = JobDay::query()->whereDate('on_date', '2025-11-01')->sole();
        $this->patch("/job-days/{$rig->id}", ['kind' => 'travel'])->assertSessionHas('success');
        $this->assertSame('travel', $rig->fresh()->kind);

        $this->delete("/job-days/{$rig->id}")->assertSessionHas('success');
        $this->assertSame(['2025-11-03'], $this->dates($job));
        $last = JobDay::query()->sole();
        $this->delete("/job-days/{$last->id}")->assertSessionHas('error');

        $this->get("/jobs/{$job->id}")->assertOk()->assertInertia(fn ($p) => $p->has('days', 1)->where('days.0.kind', 'work'));
    }
}
