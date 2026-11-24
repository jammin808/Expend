<?php

namespace Tests\Feature;

use App\Domain\Ai\AssistantTools;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Gmail\GmailScanner;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Mileage\JobTrips;
use App\Domain\Tax\Explainer;
use App\Domain\Travel\TravelDays;
use App\Jobs\ReadTravelEmail;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\JobFind;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Review fixes for jobs: making a job from a calendar entry keeps your dates and times, out-of-date finds never undo
 * what you did elsewhere, rejections are remembered by every search, and "is this a job day" means the job's days.
 */
class NewFixesJobsTest extends TestCase
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

    private function event(string $summary, string $start, ?string $end = null, ?string $location = null, bool $allDay = false): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'summary' => $summary, 'location' => $location]);
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

    private function email(string $subject, string $body, string $sent): Document
    {
        $date = (new \DateTimeImmutable($sent))->format('D, j M Y H:i:s O');
        $raw = "From: crew@acme.example\r\nSubject: {$subject}\r\nDate: {$date}\r\n\r\n{$body}";

        return app(DocumentIntake::class)->store($raw, 'mail-'.md5($subject.$sent).'.eml', 'message/rfc822', 'gmail', ['received_at' => $sent]);
    }

    private function places(): void
    {
        Setting::put('home_postcode', 'LS1 4AP');
        foreach (['LS1 4AP' => [53.7965, -1.5478], 'M1 1AA' => [53.4808, -2.2426]] as $pc => [$lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function expense(): int
    {
        return (int) Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
    }

    /** The Acme Awards: made from its calendar entry for 14–15 May, with a second day of the same entry on 15 May. */
    private function acme(): array
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $day1 = $this->event('Acme Events Awards – Grand Hall', '2025-05-14 07:00:00', '2025-05-14 21:00:00', 'Grand Hall, Leeds LS1 4AP');
        $day2 = $this->event('Acme Events Awards – Grand Hall', '2025-05-15 08:00:00', '2025-05-15 18:00:00', 'Grand Hall, Leeds LS1 4AP');
        $this->post('/calendar/job', ['key' => $day1->key(), 'date' => '2025-05-14', 'title' => 'Acme Awards', 'client_id' => $client->id,
            'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'location' => 'Grand Hall, Leeds', 'postcode' => 'LS1 4AP', 'status' => 'done'])->assertSessionHas('success');

        return [WorkJob::query()->where('title', 'Acme Awards')->sole(), $day1, $day2, $client];
    }

    private function days(WorkJob $job): array
    {
        return $job->fresh()->workDates();
    }

    // ---------------------------------------------------------------- N6: the dates you typed

    public function test_n6_a_job_made_from_an_entry_keeps_the_dates_you_typed(): void
    {
        $festival = $this->event('Big Festival', '2025-08-10 00:00:00', '2025-08-17 00:00:00', 'Knebworth Park', true);
        $this->post('/calendar/job', ['key' => $festival->key(), 'date' => '2025-08-10', 'title' => 'Big Festival', 'starts_on' => '2025-08-12', 'ends_on' => '2025-08-14'])
            ->assertSessionHas('success');
        $job = WorkJob::query()->sole();

        $this->assertSame(['2025-08-12', '2025-08-13', '2025-08-14'], $this->days($job));
        $this->assertSame(['2025-08-12', '2025-08-14'], [$job->fresh()->starts_on->format('Y-m-d'), $job->fresh()->ends_on->format('Y-m-d')]);
        $this->assertSame(['show'], JobDay::query()->distinct()->pluck('kind')->all(), 'The entry’s kind still goes on the days it covers');
        $this->assertSame([$job->id, 'you'], [CalendarJobLink::query()->value('work_job_id'), CalendarJobLink::query()->value('linked_by')]);

        // Linking the entry to a job you have still adds every day it covers (a rig or de-rig you add on purpose).
        $other = WorkJob::query()->create(['title' => 'Other', 'starts_on' => '2025-08-09', 'ends_on' => '2025-08-09', 'status' => 'booked']);
        $this->post('/calendar/link-job', ['key' => $festival->key(), 'date' => '2025-08-10', 'job_id' => $other->id]);
        $this->assertSame('2025-08-16', $other->fresh()->ends_on->format('Y-m-d'));
    }

    public function test_n6_an_entry_running_past_midnight_is_one_day_and_one_trip(): void
    {
        $this->places();
        $gig = $this->event('Gig: Band night', '2025-05-14 18:00:00', '2025-05-15 00:30:00', 'Band on the Wall, M1 1AA');
        $this->get('/jobs/calendar?m=2025-05')->assertInertia(fn ($p) => $p->where('events.0.last_day', '2025-05-14'));

        $this->post('/calendar/job', ['key' => $gig->key(), 'date' => '2025-05-14', 'title' => 'Band night', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14',
            'start_time' => '19:00', 'end_time' => '01:30', 'postcode' => 'M1 1AA', 'status' => 'done'])->assertSessionHas('success');
        $job = WorkJob::query()->sole();
        $this->assertSame(['2025-05-14'], $this->days($job));
        $this->assertSame(['19:00', '01:30'], [JobDay::query()->value('start_time'), JobDay::query()->value('end_time')]);

        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertSame(1, $p['trips']);
        // A finish at 01:30 is a late finish for the night after.
        $this->assertSame('business', app(TravelDays::class)->night('2025-05-14')['verdict']);
    }

    public function test_n6_accepting_a_possible_job_keeps_the_dates_you_typed(): void
    {
        $festival = $this->event('Big Festival', '2025-08-10 00:00:00', '2025-08-17 00:00:00', 'Knebworth Park', true);
        $candidate = JobCandidate::query()->create(['status' => 'open', 'title' => 'Big Festival', 'starts_on' => '2025-08-10', 'ends_on' => '2025-08-16', 'reasons' => ['why' => []],
            'sources' => [['key' => 'cal:'.$festival->key(), 'type' => 'calendar', 'id' => $festival->key(), 'label' => 'Big Festival', 'date' => '2025-08-10', 'to' => '2025-08-16']]]);
        $this->post("/jobs/finder/{$candidate->id}/accept", ['title' => 'Big Festival', 'starts_on' => '2025-08-12', 'ends_on' => '2025-08-14'])->assertSessionHas('success');

        $job = WorkJob::query()->sole();
        $this->assertSame(['2025-08-12', '2025-08-13', '2025-08-14'], $this->days($job));
        $this->assertSame('2025-08-14', $job->fresh()->ends_on->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- N7, N14: out-of-date finds

    public function test_n7_undo_on_an_out_of_date_calendar_find_leaves_your_link_to_another_job(): void
    {
        [$a, , $day2] = $this->acme();
        $find = JobFind::query()->where('type', 'calendar')->where('status', 'linked')->sole();
        $this->assertStringStartsWith($day2->key(), $find->ref);

        $b = WorkJob::query()->create(['title' => 'Other show', 'starts_on' => '2025-05-20', 'ends_on' => '2025-05-20', 'status' => 'done']);
        $this->post('/calendar/link-job', ['key' => $day2->key(), 'date' => '2025-05-15', 'job_id' => $b->id]);
        $this->assertSame(['2025-05-15', '2025-05-20'], $this->days($b));

        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success');
        $link = CalendarJobLink::query()->where('event_key', $day2->key())->sole();
        $this->assertSame([$b->id, 'you'], [$link->work_job_id, $link->linked_by], 'Your link to the other job stays');
        $this->assertContains($a->id, $link->rejected);
        $this->assertSame(['2025-05-15', '2025-05-20'], $this->days($b), 'and so do its days');

        // The job page no longer offers to undo something that isn't on it.
        JobFind::query()->whereKey($find->id)->update(['status' => 'linked']); // as it was before you pressed Undo
        app(JobGatherer::class)->gather($a, false);
        $this->assertNull($find->fresh(), 'A link that no longer holds is dropped');
    }

    public function test_n14_undo_on_an_out_of_date_document_or_stay_find_leaves_your_link_to_another_job(): void
    {
        [$a] = $this->acme();
        $po = $this->email('Purchase order', "Please find PO-7781 for the Acme Awards at the Grand Hall on 14 May 2025.\r\n", '2025-04-01 09:00:00');
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-05-13', 'ends_on' => '2025-05-14',
            'source' => 'manual', 'evidence' => [], 'work_job_id' => $a->id, 'job_linked_by' => 'rules']);
        app(JobGatherer::class)->gather($a, false);
        $docFind = JobFind::query()->where('type', 'document')->where('ref', (string) $po->id)->where('status', 'linked')->sole();
        $stayFind = JobFind::query()->where('type', 'travel')->where('ref', (string) $stay->id)->where('status', 'linked')->sole();

        $b = WorkJob::query()->create(['title' => 'Other show', 'starts_on' => '2025-05-20', 'ends_on' => '2025-05-20', 'status' => 'done']);
        app(JobFinder::class)->linkDocument($po->fresh(), $b, 'you');
        app(JobFinder::class)->linkTravel($stay->fresh(), $b, 'you');

        $this->post("/job-finds/{$docFind->id}/reject")->assertSessionHas('success');
        $this->post("/job-finds/{$stayFind->id}/reject")->assertSessionHas('success');
        $this->assertSame([$b->id, 'you'], [$po->fresh()->work_job_id, $po->fresh()->job_linked_by]);
        $this->assertNotContains($b->id, $po->fresh()->job_rejected ?? [], 'The job you chose isn’t marked as rejected');
        $this->assertContains($a->id, $po->fresh()->job_rejected);
        $this->assertSame($b->id, $stay->fresh()->work_job_id);
    }

    // ---------------------------------------------------------------- N8: emails sent on a job day

    public function test_n8_an_email_sent_on_a_job_day_isnt_linked_for_its_date_header_or_a_title_word(): void
    {
        [$job] = $this->acme();
        WorkJob::query()->create(['title' => 'Summer Party', 'client_id' => $job->client_id, 'starts_on' => '2025-06-20', 'ends_on' => '2025-06-20', 'po_number' => 'PO-9001', 'status' => 'booked']);
        $june = $this->email('PO for the summer party', "Acme Events purchase order PO-9001 for the Summer Party at Riverside Barn on 20 June 2025.\r\n", '2025-05-14 10:00:00');
        $telly = $this->email('Tonight', "The awards are on telly tonight, Mum x\r\n", '2025-05-15 18:00:00');
        $reply = $this->email('Re: next year', "Sounds good.\r\n\r\nOn Wed, 14 May 2025 at 10:00, Acme Events wrote:\r\n> Shall we talk about next year?\r\n", '2025-05-20 10:00:00');
        $real = $this->email('Call sheet', "Call sheet for the Acme Awards at the Grand Hall: crew call 07:00 on 14 May 2025.\r\n", '2025-05-01 10:00:00');

        app(JobGatherer::class)->gather($job, false);
        $this->assertNull($june->fresh()->work_job_id, 'Another job’s PO, sent on a job day');
        $this->assertNull($telly->fresh()->work_job_id, 'A title word and the date it was sent');
        $this->assertNull($reply->fresh()->work_job_id, 'A quoted reply header');
        $this->assertSame([$job->id, 'rules'], [$real->fresh()->work_job_id, $real->fresh()->job_linked_by], 'The job’s date and venue in the content');
        $this->assertSame('possible', JobFind::query()->where('type', 'document')->where('ref', (string) $june->id)->value('status'), 'Still listed for you to decide');
    }

    public function test_n8_the_job_finder_doesnt_link_an_invite_for_the_date_it_was_sent_either(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);
        $make = fn (string $text, string $sha) => Document::query()->create(['source' => 'gmail', 'original_name' => 'mail.eml', 'mime' => 'message/rfc822', 'size' => 1, 'sha256' => str_repeat($sha, 64),
            'stored_path' => 'x', 'kind' => 'job_invite', 'status' => 'extracted', 'email_subject' => 'Are you free?', 'received_at' => '2025-05-14 10:00:00', 'text' => $text,
            'extraction' => ['new_job' => ['title' => 'Acme job', 'client' => 'Acme Events Ltd', 'starts_on' => '2025-05-14', 'verified' => false]]]);
        $sent = $make("From: crew@acme.example\nTo: me@example.com\nDate: Wed, 14 May 2025 10:00:00 +0100\nSubject: Are you free?\n\nAcme Events here: are you free for a job soon?", 'a');
        $dated = $make("From: crew@acme.example\nTo: me@example.com\nDate: Mon, 5 May 2025 10:00:00 +0100\nSubject: Are you free?\n\nAcme Events here: are you free on 14 May 2025?", 'b');

        app(JobFinder::class)->run('2025-26', false);
        $this->assertNull($sent->fresh()->work_job_id, 'Only the date it was sent');
        $this->assertSame($job->id, $dated->fresh()->work_job_id, 'The job’s date in what it says');
    }

    // ---------------------------------------------------------------- N9: rejections every search respects

    public function test_n9_a_rejected_possible_calendar_entry_isnt_linked_by_the_job_finder(): void
    {
        [$job] = $this->acme();
        $meeting = $this->event('Acme Events planning meeting', '2025-05-15 13:00:00', '2025-05-15 14:00:00');
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('type', 'calendar')->where('ref', 'like', $meeting->key().'%')->sole();
        $this->assertSame('possible', $find->status);

        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success');
        $this->assertContains($job->id, CalendarJobLink::query()->where('event_key', $meeting->key())->value('rejected') ? CalendarJobLink::query()->where('event_key', $meeting->key())->first()->rejected : []);
        app(JobFinder::class)->run('2025-26', false);
        $this->assertNull(CalendarJobLink::query()->where('event_key', $meeting->key())->value('work_job_id'));
    }

    public function test_n9_rejecting_a_possible_find_the_finder_linked_since_unlinks_it(): void
    {
        [$job] = $this->acme();
        $meeting = $this->event('Acme Events planning meeting', '2025-05-15 13:00:00', '2025-05-15 14:00:00');
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('type', 'calendar')->where('ref', 'like', $meeting->key().'%')->sole();
        app(JobFinder::class)->linkCalendar($meeting, $job, 'rules', 'On the job’s dates');

        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success');
        $this->assertNull(CalendarJobLink::query()->where('event_key', $meeting->key())->value('work_job_id'));
    }

    public function test_n9_a_rejected_possible_document_is_remembered_on_the_document(): void
    {
        [$job] = $this->acme();
        $vague = $this->email('Acme Events catch-up', "Lovely to see you, let's talk about next year with Acme Events.\r\n", '2025-04-20 09:00:00');
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('type', 'document')->where('ref', (string) $vague->id)->sole();
        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success');
        $this->assertContains($job->id, $vague->fresh()->job_rejected);
        $this->assertNull($vague->fresh()->job_linked_by, 'Other jobs can still have it');
    }

    // ---------------------------------------------------------------- N16: stale "Money in"

    public function test_n16_accepting_an_out_of_date_money_in_find_keeps_your_split(): void
    {
        [$job] = $this->acme();
        $fee = $this->tx('2025-06-20', 150000, 'INV-0007', 'ACME EVENTS LTD');
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('type', 'income')->sole();

        $turnover = Category::query()->where('code', 'turnover')->value('id');
        app(AllocationWriter::class)->decide($fee, [
            ['kind' => 'business_income', 'category_id' => $turnover, 'amount_pence' => 120000],
            ['kind' => 'personal', 'amount_pence' => 30000],
        ]);
        $this->post("/job-finds/{$find->id}/accept")->assertSessionHas('error');
        $this->assertSame([120000, 30000], $fee->fresh()->allocations()->orderByDesc('amount_pence')->pluck('amount_pence')->all());
        $this->assertNull($find->fresh(), 'The out-of-date find goes');

        // Decided as business income (your amount): only the job is added.
        $other = $this->tx('2025-06-25', 90000, 'ACME BALANCE', 'ACME EVENTS LTD');
        app(AllocationWriter::class)->decide($other, AllocationWriter::lines(90000, 'business_income', $turnover));
        $f = JobFind::query()->create(['work_job_id' => $job->id, 'type' => 'income', 'ref' => (string) $other->id, 'status' => 'possible', 'label' => 'x', 'detail' => 'x']);
        $this->post("/job-finds/{$f->id}/accept")->assertSessionHas('success');
        $this->assertSame([[90000, $job->id]], $other->fresh()->allocations->map(fn ($a) => [$a->amount_pence, $a->work_job_id])->all());
    }

    // ---------------------------------------------------------------- N39: your times

    public function test_n39_the_times_you_type_win_over_the_calendar_entrys(): void
    {
        $this->places();
        $expo = $this->event('Expo', '2025-11-03 09:00:00', '2025-11-03 18:00:00', 'Manchester Central M1 1AA');
        $this->post('/calendar/job', ['key' => $expo->key(), 'date' => '2025-11-03', 'title' => 'Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03',
            'start_time' => '06:00', 'end_time' => '18:00', 'postcode' => 'M1 1AA'])->assertSessionHas('success');
        $job = WorkJob::query()->sole();
        $this->assertSame('06:00', JobDay::query()->value('start_time'));
        $this->assertSame('business', app(TravelDays::class)->night('2025-11-02')['verdict'], 'An early start, as you typed');

        $job->update(['start_time' => '10:00']);
        $this->assertSame('10:00', JobDay::query()->value('start_time'), 'Changing the job’s start time changes its first day’s');
        $this->assertSame('review', app(TravelDays::class)->night('2025-11-02')['verdict']);

        // A time you set on the day yourself is kept.
        $this->patch('/job-days/'.JobDay::query()->value('id'), ['kind' => 'work', 'start_time' => '07:15']);
        $job->fresh()->update(['start_time' => '11:00']);
        $this->assertSame('07:15', JobDay::query()->value('start_time'));
    }

    // ---------------------------------------------------------------- N40: days taken off a job

    public function test_n40_removing_job_days_looks_again_at_costs_suggested_for_them(): void
    {
        $job = WorkJob::query()->create(['title' => 'Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-09', 'status' => 'done']);
        $kit = $this->tx('2025-11-06', -2499, 'SCREWFIX', 'SCREWFIX');
        app(AllocationWriter::class)->decide($kit, AllocationWriter::lines(-2499, 'business_expense', $this->expense(), $job->id), 'matcher', false, 'On a day of Expo');
        $yours = $this->tx('2025-11-06', -1200, 'CABLE SHOP', 'CABLE SHOP');
        app(AllocationWriter::class)->decide($yours, AllocationWriter::lines(-1200, 'business_expense', $this->expense(), $job->id));
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-11-05', 'ends_on' => '2025-11-06',
            'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'job_linked_by' => 'rules']);

        foreach (['2025-11-05', '2025-11-06', '2025-11-07'] as $d) {
            $this->delete('/job-days/'.JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', $d)->value('id'))->assertSessionHas('success');
        }
        $this->assertSame(['2025-11-03', '2025-11-04', '2025-11-08', '2025-11-09'], $this->days($job));
        $this->assertNull($kit->fresh()->allocations()->where('work_job_id', $job->id)->first(), 'Its automatic suggestion was made again');
        $this->assertSame($job->id, $yours->fresh()->allocations()->value('work_job_id'), 'Your decision is kept');
        $this->assertNull($stay->fresh()->work_job_id, 'A stay on none of its nights comes off it');
    }

    // ---------------------------------------------------------------- N41: bookings found by a job's Gmail search

    public function test_n41_a_booking_first_found_by_a_jobs_search_is_read_as_a_stay(): void
    {
        $doc = $this->email('Your Premier Inn booking', 'Booking confirmed', '2025-05-01 10:00:00');
        $msg = GmailMessage::query()->create(['message_id' => 'abc', 'search' => 'job', 'status' => 'stored', 'document_id' => $doc->id, 'subject' => 'Your Premier Inn booking']);

        $this->assertNull(app(GmailScanner::class)->store('abc', 'hotels'));
        $this->assertSame('hotels', $msg->fresh()->search);
        Queue::assertPushed(ReadTravelEmail::class, fn ($j) => $j->gmailMessageId === $msg->id);
        $this->assertSame('hotels', GmailScanner::travelSearchFor('Premier Inn <noreply@email.premierinn.com>'));
        $this->assertSame('travel', GmailScanner::travelSearchFor('LNER <tickets@lner.co.uk>'));
        $this->assertNull(GmailScanner::travelSearchFor('crew@acme.example'));
    }

    // ---------------------------------------------------------------- N42: the day belongs to another job

    public function test_n42_an_automatic_cost_on_another_jobs_day_isnt_given_to_the_job_next_to_it(): void
    {
        $b = WorkJob::query()->create(['title' => 'Job B', 'starts_on' => '2025-05-13', 'ends_on' => '2025-05-13', 'status' => 'done']);
        $a = WorkJob::query()->create(['title' => 'Job A', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);
        $gaffer = $this->tx('2025-05-13', -4500, 'GAFFER CO', 'GAFFER CO');
        app(AllocationWriter::class)->decide($gaffer, AllocationWriter::lines(-4500, 'business_expense', $this->expense()), 'ai', false, 'Looks like equipment');

        app(JobGatherer::class)->gather($a, false);
        $this->assertNull($gaffer->fresh()->allocations()->value('work_job_id'));
        $this->assertSame('possible', JobFind::query()->where('work_job_id', $a->id)->where('ref', (string) $gaffer->id)->value('status'));
        app(JobGatherer::class)->gather($b, false);
        $this->assertSame($b->id, $gaffer->fresh()->allocations()->value('work_job_id'));
    }

    // ---------------------------------------------------------------- N43, N56: the job's real days

    public function test_n43_the_job_finder_doesnt_link_entries_on_the_days_between_a_rig_and_a_derig(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $expo = WorkJob::query()->create(['title' => 'Winter Expo', 'client_id' => $client->id, 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'status' => 'booked']);
        JobDays::add($expo, '2025-11-20', 'derig');
        $launch = $this->event('Acme Events product launch', '2025-11-10 09:00:00', '2025-11-10 18:00:00', 'Riverside Studios');

        app(JobFinder::class)->run('2025-26', false);
        $this->assertNull(CalendarJobLink::query()->where('event_key', $launch->key())->value('work_job_id'));
        $this->assertTrue(JobCandidate::query()->get()->contains(fn ($c) => in_array('cal:'.$launch->key(), $c->sourceKeys(), true)), 'Proposed as a job of its own');
    }

    public function test_n56_the_assistant_explainer_and_map_use_the_jobs_days(): void
    {
        $job = WorkJob::query()->create(['title' => 'Expo', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);
        JobDays::add($job, '2025-05-14', 'rig');
        JobDays::add($job, '2025-05-20', 'derig');
        $tools = app(AssistantTools::class);

        $this->assertSame([], $tools->run('calendar', ['date_from' => '2025-05-17', 'date_to' => '2025-05-17'])['jobs']);
        $this->assertSame([], $tools->run('list_jobs', ['date_from' => '2025-05-17', 'date_to' => '2025-05-17'])['jobs']);
        $this->assertSame(['2025-05-20'], array_column($tools->run('calendar', ['date_from' => '2025-05-19', 'date_to' => '2025-05-21'])['jobs'][0]['days_in_these_dates'], 'date'));
        $this->assertSame(['rig', 'derig'], array_column($tools->run('job_sheet', ['job_id' => $job->id])['days_worked'], 'kind'));

        $train = $this->tx('2025-05-17', -4500, 'TRAINLINE', 'TRAINLINE');
        $this->assertStringNotContainsString('You had work then', implode(' ', Explainer::payment($train->fresh())['notes']));
        $near = $this->tx('2025-05-19', -4500, 'TRAINLINE', 'TRAINLINE EARLY');
        $this->assertStringContainsString('You had work then', implode(' ', Explainer::payment($near->fresh())['notes']));

        $this->get('/connections?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p
            ->where('edges', fn ($e) => ! collect($e)->contains(fn ($x) => $x['from'] === 'tx:'.$train->id && $x['to'] === 'job:'.$job->id)));
    }

    // ---------------------------------------------------------------- N45, N69, N88, N103: a job's days

    public function test_n45_two_entries_on_one_day_keep_it_while_either_is_linked(): void
    {
        $rig = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 20:00:00');
        $derig = $this->event('De-rig Expo', '2025-11-07 08:00:00', '2025-11-07 13:00:00');
        $van = $this->event('Van return', '2025-11-07 15:00:00', '2025-11-07 16:00:00');
        $this->post('/calendar/job', ['key' => $rig->key(), 'date' => '2025-11-03', 'title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03']);
        $job = WorkJob::query()->sole();
        $this->post('/calendar/link-job', ['key' => $derig->key(), 'date' => '2025-11-07', 'job_id' => $job->id]);
        $this->post('/calendar/link-job', ['key' => $van->key(), 'date' => '2025-11-07', 'job_id' => $job->id]);
        $day = JobDay::query()->whereDate('on_date', '2025-11-07')->sole();
        $this->assertSame(['derig', '08:00', '16:00'], [$day->kind, $day->start_time, $day->end_time], 'The de-rig day keeps its call time and covers both');

        app(JobFinder::class)->unlinkCalendar($van, $job->id);
        $this->assertSame(['2025-11-03', '2025-11-07'], $this->days($job));
        app(JobFinder::class)->linkCalendar($van, $job, 'you');
        app(JobFinder::class)->unlinkCalendar($derig, $job->id);
        $this->assertSame(['2025-11-03', '2025-11-07'], $this->days($job), 'Still covered by the van return');
        $this->assertSame('2025-11-07', $job->fresh()->ends_on->format('Y-m-d'));
    }

    public function test_n69_changing_the_last_date_of_a_rig_and_derig_job_moves_the_derig(): void
    {
        $job = WorkJob::query()->create(['title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'status' => 'booked']);
        JobDays::add($job, '2025-11-03', 'rig');
        JobDays::add($job, '2025-11-07', 'derig', '07:00');

        $job->fresh()->update(['ends_on' => '2025-11-08']);
        $this->assertSame(['2025-11-03', '2025-11-08'], $this->days($job));
        $this->assertSame(['rig', 'derig'], JobDay::query()->orderBy('on_date')->pluck('kind')->all());
        $this->assertSame('07:00', JobDay::query()->whereDate('on_date', '2025-11-08')->value('start_time'));

        $job->fresh()->update(['ends_on' => '2025-11-06']);
        $this->assertSame(['2025-11-03', '2025-11-06'], $this->days($job));
        $this->assertSame('derig', JobDay::query()->whereDate('on_date', '2025-11-06')->value('kind'));

        // Postponed by a week: every day moves.
        $job->fresh()->update(['starts_on' => '2025-11-10', 'ends_on' => '2025-11-13']);
        $this->assertSame(['2025-11-10', '2025-11-13'], $this->days($job));
        $this->assertSame(['rig', 'derig'], JobDay::query()->orderBy('on_date')->pluck('kind')->all());
        $this->assertTrue(AuditEvent::query()->where('action', 'job.days_changed')->exists());
    }

    public function test_n88_a_long_job_keeps_all_its_days_and_its_end_date(): void
    {
        $job = WorkJob::query()->create(['title' => 'Winter season', 'starts_on' => '2026-11-01', 'ends_on' => '2027-01-15', 'status' => 'booked']);
        $this->assertCount(76, $this->days($job));
        $this->post("/jobs/{$job->id}/days", ['date' => '2026-10-31', 'kind' => 'travel'])->assertSessionHas('success');
        $this->assertSame('2027-01-15', $job->fresh()->ends_on->format('Y-m-d'));
        $this->assertTrue($job->fresh()->worksOn('2027-01-10'));

        // Jobs whose days stopped at 62 when job days were added get the rest.
        $old = WorkJob::query()->create(['title' => 'Old season', 'starts_on' => '2025-11-01', 'ends_on' => '2026-01-15', 'status' => 'done']);
        JobDay::query()->where('work_job_id', $old->id)->whereDate('on_date', '>', '2026-01-01')->delete();
        $this->assertCount(62, $this->days($old));
        (require database_path('migrations/2026_12_01_000610_jobs_fill_days_of_long_jobs.php'))->up();
        $this->assertCount(76, $this->days($old));
        $this->assertCount(77, $this->days($job), 'A job whose days you changed is left alone');
    }

    public function test_n103_changes_to_a_jobs_days_and_dates_are_in_its_history(): void
    {
        $job = WorkJob::query()->create(['title' => 'Show', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'status' => 'booked']);
        $derig = $this->event('De-rig: Show', '2025-11-07 08:00:00', '2025-11-07 12:00:00');
        app(JobFinder::class)->linkCalendar($derig, $job, 'you');
        $this->assertTrue(AuditEvent::query()->where('action', 'job.dates_changed')->exists());

        $this->post("/jobs/{$job->id}/days", ['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '05:00'])->assertSessionHas('success');
        $this->assertSame('rig', AuditEvent::query()->where('action', 'job.day_updated')->latest('id')->first()->after['kind']);

        app(JobFinder::class)->unlinkCalendar($derig, $job->id);
        $change = AuditEvent::query()->where('action', 'job.days_changed')->latest('id')->sole();
        $this->assertSame(['2025-11-07'], $change->after['removed']);
    }

    // ---------------------------------------------------------------- N55: the job's other days

    public function test_n55_the_search_suggests_the_show_and_derig_days_of_the_entry_you_made_a_job(): void
    {
        $rig = $this->event('Rig - Acme Awards', '2025-05-14 08:00:00', '2025-05-14 20:00:00', 'Grand Hall, Leeds LS1 4AP');
        $show = $this->event('Show - Acme Awards', '2025-05-15 08:00:00', '2025-05-15 23:00:00', 'Grand Hall, Leeds LS1 4AP');
        $derig = $this->event('De-rig - Acme Awards', '2025-05-17 08:00:00', '2025-05-17 14:00:00', 'Grand Hall, Leeds LS1 4AP');
        $this->post('/calendar/job', ['key' => $rig->key(), 'date' => '2025-05-14', 'title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'then' => 'job']);
        $job = WorkJob::query()->sole();

        $finds = JobFind::query()->where('type', 'calendar')->get();
        $this->assertSame('possible', $finds->first(fn ($f) => str_starts_with($f->ref, $show->key()))?->status);
        $derigFind = $finds->first(fn ($f) => str_starts_with($f->ref, $derig->key()));
        $this->assertSame('possible', $derigFind?->status);
        $this->assertStringContainsString('de-rig', $derigFind->detail);
        $this->get('/jobs/'.$job->id)->assertInertia(fn ($p) => $p->where('calendar', fn ($c) => collect($c)->contains('key', $derig->key())));

        $this->post("/job-finds/{$derigFind->id}/accept")->assertSessionHas('success');
        $this->assertSame(['2025-05-14', '2025-05-17'], $this->days($job));
        $this->assertSame('derig', JobDay::query()->whereDate('on_date', '2025-05-17')->value('kind'));
    }

    // ---------------------------------------------------------------- N44: a stay per visit

    public function test_n44_a_visit_without_a_stay_is_a_trip_each_day_even_when_another_visit_had_one(): void
    {
        $this->places();
        $job = WorkJob::query()->create(['title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-04', 'postcode' => 'M1 1AA', 'status' => 'done']);
        JobDays::add($job, '2025-11-08', 'derig');
        JobDays::add($job, '2025-11-09', 'derig');
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-04',
            'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'job_linked_by' => 'you', 'decision' => 'business']);

        $p = collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id);
        $this->assertSame(['2025-11-03', '2025-11-08', '2025-11-09'], $p['trip_dates']);
    }

    // ---------------------------------------------------------------- N67, N90, N105: undo that can't or shouldn't happen

    public function test_n67_n90_an_issued_invoice_stays_and_an_unlinked_draft_isnt_linked_again(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-7781', 'status' => 'done']);
        $issued = Invoice::query()->create(['number' => 'INV-0007', 'status' => 'draft', 'currency' => 'GBP', 'total_minor' => 120000, 'po_number' => 'PO-7781', 'work_job_id' => $job->id, 'bill_to_name' => 'Acme']);
        $issued->update(['status' => 'issued', 'issue_date' => '2025-05-20']);
        $draft = Invoice::query()->create(['status' => 'draft', 'currency' => 'GBP', 'total_minor' => 0, 'po_number' => 'po 7781', 'bill_to_name' => 'Acme']);
        app(JobGatherer::class)->gather($job, false);
        $this->assertSame($job->id, $draft->fresh()->work_job_id);

        $issuedFind = JobFind::query()->where('type', 'invoice')->where('ref', (string) $issued->id)->sole();
        $this->post("/job-finds/{$issuedFind->id}/reject")->assertSessionHas('error');
        $this->assertSame([$job->id, 'linked'], [$issued->fresh()->work_job_id, $issuedFind->fresh()->status]);

        $draftFind = JobFind::query()->where('type', 'invoice')->where('ref', (string) $draft->id)->sole();
        $this->post("/job-finds/{$draftFind->id}/reject")->assertSessionHas('success');
        $this->assertNull($draft->fresh()->work_job_id);
        $this->assertTrue(AuditEvent::query()->where('action', 'invoice.job_unlinked')->exists());
        app(JobGatherer::class)->gather($job, false);
        $this->assertNull($draft->fresh()->work_job_id, 'Not linked again by its PO');
    }

    public function test_n105_undo_on_a_payment_in_a_locked_year_says_so(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);
        $kit = $this->tx('2025-05-14', -2499, 'GAFFER CO', 'GAFFER CO');
        app(AllocationWriter::class)->decide($kit, AllocationWriter::lines(-2499, 'business_expense', $this->expense()), 'ai', false, 'Looks like equipment');
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('type', 'payment')->where('status', 'linked')->sole();
        TaxYear::track('2025-26')->update(['locked_at' => now()]);

        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('error');
        $this->assertSame('linked', $find->fresh()->status);
        $this->assertSame($job->id, $kit->fresh()->allocations()->value('work_job_id'));
    }

    // ---------------------------------------------------------------- N89, N91

    public function test_n89_undo_on_an_entry_gone_from_the_calendar_unlinks_it(): void
    {
        [$job, , $day2] = $this->acme();
        $find = JobFind::query()->where('type', 'calendar')->where('status', 'linked')->sole();
        $day2->update(['starts_at' => '2025-05-18 08:00:00', 'ends_at' => '2025-05-18 18:00:00']); // moved by the organiser
        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success');
        $this->assertNull(CalendarJobLink::query()->where('event_key', $day2->key())->value('work_job_id'));

        $again = $this->event('Acme Events Awards – drinks', '2025-05-15 19:00:00', '2025-05-15 22:00:00');
        app(JobFinder::class)->linkCalendar($again, $job, 'you');
        $f = JobFind::query()->create(['work_job_id' => $job->id, 'type' => 'calendar', 'ref' => $again->key().'|2025-05-15', 'status' => 'linked', 'label' => 'x', 'detail' => 'x']);
        $again->delete(); // gone from the feed
        $this->post("/job-finds/{$f->id}/reject")->assertSessionHas('success', fn ($m) => str_contains($m, 'no longer in your calendar'));
        $this->assertNull(CalendarJobLink::query()->where('event_key', $again->key())->value('work_job_id'));
    }

    public function test_n91_a_search_reads_the_jobs_days_once(): void
    {
        [$job] = $this->acme();
        for ($i = 0; $i < 40; $i++) {
            $this->email("Note {$i}", "Hello number {$i}.\r\n", '2025-05-0'.(($i % 9) + 1).' 10:00:00');
        }
        $count = 0;
        DB::listen(function ($q) use (&$count) {
            $count += (int) (str_contains($q->sql, 'from "job_days"') || str_contains($q->sql, 'from `job_days`'));
        });
        app(JobGatherer::class)->gather($job, false);
        $this->assertLessThan(15, $count);
    }

    // ---------------------------------------------------------------- checker follow-ups

    public function test_n45_unlinking_your_entry_doesnt_keep_its_day_for_an_entry_the_search_linked(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'booked']);
        $x = $this->event('Acme Awards', '2025-05-15 08:00:00', '2025-05-15 12:00:00');
        $y = $this->event('Acme Awards', '2025-05-15 14:00:00', '2025-05-15 18:00:00');
        $this->post('/calendar/link-job', ['key' => $x->key(), 'date' => '2025-05-15', 'job_id' => $job->id])->assertSessionHas('success');
        $this->assertSame(['2025-05-14', '2025-05-15'], $this->days($job));
        $this->assertSame([$job->id, 'rules'], [CalendarJobLink::query()->where('event_key', $y->key())->value('work_job_id'), CalendarJobLink::query()->where('event_key', $y->key())->value('linked_by')]);

        app(JobFinder::class)->unlinkCalendar($x, $job->id);
        $this->assertSame(['2025-05-14'], $this->days($job), 'An entry the search linked never adds (or keeps) a day');
        $this->assertNull(CalendarJobLink::query()->where('event_key', $y->key())->value('work_job_id'), 'Linked only because 15 May was a job day');
        $this->assertNotContains($job->id, CalendarJobLink::query()->where('event_key', $y->key())->value('rejected') ?? [], 'Not remembered as your “not this job”');
        $this->assertFalse(JobFind::query()->where('type', 'calendar')->where('ref', 'like', $y->key().'|%')->where('status', 'linked')->exists());
    }

    public function test_n6_a_shift_starting_after_midnight_isnt_a_late_finish(): void
    {
        $this->places();
        $job = WorkJob::query()->create(['title' => 'Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'postcode' => 'M1 1AA', 'status' => 'booked']);
        JobDays::add($job, '2025-11-04', 'derig', '00:00', '04:00', null, 'you');
        $night = app(TravelDays::class)->night('2025-11-04');
        $this->assertSame('review', $night['verdict'], $night['reason']);

        // Work that runs past midnight is still a late finish.
        JobDays::add($job, '2025-11-04', 'derig', '19:00', '01:30', null, 'you');
        $this->assertSame('business', app(TravelDays::class)->night('2025-11-04')['verdict']);

        // So is the job's own 19:00 to 01:30 on days with no times of their own.
        $gigs = WorkJob::query()->create(['title' => 'Two gigs', 'starts_on' => '2025-12-01', 'ends_on' => '2025-12-02', 'start_time' => '19:00', 'end_time' => '01:30', 'postcode' => 'M1 1AA', 'status' => 'booked']);
        $this->assertSame(['2025-12-01', '2025-12-02'], $gigs->fresh()->workDates());
        $this->assertSame('business', app(TravelDays::class)->night('2025-12-02')['verdict']);
    }

    public function test_n8_an_uploaded_po_starting_with_a_to_line_is_still_read(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $client->id, 'location' => 'Grand Hall, Leeds', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'booked']);
        $text = "To: Alex Smith\nAcme Events Ltd purchase order\nEvent: Acme Awards at the Grand Hall\nShow date: 14 May 2025\nTotal: £1,200";
        $po = Document::query()->create(['source' => 'upload', 'original_name' => 'po.pdf', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('c', 64),
            'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted', 'received_at' => '2025-05-02 10:00:00', 'text' => $text]);
        $this->assertSame($text, JobGatherer::withoutHeaders($text));
        $printed = "From: Acme Events Ltd\nTo: Alex Smith\nShow date: 14 May 2025\nVenue: Grand Hall";
        $this->assertSame($printed, JobGatherer::withoutHeaders($printed), 'A From: line without a sent date isn’t an email’s headers');

        app(JobGatherer::class)->gather($job, false);
        $this->assertSame([$job->id, 'rules'], [$po->fresh()->work_job_id, $po->fresh()->job_linked_by]);
        $this->assertSame('linked', JobFind::query()->where('type', 'document')->where('ref', (string) $po->id)->value('status'));

        // A real email's headers, and those of an email quoted in it, still go.
        $this->assertSame("Hello\n\nThanks", JobGatherer::withoutHeaders("From: a@b.example\nTo: me@example.com\nDate: Wed, 14 May 2025 10:00:00 +0100\nSubject: Hi\n\nHello\n\nFrom: Acme Events\nSent: 14 May 2025 09:00\nTo: me\nSubject: Re: Hi\nThanks"));
    }

    public function test_n8_a_wrapped_or_html_reply_header_isnt_the_jobs_date(): void
    {
        [$job] = $this->acme();
        $wrapped = $this->email('Re: next year', "Sounds good.\r\n\r\nOn Wed, 14 May 2025 at 10:00, Acme Events Ltd <crew@acme.example>\r\nwrote:\r\n> Shall we talk about next year?\r\n", '2025-05-20 10:00:00');
        $raw = "From: crew@acme.example\r\nSubject: Re: next year again\r\nDate: Tue, 20 May 2025 11:00:00 +0100\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
            .'<div>Sounds good.</div><div class="gmail_quote">On Wed, 14 May 2025 at 10:00, Acme Events &lt;crew@acme.example&gt; wrote:<blockquote>Shall we talk about next year?</blockquote></div>';
        $html = app(DocumentIntake::class)->store($raw, 'html.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-05-20 11:00:00']);
        $this->assertStringNotContainsString('14 May 2025', JobGatherer::withoutHeaders((string) $html->text));

        app(JobGatherer::class)->gather($job, false);
        $this->assertNull($wrapped->fresh()->work_job_id, 'A reply header wrapped over two lines');
        $this->assertNull($html->fresh()->work_job_id, 'A reply header in an HTML-only email');
        $this->assertSame('possible', JobFind::query()->where('type', 'document')->where('ref', (string) $wrapped->id)->value('status'));
    }

    public function test_n8_a_vague_venue_doesnt_link_a_document(): void
    {
        $job = WorkJob::query()->create(['title' => 'Corporate do', 'location' => 'London', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'booked']);
        $mum = $this->email('Dinner?', "Fancy dinner in London on 14 May 2025? Mum x\r\n", '2025-05-10 10:00:00');
        app(JobGatherer::class)->gather($job, false);
        $this->assertNull($mum->fresh()->work_job_id);
        $this->assertSame('possible', JobFind::query()->where('type', 'document')->where('ref', (string) $mum->id)->value('status'), 'Still listed for you to decide');

        // Nor does the job finder link one for a job in "Manchester".
        WorkJob::query()->create(['title' => 'Product launch', 'location' => 'Manchester', 'starts_on' => '2025-05-21', 'ends_on' => '2025-05-21', 'status' => 'booked']);
        $invite = Document::query()->create(['source' => 'gmail', 'original_name' => 'mail.eml', 'mime' => 'message/rfc822', 'size' => 1, 'sha256' => str_repeat('d', 64),
            'stored_path' => 'x', 'kind' => 'job_invite', 'status' => 'extracted', 'email_subject' => 'Dinner?', 'received_at' => '2025-05-05 10:00:00',
            'text' => "From: mum@example.com\nTo: me@example.com\nDate: Mon, 5 May 2025 10:00:00 +0100\nSubject: Dinner?\n\nFancy dinner in Manchester on 21 May 2025? Mum x",
            'extraction' => ['new_job' => ['title' => 'Dinner', 'venue' => 'Manchester', 'starts_on' => '2025-05-21', 'verified' => false]]]);
        app(JobFinder::class)->run('2025-26', false);
        $this->assertNull($invite->fresh()->work_job_id);
    }

    public function test_n8_a_date_in_the_subject_counts(): void
    {
        [$job] = $this->acme();
        $sheet = $this->email('Call sheet - Acme Awards - Wed 14 May 2025', "Crew call 07:00 at the Grand Hall.\r\n", '2025-05-01 10:00:00');
        app(JobGatherer::class)->gather($job, false);
        $this->assertSame([$job->id, 'rules'], [$sheet->fresh()->work_job_id, $sheet->fresh()->job_linked_by]);
    }

    public function test_n67_undo_on_a_void_invoice_hides_it_and_says_why(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-7781', 'status' => 'done']);
        $void = Invoice::query()->create(['number' => 'INV-0008', 'status' => 'draft', 'currency' => 'GBP', 'total_minor' => 120000, 'po_number' => 'PO-7781', 'work_job_id' => $job->id, 'bill_to_name' => 'Acme']);
        $void->update(['status' => 'issued', 'issue_date' => '2025-05-20']);
        $void->update(['status' => 'void', 'void_reason' => 'Wrong amount']);
        app(JobGatherer::class)->gather($job, false);
        $this->assertFalse(JobFind::query()->where('type', 'invoice')->where('ref', (string) $void->id)->exists(), 'A void invoice isn’t listed as found');

        // One listed before it was voided: Undo hides it (the invoice is left as it is).
        $find = JobFind::query()->create(['work_job_id' => $job->id, 'type' => 'invoice', 'ref' => (string) $void->id, 'status' => 'linked', 'label' => 'Invoice INV-0008', 'detail' => 'x']);
        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success', fn ($m) => str_contains($m, 'void'));
        $this->assertSame(['rejected', $job->id, 'void'], [$find->fresh()->status, $void->fresh()->work_job_id, $void->fresh()->status]);

        $paid = Invoice::query()->create(['number' => 'INV-0009', 'status' => 'draft', 'currency' => 'GBP', 'total_minor' => 120000, 'po_number' => 'PO-7781', 'work_job_id' => $job->id, 'bill_to_name' => 'Acme']);
        $paid->update(['status' => 'issued', 'issue_date' => '2025-05-21']);
        $paid->update(['status' => 'paid']);
        app(JobGatherer::class)->gather($job, false);
        $find = JobFind::query()->where('type', 'invoice')->where('ref', (string) $paid->id)->sole();
        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('error', fn ($m) => str_contains($m, 'has been paid'));
        $this->assertSame($job->id, $paid->fresh()->work_job_id);
    }

    public function test_n39_saving_the_job_form_unchanged_doesnt_move_a_days_times(): void
    {
        $this->places();
        $rig = $this->event('Rig: Expo', '2025-11-03 08:00:00', '2025-11-03 23:00:00', 'Manchester Central M1 1AA');
        $derig = $this->event('De-rig: Expo', '2025-11-07 08:00:00', '2025-11-07 13:00:00');
        $this->post('/calendar/job', ['key' => $rig->key(), 'date' => '2025-11-03', 'title' => 'Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03',
            'start_time' => '08:00', 'end_time' => '23:00', 'postcode' => 'M1 1AA', 'status' => 'booked'])->assertSessionHas('success');
        $job = WorkJob::query()->sole();
        $this->post('/calendar/link-job', ['key' => $derig->key(), 'date' => '2025-11-07', 'job_id' => $job->id]);
        $this->assertSame('13:00', JobDay::query()->whereDate('on_date', '2025-11-07')->value('end_time'));
        $before = app(TravelDays::class)->night('2025-11-07')['verdict'];
        DB::table('work_jobs')->where('id', $job->id)->update(['start_time' => '08:00:00', 'end_time' => '23:00:00']); // as MariaDB returns a TIME
        $audits = AuditEvent::query()->where('action', 'job.day_updated')->count();

        $this->patch("/jobs/{$job->id}", ['title' => 'Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-07', 'start_time' => '08:00', 'end_time' => '23:00',
            'postcode' => 'M1 1AA', 'fee' => '450.00', 'status' => 'booked'])->assertSessionHas('success');
        $this->assertSame(45000, $job->fresh()->fee_pence);
        $this->assertSame('13:00', JobDay::query()->whereDate('on_date', '2025-11-07')->value('end_time'), 'The de-rig keeps its own finish');
        $this->assertSame($audits, AuditEvent::query()->where('action', 'job.day_updated')->count(), 'Nothing changed, so nothing in the history');
        $this->assertSame($before, app(TravelDays::class)->night('2025-11-07')['verdict']);

        // A real change to the finish moves only a day that had the job's finish: not the de-rig's own.
        $this->patch("/jobs/{$job->id}", ['title' => 'Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-07', 'start_time' => '07:00', 'end_time' => '22:00',
            'postcode' => 'M1 1AA', 'fee' => '450.00', 'status' => 'booked'])->assertSessionHas('success');
        $this->assertSame(['07:00', '13:00'], [JobDay::query()->whereDate('on_date', '2025-11-03')->value('start_time'), JobDay::query()->whereDate('on_date', '2025-11-07')->value('end_time')]);
    }
}
