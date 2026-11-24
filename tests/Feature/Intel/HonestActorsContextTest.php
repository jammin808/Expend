<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobFinder;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\MerchantKey;
use App\Domain\Telegram\Telegram;
use App\Jobs\FindJobs;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\CalendarEvent;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Where each audit event came from is kept with it (M6): the channel (web, Telegram, the queue), a correlation shared
 * by an action and its knock-on writes, carried into the queued jobs it starts, and the run id. Older names for
 * actors are recorded as what they mean now, and automation reaching JobDays is never recorded as you.
 */
class HonestActorsContextTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(AuditLog::CONTEXT_SETTING, true); // recording where each event came from is turned on
        $this->seed();
        Setting::put('job_finder_auto', false);
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
    }

    private function tx(string $date, int $pence, string $description): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->starling->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $this->starling->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $description, 'merchant_key' => MerchantKey::from($description, $description), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1,
        ]);
    }

    public function test_the_context_inherits_what_it_doesnt_name_and_checks_what_it_does(): void
    {
        $outer = AuditContext::newCorrelation();
        $seen = AuditContext::with(null, fn () => AuditContext::with('matcher', fn () => AuditContext::current()), 'web', $outer, 12);

        $this->assertSame(['actor' => 'matcher', 'channel' => 'web', 'correlation' => $outer, 'run_id' => 12], $seen);
        $this->assertSame(26, strlen($outer));
        $this->assertTrue(AuditContext::isCorrelation($outer));
        $this->assertNull(AuditContext::current());

        $this->expectException(InvalidArgumentException::class);
        AuditContext::with(null, fn () => null, 'web', 'not-a-ulid');
    }

    public function test_each_web_request_is_one_action_from_the_web(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->tx('2025-06-10', -350, 'PRET A MANGER');
        $b = $this->tx('2025-06-11', -650, 'GREGGS');

        $this->post("/items/bank/{$a->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();
        $this->post("/items/bank/{$b->id}/decide", ['kind' => 'personal', 'business_pct' => 100])->assertSessionHasNoErrors();

        $first = AuditEvent::query()->where('subject_id', $a->id)->where('action', 'allocation.decided')->sole();
        $second = AuditEvent::query()->where('subject_id', $b->id)->where('action', 'allocation.decided')->sole();
        $this->assertSame(['you', 'web'], [$first->actor, $first->channel]);
        $this->assertTrue(AuditContext::isCorrelation((string) $first->correlation));
        $this->assertNotSame($first->correlation, $second->correlation, 'One correlation per request');
        $this->assertNull(AuditContext::current(), 'Nothing left behind after the request');
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_a_telegram_button_is_yours_from_telegram(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_webhook_secret', 'secret');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $tx = $this->tx('2025-07-31', -50000, 'HMRC SELF ASSESSMENT');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-50000, 'tax_payment'), 'matcher', false, 'Looks like a payment to HMRC (personal tax, not a business expense)');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'secret')->postJson('/hooks/telegram', ['callback_query' => [
            'id' => 'q1', 'data' => Telegram::callbackData('tx_accept', (string) $tx->id),
            'message' => ['message_id' => 7, 'date' => now()->getTimestamp(), 'chat' => ['id' => '42'], 'text' => 'To do'],
        ]])->assertOk();

        $confirmed = AuditEvent::query()->where('subject_id', $tx->id)->where('action', 'allocation.confirmed')->sole();
        $this->assertSame(['you', 'telegram'], [$confirmed->actor, $confirmed->channel]);
        $this->assertTrue(AuditContext::isCorrelation((string) $confirmed->correlation));
        $this->assertSame('you', $tx->fresh()->allocations()->value('confirmed_by'));
    }

    public function test_a_queued_job_carries_the_correlation_that_started_it(): void
    {
        config(['queue.default' => 'database']);
        $correlation = AuditContext::newCorrelation();
        // (A statement of its own: a pending dispatch is sent when it's let go of, which has to be inside the context.)
        AuditContext::with(null, function () {
            FindJobs::dispatch(null, true);
        }, 'web', $correlation);
        FindJobs::dispatch('2024-25', true); // started with no context: a correlation of its own
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--memory' => 0]);

        $runs = AuditEvent::query()->where('action', 'jobs.finder_run')->orderBy('id')->get();
        $this->assertCount(2, $runs);
        $this->assertSame(['matcher', 'queue', $correlation], [$runs[0]->actor, $runs[0]->channel, $runs[0]->correlation]);
        $this->assertSame(['matcher', 'queue'], [$runs[1]->actor, $runs[1]->channel]);
        $this->assertTrue(AuditContext::isCorrelation((string) $runs[1]->correlation));
        $this->assertNotSame($correlation, $runs[1]->correlation);
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_the_job_finders_links_and_job_days_name_the_matcher_not_rules_or_you(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14']);
        $event = CalendarEvent::query()->create(['uid' => 'acme@cal', 'starts_at' => '2025-05-14 08:00:00', 'ends_at' => '2025-05-14 18:00:00', 'all_day' => false, 'summary' => 'Acme Awards rig']);

        $link = app(JobFinder::class)->linkCalendar($event, $job, 'rules', 'On the job’s dates');
        $this->assertSame('rules', $link->linked_by, 'The link keeps its own record of who made it');
        $this->assertSame('matcher', AuditEvent::query()->where('action', 'calendar.linked')->sole()->actor);

        // A day added by automation is the matcher's; one you add is yours.
        AuditContext::with('matcher', fn () => JobDays::add($job, '2025-05-15', 'derig', null, null, $event->key(), 'calendar'));
        JobDays::add($job, '2025-05-16', 'work');
        $added = AuditEvent::query()->where('action', 'job.day_added')->orderBy('id')->pluck('actor')->all();
        $this->assertSame(['matcher', 'you'], $added);
        $this->assertSame(0, AuditEvent::query()->where('actor', 'rules')->count());
    }
}
