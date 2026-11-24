<?php

namespace Tests\Feature\MailCalendar;

use App\Domain\Calendar\CalendarSync;
use App\Domain\Calendar\EmptyFeed;
use App\Jobs\FindJobs;
use App\Models\CalendarEvent;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/** Mail and calendar batch 1: cancelled entries stop counting, a bad feed never wipes the calendar, Sync now finds jobs. */
class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://calendar.example.test/private/basic.ics';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-24 10:00:00'));
        Setting::put('job_finder_auto', false);
    }

    private function ics(string ...$events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n".implode('', $events)."END:VCALENDAR\r\n";
    }

    private function vevent(string $uid, string $start, string $summary, array $extra = []): string
    {
        $end = 'DTEND:'.CarbonImmutable::parse(substr($start, 8))->addHours(10)->format('Ymd\THis\Z');

        return "BEGIN:VEVENT\r\nUID:{$uid}\r\n{$start}\r\n{$end}\r\nSUMMARY:{$summary}\r\nLOCATION:Acme Arena\r\n"
            .implode('', array_map(fn ($l) => $l."\r\n", $extra))."END:VEVENT\r\n";
    }

    public function test_a_cancelled_entry_is_not_stored_and_one_cancelled_since_stops_counting(): void
    {
        $sync = app(CalendarSync::class);
        $sync->load($this->ics(
            $this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig'),
            $this->vevent('expo-1', 'DTSTART:20261210T080000Z', 'Acme Expo build', ['STATUS:CANCELLED']),
        ));
        $this->assertSame(['Acme Awards rig'], CalendarEvent::query()->pluck('summary')->all(), 'The cancelled entry is left out');

        // The feed now marks the awards entry cancelled (Google keeps it in the feed with STATUS:CANCELLED).
        $sync->load($this->ics(
            $this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig', ['STATUS:CANCELLED', 'SEQUENCE:2']),
            $this->vevent('expo-1', 'DTSTART:20261210T080000Z', 'Acme Expo build', ['STATUS:CANCELLED']),
        ));
        $this->assertSame(0, CalendarEvent::query()->count(), 'A good feed saying it is cancelled is believed');
        $this->assertSame([], CalendarSync::eventsOn('2026-12-03'));
    }

    public function test_a_cancelled_occurrence_of_a_repeating_entry_is_left_out_and_the_rest_kept(): void
    {
        app(CalendarSync::class)->load($this->ics(
            $this->vevent('weekly-1', 'DTSTART:20261201T080000Z', 'Acme weekly show', ['RRULE:FREQ=WEEKLY;COUNT=3']),
            "BEGIN:VEVENT\r\nUID:weekly-1\r\nRECURRENCE-ID:20261208T080000Z\r\nDTSTART:20261208T080000Z\r\nDTEND:20261208T180000Z\r\nSUMMARY:Acme weekly show\r\nSTATUS:CANCELLED\r\nEND:VEVENT\r\n",
        ));

        $days = CalendarEvent::query()->get()->map->day()->sort()->values()->all();
        $this->assertSame(['2026-12-01', '2026-12-15'], $days);
    }

    public function test_a_feed_with_no_entries_keeps_the_stored_ones(): void
    {
        $sync = app(CalendarSync::class);
        $sync->load($this->ics($this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig')));

        try {
            $sync->load($this->ics());
            $this->fail('An empty feed is reported');
        } catch (EmptyFeed $e) {
            $this->assertSame(1, $e->kept);
            $this->assertStringContainsString('kept', $e->getMessage());
        }
        $this->assertSame(['Acme Awards rig'], CalendarEvent::query()->pluck('summary')->all());

        // With nothing stored, an empty calendar is simply empty.
        CalendarEvent::query()->delete();
        $this->assertSame(0, $sync->load($this->ics()));
    }

    public function test_a_feed_that_fails_or_is_not_a_calendar_keeps_the_stored_ones(): void
    {
        $sync = app(CalendarSync::class);
        $sync->load($this->ics($this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig')));
        Queue::fake();

        $response = null;
        Http::fake(['calendar.example.test/*' => function () use (&$response) {
            return $response;
        }]);
        foreach ([Http::response('Server error', 500), Http::response('<html><body>Sign in</body></html>', 200), Http::response('', 200)] as $response) {
            try {
                $sync->sync(self::FEED);
                $this->fail('A bad feed is reported');
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $e);
                $this->assertStringContainsString('entries already here were kept', $e->getMessage(), 'Said in plain words');
            }
            $this->assertSame(1, CalendarEvent::query()->count(), 'Still there');
        }
        Queue::assertNotPushed(FindJobs::class);
    }

    public function test_the_hourly_refresh_reports_an_empty_feed_without_counting_it_as_a_refresh(): void
    {
        app(CalendarSync::class)->load($this->ics($this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig')));
        Setting::put('calendar_ics_url', self::FEED);
        Setting::put('calendar_synced_at', '2026-11-24 08:00');
        Queue::fake();
        Http::fake(['calendar.example.test/*' => Http::response($this->ics())]);

        $this->artisan('ledger:sync-calendar')->expectsOutputToContain('came back with no entries')->assertSuccessful();

        $this->assertSame('2026-11-24 08:00', Setting::get('calendar_synced_at'), 'Not a refresh: the health check notices if it keeps happening');
        $this->assertSame(1, CalendarEvent::query()->count());
        Queue::assertNotPushed(FindJobs::class);
    }

    public function test_the_hourly_refresh_runs_the_job_finder_once(): void
    {
        Setting::put('calendar_ics_url', self::FEED);
        Queue::fake();
        Http::fake(['calendar.example.test/*' => Http::response($this->ics($this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig')))]);

        $this->artisan('ledger:sync-calendar')->expectsOutputToContain('Synced 1 calendar events')->assertSuccessful();

        Queue::assertPushed(FindJobs::class, 1);
        $this->assertSame('2026-11-24 10:00', Setting::get('calendar_synced_at'));
    }

    public function test_sync_now_in_settings_also_runs_the_job_finder(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        Setting::put('calendar_ics_url', self::FEED);
        Queue::fake();
        $body = $this->ics($this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig'));
        Http::fake(['calendar.example.test/*' => function () use (&$body) {
            return Http::response($body);
        }]);

        $this->post('/settings/calendar/sync')->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Calendar synced: 1 entry.') && str_contains($m, 'press Find jobs now'));
        Setting::put('job_finder_auto', true);
        $this->post('/settings/calendar/sync')->assertSessionHas('success', fn ($m) => str_contains($m, 'Looking for jobs in it now'));
        Setting::put('job_finder_auto', false);

        Queue::assertPushed(FindJobs::class, fn (FindJobs $job) => $job->taxYear === null && ! $job->asked);
        $this->assertSame(1, CalendarEvent::query()->count());

        // An empty feed says so on the page, and keeps the entry.
        $body = $this->ics();
        $this->post('/settings/calendar/sync')->assertSessionHas('error', fn ($m) => str_contains($m, 'were kept') || str_contains($m, 'was kept'));
        $this->assertSame(1, CalendarEvent::query()->count());
    }

    public function test_a_feed_cut_short_after_an_entry_keeps_the_stored_ones(): void
    {
        $events = '';
        foreach (range(1, 20) as $i) {
            $events .= $this->vevent("rig-{$i}", 'DTSTART:202612'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'T080000Z', "Acme rig {$i}");
        }
        $sync = app(CalendarSync::class);
        $sync->load($this->ics($events));
        $this->assertSame(20, CalendarEvent::query()->count());

        // The transfer stopped after the first entry: the reader takes it, with only that entry, and no END:VCALENDAR.
        $cut = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\n".$this->vevent('rig-1', 'DTSTART:20261201T080000Z', 'Acme rig 1');
        try {
            $sync->load($cut);
            $this->fail('A feed cut short is reported');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cut short, so the entries already here were kept', $e->getMessage());
        }
        $this->assertSame(20, CalendarEvent::query()->count(), 'The other 19 entries are kept');

        // Trailing blank lines after END:VCALENDAR are fine.
        $this->assertSame(20, $sync->load($this->ics($events)."\r\n\r\n"));
    }

    public function test_a_feed_that_doesnt_answer_says_so_in_plain_words_and_keeps_the_entries(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        app(CalendarSync::class)->load($this->ics($this->vevent('awards-1', 'DTSTART:20261203T080000Z', 'Acme Awards rig')));
        Setting::put('calendar_ics_url', self::FEED);
        Queue::fake();
        Http::fake(['calendar.example.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received')]);

        $this->post('/settings/calendar/sync')->assertSessionHas('error', 'The calendar feed didn’t answer, so the entries already here were kept. Try again in a few minutes.');

        $this->assertSame(1, CalendarEvent::query()->count());
        Queue::assertNotPushed(FindJobs::class);
    }
}
