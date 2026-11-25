<?php

namespace Tests\Feature;

use App\Domain\Documents\DocumentIntake;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * What the checkers found in picking a job's days on the calendar, each case as they found it: what the review shows
 * unticked isn't linked a moment later, a "not part of this job" (an entry's or a document's) isn't lifted by a tick
 * Expend put on, the days you pick stay yours, a link is always read again under the audit head, and dates (small
 * hours, long seasons, busy calendars, 29 February) come out right. Runs on SQLite and MariaDB.
 */
class CalendarPickRecheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-11-25 12:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function event(string $summary, string $start, ?string $end = null, ?string $location = null, bool $allDay = false): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => $allDay, 'summary' => $summary, 'location' => $location]);
    }

    private function day(string $date, string $kind = 'work', ?string $start = null, ?string $end = null, ?string $note = null): array
    {
        return ['date' => $date, 'kind' => $kind, 'start_time' => $start, 'end_time' => $end, 'note' => $note];
    }

    private function entry(CalendarEvent $e, ?string $on = null, string $seen = 'free', array $extra = []): array
    {
        return $extra + ['key' => $e->key(), 'date' => $e->day(), 'on' => $on ?? $e->day(), 'seen' => $seen, 'take' => true];
    }

    private function create(array $days, array $entries = [], array $extra = []): TestResponse
    {
        return $this->post('/calendar/pick', $extra + ['pick' => AuditContext::newCorrelation(), 'days' => $days, 'entries' => $entries, 'title' => 'Winter Expo', 'status' => 'booked']);
    }

    private function add(WorkJob $job, array $days, array $entries = [], array $extra = []): TestResponse
    {
        return $this->post("/jobs/{$job->id}/days/pick", $extra + ['pick' => AuditContext::newCorrelation(), 'days' => $days, 'entries' => $entries]);
    }

    private function job(array $dates, string $title = 'Acme Awards'): WorkJob
    {
        return app(JobCreator::class)->create(['title' => $title, 'dates' => $dates], 'you', [], null, false);
    }

    private function link(CalendarEvent $e): ?CalendarJobLink
    {
        return CalendarJobLink::query()->where('event_key', $e->key())->first();
    }

    /** @return list<array{0:string,1:string,2:?string,3:?string,4:?string}> date, kind, start, finish, note */
    private function rows(WorkJob $job): array
    {
        return $job->days()->get()->map(fn (JobDay $d) => [$d->on_date->format('Y-m-d'), $d->kind, $d->start_time ? substr($d->start_time, 0, 5) : null,
            $d->end_time ? substr($d->end_time, 0, 5) : null, $d->note])->all();
    }

    /** Runs $write once, the first time the audit head is locked from now on: another save committing just then. */
    private function onHead(callable $write): void
    {
        $done = false;
        DB::listen(function ($q) use (&$done, $write) {
            if (! $done && str_contains($q->sql, 'audit_heads') && str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $done = true;
                $write();
            }
        });
    }

    // ---------------------------------------------------------------- what you saw is what's acted on

    public function test_adding_to_a_job_an_entry_with_the_jobs_title_is_ticked_so_unticking_it_leaves_it_out(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig']], 'Northern Lights');
        $rig = $this->event('Rig: Northern Lights', '2025-11-03 08:00:00', '2025-11-03 18:00:00', 'Media City, M50 2EQ');
        app(JobFinder::class)->linkCalendar($rig, $job, 'you', 'Linked by you');
        $same = $this->event('Northern Lights', '2025-11-05 19:00:00', '2025-11-05 20:00:00');

        $preview = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-05'], 'job_id' => $job->id])->assertOk()->json();
        // The review ticks by the titles of every entry on the job, not only those on the days picked.
        $this->assertSame(['northern lights'], $preview['job']['bases']);
        $entry = collect($preview['entries'])->firstWhere('key', $same->key());
        $this->assertTrue($entry['default_include']);

        // Unticked in the review, it's left out: the job's search after the save leaves it.
        $this->add($job, [$this->day('2025-11-05')], [], ['left_out' => [['key' => $same->key(), 'date' => '2025-11-05']]])->assertSessionHas('success');
        $this->assertNull($this->link($same)->work_job_id);
        $this->assertSame([$job->id], $this->link($same)->rejected);
    }

    public function test_closing_a_possible_job_never_links_a_document_you_said_isnt_part_of_the_job(): void
    {
        $job = $this->job([['date' => '2025-03-03', 'kind' => 'show']], 'Big Festival');
        $raw = "From: buyer@acme.example\r\nSubject: Purchase order PO-7781\r\nDate: Mon, 3 Mar 2025 10:00:00 +0000\r\n\r\nPlease find PO PO-7781 for the Big Festival.\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $doc->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => ['summary' => 'PO', 'new_job' => null, 'po' => ['po_number' => 'PO-7781', 'lines' => []], 'unverified_count' => 0]]);
        app(JobFinder::class)->rejectDocumentFor($doc->fresh(), $job);
        $derig = $this->event('De-rig: Big Festival', '2025-03-05 08:00:00', '2025-03-05 14:00:00', 'Fields');
        $c = JobCandidate::query()->create([
            'status' => 'open', 'fingerprint' => sha1('x'), 'title' => 'Big Festival', 'starts_on' => '2025-03-05', 'ends_on' => '2025-03-05', 'score' => 6, 'found_by' => 'rules',
            'reasons' => ['why' => [], 'jobs' => []], 'sources' => [
                ['key' => 'cal:'.$derig->key(), 'type' => 'calendar', 'id' => $derig->key(), 'label' => $derig->summary, 'date' => '2025-03-05', 'to' => '2025-03-05'],
                ['key' => 'doc:'.$doc->id, 'type' => 'document', 'id' => $doc->id, 'label' => 'Purchase order PO-7781', 'date' => null, 'to' => null],
            ],
        ]);

        $preview = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-03-05'], 'job_id' => $job->id])->json();
        $this->assertTrue($preview['candidates'][0]['others'][0]['rejected'], 'The review is told, and leaves the tick off');

        // Ticked anyway (the tick was on for you): the document stays off the job, and the message says why.
        $this->add($job, [$this->day('2025-03-05', 'derig')], [$this->entry($derig)], ['candidates' => [['id' => $c->id, 'others' => true]]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Left off: “Purchase order PO-7781” (you said it isn’t part of Big Festival)'));
        $this->assertNull($doc->fresh()->work_job_id);
        $this->assertSame([$job->id], $doc->fresh()->job_rejected);
        $this->assertSame('merged', $c->fresh()->status);
    }

    public function test_a_day_you_picked_that_the_job_already_had_stays_when_its_entry_is_not_part_of_the_job(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'show']]);
        $build = $this->event('Acme Awards build', '2025-11-03 00:00:00', '2025-11-05 00:00:00', 'Leeds', true);
        app(JobFinder::class)->linkCalendar($build, $job, 'you', 'Linked by you');
        $this->assertSame('calendar', JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-04')->value('source'));
        $before = AuditEvent::query()->count();

        // 4 Nov picked exactly as the job has it, with a new day.
        $this->add($job, [$this->day('2025-11-04', 'rig'), $this->day('2025-11-06')], [$this->entry($build, '2025-11-04', 'this_job')])->assertSessionHas('success');
        $this->assertSame('you', JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-04')->value('source'));
        $change = AuditEvent::query()->where('id', '>', $before)->where('action', 'job.days_changed')->sole();
        $this->assertSame(['2025-11-04'], $change->after['yours']);

        // Later "Not part of this job": the days you picked stay (removable with ✕).
        app(JobFinder::class)->unlinkCalendar($build, $job->id);
        $this->assertSame(['2025-11-03', '2025-11-04', '2025-11-06'], $job->fresh()->workDates());
    }

    public function test_a_move_expend_ticked_doesnt_happen_once_you_confirmed_the_entry_on_its_job(): void
    {
        $gala = $this->job([['date' => '2025-11-10', 'kind' => 'show']], 'Smith Gala');
        $e = $this->event('Show: Smith Gala', '2025-11-10 18:00:00', '2025-11-10 23:00:00', 'Leeds');
        app(JobFinder::class)->linkCalendar($e, $gala, 'rules', 'The same entry as this job’s other days');
        $preview = collect($this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-10']])->json('entries'))->firstWhere('key', $e->key());
        $this->assertSame(['other_job', 'rules', true], [$preview['state'], $preview['by'], $preview['move_default']]);

        // Confirmed on Smith Gala before Create is pressed with the review's ticks as they were.
        $this->post('/jobs/finder/links', ['type' => 'calendar', 'id' => $this->link($e)->id, 'action' => 'confirm'])->assertSessionHas('success');
        $this->create([$this->day('2025-11-10', 'show')], [$this->entry($e, null, 'other_job', ['seen_job_id' => $gala->id, 'seen_by' => 'rules'])])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Not linked: “Show: Smith Gala” (you confirmed it on Smith Gala since)'));
        $this->assertSame([$gala->id, 'you'], [$this->link($e)->work_job_id, $this->link($e)->linked_by]);

        // Seen as yours and moved with Move you ticked yourself: it moves.
        $this->create([$this->day('2025-11-10', 'show')], [$this->entry($e, null, 'other_job', ['seen_job_id' => $gala->id, 'seen_by' => 'you'])], ['title' => 'Second']);
        $this->assertSame(WorkJob::query()->where('title', 'Second')->value('id'), $this->link($e)->work_job_id);
    }

    public function test_every_link_writer_reads_the_link_again_under_the_audit_head(): void
    {
        $finder = app(JobFinder::class);
        $gala = $this->job([['date' => '2025-11-10', 'kind' => 'show']], 'Smith Gala');
        $expo = $this->job([['date' => '2025-11-12', 'kind' => 'show']], 'Winter Expo');
        $e = $this->event('Show: Smith Gala', '2025-11-10 18:00:00', '2025-11-10 23:00:00', 'Leeds');
        $finder->linkCalendar($e, $gala, 'rules', 'The same entry as this job’s other days');

        // The search takes an automatic link off the job just as a pick (moving it to Winter Expo as yours) commits.
        $this->onHead(fn () => DB::table('calendar_job_links')->where('event_key', $e->key())->update(['work_job_id' => $expo->id, 'linked_by' => 'you']));
        $finder->unlinkAutomatic($e, $gala, 'Its day is no longer one of the job’s days');
        $this->assertSame([$expo->id, 'you'], [$this->link($e)->work_job_id, $this->link($e)->linked_by], 'The pick’s link stays');

        // "Not part of this job" given just as another one is recorded: neither is lost.
        $f = $this->event('Rig: Other', '2025-11-20 08:00:00', '2025-11-20 12:00:00', 'Hall');
        $finder->rejectCalendarFor($f, $gala->id);
        $this->onHead(fn () => DB::table('calendar_job_links')->where('event_key', $f->key())->update(['rejected' => json_encode([$gala->id, $expo->id])]));
        $third = $this->job([['date' => '2025-11-21', 'kind' => 'show']], 'Third');
        $finder->rejectCalendarFor($f, $third->id);
        $this->assertEqualsCanonicalizing([$gala->id, $expo->id, $third->id], $this->link($f)->rejected);
    }

    public function test_adding_to_a_job_fills_its_blank_po_and_venue_from_a_possible_job_you_edited(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'show']]);
        $derig = $this->event('De-rig: Acme Awards', '2025-11-05 08:00:00', '2025-11-05 12:00:00', 'Leeds Arena');
        $c = JobCandidate::query()->create([
            'status' => 'open', 'fingerprint' => sha1('y'), 'title' => 'Acme Awards', 'starts_on' => '2025-11-05', 'ends_on' => '2025-11-05', 'score' => 6, 'found_by' => 'rules',
            'reasons' => ['why' => [], 'jobs' => []], 'edited_at' => now(), 'po_number' => 'PO-4455', 'location' => 'Leeds Arena', 'postcode' => 'ls11 8bu',
            'sources' => [['key' => 'cal:'.$derig->key(), 'type' => 'calendar', 'id' => $derig->key(), 'label' => $derig->summary, 'date' => '2025-11-05', 'to' => '2025-11-05']],
        ]);

        $this->add($job, [$this->day('2025-11-05', 'derig')], [$this->entry($derig)], ['candidates' => [['id' => $c->id, 'others' => false]]])->assertSessionHas('success');

        $this->assertSame('merged', $c->fresh()->status);
        $this->assertSame(['PO-4455', 'Leeds Arena', 'LS11 8BU'], [$job->fresh()->po_number, $job->fresh()->location, $job->fresh()->postcode]);
        $this->assertSame('Blank details filled from the possible job you edited', AuditEvent::query()->where('action', 'job.updated')->where('subject_id', $job->id)->latest('id')->value('reason'));
    }

    public function test_moving_an_entry_off_a_job_it_brought_every_day_to_says_which_day_that_job_keeps(): void
    {
        $gala = JobDays::withoutRange(fn () => WorkJob::query()->create(['title' => 'Smith Gala', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-04', 'status' => 'booked']));
        $e = $this->event('Smith Gala', '2025-11-03 00:00:00', '2025-11-05 00:00:00', 'Leeds', true);
        // Its days, the later one first: which day stays mustn't depend on the order they were written in.
        foreach (['2025-11-04', '2025-11-03'] as $d) {
            JobDay::query()->create(['work_job_id' => $gala->id, 'on_date' => $d, 'kind' => 'show', 'source' => 'calendar', 'calendar_key' => $e->key()]);
        }
        app(JobFinder::class)->linkCalendar($e, $gala, 'you', 'Linked by you', null, null, false);

        $other = collect($this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-03']])->json('entries'))->firstWhere('key', $e->key())['other_job'];
        $this->assertSame([true, ['2025-11-03'], '2025-11-04'], [$other['only_day'], $other['loses'], $other['keeps']]);

        $this->create([$this->day('2025-11-03', 'show')], [$this->entry($e, '2025-11-03', 'other_job', ['seen_job_id' => $gala->id, 'seen_by' => 'you'])]);
        $this->assertSame(['2025-11-04'], $gala->fresh()->workDates(), 'It keeps the day the review named');
    }

    public function test_the_history_shows_days_moved_with_their_times_and_the_entry_each_picked_day_is_named_after(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '08:00', 'end_time' => '18:00', 'note' => 'Bring IEMs']]);
        $job->update(['starts_on' => '2025-11-05', 'ends_on' => '2025-11-05']);
        $moved = AuditEvent::query()->where('action', 'job.days_changed')->where('subject_id', $job->id)->latest('id')->first();
        $this->assertEquals([['date' => '2025-11-05', 'kind' => 'rig', 'start_time' => '08:00', 'end_time' => '18:00', 'note' => 'Bring IEMs']], $moved->after['days']);

        $rig = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 20:00:00', 'Hall');
        $this->create([$this->day('2025-11-03', 'rig', '08:00', '20:00'), $this->day('2025-11-04')], [$this->entry($rig)]);
        $created = AuditEvent::query()->where('action', 'job.created')->latest('id')->first();
        $this->assertSame($rig->key(), $created->after['days'][0]['calendar_key']);
        $this->assertArrayNotHasKey('calendar_key', $created->after['days'][1], 'Only a day named after an entry says so');
        $expo = WorkJob::query()->where('title', 'Winter Expo')->sole();
        $this->assertSame($rig->key(), JobDay::query()->where('work_job_id', $expo->id)->whereDate('on_date', '2025-11-03')->value('calendar_key'));

        $derig = $this->event('De-rig: Winter Expo', '2025-11-07 08:00:00', '2025-11-07 12:00:00', 'Hall');
        $this->add($expo, [$this->day('2025-11-07', 'derig')], [$this->entry($derig)]);
        $added = AuditEvent::query()->where('action', 'job.days_changed')->where('subject_id', $expo->id)->latest('id')->first();
        $this->assertSame($derig->key(), collect($added->after['days'])->firstWhere('date', '2025-11-07')['calendar_key']);
    }

    public function test_a_pick_that_cant_be_read_is_answered_in_plain_words(): void
    {
        $this->post('/calendar/pick', [
            'pick' => AuditContext::newCorrelation(), 'days' => [$this->day('2025-11-03')], 'title' => ['x'], 'location' => ['y'], 'notes' => ['z'], 'fee' => ['1'],
            'new_client' => ['n'], 'postcode' => ['p'], 'po_number' => ['q'], 'po_not_required' => 'maybe', 'candidates' => [['id' => 1, 'others' => 'maybe']],
        ])->assertStatus(302); // a fee that isn't text was a server error
        $errors = session('errors')->getBag('default')->all();
        $this->assertNotEmpty($errors);
        foreach ($errors as $m) {
            $this->assertDoesNotMatchRegularExpression('/\bfield\b|[a-z]\.\d+\.[a-z]|_/', $m, 'No field names: '.$m);
        }
        $this->post('/calendar/pick', ['pick' => AuditContext::newCorrelation(), 'days' => ['x'], 'title' => 'Winter Expo']);
        $this->assertContains('A picked day couldn’t be read. Close it and pick the days again.', session('errors')->getBag('default')->all());
    }

    public function test_the_suggested_title_keeps_day_words_that_are_part_of_the_name(): void
    {
        $e = $this->event('Rig/Show/De-rig: Spring Trade Show', '2025-11-03 08:00:00', '2025-11-03 20:00:00', 'NEC Birmingham, B40 1NT');
        $this->assertSame('Spring Trade Show', $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-03']])->json('suggest.title'));
        $f = $this->event('Frost Festival build and show', '2025-11-10 00:00:00', '2025-11-11 00:00:00', 'Hyde Park', true);
        $this->assertSame('Frost Festival build and show', $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-10']])->json('suggest.title'));
        $this->assertNotNull($e->key().$f->key());
    }

    // ---------------------------------------------------------------- dates

    public function test_a_small_hours_entry_between_two_picked_days_belongs_to_the_evening_before_only(): void
    {
        $fri = $this->event('Show: Winter Expo', '2026-12-04 19:00:00', '2026-12-04 23:00:00', 'ExCeL London');
        $getIn = $this->event('Get-in: Winter Expo', '2026-12-05 00:30:00', '2026-12-05 03:00:00', 'ExCeL London');
        $sat = $this->event('Show: Winter Expo', '2026-12-05 18:00:00', '2026-12-05 23:00:00', 'ExCeL London');

        $days = collect($this->postJson('/calendar/pick/preview', ['dates' => ['2026-12-04', '2026-12-05']])->json('dates'))->keyBy('date');
        $this->assertSame(['kind' => 'show', 'start_time' => '19:00', 'end_time' => '03:00'], $days['2026-12-04']['suggested']);
        $this->assertSame(['kind' => 'show', 'start_time' => '18:00', 'end_time' => '23:00'], $days['2026-12-05']['suggested']);

        $this->create([$this->day('2026-12-04', 'show', '19:00', '03:00'), $this->day('2026-12-05', 'show', '18:00', '23:00')], [$this->entry($fri), $this->entry($getIn, '2026-12-05'), $this->entry($sat)]);
        $job = WorkJob::query()->sole();
        $keys = $job->days()->get()->mapWithKeys(fn (JobDay $d) => [$d->on_date->format('Y-m-d') => $d->calendar_key])->all();
        $this->assertSame(['2026-12-04' => $fri->key(), '2026-12-05' => $sat->key()], $keys, 'Saturday is named after its own show, not the get-in');
    }

    public function test_on_a_busy_calendar_the_later_days_of_a_long_pick_keep_their_entries(): void
    {
        $rows = [];
        for ($i = 0, $t = Carbon::parse('2026-01-01 00:00:00'); $i < 4100; $i++, $t = $t->copy()->addHours(2)) {
            $uid = 'busy-'.$i.'@cal';
            $rows[] = ['uid' => $uid, 'event_key' => CalendarEvent::keyFor($uid, null), 'starts_at' => $t->format('Y-m-d H:i:s'), 'ends_at' => $t->copy()->addHour()->format('Y-m-d H:i:s'),
                'all_day' => false, 'summary' => 'Reminder '.$i, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('calendar_events')->insert($chunk);
        }
        $derig = $this->event('De-rig: Winter Expo', '2026-12-09 08:00:00', '2026-12-09 13:00:00', 'ExCeL London, E16 1XL');

        $preview = $this->postJson('/calendar/pick/preview', ['dates' => ['2026-01-20', '2026-12-09']])->assertOk()->json();
        $day = collect($preview['dates'])->firstWhere('date', '2026-12-09');
        $this->assertContains($derig->key(), $day['entries']);
        $this->assertSame(['kind' => 'derig', 'start_time' => '08:00', 'end_time' => '13:00'], $day['suggested']);
    }

    public function test_correcting_a_jobs_year_across_29_february_moves_every_day(): void
    {
        $job = $this->job([
            ['date' => '2028-02-28', 'kind' => 'rig', 'start_time' => '08:00', 'end_time' => '18:00', 'note' => 'Bring IEMs'],
            ['date' => '2028-03-01', 'kind' => 'show', 'start_time' => '19:00', 'end_time' => '01:30'],
            ['date' => '2028-03-03', 'kind' => 'derig'],
        ]);
        $this->patch('/jobs/'.$job->id, ['title' => 'Acme Awards', 'starts_on' => '2027-02-28', 'ends_on' => '2027-03-03'])->assertSessionHas('success');
        $this->assertSame([
            ['2027-02-28', 'rig', '08:00', '18:00', 'Bring IEMs'], ['2027-03-01', 'show', '19:00', '01:30', null], ['2027-03-03', 'derig', null, null, null],
        ], $this->rows($job));

        // 29 February moved to a year without one lands on 28 February; when that's taken, 28 February's own day stays.
        $leap = $this->job([['date' => '2028-02-28', 'kind' => 'rig'], ['date' => '2028-02-29', 'kind' => 'show'], ['date' => '2028-03-01', 'kind' => 'derig']], 'Leap');
        $leap->update(['starts_on' => '2027-02-28', 'ends_on' => '2027-03-01']);
        $this->assertSame([['2027-02-28', 'rig', null, null, null], ['2027-03-01', 'derig', null, null, null]], $this->rows($leap));
    }

    public function test_an_entry_longer_than_400_days_is_on_the_days_it_covers(): void
    {
        $season = $this->event('Acme Theatre season', '2025-09-01 00:00:00', '2026-12-01 00:00:00', 'Acme Theatre', true);

        $preview = $this->postJson('/calendar/pick/preview', ['dates' => ['2026-11-10']])->json();
        $entry = collect($preview['entries'])->firstWhere('key', $season->key());
        $this->assertNotNull($entry);
        $this->assertSame(['picked' => 1, 'total' => 456], $entry['partial']);

        $this->create([$this->day('2026-11-10', 'show')], [$this->entry($season, '2026-11-10')])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'Moved since') && str_contains($m, 'Linked 1 calendar entry'));
        $this->assertSame(WorkJob::query()->value('id'), $this->link($season)->work_job_id);
    }

    public function test_an_entry_moved_onto_another_picked_day_before_the_save_is_still_linked(): void
    {
        $derig = $this->event('De-rig: Winter Expo', '2026-12-05 08:00:00', '2026-12-05 13:00:00', 'ExCeL London');
        $derig->update(['starts_at' => '2026-12-06 08:00:00', 'ends_at' => '2026-12-06 13:00:00']); // moved in the feed meanwhile

        $this->create([$this->day('2026-12-05', 'derig'), $this->day('2026-12-06', 'derig')], [$this->entry($derig, '2026-12-05') + ['date' => '2026-12-05']])
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'Moved since'));
        $job = WorkJob::query()->sole();
        $this->assertSame($job->id, $this->link($derig)->work_job_id);
        $this->assertSame($derig->key(), JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2026-12-06')->value('calendar_key'));
    }

    public function test_an_entry_gone_from_the_calendar_is_named_by_its_day(): void
    {
        $this->create([$this->day('2026-12-05', 'derig')], [['key' => str_repeat('a', 40), 'date' => '2026-12-05', 'on' => '2026-12-05', 'seen' => 'free', 'take' => true]])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Gone from your calendar: the entry on Sat 5 Dec.'));
    }

    public function test_adding_a_day_that_would_make_the_job_longer_than_400_days_is_flagged_before_the_save(): void
    {
        $job = $this->job([['date' => '2026-01-01', 'kind' => 'show'], ['date' => '2026-12-31', 'kind' => 'show']]);
        $days = collect($this->postJson('/calendar/pick/preview', ['dates' => ['2026-12-30', '2027-02-05'], 'job_id' => $job->id])->json('dates'))->keyBy('date');
        $this->assertSame([false, true], [$days['2026-12-30']['too_long'], $days['2027-02-05']['too_long']]);
    }

    public function test_the_calendar_shows_this_month_for_a_month_that_doesnt_exist(): void
    {
        foreach (['2026-13', '2026-00'] as $m) {
            $this->get('/jobs/calendar?m='.$m)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('jobs/calendar', false)->where('month', '2026-11'));
        }
    }

    public function test_a_new_job_has_its_own_times_only_when_both_are_known(): void
    {
        $this->create([$this->day('2026-11-29', 'travel'), $this->day('2026-12-07', 'derig', '08:00', '13:00')]);
        $job = WorkJob::query()->sole();
        $this->assertSame([null, null], [$job->start_time, $job->end_time]);
    }
}
