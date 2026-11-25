<?php

namespace Tests\Feature\Jobs;

use App\Domain\Ai\AiClient;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobFinder;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Build slice S4: a job's end client (who the work was for, booked through an agency that pays). The job finder counts
 * a source that mentions the end client the way it counts one that mentions the paying client (+3, "mentions Big Prod
 * Co"), once when both have the same name; JobCreator takes the end client by id or by name, and keeps it only when
 * it's someone other than the paying client.
 */
class JobFinderEndClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        Setting::put('job_finder_auto', false);
        $this->app->instance(AiClient::class, new FakeAiClient);
    }

    private function event(string $summary, string $start, string $end): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => false, 'summary' => $summary]);
    }

    private function job(string $title, Client $client, ?Client $end = null): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'end_client_id' => $end?->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'booked']);
    }

    public function test_an_entry_that_names_the_end_client_is_linked_to_that_job(): void
    {
        $agency = Client::query()->create(['name' => 'Crew Co']);
        $other = Client::query()->create(['name' => 'Stage Hands Agency']);
        $prod = Client::query()->create(['name' => 'Big Prod Co']);
        $awards = $this->job('Awards night', $agency, $prod);
        $this->job('Gala dinner', $other);
        $entry = $this->event('Big Prod Co rehearsal', '2025-05-14 09:00:00', '2025-05-14 17:00:00');

        $result = app(JobFinder::class)->run('2025-26', false);

        $this->assertSame(1, $result['linked'], 'Two jobs that day: the end client named in the entry tells them apart');
        $link = CalendarJobLink::query()->where('event_key', $entry->key())->sole();
        $this->assertSame([$awards->id, 'rules'], [$link->work_job_id, $link->linked_by]);
        $this->assertStringContainsString('mentions Big Prod Co', $link->reason);
    }

    public function test_without_the_end_client_the_same_entry_is_a_close_call_left_for_you(): void
    {
        $agency = Client::query()->create(['name' => 'Crew Co']);
        $other = Client::query()->create(['name' => 'Stage Hands Agency']);
        Client::query()->create(['name' => 'Big Prod Co']);
        $this->job('Awards night', $agency);
        $this->job('Gala dinner', $other);
        $entry = $this->event('Big Prod Co rehearsal', '2025-05-14 09:00:00', '2025-05-14 17:00:00');

        app(JobFinder::class)->run('2025-26', false);

        $this->assertNull(CalendarJobLink::query()->where('event_key', $entry->key())->value('work_job_id'));
    }

    public function test_an_end_client_with_the_paying_clients_name_counts_once(): void
    {
        $payer = Client::query()->create(['name' => 'Big Prod Co Ltd']);
        $end = Client::query()->create(['name' => 'Big Prod Co']);
        $job = $this->job('Awards night', $payer, $end);
        $entry = $this->event('Big Prod Co rehearsal', '2025-05-14 09:00:00', '2025-05-14 17:00:00');

        app(JobFinder::class)->run('2025-26', false);

        $link = CalendarJobLink::query()->where('event_key', $entry->key())->sole();
        $this->assertSame($job->id, $link->work_job_id);
        $this->assertSame(1, substr_count($link->reason, 'mentions'), 'The same name isn’t counted twice: '.$link->reason);
    }

    public function test_a_new_job_takes_its_end_client_by_id_or_by_name(): void
    {
        $agency = Client::query()->create(['name' => 'Crew Co']);

        $job = app(JobCreator::class)->create(['title' => 'Awards night', 'client_id' => $agency->id, 'end_client_name' => 'Big Prod Co', 'starts_on' => '2026-03-12'], 'you');
        $prod = Client::query()->where('name', 'Big Prod Co')->sole();
        $this->assertSame([$agency->id, $prod->id], [$job->client_id, $job->end_client_id]);
        $this->assertSame('For a new job, as its end client', AuditEvent::query()->where('action', 'client.created')->where('subject_id', $prod->id)->value('reason'));
        $this->assertSame($prod->id, AuditEvent::query()->where('action', 'job.created')->sole()->after['end_client_id']);

        $again = app(JobCreator::class)->create(['title' => 'Awards night 2', 'client_id' => $agency->id, 'end_client_id' => $prod->id, 'starts_on' => '2026-03-13'], 'you');
        $this->assertSame($prod->id, $again->end_client_id);

        $same = app(JobCreator::class)->create(['title' => 'Direct booking', 'client_id' => $prod->id, 'end_client_id' => $prod->id, 'starts_on' => '2026-03-14'], 'you');
        $this->assertNull($same->end_client_id, 'An end client who is the paying client isn’t kept');

        $merged = Client::query()->create(['name' => 'BIG PROD CO LTD', 'merged_into_id' => $prod->id]);
        $this->assertSame($prod->id, JobCreator::check(['title' => 'Gala', 'starts_on' => '2026-03-15', 'end_client_id' => $merged->id])['end_client_id'], 'A merged client is the one it went into');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('That end client isn’t in your list.');
        app(JobCreator::class)->create(['title' => 'Gala', 'starts_on' => '2026-03-15', 'end_client_id' => 999], 'you');
    }
}
