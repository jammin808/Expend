<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Expend's own health on the to-do list (the audit log, Starling, the calendar, background work, the AI spending cap),
 * the alarm when the audit log has been altered, overdue-invoice nudges that respect a snooze, and the nightly work on
 * London time.
 */
class HealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function pairTelegram(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_webhook_secret', 'secret');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    /** @return array<string, array> the health items by key */
    private function health(): array
    {
        return collect(TodoList::build(100)['items'])->where('group', 'health')->keyBy('key')->all();
    }

    private function messages(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->count();
    }

    public function test_an_altered_audit_log_goes_first_on_the_list_with_one_telegram_message(): void
    {
        $this->pairTelegram();
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'po_number' => 'PO-1']);
        AuditLog::record('job.updated', $job, null, ['title' => 'Arena show']);
        AuditLog::record('job.updated', $job, null, ['title' => 'Arena show, day 2']);

        $this->artisan('ledger:verify-audit')->assertSuccessful();
        $this->assertSame([], $this->health());
        $this->assertSame(0, $this->messages());

        $tampered = (int) DB::table('audit_events')->orderBy('id')->value('id');
        DB::table('audit_events')->where('id', $tampered)->update(['reason' => 'Changed afterwards']);
        $this->artisan('ledger:verify-audit')->assertFailed();

        $list = TodoList::build(100);
        $item = $list['items'][0];
        $this->assertSame(['health:audit', 'health', 1], [$item['key'], $item['group'], $item['priority']]);
        $this->assertSame('The audit log has been altered', $item['title']);
        $this->assertStringContainsString('event '.$tampered, $item['detail']);
        $this->assertTrue($item['snooze_only']);
        $this->assertSame(1, $this->messages(), 'Told on Telegram at once');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'audit log altered') && $r['chat_id'] === '42');

        // The same break found again the next night: still first on the list, not sent again.
        Carbon::setTestNow('2026-11-25 03:10:00');
        $this->artisan('ledger:verify-audit')->assertFailed();
        $this->assertSame(1, $this->messages());
        $this->assertArrayHasKey('health:audit', $this->health());
    }

    public function test_an_alarm_that_couldnt_be_sent_is_tried_again_at_the_next_check(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'po_number' => 'PO-1']);
        AuditLog::record('job.updated', $job, null, ['title' => 'Arena show']);
        DB::table('audit_events')->update(['reason' => 'Changed afterwards']);

        $this->artisan('ledger:verify-audit')->assertFailed(); // Telegram not paired yet
        $this->assertArrayHasKey('health:audit', $this->health());

        $this->pairTelegram();
        $this->artisan('ledger:verify-audit')->assertFailed();
        $this->artisan('ledger:verify-audit')->assertFailed();
        $this->assertSame(1, $this->messages());
    }

    public function test_starling_the_calendar_failed_work_and_the_ai_cap_each_show(): void
    {
        $this->assertSame([], $this->health());

        Setting::put('starling_token', 'token');
        Setting::put('starling_last_result', ['at' => now()->subHours(3)->toIso8601String(), 'results' => [
            ['account' => 'Starling Business', 'ok' => false, 'result' => 'Starling said no'], ['account' => 'Starling Personal', 'ok' => true, 'result' => '3 imported'],
        ]]);
        Setting::put('calendar_ics_url', 'https://calendar.example/private.ics');
        Setting::put('calendar_synced_at', now()->subHours(3)->format('Y-m-d H:i'));
        DB::table('failed_jobs')->insert(['uuid' => 'a1', 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\ReadDocument']),
            'exception' => 'RuntimeException: no', 'failed_at' => now()->subHour()]);
        Document::query()->create(['original_name' => 'po.pdf', 'stored_path' => 'documents/po.pdf', 'sha256' => str_repeat('b', 64), 'source' => 'upload', 'status' => 'failed',
            'error' => 'This month’s AI spending cap ($40) is reached. Raise it in Settings › AI to continue.']);

        $failed = 'health:failed_jobs:'.DB::table('failed_jobs')->value('id');
        $health = $this->health();
        $this->assertSame(['health:starling', 'health:calendar', $failed, 'health:ai_cap'], array_keys($health));
        $this->assertSame('Starling import failed: Starling Business', $health['health:starling']['title']);
        $this->assertSame('Calendar not updated since 24 Nov, 07:00', $health['health:calendar']['title'], 'On London time');
        $this->assertSame('1 background task failed in the last week', $health[$failed]['title']);
        $this->assertStringContainsString('reading a document', $health[$failed]['detail']);
        $this->assertSame('1 document not read: the AI spending cap was reached', $health['health:ai_cap']['title']);
        $this->assertSame('/documents?status=failed', $health['health:ai_cap']['href']);
        $this->assertTrue(collect($health)->every(fn ($i) => $i['priority'] === 1));
        $this->assertSame([true, true, false, true], array_values(array_map(fn ($i) => $i['snooze_only'], $health)), 'Failed work is kept by the newest failure');

        // Put right: the calendar synced an hour ago, Starling's next import worked.
        Setting::put('calendar_synced_at', now()->subHour()->format('Y-m-d H:i'));
        Setting::put('starling_last_result', ['at' => now()->toIso8601String(), 'results' => [['account' => 'Starling Business', 'ok' => true, 'result' => '2 imported']]]);
        $this->assertSame([$failed, 'health:ai_cap'], array_keys($this->health()));

        // Snoozed: hidden for the week, and "not relevant" isn't offered where the key never changes.
        $this->post('/todo/dismiss', ['key' => $failed, 'days' => 7])->assertSessionHas('success');
        $this->post('/todo/dismiss', ['key' => 'health:ai_cap'])->assertSessionHasErrors('key');
        $this->assertSame(['health:ai_cap'], array_keys($this->health()));
    }

    public function test_failed_work_is_only_recent_failures_and_can_be_set_aside(): void
    {
        $fail = function (string $job, string $at): int {
            return (int) DB::table('failed_jobs')->insertGetId(['uuid' => uniqid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\'.$job]),
                'exception' => 'RuntimeException: no', 'failed_at' => $at]);
        };
        // Failures from launch, never cleared: long put right, so not something wrong now.
        $fail('ReadDocument', '2026-09-01 10:00:00');
        $fail('FindJobs', '2026-10-30 10:00:00');
        $this->assertSame([], $this->health());

        $fail('ScanGmail', '2026-11-23 22:00:00');
        $second = $fail('HandleTelegramUpdate', '2026-11-24 08:00:00');
        $item = $this->health()['health:failed_jobs:'.$second];
        $this->assertSame('2 background tasks failed in the last week', $item['title']);
        $this->assertStringContainsString('The newest: a Telegram message', $item['detail']);
        $this->assertStringContainsString('queue:forget', $item['detail']);
        $this->assertStringNotContainsString('retry all', $item['detail']);
        FindingsRegistry::sync();

        // Looked at and set aside: hidden, and recorded as set aside, not dealt with.
        $this->post('/todo/dismiss', ['key' => 'health:failed_jobs:'.$second])->assertSessionHas('success', 'Marked not relevant.');
        $this->assertSame([], $this->health());
        FindingsRegistry::sync();
        $this->assertSame('dismissed', Finding::query()->where('key', 'health:failed_jobs:'.$second)->value('resolution'));

        // Something else fails: back, about the new failure only.
        $third = $fail('ReadDocument', '2026-11-24 09:30:00');
        $this->assertSame('1 background task failed in the last week', $this->health()['health:failed_jobs:'.$third]['title']);
    }

    public function test_an_event_written_while_the_check_runs_is_not_an_alarm(): void
    {
        $this->pairTelegram();
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'po_number' => 'PO-1']);
        for ($i = 0; $i < 5; $i++) {
            AuditLog::record('job.updated', $job, null, ['title' => 'Arena show '.$i]);
        }
        // Another process (the queue) writes an event just before each of the check's first three reads: whichever
        // order it reads the events and the chain's head in, one of them sees a head the events it read don't reach.
        $writes = 0;
        $busy = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$writes, &$busy, $job) {
            if ($busy || $writes >= 3 || ! str_starts_with(strtolower(ltrim($sql)), 'select') || ! preg_match('/audit_(events|heads)/', $sql)) {
                return;
            }
            $busy = true;
            $writes++;
            try {
                AuditLog::record('document.read', $job, null, ['by' => 'the queue'], actor: 'ai');
            } finally {
                $busy = false;
            }
        });
        $this->assertSame(['ok' => true, 'checked' => 8, 'broken_at' => null], AuditLog::verify(), 'All checked, none looks like a break');
        $this->assertSame(3, $writes);

        $this->artisan('ledger:verify-audit')->assertSuccessful();
        $this->assertSame(['ok' => true, 'checked' => 8], array_intersect_key(Setting::get(TodoList::AUDIT_RESULT), ['ok' => 0, 'checked' => 0]));
        $this->assertSame(0, $this->messages(), 'No alarm');
        $this->assertSame([], $this->health());

        // A real break is still found.
        DB::table('audit_events')->where('id', DB::table('audit_events')->orderBy('id')->value('id'))->update(['reason' => 'Changed afterwards']);
        $this->artisan('ledger:verify-audit')->assertFailed();
        $this->assertSame(1, $this->messages());
        $this->assertArrayHasKey('health:audit', $this->health());
    }

    public function test_an_event_added_without_moving_the_head_is_a_break(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'po_number' => 'PO-1']);
        AuditLog::record('job.updated', $job, null, ['title' => 'Arena show']);
        $last = AuditEvent::query()->orderByDesc('id')->first();

        // Added straight into the database, chained and hashed as the app would, but not through AuditLog::record().
        $added = new AuditEvent(['occurred_at' => '2026-11-24 10:00:00', 'actor' => 'you', 'action' => 'job.updated', 'subject_type' => 'WorkJob', 'subject_id' => $job->id,
            'before' => null, 'after' => ['title' => 'Somewhere else'], 'reason' => null, 'prev_hash' => $last->hash]);
        $added->hash = AuditLog::hashFor($added);
        $added->save();

        $this->assertSame(['ok' => false, 'checked' => 1, 'broken_at' => $added->id], AuditLog::verify());
    }

    public function test_a_snoozed_overdue_invoice_gets_no_nudge(): void
    {
        $this->pairTelegram();
        $issue = function (string $title): Invoice {
            $job = WorkJob::query()->create(['title' => $title, 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'po_number' => 'PO-'.$title]);
            $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example']);
            app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

            return app(InvoiceService::class)->issue($invoice->fresh(), '2026-09-15')->fresh();
        };
        $snoozed = $issue('Gala');
        $dismissed = $issue('Launch');
        $open = $issue('Awards');
        TodoDismissal::query()->create(['key' => 'invoice_overdue:'.$snoozed->id, 'until' => now()->addDays(7)]);
        TodoDismissal::query()->create(['key' => 'invoice_overdue:'.$dismissed->id, 'until' => null]);

        $this->artisan('ledger:invoice-nudges')->expectsOutput('Nudged about 1 overdue invoices.')->assertSuccessful();
        $this->assertNotNull($open->fresh()->overdue_nudged_at);
        $this->assertNull($snoozed->fresh()->overdue_nudged_at);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Invoice '.$open->number));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Invoice '.$snoozed->number));

        // The snooze ends: the nudge comes again.
        Carbon::setTestNow('2026-12-02 09:13:00');
        $this->artisan('ledger:invoice-nudges')->expectsOutput('Nudged about 2 overdue invoices.')->assertSuccessful();
        $this->assertNotNull($snoozed->fresh()->overdue_nudged_at);
        $this->assertNull($dismissed->fresh()->overdue_nudged_at, 'Marked not relevant: never nudged');
    }

    public function test_the_nightly_work_runs_on_london_time(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $name) => $events->first(fn (Event $e) => str_contains((string) $e->command, $name) || $e->description === $name);
        foreach (['ledger:verify-audit', 'ledger:transfers', 'ledger:categorise', 'ledger:find-jobs', 'ledger:opportunities', 'ledger:recheck', 'ledger:findings', 'gmail-rescan', 'starling-import'] as $name) {
            $event = $find($name);
            $this->assertNotNull($event, $name);
            $this->assertSame('Europe/London', (string) $event->timezone, $name);
        }
    }
}
