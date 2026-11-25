<?php

namespace Tests\Feature;

use App\Domain\Jobs\PoStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentFact;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Use in job" on the job sheet changes the one detail it was pressed on. The job's PO number, its "No PO needed"
 * tick and everything else the owner typed stay as they were.
 */
class JobSheetUseInJobTest extends TestCase
{
    use RefreshDatabase;

    /** Which job field a fact fills in, as in resources/js/components/job-sheet.tsx. */
    private const JOB_FIELD = ['venue' => 'location', 'postcode' => 'postcode', 'call_time' => 'start_time', 'start_time' => 'start_time',
        'finish_time' => 'end_time', 'start_date' => 'starts_on', 'end_date' => 'ends_on'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function job(array $extra = []): WorkJob
    {
        return WorkJob::query()->create($extra + ['title' => 'Product launch', 'client_id' => Client::query()->create(['name' => 'Acme Events'])->id,
            'starts_on' => '2025-05-10', 'ends_on' => '2025-05-11', 'start_time' => '08:00', 'end_time' => '18:00', 'location' => 'Old venue',
            'postcode' => 'M1 1AA', 'fee_pence' => 45000, 'status' => 'booked', 'notes' => 'Bring the radios']);
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

    /** The job page's props, as the browser gets them. */
    private function props(WorkJob $job): array
    {
        $page = $this->get("/jobs/{$job->id}")->assertInertia(fn ($p) => $p->component('jobs/show', false))->viewData('page');

        return json_decode(json_encode($page['props']), true);
    }

    /** The value applyFact() puts in the job field. */
    private function value(array $fact): string
    {
        $field = self::JOB_FIELD[$fact['field']];

        return match ($field) {
            'start_time', 'end_time' => $fact['at_time'] ?? $fact['value'],
            'starts_on', 'ends_on' => $fact['on_date'] ?? $fact['value'],
            default => $fact['value'],
        };
    }

    /** Sends the PATCH as Inertia's router.patch does: JSON, with the Inertia headers. */
    private function patchAsPage(WorkJob $job, array $body)
    {
        return $this->json('PATCH', "/jobs/{$job->id}", $body, ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']);
    }

    /** The body the live release's job sheet sent: the job's fields without its PO number or "No PO needed" tick. */
    private function oldBody(array $job, array $fact): array
    {
        return [
            'title' => $job['title'], 'client_id' => $job['client_id'], 'starts_on' => $job['starts_on'], 'ends_on' => $job['ends_on'],
            'start_time' => $job['start_time'], 'end_time' => $job['end_time'], 'location' => $job['location'], 'postcode' => $job['postcode'],
            'fee' => $job['fee_pence'] ? number_format($job['fee_pence'] / 100, 2, '.', '') : '', 'status' => $job['status'], 'notes' => $job['notes'],
            self::JOB_FIELD[$fact['field']] => $this->value($fact),
        ];
    }

    /** The body the job sheet sends now: what the job needs to save, and the one field the fact fills in. */
    private function body(array $job, array $fact): array
    {
        return ['title' => $job['title'], 'starts_on' => $job['starts_on'], 'ends_on' => $job['ends_on'], self::JOB_FIELD[$fact['field']] => $this->value($fact)];
    }

    public function test_using_a_fact_keeps_the_po_number_and_the_no_po_needed_tick(): void
    {
        $withPo = $this->job(['po_number' => 'PO-4471']);
        $noPo = $this->job(['title' => 'Awards dinner', 'po_not_required' => true]);

        foreach ([$withPo, $noPo] as $job) {
            $this->fact($job, 'venue', 'Leeds Arena');
            $props = $this->props($job);
            $fact = collect($props['facts'])->firstWhere('field', 'venue');
            $this->assertTrue($fact['verified'], 'The "Use in job" button shows');
            $this->assertFalse($props['job']['missing']);

            // Both what the live page sent and what it sends now.
            foreach ([$this->oldBody($props['job'], $fact), $this->body($props['job'], $fact)] as $body) {
                $this->patchAsPage($job, $body)->assertSessionHasNoErrors()->assertSessionHas('success');
            }
        }

        $this->assertSame(['Leeds Arena', 'PO-4471', false], [$withPo->fresh()->location, $withPo->fresh()->po_number, $withPo->fresh()->po_not_required]);
        $this->assertSame(['Leeds Arena', null, true], [$noPo->fresh()->location, $noPo->fresh()->po_number, $noPo->fresh()->po_not_required]);
        $po = PoStatus::forJobs([$withPo->fresh(), $noPo->fresh()]);
        $this->assertFalse($po[$withPo->id]['missing'], 'Not back in the PO chase');
        $this->assertFalse($po[$noPo->id]['missing'], 'Not back in the PO chase');

        $audit = AuditEvent::query()->where('action', 'job.updated')->where('subject_id', $withPo->id)->orderBy('id')->first();
        $this->assertSame(['Old venue', 'Leeds Arena'], [$audit->before['location'], $audit->after['location']]);
        $this->assertSame(['PO-4471', 'PO-4471'], [$audit->before['po_number'], $audit->after['po_number']]);
    }

    public function test_using_a_fact_changes_only_its_own_field(): void
    {
        $job = $this->job(['po_number' => 'PO-4471']);
        $this->fact($job, 'call_time', 'Call 07:00', ['at_time' => '07:00']);
        $props = $this->props($job);
        $fact = collect($props['facts'])->firstWhere('field', 'call_time');

        $this->patchAsPage($job, $this->body($props['job'], $fact))->assertSessionHasNoErrors()->assertSessionHas('success');

        $job = $job->fresh();
        $this->assertSame('07:00', substr((string) $job->start_time, 0, 5));
        $this->assertSame(['Product launch', 'Acme Events', '2025-05-10', '2025-05-11', '18:00', 'Old venue', 'M1 1AA', 45000, 'booked', 'Bring the radios', 'PO-4471', false],
            [$job->title, $job->client?->name, $job->starts_on->format('Y-m-d'), $job->ends_on->format('Y-m-d'), substr((string) $job->end_time, 0, 5), $job->location,
                $job->postcode, $job->fee_pence, $job->status, $job->notes, $job->po_number, $job->po_not_required]);
    }

    public function test_the_job_form_still_saves_every_field_it_sends(): void
    {
        $job = $this->job(['po_number' => 'PO-4471']);

        // As job-form.tsx sends it: every field, the empty ones as empty strings.
        $this->patchAsPage($job, ['title' => 'Product launch', 'client_id' => '', 'new_client' => '', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10',
            'start_time' => '', 'end_time' => '', 'location' => '', 'postcode' => '', 'fee' => '', 'status' => 'done', 'notes' => '', 'po_number' => '',
            'po_not_required' => true])->assertSessionHasNoErrors()->assertSessionHas('success');

        $job = $job->fresh();
        $this->assertSame([null, '2025-05-10', null, null, null, null, null, 'done', null, null, true],
            [$job->client_id, $job->ends_on->format('Y-m-d'), $job->start_time, $job->end_time, $job->location, $job->postcode, $job->fee_pence, $job->status,
                $job->notes, $job->po_number, $job->po_not_required]);
    }
}
