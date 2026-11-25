<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;
use App\Domain\Audit\AuditLog;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\DayPick;
use App\Domain\Jobs\JobCreator;
use App\Domain\Jobs\JobFinder;
use App\Domain\Mileage\JobTrips;
use App\Jobs\GatherForJob;
use App\Jobs\GatherFromGmail;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\CalendarPick;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\JobCandidate;
use App\Models\JobDay;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Picking days on the calendar (a rig, show days and a de-rig, not one after another) and making them one job, or
 * adding them to one: what you saw is what's acted on, the days are yours, an entry you untick is "not part of this
 * job", and one pick never makes two jobs. Runs on SQLite and MariaDB.
 */
class CalendarPickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        // Days in 2025 are named with their year ("Mon 3 Nov 2025"), as they're not this year's.
        Carbon::setTestNow('2026-11-25 12:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function event(string $summary, string $start, ?string $end = null, ?string $location = null, bool $allDay = false, ?string $recurrence = null): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'recurrence_id' => $recurrence, 'starts_at' => $start, 'ends_at' => $end,
            'all_day' => $allDay, 'summary' => $summary, 'location' => $location]);
    }

    private function token(): string
    {
        return AuditContext::newCorrelation();
    }

    private function day(string $date, string $kind = 'work', ?string $start = null, ?string $end = null, ?string $note = null): array
    {
        return ['date' => $date, 'kind' => $kind, 'start_time' => $start, 'end_time' => $end, 'note' => $note];
    }

    private function entry(CalendarEvent $e, ?string $on = null, string $seen = 'free', array $extra = []): array
    {
        return $extra + ['key' => $e->key(), 'date' => $e->day(), 'on' => $on ?? $e->day(), 'seen' => $seen, 'take' => false];
    }

    private function create(array $days, array $entries = [], array $extra = []): TestResponse
    {
        return $this->post('/calendar/pick', $extra + ['pick' => $this->token(), 'days' => $days, 'entries' => $entries, 'title' => 'Winter Expo', 'status' => 'done']);
    }

    private function add(WorkJob $job, array $days, array $entries = [], array $extra = []): TestResponse
    {
        return $this->post("/jobs/{$job->id}/days/pick", $extra + ['pick' => $this->token(), 'days' => $days, 'entries' => $entries]);
    }

    /** @return list<int> what a refused pick must leave as it was */
    private function counts(): array
    {
        return [WorkJob::query()->count(), JobDay::query()->count(), CalendarJobLink::query()->count(), Client::query()->count(), AuditEvent::query()->count(), CalendarPick::query()->count()];
    }

    /** @return list<array{0:string,1:string}> the job's days as [date, kind] */
    private function days(WorkJob $job): array
    {
        return $job->days()->get()->map(fn (JobDay $d) => [$d->on_date->format('Y-m-d'), $d->kind])->all();
    }

    private function link(CalendarEvent $e): ?CalendarJobLink
    {
        return CalendarJobLink::query()->where('event_key', $e->key())->first();
    }

    private function job(array $dates, string $title = 'Acme Awards'): WorkJob
    {
        return app(JobCreator::class)->create(['title' => $title, 'dates' => $dates], 'you', [], null, false);
    }

    private function poDocument(string $po = 'PO-7781'): Document
    {
        $raw = "From: buyer@acme.example\r\nSubject: Purchase order {$po}\r\nDate: Mon, 3 Mar 2025 10:00:00 +0000\r\n\r\nPlease find PO {$po} for the Big Festival.\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $doc->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => ['summary' => 'PO for the Big Festival', 'new_job' => null, 'po' => ['po_number' => $po, 'lines' => []], 'unverified_count' => 0]]);

        return $doc->fresh();
    }

    private function candidate(array $events, array $docs = [], array $fields = []): JobCandidate
    {
        $sources = [
            ...array_map(fn (CalendarEvent $e) => ['key' => 'cal:'.$e->key(), 'type' => 'calendar', 'id' => $e->key(), 'label' => $e->summary, 'date' => $e->day(), 'to' => $e->day()], $events),
            ...array_map(fn (Document $d) => ['key' => 'doc:'.$d->id, 'type' => 'document', 'id' => $d->id, 'label' => $d->email_subject, 'date' => null, 'to' => null], $docs),
        ];

        return JobCandidate::query()->create($fields + [
            'status' => 'open', 'fingerprint' => sha1(collect($sources)->pluck('key')->sort()->implode('|')), 'title' => 'Possible job',
            'starts_on' => $events[0]->day(), 'ends_on' => $events[count($events) - 1]->day(), 'sources' => $sources, 'reasons' => ['why' => [], 'jobs' => []], 'score' => 6, 'found_by' => 'rules',
        ]);
    }

    private function places(): void
    {
        Setting::put('home_postcode', 'LS1 4AP');
        foreach (['LS1 4AP' => [53.7965, -1.5478], 'M1 1AA' => [53.4808, -2.2426]] as $pc => [$lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ---------------------------------------------------------------- 1–3: making a job

    public function test_a_rig_a_show_and_a_de_rig_on_separate_days_make_one_job(): void
    {
        $rig = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 20:00:00', 'ExCeL London, E16 1XL');
        $show = $this->event('Show: Winter Expo', '2025-11-05 10:00:00', '2025-11-05 23:00:00', 'ExCeL London, E16 1XL');
        $derig = $this->event('De-rig: Winter Expo', '2025-11-07 07:00:00', '2025-11-07 13:00:00', 'ExCeL London, E16 1XL');
        $deletes = 0;
        DB::listen(function ($q) use (&$deletes) {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'delete') && str_contains($q->sql, 'job_days')) {
                $deletes++;
            }
        });
        $token = $this->token();

        $response = $this->create([
            $this->day('2025-11-03', 'rig', '08:00', '20:00'), $this->day('2025-11-05', 'show', '10:00', '23:00'), $this->day('2025-11-07', 'derig', '07:00', '13:00'),
        ], [$this->entry($rig), $this->entry($show), $this->entry($derig)], ['pick' => $token, 'location' => 'ExCeL London', 'postcode' => 'e16 1xl']);

        $job = WorkJob::query()->sole();
        $response->assertRedirect('/jobs/'.$job->id)->assertSessionHas('success', fn ($m) => str_contains($m, 'Added Winter Expo with 3 days') && str_contains($m, 'Linked 3 calendar entries'));
        $this->assertSame(['2025-11-03', '2025-11-05', '2025-11-07'], $job->workDates());
        $this->assertSame([['2025-11-03', 'rig'], ['2025-11-05', 'show'], ['2025-11-07', 'derig']], $this->days($job));
        $days = $job->days()->get();
        $this->assertSame(['you'], $days->pluck('source')->unique()->values()->all(), 'The days you picked are yours');
        $this->assertSame(['08:00', '20:00'], [substr($days[0]->start_time, 0, 5), substr($days[0]->end_time, 0, 5)]);
        $this->assertSame([$rig->key(), $show->key(), $derig->key()], $days->pluck('calendar_key')->all());
        $this->assertSame(['2025-11-03', '2025-11-07', 'E16 1XL', 'done'], [$job->starts_on->format('Y-m-d'), $job->ends_on->format('Y-m-d'), $job->postcode, $job->status]);

        $links = CalendarJobLink::query()->where('work_job_id', $job->id)->get();
        $this->assertCount(3, $links);
        $this->assertSame(['you'], $links->pluck('linked_by')->unique()->values()->all());

        $created = AuditEvent::query()->where('action', 'job.created')->sole();
        $this->assertCount(3, $created->after['evidence']);
        $this->assertSame(['calendar_event'], array_values(array_unique(array_column($created->after['evidence'], 'type'))));
        $this->assertSame('From 3 calendar entries you picked', $created->reason);
        $this->assertCount(3, $created->after['days']);

        $this->assertSame(0, $deletes, 'No day was made only to be deleted again');
        $this->assertLessThanOrEqual(1, Queue::pushed(GatherFromGmail::class)->count());
        Queue::assertNotPushed(GatherForJob::class);
        $this->assertSame($job->id, CalendarPick::query()->where('token', $token)->value('work_job_id'));
        $this->assertSame('create', CalendarPick::query()->value('mode'));
    }

    public function test_some_days_of_a_festival_are_exactly_the_days_picked_and_stay(): void
    {
        $fest = $this->event('Big Festival', '2025-08-10 00:00:00', '2025-08-17 00:00:00', 'Knebworth Park, SG1 2AX', true);
        $this->create(array_map(fn ($d) => $this->day($d, 'show'), ['2025-08-10', '2025-08-11', '2025-08-14', '2025-08-16']), [$this->entry($fest)], ['title' => 'Big Festival']);
        $job = WorkJob::query()->sole();
        $picked = ['2025-08-10', '2025-08-11', '2025-08-14', '2025-08-16'];
        $this->assertSame($picked, $job->workDates());
        $this->assertSame(['you', $job->id], [$this->link($fest)->linked_by, $this->link($fest)->work_job_id]);

        // A possible job with the festival and its PO, added to the job: the PO comes, the days you left out don't.
        $doc = $this->poDocument();
        $candidate = $this->candidate([$fest], [$doc]);
        $linkedBefore = AuditEvent::query()->where('action', 'calendar.linked')->where('subject_id', $this->link($fest)->id)->count();
        app(JobFinder::class)->merge($candidate, $job);
        $this->assertSame($picked, $job->fresh()->workDates(), 'Linking an entry already yours on the job again changes nothing');
        $this->assertSame($linkedBefore, AuditEvent::query()->where('action', 'calendar.linked')->where('subject_id', $this->link($fest)->id)->count());
        $this->assertSame($job->id, $doc->fresh()->work_job_id);

        // "Not part of this job" later: the days you picked stay (you remove one with its ✕).
        app(JobFinder::class)->unlinkCalendar($fest, $job->id);
        $this->assertNull($this->link($fest)->work_job_id);
        $this->assertSame($picked, $job->fresh()->workDates());
    }

    public function test_days_without_calendar_entries_are_work_days(): void
    {
        $this->create([$this->day('2025-11-03'), $this->day('2025-11-10')], [], ['title' => 'Hire: Smith Productions'])->assertSessionHas('success');
        $job = WorkJob::query()->sole();
        $this->assertSame([['2025-11-03', 'work'], ['2025-11-10', 'work']], $this->days($job));
        $this->assertSame('Hire: Smith Productions', $job->title);
        $this->assertSame('From the days you picked', AuditEvent::query()->where('action', 'job.created')->value('reason'));
        $this->assertSame(0, CalendarJobLink::query()->count());
    }

    // ---------------------------------------------------------------- 4: adding to a job

    public function test_adding_days_writes_only_what_changed_and_records_it_once(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '08:00', 'end_time' => '18:00'], ['date' => '2025-11-05', 'kind' => 'show', 'start_time' => '10:00', 'end_time' => '22:00']]);
        // A time with seconds (as a TIME column gives it back) is the same minute: no change. A job's day keeps five
        // characters, so MariaDB can't hold one; SQLite can, so it's tried there.
        if (DB::getDriverName() === 'sqlite') {
            DB::table('job_days')->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-03')->update(['start_time' => '08:00:00', 'end_time' => '18:00:00']);
        }
        $rig = JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-03')->sole();
        $stamp = $rig->updated_at->toDateTimeString();
        Carbon::setTestNow(now()->addMinutes(5));
        $derig = $this->event('De-rig: Acme Awards', '2025-11-07 07:00:00', '2025-11-07 13:00:00', 'Leeds Arena');
        $before = AuditEvent::query()->count();

        $this->add($job, [
            $this->day('2025-11-03', 'rig', '08:00', '18:00'), // as it is
            $this->day('2025-11-05', 'show', '10:00', null), // finish cleared
            $this->day('2025-11-07', 'derig', '07:00', '13:00'), // new
            $this->day('2025-11-01', 'travel'), // new, and the job now starts earlier
        ], [$this->entry($derig)])->assertRedirect('/jobs/'.$job->id)->assertSessionHas('success', fn ($m) => str_contains($m, 'Added 2 days to Acme Awards') && str_contains($m, 'Changed Wed 5 Nov 2025'));

        $this->assertSame($stamp, $rig->fresh()->updated_at->toDateTimeString(), 'An unchanged day isn’t written');
        $this->assertSame(0, AuditEvent::query()->where('id', '>', $before)->where('action', 'job.day_updated')->count());
        $changes = AuditEvent::query()->where('id', '>', $before)->where('action', 'job.days_changed')->get();
        $this->assertCount(1, $changes);
        $this->assertSame(['2025-11-01', '2025-11-07'], $changes[0]->after['added']);
        $this->assertSame(['2025-11-05'], $changes[0]->after['changed']);
        $this->assertEquals(['date' => '2025-11-05', 'kind' => 'show', 'start_time' => '10:00', 'end_time' => '22:00', 'note' => null], $changes[0]->before['days'][1]);
        $this->assertEquals(['date' => '2025-11-05', 'kind' => 'show', 'start_time' => '10:00', 'end_time' => null, 'note' => null], $changes[0]->after['days'][2]);
        $show = JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-05')->sole();
        $this->assertSame([null, 'you'], [$show->end_time, $show->source]);
        $new = JobDay::query()->where('work_job_id', $job->id)->whereDate('on_date', '2025-11-07')->sole();
        $this->assertSame(['derig', 'you', $derig->key()], [$new->kind, $new->source, $new->calendar_key]);
        $this->assertSame(1, AuditEvent::query()->where('id', '>', $before)->where('action', 'job.dates_changed')->count());
        $this->assertSame(['2025-11-01', '2025-11-07'], [$job->fresh()->starts_on->format('Y-m-d'), $job->fresh()->ends_on->format('Y-m-d')]);
        $this->assertSame(['you', $job->id], [$this->link($derig)->linked_by, $this->link($derig)->work_job_id]);
        $this->assertSame('add', CalendarPick::query()->value('mode'));
    }

    // ---------------------------------------------------------------- 5–8: what you saw is what's acted on

    public function test_an_entry_on_another_job_moves_only_when_you_say_and_only_from_the_job_you_saw(): void
    {
        $finder = app(JobFinder::class);
        $a = $this->job([['date' => '2025-11-03', 'kind' => 'show']], 'Acme Awards');
        $b = $this->job([['date' => '2025-11-12', 'kind' => 'show']], 'Smith Gala');
        $e = $this->event('Load-in: Winter Expo', '2025-11-10 08:00:00', '2025-11-10 12:00:00', 'Leeds Arena');
        $finder->linkCalendar($e, $a, 'you', 'Linked by you');
        $this->assertSame(['2025-11-03', '2025-11-10'], $a->fresh()->workDates());

        // Left where it is unless you tick Move.
        $this->create([$this->day('2025-11-10', 'rig')], [$this->entry($e, null, 'other_job', ['seen_job_id' => $a->id])])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Left where it is: “Load-in: Winter Expo” on Acme Awards'));
        $this->assertSame($a->id, $this->link($e)->work_job_id);

        // Moved with Move ticked: the day it brought to Acme Awards comes off it.
        $before = AuditEvent::query()->count();
        $this->create([$this->day('2025-11-10', 'rig')], [$this->entry($e, null, 'other_job', ['seen_job_id' => $a->id, 'take' => true])], ['title' => 'Winter Expo again']);
        $new = WorkJob::query()->where('title', 'Winter Expo again')->sole();
        $this->assertSame([$new->id, 'you'], [$this->link($e)->work_job_id, $this->link($e)->linked_by]);
        $this->assertSame(['2025-11-03'], $a->fresh()->workDates());
        $this->assertTrue(AuditEvent::query()->where('id', '>', $before)->where('action', 'job.days_changed')->where('subject_id', $a->id)->exists());

        // You saw it on Acme Awards, but it's on Smith Gala now: left there, and named.
        $f = $this->event('Get-out: Smith Gala', '2025-11-12 22:00:00', '2025-11-12 23:30:00', 'Leeds Arena');
        $finder->linkCalendar($f, $b, 'you', 'Linked by you');
        $this->create([$this->day('2025-11-12', 'derig')], [$this->entry($f, null, 'other_job', ['seen_job_id' => $a->id, 'take' => true])], ['title' => 'Third'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Not linked: “Get-out: Smith Gala” (it’s on Smith Gala now)'));
        $this->assertSame($b->id, $this->link($f)->work_job_id);
    }

    public function test_your_press_beats_automation_but_never_what_you_did_since(): void
    {
        $finder = app(JobFinder::class);
        $other = $this->job([['date' => '2025-11-03', 'kind' => 'show']], 'Acme Awards');
        $auto = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 12:00:00', 'Hall');
        $yours = $this->event('Show: Winter Expo', '2025-11-03 13:00:00', '2025-11-03 22:00:00', 'Hall');
        // Shown free; then linked automatically, and by you, elsewhere before you pressed Create.
        $finder->linkCalendar($auto, $other, 'rules', 'The same entry as this job’s other days');
        $finder->linkCalendar($yours, $other, 'you', 'Linked by you');

        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($auto), $this->entry($yours)])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Not linked: “Show: Winter Expo” (you linked it to Acme Awards since)'));
        $job = WorkJob::query()->where('title', 'Winter Expo')->sole();
        $this->assertSame([$job->id, 'you'], [$this->link($auto)->work_job_id, $this->link($auto)->linked_by]);
        $this->assertSame($other->id, $this->link($yours)->work_job_id);
    }

    public function test_not_a_job_and_not_this_job_need_your_say(): void
    {
        $finder = app(JobFinder::class);
        $n = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 12:00:00', 'Hall');
        $finder->markNotJob($n);

        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($n, null, 'not_job')], ['title' => 'First']);
        $this->assertTrue($this->link($n)->not_job, 'Shown as “not a job”, unticked: left as it is');
        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($n, null, 'not_job', ['take' => true])], ['title' => 'Second']);
        $second = WorkJob::query()->where('title', 'Second')->sole();
        $this->assertSame([false, $second->id], [$this->link($n)->not_job, $this->link($n)->work_job_id]);

        // Adding to a job: one you said isn't part of it is linked only with "Include anyway", which lifts that.
        $job = $this->job([['date' => '2025-11-10', 'kind' => 'show']], 'Acme Awards');
        $r = $this->event('De-rig: Acme Awards', '2025-11-11 07:00:00', '2025-11-11 12:00:00', 'Hall');
        $finder->rejectCalendarFor($r, $job->id);
        $this->add($job, [$this->day('2025-11-11', 'derig')], [$this->entry($r, null, 'not_this_job')]);
        $this->assertNull($this->link($r)->work_job_id);
        $this->add($job, [$this->day('2025-11-11', 'derig')], [$this->entry($r, null, 'not_this_job', ['take' => true])]);
        $this->assertSame($job->id, $this->link($r)->work_job_id);
        $this->assertNotContains($job->id, $this->link($r)->rejected ?? []);
    }

    public function test_an_entry_you_untick_is_not_part_of_the_job_so_its_search_leaves_it(): void
    {
        $rig = $this->event('Rig: Northern Lights', '2025-11-03 08:00:00', '2025-11-03 18:00:00', 'Media City, M50 2EQ');
        $same = $this->event('Northern Lights', '2025-11-03 19:00:00', '2025-11-03 20:00:00');

        $preview = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-03']])->assertOk()->json();
        $entries = collect($preview['entries'])->keyBy('key');
        $this->assertLessThan(2, $entries[$same->key()]['score']);
        $this->assertTrue($entries[$same->key()]['default_include'], 'The same title as an entry ticked: ticked for you, as the job’s search would link it');
        $this->assertTrue($entries[$rig->key()]['default_include']);

        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($rig)], ['left_out' => [['key' => $same->key(), 'date' => '2025-11-03']], 'title' => 'Northern Lights'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 entry you left out won’t be linked to it automatically'));
        $job = WorkJob::query()->sole();
        $link = $this->link($same);
        $this->assertNull($link->work_job_id, 'The job’s search ran and left it');
        $this->assertSame([$job->id], $link->rejected);
        $this->assertSame('Left out when you picked the job’s days', $link->reason);

        // Linking it yourself later lifts it.
        app(JobFinder::class)->linkCalendar($same, $job, 'you', 'Linked by you');
        $this->assertNull($this->link($same)->rejected);
    }

    // ---------------------------------------------------------------- 9–12: refusals, limits, retries, locked years

    public function test_a_cancelled_job_is_refused_and_nothing_is_written(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'show']]);
        $job->update(['status' => 'cancelled']);
        $e = $this->event('De-rig: Acme Awards', '2025-11-04 07:00:00', '2025-11-04 12:00:00', 'Hall');
        $counts = $this->counts();

        $this->add($job, [$this->day('2025-11-04', 'derig')], [$this->entry($e)])->assertSessionHas('error', 'That job is cancelled. Pick another job or make a new one.');
        $this->assertSame($counts, $this->counts());

        // Cancelled after the page checked: refused inside the save, and the pick's token isn't used up.
        $job->forceFill(['status' => 'booked'])->saveQuietly();
        $stale = $job->fresh();
        $job->forceFill(['status' => 'cancelled'])->saveQuietly();
        try {
            app(DayPick::class)->addTo($stale, ['pick' => $this->token(), 'days' => [$this->day('2025-11-04', 'derig')], 'entries' => [$this->entry($e)]]);
            $this->fail('A cancelled job took days');
        } catch (InvalidArgumentException $ex) {
            $this->assertSame('That job is cancelled. Pick another job or make a new one.', $ex->getMessage());
        }
        $this->assertSame($counts, $this->counts());
    }

    public function test_limits_on_days_and_entries_are_refused_and_nothing_is_written(): void
    {
        $e = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 12:00:00', 'Hall');
        $counts = $this->counts();
        $many = array_map(fn ($i) => $this->day(Carbon::parse('2025-01-01')->addDays($i)->format('Y-m-d')), range(0, 120));

        $this->create($many)->assertSessionHasErrors(['days' => '120 days is the most at once.']);
        $this->create([$this->day('2025-11-03'), $this->day('2025-11-03')])->assertSessionHasErrors(['days.1.date' => 'Mon 3 Nov 2025 is picked twice.']);
        $this->create([$this->day('2025-01-01'), $this->day('2026-03-01')])->assertSessionHasErrors(['days' => 'A job can cover at most 400 days. Make it separate jobs.']);
        $this->create([$this->day('2025-11-03')], array_map(fn ($i) => ['key' => sha1((string) $i), 'date' => '2025-11-03', 'on' => '2025-11-03', 'seen' => 'free'], range(0, 150)))
            ->assertSessionHasErrors(['entries' => '150 calendar entries is the most at once.']);
        $this->create([$this->day('2025-11-03')], [$this->entry($e), $this->entry($e)])->assertSessionHasErrors(['entries.1.key']);
        $this->create([$this->day('2025-11-03')], [$this->entry($e)], ['left_out' => [['key' => $e->key(), 'date' => '2025-11-03']]])
            ->assertSessionHasErrors(['left_out.0.key' => 'A calendar entry is both ticked and left out. Tick or untick it again.']);
        $this->create([$this->day('2025-11-04')], [$this->entry($e)])->assertSessionHasErrors(['entries.0.on']);
        $this->create([$this->day('2025-11-03')], [['key' => 'not-a-key', 'date' => '2025-11-03', 'on' => '2025-11-03', 'seen' => 'free']])->assertSessionHasErrors(['entries.0.key']);
        $this->create([$this->day('2025-11-03', 'party')])->assertSessionHasErrors(['days.0.kind' => 'Pick what kind of day Mon 3 Nov 2025 is.']);
        $this->create([$this->day('2025-11-03', 'show', '9am')])->assertSessionHasErrors(['days.0.start_time' => 'The Mon 3 Nov 2025 start time needs hours and minutes, like 07:30.']);
        $this->create([$this->day('2025-11-03', 'show', null, '25:00')], [], ['new_client' => 'Acme Events'])
            ->assertSessionHasErrors(['days.0.end_time' => 'The Mon 3 Nov 2025 finish time needs hours and minutes, like 18:00.']);
        $this->create([$this->day('2025-11-03')], [], ['pick' => 'not-a-token'])->assertSessionHasErrors(['pick']);
        $this->post('/calendar/pick', ['days' => [$this->day('2025-11-03')], 'title' => 'Winter Expo'])->assertSessionHasErrors(['pick']);

        $this->assertSame($counts, $this->counts(), 'Nothing written, not even the new client');
    }

    public function test_a_day_too_far_from_the_job_is_refused_by_name(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'show']]);
        $counts = $this->counts();
        $this->add($job, [$this->day('2026-04-01', 'derig')])->assertSessionHas('error', 'Wed 1 Apr is more than four months from Acme Awards. Make it a separate job.');
        $this->assertSame($counts, $this->counts());
        $this->add($job, [$this->day('2025-11-03', 'show'), $this->day('2025-11-03', 'rig')])->assertSessionHasErrors(['days.1.date']);
        $this->assertSame($counts, $this->counts());
    }

    public function test_the_same_pick_sent_twice_makes_one_job(): void
    {
        $e = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 12:00:00', 'Hall');
        $token = $this->token();
        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($e)], ['pick' => $token, 'new_client' => 'Acme Events']);
        $job = WorkJob::query()->sole();
        $counts = $this->counts();

        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($e)], ['pick' => $token, 'new_client' => 'Acme Events'])
            ->assertRedirect('/jobs/'.$job->id)->assertSessionHas('success', 'Already saved: Winter Expo.');
        $this->assertSame($counts, $this->counts(), 'Nothing written the second time');

        $this->postJson('/calendar/pick/preview', ['pick' => $token, 'dates' => ['2025-11-03']])->assertOk()
            ->assertExactJson(['pick' => $token, 'done' => ['job_id' => $job->id, 'title' => 'Winter Expo', 'mode' => 'create']]);
        $fresh = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-03']])->json('pick');
        $this->assertTrue(AuditContext::isCorrelation($fresh));
        $this->assertNotSame($token, $fresh);

        $add = $this->token();
        $this->add($job, [$this->day('2025-11-04', 'derig')], [], ['pick' => $add]);
        $this->postJson('/calendar/pick/preview', ['pick' => $add, 'dates' => ['2025-11-04']])->assertJsonPath('done', ['job_id' => $job->id, 'title' => 'Winter Expo', 'mode' => 'add']);
    }

    public function test_days_in_a_locked_year_are_added_and_named_and_its_figures_stay_as_they_are(): void
    {
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);
        $this->places();
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-04-04', 'tax_year' => '2024-25',
            'description' => 'CABLE SHOP', 'counterparty' => 'CABLE SHOP', 'merchant_key' => 'CABLE SHOP', 'amount_pence' => -1500, 'raw' => [], 'fingerprint' => md5('cable'), 'occurrence' => 1]);

        $this->create([$this->day('2025-04-04', 'rig'), $this->day('2025-04-07', 'derig')], [], ['postcode' => 'M1 1AA', 'title' => 'Spring Build'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 day is in 2024-25, which is locked: it’s on the job, and that year’s figures stay as they are.'));
        $job = WorkJob::query()->sole();
        $this->assertSame(['2025-04-04', '2025-04-07'], $job->workDates());

        $this->assertSame([], JobTrips::proposals('2024-25'));
        $this->assertSame(['2025-04-07'], collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id)['trip_dates']);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $tx->id)->count(), 'Nothing is written into the locked year');
        $this->postJson('/calendar/pick/preview', ['dates' => ['2025-04-04', '2025-04-07']])
            ->assertJsonPath('dates.0.locked', true)->assertJsonPath('dates.0.tax_year', '2024-25')->assertJsonPath('dates.1.locked', false);
    }

    // ---------------------------------------------------------------- 13–16: possible jobs, the preview, the month, the job page

    public function test_possible_jobs_made_of_the_entries_picked_are_closed(): void
    {
        $rig = $this->event('Rig: Big Festival', '2025-08-10 08:00:00', '2025-08-10 18:00:00', 'Knebworth Park');
        $show = $this->event('Show: Big Festival', '2025-08-11 10:00:00', '2025-08-11 22:00:00', 'Knebworth Park');
        $calendarOnly = $this->candidate([$rig, $show]);
        $this->create([$this->day('2025-08-10', 'rig'), $this->day('2025-08-11', 'show')], [$this->entry($rig), $this->entry($show)],
            ['candidates' => [['id' => $calendarOnly->id, 'others' => false]], 'title' => 'Big Festival']);
        $job = WorkJob::query()->sole();
        $this->assertSame(['accepted', $job->id], [$calendarOnly->fresh()->status, $calendarOnly->fresh()->work_job_id]);
        $closed = AuditEvent::query()->where('action', 'jobs.candidate_closed')->sole();
        $this->assertEquals(['status' => 'accepted', 'work_job_id' => $job->id, 'sources' => $calendarOnly->sourceKeys(), 'others_linked' => 0], $closed->after);

        // One also found from a PO: closed with it only when you bring the PO too.
        $derig = $this->event('De-rig: Big Festival', '2025-08-12 07:00:00', '2025-08-12 12:00:00', 'Knebworth Park');
        $withPo = $this->candidate([$derig], [$doc = $this->poDocument()]);
        $this->add($job, [$this->day('2025-08-12', 'derig')], [$this->entry($derig)], ['candidates' => [['id' => $withPo->id, 'others' => false]]]);
        $this->assertSame('open', $withPo->fresh()->status);
        $this->assertNull($doc->fresh()->work_job_id);
        $this->add($job, [$this->day('2025-08-12', 'derig')], [$this->entry($derig, null, 'this_job')], ['candidates' => [['id' => $withPo->id, 'others' => true]]]);
        $this->assertSame(['merged', $job->id], [$withPo->fresh()->status, $withPo->fresh()->work_job_id]);
        $this->assertSame([$job->id, 'you'], [$doc->fresh()->work_job_id, $doc->fresh()->job_linked_by]);

        // Your edits to a possible job made only of the entries picked fill in the new job.
        $gala = $this->event('Show: Smith Gala', '2025-09-05 18:00:00', '2025-09-05 23:00:00', 'Leeds Town Hall, LS1 3AD');
        $edited = $this->candidate([$gala], [], ['title' => 'Smith Gala 2025', 'po_number' => 'PO-9911', 'client_name' => 'smith productions', 'edited_at' => now()]);
        $smith = Client::query()->create(['name' => 'Smith Productions']);
        $preview = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-09-05']])->assertOk()->json();
        $this->assertSame(['Smith Gala 2025', 'PO-9911', $smith->id, $edited->id], [$preview['suggest']['title'], $preview['suggest']['po_number'], $preview['suggest']['client_id'], $preview['suggest']['from_candidate']]);
        $this->assertTrue(collect($preview['candidates'])->firstWhere('id', $edited->id)['covered']);
    }

    public function test_the_preview_reads_only_and_says_what_each_entry_is(): void
    {
        $finder = app(JobFinder::class);
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);
        $rig = $this->event('Rig: Winter Expo', '2025-11-07 08:00:00', '2025-11-07 20:00:00', 'ExCeL London, E16 1XL');
        $physio = $this->event('Physio', '2025-11-07 07:00:00', '2025-11-07 07:45:00', 'Leeds Physio Clinic, LS1 4AP');
        $weekly = $this->event('Crew briefing', '2025-11-07 06:30:00', '2025-11-07 07:00:00', null, false, '20251107T063000Z');
        $oneOff = $this->event('Crew call', '2025-11-10 06:30:00', '2025-11-10 07:00:00');
        $loadOut = $this->event('Load-out: Winter Expo', '2025-11-08 00:30:00', '2025-11-08 04:00:00', 'ExCeL London, E16 1XL');
        $long = $this->event(mb_substr(str_repeat('Winter Expo opening night ', 10), 0, 250), '2025-11-10 18:00:00', '2025-11-10 23:00:00', mb_substr(str_repeat('Hall 3, ExCeL London, ', 12), 0, 250));
        $related = $this->event('De-rig: Winter Expo', '2025-11-14 07:00:00', '2025-11-14 13:00:00', 'ExCeL London, E16 1XL');
        $other = $this->job([['date' => '2025-11-10', 'kind' => 'show']], 'Acme Awards');
        $mine = $this->event('Show: Acme Awards', '2025-11-10 19:00:00', '2025-11-10 22:00:00', 'Leeds Arena');
        $finder->linkCalendar($mine, $other, 'you', 'Linked by you');
        $auto = $this->event('Acme Awards reception', '2025-11-10 17:00:00', '2025-11-10 18:00:00', 'Leeds Arena');
        $finder->linkCalendar($auto, $other, 'rules', 'The same entry');
        $before = [AuditEvent::query()->count(), CalendarJobLink::query()->count(), JobDay::query()->count(), JobCandidate::query()->count(), CalendarPick::query()->count()];

        $p = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-07', '2025-11-10', '2025-04-04']])->assertOk()->json();

        $this->assertSame($before, [AuditEvent::query()->count(), CalendarJobLink::query()->count(), JobDay::query()->count(), JobCandidate::query()->count(), CalendarPick::query()->count()]);
        $this->assertTrue(AuditContext::isCorrelation($p['pick']));
        $this->assertSame(['2025-04-04', '2025-11-07', '2025-11-10'], array_column($p['dates'], 'date'));
        $this->assertSame([true, false], [$p['dates'][0]['locked'], $p['dates'][1]['locked']]);
        $this->assertSame([['id' => $other->id, 'title' => 'Acme Awards', 'cancelled' => false]], $p['dates'][2]['other_jobs']);
        $e = collect($p['entries'])->keyBy('key');
        $this->assertTrue($e[$rig->key()]['default_include']);
        $this->assertFalse($e[$physio->key()]['default_include'], 'A physio appointment at a clinic (score 1) isn’t ticked');
        $this->assertFalse($e[$weekly->key()]['default_include'], 'A repeating entry needs more to be ticked');
        $this->assertTrue($e[$oneOff->key()]['default_include']);
        $this->assertSame('2025-11-07', $e[$loadOut->key()]['fold_onto']);
        $this->assertSame(['picked' => 0, 'total' => 1], $e[$loadOut->key()]['partial']);
        $this->assertContains($loadOut->key(), $p['dates'][1]['folds']);
        $this->assertSame(['other_job', 'you', false], [$e[$mine->key()]['state'], $e[$mine->key()]['by'], $e[$mine->key()]['move_default']]);
        $this->assertSame(['other_job', 'rules', true], [$e[$auto->key()]['state'], $e[$auto->key()]['by'], $e[$auto->key()]['move_default']]);
        $this->assertSame(['loses' => [], 'only_day' => false], array_intersect_key($e[$mine->key()]['other_job'], ['loses' => 1, 'only_day' => 1]),
            'Its day was the job’s already: moving it takes none off Acme Awards');
        $this->assertSame([$related->key()], array_column($p['related'], 'key'));
        $this->assertSame(['kind' => 'rig', 'start_time' => '08:00', 'end_time' => '20:00'], $p['dates'][1]['suggested']);
        $this->assertLessThanOrEqual(150, mb_strlen($p['suggest']['title']));
        $this->assertSame('Winter Expo', $p['suggest']['title']);
        $this->assertSame('done', $p['suggest']['status']);

        // The title and venue of a long entry are shortened to fit, so the form saves as it loads.
        $q = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-10'], 'overrides' => [$oneOff->key() => 'out', $long->key() => 'in']])->json('suggest');
        $this->assertLessThanOrEqual(150, mb_strlen($q['title']));
        $this->assertLessThanOrEqual(200, mb_strlen($q['location']));
        $this->assertStringStartsWith('Winter Expo opening night', $q['title']);

        // A link that brought the other job its day: moving it would take that day off (or keep it, its only day).
        $solo = $this->job([['date' => '2025-12-01', 'kind' => 'show']], 'Solo Gig');
        $brought = $this->event('Tech: Solo Gig', '2025-12-02 10:00:00', '2025-12-02 18:00:00', 'Leeds');
        $finder->linkCalendar($brought, $solo, 'you', 'Linked by you');
        $s = collect($this->postJson('/calendar/pick/preview', ['dates' => ['2025-12-02']])->json('entries'))->keyBy('key');
        $this->assertSame(['2025-12-02'], $s[$brought->key()]['other_job']['loses']);
        $this->assertFalse($s[$brought->key()]['other_job']['only_day']);
        JobDay::query()->where('work_job_id', $solo->id)->whereDate('on_date', '2025-12-01')->delete();
        $s = collect($this->postJson('/calendar/pick/preview', ['dates' => ['2025-12-02']])->json('entries'))->keyBy('key');
        $this->assertSame([[], true], [$s[$brought->key()]['other_job']['loses'], $s[$brought->key()]['other_job']['only_day']]);

        $this->postJson('/calendar/pick/preview', ['dates' => []])->assertStatus(422)->assertJsonValidationErrors(['dates']);
        $this->postJson('/calendar/pick/preview', ['dates' => ['2025-13-40']])->assertStatus(422);
    }

    public function test_the_month_lists_entries_that_began_in_an_earlier_month(): void
    {
        $spill = $this->event('Big Festival', '2025-10-28 00:00:00', '2025-11-03 00:00:00', 'Knebworth', true);
        $tour = $this->event('Panto season', '2025-11-28 00:00:00', '2026-01-05 00:00:00', 'Leeds Grand Theatre', true);
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'show']], 'Acme Awards');
        TaxYear::track('2024-25', 'filed')->update(['locked_at' => now()]);

        $nov = $this->getJson('/calendar/month?m=2025-11')->assertOk()->json();
        $this->assertSame(['2025-11', '2025-10', '2025-12'], [$nov['month'], $nov['prev'], $nov['next']]);
        $this->assertContains($spill->key(), array_column($nov['events'], 'key'));
        $this->assertSame([['job_id' => $job->id, 'title' => 'Acme Awards', 'cancelled' => false]], $nov['job_days']['2025-11-03']);
        $this->assertSame([['label' => '2024-25', 'starts_on' => '2024-04-06', 'ends_on' => '2025-04-05']], $nov['locked_years']);
        $this->assertContains($tour->key(), array_column($this->getJson('/calendar/month?m=2026-01')->json('events'), 'key'));
        $this->getJson('/calendar/month?m=2025-13')->assertStatus(422);

        $this->get('/jobs/calendar?m=2025-11')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('jobs/calendar', false)
            ->has('locked_years', 1)->has('job_days.2025-11-03', 1)
            ->where('events.0.key', $spill->key())->where('events.0.date', '2025-10-28')->where('events.0.last_day', '2025-11-02')
            ->has('events.0', fn (AssertableInertia $e) => $e->hasAll(['key', 'date', 'last_day', 'summary', 'location', 'all_day', 'start_time', 'end_time', 'job', 'by', 'not_job',
                'looks_like_work', 'postcode', 'client_id', 'state', 'recurring', 'base', 'score', 'default_include'])));
    }

    public function test_days_picked_on_the_job_page_are_added_and_its_entries_linked(): void
    {
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'rig']], 'Winter Expo');
        $derig = $this->event('De-rig: Winter Expo', '2025-11-07 07:00:00', '2025-11-07 13:00:00', 'ExCeL London');
        $this->getJson('/calendar/pick/jobs?q=winter&near=2025-11-07')->assertOk()->assertJsonPath('jobs.0.id', $job->id)->assertJsonPath('jobs.0.days', ['2025-11-03']);
        $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-07'], 'job_id' => $job->id])->assertOk()
            ->assertJsonPath('job.id', $job->id)->assertJsonPath('dates.0.on_job', null)->assertJsonPath('entries.0.state', 'free');

        $this->add($job, [$this->day('2025-11-07', 'derig', '07:00', '13:00')], [$this->entry($derig)])->assertRedirect('/jobs/'.$job->id);
        $this->assertSame(['2025-11-03', '2025-11-07'], $job->fresh()->workDates());
        $this->assertSame($job->id, $this->link($derig)->work_job_id);
        $this->get('/jobs/'.$job->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('jobs/show', false)->has('days', 2));
        $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-07'], 'job_id' => $job->id])
            ->assertJsonPath('dates.0.on_job', ['kind' => 'derig', 'start_time' => '07:00', 'end_time' => '13:00', 'note' => null])->assertJsonPath('entries.0.state', 'this_job');
    }

    // ---------------------------------------------------------------- 17–19: the audit trail, links, JobCreator

    public function test_the_audit_chain_verifies_and_a_pick_shares_one_correlation(): void
    {
        Setting::put(AuditLog::CONTEXT_SETTING, true);
        $rig = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 12:00:00', 'Hall');
        $derig = $this->event('De-rig: Winter Expo', '2025-11-07 07:00:00', '2025-11-07 12:00:00', 'Hall');
        $make = $this->token();
        $this->create([$this->day('2025-11-03', 'rig')], [$this->entry($rig)], ['pick' => $make]);
        $job = WorkJob::query()->sole();
        $add = $this->token();
        $this->add($job, [$this->day('2025-11-07', 'derig')], [$this->entry($derig)], ['pick' => $add]);

        $this->assertTrue(AuditLog::verify()['ok']);
        $this->assertSame($make, AuditEvent::query()->where('action', 'job.created')->sole()->correlation);
        $this->assertSame([$make, $add], AuditEvent::query()->where('action', 'calendar.linked')->orderBy('id')->pluck('correlation')->all());
        $this->assertSame([$add], AuditEvent::query()->where('action', 'job.days_changed')->pluck('correlation')->unique()->values()->all());
        $this->assertSame(['you'], AuditEvent::query()->whereIn('correlation', [$make, $add])->whereIn('action', ['job.created', 'calendar.linked', 'job.days_changed'])->pluck('actor')->unique()->values()->all());
    }

    public function test_linking_an_entry_respects_what_you_did(): void
    {
        $finder = app(JobFinder::class);
        $job = $this->job([['date' => '2025-11-03', 'kind' => 'show']]);
        $other = $this->job([['date' => '2025-11-20', 'kind' => 'show']], 'Smith Gala');
        $e = $this->event('De-rig: Acme Awards', '2025-11-05 07:00:00', '2025-11-05 12:00:00', 'Hall');

        $finder->linkCalendar($e, $job, 'you', 'Linked by you', null, null, false);
        $this->assertSame(['2025-11-03'], $job->fresh()->workDates(), 'days: false adds no day');

        $count = AuditEvent::query()->count();
        $finder->linkCalendar($e, $job, 'you', 'Linked again');
        $this->assertSame($count, AuditEvent::query()->count(), 'Already yours on this job: nothing written');
        $this->assertSame(['2025-11-03'], $job->fresh()->workDates());

        $finder->linkCalendar($e, $other, 'rules', 'Looks like it');
        $this->assertSame($job->id, $this->link($e)->work_job_id, 'Automation never moves a link you made');

        $f = $this->event('Rig: Smith Gala', '2025-11-19 07:00:00', '2025-11-19 12:00:00', 'Hall');
        $finder->rejectCalendarFor($f, $job->id);
        $finder->linkCalendar($f, $other, 'rules', 'Looks like it');
        $this->assertSame([$other->id, 'rules', [$job->id]], [$this->link($f)->work_job_id, $this->link($f)->linked_by, $this->link($f)->rejected], 'The “not this job” you gave is kept');

        $finder->confirmCalendarLink($this->link($f));
        $this->assertSame('you', $this->link($f)->linked_by);
        $this->assertSame('calendar.link_confirmed', AuditEvent::query()->latest('id')->value('action'));
    }

    public function test_job_creator_takes_each_days_times_and_note_and_makes_only_those_days(): void
    {
        $inserts = [];
        DB::listen(function ($q) use (&$inserts) {
            if (str_starts_with(strtolower(ltrim($q->sql)), 'insert') && str_contains($q->sql, 'job_days')) {
                $inserts[] = $q->bindings;
            }
        });
        $job = app(JobCreator::class)->create(['title' => 'Expo build', 'dates' => [
            ['date' => '2026-05-10', 'kind' => 'rig', 'start_time' => '07:30:00', 'end_time' => '19:00', 'note' => ' Bring the long ladder ', 'calendar_key' => str_repeat('a', 40)],
            ['date' => '2026-05-15', 'kind' => 'derig'],
            '2026-05-12',
        ]], 'assistant');

        $days = $job->days()->get();
        $this->assertSame(['2026-05-10', '2026-05-12', '2026-05-15'], $job->workDates());
        $this->assertSame(['07:30', '19:00', 'Bring the long ladder', null], [substr($days[0]->start_time, 0, 5), substr($days[0]->end_time, 0, 5), $days[0]->note, $days[0]->calendar_key]);
        $this->assertCount(3, $inserts, 'Only the days listed are made: no day from the range in between');
        $this->assertSame(0, JobDay::query()->where('source', 'range')->count());
        $created = AuditEvent::query()->where('action', 'job.created')->sole();
        $this->assertSame('Bring the long ladder', $created->after['days'][0]['note']);
        $this->assertArrayNotHasKey('note', $created->after['days'][1], 'A day without a note is recorded as before');

        try {
            app(JobCreator::class)->create(['title' => 'Gig', 'dates' => [['date' => '2026-05-10', 'note' => str_repeat('x', 201)]]], 'you');
            $this->fail('A long note was taken');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A day’s note is too long (200 characters at most).', $e->getMessage());
        }
        try {
            app(JobCreator::class)->create(['title' => 'Gig', 'dates' => [['date' => '2026-05-10', 'start_time' => '7.30']]], 'you');
            $this->fail('A bad time was taken');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Give times as HH:MM (24-hour).', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- 20–22: overlap with the finder, many entries, after midnight

    public function test_a_job_finder_run_overlapping_a_pick_neither_reopens_nor_proposes_again(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        Setting::put('ai_suggestions', true);
        $rig = $this->event('Rig: Winter Expo', '2025-11-03 08:00:00', '2025-11-03 20:00:00', 'ExCeL London, E16 1XL');
        $this->event('Maria @ Unit 3', '2025-11-04 09:00:00', '2025-11-04 11:00:00', 'Salford'); // unclear: the AI is asked
        $candidate = $this->candidate([$rig]);
        $sources = $candidate->sources;
        $test = $this;
        $ai = new class([], fn () => app(DayPick::class)->create(['pick' => AuditContext::newCorrelation(), 'days' => [$test->pickDay('2025-11-03', 'rig')], 'entries' => [['key' => $rig->key(), 'date' => '2025-11-03', 'on' => '2025-11-03', 'seen' => 'free', 'take' => false]], 'candidates' => [['id' => $candidate->id, 'others' => false]], 'title' => 'Winter Expo', 'status' => 'done'])) extends FakeAiClient
        {
            public function __construct(array $responses, private \Closure $during)
            {
                parent::__construct($responses);
            }

            public function send(array $params): AiResult
            {
                ($this->during)(); // you save a pick while the job finder waits for its answer

                return FakeAiClient::json(['entries' => [['i' => 0, 'work' => false, 'quote' => null, 'client' => null]]]);
            }
        };
        $this->app->instance(AiClient::class, $ai);

        app(JobFinder::class)->run('2025-26');

        $job = WorkJob::query()->sole();
        $this->assertSame([], JobCandidate::query()->where('status', 'open')->get()->filter(fn (JobCandidate $c) => in_array('cal:'.$rig->key(), $c->sourceKeys(), true))->values()->all());
        $this->assertSame(['accepted', $job->id, $sources], [$candidate->fresh()->status, $candidate->fresh()->work_job_id, $candidate->fresh()->sources]);
        $this->assertSame([$job->id, 'you'], [$this->link($rig)->work_job_id, $this->link($rig)->linked_by]);
    }

    /** A day as the pick sends it (for the overlap test's AI stand-in). */
    public function pickDay(string $date, string $kind): array
    {
        return $this->day($date, $kind);
    }

    public function test_a_pick_of_many_entries_keeps_forty_items_of_evidence_and_links_each(): void
    {
        $entries = [];
        $dates = ['2025-11-03', '2025-11-04', '2025-11-05', '2025-11-06', '2025-11-07'];
        foreach (range(1, 41) as $i) {
            $d = $dates[$i % 5];
            $e = $this->event("Session {$i}: Winter Expo", $d.' '.sprintf('%02d', 6 + intdiv($i, 5)).':00:00', $d.' '.sprintf('%02d', 6 + intdiv($i, 5)).':30:00', 'Hall');
            $entries[] = $this->entry($e);
        }
        $this->create(array_map(fn ($d) => $this->day($d, 'show'), $dates), $entries)->assertSessionHas('success', fn ($m) => str_contains($m, 'Linked 41 calendar entries.'));

        $evidence = AuditEvent::query()->where('action', 'job.created')->sole()->after['evidence'];
        $this->assertCount(40, $evidence);
        $this->assertSame(['note' => '…and 2 more calendar entries, each linked in this job’s history'], $evidence[39]);
        $this->assertSame(41, AuditEvent::query()->where('action', 'calendar.linked')->count());
        $this->assertSame(41, CalendarJobLink::query()->where('work_job_id', WorkJob::query()->value('id'))->count());
    }

    public function test_a_load_out_after_midnight_is_folded_onto_the_show_day(): void
    {
        $this->places();
        $show = $this->event('Show: Winter Expo', '2025-11-07 22:00:00', '2025-11-07 23:30:00', 'Manchester Central, M1 1AA');
        $loadOut = $this->event('Load-out: Winter Expo', '2025-11-08 00:30:00', '2025-11-08 04:00:00', 'Manchester Central, M1 1AA');
        $p = $this->postJson('/calendar/pick/preview', ['dates' => ['2025-11-07'], 'overrides' => [$loadOut->key() => 'in']])->json();
        $this->assertSame('2025-11-07', collect($p['entries'])->firstWhere('key', $loadOut->key())['fold_onto']);
        $this->assertSame(['kind' => 'show', 'start_time' => '22:00', 'end_time' => '04:00'], $p['dates'][0]['suggested']);

        $this->create([$this->day('2025-11-07', 'show', '22:00', '04:00')], [$this->entry($show), $this->entry($loadOut, '2025-11-07')], ['postcode' => 'M1 1AA']);
        $job = WorkJob::query()->sole();
        $this->assertSame(['2025-11-07'], $job->workDates(), 'No Saturday for the load-out');
        $this->assertSame('04:00', substr((string) $job->days()->first()->end_time, 0, 5));
        $this->assertSame($job->id, $this->link($loadOut)->work_job_id);
        $this->assertSame(1, collect(JobTrips::proposals('2025-26'))->firstWhere('job_id', $job->id)['trips']);
    }
}
