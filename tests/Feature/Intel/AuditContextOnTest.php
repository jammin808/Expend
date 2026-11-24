<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Audit context on (M7): intel_audit_context defaults to on, so every event written from the web, Telegram, the queue
 * or a cycle run records its channel, correlation and run id (hash v2). A chain written before (v1, as M6 wrote it) and
 * carried on after (v2) verifies as one: by AuditLog::verify(), and by deploy/restore-check.sh on a restored MariaDB copy.
 */
class AuditContextOnTest extends TestCase
{
    use RefreshDatabase;

    private ?string $dir = null;

    /** @var list<string> */
    private array $databases = [];

    protected function tearDown(): void
    {
        if ($this->databases !== []) {
            DB::setDefaultConnection('mysql');
            foreach ($this->databases as $db) {
                rescue(fn () => DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$db}`"), report: false);
            }
        }
        if ($this->dir !== null) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /** A chain as M6 wrote it (recording where events came from off: v1), then carried on as M7 writes it (on: v2). */
    private function mixedChain(): void
    {
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 's.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-06-10', 'tax_year' => '2025-26',
            'description' => 'ACME EVENTS — café “PO-1”', 'merchant_key' => 'ACME EVENTS', 'amount_pence' => 45_000, 'raw' => [], 'fingerprint' => md5('m7'), 'occurrence' => 1]);
        $writer = app(AllocationWriter::class);

        // Before: off, as M6 shipped (stored false here, since the default is now on).
        Setting::put(AuditLog::CONTEXT_SETTING, false);
        AuditContext::with(null, fn () => $writer->decide($tx, AllocationWriter::lines(45_000, 'business_income', Category::query()->where('code', 'turnover')->value('id'))),
            'web', AuditContext::newCorrelation());
        AuditContext::with('system', fn () => AuditLog::record('test.before', 'Test', ['b' => 1, 'a' => 2], ['ok' => true]), 'schedule', AuditContext::newCorrelation(), 3);

        // After: the setting as the deploy leaves it (not stored), so its default: on.
        DB::table('settings')->where('key', AuditLog::CONTEXT_SETTING)->delete();
        AuditContext::with(null, function () use ($writer, $tx) {
            $writer->clear($tx->fresh(), 'Sent back');
            AuditLog::record('test.after', 'Test', ['z' => ['y' => 'café ✓', 'b' => [2, 1]], 'a' => 1], ['totals' => collect(['zeta' => 1, 'alpha' => 2])], 'From the web');
        }, 'web', AuditContext::newCorrelation());
        AuditContext::with('system', fn () => AuditLog::record('test.run', 'Test', null, ['n' => 1]), 'schedule', AuditContext::newCorrelation(), 7);
        AuditContext::with(null, fn () => AuditLog::record('test.telegram', 'Test'), 'telegram', AuditContext::newCorrelation());
        AuditLog::record('test.plain', 'Test', null, ['last' => true]); // outside any context: no channel, so v1 still
    }

    public function test_it_defaults_to_on_and_can_still_be_turned_off(): void
    {
        $this->assertTrue(Setting::DEFAULTS[AuditLog::CONTEXT_SETTING]);
        $this->assertNull(DB::table('settings')->where('key', AuditLog::CONTEXT_SETTING)->value('value'));
        $this->assertTrue(AuditLog::recordsContext(), 'Not stored: its default');
        Setting::put(AuditLog::CONTEXT_SETTING, false);
        $this->assertFalse(AuditLog::recordsContext(), 'Turned off by hand');
        Setting::put(AuditLog::CONTEXT_SETTING, null);
        $this->assertTrue(AuditLog::recordsContext());
    }

    public function test_an_event_from_the_web_records_where_it_came_from(): void
    {
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 's.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-06-10', 'tax_year' => '2025-26',
            'description' => 'PRET A MANGER', 'merchant_key' => 'PRET A MANGER', 'amount_pence' => -650, 'raw' => [], 'fingerprint' => md5('web'), 'occurrence' => 1]);
        $this->actingAs(User::factory()->create());
        $this->post("/items/bank/{$tx->id}/decide", ['kind' => 'personal'])->assertSessionHas('success');

        $event = AuditEvent::query()->where('action', 'allocation.decided')->sole();
        $this->assertSame(['you', 'web'], [$event->actor, $event->channel]);
        $this->assertTrue(AuditContext::isCorrelation((string) $event->correlation));
        $this->assertSame(AuditLog::hashFor($event), $event->hash, 'Hashed the second way (v2), with where it came from in front');
        $v1 = (clone $event)->forceFill(['channel' => null, 'correlation' => null, 'run_id' => null]);
        $this->assertNotSame(AuditLog::hashFor($v1), $event->hash);
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_a_chain_written_before_and_after_verifies(): void
    {
        $this->mixedChain();
        $events = AuditEvent::query()->orderBy('id')->get();
        $before = $events->filter(fn (AuditEvent $e) => str_starts_with($e->action, 'allocation.decided') || $e->action === 'test.before');
        $after = $events->filter(fn (AuditEvent $e) => in_array($e->action, ['allocation.cleared', 'test.after', 'test.run', 'test.telegram'], true));

        $this->assertSame([[null, null, null]], $before->map(fn ($e) => [$e->channel, $e->correlation, $e->run_id])->unique()->values()->all(), 'Written the first way (v1) while off');
        $this->assertSame(['web', 'web', 'schedule', 'telegram'], $after->pluck('channel')->values()->all(), 'The second way (v2) once on');
        $this->assertSame(7, (int) $after->firstWhere('action', 'test.run')->run_id);
        $this->assertNull($events->last()->channel, 'An event outside any context stays v1');
        $this->assertSame(['ok' => true, 'checked' => $events->count(), 'broken_at' => null], AuditLog::verify());

        // Tampering with where a v2 event came from is caught.
        DB::table('audit_events')->where('id', $after->firstWhere('action', 'test.run')->id)->update(['run_id' => 8]);
        $this->assertFalse(AuditLog::verify()['ok']);
    }

    /**
     * The same mixed chain, backed up the way the server does and loaded into an empty MariaDB database by
     * deploy/restore-check.sh, which checks the chain in SQL. Runs only against MySQL/MariaDB with the command-line tools,
     * on scratch databases of its own (ledger_restore_*), never the test database.
     */
    public function test_restore_check_verifies_the_mixed_chain_on_a_restored_copy(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Backups are MySQL dumps: run with DB_CONNECTION=mysql.');
        }
        foreach (['mysqldump', 'mysql', 'openssl', 'tar', 'sha256sum'] as $bin) {
            if (! Process::run(['sh', '-c', 'command -v '.$bin])->successful()) {
                $this->markTestSkipped("{$bin} isn't installed.");
            }
        }
        $src = $this->scratch('m7src');
        $dst = $this->scratch('m7dst');
        DB::setDefaultConnection($src);
        Artisan::call('migrate', ['--database' => $src, '--force' => true]);
        $this->mixedChain();
        $events = DB::connection($src)->table('audit_events')->count();
        $v2 = DB::connection($src)->table('audit_events')->whereNotNull('channel')->count();
        $this->assertTrue(AuditLog::verify()['ok']);
        DB::setDefaultConnection('mysql');
        $this->assertSame(4, $v2);
        $this->assertGreaterThan($v2, $events);

        $check = $this->backUpAndCheck($src, $dst);
        $this->assertSame(0, $check->exitCode(), $check->output().$check->errorOutput());
        $this->assertStringContainsString("audit trail's chain intact ({$events} events)", $check->output());
        $this->assertStringContainsString('Backup restores correctly.', $check->output());
        DB::setDefaultConnection($dst);
        $this->assertTrue(AuditLog::verify()['ok'], 'The app’s own check of the restored copy agrees');
        DB::setDefaultConnection('mysql');
    }

    private function scratch(string $name): string
    {
        $db = 'ledger_restore_'.$name.'_'.getmypid();
        try {
            DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$db}`");
            DB::connection('mysql')->statement("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            $this->markTestSkipped('Needs rights to create ledger_restore_* databases: '.$e->getMessage());
        }
        $this->databases[] = $db;
        config(["database.connections.{$db}" => ['database' => $db] + config('database.connections.mysql')]);

        return $db;
    }

    /** Back up $src as deploy/backup.sh does on the server, then check the backup with restore-check.sh, loading it into $dst. */
    private function backUpAndCheck(string $src, string $dst): ProcessResult
    {
        $this->dir = sys_get_temp_dir().'/ledger-backup-m7-'.getmypid();
        File::deleteDirectory($this->dir);
        $app = $this->dir.'/domain/app';
        File::ensureDirectoryExists($app.'/releases/r1/deploy');
        File::ensureDirectoryExists($app.'/shared/storage/app/private/statements');
        File::copy(base_path('deploy/backup.sh'), $app.'/releases/r1/deploy/backup.sh');
        symlink('releases/r1', $app.'/current');
        File::put($app.'/shared/storage/app/private/statements/s.csv', "Date,Amount\n2025-06-10,450.00\n");
        $c = config('database.connections.mysql');
        File::put($app.'/shared/.env', "APP_NAME=Ledger\nDB_HOST={$c['host']}\nDB_DATABASE={$src}\nDB_USERNAME={$c['username']}\nDB_PASSWORD=\"{$c['password']}\"\n");
        $keys = $this->dir.'/keys';
        File::ensureDirectoryExists($keys);
        $this->assertTrue(Process::run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $keys.'/private.pem'])->successful());
        $this->assertTrue(Process::run(['openssl', 'pkey', '-in', $keys.'/private.pem', '-pubout', '-out', $app.'/shared/backup_public.pem'])->successful());
        $backup = Process::env(['BACKUP_DIR' => $this->dir.'/backups', 'HOME' => $this->dir])->run(['bash', $app.'/current/deploy/backup.sh']);
        $this->assertTrue($backup->successful(), $backup->errorOutput().$backup->output());
        $file = glob($this->dir.'/backups/ledger-*.tar')[0] ?? null;
        $this->assertNotNull($file);

        return Process::env(['RESTORE_DB_HOST' => $c['host'], 'RESTORE_DB_PORT' => (string) $c['port'], 'RESTORE_DB_USER' => $c['username'], 'MYSQL_PWD' => $c['password']])
            ->run(['bash', base_path('deploy/restore-check.sh'), $file, $keys.'/private.pem', '--load', $dst]);
    }
}
