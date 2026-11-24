<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\DocumentReader;
use App\Domain\Calendar\CalendarSync;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Jobs\JobFinder;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Verification\Verifier;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\Document;
use App\Models\JobCandidate;
use App\Models\JobFind;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

class ReviewFixesJobFinderTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        $this->ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $this->ai);
    }

    private function finder(): JobFinder
    {
        return app(JobFinder::class);
    }

    private function ics(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n".implode('', $events)."END:VCALENDAR\r\n";
    }

    private function vevent(string $uid, string $start, string $end, string $summary, ?string $location = null, array $extra = []): string
    {
        return "BEGIN:VEVENT\r\nUID:{$uid}\r\n{$start}\r\n{$end}\r\nSUMMARY:{$summary}\r\n".($location ? "LOCATION:{$location}\r\n" : '')
            .implode('', array_map(fn ($l) => $l."\r\n", $extra))."END:VEVENT\r\n";
    }

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    private function poDoc(string $po, string $text, ?int $parentId = null, string $name = 'po.txt'): Document
    {
        $doc = app(DocumentIntake::class)->store($text, $name, 'text/plain', 'gmail', ['received_at' => '2025-03-03 10:00:00'], $parentId);
        $doc->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => ['summary' => 'PO', 'new_job' => null, 'po' => ['po_number' => $po, 'lines' => []], 'unverified_count' => 0]]);
        $doc->facts()->create(['field' => 'po_number', 'label' => 'PO number', 'value' => $po, 'quote' => $po, 'verified' => true]);

        return $doc->fresh();
    }

    // R4, R30: the migration that re-keyed calendar links neither crashes on links that now share a key nor on a
    // half-applied schema, and it can be rolled back on SQLite.
    public function test_the_key_migration_survives_clashing_links_a_half_applied_schema_and_a_rollback(): void
    {
        $m = $this->migration('2026_11_26_000002_stabilise_job_links');
        $m->down(); // R30: used to fail on SQLite (the fingerprint index was left behind)
        $this->assertFalse(Schema::hasColumn('job_candidates', 'fingerprint'));

        $job = WorkJob::query()->create(['title' => 'Weekly show', 'starts_on' => '2025-01-06', 'ends_on' => '2025-01-13']);
        $legacy = fn (string $uid, string $start, ?int $jobId, string $by, string $updated) => DB::table('calendar_job_links')->insert([
            'event_key' => sha1($uid.'|'.$start), 'event_uid' => $uid, 'event_starts_at' => $start, 'summary' => 'Show',
            'work_job_id' => $jobId, 'linked_by' => $by, 'created_at' => $updated, 'updated_at' => $updated,
        ]);
        // Two occurrences of a series that has since gone from the calendar, and a moved one-off entry linked twice.
        $legacy('weekly@google.com', '2025-01-06 09:00:00', $job->id, 'rules', '2025-01-01 10:00:00');
        $legacy('weekly@google.com', '2025-01-13 09:00:00', null, 'you', '2025-01-02 10:00:00');
        CalendarEvent::query()->create(['uid' => 'gig@google.com', 'starts_at' => '2025-01-20 09:00:00', 'ends_at' => '2025-01-20 18:00:00', 'summary' => 'Gig']);
        $legacy('gig@google.com', '2025-01-13 09:00:00', null, 'you', '2025-01-03 10:00:00');
        $legacy('gig@google.com', '2025-01-20 09:00:00', $job->id, 'rules', '2025-01-04 10:00:00');

        // MariaDB doesn't undo the column changes when a migration fails part-way: they are already there on the retry.
        Schema::table('calendar_events', fn ($t) => $t->string('recurrence_id', 19)->nullable());
        Schema::table('calendar_job_links', function ($t) {
            $t->boolean('not_job')->default(false);
            $t->json('rejected')->nullable();
        });

        $m->up();

        $this->assertTrue(Schema::hasColumn('job_candidates', 'fingerprint'));
        $this->assertSame(4, CalendarJobLink::query()->count(), 'No link is lost');
        $this->assertSame(4, CalendarJobLink::query()->distinct()->count('event_key'));
        $gig = CalendarEvent::query()->where('uid', 'gig@google.com')->sole();
        $live = CalendarJobLink::query()->where('event_key', $gig->key())->sole();
        $this->assertSame([null, 'you', true], [$live->work_job_id, $live->linked_by, $live->not_job], 'Your "not a job" is the one that holds');
    }

    // G4 (and R4's leftovers): links keyed wrongly by that migration are re-keyed from the calendar itself.
    public function test_links_to_moved_and_single_occurrences_of_repeating_entries_are_found_again_after_a_sync(): void
    {
        $job = WorkJob::query()->create(['title' => 'Rig ACME', 'starts_on' => '2026-06-10', 'ends_on' => '2026-06-10']);
        $other = WorkJob::query()->create(['title' => 'Other', 'starts_on' => '2026-06-10', 'ends_on' => '2026-06-10']);
        // What the old migration left: sha1(uid|start) for a series with several occurrences, sha1(uid|'') for one.
        CalendarJobLink::query()->create(['event_key' => sha1('weekly-1|2026-06-10 08:00:00'), 'event_uid' => 'weekly-1', 'event_starts_at' => '2026-06-10 08:00:00',
            'summary' => 'Rig ACME', 'work_job_id' => $job->id, 'linked_by' => 'you']);
        CalendarJobLink::query()->create(['event_key' => sha1('once-1|'), 'event_uid' => 'once-1', 'event_starts_at' => '2026-07-01 08:00:00',
            'summary' => 'Recce', 'work_job_id' => null, 'not_job' => true, 'linked_by' => 'you']);
        // Automation linked the moved occurrence again after its key changed; your link wins over that.
        $moved = CalendarEvent::keyFor('weekly-1', '2026-06-08 08:00:00');
        CalendarJobLink::query()->create(['event_key' => $moved, 'event_uid' => 'weekly-1', 'event_starts_at' => '2026-06-10 08:00:00',
            'summary' => 'Rig ACME', 'work_job_id' => $other->id, 'linked_by' => 'rules']);

        app(CalendarSync::class)->load($this->ics(
            $this->vevent('weekly-1', 'DTSTART:20260601T080000Z', 'DTEND:20260601T170000Z', 'Rig ACME', 'NEC', ['RRULE:FREQ=WEEKLY;COUNT=3']),
            $this->vevent('weekly-1', 'DTSTART:20260610T080000Z', 'DTEND:20260610T170000Z', 'Rig ACME', 'NEC', ['RECURRENCE-ID:20260608T080000Z']),
            $this->vevent('once-1', 'DTSTART:20260701T080000Z', 'DTEND:20260701T120000Z', 'Recce', 'Leeds', ['RRULE:FREQ=WEEKLY;COUNT=1']),
        ));

        $event = CalendarEvent::query()->where('uid', 'weekly-1')->where('starts_at', '2026-06-10 08:00:00')->sole();
        $link = CalendarJobLink::query()->where('event_key', $event->key())->sole();
        $this->assertSame([$job->id, 'you'], [$link->work_job_id, $link->linked_by]);
        $once = CalendarEvent::query()->where('uid', 'once-1')->sole();
        $this->assertTrue(CalendarJobLink::query()->where('event_key', $once->key())->sole()->not_job, '"Not a job" still holds');
        $this->assertSame(2, CalendarJobLink::query()->count(), 'The automatic duplicate gave way to your link');
    }

    // G2: a possible job you edited before keys changed keeps pointing at its calendar entry.
    public function test_an_edited_possible_job_with_an_old_calendar_key_is_not_proposed_twice_and_links_on_accept(): void
    {
        $old = sha1('expo-123|2025-09-02 08:00:00');
        $candidate = JobCandidate::query()->create(['status' => 'open', 'title' => 'NEC Expo (my title)', 'starts_on' => '2025-09-02', 'ends_on' => '2025-09-02',
            'sources' => [['key' => 'cal:'.$old, 'type' => 'calendar', 'id' => $old, 'label' => 'Install: Expo build', 'date' => '2025-09-02', 'to' => '2025-09-02', 'quote' => null, 'verified' => true, 'note' => 'From your calendar']],
            'reasons' => ['why' => [], 'jobs' => []], 'score' => 3, 'edited_at' => now()]);

        app(CalendarSync::class)->load($this->ics($this->vevent('expo-123', 'DTSTART:20250902T080000Z', 'DTEND:20250902T180000Z', 'Install: Expo build', 'NEC Birmingham')));
        $event = CalendarEvent::query()->sole();
        $this->assertSame(['cal:'.$event->key()], $candidate->fresh()->sourceKeys());

        $this->finder()->run('2025-26', false);
        $this->assertSame([$candidate->id], JobCandidate::query()->where('status', 'open')->pluck('id')->all(), 'The entry isn’t proposed a second time');

        $this->actingAs(User::factory()->create())->post("/jobs/finder/{$candidate->id}/accept", ['title' => 'NEC Expo', 'starts_on' => '2025-09-02', 'ends_on' => '2025-09-02'])->assertRedirect();
        $this->assertNotNull(CalendarJobLink::query()->where('event_key', $event->key())->value('work_job_id'));
    }

    // G2, G4 in production: the repair migration keys each stored entry and matches links and possible jobs again,
    // without waiting for the next calendar sync, and leaves alone what it can't be sure of.
    public function test_the_repair_migration_matches_old_keys_to_the_calendar_already_loaded(): void
    {
        DB::table('calendar_events')->insert([
            ['uid' => 'expo-1', 'recurrence_id' => null, 'starts_at' => '2025-09-02 08:00:00', 'ends_at' => '2025-09-02 18:00:00', 'all_day' => false, 'summary' => 'Install: Expo build', 'created_at' => now(), 'updated_at' => now()],
            ['uid' => 'wk', 'recurrence_id' => '2025-09-01 08:00:00', 'starts_at' => '2025-09-03 08:00:00', 'ends_at' => '2025-09-03 17:00:00', 'all_day' => false, 'summary' => 'Rig', 'created_at' => now(), 'updated_at' => now()],
            ['uid' => 'wk', 'recurrence_id' => '2025-09-08 08:00:00', 'starts_at' => '2025-09-08 08:00:00', 'ends_at' => '2025-09-08 17:00:00', 'all_day' => false, 'summary' => 'Rig', 'created_at' => now(), 'updated_at' => now()],
            // Loaded before the sync knew which entries repeat: these can't be told apart, so they're left alone.
            ['uid' => 'old-series', 'recurrence_id' => null, 'starts_at' => '2025-09-04 08:00:00', 'ends_at' => null, 'all_day' => false, 'summary' => 'Show', 'created_at' => now(), 'updated_at' => now()],
            ['uid' => 'old-series', 'recurrence_id' => null, 'starts_at' => '2025-09-11 08:00:00', 'ends_at' => null, 'all_day' => false, 'summary' => 'Show', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $job = WorkJob::query()->create(['title' => 'Expo', 'starts_on' => '2025-09-02', 'ends_on' => '2025-09-03']);
        $source = fn (string $key) => [['key' => 'cal:'.$key, 'type' => 'calendar', 'id' => $key, 'label' => 'x', 'date' => '2025-09-02', 'to' => '2025-09-02', 'quote' => null, 'verified' => true, 'note' => null]];
        $accepted = JobCandidate::query()->create(['status' => 'accepted', 'title' => 'Expo', 'starts_on' => '2025-09-02', 'ends_on' => '2025-09-02', 'work_job_id' => $job->id,
            'sources' => $source(sha1('expo-1|2025-09-02 08:00:00')), 'reasons' => [], 'score' => 3]);
        $unsure = JobCandidate::query()->create(['status' => 'open', 'title' => 'Show', 'starts_on' => '2025-09-04', 'ends_on' => '2025-09-04', 'edited_at' => now(),
            'sources' => $source(sha1('old-series|2025-09-04 08:00:00')), 'reasons' => [], 'score' => 3]);
        $moved = CalendarJobLink::query()->create(['event_key' => sha1('wk|2025-09-03 08:00:00'), 'event_uid' => 'wk', 'event_starts_at' => '2025-09-03 08:00:00',
            'summary' => 'Rig', 'work_job_id' => $job->id, 'linked_by' => 'you']);

        $this->migration('2026_11_29_000501_job_finder_repair_calendar_keys')->up();

        $this->assertSame(0, CalendarEvent::query()->whereNull('event_key')->count());
        $expo = CalendarEvent::query()->where('uid', 'expo-1')->sole();
        $this->assertSame(['cal:'.$expo->key()], $accepted->fresh()->sourceKeys());
        $this->assertSame(['cal:'.sha1('old-series|2025-09-04 08:00:00')], $unsure->fresh()->sourceKeys());
        $this->assertSame(CalendarEvent::keyFor('wk', '2025-09-01 08:00:00'), $moved->fresh()->event_key);
        $this->assertSame('Rig', JobFinder::event($moved->fresh()->event_key, '2025-08-01')?->summary, 'Found by its key, whatever date is given');
    }

    // R11: possible jobs dated just outside the run's dates aren't made again on every run.
    public function test_a_possible_job_just_outside_the_runs_dates_is_kept_once(): void
    {
        $raw = "From: buyer@acme.example\r\nSubject: PO-8890\r\n\r\nPO-8890 for the Riverside Gala on 20 April 2026.\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', 'gmail', ['received_at' => '2026-03-01 10:00:00']);
        $doc->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => ['summary' => 'PO', 'po' => ['po_number' => 'PO-8890', 'lines' => []],
            'new_job' => ['title' => 'Riverside Gala', 'starts_on' => '2026-04-20', 'ends_on' => '2026-04-20', 'quote' => 'Riverside Gala on 20 April 2026', 'verified' => true]]]);

        foreach (range(1, 3) as $i) {
            $this->finder()->run('2025-26', false);
        }
        $this->assertSame(1, JobCandidate::query()->where('status', 'open')->count());

        // Copies left by earlier runs are cleared.
        $copy = JobCandidate::query()->sole()->replicate();
        $copy->save();
        $this->finder()->run('2025-26', false);
        $this->assertSame(1, JobCandidate::query()->where('status', 'open')->count());
    }

    // R12: an entry moved by more than a day is still found from a possible job you edited.
    public function test_a_moved_calendar_entry_is_still_found_when_accepting_an_edited_possible_job(): void
    {
        app(CalendarSync::class)->load($this->ics($this->vevent('expo-9', 'DTSTART:20250902T080000Z', 'DTEND:20250902T180000Z', 'Install: Expo build', 'NEC Birmingham')));
        $this->finder()->run('2025-26', false);
        $candidate = JobCandidate::query()->sole();
        $user = User::factory()->create();
        $this->actingAs($user)->patch("/jobs/finder/{$candidate->id}", ['title' => 'Expo build', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-09'])->assertSessionHas('success');

        app(CalendarSync::class)->load($this->ics($this->vevent('expo-9', 'DTSTART:20250909T080000Z', 'DTEND:20250909T180000Z', 'Install: Expo build', 'NEC Birmingham')));
        $this->finder()->run('2025-26', false);
        $check = app(Verifier::class)->candidate($candidate->fresh());
        $this->assertSame([true, 'Still in your calendar, moved to Tue 9 Sep 2025'], [$check->checks[0]['ok'], $check->checks[0]['note']]);
        $this->assertSame('2025-09-09', $candidate->fresh()->sources[0]['date'], 'The source follows the entry');

        $this->actingAs($user)->post("/jobs/finder/{$candidate->id}/accept", ['title' => 'Expo build', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-09'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'linked 1 source'));
        $event = CalendarEvent::query()->sole();
        $this->assertNotNull(CalendarJobLink::query()->where('event_key', $event->key())->value('work_job_id'));
    }

    // R12: the message after adding a possible job says what was really linked.
    public function test_adding_a_possible_job_says_which_sources_couldnt_be_linked(): void
    {
        app(CalendarSync::class)->load($this->ics($this->vevent('gone-1', 'DTSTART:20250902T080000Z', 'DTEND:20250902T180000Z', 'Install: Expo build', 'NEC Birmingham')));
        $this->finder()->run('2025-26', false);
        $candidate = JobCandidate::query()->sole();
        // The entry leaves the feed (a feed with no entries at all keeps what's stored, so another entry stays in it).
        app(CalendarSync::class)->load($this->ics($this->vevent('other-1', 'DTSTART:20250910T080000Z', 'DTEND:20250910T090000Z', 'Dentist')));

        $this->actingAs(User::factory()->create())->post("/jobs/finder/{$candidate->id}/accept", ['title' => 'Expo build', 'starts_on' => '2025-09-02', 'ends_on' => '2025-09-02'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'linked 0 sources (1 not linked, because it’s no longer there or you’ve linked it to another job: “Install: Expo build”)'));
    }

    // R13, R14: the job's PO number is matched as it's written, and a short number on its own isn't a PO.
    public function test_po_numbers_match_as_written_and_short_numbers_need_a_po_label(): void
    {
        $job = fn (string $po) => WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-01', 'ends_on' => '2025-12-01', 'po_number' => $po]);
        foreach (['ACME-2025-0042', '4500 123 456', 'PO/4500-123'] as $po) {
            $this->assertTrue(Verifier::jobEvidence("Hi, purchase order {$po} attached.", $job($po))['ok'], $po);
        }
        $this->assertTrue(Verifier::jobEvidence('Ref PO4500123 for the work', $job('4500123'))['ok'], 'Written straight after "PO"');

        foreach ([['PO-12', 'Call time 12:00 on the day'], ['PO-123', 'Studio 123, Media City'], ['PO 0042', 'Tel 0161 496 0042'], ['0042', 'Tel 0161 496 0042'], ['PO-4521', 'Unit 4521 Trafford Park']] as [$po, $text]) {
            $this->assertFalse(Verifier::jobEvidence($text, $job($po))['ok'], "{$po} in “{$text}”");
        }
        $this->assertTrue(Verifier::poInText('Our PO 12 for the ball', 'PO-12'), 'With its PO label a short number still counts');
        $this->assertTrue(Verifier::poInText('Purchase order no. 4521', 'PO-4521'));
        $this->assertTrue(Verifier::poInText('Order number: 0042', '0042'));
    }

    // R15: an email with POs for two jobs: each PO goes to its own job, and the email isn't moved between them.
    public function test_an_email_with_pos_for_two_jobs_links_each_po_to_its_own_job(): void
    {
        $a = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1111']);
        $b = WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-10', 'ends_on' => '2025-12-10', 'po_number' => 'PO-2222']);
        $mail = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: Two POs\r\n\r\nPOs attached.\r\n", 'pos.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $one = $this->poDoc('PO-1111', 'Purchase order PO-1111 Spring Gala', $mail->id, 'po1.txt');
        $two = $this->poDoc('PO-2222', 'Purchase order PO-2222 Winter Ball', $mail->id, 'po2.txt');

        $this->assertSame(2, $this->finder()->run('2025-26', false)['linked']);

        $this->assertSame($a->id, $one->fresh()->work_job_id);
        $this->assertSame($b->id, $two->fresh()->work_job_id);
        $this->assertSame($a->id, $one->facts()->value('work_job_id'), 'The PO-1111 details stay with the Spring Gala');
        $this->assertNull($mail->fresh()->work_job_id, 'The email is for both jobs, so it isn’t put on either');
        $this->actingAs(User::factory()->create())->get('/jobs/finder')->assertOk()
            ->assertInertia(fn ($p) => $p->where('auto', fn ($auto) => collect($auto)->pluck('id')->sort()->values()->all() === [$one->id, $two->id]));
    }

    // G3: accepting a possible job built from one attachment leaves an attachment you linked to another job alone.
    public function test_accepting_a_possible_job_from_one_attachment_leaves_your_other_attachments_alone(): void
    {
        $leeds = WorkJob::query()->create(['title' => 'Leeds Gala', 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-01']);
        $mail = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: Two jobs\r\n\r\nBoth POs attached.\r\n", 'two.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $expo = $this->poDoc('PO-3001', 'Purchase order PO-3001 for the Expo on 9 September 2025', $mail->id, 'expo.txt');
        $expo->update(['extraction' => array_merge($expo->extraction, ['new_job' => ['title' => 'Expo', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-09', 'quote' => 'Expo on 9 September 2025', 'verified' => true]])]);
        $gala = $this->poDoc('PO-3002', 'Purchase order PO-3002 Leeds Gala', $mail->id, 'gala.txt');
        DocumentReader::relink($gala, $leeds->id, 'you');

        $this->finder()->run('2025-26', false);
        $candidate = JobCandidate::query()->sole();
        $this->actingAs(User::factory()->create())->post("/jobs/finder/{$candidate->id}/accept", ['title' => 'Expo', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-09', 'po_number' => 'PO-3001'])->assertRedirect();

        $new = WorkJob::query()->where('title', 'Expo')->sole();
        $this->assertSame([$new->id, 'you'], [$expo->fresh()->work_job_id, $expo->fresh()->job_linked_by]);
        $this->assertSame([$leeds->id, 'you'], [$gala->fresh()->work_job_id, $gala->fresh()->job_linked_by], 'Your link is never moved');
        $this->assertSame($leeds->id, $gala->facts()->value('work_job_id'));
    }

    // R28: "Find jobs now" keeps the possible jobs the AI found (same id) while the AI pass runs in the background.
    public function test_find_jobs_now_keeps_possible_jobs_found_with_ai(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        $this->event('Maria @ Unit 3', '2025-08-04 09:00:00', '2025-08-04 11:00:00', 'Salford');
        $this->ai->responses = [FakeAiClient::json(['entries' => [['i' => 0, 'work' => true, 'quote' => 'Unit 3', 'client' => null]]])];
        $this->finder()->run('2025-26');
        $candidate = JobCandidate::query()->sole();
        $this->assertSame('ai', $candidate->found_by);

        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/jobs/finder/run', ['year' => '2025-26'])->assertSessionHas('success');

        $this->assertSame([$candidate->id], JobCandidate::query()->where('status', 'open')->pluck('id')->all());
        $this->assertCount(1, $this->ai->requests, 'The remembered answer is used, not asked again');
    }

    // R29: the calendar for a UK day runs from UK midnight, also in summer time.
    public function test_the_calendar_for_a_day_uses_uk_midnight(): void
    {
        app(CalendarSync::class)->load($this->ics(
            $this->vevent('one@x', 'DTSTART;TZID=Europe/London:20260629T003000', 'DTEND;TZID=Europe/London:20260629T013000', 'Overnight get-out'),
            $this->vevent('two@x', 'DTSTART;TZID=Europe/London:20260629T000000', 'DTEND;TZID=Europe/London:20260629T004500', 'Midnight load-in'),
            $this->vevent('three@x', 'DTSTART;VALUE=DATE:20260629', 'DTEND;VALUE=DATE:20260630', 'All day 29th'),
            $this->vevent('four@x', 'DTSTART;TZID=Europe/London:20260628T230000', 'DTEND;TZID=Europe/London:20260628T233000', 'Late on the 28th'),
        ));

        $this->assertSame(['Late on the 28th'], array_column(CalendarSync::eventsOn('2026-06-28'), 'summary'));
        $this->assertEqualsCanonicalizing(['Midnight load-in', 'Overnight get-out', 'All day 29th'], array_column(CalendarSync::eventsOn('2026-06-29'), 'summary'));
    }

    // R14 (second pass): "order" on its own isn't a PO label, and a short PO isn't a clock time, a date or a PO Box.
    public function test_running_orders_times_and_quantities_are_not_taken_for_a_short_po(): void
    {
        $job = fn (string $po) => WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-01', 'ends_on' => '2025-12-01', 'po_number' => $po]);
        foreach ([
            ['PO-12', "Running order\n12:00 Doors open"],
            ['PO-12', 'Running order 12:30 doors, 13:00 speeches'],
            ['PO-123', 'Please order 123 chairs for the stage'],
            ['PO-4521', 'Running order 4521'],
            ['PO-4521', 'PO Box 4521, Manchester'],
            ['PO-12', 'PO 12:30 call for crew'],
            ['PO-12', 'Purchase order 12/03/2025 attached'],
            ['PO-12', "Purchase order terms\n12. Payment within 30 days"],
            ['PO-12', "Purchase order terms\n12 chairs at £5 each"],
            ['PO-12', "PO Date\n12 March 2025"],
            ['PO-12', "PO Number\n4500123\nDate\n12 March 2025"],
            ['PO-12', 'PO no. 12 March 2025'],
        ] as [$po, $text]) {
            $this->assertFalse(Verifier::jobEvidence($text, $job($po))['ok'], "{$po} in “{$text}”");
        }
    }

    // R14 (second pass): the usual UK ways of labelling a PO still count for a short number.
    public function test_short_po_numbers_count_with_the_usual_uk_labels_and_in_a_table(): void
    {
        $job = fn (string $po) => WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-01', 'ends_on' => '2025-12-01', 'po_number' => $po]);
        foreach ([
            ['12345', 'PO Reference: 12345'],
            ['12345', 'Purchase Order Reference 12345'],
            ['10234', 'P/O 10234'],
            ['PO-10234', 'P/O No: 10234'],
            ['10234', 'Purchase Order Reference: 10234'],
            ['10234', "PO Number\tDate\n10234\t12/03/2025"],
            ['10234', "PO Number Date Amount\n10234 12/03/2025 £1,200.00"], // as it's stored, with the tabs gone
            ['PO-4521', 'Order no. 4521'],
            ['PO-4521', 'Order #4521 confirmed'],
            ['PO-12', 'P.O. 12 for the ball'],
        ] as [$po, $text]) {
            $this->assertTrue(Verifier::jobEvidence($text, $job($po))['ok'], "{$po} in “{$text}”");
        }
        $this->assertFalse(Verifier::jobEvidence("PO Number Date Qty\n10233 12/03/2025 4521", $job('PO-4521'))['ok'], 'Only the PO column counts');
    }

    // R13 (second pass): a job PO written with a dotted or slashed "P.O." still matches the bare number.
    public function test_a_job_po_written_with_a_dotted_or_slashed_prefix_matches_the_number(): void
    {
        $job = fn (string $po) => WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-01', 'ends_on' => '2025-12-01', 'po_number' => $po]);
        foreach ([
            ['P.O. 4500123', 'Purchase order 4500123'],
            ['P.O. 4500123', 'Ref 4500123 attached'],
            ['P/O 4500123', 'Purchase order 4500123'],
            ['P.O. 4500123', 'PO 4500123 attached'],
            ['P.O. 10234', 'Purchase order no. 10234'],
        ] as [$po, $text]) {
            $this->assertTrue(Verifier::jobEvidence($text, $job($po))['ok'], "{$po} in “{$text}”");
        }
        $this->assertFalse(Verifier::jobEvidence('Tel 0161 450 0123', $job('P.O. 4500123'))['ok']);
        $this->assertFalse(Verifier::jobEvidence('Studio 10234 on the day', $job('P.O. 10234'))['ok'], 'Still short: needs its label');
    }

    // R4 (second pass): after the key migration, the automatic link that lost the clash to your decision is removed.
    public function test_the_automatic_link_that_lost_a_key_clash_to_your_decision_is_removed(): void
    {
        $this->migration('2026_11_26_000002_stabilise_job_links')->down();
        $job = WorkJob::query()->create(['title' => 'Gig', 'starts_on' => '2025-01-20', 'ends_on' => '2025-01-20']);
        $legacy = fn (string $start, ?int $jobId, string $by, string $updated) => DB::table('calendar_job_links')->insertGetId([
            'event_key' => sha1('gig@google.com|'.$start), 'event_uid' => 'gig@google.com', 'event_starts_at' => $start, 'summary' => 'Gig',
            'work_job_id' => $jobId, 'linked_by' => $by, 'created_at' => $updated, 'updated_at' => $updated,
        ]);
        CalendarEvent::query()->create(['uid' => 'gig@google.com', 'starts_at' => '2025-01-20 09:00:00', 'ends_at' => '2025-01-20 18:00:00', 'summary' => 'Gig']);
        $yours = $legacy('2025-01-13 09:00:00', null, 'you', '2025-01-03 10:00:00');
        $rules = $legacy('2025-01-20 09:00:00', $job->id, 'rules', '2025-01-04 10:00:00');

        $this->migration('2026_11_26_000002_stabilise_job_links')->up();
        $this->migration('2026_11_29_000501_job_finder_repair_calendar_keys')->up();

        $gig = CalendarEvent::query()->sole();
        $this->assertSame([$yours], CalendarJobLink::query()->pluck('id')->all(), 'No automatic link is left over');
        $this->assertTrue(CalendarJobLink::query()->sole()->not_job);
        $this->assertSame($gig->key(), CalendarJobLink::query()->sole()->event_key);
        $this->assertDatabaseHas('audit_events', ['action' => 'calendar.link_removed', 'subject_id' => $rules]);
        $this->actingAs(User::factory()->create())->get('/jobs/finder')->assertOk()->assertInertia(fn ($p) => $p->where('auto', []));
    }

    // R4 (second pass): of two automatic links to one entry, the leftover goes, and any "not this job" on it is kept.
    public function test_a_leftover_automatic_link_gives_way_to_the_entrys_own_link_and_keeps_your_not_this_job(): void
    {
        [$one, $two, $three] = collect(['One', 'Two', 'Three'])->map(fn ($t) => WorkJob::query()->create(['title' => $t, 'starts_on' => '2025-02-01', 'ends_on' => '2025-02-01']))->all();
        CalendarEvent::query()->create(['uid' => 'show@x', 'starts_at' => '2025-02-01 09:00:00', 'ends_at' => '2025-02-01 18:00:00', 'summary' => 'Show']);
        CalendarEvent::query()->create(['uid' => 'other@x', 'starts_at' => '2025-02-02 09:00:00', 'ends_at' => '2025-02-02 18:00:00', 'summary' => 'Other']);
        $link = fn (string $key, string $uid, string $start, int $job, ?array $rejected) => CalendarJobLink::query()->create(['event_key' => $key, 'event_uid' => $uid,
            'event_starts_at' => $start, 'summary' => 'Show', 'work_job_id' => $job, 'rejected' => $rejected, 'linked_by' => 'rules']);
        $holder = $link(CalendarEvent::keyFor('show@x', null), 'show@x', '2025-02-01 09:00:00', $one->id, null);
        $leftover = $link(sha1('show@x|2025-02-01 09:00:00'), 'show@x', '2025-02-01 09:00:00', $two->id, [$three->id]);
        // Here the leftover says "not this job" to the very job the entry was linked to automatically: left for you.
        $otherHolder = $link(CalendarEvent::keyFor('other@x', null), 'other@x', '2025-02-02 09:00:00', $one->id, null);
        $otherLeftover = $link(sha1('other@x|2025-02-02 09:00:00'), 'other@x', '2025-02-02 09:00:00', $two->id, [$one->id]);

        CalendarSync::repairKeys();

        $this->assertNull(CalendarJobLink::query()->find($leftover->id));
        $this->assertSame([$one->id, [$three->id]], [$holder->fresh()->work_job_id, $holder->fresh()->rejected]);
        $this->assertNotNull($otherHolder->fresh());
        $this->assertNotNull($otherLeftover->fresh());
    }

    // R11 (second pass): a run over a narrower period doesn't propose part of a job another run proposed whole.
    public function test_a_narrower_run_does_not_propose_part_of_a_possible_job_again(): void
    {
        $this->travelTo('2026-03-10 12:00:00');
        $raw = "From: buyer@acme.example\r\nSubject: PO-8890\r\n\r\nPO-8890 for the Riverside Gala on 20 April 2026.\r\n";
        $doc = app(DocumentIntake::class)->store($raw, 'po.eml', 'message/rfc822', 'gmail', ['received_at' => '2026-03-01 10:00:00']);
        $doc->update(['status' => 'extracted', 'kind' => 'po', 'extraction' => ['summary' => 'PO', 'po' => ['po_number' => 'PO-8890', 'lines' => []],
            'new_job' => ['title' => 'Riverside Gala', 'starts_on' => '2026-04-20', 'ends_on' => '2026-04-20', 'quote' => 'Riverside Gala on 20 April 2026', 'verified' => true]]]);
        $this->event('Riverside Gala build', '2026-04-20 08:00:00', '2026-04-20 18:00:00', 'Riverside Park');

        $this->finder()->run(null, false);
        $whole = JobCandidate::query()->where('status', 'open')->sole();
        $this->assertCount(2, $whole->sources);

        $this->finder()->run('2025-26', false);
        $this->assertSame([$whole->id], JobCandidate::query()->where('status', 'open')->pluck('id')->all());
        $this->assertCount(2, $whole->fresh()->sources);

        // A run that covers its dates still sees it whole.
        $this->finder()->run('2026-27', false);
        $this->assertSame([$whole->id], JobCandidate::query()->where('status', 'open')->pluck('id')->all());
    }

    // G3 (second pass): adding a possible job to a job you already have leaves an attachment you said isn't for it.
    public function test_merging_a_possible_job_leaves_an_attachment_you_said_isnt_for_that_job(): void
    {
        $leeds = WorkJob::query()->create(['title' => 'Leeds Gala', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-09']);
        $mail = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: Expo\r\n\r\nPO and rider attached.\r\n", 'expo.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $expo = $this->poDoc('PO-3001', 'Purchase order PO-3001 for the Expo on 9 September 2025', $mail->id, 'expo.txt');
        $expo->update(['extraction' => array_merge($expo->extraction, ['new_job' => ['title' => 'Expo', 'starts_on' => '2025-09-09', 'ends_on' => '2025-09-09', 'quote' => 'Expo on 9 September 2025', 'verified' => true]])]);
        $rider = app(DocumentIntake::class)->store('Technical rider: two follow spots.', 'rider.txt', 'text/plain', 'gmail', ['received_at' => '2025-03-03 10:00:00'], $mail->id);
        DocumentReader::relink($rider, $leeds->id, 'you');
        DocumentReader::relink($rider->fresh(), null, 'you');
        $this->assertSame([$leeds->id], $rider->fresh()->job_rejected);

        $this->finder()->run('2025-26', false);
        $candidate = JobCandidate::query()->where('status', 'open')->sole();
        $this->actingAs(User::factory()->create())->post("/jobs/finder/{$candidate->id}/merge", ['work_job_id' => $leeds->id])->assertSessionHas('success');

        $this->assertSame($leeds->id, $expo->fresh()->work_job_id);
        $this->assertNull($rider->fresh()->work_job_id, 'You said the rider isn’t for the Leeds Gala');
        $this->assertNull($mail->fresh()->work_job_id, 'Only the PO goes, as another part of the email isn’t for this job');
    }

    // R15/G3 regression: confirming or undoing an email's link only touches the attachments that are on its job.
    public function test_confirming_or_undoing_an_emails_link_leaves_an_attachment_on_another_job_alone(): void
    {
        $a = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1111']);
        $b = WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-10', 'ends_on' => '2025-12-10', 'po_number' => 'PO-2222']);
        $mail = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: Two POs\r\n\r\nPOs attached.\r\n", 'pos.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $one = $this->poDoc('PO-1111', 'Purchase order PO-1111 Spring Gala', $mail->id, 'po1.txt');
        $two = $this->poDoc('PO-2222', 'Purchase order PO-2222 Winter Ball', $mail->id, 'po2.txt');
        $this->finder()->run('2025-26', false);
        // The email itself is linked to the Spring Gala (as gathering a job's paperwork does).
        $this->finder()->linkDocument($mail->fresh(), $a, 'rules');
        $this->assertSame([$a->id, $b->id], [$mail->fresh()->work_job_id, $two->fresh()->work_job_id]);

        $user = User::factory()->create();
        $this->actingAs($user)->post('/jobs/finder/links', ['type' => 'document', 'id' => $mail->id, 'action' => 'confirm'])->assertSessionHas('success');
        $this->assertSame([$a->id, 'you'], [$mail->fresh()->work_job_id, $mail->fresh()->job_linked_by]);
        $this->assertSame([$b->id, 'rules'], [$two->fresh()->work_job_id, $two->fresh()->job_linked_by], 'The Winter Ball PO stays on the Winter Ball');
        $this->assertSame($b->id, $two->facts()->value('work_job_id'));

        $this->actingAs($user)->post('/jobs/finder/links', ['type' => 'document', 'id' => $mail->id, 'action' => 'unlink'])->assertSessionHas('success');
        $this->assertSame([null, [$a->id]], [$mail->fresh()->work_job_id, $mail->fresh()->job_rejected]);
        $this->assertSame([null, [$a->id]], [$one->fresh()->work_job_id, $one->fresh()->job_rejected], 'What was on the email’s job comes off with it');
        $this->assertSame([$b->id, null], [$two->fresh()->work_job_id, $two->fresh()->job_rejected], 'The Winter Ball PO isn’t touched');
    }

    // R15/G3 regression: moving an email to another job yourself takes what was on its old job with it, and only that.
    public function test_moving_an_email_yourself_moves_the_attachments_that_were_on_its_job(): void
    {
        [$a, $b, $c] = collect(['Spring Gala', 'Summer Fair', 'Winter Ball'])->map(fn ($t) => WorkJob::query()->create(['title' => $t, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']))->all();
        $mail = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: Paperwork\r\n\r\nAttached.\r\n", 'mail.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $first = $this->poDoc('PO-1111', 'Purchase order PO-1111', $mail->id, 'po1.txt');
        $other = $this->poDoc('PO-3333', 'Purchase order PO-3333', $mail->id, 'po3.txt');
        DocumentReader::relink($mail->fresh(), $a->id, 'you');
        DocumentReader::relink($other->fresh(), $c->id, 'you');

        $this->actingAs(User::factory()->create())->patch("/documents/{$mail->id}", ['work_job_id' => $b->id])->assertSessionHas('success');

        $this->assertSame([$b->id, $b->id, $c->id], [$mail->fresh()->work_job_id, $first->fresh()->work_job_id, $other->fresh()->work_job_id]);
        $this->assertSame($b->id, $first->facts()->value('work_job_id'));
        $this->assertSame($c->id, $other->facts()->value('work_job_id'));
    }

    // R15/G3 regression: "not this job" on a gathered email leaves its attachment on another job alone.
    public function test_rejecting_a_gathered_email_leaves_an_attachment_on_another_job_alone(): void
    {
        $a = WorkJob::query()->create(['title' => 'Spring Gala', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'po_number' => 'PO-1111']);
        $b = WorkJob::query()->create(['title' => 'Winter Ball', 'starts_on' => '2025-12-10', 'ends_on' => '2025-12-10', 'po_number' => 'PO-2222']);
        $mail = app(DocumentIntake::class)->store("From: buyer@acme.example\r\nSubject: Two POs\r\n\r\nPOs attached.\r\n", 'pos.eml', 'message/rfc822', 'gmail', ['received_at' => '2025-03-03 10:00:00']);
        $this->poDoc('PO-1111', 'Purchase order PO-1111 Spring Gala', $mail->id, 'po1.txt');
        $two = $this->poDoc('PO-2222', 'Purchase order PO-2222 Winter Ball', $mail->id, 'po2.txt');
        $this->finder()->run('2025-26', false);
        $gatherer = app(JobGatherer::class);
        $gatherer->gather($a, false);
        $find = JobFind::query()->where('work_job_id', $a->id)->where('type', 'document')->where('ref', (string) $mail->id)->sole();
        $this->assertSame(['linked', $a->id, $b->id], [$find->status, $mail->fresh()->work_job_id, $two->fresh()->work_job_id]);

        $gatherer->reject($find);

        $this->assertNull($mail->fresh()->work_job_id);
        $this->assertSame([$b->id, null], [$two->fresh()->work_job_id, $two->fresh()->job_rejected]);
    }

    private function event(string $summary, string $start, ?string $end = null, ?string $location = null): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => md5($summary.$start).'@cal', 'starts_at' => $start, 'ends_at' => $end, 'all_day' => false, 'summary' => $summary, 'location' => $location]);
    }
}
