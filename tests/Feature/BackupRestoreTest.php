<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The backup really restores: deploy/backup.sh run in a copy of the server's folder layout, then deploy/restore-check.sh
 * with the private key loads it into an empty database, every table matches the original row for row, and the audit
 * trail's chain is checked in the loaded copy (a changed event fails the check).
 *
 * Runs only against MySQL/MariaDB with the command-line tools installed, and needs rights to create databases named
 * ledger_restore_*. It uses its own scratch databases, never the test database.
 */
class BackupRestoreTest extends TestCase
{
    private string $dir;

    private array $databases = [];

    protected function tearDown(): void
    {
        DB::setDefaultConnection('mysql');
        foreach ($this->databases as $db) {
            rescue(fn () => DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$db}`"), report: false);
        }
        if (isset($this->dir)) {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function requirements(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Backups are MySQL dumps: run with DB_CONNECTION=mysql.');
        }
        foreach (['mysqldump', 'mysql', 'openssl', 'tar', 'sha256sum'] as $bin) {
            if (! Process::run(['sh', '-c', 'command -v '.$bin])->successful()) {
                $this->markTestSkipped("{$bin} isn't installed.");
            }
        }
    }

    /** The key inside a backup archive, opened with the test's private key. */
    private function archiveKey(string $file): string
    {
        $out = $this->dir.'/key-check';
        File::ensureDirectoryExists($out);
        $this->assertTrue(Process::run(['tar', '-xf', $file, '-C', $out, 'key.enc'])->successful());
        $this->assertTrue(Process::run(['openssl', 'pkeyutl', '-decrypt', '-inkey', $this->dir.'/keys/private.pem', '-pkeyopt', 'rsa_padding_mode:oaep', '-in', $out.'/key.enc', '-out', $out.'/key.txt'])->successful());

        return File::get($out.'/key.txt');
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

    /** A small but real ledger in the source database: the full schema, categories, a transaction, a decision and an audit entry. */
    private function fill(string $db): void
    {
        DB::setDefaultConnection($db);
        Artisan::call('migrate', ['--database' => $db, '--force' => true]);
        Artisan::call('db:seed', ['--force' => true]);
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 'statement.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'private/statements/statement.csv']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2025-06-10', 'tax_year' => '2025-26',
            'description' => 'ALEX SMITH EVENTS — café “PO-1”', 'merchant_key' => 'ALEX SMITH EVENTS', 'amount_pence' => 123_456, 'raw' => ['note' => 'ünïcode ✓'], 'fingerprint' => md5('x'), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(123_456, 'business_income', Category::query()->where('code', 'turnover')->value('id')));
        AuditLog::record('test.backup', 'Test', null, ['ok' => true]);
        // What the chain check has to read exactly as the app wrote it: accents, quotes, slashes, decimals, no subject id.
        AuditLog::record('test.backup', 'Test', ['rate' => 1.5, 'path' => 'a/b'], ['note' => 'café “PO-1” ✓', 'nested' => ['x' => [1, 2]], 'empty' => []], 'Checked by Alex Smith', 'system');
        // Events hashed the second way (v2), once recording where events came from is turned on: with where they came
        // from, the correlation and a run id, and keys given out of order, some inside a collection or an object
        // (stored, and hashed, as plain JSON in order); chained between events hashed the first way.
        Setting::put(AuditLog::CONTEXT_SETTING, true);
        AuditContext::with(null, function () use ($tx) {
            app(AllocationWriter::class)->confirm($tx, 'Confirmed on the web');
            AuditLog::record('test.backup_v2', 'Test', ['z' => 1, 'a' => ['y' => 'café ✓', 'b' => [2, 1]], 'row' => (object) ['b' => 1, 'a' => 2]],
                ['ok' => true, 'totals' => collect(['zeta' => 1, 'alpha' => 2])], 'From the web');
        }, 'web', AuditContext::newCorrelation());
        AuditContext::with('system', fn () => AuditLog::record('test.backup_run', 'Test', null, ['n' => 1]), 'schedule', null, 7);
        AuditLog::record('test.backup', 'Test', null, ['last' => true]);
        DB::setDefaultConnection('mysql');
    }

    /**
     * Back up the source database the way the server does, then check the backup with restore-check.sh, loading it
     * into $dst.
     *
     * @return array{0:ProcessResult, 1:string} the check's result, and the backup file
     */
    private function backUpAndCheck(string $src, string $dst, string $name): array
    {
        // The server's layout: app/current -> releases/r1, with the .env, the public key and uploads in app/shared.
        $this->dir = sys_get_temp_dir().'/ledger-backup-'.$name.'-'.getmypid();
        File::deleteDirectory($this->dir);
        $app = $this->dir.'/domain/app';
        File::ensureDirectoryExists($app.'/releases/r1/deploy');
        File::ensureDirectoryExists($app.'/shared/storage/app/private/statements');
        File::copy(base_path('deploy/backup.sh'), $app.'/releases/r1/deploy/backup.sh');
        symlink('releases/r1', $app.'/current');
        File::put($app.'/shared/storage/app/private/statements/statement.csv', "Date,Amount\n2025-06-10,1234.56\n");
        $c = config('database.connections.mysql');
        File::put($app.'/shared/.env', "APP_NAME=Ledger\nDB_HOST={$c['host']}\nDB_DATABASE={$src}\nDB_USERNAME={$c['username']}\nDB_PASSWORD=\"{$c['password']}\"\n");

        $keys = $this->dir.'/keys';
        File::ensureDirectoryExists($keys);
        $this->assertTrue(Process::run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $keys.'/private.pem'])->successful());
        $this->assertTrue(Process::run(['openssl', 'pkey', '-in', $keys.'/private.pem', '-pubout', '-out', $app.'/shared/backup_public.pem'])->successful());

        // The daily cron's form: through the current/ link.
        $backup = Process::env(['BACKUP_DIR' => $this->dir.'/backups', 'HOME' => $this->dir])->run(['bash', $app.'/current/deploy/backup.sh']);
        $this->assertTrue($backup->successful(), $backup->errorOutput().$backup->output());
        $file = glob($this->dir.'/backups/ledger-*.tar')[0] ?? null;
        $this->assertNotNull($file);

        $check = Process::env(['RESTORE_DB_HOST' => $c['host'], 'RESTORE_DB_PORT' => (string) $c['port'], 'RESTORE_DB_USER' => $c['username'], 'MYSQL_PWD' => $c['password']])
            ->run(['bash', base_path('deploy/restore-check.sh'), $file, $keys.'/private.pem', '--load', $dst, '--keep', $this->dir.'/restored']);

        return [$check, $file];
    }

    /** @return array<string, string|null> table => checksum */
    private function checksums(string $db): array
    {
        $tables = collect(DB::connection($db)->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))->map(fn ($r) => array_values((array) $r)[0])->sort()->values();

        return $tables->mapWithKeys(fn ($t) => [$t => (string) (DB::connection($db)->selectOne("CHECKSUM TABLE `{$t}`")->Checksum ?? '')])->all();
    }

    public function test_a_backup_made_on_the_server_restores_completely(): void
    {
        $this->requirements();
        $src = $this->scratch('src');
        $dst = $this->scratch('dst');
        $this->fill($src);
        DB::setDefaultConnection($src);
        $this->assertTrue(AuditLog::verify()['ok']);
        DB::setDefaultConnection('mysql');

        [$check, $file] = $this->backUpAndCheck($src, $dst, 'test');
        $this->assertFileExists($file.'.sha256');
        // The server can't read its own backups: nothing in the archive is plain text.
        $this->assertStringNotContainsString('ALEX SMITH EVENTS', (string) file_get_contents($file));
        // The archive's key is 64 hex characters. openssl's -pass file: stops at the first newline or NUL byte, so a key
        // of raw random bytes could leave a password of a few bytes, or an empty one.
        $this->assertSame(32, strlen(hex2bin(trim($this->archiveKey($file)))));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\n\z/', $this->archiveKey($file));

        $this->assertSame(0, $check->exitCode(), $check->output().$check->errorOutput());
        $this->assertStringContainsString('Backup restores correctly.', $check->output());
        $this->assertStringContainsString('checksum matches', $check->output());
        $this->assertStringContainsString('uploaded files: 1', $check->output());
        $events = DB::connection($src)->table('audit_events')->count();
        $this->assertGreaterThanOrEqual(6, $events);
        $this->assertSame(3, DB::connection($src)->table('audit_events')->whereNotNull('channel')->count(), 'Some events hashed each way');
        $this->assertStringContainsString("audit trail's chain intact ({$events} events)", $check->output());

        // Every table, row for row.
        $before = $this->checksums($src);
        $this->assertNotEmpty($before);
        $this->assertSame($before, $this->checksums($dst));
        $this->assertSame(123_456, (int) DB::connection($dst)->table('bank_transactions')->value('amount_pence'));
        $this->assertSame("Date,Amount\n2025-06-10,1234.56\n", File::get($this->dir.'/restored/private/statements/statement.csv'));
    }

    public function test_a_changed_audit_event_fails_the_check_on_the_restored_copy(): void
    {
        $this->requirements();
        $src = $this->scratch('tsrc');
        $dst = $this->scratch('tdst');
        $this->fill($src);
        // Somebody edits a recorded reason straight in the database (the app itself never updates an event).
        $tampered = (int) DB::connection($src)->table('audit_events')->where('action', 'allocation.decided')->value('id');
        DB::connection($src)->table('audit_events')->where('id', $tampered)->update(['reason' => 'Changed afterwards']);
        $good = DB::connection($src)->table('audit_events')->where('id', '<', $tampered)->count();

        [$check] = $this->backUpAndCheck($src, $dst, 'tamper');

        $this->assertNotSame(0, $check->exitCode(), $check->output());
        $this->assertStringContainsString("FAIL  audit trail's chain broken at event {$tampered} after {$good} good events", $check->output());
        $this->assertStringContainsString('Backup check FAILED.', $check->output());
        // The app's own check of the copy agrees.
        DB::setDefaultConnection($dst);
        $this->assertSame(['ok' => false, 'checked' => $good, 'broken_at' => $tampered], AuditLog::verify());
    }

    public function test_a_changed_correlation_fails_the_check_on_the_restored_copy(): void
    {
        $this->requirements();
        $src = $this->scratch('csrc');
        $dst = $this->scratch('cdst');
        $this->fill($src);
        // Somebody moves an event to another action's correlation, straight in the database.
        $tampered = (int) DB::connection($src)->table('audit_events')->where('action', 'test.backup_v2')->value('id');
        DB::connection($src)->table('audit_events')->where('id', $tampered)->update(['correlation' => AuditContext::newCorrelation()]);
        $good = DB::connection($src)->table('audit_events')->where('id', '<', $tampered)->count();

        [$check] = $this->backUpAndCheck($src, $dst, 'correlation');

        $this->assertStringContainsString("FAIL  audit trail's chain broken at event {$tampered} after {$good} good events", $check->output());
        DB::setDefaultConnection($dst);
        $this->assertSame(['ok' => false, 'checked' => $good, 'broken_at' => $tampered], AuditLog::verify());
    }

    public function test_where_an_event_came_from_folded_into_its_reason_fails_the_check(): void
    {
        $this->requirements();
        $src = $this->scratch('fsrc');
        $dst = $this->scratch('fdst');
        $this->fill($src);
        // Somebody empties where an event came from and adds that text to its reason instead, straight in the database.
        $row = DB::connection($src)->table('audit_events')->where('action', 'test.backup_v2')->first();
        DB::connection($src)->table('audit_events')->where('id', $row->id)->update(['channel' => null, 'correlation' => null, 'run_id' => null,
            'reason' => $row->reason.'|v2|'.$row->channel.'|'.$row->correlation.'|'.$row->run_id]);
        $good = DB::connection($src)->table('audit_events')->where('id', '<', $row->id)->count();

        [$check] = $this->backUpAndCheck($src, $dst, 'fold');

        $this->assertStringContainsString("FAIL  audit trail's chain broken at event {$row->id} after {$good} good events", $check->output());
        DB::setDefaultConnection($dst);
        $this->assertSame(['ok' => false, 'checked' => $good, 'broken_at' => (int) $row->id], AuditLog::verify());
    }

    public function test_a_wrong_key_or_a_damaged_file_is_reported(): void
    {
        $this->requirements();
        $this->dir = sys_get_temp_dir().'/ledger-backup-bad-'.getmypid();
        File::ensureDirectoryExists($this->dir);
        $k = $this->dir;
        Process::run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $k.'/right.pem']);
        Process::run(['openssl', 'genpkey', '-algorithm', 'RSA', '-pkeyopt', 'rsa_keygen_bits:2048', '-out', $k.'/wrong.pem']);
        Process::run(['openssl', 'pkey', '-in', $k.'/right.pem', '-pubout', '-out', $k.'/right.pub']);

        // A backup-shaped archive made the same way backup.sh makes one.
        File::ensureDirectoryExists($k.'/p/private');
        File::put($k.'/p/database.sql', "CREATE TABLE `migrations` (id int);\n-- Dump completed on 2026-11-24\n");
        $script = 'set -e; cd '.escapeshellarg($k).'; tar -czf payload.tar.gz -C p database.sql private; openssl rand -out key.bin 32;'
            .' openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -in payload.tar.gz -out payload.enc -pass file:key.bin;'
            .' openssl pkeyutl -encrypt -pubin -inkey right.pub -pkeyopt rsa_padding_mode:oaep -in key.bin -out key.enc;'
            .' tar -cf b.tar payload.enc key.enc; sha256sum b.tar > b.tar.sha256';
        $this->assertTrue(Process::run(['bash', '-c', $script])->successful());

        $wrong = Process::run(['bash', base_path('deploy/restore-check.sh'), $k.'/b.tar', $k.'/wrong.pem']);
        $this->assertNotSame(0, $wrong->exitCode());
        $this->assertStringContainsString('can\'t open the backup', $wrong->output());

        // The right key, but the dump has only one of the main tables.
        $partial = Process::run(['bash', base_path('deploy/restore-check.sh'), $k.'/b.tar', $k.'/right.pem']);
        $this->assertNotSame(0, $partial->exitCode());
        $this->assertStringContainsString('missing: users', $partial->output());

        // One byte changed: the checksum catches it.
        $bytes = file_get_contents($k.'/b.tar');
        $bytes[strlen($bytes) - 600] = chr(ord($bytes[strlen($bytes) - 600]) ^ 1);
        file_put_contents($k.'/b.tar', $bytes);
        $damaged = Process::run(['bash', base_path('deploy/restore-check.sh'), $k.'/b.tar', $k.'/right.pem']);
        $this->assertNotSame(0, $damaged->exitCode());
        $this->assertStringContainsString('checksum doesn\'t match', $damaged->output());
    }
}
