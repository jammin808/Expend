<?php

namespace Tests\Feature\Invoices;

use App\Domain\Audit\AuditLog;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Models\Client;
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
 * Build slice S6, for real on MariaDB (two connections): draftForJob takes the audit head before the job's row; the
 * draft sync takes the head before the paying client's row and the invoice when it has something to write, and no
 * lock at all when it hasn't (it runs on every view of a draft). Each runs without the test's own transaction, as in
 * use, and removes what it wrote afterwards. Skipped on SQLite, which has one writer anyway.
 */
class DraftSyncLockTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['invoice_lines', 'invoices', 'job_days', 'work_jobs', 'clients', 'audit_events'];

    /** @var array<string, int> */
    private array $start = [];

    private ?object $head = null;

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
        DB::transaction(fn () => AuditLog::lockHead());
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
     * When a row of $table is first locked, whether another connection could take the audit head then.
     *
     * @return \Closure(): ?string
     */
    private function watch(string $table): \Closure
    {
        $found = null;
        Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$found, $table) {
            if ($found !== null || $q->connectionName === 'other' || ! str_contains($q->sql, 'for update') || ! str_contains($q->sql, '`'.$table.'`')) {
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

    public function test_draft_for_job_and_the_sync_take_the_audit_head_first(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'fee_pence' => 65000,
            'status' => 'done']);

        $jobLock = $this->watch('work_jobs');
        $clientLock = $this->watch('clients');
        $draft = app(InvoiceService::class)->draftForJob($job);
        $this->assertSame(['head was held', 'head was held'], [$jobLock(), $clientLock()], 'The head before the job, and before the client');
        $this->assertSame(65000, $draft->fresh()->total_minor);

        // A change to write: the head before the invoice's row.
        $job->update(['fee_pence' => 70000]);
        $invoiceLock = $this->watch('invoices');
        $r = app(InvoiceDraftSync::class)->sync($draft);
        $this->assertSame([1, 'head was held'], [$r['changed'], $invoiceLock()]);
    }

    public function test_a_sync_with_nothing_to_write_takes_no_lock(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'fee_pence' => 65000, 'status' => 'done']);
        $draft = app(InvoiceService::class)->draftForJob($job);

        // Another writer holds the audit head: a sync that locked anything would wait for it, then give up.
        $other = DB::connection('other');
        $other->beginTransaction();
        $other->table('audit_heads')->where('id', 1)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $r = app(InvoiceDraftSync::class)->sync($draft);
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            $other->rollBack();
        }
        $this->assertSame([0, false], [$r['changed'], $r['wrote']]);
    }
}
