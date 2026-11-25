<?php

namespace Tests\Feature\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobStatus;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\WorkJob;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * Done, a draft's sync, a cost decided on a job and a new job, for real on MariaDB (two processes, their own
 * connections): every audited writer takes the audit head before any row, so one waits for the other and neither
 * fails. AllocationWriter and JobCreator write rows with foreign keys to a job or client (which lock that row in share
 * mode until commit) only after taking the head; JobStatus and InvoiceDraftSync take the head, then the job or client.
 *
 * The other process pauses right after its insert (a query listener in that process only), so the interleaving is
 * the same every run. Skipped on SQLite, which has one writer anyway.
 */
class WritersLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = ['allocations', 'bank_transactions', 'import_batches', 'accounts', 'invoice_lines', 'invoices', 'job_days', 'work_jobs', 'clients', 'audit_events'];

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
            $this->markTestSkipped('Needs MariaDB: two processes on the same database.');
        }
        Queue::fake();
        foreach (self::TABLES as $t) {
            $this->start[$t] = (int) DB::table($t)->max('id');
        }
        DB::transaction(fn () => AuditLog::lockHead());
        $this->head = DB::table('audit_heads')->where('id', 1)->first();
    }

    protected function tearDown(): void
    {
        if ($this->start !== []) {
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
     * Start the other process: it runs $code (PHP, with the app booted) and pauses 3 seconds right after the first
     * query starting with $pauseAfter, having printed HOLDING. Returns [process, stdout].
     *
     * @return array{0: resource, 1: resource}
     */
    private function other(string $pauseAfter, string $code): array
    {
        $script = sys_get_temp_dir().'/expend-lock-order-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($script, '<?php
require '.var_export(base_path('vendor/autoload.php'), true).';
$app = require '.var_export(base_path('bootstrap/app.php'), true).';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$held = false;
Illuminate\Support\Facades\DB::listen(function ($q) use (&$held) {
    if (! $held && str_starts_with($q->sql, '.var_export($pauseAfter, true).')) {
        $held = true;
        fwrite(STDOUT, "HOLDING\n");
        fflush(STDOUT);
        sleep(3);
    }
});
try {
'.$code.'
    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDOUT, "ERR ".get_class($e).": ".substr($e->getMessage(), 0, 200)."\n");
}
');
        $env = getenv();
        $proc = proc_open([PHP_BINARY, $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 30);
        $first = fgets($pipes[1]);
        if ($first !== "HOLDING\n") {
            $this->fail('The other process didn’t get to its insert: '.var_export($first, true).' '.stream_get_contents($pipes[1]).' '.stream_get_contents($pipes[2]));
        }

        return [$proc, $pipes[1]];
    }

    private function finish(array $other): string
    {
        [$proc, $out] = $other;
        $rest = stream_get_contents($out);
        proc_close($proc);

        return trim((string) $rest);
    }

    /**
     * A cost decided on the job (AllocationWriter::decide: the payment's lines, then the audit) while you press Done on
     * that job. decide's insert into allocations locks the job row in share mode (foreign key), then it waits for the
     * audit head; Done holds the head and waits for the job row.
     */
    public function test_done_and_a_cost_decided_on_the_same_job_at_the_same_moment_do_not_deadlock(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'fee_pence' => 65000, 'status' => 'booked']);
        $account = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->create(['account_id' => $account->id, 'original_filename' => 'june.csv', 'file_sha256' => str_repeat('d', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-02', 'tax_year' => '2026-27',
            'description' => 'TRAINLINE', 'counterparty' => 'TRAINLINE', 'merchant_key' => 'TRAINLINE', 'amount_pence' => -4500, 'raw' => [], 'fingerprint' => 'lock-order-'.uniqid()]);
        $rail = (int) Category::query()->where('code', 'travel_rail')->value('id');

        $other = $this->other('insert into `allocations`', '
    app(App\Domain\Ledger\AllocationWriter::class)->decide(App\Models\BankTransaction::findOrFail('.$tx->id.'), [
        ["kind" => "business_expense", "category_id" => '.$rail.', "work_job_id" => '.$job->id.', "amount_pence" => -4500, "business_use_pct" => 100],
    ]);');

        $mine = 'OK';
        try {
            app(JobStatus::class)->markDone($job);
        } catch (Throwable $e) {
            $mine = 'ERR '.substr($e->getMessage(), 0, 110).($e instanceof QueryException ? ' | SQL: '.$e->getSql() : '');
        }
        $theirs = $this->finish($other);

        $this->assertSame(['OK', 'OK'], [$mine, $theirs], "Done: {$mine}\nThe cost decision: {$theirs}");
    }

    /**
     * A new job added for Acme (JobCreator::create: the job row, its days, then the audit) while a draft of another
     * Acme job is brought up to date (a page view, the nightly sync, Done's draft). create's insert into work_jobs
     * locks Acme's client row in share mode (foreign key), then it waits for the audit head; the sync holds the head
     * and waits for Acme's row (InvoiceDraftSync::lockOwner).
     */
    public function test_a_draft_sync_and_a_new_job_for_the_same_client_do_not_deadlock(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-03', 'fee_pence' => 65000, 'status' => 'done']);
        $draft = app(InvoiceService::class)->draftForJob($job);
        DB::table('work_jobs')->where('id', $job->id)->update(['fee_pence' => 70000]); // something for the sync to bring in

        $other = $this->other('insert into `work_jobs`', '
    app(App\Domain\Jobs\JobCreator::class)->create(["title" => "Arena gala", "client_id" => '.$client->id.', "starts_on" => "2026-06-08", "ends_on" => "2026-06-08"], "you", [], null, false);');

        $mine = 'OK';
        try {
            app(InvoiceDraftSync::class)->sync(Invoice::query()->findOrFail($draft->id));
        } catch (Throwable $e) {
            $mine = 'ERR '.substr($e->getMessage(), 0, 110).($e instanceof QueryException ? ' | SQL: '.$e->getSql() : '');
        }
        $theirs = $this->finish($other);

        $this->assertSame(['OK', 'OK'], [$mine, $theirs], "The draft sync: {$mine}\nThe new job: {$theirs}");
    }
}
