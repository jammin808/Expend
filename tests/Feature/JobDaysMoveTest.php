<?php

namespace Tests\Feature;

use App\Domain\Jobs\JobCreator;
use App\Models\AuditEvent;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Moving a whole job (its first and last dates by the same number of days: postponed, or brought forward) moves every
 * day with it, each keeping its kind, times and note: a one-day job, days without a break, and separate days alike.
 */
class JobDaysMoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
    }

    /** @return list<array{0:string,1:string,2:?string,3:?string,4:?string,5:string}> date, kind, start, finish, note, source */
    private function days(WorkJob $job): array
    {
        return $job->days()->get()->map(fn (JobDay $d) => [$d->on_date->format('Y-m-d'), $d->kind, $d->start_time ? substr($d->start_time, 0, 5) : null,
            $d->end_time ? substr($d->end_time, 0, 5) : null, $d->note, $d->source])->all();
    }

    private function job(array $dates): WorkJob
    {
        return app(JobCreator::class)->create(['title' => 'Acme Awards', 'dates' => $dates], 'you', [], null, false);
    }

    public function test_a_one_day_job_moved_to_another_date_keeps_its_kind_times_and_note(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '08:00', 'end_time' => '18:00', 'note' => 'Bring IEMs']]);
        $before = AuditEvent::query()->count();

        $this->patch('/jobs/'.$job->id, ['title' => 'Acme Awards', 'starts_on' => '2025-11-05', 'ends_on' => '2025-11-05'])->assertSessionHas('success');

        $this->assertSame([['2025-11-05', 'rig', '08:00', '18:00', 'Bring IEMs', 'you']], $this->days($job));
        $change = AuditEvent::query()->where('id', '>', $before)->where('action', 'job.days_changed')->sole();
        $this->assertSame(['2025-11-03'], $change->after['removed']);
        $this->assertSame(['2025-11-05'], $change->after['added']);
    }

    public function test_days_without_a_break_moved_together_keep_what_each_one_is(): void
    {
        $job = $this->job([
            ['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '07:00'],
            ['date' => '2025-11-04', 'kind' => 'show', 'note' => 'Doors 19:00'],
            ['date' => '2025-11-05', 'kind' => 'derig', 'end_time' => '02:00'],
        ]);
        $job->update(['starts_on' => '2025-11-10', 'ends_on' => '2025-11-12']);

        $this->assertSame([
            ['2025-11-10', 'rig', '07:00', null, null, 'you'],
            ['2025-11-11', 'show', null, null, 'Doors 19:00', 'you'],
            ['2025-11-12', 'derig', null, '02:00', null, 'you'],
        ], $this->days($job));

        // Brought forward a day: the nearest day moves first, so none lands on one that hasn't moved yet.
        $job->fresh()->update(['starts_on' => '2025-11-09', 'ends_on' => '2025-11-11']);
        $this->assertSame(['rig', 'show', 'derig'], array_column($this->days($job), 1));
        $this->assertSame(['2025-11-09', '2025-11-10', '2025-11-11'], $job->fresh()->workDates());
    }

    public function test_separate_days_moved_together_stay_separate(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '08:00'], ['date' => '2025-11-07', 'kind' => 'derig', 'end_time' => '13:00']]);
        $job->update(['starts_on' => '2025-11-01', 'ends_on' => '2025-11-05']);

        $this->assertSame([['2025-11-01', 'rig', '08:00', null, null, 'you'], ['2025-11-05', 'derig', null, '13:00', null, 'you']], $this->days($job));
    }

    public function test_a_longer_job_still_gains_the_days_between_and_keeps_its_first(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '08:00']]);
        $job->update(['ends_on' => '2025-11-05']);

        $this->assertSame([['2025-11-03', 'rig', '08:00', null, null, 'you'], ['2025-11-04', 'work', null, null, null, 'range'], ['2025-11-05', 'work', null, null, null, 'range']], $this->days($job));
    }
}
