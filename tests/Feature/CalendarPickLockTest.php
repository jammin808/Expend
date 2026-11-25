<?php

namespace Tests\Feature;

use App\Domain\Intel\AuditContext;
use App\Models\AuditEvent;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\CalendarPick;
use App\Models\Client;
use App\Models\JobDay;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two saves at once, for real, on MariaDB (two connections): a pick waits for the audit head another writer holds and,
 * when it can't have it, says so and writes nothing; the same pick saved by another request meanwhile goes to that
 * job. Each runs without the test's own transaction (so the pick's transaction is the outermost, as in use) and
 * removes what it wrote afterwards. Skipped on SQLite, which has one writer anyway.
 */
class CalendarPickLockTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['calendar_picks', 'calendar_job_links', 'job_finds', 'link_provenance', 'job_days', 'work_jobs', 'calendar_events', 'clients', 'users', 'audit_events'];

    /** @var array<string, int> each table's last id before the test */
    private array $start = [];

    private ?object $head = null;

    /** No wrapping transaction: what the pick commits or rolls back is what really happens. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MariaDB: two connections to the same database.');
        }
        Queue::fake();
        foreach (self::TABLES as $t) {
            $this->start[$t] = (int) DB::table($t)->max('id');
        }
        $this->head = DB::table('audit_heads')->where('id', 1)->first();
        config(['database.connections.other' => config('database.connections.'.DB::getDefaultConnection())]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        if ($this->start !== []) {
            CalendarPick::flushEventListeners();
            $other = DB::connection('other');
            while ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
            DB::purge('other');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            Schema::withoutForeignKeyConstraints(function () {
                foreach (self::TABLES as $t) {
                    DB::table($t)->where('id', '>', $this->start[$t])->delete();
                }
            });
            DB::table('audit_heads')->where('id', 1)->update(['hash' => $this->head?->hash, 'event_id' => $this->head?->event_id]);
        }
        parent::tearDown();
    }

    private function payload(CalendarEvent $e, string $token): array
    {
        return ['pick' => $token, 'title' => 'Winter Expo', 'status' => 'done',
            'days' => [['date' => '2025-11-03', 'kind' => 'rig', 'start_time' => '08:00', 'end_time' => '12:00']],
            'entries' => [['key' => $e->key(), 'date' => '2025-11-03', 'on' => '2025-11-03', 'seen' => 'free', 'take' => false]], 'new_client' => 'Acme Events'];
    }

    /** @return list<int> */
    private function counts(): array
    {
        return [WorkJob::query()->count(), JobDay::query()->count(), CalendarJobLink::query()->count(), Client::query()->count(), AuditEvent::query()->count(), CalendarPick::query()->count()];
    }

    public function test_a_pick_that_cannot_have_the_audit_head_says_so_and_writes_nothing(): void
    {
        $e = CalendarEvent::query()->create(['uid' => 'rig-lock@cal', 'starts_at' => '2025-11-03 08:00:00', 'ends_at' => '2025-11-03 12:00:00', 'all_day' => false, 'summary' => 'Rig: Winter Expo', 'location' => 'Hall']);
        $counts = $this->counts();
        $token = AuditContext::newCorrelation();

        // Another writer holds the head (a long save elsewhere) for longer than the pick waits.
        $other = DB::connection('other');
        $other->beginTransaction();
        $other->table('audit_heads')->where('id', 1)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $this->post('/calendar/pick', $this->payload($e, $token))->assertSessionHas('error', 'Still saving your last tap. Look at the job in a moment.');
        $this->assertSame($counts, $this->counts(), 'Nothing written: not the job, its days, the link, the client, an audit event or the token');

        // Once the other writer is done, the same pick saves.
        $other->rollBack();
        $this->post('/calendar/pick', $this->payload($e, $token))->assertSessionHas('success');
        $job = WorkJob::query()->where('id', '>', $this->start['work_jobs'])->sole();
        $this->assertSame([$job->id, 'you'], [CalendarJobLink::query()->where('event_key', $e->key())->value('work_job_id'), CalendarJobLink::query()->where('event_key', $e->key())->value('linked_by')]);
        $this->assertSame($job->id, CalendarPick::query()->where('token', $token)->value('work_job_id'));
    }

    public function test_adding_to_a_job_locks_nothing_of_the_job_before_the_audit_head(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'status' => 'booked']);
        $token = AuditContext::newCorrelation();
        // Another writer that holds the audit head and then changes the job (a search, a merge) must be able to: the
        // pick's token row, written before the head, mustn't hold the job's row (its foreign key would share-lock it).
        $outcome = null;
        CalendarPick::created(function () use ($job, &$outcome) {
            $other = DB::connection('other');
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $other->table('work_jobs')->where('id', $job->id)->update(['notes' => 'Changed by another save']);
                $outcome = 'changed';
            } catch (QueryException) {
                $outcome = 'waited for the pick';
            }
        });

        $this->post("/jobs/{$job->id}/days/pick", ['pick' => $token, 'days' => [['date' => '2025-11-05', 'kind' => 'derig']]])->assertSessionHas('success');

        $this->assertSame('changed', $outcome);
        $this->assertSame(['2025-11-03', '2025-11-05'], $job->fresh()->workDates());
        $this->assertSame($job->id, CalendarPick::query()->where('token', $token)->value('work_job_id'), 'The job goes on the token row once it’s locked');
    }

    public function test_the_same_pick_saved_by_another_request_meanwhile_goes_to_its_job(): void
    {
        $e = CalendarEvent::query()->create(['uid' => 'rig-race@cal', 'starts_at' => '2025-11-03 08:00:00', 'ends_at' => '2025-11-03 12:00:00', 'all_day' => false, 'summary' => 'Rig: Winter Expo', 'location' => 'Hall']);
        $made = WorkJob::query()->create(['title' => 'Winter Expo', 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-03', 'status' => 'done']);
        $token = AuditContext::newCorrelation();
        // The first tap's request commits its pick just as this one starts its own (after this one found no row).
        CalendarPick::creating(function (CalendarPick $pick) use ($made) {
            DB::connection('other')->table('calendar_picks')->insert(['token' => $pick->token, 'mode' => 'create', 'work_job_id' => $made->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $counts = $this->counts();

        $this->post('/calendar/pick', $this->payload($e, $token))->assertRedirect('/jobs/'.$made->id)->assertSessionHas('success', 'Already saved: Winter Expo.');

        $this->assertSame([$counts[0], $counts[1], $counts[2], $counts[3], $counts[4], $counts[5] + 1], $this->counts(), 'Only the other request’s token row: no second job, client, link or audit event');
        $this->assertNull(CalendarJobLink::query()->where('event_key', $e->key())->first());
    }
}
