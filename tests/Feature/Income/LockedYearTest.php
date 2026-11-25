<?php

namespace Tests\Feature\Income;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\TaxYear;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Build slice S9 (income v3, owner answer 4: a locked year changes nothing, unlock with a reason first): a job's
 * paying client can't change while it has locked-year income that takes its client from the job; its dates can.
 * Bulk decisions and "Set client" leave locked payments and count them. Closing and replacing invoices write no
 * ledger line, so a lock doesn't stop them.
 */
class LockedYearTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    private Client $acme;

    private Client $other;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        Queue::fake();
        $this->signIn();
        $this->acme = $this->client();
        $this->other = $this->client('Other Ltd');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function lock(string $label = '2025-26'): void
    {
        TaxYear::track($label)->forceFill(['locked_at' => now()])->save();
    }

    private function form(WorkJob $job, array $over = []): array
    {
        return $over + ['title' => $job->title, 'client_id' => $job->client_id, 'starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d')];
    }

    public function test_a_jobs_client_cant_change_with_locked_income_that_takes_its_client_from_the_job(): void
    {
        // Income on the job with no invoice.
        $plain = $this->job('Show A', $this->acme, '2025-05-01');
        $this->decide($this->tx(30000, 'ACME PAYROLL', '2025-05-20'), [$this->income(30000, ['work_job_id' => $plain->id])]);
        // Income naming an invoice with no client of its own.
        $viaInvoice = $this->job('Show B', $this->acme, '2025-05-02');
        $noClient = $this->invoice('INV-0001', 20000, $viaInvoice, null, ['issue_date' => '2025-05-03']);
        $this->decide($this->tx(20000, 'ACME PAYROLL', '2025-05-21'), [$this->income(20000, ['work_job_id' => $viaInvoice->id, 'invoice_id' => $noClient->id])]);
        // Income naming an invoice that has its client: allowed.
        $named = $this->job('Show C', $this->acme, '2025-05-03');
        $withClient = $this->invoice('INV-0002', 25000, $named, $this->acme, ['issue_date' => '2025-05-04']);
        $this->decide($this->tx(25000, 'ACME PAYROLL', '2025-05-22'), [$this->income(25000, ['work_job_id' => $named->id, 'invoice_id' => $withClient->id])]);
        $this->lock();

        foreach ([$plain, $viaInvoice] as $job) {
            $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['client_id' => $this->other->id]), "/jobs/{$job->id}")
                ->assertSessionHas('error', 'This job has income in 2025-26, which is locked: unlock it (with a reason) first.');
            $this->assertSame($this->acme->id, $job->fresh()->client_id);
        }
        $this->visit('PATCH', "/jobs/{$named->id}", $this->form($named, ['client_id' => $this->other->id]), "/jobs/{$named->id}")->assertSessionHas('success');
        $this->assertSame($this->other->id, $named->fresh()->client_id);

        // Its dates (and days) still change: a job's days are just dates.
        $this->visit('PATCH', "/jobs/{$plain->id}", $this->form($plain, ['ends_on' => '2025-05-02']), "/jobs/{$plain->id}")->assertSessionHas('success');
        $this->assertSame('2025-05-02', $plain->fresh()->ends_on->format('Y-m-d'));
        // Unlocked, the client can change.
        TaxYear::query()->where('label', '2025-26')->update(['locked_at' => null]);
        $this->visit('PATCH', "/jobs/{$plain->id}", $this->form($plain->fresh(), ['client_id' => $this->other->id]), "/jobs/{$plain->id}")->assertSessionHas('success');
        $this->assertSame($this->other->id, $plain->fresh()->client_id);
    }

    public function test_bulk_decisions_and_set_client_leave_locked_payments_and_count_them(): void
    {
        $old = $this->tx(9000, 'ACME PAYROLL', '2025-05-20');
        $new = $this->tx(8000, 'ACME PAYROLL', '2026-05-20');
        $this->lock();

        $this->visit('POST', '/decisions/bulk-client', ['merchant_key' => 'ACME PAYROLL', 'client_id' => $this->acme->id])
            ->assertSessionHas('success', 'Set 1 as Acme Events’s. Left 1 in a locked year: unlock it (with a reason) first.');
        $this->assertSame([], $this->stored($old));
        $this->assertSame($this->acme->id, $this->stored($new)[0][3]);

        $other = $this->tx(7000, 'SOMEONE', '2025-06-01');
        $this->visit('POST', '/decisions/bulk', ['ids' => [$other->id], 'kind' => 'business_income', 'category_id' => $this->cat('turnover')])
            ->assertSessionHas('error');
        $this->assertSame([], $this->stored($other));
    }

    public function test_closing_and_replacing_invoices_arent_stopped_by_a_lock(): void
    {
        $invoice = $this->invoice('INV-0010', 45000, null, $this->acme, ['issue_date' => '2025-05-03', 'due_date' => '2025-06-02']);
        $this->decide($this->tx(30000, 'ACME PAYROLL', '2025-05-20'), [$this->income(30000, ['invoice_id' => $invoice->id])]);
        $void = $this->invoice('INV-0009', 20000, null, $this->acme, ['issue_date' => '2025-04-03']);
        $this->decide($this->tx(20000, 'ACME PAYROLL', '2025-04-20'), [$this->income(20000, ['invoice_id' => $void->id])]);
        $void->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong rate']);
        $this->lock();

        $this->visit('POST', "/invoices/{$invoice->id}/close", ['reason' => 'wont_chase'], "/invoices/{$invoice->id}")->assertSessionHas('success');
        $this->assertSame(15000, $invoice->fresh()->closed_minor);
        $this->visit('POST', "/invoices/{$void->id}/replaced-by", ['invoice_id' => $invoice->id], "/invoices/{$void->id}")->assertSessionHas('success');
        $this->assertSame($invoice->id, $void->fresh()->replaced_by_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'invoice.replaced')->count());
    }
}
