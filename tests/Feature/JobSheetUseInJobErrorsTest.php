<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When "Use in job" on the job sheet can't save, the page shows the first error as a message. It says what's wrong in
 * plain words (not "must match the format H:i"), and the job stays as it was. A done job stays done.
 */
class JobSheetUseInJobErrorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function job(array $extra = []): WorkJob
    {
        return WorkJob::query()->create($extra + ['title' => 'Product launch', 'client_id' => Client::query()->create(['name' => 'Acme Events'])->id,
            'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10', 'start_time' => '08:00', 'end_time' => '18:00', 'location' => 'Old venue',
            'postcode' => 'M1 1AA', 'fee_pence' => 45000, 'status' => 'booked', 'po_number' => 'PO-4471']);
    }

    /** A call sheet linked to the job with one checked detail on it. */
    private function fact(WorkJob $job, string $field, string $value, array $extra = []): void
    {
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'call-sheet.pdf', 'mime' => 'application/pdf', 'size' => 1,
            'sha256' => hash('sha256', $field.$value.$job->id), 'stored_path' => 'x', 'kind' => 'call_sheet', 'status' => 'extracted',
            'received_at' => '2025-05-01 09:00:00', 'work_job_id' => $job->id]);
        DocumentFact::query()->create($extra + ['document_id' => $doc->id, 'work_job_id' => $job->id, 'field' => $field, 'label' => ucfirst($field),
            'value' => $value, 'quote' => $value, 'verified' => true]);
    }

    /**
     * Presses "Use in job" on the job's only fact as resources/js/components/job-sheet.tsx does: reads the job page's
     * props, then sends the job's title and dates plus the one field the fact fills in, as an Inertia JSON PATCH.
     */
    private function useInJob(WorkJob $job)
    {
        $page = $this->get("/jobs/{$job->id}")->assertInertia(fn ($p) => $p->component('jobs/show', false))->viewData('page');
        $props = json_decode(json_encode($page['props']), true);
        $f = $props['facts'][0];
        $this->assertTrue($f['verified'], 'The "Use in job" button shows');
        $field = ['venue' => 'location', 'postcode' => 'postcode', 'call_time' => 'start_time', 'start_time' => 'start_time',
            'finish_time' => 'end_time', 'start_date' => 'starts_on', 'end_date' => 'ends_on'][$f['field']];
        $value = match ($field) {
            'start_time', 'end_time' => $f['at_time'] ?? $f['value'],
            'starts_on', 'ends_on' => $f['on_date'] ?? $f['value'],
            default => $f['value'],
        };
        $body = ['title' => $props['job']['title'], 'starts_on' => $props['job']['starts_on'], 'ends_on' => $props['job']['ends_on'], $field => $value];

        return $this->json('PATCH', "/jobs/{$job->id}", $body, ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']);
    }

    /** The first error, as the job sheet's toast shows it. */
    private function firstError(): ?string
    {
        return session('errors')?->getBag('default')->first();
    }

    public function test_a_start_date_after_the_last_day_says_so_plainly(): void
    {
        $job = $this->job();
        $this->fact($job, 'start_date', '11 May 2025', ['on_date' => '2025-05-11']);

        $this->useInJob($job)->assertSessionHasErrors('ends_on');

        $this->assertSame('The job can’t end before it starts.', $this->firstError());
        $this->assertSame(['2025-05-10', 'PO-4471'], [$job->fresh()->starts_on->format('Y-m-d'), $job->fresh()->po_number]);
    }

    public function test_a_call_time_that_is_not_a_time_says_so_plainly(): void
    {
        $job = $this->job();
        $this->fact($job, 'call_time', '7am call'); // the AI read no time from it

        $this->useInJob($job)->assertSessionHasErrors('start_time');

        $this->assertSame('The start time needs hours and minutes, like 07:30.', $this->firstError());
        $this->assertSame('08:00', substr((string) $job->fresh()->start_time, 0, 5));
    }

    public function test_a_finish_time_that_is_not_a_time_says_so_plainly(): void
    {
        $job = $this->job();
        $this->fact($job, 'finish_time', 'Late finish');

        $this->useInJob($job)->assertSessionHasErrors('end_time');

        $this->assertSame('The finish time needs hours and minutes, like 18:00.', $this->firstError());
    }

    public function test_a_long_postcode_says_so_plainly(): void
    {
        $job = $this->job();
        $this->fact($job, 'postcode', 'M1 1AA (loading bay)');

        $this->useInJob($job)->assertSessionHasErrors('postcode');

        $this->assertSame('A postcode is 10 characters at most, like SE10 0DX.', $this->firstError());
        $this->assertSame('M1 1AA', $job->fresh()->postcode);
    }

    public function test_a_done_job_stays_done(): void
    {
        $job = $this->job(['status' => 'done']);
        $this->fact($job, 'venue', 'Leeds Arena');

        $this->useInJob($job)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(['Leeds Arena', 'done', 'PO-4471', 45000], [$job->fresh()->location, $job->fresh()->status, $job->fresh()->po_number, $job->fresh()->fee_pence]);
    }
}
