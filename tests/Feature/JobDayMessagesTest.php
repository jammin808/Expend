<?php

namespace Tests\Feature;

use App\Models\JobDay;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A job day that can't be saved says why in plain words: the job page shows the message as it is. */
class JobDayMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_refused_days_say_why_in_plain_words(): void
    {
        $this->actingAs(User::factory()->create());
        $job = WorkJob::query()->create(['title' => 'Product launch', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10', 'status' => 'booked']);
        $day = JobDay::query()->where('work_job_id', $job->id)->firstOrFail();
        $this->postJson("/jobs/{$job->id}/days", ['date' => '', 'kind' => 'gig', 'start_time' => '7.30'])
            ->assertJsonValidationErrors([
                'date' => 'Pick the day to add.',
                'kind' => 'Pick what kind of day it is.',
                'start_time' => 'The start time needs hours and minutes, like 07:30.',
            ]);

        $this->patchJson("/job-days/{$day->id}", ['kind' => 'work', 'end_time' => '25:00', 'note' => str_repeat('x', 201)])
            ->assertJsonValidationErrors([
                'end_time' => 'The finish time needs hours and minutes, like 18:00.',
                'note' => 'A note on a day is 200 characters at most.',
            ]);
        $this->assertNull($day->fresh()->end_time);
    }
}
