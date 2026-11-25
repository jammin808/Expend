<?php

namespace Tests\Feature;

use App\Domain\Calendar\CalendarSync;
use App\Http\Controllers\JobFinderController;
use App\Models\CalendarJobLink;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * "This is a job" on a calendar entry whose title or venue is longer than a job's (Google Calendar keeps a full
 * address with directions, or a meeting link with dial-in text). The calendar page and the connections map fill the
 * form with text that fits, the whole text in the notes; the server does the same when the entry's own text comes in
 * too long (as a page opened before this release still sends it). A long venue you typed yourself is refused, and the
 * page shows why.
 */
class CalendarJobLongTextTest extends TestCase
{
    use RefreshDatabase;

    private const VENUE = 'ExCeL London, Royal Victoria Dock, 1 Western Gateway, London E16 1XL. Crew and deliveries: use the '
        .'East Entrance off Seagull Lane, then follow the signs for the loading bays; check in at the security lodge by Gate 4 '
        .'with photo ID.';

    private const TITLE = 'Acme Events Awards 2026: rig, show and de-rig for the main stage, the breakout rooms and the gala '
        .'dinner, with the client walkthrough on the first evening and the crew briefing at 07:30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Queue::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
    }

    /** One entry in a Google Calendar feed, read the way Sync now reads it. */
    private function entry(string $summary, ?string $location): void
    {
        $escape = fn (string $s) => str_replace([',', ';'], ['\\,', '\\;'], $s);
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:awards-2026@example.test\r\n"
            ."DTSTART:20260310T080000Z\r\nDTEND:20260310T220000Z\r\nSUMMARY:".$escape($summary)."\r\n"
            .($location !== null ? 'LOCATION:'.$escape($location)."\r\n" : '')
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";
        app(CalendarSync::class)->load($ics);
    }

    /** The entry as the calendar page gets it. */
    private function pageEntry(): array
    {
        $page = $this->get('/jobs/calendar?m=2026-03')->assertInertia(fn ($p) => $p->component('jobs/calendar', false))->viewData('page');

        return json_decode(json_encode($page['props']), true)['events'][0];
    }

    /** What JobForm sends for "This is a job", filled in from the entry as the live release's page filled it. */
    private function formBody(array $e, array $changes = []): array
    {
        return $changes + [
            'title' => $e['summary'], 'client_id' => $e['client_id'] ? (string) $e['client_id'] : '', 'new_client' => '',
            'starts_on' => $e['date'], 'ends_on' => $e['last_day'], 'start_time' => $e['start_time'] ?? '', 'end_time' => $e['end_time'] ?? '',
            'location' => $e['location'] ?? '', 'postcode' => $e['postcode'] ?? '', 'fee' => '', 'status' => 'done', 'notes' => '',
            'po_number' => '', 'po_not_required' => false, 'key' => $e['key'], 'date' => $e['date'], 'then' => 'job',
        ];
    }

    /** The page's own fitEntry (resources/js/pages/jobs/calendar.tsx), run in node: what the form is filled with. */
    private function pageFit(string $title, ?string $location): array
    {
        $probe = new Process(['node', '-e', 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Node 22 or later is needed to run the page code.');
        }
        $source = (string) file_get_contents(resource_path('js/pages/jobs/calendar.tsx'));
        $this->assertSame(1, preg_match('/^function shorten\(.*?^export function fitEntry\(.*?^}$/ms', $source, $code), 'shorten and fitEntry are on the calendar page');
        $dir = sys_get_temp_dir().'/fit-entry-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/fit.mts', $code[0]);
        file_put_contents($dir.'/run.mjs', "const { fitEntry } = await import(process.argv[2]);\n"
            ."const [title, location] = JSON.parse(process.argv[3]);\nconsole.log(JSON.stringify(fitEntry(title, location)));\n");
        $run = new Process(['node', '--no-warnings', '--experimental-strip-types', $dir.'/run.mjs', 'file://'.$dir.'/fit.mts', json_encode([$title, $location])]);
        $run->run();
        @unlink($dir.'/fit.mts');
        @unlink($dir.'/run.mjs');
        @rmdir($dir);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }

    /** Posts as Inertia's useForm does: JSON, with the Inertia headers. */
    private function postAsPage(array $body)
    {
        return $this->json('POST', '/calendar/job', $body, ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']);
    }

    public function test_the_entrys_own_long_venue_saves_shortened_with_the_whole_venue_in_the_notes(): void
    {
        $this->assertGreaterThan(200, mb_strlen(self::VENUE));
        $this->entry('Acme Awards rig', self::VENUE);
        $e = $this->pageEntry();
        $this->assertSame(self::VENUE, $e['location']);

        $response = $this->postAsPage($this->formBody($e));

        $response->assertSessionHasNoErrors();
        $job = WorkJob::query()->sole();
        $response->assertRedirect('/jobs/'.$job->id);
        // Cut at the end of a part of the address, not in the middle of a word.
        $this->assertSame('ExCeL London, Royal Victoria Dock, 1 Western Gateway, London E16 1XL', $job->location);
        $this->assertStringContainsString('Full venue from your calendar: '.self::VENUE, $job->notes);
        $this->assertSame('E16 1XL', $job->postcode);
        $this->assertStringContainsString('the whole text is in the job’s notes', session('success'));
        $this->assertSame($job->id, CalendarJobLink::query()->value('work_job_id'));
    }

    public function test_the_entrys_own_long_title_saves_shortened_with_the_whole_title_in_the_notes(): void
    {
        $this->assertGreaterThan(150, mb_strlen(self::TITLE));
        $this->entry(self::TITLE, 'Grand Hall, Leeds');
        $e = $this->pageEntry();

        $response = $this->postAsPage($this->formBody($e));

        $response->assertSessionHasNoErrors();
        $job = WorkJob::query()->sole();
        // Cut after a part of it, not in the middle of a word.
        $this->assertSame('Acme Events Awards 2026: rig, show and de-rig for the main stage, the breakout rooms and the gala dinner', $job->title);
        $this->assertSame('Grand Hall, Leeds', $job->location);
        $this->assertSame('Full title from your calendar: '.self::TITLE, $job->notes);
    }

    public function test_text_is_shortened_at_a_sensible_place(): void
    {
        // A sentence or a line ends it (the address before the directions), else a part of it, else a word with "…".
        $this->assertSame('Grand Hall, Leeds', JobFinderController::shorten("Grand Hall, Leeds\n".str_repeat('Park behind the stage door ', 8), 50));
        $words = 'Acme Events Awards rig show and de-rig for the main stage and the breakout rooms with the client walkthrough on the first evening';
        $this->assertSame('Acme Events Awards rig show and de-rig for the main stage and the…', JobFinderController::shorten($words, 70));
        $this->assertSame(str_repeat('x', 39).'…', JobFinderController::shorten(str_repeat('x', 60), 40));
        $this->assertSame('Grand Hall, Leeds', JobFinderController::shorten('Grand Hall, Leeds', 50));
        foreach ([self::VENUE, self::TITLE, $words, str_repeat('Hall 3 – ', 40), str_repeat('😀', 300)] as $text) {
            foreach ([40, 150, 200] as $max) {
                $this->assertLessThanOrEqual($max, mb_strlen(JobFinderController::shorten($text, $max)));
            }
        }
    }

    public function test_notes_you_typed_are_kept_above_the_whole_venue(): void
    {
        $this->entry('Acme Awards rig', self::VENUE);
        $e = $this->pageEntry();

        $this->postAsPage($this->formBody($e, ['notes' => 'Bring the radios']))->assertSessionHasNoErrors();

        $this->assertSame("Bring the radios\nFull venue from your calendar: ".self::VENUE, WorkJob::query()->sole()->notes);
    }

    public function test_the_whole_venue_is_added_to_the_notes_once_and_only_if_they_have_room(): void
    {
        $this->entry('Acme Awards rig', self::VENUE);
        $e = $this->pageEntry();
        $line = 'Full venue from your calendar: '.self::VENUE;

        // Already there (you put the whole venue back in the venue box): not added again.
        $this->postAsPage($this->formBody($e, ['notes' => $line, 'then' => null]))->assertSessionHasNoErrors();
        $this->assertSame($line, WorkJob::query()->sole()->notes);
        WorkJob::query()->delete();

        // Notes that are nearly full stay as you typed them; the venue is still shortened, and the message says where the rest is.
        $full = str_repeat('Bring the radios. ', 110);
        $this->postAsPage($this->formBody($e, ['notes' => trim($full)]))->assertSessionHasNoErrors();
        $job = WorkJob::query()->sole();
        $this->assertSame(trim($full), $job->notes);
        $this->assertSame('ExCeL London, Royal Victoria Dock, 1 Western Gateway, London E16 1XL', $job->location);
        $this->assertStringContainsString('the whole text is still in your calendar', session('success'));
    }

    public function test_the_calendar_page_fills_the_form_with_text_that_fits(): void
    {
        $this->entry(self::TITLE, self::VENUE);
        $e = $this->pageEntry();

        $fit = $this->pageFit($e['summary'], $e['location']);

        // Cut the way the server cuts, with the whole text in the notes.
        $this->assertSame(JobFinderController::shorten(self::TITLE, 150), $fit['title']);
        $this->assertSame('ExCeL London, Royal Victoria Dock, 1 Western Gateway, London E16 1XL', $fit['location']);
        $this->assertSame('Full title from your calendar: '.self::TITLE."\nFull venue from your calendar: ".self::VENUE, $fit['notes']);
        $this->assertSame(['title' => 'Grand Hall, Leeds', 'location' => null, 'notes' => null], $this->pageFit('Grand Hall, Leeds', null));

        $response = $this->postAsPage($this->formBody($e, ['title' => $fit['title'], 'location' => $fit['location'], 'notes' => $fit['notes']]));

        $response->assertSessionHasNoErrors();
        $job = WorkJob::query()->sole();
        $response->assertRedirect('/jobs/'.$job->id);
        $this->assertSame([$fit['title'], $fit['location'], $fit['notes']], [$job->title, $job->location, $job->notes]);
        $this->assertStringNotContainsString('shortened', session('success'));
    }

    public function test_the_connections_map_fills_the_form_the_same_way(): void
    {
        $this->entry('Acme Awards rig', self::VENUE);
        $page = $this->get('/connections?year=2025-26&all=1')->assertInertia(fn ($p) => $p->component('connections', false))->viewData('page');
        $node = collect(json_decode(json_encode($page['props']), true)['nodes'])->firstWhere('type', 'calendar');
        $fit = $this->pageFit($node['label'], $node['data']['location']);

        // The map's form: no postcode or client filled in, and it stays on the map afterwards.
        $this->postAsPage([
            'title' => $fit['title'], 'client_id' => '', 'new_client' => '', 'starts_on' => $node['date'], 'ends_on' => $node['end'] ?? $node['date'],
            'start_time' => $node['data']['time'] ?? '', 'end_time' => $node['data']['end_time'] ?? '', 'location' => $fit['location'], 'postcode' => '',
            'fee' => '', 'status' => 'done', 'notes' => $fit['notes'], 'po_number' => '', 'po_not_required' => false,
            'key' => $node['data']['key'], 'date' => $node['date'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $job = WorkJob::query()->sole();
        $this->assertSame('ExCeL London, Royal Victoria Dock, 1 Western Gateway, London E16 1XL', $job->location);
        $this->assertSame('Full venue from your calendar: '.self::VENUE, $job->notes);
    }

    public function test_a_long_venue_you_typed_yourself_is_refused_with_a_message_the_page_shows(): void
    {
        $this->entry('Acme Awards rig', 'ExCeL London');
        $e = $this->pageEntry();

        $this->postAsPage($this->formBody($e, ['location' => str_repeat('Hall S10, ', 25)]))
            ->assertRedirect()->assertSessionHasErrors(['location' => 'The venue is too long (200 characters at most).']);

        $this->assertSame(0, WorkJob::query()->count());
    }

    public function test_a_long_title_or_notes_you_typed_are_refused_in_the_job_forms_words(): void
    {
        $this->entry('Acme Awards rig', 'ExCeL London');
        $e = $this->pageEntry();

        // The same words the job form shows elsewhere, with the thousands written as 2,000.
        $this->postAsPage($this->formBody($e, ['title' => str_repeat('Acme Events rig ', 10), 'notes' => str_repeat('Bring the radios. ', 120)]))
            ->assertRedirect()->assertSessionHasErrors([
                'title' => 'The job title is too long (150 characters at most).',
                'notes' => 'The notes are too long (2,000 characters at most).',
            ]);

        $this->assertSame(0, WorkJob::query()->count());
    }
}
