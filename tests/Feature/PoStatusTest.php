<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Jobs\PoStatus;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoStatusTest extends TestCase
{
    use RefreshDatabase;

    private function job(string $title, array $extra = []): WorkJob
    {
        return WorkJob::query()->create($extra + ['title' => $title, 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10']);
    }

    /** An email carrying a PO attachment, read and linked to the job. */
    private function poEmail(WorkJob $job, string $number = 'PO-7781'): array
    {
        $raw = "From: buyer@client.example\r\nSubject: PO for May show\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain\r\n\r\nPO attached.\r\n"
            ."--b\r\nContent-Type: text/plain; name=po.txt\r\nContent-Disposition: attachment; filename=po.txt\r\n\r\nPurchase order {$number}\r\n--b--\r\n";
        $email = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', 'email');
        $po = $email->children()->sole();
        $po->update(['kind' => 'po', 'work_job_id' => $job->id, 'extraction' => ['po' => ['po_number' => $number, 'lines' => []]]]);

        return [$email->fresh(), $po->fresh()];
    }

    private function invoice(WorkJob $job, string $status, ?Document $from = null): Invoice
    {
        return Invoice::query()->create(['number' => 'INV-'.random_int(1000, 9999), 'work_job_id' => $job->id, 'document_id' => $from?->id,
            'status' => $status, 'issued_at' => $status === 'draft' ? null : now(), 'total_minor' => 50000]);
    }

    public function test_jobs_without_a_po_are_flagged_unless_not_needed_or_cancelled(): void
    {
        $none = $this->job('No paperwork');
        $waived = $this->job('Regular client', ['po_not_required' => true]);
        $cancelled = $this->job('Called off', ['status' => 'cancelled']);
        $typed = $this->job('PO by phone', ['po_number' => 'PH-12']);
        $withDoc = $this->job('PO by email');
        $this->poEmail($withDoc);

        $status = PoStatus::forJobs(WorkJob::all());
        $this->assertTrue($status[$none->id]['missing']);
        $this->assertFalse($status[$waived->id]['missing']);
        $this->assertFalse($status[$cancelled->id]['missing']);
        $this->assertSame(['PH-12', 'job'], [$status[$typed->id]['po']['number'], $status[$typed->id]['po']['source']]);
        $this->assertSame('PO-7781', $status[$withDoc->id]['po']['number']);

        $this->actingAs(User::factory()->create());
        $this->get('/jobs?show=no_po')->assertOk()->assertInertia(fn ($p) => $p->has('jobs', 1)->where('jobs.0.title', 'No paperwork')->where('counts.no_po', 1));
        TaxYear::track('2025-26');
        $this->get('/dashboard')->assertInertia(fn ($p) => $p->where('counts.jobs_without_po', 1));
    }

    public function test_the_po_email_gets_a_green_tick_once_the_job_is_invoiced(): void
    {
        $job = $this->job('May show');
        [$email, $po] = $this->poEmail($job);
        $this->actingAs(User::factory()->create());

        $this->invoice($job, 'draft');
        $this->assertSame([], PoStatus::invoicedDocuments(collect([$email])), 'A draft isn’t invoiced yet');
        $this->get('/documents')->assertInertia(fn ($p) => $p->where('documents.0.invoiced', null));

        $issued = $this->invoice($job, 'issued');
        $this->get('/documents')->assertInertia(fn ($p) => $p->where('documents.0.invoiced.number', $issued->number)->where('documents.0.invoiced.status', 'issued'));
        $this->get('/documents/'.$email->id)->assertInertia(fn ($p) => $p->where('document.invoiced.number', $issued->number)->where('document.children.0.invoiced.number', $issued->number));
        $this->get('/jobs')->assertInertia(fn ($p) => $p->where('jobs.0.invoice.number', $issued->number)->where('jobs.0.missing', false));
    }

    public function test_voided_invoices_dont_count_and_an_invoice_drafted_from_a_document_ticks_it(): void
    {
        $job = $this->job('Show');
        [$email] = $this->poEmail($job);
        $this->invoice($job, 'void');
        $this->assertSame([], PoStatus::invoicedDocuments(collect([$email])));

        $other = $this->job('Other job');
        [$otherEmail, $otherPo] = $this->poEmail($other, 'PO-2');
        $otherPo->update(['work_job_id' => null]);
        $paid = $this->invoice($other, 'paid', $otherPo);
        $this->assertSame(['id' => $paid->id, 'number' => $paid->number, 'status' => 'paid'], PoStatus::invoicedDocuments(collect([$otherEmail->fresh()]))[$otherEmail->id]);
    }

    public function test_the_po_number_can_be_typed_on_the_job_or_marked_not_needed(): void
    {
        $job = $this->job('Show');
        $this->actingAs(User::factory()->create());
        $form = ['title' => 'Show', 'starts_on' => '2025-05-10', 'ends_on' => '2025-05-10'];

        $this->patch("/jobs/{$job->id}", $form + ['po_number' => ' PO 55 '])->assertSessionHas('success');
        $this->assertSame('PO 55', $job->fresh()->po_number);
        $this->patch("/jobs/{$job->id}", $form + ['po_number' => '', 'po_not_required' => true]);
        $this->assertNull($job->fresh()->po_number);
        $this->assertTrue($job->fresh()->po_not_required);
        $this->get("/jobs/{$job->id}")->assertInertia(fn ($p) => $p->where('job.missing', false)->where('job.po_not_required', true));
    }
}
