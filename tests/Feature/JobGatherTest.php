<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Jobs\GatherFromGmail;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\JobFind;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Click a calendar entry, say it's a job, and everything that belongs to it is found. */
class JobGatherTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Queue::fake();
        Setting::put('job_finder_auto', false);
        Setting::put('own_names', ['Alex Smith']);
        $this->actingAs(User::factory()->create());
        $this->bank = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
    }

    private function event(string $summary, string $start, ?string $end = null, ?string $location = null): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => false, 'summary' => $summary, 'location' => $location]);
    }

    private function tx(string $date, int $pence, string $description, ?string $counterparty = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $this->bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => strtoupper($counterparty ?: strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $date.$pence.$description.$counterparty), 'occurrence' => 1,
        ]);
    }

    private function email(string $subject, string $body, string $received): Document
    {
        $raw = "From: crew@acme.example\r\nSubject: {$subject}\r\nDate: Mon, 3 Mar 2025 10:00:00 +0000\r\n\r\n{$body}";

        return app(DocumentIntake::class)->store($raw, 'mail.eml', 'message/rfc822', 'gmail', ['received_at' => $received]);
    }

    private function scenario(): array
    {
        Client::query()->create(['name' => 'Acme Events Ltd']);
        $day1 = $this->event('Acme Events Awards – Grand Hall', '2025-05-14 07:00:00', '2025-05-14 23:00:00', 'Grand Hall, Leeds LS1 4AP');
        $day2 = $this->event('Acme Events Awards – Grand Hall', '2025-05-15 08:00:00', '2025-05-15 18:00:00', 'Grand Hall, Leeds LS1 4AP');
        $derig = $this->event('Acme Events Awards – Grand Hall', '2025-05-17 08:00:00', '2025-05-17 12:00:00', 'Grand Hall, Leeds LS1 4AP');
        $other = $this->event('Dentist', '2025-05-14 17:00:00', '2025-05-14 17:30:00');
        $po = $this->email('Purchase order PO-7781', "Please find PO-7781 for the Acme Awards at the Grand Hall on 14 May 2025.\r\n", '2025-04-01 09:00:00');
        $vague = $this->email('Acme Events catch-up', "Lovely to see you, let's talk about next year with Acme Events.\r\n", '2025-04-20 09:00:00');
        $unrelated = $this->email('Your gas bill', "Your bill for May 2025.\r\n", '2025-05-14 09:00:00');

        $uber = $this->tx('2025-05-14', -2350, 'UBER *TRIP', 'UBER');
        $hardware = $this->tx('2025-05-15', -1899, 'SCREWFIX LEEDS', 'SCREWFIX');
        $aiGuess = $this->tx('2025-05-15', -4500, 'GAFFER TAPE CO', 'GAFFER TAPE CO');
        $equipment = Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
        app(AllocationWriter::class)->decide($aiGuess, AllocationWriter::lines(-4500, 'business_expense', $equipment), 'ai', false, 'Looks like equipment');
        $toSavings = $this->tx('2025-05-15', -20000, 'Savings', 'A SMITH');
        $fee = $this->tx('2025-06-20', 120000, 'INV-0007', 'ACME EVENTS LTD');

        return compact('day1', 'day2', 'derig', 'other', 'po', 'vague', 'unrelated', 'uber', 'hardware', 'aiGuess', 'toSavings', 'fee');
    }

    public function test_saying_a_calendar_entry_is_a_job_finds_what_belongs_to_it(): void
    {
        $s = $this->scenario();
        $client = Client::query()->where('name', 'Acme Events Ltd')->value('id');

        $response = $this->post('/calendar/job', [
            'key' => $s['day1']->key(), 'date' => '2025-05-14', 'then' => 'job',
            'title' => 'Acme Awards', 'client_id' => $client, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15',
            'location' => 'Grand Hall, Leeds', 'postcode' => 'LS1 4AP', 'fee' => '1200.00', 'status' => 'done',
        ]);
        $job = WorkJob::query()->sole();
        $response->assertRedirect('/jobs/'.$job->id)->assertSessionHas('success');

        // Calendar: the other day of the same entry is linked; a nearby repeat is suggested; the dentist isn't.
        $this->assertSame($job->id, CalendarJobLink::query()->where('event_key', $s['day2']->key())->value('work_job_id'));
        $this->assertSame('possible', JobFind::query()->where('type', 'calendar')->where('ref', 'like', $s['derig']->key().'%')->value('status'));
        $this->assertNull(CalendarJobLink::query()->where('event_key', $s['other']->key())->value('work_job_id'));

        // Documents: the PO (its number isn't on the job, but the client and date are) is linked; a vague one is suggested.
        $this->assertSame($job->id, $s['po']->fresh()->work_job_id);
        $this->assertSame('rules', $s['po']->fresh()->job_linked_by);
        $this->assertSame('possible', JobFind::query()->where('type', 'document')->where('ref', (string) $s['vague']->id)->value('status'));
        $this->assertNull($s['unrelated']->fresh()->work_job_id);

        // Payments: the taxi is suggested as the job's cost; the AI's guess gets the job; the hardware is asked about; the transfer is left out.
        $this->assertSame($job->id, $s['uber']->fresh()->allocations()->value('work_job_id'));
        $this->assertNull($s['uber']->fresh()->allocations()->value('confirmed_at'), 'Suggested for you to confirm, not decided');
        $this->assertSame($job->id, $s['aiGuess']->fresh()->allocations()->value('work_job_id'));
        $this->assertSame('possible', JobFind::query()->where('type', 'payment')->where('ref', (string) $s['hardware']->id)->value('status'));
        $this->assertFalse(JobFind::query()->where('type', 'payment')->where('ref', (string) $s['toSavings']->id)->exists());

        // The fee coming in later is suggested as this job's income.
        $this->assertSame('possible', JobFind::query()->where('type', 'income')->where('ref', (string) $s['fee']->id)->value('status'));

        $this->get('/jobs/'.$job->id)->assertOk()->assertInertia(fn ($p) => $p->has('finds')->where('gather.state.gmail', 'off'));
    }

    public function test_you_link_or_reject_what_may_belong_and_rejections_stick(): void
    {
        $s = $this->scenario();
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => Client::query()->value('id'), 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'fee_pence' => 120000, 'location' => 'Grand Hall, Leeds', 'status' => 'done']);
        app(JobGatherer::class)->gather($job);

        $fee = JobFind::query()->where('type', 'income')->sole();
        $this->post("/job-finds/{$fee->id}/accept")->assertSessionHas('success');
        $this->assertSame(['business_income', $job->id], [$s['fee']->fresh()->allocations()->value('kind'), $s['fee']->fresh()->allocations()->value('work_job_id')]);

        $vague = JobFind::query()->where('type', 'document')->where('status', 'possible')->sole();
        $this->post("/job-finds/{$vague->id}/reject")->assertSessionHas('success');
        app(JobGatherer::class)->gather($job);
        $this->assertSame('rejected', $vague->fresh()->status, 'Not suggested again');

        // Undoing a link the search made unlinks it and remembers "not this job".
        $po = JobFind::query()->where('type', 'document')->where('status', 'linked')->sole();
        $this->post("/job-finds/{$po->id}/reject");
        $this->assertNull($s['po']->fresh()->work_job_id);
        $this->assertContains($job->id, $s['po']->fresh()->job_rejected);
        app(JobGatherer::class)->gather($job);
        $this->assertNull($s['po']->fresh()->work_job_id, 'Not linked again');

        // A business cost with no job, decided by you: linked to the job in one tap.
        $hw = JobFind::query()->where('type', 'payment')->where('ref', (string) $s['hardware']->id)->sole();
        $this->post("/job-finds/{$hw->id}/accept")->assertSessionHas('error');
        $tools = Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
        app(AllocationWriter::class)->decide($s['hardware']->fresh(), AllocationWriter::lines(-1899, 'business_expense', $tools));
        $this->post("/job-finds/{$hw->id}/accept")->assertSessionHas('success');
        $this->assertSame($job->id, $s['hardware']->fresh()->allocations()->value('work_job_id'));
    }

    public function test_an_entry_can_join_a_job_you_have_and_stretch_its_dates(): void
    {
        $s = $this->scenario();
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'status' => 'done']);

        $this->post('/calendar/link-job', ['key' => $s['derig']->key(), 'date' => '2025-05-17', 'job_id' => $job->id])
            ->assertRedirect('/jobs/'.$job->id);
        $this->assertSame($job->id, CalendarJobLink::query()->where('event_key', $s['derig']->key())->value('work_job_id'));
        $this->assertSame('2025-05-17', $job->fresh()->ends_on->format('Y-m-d'));
        $this->assertSame('you', CalendarJobLink::query()->where('event_key', $s['derig']->key())->value('linked_by'));
    }

    public function test_the_calendar_page_lists_every_entry_with_its_status(): void
    {
        $s = $this->scenario();
        $this->get('/jobs/calendar?m=2025-05')->assertOk()->assertInertia(fn ($p) => $p
            ->where('month', '2025-05')
            ->has('events', 4)
            ->where('events.0.summary', 'Acme Events Awards – Grand Hall')
            ->where('events.0.postcode', 'LS1 4AP')
            ->where('events.0.client_id', Client::query()->value('id')));
    }

    public function test_gmail_is_searched_for_the_job_when_connected(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => Client::query()->create(['name' => 'Acme Events Ltd'])->id, 'starts_on' => '2025-05-14',
            'ends_on' => '2025-05-15', 'po_number' => 'PO-7781', 'location' => 'Grand Hall, Leeds', 'status' => 'done']);
        $q = JobGatherer::gmailQuery($job->fresh('client'));
        $this->assertStringContainsString('"PO-7781"', $q);
        $this->assertStringContainsString('"Acme Events"', $q);
        $this->assertStringContainsString('"Grand Hall"', $q);
        $this->assertStringContainsString('after:2025/01/14 before:2025/06/26', $q);

        app(JobGatherer::class)->gather($job);
        Queue::assertNotPushed(GatherFromGmail::class); // not connected in tests

        Setting::put('google_client_id', 'x');
        Setting::put('google_client_secret', 'y');
        Setting::put('gmail_refresh_token', 'z');
        app(JobGatherer::class)->gather($job);
        Queue::assertPushed(GatherFromGmail::class, fn ($j) => $j->jobId === $job->id);
        $this->assertSame('queued', $job->fresh()->gather_state['gmail']);
    }
}
