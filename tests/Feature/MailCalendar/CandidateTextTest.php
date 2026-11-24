<?php

namespace Tests\Feature\MailCalendar;

use App\Domain\Intel\Vetoes;
use App\Domain\Jobs\CandidateText;
use App\Domain\Jobs\JobFinder;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Client;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Mail and calendar batch 1: a possible job from the calendar says so, opens the entry and offers "Part of". */
class CandidateTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-24 10:00:00'));
    }

    private function entry(string $uid, string $start, string $end, string $summary, ?string $location = 'Acme Arena, Leeds LS1 1AA', bool $allDay = false): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => $uid, 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'summary' => $summary, 'location' => $location]);
    }

    private function candidate(string $title, string $from, string $to, array $sources, array $extra = []): JobCandidate
    {
        return JobCandidate::query()->create($extra + ['status' => 'open', 'title' => $title, 'starts_on' => $from, 'ends_on' => $to, 'sources' => $sources, 'score' => 3, 'found_by' => 'rules']);
    }

    private function fromCalendar(CalendarEvent $e, array $extra = []): JobCandidate
    {
        return $this->candidate($e->summary, $e->day(), $e->lastDay()->format('Y-m-d'),
            [['key' => 'cal:'.$e->key(), 'type' => 'calendar', 'id' => $e->key(), 'label' => $e->summary, 'date' => $e->day(), 'to' => $e->lastDay()->format('Y-m-d'), 'verified' => true]], $extra);
    }

    public function test_a_calendar_possible_job_says_where_it_came_from_and_opens_the_entry(): void
    {
        $e = $this->entry('awards-1', '2026-12-03 08:00:00', '2026-12-03 18:00:00', 'Acme Awards rig');
        $c = $this->fromCalendar($e, ['location' => 'Acme Arena, Leeds LS1 1AA']);

        $text = CandidateText::for($c);
        $this->assertSame('Possible job: Acme Awards rig', $text['title']);
        $this->assertSame('From your calendar: Thu 3 Dec, 08:00–18:00, Acme Arena, Leeds LS1 1AA', $text['detail']);
        $this->assertSame('/jobs/calendar?m=2026-12&entry='.$e->key(), $text['href']);
        $this->assertTrue($text['from_calendar']);
        $this->assertNull($text['part_of']);
    }

    public function test_summer_times_all_day_and_multi_day_entries_read_in_uk_time(): void
    {
        // 07:00 UTC in July is 08:00 in London; a different year shows the year.
        $summer = $this->fromCalendar($this->entry('summer-1', '2027-07-06 07:00:00', '2027-07-06 17:00:00', 'Acme Festival'));
        $this->assertSame('From your calendar: Tue 6 Jul 2027, 08:00–18:00, Acme Arena, Leeds LS1 1AA', CandidateText::for($summer)['detail']);

        $allDay = $this->fromCalendar($this->entry('day-1', '2026-12-08 00:00:00', '2026-12-09 00:00:00', 'Acme load-in', null, true));
        $this->assertSame('From your calendar: Tue 8 Dec, all day', CandidateText::for($allDay)['detail']);

        $days = $this->fromCalendar($this->entry('days-1', '2026-12-10 00:00:00', '2026-12-13 00:00:00', 'Acme Expo', 'https://meet.google.com/abc', true));
        $this->assertSame('From your calendar: Thu 10 Dec to Sat 12 Dec', CandidateText::for($days)['detail'], 'A meeting link is not a place');
    }

    public function test_an_entry_gone_from_the_feed_reads_from_what_was_kept_and_others_read_as_before(): void
    {
        $gone = $this->candidate('Acme rig', '2026-12-03', '2026-12-03',
            [['key' => 'cal:'.str_repeat('a', 40), 'type' => 'calendar', 'id' => str_repeat('a', 40), 'label' => 'Acme rig', 'date' => '2026-12-03', 'to' => '2026-12-03'],
                ['key' => 'doc:7', 'type' => 'document', 'id' => 7, 'label' => 'PO 4471', 'date' => '2026-12-03', 'to' => '2026-12-03']],
            ['start_time' => '08:00', 'end_time' => '18:00', 'location' => 'Acme Arena']);
        $text = CandidateText::for($gone);
        $this->assertSame('No longer in your calendar: Thu 3 Dec, 08:00–18:00, Acme Arena · and 1 other source', $text['detail'], 'Cancelled or deleted since: it says so');
        $this->assertSame('/jobs/finder', $text['href'], 'There is no entry to open');
        $this->assertNull($text['part_of']);
        $this->assertStringStartsWith('1|', $text['sort'], 'Not counted as coming up soon');

        $doc = $this->candidate('Acme Expo', '2026-12-03', '2026-12-04', [['key' => 'doc:9', 'type' => 'document', 'id' => 9, 'label' => 'Booking']], ['client_name' => 'Acme Events']);
        $text = CandidateText::for($doc);
        $this->assertSame('3 Dec 2026 · Acme Events · from 1 source', $text['detail']);
        $this->assertSame('/connections?year=2026-27', $text['href']);
        $this->assertFalse($text['from_calendar']);
    }

    public function test_part_of_a_job_within_a_few_days_with_the_same_client_or_place(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $other = Client::query()->create(['name' => 'Smith Productions']);
        $awards = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $acme->id, 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-05', 'status' => 'booked']);
        WorkJob::query()->create(['title' => 'Smith gala', 'client_id' => $other->id, 'starts_on' => '2026-12-03', 'ends_on' => '2026-12-03', 'status' => 'booked', 'location' => 'Town Hall']);
        $e = $this->entry('awards-1', '2026-12-03 08:00:00', '2026-12-03 18:00:00', 'Rig day');

        $text = CandidateText::for($this->fromCalendar($e, ['client_name' => 'Acme Events']));
        $this->assertSame(['job_id' => $awards->id, 'title' => 'Acme Awards', 'label' => 'Part of Acme Awards',
            'href' => '/jobs/calendar?m=2026-12&entry='.$e->key().'&job='.$awards->id], $text['part_of']);

        // The same place, no client: still offered; a different client and place on the same day never is.
        $place = $this->fromCalendar($this->entry('derig-1', '2026-12-06 08:00:00', '2026-12-06 12:00:00', 'De-rig', 'Town Hall, Leeds'), ['location' => 'Town Hall, Leeds']);
        $this->assertSame('Smith gala', CandidateText::for($place)['part_of']['title'] ?? null, 'Within 3 days, place named in the other');
        $far = $this->fromCalendar($this->entry('far-1', '2026-12-12 08:00:00', '2026-12-12 12:00:00', 'Acme again'), ['client_name' => 'Acme Events']);
        $this->assertNull(CandidateText::for($far)['part_of'], 'A week away is not part of it');
        $unrelated = $this->fromCalendar($this->entry('x-1', '2026-12-03 19:00:00', '2026-12-03 22:00:00', 'Dinner', 'Somewhere else'));
        $this->assertNull(CandidateText::for($unrelated)['part_of']);

        // Two equally near: none offered, so the wrong one is never suggested.
        WorkJob::query()->create(['title' => 'Acme Expo', 'client_id' => $acme->id, 'starts_on' => '2026-12-02', 'ends_on' => '2026-12-02', 'status' => 'booked']);
        $this->assertNull(CandidateText::for($this->fromCalendar($e, ['client_name' => 'Acme Events']))['part_of']);
    }

    public function test_the_next_fourteen_days_come_first(): void
    {
        $later = $this->candidate('Spring show', '2027-03-01', '2027-03-01', []);
        $past = $this->candidate('Autumn show', '2026-10-01', '2026-10-01', []);
        $soon = $this->candidate('Acme Awards', '2026-12-03', '2026-12-03', []);
        $ongoing = $this->candidate('Acme Expo', '2026-11-22', '2026-11-25', []);
        $justAfter = $this->candidate('Winter ball', '2026-12-09', '2026-12-09', []);

        $order = array_keys(CandidateText::forAll(JobCandidate::query()->get()));
        $this->assertSame([$ongoing->id, $soon->id, $past->id, $justAfter->id, $later->id], $order, 'Under way and within 14 days first; then the rest by date');
    }

    public function test_the_calendar_page_opens_the_entry_on_part_of(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $e = $this->entry('awards-1', '2026-12-03 08:00:00', '2026-12-03 18:00:00', 'Rig day');
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-05', 'status' => 'booked']);

        $this->get('/jobs/calendar?m=2026-12&entry='.$e->key().'&job='.$job->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('jobs/calendar', false)->where('open.key', $e->key())->where('open.job_id', $job->id));
        $this->get('/jobs/calendar?m=2026-12&entry=not-a-key')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('open', null));
    }

    public function test_part_of_is_never_offered_for_a_job_you_said_it_isnt_part_of(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $awards = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $acme->id, 'starts_on' => '2026-12-03', 'ends_on' => '2026-12-04', 'status' => 'booked']);
        $e = $this->entry('dinner-1', '2026-12-06 18:00:00', '2026-12-06 22:00:00', 'Acme Events dinner');
        $c = $this->fromCalendar($e, ['client_name' => 'Acme Events']);
        $this->assertSame($awards->id, CandidateText::for($c)['part_of']['job_id'] ?? null);

        // "Not this job" on the entry: not offered again, here or in a list.
        app(JobFinder::class)->rejectCalendarFor($e, $awards->id);
        $this->assertFalse(Vetoes::allows('calendar_job', 'cal:'.$e->key(), 'job:'.$awards->id), 'The same store Vetoes reads');
        $this->assertNull(CandidateText::for($c)['part_of']);
        $this->assertNull(CandidateText::forAll([$c->fresh()])[$c->id]['part_of']);

        // The entry is "not a job": nothing is offered at all, even a job you haven't said no to.
        CalendarJobLink::query()->where('event_key', $e->key())->update(['rejected' => null, 'not_job' => true]);
        $this->assertNull(CandidateText::for($c)['part_of']);

        // A document of it that you said isn't the job's.
        $doc = Document::query()->create(['source' => 'email', 'kind' => 'booking', 'original_name' => 'Booking.eml', 'stored_path' => 'x', 'sha256' => str_repeat('b', 64), 'job_rejected' => [$awards->id]]);
        $fromDoc = $this->candidate('Acme Events dinner', '2026-12-06', '2026-12-06', [['key' => 'doc:'.$doc->id, 'type' => 'document', 'id' => $doc->id, 'label' => 'Booking']], ['client_name' => 'Acme Events']);
        $this->assertNull(CandidateText::for($fromDoc)['part_of']);
        $this->assertNull(CandidateText::forAll([$fromDoc])[$fromDoc->id]['part_of']);
    }

    public function test_a_place_that_could_be_anywhere_or_another_clients_venue_is_not_the_same_job(): void
    {
        $smith = Client::query()->create(['name' => 'Smith Productions']);
        $acme = Client::query()->create(['name' => 'Acme Events Ltd']);
        WorkJob::query()->create(['title' => 'Smith Expo', 'client_id' => $smith->id, 'starts_on' => '2026-12-05', 'ends_on' => '2026-12-06', 'status' => 'booked', 'location' => 'ExCeL London']);

        // "London" is inside "ExCeL London", but says nothing about the job.
        $london = $this->fromCalendar($this->entry('acme-1', '2026-12-06 08:00:00', '2026-12-06 18:00:00', 'Acme show', 'London'), ['location' => 'London']);
        $this->assertNull(CandidateText::for($london)['part_of']);

        // The same venue, but both name a client and they differ: two clients' work.
        $venue = $this->fromCalendar($this->entry('acme-2', '2026-12-07 08:00:00', '2026-12-07 18:00:00', 'Acme show', 'ExCeL London, Royal Victoria Dock'),
            ['location' => 'ExCeL London, Royal Victoria Dock', 'client_name' => 'Acme Events']);
        $this->assertNull(CandidateText::for($venue)['part_of']);

        // No client on the possible job: the venue (before the comma) is enough. A word for a kind of place isn't.
        $noClient = $this->fromCalendar($this->entry('x-2', '2026-12-07 08:00:00', '2026-12-07 18:00:00', 'De-rig', 'ExCeL London, Royal Victoria Dock'),
            ['location' => 'ExCeL London, Royal Victoria Dock']);
        $this->assertSame('Smith Expo', CandidateText::for($noClient)['part_of']['title'] ?? null);
        $hall = $this->fromCalendar($this->entry('x-3', '2026-12-07 08:00:00', '2026-12-07 18:00:00', 'De-rig', 'Hall'), ['location' => 'Hall']);
        $this->assertNull(CandidateText::for($hall)['part_of']);
        // Part of a word isn't the place: "Lond" is not in "ExCeL London".
        $part = $this->fromCalendar($this->entry('x-4', '2026-12-07 08:00:00', '2026-12-07 18:00:00', 'De-rig', 'ExCeL Lond'), ['location' => 'ExCeL Lond']);
        $this->assertNull(CandidateText::for($part)['part_of']);

        // A vague-place job on the same day no longer hides your own client's job a day away. "Ltd" doesn't matter.
        $awards = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $acme->id, 'starts_on' => '2026-12-09', 'ends_on' => '2026-12-09', 'status' => 'booked']);
        WorkJob::query()->create(['title' => 'Rig', 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-10', 'status' => 'booked', 'location' => 'London']);
        $derig = $this->fromCalendar($this->entry('acme-3', '2026-12-10 08:00:00', '2026-12-10 12:00:00', 'Acme de-rig', 'London'), ['location' => 'London', 'client_name' => 'Acme Events']);
        $this->assertSame($awards->id, CandidateText::for($derig)['part_of']['job_id'] ?? null);
    }
}
