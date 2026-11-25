<?php

namespace Tests\Feature\Jobs;

use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Income\HttpFixtures;
use Tests\TestCase;

/**
 * Build slice S9 (a job's status over HTTP): every change goes through JobStatus, from the buttons and from the job
 * form. Done gets the invoice ready (and opens it); a job added already done only offers "Draft its invoice?"; back to
 * booked clears when it was done; Cancelled keeps what they owe. The form takes the end client, the rate and the days
 * agreed (filled when you set a fee). The jobs list and calendar carry each job's stage and "Done?".
 */
class JobDonePagesTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        Queue::fake();
        $this->signIn();
        Setting::put('invoice_terms_days', 30);
        $this->acme = $this->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function booked(string $title = 'Arena load-in', string $on = '2026-06-08'): WorkJob
    {
        return $this->job($title, $this->acme, $on, ['status' => 'booked', 'fee_pence' => 65000]);
    }

    private function form(WorkJob $job, array $over = []): array
    {
        return $over + ['title' => $job->title, 'client_id' => $job->client_id, 'starts_on' => $job->starts_on->format('Y-m-d'), 'ends_on' => $job->ends_on->format('Y-m-d')];
    }

    public function test_the_form_s_done_gets_the_invoice_ready_and_back_to_booked_clears_it(): void
    {
        $job = $this->booked();
        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['status' => 'done']), "/jobs/{$job->id}")
            ->assertSessionHas('success', 'Saved. Marked done. Its invoice is ready to check.');
        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertNotNull($job->done_at);
        $draft = Invoice::query()->where('work_job_id', $job->id)->sole();
        $this->assertSame('draft', $draft->status);
        $this->assertSame(1, AuditEvent::query()->where('action', 'job.done')->count());

        // Done to Booked clears when it was done, and the untouched draft Done made goes.
        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['status' => 'booked']), "/jobs/{$job->id}")->assertSessionHas('success', 'Saved. Back to booked.');
        $this->assertSame(['booked', null], [$job->fresh()->status, $job->fresh()->done_at]);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'job.reopened')->count());
    }

    public function test_a_job_added_already_done_offers_draft_its_invoice_and_makes_none_by_itself(): void
    {
        $this->visit('POST', '/jobs', ['title' => 'Old gala', 'client_id' => $this->acme->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-02', 'fee' => '500.00', 'status' => 'done'], '/jobs')
            ->assertRedirect()->assertSessionHas('success', 'Added Old gala. It’s done: draft its invoice?');
        $job = WorkJob::query()->where('title', 'Old gala')->sole();
        $this->assertSame([2, 'done'], [$job->days_agreed, $job->status], 'the days agreed are filled with a fee');
        $this->assertSame(0, Invoice::query()->count());
        $this->get("/jobs/{$job->id}")->assertInertia(fn (AssertableInertia $p) => $p->component('jobs/show', false)->where('offers_draft', true)->where('stage.stage', 'done_no_draft'));

        $this->visit('POST', "/jobs/{$job->id}/draft", [], "/jobs/{$job->id}")->assertRedirect('/invoices/'.Invoice::query()->value('id'));
        $this->assertSame(1, Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->count());
    }

    public function test_done_opens_the_invoice_and_cancelled_keeps_what_they_owe_with_undo(): void
    {
        $job = $this->booked();
        $this->visit('POST', "/jobs/{$job->id}/done", [], "/jobs/{$job->id}")->assertRedirect('/invoices/'.Invoice::query()->where('work_job_id', $job->id)->value('id'))
            ->assertSessionHas('success', 'Marked done. Its invoice is ready to check.');
        $this->visit('POST', "/jobs/{$job->id}/done", ['stay' => true], "/jobs/{$job->id}")->assertRedirect("/jobs/{$job->id}")->assertSessionHas('success', 'Arena load-in is already done.');

        $other = $this->booked('Expo rig', '2026-06-12');
        $this->visit('POST', "/jobs/{$other->id}/cancel", ['charge' => 'fee'], "/jobs/{$other->id}")->assertSessionHas('error', 'Give the cancellation fee: an amount above zero.');
        $this->visit('POST', "/jobs/{$other->id}/cancel", ['charge' => 'maybe'], "/jobs/{$other->id}")
            ->assertSessionHasErrors(['charge' => 'Say whether they owe you anything: nothing, a cancellation fee or your costs.']);
        $this->visit('POST', "/jobs/{$other->id}/cancel", ['charge' => 'fee', 'fee' => '150.00'], "/jobs/{$other->id}")
            ->assertSessionHas('success', 'Cancelled. Its invoice is ready to check.');
        $this->assertSame(['cancelled', 'fee', 15000], [$other->fresh()->status, $other->fresh()->cancel_charge, $other->fresh()->cancel_fee_pence]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'job.cancelled')->count());

        $this->visit('POST', "/jobs/{$other->id}/uncancel", [], "/jobs/{$other->id}")->assertSessionHas('success', 'Back to booked.');
        $this->assertSame(['booked', null], [$other->fresh()->status, $other->fresh()->cancel_charge]);
        $this->assertSame(0, Invoice::query()->where('work_job_id', $other->id)->count(), 'the untouched draft cancelling made goes');
    }

    public function test_the_form_takes_the_end_client_rate_and_days_agreed(): void
    {
        $job = $this->booked();
        $job->forceFill(['fee_pence' => null])->save();
        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['new_end_client' => 'Big Prod Co', 'rate' => '250.00', 'rate_unit' => 'day']), "/jobs/{$job->id}")
            ->assertSessionHas('success');
        $job->refresh();
        $end = Client::query()->where('name', 'Big Prod Co')->sole();
        $this->assertSame([$end->id, 25000, 'day', 1], [$job->end_client_id, $job->rate_pence, $job->rate_unit, $job->days_agreed]);
        $this->assertSame('For a job, as its end client', AuditEvent::query()->where('action', 'client.created')->where('subject_id', $end->id)->value('reason'));

        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['days_agreed' => 0]), "/jobs/{$job->id}")->assertSessionHasErrors(['days_agreed' => 'The days agreed are at least 1.']);
        $this->get("/jobs/{$job->id}")->assertInertia(fn (AssertableInertia $p) => $p->where('job.end_client', 'Big Prod Co')->where('job.rate_pence', 25000)
            ->where('terms.fp', fn ($fp) => is_string($fp))->has('charges'));
    }

    public function test_the_jobs_list_and_calendar_carry_stages_and_done(): void
    {
        $due = $this->booked('Arena load-in', '2026-06-08');
        $this->booked('Next week', '2026-06-17');

        $this->get('/jobs')->assertInertia(fn (AssertableInertia $p) => $p->component('jobs/index', false)->where('counts.done_due', 1)
            ->where('jobs', fn ($rows) => collect($rows)->firstWhere('id', $due->id)['stage']['chip'] === 'Done?'));
        $this->get('/jobs?show=done_due')->assertInertia(fn (AssertableInertia $p) => $p->has('jobs', 1)->where('jobs.0.id', $due->id));
        $this->get('/jobs/calendar?m=2026-06')->assertInertia(fn (AssertableInertia $p) => $p->where('jobs', fn ($jobs) => collect($jobs)->firstWhere('id', $due->id)['done_due'] === true
            && collect($jobs)->firstWhere('id', $due->id)['status'] === 'booked'));

        // A fixed number of queries for many jobs.
        $count = function () {
            $this->get('/jobs')->assertOk();

            return $this->queries(fn () => $this->get('/jobs')->assertOk());
        };
        $few = $count();
        for ($i = 0; $i < 30; $i++) {
            $this->booked('Show '.$i, '2026-05-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT));
        }
        $this->assertSame($few, $count());
    }

    public function test_a_new_paying_client_on_the_form_moves_the_jobs_open_draft_to_them(): void
    {
        $this->acme->forceFill(['billing_email' => 'accounts@acme.example.test', 'payment_terms_days' => 30])->save();
        $job = $this->booked();
        $this->visit('POST', "/jobs/{$job->id}/done", ['stay' => true], "/jobs/{$job->id}")->assertSessionHas('success');
        $draft = Invoice::query()->where('work_job_id', $job->id)->where('status', 'draft')->sole();
        $this->assertSame([$this->acme->id, 'Acme Events', 'accounts@acme.example.test'], [(int) $draft->client_id, $draft->bill_to_name, $draft->bill_to_email]);
        // A note you typed on the draft stays yours.
        $draft->update(['bill_to_address' => '1 Typed Street']);
        $big = $this->client('Big Prod Co', ['billing_email' => 'ap@bigprod.example.test', 'invoice_name' => 'Big Productions Ltd', 'payment_terms_days' => 14]);

        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['client_id' => $big->id]), "/jobs/{$job->id}")
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Its draft now bills Big Prod Co.'));

        $draft->refresh();
        $this->assertSame([$big->id, 'Big Productions Ltd', 'ap@bigprod.example.test', '1 Typed Street', 'Payment due within 14 days.'],
            [(int) $draft->client_id, $draft->bill_to_name, $draft->bill_to_email, $draft->bill_to_address, $draft->notes]);
        $event = AuditEvent::query()->where('action', 'invoice.client_changed')->sole();
        $this->assertSame([$this->acme->id, $big->id], [(int) $event->before['client_id'], (int) $event->after['client_id']]);
    }

    public function test_the_form_refuses_a_rate_of_zero_or_less_and_money_typed_the_wrong_way_in_words(): void
    {
        $job = $this->booked();
        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['rate' => '-250', 'rate_unit' => 'day']), "/jobs/{$job->id}")
            ->assertSessionHas('error', 'Give the rate as an amount above zero, a day or an hour.');
        $this->assertNull($job->fresh()->rate_pence);

        $this->from("/jobs/{$job->id}")->patch("/jobs/{$job->id}", $this->form($job, ['rate' => '250.555', 'rate_unit' => 'day']))
            ->assertRedirect("/jobs/{$job->id}")->assertSessionHasErrors(['rate' => 'Give the rate as an amount, like 250.00.']);
        $this->from("/jobs/{$job->id}")->patch("/jobs/{$job->id}", $this->form($job, ['fee' => '650,00']))
            ->assertSessionHasErrors(['fee' => 'Give the fee as an amount, like 650.00.']);
        $this->from("/jobs/{$job->id}")->post("/jobs/{$job->id}/cancel", ['charge' => 'fee', 'fee' => '150,50'])
            ->assertRedirect("/jobs/{$job->id}")->assertSessionHas('error', 'Give the cancellation fee as an amount, like 150.00.');
        $this->assertSame(['booked', null, 65000], [$job->fresh()->status, $job->fresh()->rate_pence, $job->fresh()->fee_pence]);
    }

    public function test_a_client_change_refused_for_a_locked_year_adds_no_client(): void
    {
        $job = $this->job('Spring show', $this->acme, '2025-07-01');
        $this->decide($this->tx(65000, 'ACME PAYROLL', '2025-07-03'), [$this->income(65000, ['work_job_id' => $job->id])]);
        TaxYear::query()->updateOrCreate(['label' => '2025-26'], ['starts_on' => '2025-04-06', 'ends_on' => '2026-04-05', 'locked_at' => now(), 'status' => 'filed']);
        $clients = Client::query()->count();

        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['client_id' => null, 'new_client' => 'Brand New Co', 'new_end_client' => 'Another New Co']), "/jobs/{$job->id}")
            ->assertSessionHas('error', 'This job has income in 2025-26, which is locked: unlock it (with a reason) first.');
        $this->assertSame($clients, Client::query()->count());

        // Typing the name of the client it has already isn't a change, and a new end client is added once the save goes ahead.
        $this->visit('PATCH', "/jobs/{$job->id}", $this->form($job, ['client_id' => null, 'new_client' => 'acme events', 'new_end_client' => 'Another New Co']), "/jobs/{$job->id}")
            ->assertSessionHas('success');
        $this->assertSame([$this->acme->id, 'Another New Co'], [(int) $job->fresh()->client_id, $job->fresh()->endClient?->name]);
    }

    public function test_a_job_added_already_cancelled_keeps_what_they_owe(): void
    {
        $this->visit('POST', '/jobs', ['title' => 'Called-off gala', 'client_id' => $this->acme->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01',
            'status' => 'cancelled', 'cancel_charge' => 'fee', 'cancel_fee' => '150.00'], '/jobs')->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'Added Called-off gala. Cancelled.'));
        $job = WorkJob::query()->where('title', 'Called-off gala')->sole();
        $this->assertSame(['cancelled', 'fee', 15000], [$job->status, $job->cancel_charge, $job->cancel_fee_pence]);

        $this->visit('POST', '/jobs', ['title' => 'No fee given', 'client_id' => $this->acme->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01',
            'status' => 'cancelled', 'cancel_charge' => 'fee'], '/jobs')->assertSessionHas('error', 'Give the cancellation fee: an amount above zero.');
        $this->assertFalse(WorkJob::query()->where('title', 'No fee given')->exists());
    }
}
