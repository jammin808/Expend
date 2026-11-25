<?php

namespace Tests\Feature\Quick;

use App\Domain\Quick\QuickEntries;
use App\Http\Controllers\QuickController;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two sends of one entry at once, for real, on MariaDB (two connections, the unique index on client_key doing the
 * work): the second claim fails on the index and is told to wait, or that it was a repeat, and writes nothing; a
 * withdraw racing the send leaves one row. Each runs without the test's own transaction (so each claim commits as in
 * use) and removes what it wrote afterwards. Skipped on SQLite, which has one writer anyway.
 */
class QuickRaceTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    private const TABLES = ['quick_entries', 'allocations', 'manual_expenses', 'documents', 'audit_events', 'accounts', 'users'];

    /** @var list<array<string, mixed>> the settings as they were */
    private array $settings = [];

    /** @var array<string, int> each table's last id before the test */
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
        foreach (self::TABLES as $t) {
            $this->start[$t] = (int) DB::table($t)->max('id');
        }
        $this->head = DB::table('audit_heads')->where('id', 1)->first();
        $this->settings = DB::table('settings')->get()->map(fn ($r) => (array) $r)->all();
        config(['database.connections.other' => config('database.connections.'.DB::getDefaultConnection())]);
        $this->setUpQuick();
    }

    protected function tearDown(): void
    {
        if ($this->start !== []) {
            QuickEntry::flushEventListeners();
            DB::purge('other');
            Schema::withoutForeignKeyConstraints(function () {
                foreach (self::TABLES as $t) {
                    DB::table($t)->where('id', '>', $this->start[$t])->delete();
                }
                DB::table('settings')->delete();
                foreach ($this->settings as $row) {
                    DB::table('settings')->insert($row);
                }
            });
            DB::table('audit_heads')->where('id', 1)->update(['hash' => $this->head?->hash, 'event_id' => $this->head?->event_id]);
        }
        $this->tearDownQuick();
        parent::tearDown();
    }

    /** Just before this request's claim, another request commits its claim of the same key. */
    private function anotherClaimFirst(string $key, array $row): void
    {
        QuickEntry::creating(function (QuickEntry $e) use ($key, $row) {
            if ($e->client_key === $key && $e->status !== 'withdrawn') {
                DB::connection('other')->table('quick_entries')->insert(['client_key' => $key, 'channel' => 'phone', 'currency' => 'GBP', 'created_at' => now(), 'updated_at' => now()] + $row);
            }
        });
    }

    public function test_a_send_racing_one_still_arriving_waits_and_writes_nothing(): void
    {
        $f = $this->fields();
        $this->anotherClaimFirst($f['client_key'], ['status' => 'receiving']);

        $this->send($f)->assertStatus(202)->assertJsonPath('state', 'receiving');

        $this->assertSame(1, QuickEntry::query()->where('client_key', $f['client_key'])->count());
        $this->assertSame(['receiving', 0], [$this->entry($f['client_key'])->status, ManualExpense::query()->where('id', '>', $this->start['manual_expenses'])->count()]);
    }

    public function test_a_send_racing_one_that_finished_is_a_repeat_with_one_expense(): void
    {
        $f = $this->fields(['payload_sha' => str_repeat('c', 64)]);
        QuickEntries::asYou(fn () => app(QuickEntries::class)->submit(QuickController::wellFormed($this->fields(['client_key' => $this->key()]))));
        $before = ManualExpense::query()->count();
        $this->anotherClaimFirst($f['client_key'], ['status' => 'added', 'payload_sha' => str_repeat('c', 64)]);

        $this->send($f)->assertOk()->assertJsonPath('repeat', true)->assertJsonMissingPath('changed');

        $this->assertSame($before, ManualExpense::query()->count(), 'No second expense');
        $this->assertSame(0, AuditEvent::query()->where('action', 'quick_entry.received')->where('subject_id', $this->entry($f['client_key'])->id)->count());
    }

    public function test_a_withdraw_racing_the_send_leaves_one_row_and_nothing_added(): void
    {
        $f = $this->fields();
        // The send's claim commits just as the withdraw looks for the key and finds none.
        QuickEntry::creating(function (QuickEntry $e) use ($f) {
            if ($e->client_key === $f['client_key'] && $e->status === 'withdrawn') {
                DB::connection('other')->table('quick_entries')->insert(['client_key' => $f['client_key'], 'channel' => 'phone', 'currency' => 'GBP', 'status' => 'receiving',
                    'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->postJson('/quick/entries/'.$f['client_key'].'/withdraw')->assertOk()->assertJsonPath('state', 'withdrawn');

        $this->assertSame(1, QuickEntry::query()->where('client_key', $f['client_key'])->count());
        $this->assertSame('withdrawn', $this->entry($f['client_key'])->status, 'The send, when it reaches step 4, stops');
        QuickEntry::flushEventListeners();
        $this->send($f)->assertOk()->assertJsonPath('state', 'withdrawn');
        $this->assertSame(0, Allocation::query()->where('id', '>', $this->start['allocations'])->count());
    }
}
