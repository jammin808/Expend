<?php

namespace Tests\Feature\MailCalendar;

use App\Console\Commands\JobFinishedNudges;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Intel\Ref;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\FindingsRegistry;
use App\Models\AuditEvent;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
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

/**
 * Mail and calendar batch 1, as S10 changed it: the morning after a job finishes, "Acme Awards: last day was yesterday.
 * All done? Marking it done gets its invoice ready to check." with Done, Not yet, Cancelled and Open the job, once per
 * job and finish.
 */
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
        Setting::put('telegram_webhook_secret', 'secret');
        $job = $this->job('Acme Awards', '2026-12-03', '2026-12-04');

        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 1 finished job')->assertSuccessful();

        $this->assertCount(1, $this->messages());
        $sent = $this->messages()[0];
        $this->assertSame('Acme Awards: last day was yesterday. All done? Marking it done gets its invoice ready to check.', $sent['text']);
        // S10: Done, Not yet / Cancelled, Open the job: the presses bound to the job as shown (Telegram::callbackData).
        $keyboard = $sent['reply_markup']['inline_keyboard'];
        $this->assertSame([['Done', Telegram::callbackData('job_done', (string) $job->id)], ['Not yet', Telegram::callbackData('job_not_yet', (string) $job->id)]],
            array_map(fn ($b) => [$b['text'], $b['callback_data']], $keyboard[0]));
        // Worded as on the job page and the to-do list: it asks what they owe before anything is done.
        $this->assertSame(['Cancelled…', Telegram::callbackData('job_cancel', (string) $job->id)], [$keyboard[1][0]['text'], $keyboard[1][0]['callback_data']]);
        $this->assertSame(['text' => 'Open the job', 'url' => url('/jobs/'.$job->id)], $keyboard[1][1]);
        $this->assertTrue((bool) Setting::get('telegram_buttons_on'), 'Presses are let through (allowButtons)');
        $this->assertStringNotContainsString('PO-4471', json_encode($this->sent), 'No references or amounts');
        $this->assertStringNotContainsString('1,380', json_encode($this->sent));
        $this->assertSame([$job->id.'@2026-12-04T23:59' => '2026-12-05'], JobFinishedNudges::told(), 'Kept in settings by job and finish, the day it was sent');
        $this->assertSame(0, AuditEvent::query()->count(), 'A message is not a change to your records');

        // Once per finish: not the next morning.
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-06 08:37:00', 'Europe/London')->utc());
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->messages());

        // A finish that moves later (a day added) asks again, the morning after the new last day.
        $job->update(['ends_on' => '2026-12-06']);
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->messages(), 'Not before the new last day has finished');
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-07 08:37:00', 'Europe/London')->utc());
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(2, $this->messages());
        $this->assertStringStartsWith('Acme Awards: last day was yesterday.', $this->messages()[1]['text']);
    }

    /** @return list<array> the messages sent (not the setWebhook that lets presses through) */
    private function messages(): array
    {
        return array_values(array_filter($this->sent, fn (array $m) => isset($m['text'])));
    }

    public function test_invoiced_cancelled_unfinished_and_set_aside_jobs_are_not_asked_about(): void
    {
        $invoiced = $this->job('Acme Expo', '2026-12-04', '2026-12-04');
        $draft = Invoice::query()->create(['work_job_id' => $invoiced->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);
        app(InvoiceService::class)->updateDraft($draft, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);
        app(InvoiceService::class)->issue($draft->fresh(), '2026-12-04');
        $this->job('Cancelled gala', '2026-12-04', '2026-12-04', 'cancelled');
        $this->job('Still on', '2026-12-04', '2026-12-06');
        // Finished more than 60 days ago (the owner's choice at release): it keeps "Invoice <job>" on the to-do list instead.
        $this->job('Long ago', '2026-09-20', '2026-09-20');
        $snoozed = $this->job('Snoozed show', '2026-12-04', '2026-12-04');
        TodoDismissal::query()->create(['key' => 'invoice:'.$snoozed->id, 'until' => now()->addWeek()]);
        $dismissed = $this->job('Not mine', '2026-12-04', '2026-12-04');
        TodoDismissal::query()->create(['key' => 'invoice:'.$dismissed->id, 'until' => null]);

        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 0 finished jobs')->assertSuccessful();
        $this->assertSame([], $this->messages());
    }

    public function test_a_missed_morning_is_caught_up_and_a_job_marked_done_gets_its_invoice(): void
    {
        $job = $this->job('Acme Awards', '2026-12-02', '2026-12-02');
        Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);

        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertSame('Acme Awards: last day was Wed 2 Dec. All done? Marking it done gets its invoice ready to check.', $this->messages()[0]['text']);

        // Marked done (with its draft) before its morning: the message says its invoice is ready, with a link to it.
        $done = WorkJob::query()->create(['title' => 'Gala dinner', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-04', 'status' => 'done', 'done_at' => '2026-12-04 23:00:00']);
        $draft = Invoice::query()->create(['work_job_id' => $done->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events']);
        // Marked done with nothing drafted or billed: "Draft its invoice?".
        $offer = WorkJob::query()->create(['title' => 'Launch party', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-04', 'status' => 'done', 'done_at' => '2026-12-04 23:00:00']);
        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 2 finished jobs')->assertSuccessful();
        $byText = collect($this->messages())->keyBy('text');
        $ready = $byText['Gala dinner: its invoice is ready to check.'];
        $this->assertSame([['text' => 'Check the invoice', 'url' => url('/invoices/'.$draft->id)]], $ready['reply_markup']['inline_keyboard'][0]);
        $ask = $byText['Launch party is done. Draft its invoice?'];
        $this->assertSame(['Draft it', Telegram::callbackData('job_invoice', (string) $offer->id)], [$ask['reply_markup']['inline_keyboard'][0][0]['text'], $ask['reply_markup']['inline_keyboard'][0][0]['callback_data']]);
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
        $this->assertSame('Acme Awards: last day was yesterday. All done? Marking it done gets its invoice ready to check.'."\n".url('/jobs/'.$job->id), end($this->sent)['text']);
        $this->assertSame([$job->id.'@2026-12-04T23:59'], array_keys(JobFinishedNudges::told()));
    }

    public function test_three_jobs_on_one_morning_go_as_one_message_and_not_yet_asks_again_when_it_ends(): void
    {
        $jobs = collect(['Acme Awards', 'Gala dinner', 'Launch party'])->map(fn ($t) => $this->job($t, '2026-12-04', '2026-12-04'));

        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 3 finished jobs')->assertSuccessful();
        $this->assertCount(1, $this->messages(), 'Three or more on one morning: one message');
        $sent = $this->messages()[0];
        $this->assertStringStartsWith('3 jobs have finished. All done?', $sent['text']);
        $this->assertStringContainsString('• Gala dinner: last day was yesterday', $sent['text']);
        $rows = $sent['reply_markup']['inline_keyboard'];
        $this->assertSame($jobs->map(fn ($j) => Telegram::callbackData('job_done', (string) $j->id))->all(), array_map(fn ($r) => $r[0]['callback_data'], array_slice($rows, 0, 3)));
        $this->assertSame(url('/todo'), $rows[3][0]['url']);
        $this->assertCount(3, JobFinishedNudges::told());

        // "Not yet" on one: asked once more the morning its snooze ends.
        TodoDismissal::query()->create(['key' => 'job_done:'.$jobs[0]->id, 'until' => '2026-12-08 00:00:00', 'note' => 'Not finished yet']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-07 08:37:00', 'Europe/London')->utc());
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->messages(), 'Still snoozed');
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-08 08:37:00', 'Europe/London')->utc());
        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 1 finished job')->assertSuccessful();
        $this->assertSame('Acme Awards: last day was Fri 4 Dec. All done? Marking it done gets its invoice ready to check.', $this->messages()[1]['text']);
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-09 08:37:00', 'Europe/London')->utc());
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(2, $this->messages(), 'Once more, not every morning');
    }

    public function test_not_finished_yet_with_your_own_words_still_asks_again_when_it_ends(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $job = $this->job('Gala dinner', '2026-12-04', '2026-12-04');
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->messages());

        // The to-do page's snooze: 3 days, "Not finished yet", with a word of your own.
        $this->post('/todo/dismiss', ['key' => 'job_done:'.$job->id, 'days' => 3, 'reason' => 'not_finished', 'note' => 'One more day next week'])->assertSessionHasNoErrors();
        $this->assertSame('Not finished yet: One more day next week', TodoDismissal::query()->where('key', 'job_done:'.$job->id)->value('note'));

        foreach (['2026-12-06', '2026-12-07', '2026-12-08', '2026-12-09'] as $day) {
            Carbon::setTestNow(CarbonImmutable::parse($day.' 08:37:00', 'Europe/London')->utc());
            $this->artisan('ledger:job-finished')->assertSuccessful();
        }
        $this->assertCount(2, $this->messages(), 'Asked once more when the snooze ended, not every morning');
    }

    public function test_a_job_asked_about_before_finishes_were_kept_is_not_asked_again(): void
    {
        $job = $this->job('Acme Awards', '2026-12-03', '2026-12-04');
        Setting::put(JobFinishedNudges::TOLD, [(string) $job->id => '2026-12-05']);
        $this->artisan('ledger:job-finished')->expectsOutputToContain('Asked about 0 finished jobs')->assertSuccessful();
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

        $sent = $this->messages()[0];
        $this->assertSame('Acme Awards, Grosvenor House [postcode], PO [number]: last day was yesterday. All done? Marking it done gets its invoice ready to check.', $sent['text']);
        $this->assertSame(MorningReport::clean($sent['text']), $sent['text']);
        $this->assertSame(url('/jobs/'.$job->id), $sent['reply_markup']['inline_keyboard'][1][1]['url'], 'The link goes as a button');
    }

    public function test_the_next_morning_report_stays_quiet_after_the_message(): void
    {
        Setting::put('intel_cycle', 'on');
        $this->at('2026-12-05 08:37:00');
        $this->job('Acme Awards', '2026-12-04', '2026-12-04');
        $this->artisan('ledger:job-finished')->assertSuccessful();
        $this->assertCount(1, $this->messages());

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
        // S10: a booked job that has just finished asks "Is it done?" (job_done:), in place of "Invoice <job>".
        $finding = Finding::query()->where('key', 'job_done:'.$job->id)->sole();

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
