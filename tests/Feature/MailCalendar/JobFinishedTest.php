<?php

namespace Tests\Feature\MailCalendar;

use App\Console\Commands\JobFinishedNudges;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Intel\Ref;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Todo\FindingsRegistry;
use App\Models\AuditEvent;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Mail and calendar batch 1: "Acme Awards finished yesterday. Invoice it?", once per job. */
class JobFinishedTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> what was sent to Telegram */
    private array $sent = [];

    /** Telegram refuses messages with a link button (as it does a link it can't open). */
    private bool $refuseButtons = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Issuing needs your name and address on the invoice (S6).
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-05 08:37:00', 'Europe/London')->utc());
        Http::fake(['api.telegram.org/*' => function (HttpRequest $r) {
            $this->sent[] = $r->data();

            return $this->refuseButtons && isset($r->data()['reply_markup'])
                ? Http::response(['ok' => false, 'description' => 'Bad Request: wrong HTTP URL'], 400)
                : Http::response(['ok' => true, 'result' => ['message_id' => count($this->sent)]]);
        }]);
    }

    private function job(string $title, string $from, string $to, string $status = 'booked'): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'starts_on' => $from, 'ends_on' => $to, 'status' => $status, 'fee_pence' => 138000, 'po_number' => 'PO-4471']);
    }

    public function test_the_morning_after_a_job_with_no_invoice_it_asks_once_with_a_link(): void
    {
        $job = $this->job('Acme Awards', '2026-12-03', '2026-12-04');

        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 1 finished job')->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertSame('Acme Awards finished yesterday. Invoice it?', $this->sent[0]['text']);
        $button = $this->sent[0]['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame(['text' => 'Open the job', 'url' => url('/jobs/'.$job->id)], $button);
        $this->assertStringNotContainsString('PO-4471', json_encode($this->sent), 'No references or amounts');
        $this->assertStringNotContainsString('1,380', json_encode($this->sent));
        $this->assertSame([$job->id => '2026-12-05'], JobFinishedNudges::told(), 'Kept in settings, the day it was sent');
        $this->assertSame(0, AuditEvent::query()->count(), 'A message is not a change to your records');

        // Once per job, ever: not the next morning, nor after its dates change.
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-06 08:37:00', 'Europe/London')->utc());
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $job->update(['ends_on' => '2026-12-05']);
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->sent);
    }

    public function test_invoiced_cancelled_unfinished_and_set_aside_jobs_are_not_asked_about(): void
    {
        $invoiced = $this->job('Acme Expo', '2026-12-04', '2026-12-04');
        $draft = Invoice::query()->create(['work_job_id' => $invoiced->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);
        app(InvoiceService::class)->updateDraft($draft, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);
        app(InvoiceService::class)->issue($draft->fresh(), '2026-12-04');
        $this->job('Cancelled gala', '2026-12-04', '2026-12-04', 'cancelled');
        $this->job('Still on', '2026-12-04', '2026-12-06');
        $this->job('Long ago', '2026-11-20', '2026-11-20');
        $snoozed = $this->job('Snoozed show', '2026-12-04', '2026-12-04');
        TodoDismissal::query()->create(['key' => 'invoice:'.$snoozed->id, 'until' => now()->addWeek()]);
        $dismissed = $this->job('Not mine', '2026-12-04', '2026-12-04');
        TodoDismissal::query()->create(['key' => 'invoice:'.$dismissed->id, 'until' => null]);

        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 0 finished jobs')->assertSuccessful();
        $this->assertSame([], $this->sent);
    }

    public function test_a_missed_morning_is_caught_up_and_a_draft_is_mentioned(): void
    {
        $job = $this->job('Acme Awards', '2026-12-02', '2026-12-02');
        Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);

        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertSame('Acme Awards finished on Wed 2 Dec. Invoice it? A draft is ready to check.', $this->sent[0]['text']);
    }

    public function test_nothing_is_sent_or_kept_without_telegram_and_a_refused_button_falls_back_to_the_link(): void
    {
        $job = $this->job('Acme Awards', '2026-12-04', '2026-12-04');
        Setting::put('telegram_chat_id', null);
        $this->artisan('ledger:job-finished')->expectsOutputToContain('Telegram isn’t paired')->assertSuccessful();
        $this->assertSame([], JobFinishedNudges::told());

        Setting::put('telegram_chat_id', '42');
        $this->refuseButtons = true;
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertSame('Acme Awards finished yesterday. Invoice it?'."\n".url('/jobs/'.$job->id), end($this->sent)['text']);
        $this->assertSame([$job->id], array_keys(JobFinishedNudges::told()));
    }

    public function test_it_runs_each_morning_on_london_time_after_the_nightly_work(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => str_contains((string) $e->command, 'ledger:job-finished'));
        $this->assertNotNull($event);
        $this->assertSame('Europe/London', (string) $event->timezone);
        $this->assertSame('37 8 * * *', $event->expression);
    }

    /** London wall-clock time, for Carbon and CarbonImmutable alike. */
    private function at(string $london): void
    {
        $utc = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    public function test_postcodes_and_long_references_in_the_title_are_left_out(): void
    {
        // As the job finder names a job from a calendar entry's summary.
        $job = $this->job('Acme Awards, Grosvenor House W1K 7TN, PO 4500123456', '2026-12-04', '2026-12-04');

        $this->artisan('ledger:job-finished')->assertSuccessful();

        $this->assertSame('Acme Awards, Grosvenor House [postcode], PO [number] finished yesterday. Invoice it?', $this->sent[0]['text']);
        $this->assertSame(MorningReport::clean($this->sent[0]['text']), $this->sent[0]['text']);
        $this->assertSame(url('/jobs/'.$job->id), $this->sent[0]['reply_markup']['inline_keyboard'][0][0]['url'], 'The link goes as the button');
    }

    public function test_the_next_morning_report_stays_quiet_after_the_message(): void
    {
        Setting::put('intel_cycle', 'on');
        $this->at('2026-12-05 08:37:00');
        $this->job('Acme Awards', '2026-12-04', '2026-12-04');
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->sent);

        $this->at('2026-12-06 07:31:00');
        $report = MorningReport::build(app(CycleRunner::class)->start('morning'), CarbonImmutable::parse('2026-12-05 08:00:00', 'Europe/London'));

        $this->assertNull($report['did'] ?? null, 'Asking you is not a change the automation made');
        $this->assertTrue($report['quiet'], 'A quiet night sends nothing (plan 7.1)');
    }

    public function test_invoicing_the_job_after_the_message_is_credited_to_you(): void
    {
        $this->at('2026-12-05 07:10:00');
        $job = $this->job('Acme Awards', '2026-12-04', '2026-12-04');
        FindingsRegistry::refresh();
        $finding = Finding::query()->where('key', 'invoice:'.$job->id)->sole();

        $this->at('2026-12-05 08:37:00');
        $this->artisan('ledger:job-finished')->assertSuccessful();

        // You tap "Open the job", draft and issue the invoice straight away.
        $this->at('2026-12-05 08:45:00');
        AuditContext::with('you', function () use ($job) {
            $draft = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);
            app(InvoiceService::class)->updateDraft($draft, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);
            app(InvoiceService::class)->issue($draft->fresh(), '2026-12-05');
        }, 'web');

        $this->at('2026-12-05 09:13:00');
        Cache::forget('findings:synced');
        FindingsRegistry::refresh();
        $this->assertNotNull($finding->fresh()->resolved_at);
        $resolved = FindingEvent::query()->where('finding_id', $finding->id)->where('event', 'resolved')->sole();
        $cause = AuditEvent::query()->find((int) (Ref::parse($resolved->cause_ref)['id'] ?? 0));
        $this->assertSame('Invoice', $cause?->subject_type, 'Dealt with by the invoice, not by the message');
        $this->assertSame('you', $cause?->actor);
    }
}
