<?php

namespace Tests\Feature\MailCalendar;

use App\Domain\Jobs\JobGatherer;
use App\Jobs\GatherForJob;
use App\Jobs\GatherFromGmail;
use App\Models\Client;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Mail and calendar batch 1: a job's PO, dates, client or venue changing searches again (Gmail too, never Sent mail). */
class JobEditSearchTest extends TestCase
{
    use RefreshDatabase;

    private WorkJob $job;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $client = Client::query()->create(['name' => 'Acme Events']);
        $this->job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'starts_on' => '2026-12-03', 'ends_on' => '2026-12-04',
            'location' => 'Acme Arena, Leeds', 'postcode' => 'LS1 1AA', 'po_number' => 'PO-4471', 'fee_pence' => 70000, 'status' => 'booked']);
    }

    private function form(array $changes = []): array
    {
        return $changes + ['title' => 'Acme Awards', 'client_id' => $this->job->client_id, 'starts_on' => '2026-12-03', 'ends_on' => '2026-12-04',
            'location' => 'Acme Arena, Leeds', 'postcode' => 'LS1 1AA', 'po_number' => 'PO-4471', 'fee' => '700', 'status' => 'booked', 'notes' => null];
    }

    public function test_other_edits_search_nothing(): void
    {
        Queue::fake();
        foreach ([['fee' => '750'], ['notes' => 'Bring the long XLRs'], ['start_time' => '08:00', 'end_time' => '18:00'], ['title' => 'Acme Awards 2026'],
            ['postcode' => 'ls1 1aa'], ['po_number' => ' PO-4471 ']] as $change) {
            $this->patch('/jobs/'.$this->job->id, $this->form($change))->assertSessionHas('success', 'Saved.');
        }
        Queue::assertNotPushed(GatherForJob::class);
    }

    public function test_a_new_po_dates_client_or_venue_searches_again_once(): void
    {
        $other = Client::query()->create(['name' => 'Smith Productions']);
        foreach ([['po_number' => 'PO-4472'], ['ends_on' => '2026-12-05'], ['client_id' => $other->id], ['location' => 'Town Hall, Leeds'], ['postcode' => 'LS2 8AA']] as $change) {
            Queue::fake();
            $this->patch('/jobs/'.$this->job->id, $this->form($change))->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Saved. Looking again'));
            Queue::assertPushed(GatherForJob::class, fn (GatherForJob $j) => $j->jobId === $this->job->id);
            $this->job->refresh();
            // The same change saved again: no new search.
            $this->patch('/jobs/'.$this->job->id, $this->form(array_merge($change, ['client_id' => $this->job->client_id])))->assertSessionHas('success', 'Saved.');
            Queue::assertPushed(GatherForJob::class, 1);
        }
    }

    public function test_saving_twice_before_the_search_runs_searches_once(): void
    {
        Queue::fake();
        $this->patch('/jobs/'.$this->job->id, $this->form(['po_number' => 'PO-1']));
        $this->patch('/jobs/'.$this->job->id, $this->form(['po_number' => 'PO-2']));
        Queue::assertPushed(GatherForJob::class, 1);
    }

    public function test_a_cancelled_job_is_not_searched(): void
    {
        Queue::fake();
        $this->patch('/jobs/'.$this->job->id, $this->form(['status' => 'cancelled', 'po_number' => 'PO-9']))->assertSessionHas('success', 'Saved.');
        Queue::assertNotPushed(GatherForJob::class);
    }

    public function test_adding_or_removing_a_day_searches_again_and_changing_its_times_does_not(): void
    {
        Queue::fake();
        Setting::put('google_client_id', 'client-id');
        Setting::put('google_client_secret', 'client-secret');
        Setting::put('gmail_refresh_token', 'refresh-token');

        $this->post('/jobs/'.$this->job->id.'/days', ['date' => '2026-12-07', 'kind' => 'derig'])->assertSessionHas('success');
        Queue::assertPushed(GatherFromGmail::class, fn (GatherFromGmail $j) => $j->jobId === $this->job->id);

        $day = JobDay::query()->where('work_job_id', $this->job->id)->where('on_date', 'like', '2026-12-07%')->sole();
        $this->patch('/job-days/'.$day->id, ['kind' => 'derig', 'start_time' => '09:00', 'end_time' => '13:00'])->assertSessionHas('success', 'Saved.');
        Queue::assertNotPushed(GatherForJob::class);

        $this->delete('/job-days/'.$day->id)->assertSessionHas('success', fn ($m) => str_contains($m, 'Looking again'));
        Queue::assertPushed(GatherForJob::class, fn (GatherForJob $j) => $j->jobId === $this->job->id);
    }

    public function test_the_gmail_search_leaves_out_sent_mail(): void
    {
        Setting::put('google_client_id', 'client-id');
        Setting::put('google_client_secret', 'client-secret');
        Setting::put('gmail_refresh_token', 'refresh-token');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'access-1', 'expires_in' => 3600]),
            'gmail.googleapis.com/*' => Http::response(['messages' => [], 'resultSizeEstimate' => 0]),
        ]);

        $query = GatherFromGmail::query($this->job->fresh('client'));
        $this->assertStringEndsWith(' -in:sent', $query);
        $this->assertSame(1, substr_count($query, '-in:sent'));

        GatherFromGmail::dispatchSync($this->job->id);

        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'gmail.googleapis.com') && str_contains(urldecode($r->url()), '-in:sent'));
        $this->assertSame('done', $this->job->fresh()->gather_state['gmail']);
    }

    public function test_the_saved_message_mentions_gmail_only_when_gmail_will_be_searched(): void
    {
        Setting::put('google_client_id', 'client-id');
        Setting::put('google_client_secret', 'client-secret');
        Setting::put('gmail_refresh_token', 'refresh-token');
        Queue::fake();

        $this->patch('/jobs/'.$this->job->id, $this->form(['po_number' => 'PO-4472']))
            ->assertSessionHas('success', 'Saved. Looking again for what belongs to it, Gmail included.');

        // Nothing to search Gmail by: no client, no PO, a place that could be anywhere and a short title.
        $rig = WorkJob::query()->create(['title' => 'Rig', 'starts_on' => '2026-12-03', 'ends_on' => '2026-12-03', 'location' => 'London', 'status' => 'booked']);
        $this->patch('/jobs/'.$rig->id, ['title' => 'Rig', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-04', 'location' => 'London', 'status' => 'booked'])
            ->assertSessionHas('success', 'Saved. Looking again for what belongs to it. Gmail isn’t searched yet: add the client, the PO number or the venue to search it by.');
        Queue::assertPushed(GatherForJob::class, fn (GatherForJob $j) => $j->jobId === $rig->id);
        Queue::fake();
        (new GatherForJob($rig->id))->handle(app(JobGatherer::class));
        Queue::assertNotPushed(GatherFromGmail::class);
    }
}
