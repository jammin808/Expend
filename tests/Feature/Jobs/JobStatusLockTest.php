<?php

namespace Tests\Feature\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\JobStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\JobDay;
use App\Models\WorkJob;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Build slice S4, for real on MariaDB (two connections): Done, back to booked and Cancelled take the audit head before
 * the job's row (the lock order every audited writer uses, as the calendar picker does), and Done's draft is made
 * under both. Each runs without the test's own transaction, so JobStatus's transaction is the outermost, as in use,
 * and removes what it wrote afterwards. Skipped on SQLite, which has one writer anyway.
 */
class JobStatusLockTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['invoice_lines', 'invoices', 'job_days', 'work_jobs', 'clients', 'audit_events'];

    /** @var array<string, int> each table's last id before the test */
    private array $start = [];

    private ?object $head = null;

    /** No wrapping transaction: what JobStatus commits or rolls back is what really happens. */
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
        DB::transaction(fn () => AuditLog::lockHead()); // the head row is there, as it is once anything was audited
        $this->head = DB::table('audit_heads')->where('id', 1)->first();
        config(['database.connections.other' => config('database.connections.'.DB::getDefaultConnection())]);
    }

    protected function tearDown(): void
    {
        if ($this->start !== []) {
            $other = DB::connection('other');
            while ($other->transactionLevel() > 0) {
                $other->rollBack();
            }
            DB::purge('other');
            Schema::withoutForeignKeyConstraints(function () {
                foreach (self::TABLES as $t) {
                    DB::table($t)->where('id', '>', $this->start[$t])->delete();
                }
            });
            DB::table('audit_heads')->where('id', 1)->update(['hash' => $this->head?->hash, 'event_id' => $this->head?->event_id]);
        }
        parent::tearDown();
    }

    /**
     * When the job's row is first locked, whether another connection could take the audit head then: it can't when
     * the head was taken first (it waits, then gives up after a second).
     *
     * @return \Closure(): ?string what the other connection found
     */
    private function watchJobLock(WorkJob $job): \Closure
    {
        $found = null;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$found) {
            if ($found !== null || $q->connectionName === 'other' || ! str_contains($q->sql, 'for update') || ! str_contains($q->sql, '`work_jobs`')) {
                return;
            }
            $other = DB::connection('other');
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $other->beginTransaction();
            try {
                $other->table('audit_heads')->where('id', 1)->lockForUpdate()->first();
                $found = 'head was free';
            } catch (QueryException) {
                $found = 'head was held';
            } finally {
                $other->rollBack();
            }
        });

        return function () use (&$found) {
            return $found;
        };
    }

    public function test_done_takes_the_audit_head_before_the_job_and_makes_its_draft_under_both(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'fee_pence' => 65000, 'status' => 'booked']);
        $seen = $this->watchJobLock($job);

        $r = app(JobStatus::class)->markDone($job);

        $this->assertSame('head was held', $seen(), 'The audit head was locked before the job’s row');
        $this->assertSame(['done', 'made'], [$job->fresh()->status, $r['draft']]);
        $this->assertSame(1, Invoice::query()->where('work_job_id', $job->id)->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'job.done')->where('subject_id', $job->id)->count());
    }

    public function test_back_to_booked_and_cancelled_take_the_audit_head_first_too(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'status' => 'done']);
        $seen = $this->watchJobLock($job);
        app(JobStatus::class)->reopen($job);
        $this->assertSame('head was held', $seen());

        $job2 = WorkJob::query()->create(['title' => 'Arena gala', 'starts_on' => '2026-06-04', 'ends_on' => '2026-06-04', 'status' => 'booked']);
        $seen = $this->watchJobLock($job2);
        app(JobStatus::class)->cancel($job2);
        $this->assertSame('head was held', $seen());
        $this->assertSame(['booked', 'cancelled'], [$job->fresh()->status, $job2->fresh()->status]);
    }

    public function test_a_done_that_cannot_have_the_audit_head_writes_nothing(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'fee_pence' => 65000, 'status' => 'booked']);
        $events = AuditEvent::query()->count();
        $other = DB::connection('other');
        $other->beginTransaction();
        $other->table('audit_heads')->where('id', 1)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(JobStatus::class)->markDone($job);
            $this->fail('Marked done without the audit head');
        } catch (QueryException) {
            // it waited for the head, then gave up
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            $other->rollBack();
        }

        $this->assertSame(['booked', null], [$job->fresh()->status, $job->fresh()->done_at]);
        $this->assertSame([0, $events], [Invoice::query()->where('work_job_id', $job->id)->count(), AuditEvent::query()->count()]);
        $this->assertSame(1, JobDay::query()->where('work_job_id', $job->id)->count());
    }
}
