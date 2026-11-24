<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\DocumentReader;
use App\Domain\Intel\Cycle\AiAllowance;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Steps\AcquireRetryReads;
use App\Domain\Intel\Cycle\Steps\PreserveSeal;
use App\Domain\Intel\Cycle\Steps\RelateJobs;
use App\Domain\Jobs\JobFinder;
use App\Domain\Verification\Verifier;
use App\Jobs\FindJobs;
use App\Jobs\ReadDocument;
use App\Jobs\ReadTravelEmail;
use App\Jobs\RetryTravelRead;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * AI on a budget (docs/intelligence-cycle.md 6.2, M5): the monthly cap is shared out by purpose, and the assistant's
 * share is a reserve the cycle never uses; each purpose keeps to its share until the 20th; a run's allowance is a
 * ceiling; a read refused for want of budget waits (waiting_budget) and is queued again by the cycle when there's
 * room; the job finder asks the AI about 15 calendar entries at a time in the cycle and shares one lock.
 */
class CycleAiBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Setting::put('intel_cycle', 'on');
        Setting::put('ai_monthly_cap_usd', 10);
        Http::fake();
        $this->at('2026-11-10 03:10:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $london): void
    {
        $utc = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    /** Spend $usd this month on $purpose (output tokens at $20 a million). */
    private function spend(string $purpose, float $usd): void
    {
        AiUsage::withPurpose($purpose, fn () => AiUsage::record('claude-opus-5-5', 0, (int) round($usd * 50_000)));
    }

    private function refused(callable $call): bool
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            $this->assertSame(AiUsage::REFUSED, $e->getCode());
            $this->assertTrue(AiUsage::refused($e));

            return true;
        }

        return false;
    }

    /** An AI that goes through the month's budget as the real one does: each call reserves $0.20, then costs it. */
    private function meteredAi(array $responses = []): AiClient
    {
        return new class($responses) implements AiClient
        {
            public array $requests = [];

            public function __construct(public array $responses) {}

            public function send(array $params): AiResult
            {
                $reservation = AiUsage::guard(0.20);
                $this->requests[] = $params;
                AiUsage::record('claude-opus-5-5', 0, 10_000, 0, $reservation);

                return array_shift($this->responses) ?? FakeAiClient::json(['entries' => []]);
            }
        };
    }

    public function test_spend_is_kept_by_purpose_and_a_call_made_for_none_is_yours(): void
    {
        $this->spend('reading', 0.5);
        AiUsage::record('claude-opus-5-5', 0, 5_000); // the assistant: no purpose named
        $usage = AiUsage::thisMonth();
        $this->assertSame(['reading' => 0.5, 'assistant' => 0.1], $usage['by_purpose']);
        $this->assertSame(0.6, $usage['usd']);
        $this->assertSame(['assistant' => 3.0, 'reading' => 4.0, 'verify' => 1.0, 'discovery' => 2.0], AiUsage::shares());
    }

    public function test_the_cycle_never_uses_the_assistants_share(): void
    {
        $this->spend('reading', 4.0);
        $this->spend('verify', 1.0);
        $this->spend('discovery', 2.0);
        $this->assertSame(7.0, AiUsage::cycleSpent());

        // The cycle's part (70%) is used up: none of its purposes may call, even after the 20th.
        $this->at('2026-11-25 03:10:00');
        foreach (['reading', 'verify', 'discovery'] as $purpose) {
            $this->assertTrue($this->refused(fn () => AiUsage::withPurpose($purpose, fn () => AiUsage::guard(0.01))), $purpose);
            $this->assertSame(0.0, AiUsage::available($purpose));
        }
        $this->assertSame(0, AiAllowance::forNight(), 'No allowance tonight');
        // Your own questions still have the reserve.
        $this->assertNotNull(AiUsage::guard(0.5));
        $this->assertEqualsWithDelta(2.5, AiUsage::available('assistant'), 0.0001);
    }

    public function test_each_purpose_keeps_to_its_share_until_the_20th_then_may_borrow_what_the_others_left(): void
    {
        $this->spend('verify', 1.0);
        $this->assertTrue($this->refused(fn () => AiUsage::withPurpose('verify', fn () => AiUsage::guard(0.05))), 'Its share of $1 is used');
        $this->assertNotNull(AiUsage::withPurpose('reading', fn () => AiUsage::guard(0.05)), 'Another share is untouched');

        $this->at('2026-11-21 03:10:00');
        $this->assertNotNull(AiUsage::withPurpose('verify', fn () => AiUsage::guard(0.05)), 'From the 21st, what reading and discovery haven’t used');
        $this->assertEqualsWithDelta(5.95, AiUsage::available('verify'), 0.0001, '$7 for the cycle, less $1 spent and $0.05 set aside');
    }

    public function test_your_own_questions_may_use_what_the_cycle_hasnt(): void
    {
        $this->spend('assistant', 5.0);
        $this->assertNotNull(AiUsage::guard(1.0), 'Past the assistant’s $3 share: it’s you asking, and the month has room');
        $this->assertEqualsWithDelta(4.0, AiUsage::available('reading'), 0.0001, 'The cycle keeps to what the cap has left: $10 less $5 spent and $1 set aside');
        $this->spend('assistant', 3.5);
        $this->assertEqualsWithDelta(0.5, AiUsage::available('discovery'), 0.0001);
    }

    public function test_the_nightly_allowance_spreads_the_cycles_part_over_the_days_left(): void
    {
        Setting::put('ai_monthly_cap_usd', 40);
        $this->at('2026-11-24 03:10:00'); // 7 days left, counting today
        $this->assertSame(1_500_000, AiAllowance::forNight(), 'The nightly ceiling, $1.50');
        $this->spend('reading', 25.0);
        $this->assertSame(428_571, AiAllowance::forNight(), '($28 − $25) ÷ 7');
        Setting::put('intel_ai_night_usd', 0.25);
        $this->assertSame(250_000, AiAllowance::forNight());
    }

    public function test_a_runs_allowance_is_a_ceiling_on_its_calls(): void
    {
        $ai = new AiAllowance(300_000);
        $first = $ai->within('discovery', fn () => AiUsage::record('claude-opus-5-5', 0, 10_000, 0, AiUsage::guard(0.2)) ?? 'called');
        $this->assertSame(200_000, $ai->spentMicro(), 'What it really cost: 10,000 tokens at $20 a million');
        $this->assertSame('called', $first);
        $this->assertFalse($ai->refused());
        $this->assertTrue($this->refused(fn () => $ai->within('discovery', fn () => AiUsage::guard(0.2))), 'A second $0.20 call would pass $0.30');
        $this->assertTrue($ai->refused());
        $this->assertTrue($ai->allow(0.05, 'reading'), 'Smaller work still fits');
        $this->assertFalse($ai->allow(0.10, 'reading'));
    }

    public function test_a_read_refused_for_want_of_budget_waits_instead_of_failing(): void
    {
        $this->app->instance(AiClient::class, $this->meteredAi());
        Setting::put('anthropic_api_key', 'test-key');
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'po.txt', 'mime' => 'text/plain', 'size' => 20, 'sha256' => str_repeat('c', 64),
            'stored_path' => 'documents/po.txt', 'text' => 'Purchase order PO-1 for Acme Events', 'text_status' => 'extracted', 'status' => 'new']);
        $this->spend('reading', 4.0);

        (new ReadDocument($doc->id))->handle(app(DocumentReader::class), app(Verifier::class));

        $doc = $doc->fresh();
        $this->assertSame(ReadDocument::WAITING, $doc->status);
        $this->assertStringContainsString('reading documents', $doc->error);
    }

    public function test_while_the_cycle_is_off_or_on_trial_a_read_may_use_what_the_month_has_left_as_before(): void
    {
        $this->app->instance(AiClient::class, $this->meteredAi([FakeAiClient::json([]), FakeAiClient::json([])]));
        Setting::put('anthropic_api_key', 'test-key');
        $this->spend('reading', 4.0); // reading's whole share: only the cycle (on) reads again what's held back
        foreach (['off', 'shadow'] as $i => $mode) {
            Setting::put('intel_cycle', $mode);
            $this->assertEqualsWithDelta(6.0 - 0.2 * $i, AiUsage::available('reading'), 0.0001, "No shares while the cycle is {$mode}");
            $doc = Document::query()->create(['source' => 'upload', 'original_name' => "po{$i}.txt", 'mime' => 'text/plain', 'size' => 20, 'sha256' => str_repeat((string) $i, 64),
                'stored_path' => "documents/po{$i}.txt", 'text' => 'Purchase order PO-1 for Acme Events', 'text_status' => 'extracted', 'status' => 'new']);
            (new ReadDocument($doc->id))->handle(app(DocumentReader::class), app(Verifier::class));
            $this->assertNotContains($doc->fresh()->status, [ReadDocument::WAITING, 'failed'], "Read while the cycle is {$mode}");
        }

        // Refused by the month's cap itself while the cycle is off: failed, as before, where Read with AI offers it again.
        Setting::put('intel_cycle', 'off');
        $this->spend('assistant', 5.6);
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'po9.txt', 'mime' => 'text/plain', 'size' => 20, 'sha256' => str_repeat('9', 64),
            'stored_path' => 'documents/po9.txt', 'text' => 'Purchase order PO-9 for Acme Events', 'text_status' => 'extracted', 'status' => 'new']);
        (new ReadDocument($doc->id))->handle(app(DocumentReader::class), app(Verifier::class));
        $this->assertSame('failed', $doc->fresh()->status, 'Nothing reads it again on its own while the cycle is off');
        $this->assertStringContainsString('spending cap', $doc->fresh()->error);
    }

    public function test_spend_from_before_purposes_were_kept_counts_against_the_cycles_part(): void
    {
        // The month this was released: $34 of $40 spent, with no purposes kept.
        Setting::put('ai_monthly_cap_usd', 40);
        $this->at('2026-11-25 03:10:00');
        Setting::put('ai_usage_2026_11', ['input' => 0, 'output' => 1_700_000, 'usd' => 34.0, 'calls' => 120]);

        $this->assertSame(0.0, AiUsage::available('reading'), 'The cycle’s $28 is taken to be spent: the $6 left is the assistant’s reserve');
        $this->assertSame(0, AiAllowance::forNight(), 'No allowance tonight');
        $this->assertTrue($this->refused(fn () => AiUsage::withPurpose('reading', fn () => AiUsage::guard(0.05))));
        $this->assertEqualsWithDelta(6.0, AiUsage::available('assistant'), 0.0001, 'Your questions still have what’s left');
        $this->assertSame(34.0, AiUsage::cycleSpent());
    }

    public function test_booking_emails_read_again_by_the_cycle_are_paid_from_reading_never_your_share(): void
    {
        Storage::fake('local');
        Setting::put('anthropic_api_key', 'test-key');
        $this->app->instance(AiClient::class, $this->meteredAi(array_fill(0, 6, FakeAiClient::json(['items' => []]))));
        $this->spend('reading', 3.8); // $0.20 of reading's $4 left: room for one read
        foreach (range(1, 6) as $i) {
            Storage::disk('local')->put("mail/{$i}.eml", "From: bookings@hotel.example\r\nSubject: Your booking\r\n\r\nYour booking reference ABC{$i}. Check-in 14 Nov 2026.");
            $doc = Document::query()->create(['source' => 'gmail', 'original_name' => "{$i}.eml", 'mime' => 'message/rfc822', 'size' => 10, 'sha256' => str_repeat((string) $i, 64),
                'stored_path' => "mail/{$i}.eml", 'text' => "Your booking reference ABC{$i}. Check-in 14 Nov 2026.", 'text_status' => 'extracted', 'status' => 'extracted',
                'email_from' => 'bookings@hotel.example']);
            GmailMessage::query()->create(['message_id' => "m{$i}", 'search' => 'hotels', 'document_id' => $doc->id, 'status' => 'failed',
                'error' => 'This month’s AI spending cap ($10) is nearly reached: this request could go over it.']);
        }
        $runner = new CycleRunner(plans: ['nightly' => [AcquireRetryReads::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        $runner->work($run->id); // the queue runs at once in tests, as the worker would later

        $step = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', AcquireRetryReads::KEY)->sole();
        $this->assertSame(1, $step->counts['queued']);
        $by = AiUsage::thisMonth()['by_purpose'];
        $this->assertArrayNotHasKey('assistant', $by, 'Nothing the cycle started is counted as yours');
        $this->assertEqualsWithDelta(4.0, $by['reading'], 0.0001);
    }

    public function test_a_booking_email_read_when_found_or_by_read_again_is_paid_from_reading(): void
    {
        Storage::fake('local');
        Setting::put('anthropic_api_key', 'test-key');
        $this->app->instance(AiClient::class, $this->meteredAi([FakeAiClient::json(['items' => []])]));
        Storage::disk('local')->put('mail/1.eml', "From: bookings@hotel.example\r\nSubject: Your booking\r\n\r\nYour booking reference ABC1. Check-in 14 Nov 2026.");
        $doc = Document::query()->create(['source' => 'gmail', 'original_name' => '1.eml', 'mime' => 'message/rfc822', 'size' => 10, 'sha256' => str_repeat('1', 64),
            'stored_path' => 'mail/1.eml', 'text' => 'Your booking reference ABC1. Check-in 14 Nov 2026.', 'text_status' => 'extracted', 'status' => 'extracted',
            'email_from' => 'bookings@hotel.example']);
        $email = GmailMessage::query()->create(['message_id' => 'm1', 'search' => 'hotels', 'document_id' => $doc->id, 'status' => 'stored']);

        // As Gmail's search, or Read again on the travel page, queues it: not the cycle, and not one of your questions.
        app()->call([new ReadTravelEmail($email->id), 'handle']);

        $this->assertSame('no_booking', $email->fresh()->status);
        $this->assertSame(['reading' => 0.2], AiUsage::thisMonth()['by_purpose'], 'Counted as reading, never against your share');
    }

    public function test_reads_that_waited_are_queued_again_within_the_nights_allowance(): void
    {
        Bus::fake([ReadDocument::class, RetryTravelRead::class]);
        Setting::put('intel_ai_night_usd', 0.30); // two reads at about $0.15
        $docs = collect(range(1, 3))->map(fn ($i) => Document::query()->create(['source' => 'upload', 'original_name' => "po{$i}.pdf", 'mime' => 'application/pdf', 'size' => 10,
            'sha256' => str_repeat((string) $i, 64), 'stored_path' => "documents/po{$i}.pdf", 'status' => ReadDocument::WAITING, 'error' => 'This month’s AI spending cap has no room left']));
        $runner = new CycleRunner(plans: ['nightly' => [AcquireRetryReads::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        $runner->work($run->id);

        Bus::assertDispatchedTimes(ReadDocument::class, 2);
        $step = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', AcquireRetryReads::KEY)->sole();
        $this->assertSame(['budget', 2, 1], [$step->status, $step->counts['queued'], $step->counts['waiting']], 'Not a failure: the last one waits for tomorrow');
        $this->assertSame(['queued', 'queued', ReadDocument::WAITING], $docs->map(fn ($d) => $d->fresh()->status)->all());
        $this->assertSame(300_000, $run->fresh()->ai_spent_micro);

        // A booking email that failed on the cap is read again too.
        $email = GmailMessage::query()->create(['message_id' => 'm1', 'search' => 'travel', 'document_id' => $docs[2]->id, 'status' => 'failed', 'error' => 'This month’s AI spending cap ($10) is reached.']);
        $docs[2]->update(['status' => 'extracted']);
        $this->at('2026-11-11 03:10:00');
        $next = $runner->start('nightly');
        $runner->work($next->id);
        Bus::assertDispatched(RetryTravelRead::class, fn (RetryTravelRead $j) => $j->gmailMessageId === $email->id);
        $this->assertSame('stored', $email->fresh()->status);
    }

    public function test_the_job_finder_asks_about_fifteen_entries_at_a_time_in_the_cycle(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        $ai = new FakeAiClient([
            FakeAiClient::json(['entries' => array_map(fn ($i) => ['i' => $i, 'work' => false, 'quote' => null, 'client' => null], range(0, 14))]),
            FakeAiClient::json(['entries' => array_map(fn ($i) => ['i' => $i, 'work' => false, 'quote' => null, 'client' => null], range(0, 4))]),
        ]);
        $this->app->instance(AiClient::class, $ai);
        foreach (range(1, 20) as $i) {
            CalendarEvent::query()->create(['uid' => "e{$i}@cal", 'starts_at' => sprintf('2026-10-%02d 09:00:00', $i), 'ends_at' => sprintf('2026-10-%02d 11:00:00', $i),
                'all_day' => false, 'summary' => "Maria @ Unit {$i}", 'location' => 'Salford']);
        }
        $runner = new CycleRunner(plans: ['nightly' => [RelateJobs::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        $step = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', RelateJobs::KEY)->sole();

        $runner->work($run->id);
        $this->assertCount(1, $ai->requests);
        $this->assertSame(15, substr_count((string) $ai->requests[0]['messages'][0]['content'], '"i":'), 'At most 15 entries in one go');
        $this->assertSame(['more', 5], [$step()->status, $step()->counts['ai_left']]);

        $this->assertSame(0, AuditEvent::query()->where('action', 'jobs.finder_run')->count(), 'Asking links nothing and isn’t a run of the job finder');

        $runner->work($run->id);
        $this->assertCount(2, $ai->requests);
        $this->assertSame(['ok', 0, 20], [$step()->status, $step()->counts['ai_left'], $step()->counts['ai_checked']]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'jobs.finder_run')->count(), 'Then one run, with every answer');
        $this->assertSame(JobFinder::AI_LIMIT, 120, 'ledger:find-jobs still asks about up to 120 at once');
    }

    public function test_the_job_finder_stops_at_the_nights_allowance_with_budget(): void
    {
        Setting::put('anthropic_api_key', 'test-key');
        Setting::put('intel_ai_night_usd', 0.30);
        $ai = $this->meteredAi([FakeAiClient::json(['entries' => array_map(fn ($i) => ['i' => $i, 'work' => false, 'quote' => null, 'client' => null], range(0, 14))])]);
        $this->app->instance(AiClient::class, $ai);
        foreach (range(1, 20) as $i) {
            CalendarEvent::query()->create(['uid' => "e{$i}@cal", 'starts_at' => sprintf('2026-10-%02d 09:00:00', $i), 'ends_at' => sprintf('2026-10-%02d 11:00:00', $i),
                'all_day' => false, 'summary' => "Maria @ Unit {$i}", 'location' => 'Salford']);
        }
        $runner = new CycleRunner(plans: ['nightly' => [RelateJobs::KEY, PreserveSeal::KEY]]);
        $this->app->instance(CycleRunner::class, $runner);
        $run = $runner->start('nightly');
        for ($i = 0; $i < 5 && ! $run->fresh()->isFinished(); $i++) {
            $runner->work($run->id);
        }

        $step = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', RelateJobs::KEY)->sole();
        $this->assertSame(['budget', 5], [$step->status, $step->counts['ai_left']], 'The second $0.20 call would pass $0.30: the rest waits for tomorrow');
        $this->assertCount(1, $ai->requests);
        $this->assertSame('ok', $run->fresh()->status, 'Running out of AI budget isn’t a failure');
        $this->assertSame(['discovery' => 0.2], AiUsage::thisMonth()['by_purpose']);
    }

    public function test_one_run_of_the_job_finder_at_a_time(): void
    {
        $lock = Cache::lock(JobFinder::LOCK, 60);
        $this->assertTrue($lock->get());

        // The cycle's step waits for the next tick.
        $outcome = app(RelateJobs::class)->run(new CycleContext(runId: null));
        $this->assertSame('more', $outcome->status);
        // The queued job leaves it to the run under way.
        (new FindJobs(null, true))->handle(app(JobFinder::class), app(Verifier::class));
        $this->assertNull(Setting::get('job_finder_last_run'));

        $lock->release();
        (new FindJobs(null, true))->handle(app(JobFinder::class), app(Verifier::class));
        $this->assertNotNull(Setting::get('job_finder_last_run'));
    }
}
