<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Models\AuditEvent;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit hash's second way (v2, docs/intelligence-cycle.md 3.3): an event with a channel, correlation or run id is
 * hashed with them, and with its JSON re-encoded canonically; every other event, and so every event already in the
 * log, hashes exactly as before. Runs on SQLite and MariaDB (DB_CONNECTION=mysql); the SQL check of a restored copy is
 * covered by BackupRestoreTest.
 */
class AuditHashV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put(AuditLog::CONTEXT_SETTING, true); // recording where events came from is turned on (see the test of it off)
    }

    /** The first release's hash, written out here on its own so a change to AuditLog::hashFor can't hide in both. */
    private static function oldHash(array $e): string
    {
        return hash('sha256', implode('|', [
            $e['prev_hash'] ?? '', $e['occurred_at'], $e['actor'], $e['action'], $e['subject_type'], (string) ($e['subject_id'] ?? ''),
            json_encode($e['before'] ?? null), json_encode($e['after'] ?? null), $e['reason'] ?? '',
        ]));
    }

    /**
     * What deploy/restore-check.sh computes in SQL from a stored row, written out in PHP: the stored JSON text as it
     * is, and for an event with a channel, correlation or run id, "v2|" and those three in front.
     */
    private static function sqlHash(array $row): string
    {
        $v2 = $row['channel'] !== null || $row['correlation'] !== null || $row['run_id'] !== null;

        return hash('sha256', ($v2 ? 'v2|'.$row['channel'].'|'.$row['correlation'].'|'.$row['run_id'].'|' : '').implode('|', [
            $row['prev_hash'] ?? '', $row['occurred_at'], $row['actor'], $row['action'], $row['subject_type'], (string) ($row['subject_id'] ?? ''),
            $row['before'] ?? 'null', $row['after'] ?? 'null', $row['reason'] ?? '',
        ]));
    }

    /** @return int|null the first stored event whose hash the SQL check wouldn't reproduce */
    private static function sqlBreak(): ?int
    {
        foreach (DB::table('audit_events')->orderBy('id')->get() as $row) {
            $row = (array) $row;
            $row['occurred_at'] = substr((string) $row['occurred_at'], 0, 19);
            if (self::sqlHash($row) !== $row['hash']) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    /** Events as the first release stored them: no channel, correlation or run id; the chain's head on the last. */
    private function legacyChain(int $n): array
    {
        $prev = null;
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $row = ['occurred_at' => "2025-06-0{$i} 10:00:00", 'actor' => $i % 2 ? 'you' : 'rules', 'action' => 'allocation.decided', 'subject_type' => 'BankTransaction',
                'subject_id' => $i, 'before' => null, 'after' => [['kind' => 'personal', 'amount_pence' => -350 * $i, 'note' => 'café “Acme” / ✓', 'confirmed' => true]],
                'reason' => $i === 2 ? null : 'Checked by Alex Smith', 'prev_hash' => $prev];
            $row['hash'] = self::oldHash($row);
            $ids[] = DB::table('audit_events')->insertGetId(['before' => null, 'after' => json_encode($row['after'])] + $row);
            $prev = $row['hash'];
        }
        DB::table('audit_heads')->updateOrInsert(['id' => 1], ['hash' => $prev, 'event_id' => end($ids), 'created_at' => now(), 'updated_at' => now()]);

        return $ids;
    }

    public function test_an_event_with_none_of_them_hashes_exactly_as_the_first_release_did(): void
    {
        $a = new AuditEvent(['occurred_at' => '2026-01-02 03:04:05', 'actor' => 'you', 'action' => 'allocation.decided', 'subject_type' => 'BankTransaction', 'subject_id' => 7,
            'before' => null, 'after' => [['kind' => 'personal', 'amount_pence' => -350, 'note' => 'café “x” / ✓', 'confirmed' => true]], 'reason' => 'Test', 'prev_hash' => null]);
        $b = new AuditEvent(['occurred_at' => '2026-01-02 03:04:06', 'actor' => 'matcher', 'action' => 'transfer.paired', 'subject_type' => 'BankTransaction', 'subject_id' => 8,
            'before' => ['transfer_pair_id' => null], 'after' => ['transfer_pair_id' => 9], 'reason' => null, 'prev_hash' => str_repeat('a', 64)]);

        // Worked out with the first release's AuditLog::hashFor (commit 3384b60).
        $this->assertSame('68a137c2de2ffc49ae2058659a70a69fc636e9467164e02a454611cb25fe786b', AuditLog::hashFor($a));
        $this->assertSame('c5369e1a9744c74d6d17ef54898d0ceb2e8da302fc296a7e1f8b1eb615b68264', AuditLog::hashFor($b));
    }

    public function test_a_chain_from_before_the_columns_still_verifies_and_carries_on_mixed(): void
    {
        $legacy = $this->legacyChain(3);
        $this->assertSame(['ok' => true, 'checked' => 3, 'broken_at' => null], AuditLog::verify());

        // New events: one from the web (v2), one with no context (v1), one from a cycle run (v2), in one chain.
        $correlation = AuditContext::newCorrelation();
        $web = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test', ['b' => 1, 'a' => ['z' => 1, 'y' => [3, 2]]], ['ok' => true], 'From the web'), 'web', $correlation);
        $plain = AuditLog::record('test.plain', 'Test', null, ['b' => 2, 'a' => 1]);
        $run = AuditContext::with('system', fn () => AuditLog::record('test.run', 'Test', null, ['n' => 1]), 'schedule', null, 44);

        $this->assertSame(['web', $correlation, null], [$web->channel, $web->correlation, $web->run_id]);
        $this->assertSame([null, null, null], [$plain->channel, $plain->correlation, $plain->run_id]);
        $this->assertSame(['schedule', null, 44, 'system'], [$run->channel, $run->correlation, (int) $run->run_id, $run->actor]);
        $this->assertSame(DB::table('audit_events')->where('id', end($legacy))->value('hash'), $web->prev_hash, 'Chained straight on');

        // The v1 event is hashed the first way; the v2 ones are not.
        $stored = fn (AuditEvent $e) => (array) DB::table('audit_events')->find($e->id);
        $this->assertSame(self::oldHash(['before' => null, 'after' => ['b' => 2, 'a' => 1]] + $stored($plain)), $plain->hash);
        $this->assertNotSame(self::oldHash(['before' => json_decode($stored($web)['before'], true), 'after' => ['ok' => true]] + $stored($web)), $web->hash);
        // A v2 event's JSON is stored with its keys in order: the stored text is the text it's hashed as.
        $this->assertSame('{"a":{"y":[3,2],"z":1},"b":1}', $stored($web)['before']);
        $this->assertSame('{"b":2,"a":1}', $stored($plain)['after'], 'A v1 event is stored as written');

        $this->assertSame(['ok' => true, 'checked' => 6, 'broken_at' => null], AuditLog::verify());
    }

    public function test_changing_the_channel_correlation_or_run_id_breaks_the_chain(): void
    {
        $this->legacyChain(2);
        $event = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test', null, ['ok' => true]), 'telegram', AuditContext::newCorrelation(), 9);
        AuditLog::record('test.after', 'Test');
        $original = (array) DB::table('audit_events')->find($event->id);

        foreach ([['channel' => 'web'], ['correlation' => AuditContext::newCorrelation()], ['run_id' => 10], ['channel' => null, 'correlation' => null, 'run_id' => null]] as $change) {
            DB::table('audit_events')->where('id', $event->id)->update($change);
            $this->assertSame(['ok' => false, 'checked' => 2, 'broken_at' => $event->id], AuditLog::verify(), 'Changed: '.implode(', ', array_keys($change)));
            DB::table('audit_events')->where('id', $event->id)->update(array_intersect_key($original, $change));
            $this->assertTrue(AuditLog::verify()['ok']);
        }

        // Giving an event from before the columns a channel afterwards is caught too.
        $first = (int) DB::table('audit_events')->min('id');
        DB::table('audit_events')->where('id', $first)->update(['channel' => 'web']);
        $this->assertSame(['ok' => false, 'checked' => 0, 'broken_at' => $first], AuditLog::verify());
    }

    public function test_a_v2_event_survives_a_database_that_reorders_json_keys(): void
    {
        $v2 = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test', ['note' => 'café ✓', 'rate' => 1.5, 'path' => 'a/b', 'nested' => ['zz' => 1, 'a' => [1, 2]], 'empty' => []],
            ['id' => 3]), 'web', AuditContext::newCorrelation());
        $v1 = AuditLog::record('test.plain', 'Test', ['note' => 'café ✓', 'rate' => 1.5, 'id' => 3]);
        AuditLog::record('test.after', 'Test');
        $this->assertTrue(AuditLog::verify()['ok']);

        // What MySQL's native JSON type hands back: keys by length then name, a space after each colon and comma,
        // characters unescaped. A v2 event is re-encoded canonically before hashing, so it still verifies.
        DB::table('audit_events')->where('id', $v2->id)->update(['before' => '{"note": "café ✓", "path": "a/b", "rate": 1.5, "empty": [], "nested": {"a": [1, 2], "zz": 1}}',
            'after' => '{"id": 3}']);
        $this->assertTrue(AuditLog::verify()['ok'], 'Reordered keys and spacing don’t change a v2 event');

        // A v1 event is hashed as its JSON was written, so on such a database it would break: there, AuditLog
        // writes every event the second way (see test_on_a_database_that_reorders_json_every_event_is_v2).
        DB::table('audit_events')->where('id', $v1->id)->update(['before' => '{"id": 3, "note": "café ✓", "rate": 1.5}']);
        $this->assertSame($v1->id, AuditLog::verify()['broken_at']);
    }

    public function test_on_a_database_that_reorders_json_every_event_is_v2(): void
    {
        $this->assertFalse(AuditLog::reordersJson(), 'SQLite and MariaDB keep the JSON as written');
        Setting::put(AuditLog::CONTEXT_SETTING, false); // even with recording where events came from turned off
        $reorders = new \ReflectionProperty(AuditLog::class, 'reorders');
        $reorders->setValue(null, [DB::connection()->getName() => true]);
        try {
            $event = AuditLog::record('test.plain', 'Test', ['b' => 1, 'a' => 2]);
        } finally {
            $reorders->setValue(null, []);
        }

        $this->assertSame('cli', $event->channel, 'Named where it came from (a command, here), so it is hashed canonically');
        $this->assertSame('{"a":2,"b":1}', DB::table('audit_events')->where('id', $event->id)->value('before'));
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_the_canonical_form_reads_back_as_it_was_written(): void
    {
        $values = [
            null, true, 0, -350, 1.5, 10.0, 'café “PO-1” ✓ a/b', [], [3, 1, 2], ['b' => 1, 'a' => 2],
            ['z' => ['y' => [['d' => 1, 'c' => null]], 'x' => []], 'a' => 'é', 10 => 'ten', 2 => 'two'],
        ];
        foreach ($values as $v) {
            $text = AuditLog::canonicalJson($v);
            $this->assertSame($text, AuditLog::canonicalJson(json_decode($text, true)), $text);
        }
        // Keys compared as text ("10" before "2"), characters escaped as PHP (and the model's array cast) writes them.
        $this->assertSame('{"10":"ten","2":"two","a":"\\u00e9","z":{"x":[],"y":[{"c":null,"d":1}]}}', AuditLog::canonicalJson($values[10]));
        $this->assertSame('[3,1,2]', AuditLog::canonicalJson([3, 1, 2]), 'A list keeps its order');
    }

    public function test_a_legacy_actor_is_recorded_as_what_it_means_now(): void
    {
        $this->assertSame(['matcher', 'system'], [AuditLog::record('test.a', 'Test', actor: 'rules')->actor, AuditLog::record('test.b', 'Test', actor: 'server')->actor]);
        $this->assertTrue(AuditLog::verify()['ok']);
    }

    public function test_a_v2_event_is_hashed_with_where_it_came_from_in_front(): void
    {
        $correlation = AuditContext::newCorrelation();
        $event = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test', ['b' => 1, 'a' => 2], ['ok' => true], 'From the web'), 'telegram', $correlation, 9);
        $row = (array) DB::table('audit_events')->find($event->id);

        $this->assertSame(hash('sha256', 'v2|telegram|'.$correlation.'|9|'.implode('|', [
            '', substr((string) $row['occurred_at'], 0, 19), 'you', 'test.web', 'Test', '', '{"a":2,"b":1}', '{"ok":true}', 'From the web',
        ])), $event->hash);
        $this->assertSame($event->hash, self::sqlHash(['occurred_at' => substr((string) $row['occurred_at'], 0, 19)] + $row));
    }

    /**
     * Emptying an event's channel, correlation and run id and adding their text to its reason must not give an event
     * that hashes as v1 to the same hash: where it came from would be gone without a trace, every later hash unchanged.
     */
    public function test_where_an_event_came_from_cant_be_folded_into_its_reason(): void
    {
        $this->legacyChain(1);
        $correlation = AuditContext::newCorrelation();
        $event = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test', null, ['ok' => true], 'Decided in Telegram'), 'telegram', $correlation, 9);
        AuditLog::record('test.after', 'Test');
        $this->assertTrue(AuditLog::verify()['ok']);

        foreach (['|v2|telegram|'.$correlation.'|9', 'v2|telegram|'.$correlation.'|9|', '|telegram|'.$correlation.'|9'] as $text) {
            foreach (['Decided in Telegram'.$text, $text.'Decided in Telegram'] as $reason) {
                DB::table('audit_events')->where('id', $event->id)->update(['channel' => null, 'correlation' => null, 'run_id' => null, 'reason' => $reason]);
                $this->assertSame(['ok' => false, 'checked' => 1, 'broken_at' => $event->id], AuditLog::verify(), $reason);
                $this->assertSame($event->id, self::sqlBreak(), 'The SQL check of a restored copy catches it too');
            }
        }
        // Moved between them, the three can't stand in for each other either.
        foreach ([['channel' => 'telegram', 'correlation' => null, 'run_id' => null, 'reason' => $correlation.'|9|Decided in Telegram'],
            ['channel' => null, 'correlation' => null, 'run_id' => null, 'reason' => 'telegram|'.$correlation.'|9|Decided in Telegram']] as $change) {
            DB::table('audit_events')->where('id', $event->id)->update($change);
            $this->assertSame($event->id, AuditLog::verify()['broken_at'], $change['reason']);
        }
        DB::table('audit_events')->where('id', $event->id)->update(['channel' => 'telegram', 'correlation' => $correlation, 'run_id' => 9, 'reason' => 'Decided in Telegram']);
        $this->assertTrue(AuditLog::verify()['ok']);
        $this->assertNull(self::sqlBreak());
    }

    public function test_objects_inside_a_v2_payload_are_stored_as_the_text_they_are_hashed_as(): void
    {
        $event = AuditContext::with(null, fn () => AuditLog::record('test.obj', 'Test', ['row' => (object) ['b' => 1, 'a' => 2]],
            ['totals' => collect(['zeta' => 1, 'alpha' => 2]), 'list' => collect([3, 1]), 'none' => (object) [], 'rate' => 1.5]), 'web', AuditContext::newCorrelation());
        AuditLog::record('test.after', 'Test');

        $row = DB::table('audit_events')->find($event->id);
        $this->assertSame('{"row":{"a":2,"b":1}}', $row->before);
        $this->assertSame('{"list":[3,1],"none":[],"rate":1.5,"totals":{"alpha":2,"zeta":1}}', $row->after);
        $this->assertTrue(AuditLog::verify()['ok']);
        $this->assertNull(self::sqlBreak(), 'The SQL check, which hashes the stored text, agrees');
    }

    /**
     * Turned off (by hand: from M7 it's on by default), events record only the first way, even from the web, Telegram,
     * the queue or a cycle run: a release before M6, which knows only the first way, can be redeployed and still
     * verifies every event.
     */
    public function test_turned_off_every_event_is_v1_so_the_release_before_still_verifies(): void
    {
        Setting::put(AuditLog::CONTEXT_SETTING, false);
        $this->assertFalse(AuditLog::recordsContext());
        $this->legacyChain(2);
        $web = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test', ['b' => 1, 'a' => 2], ['ok' => true], 'From the web'), 'web', AuditContext::newCorrelation());
        AuditContext::with('system', fn () => AuditLog::record('test.run', 'Test', null, ['n' => 1]), 'schedule', AuditContext::newCorrelation(), 44);
        AuditLog::record('test.plain', 'Test');

        $this->assertSame([null, null, null], [$web->channel, $web->correlation, $web->run_id]);
        $this->assertSame('{"b":1,"a":2}', DB::table('audit_events')->where('id', $web->id)->value('before'), 'Stored as written, as before');
        // The release before this one checks each event by the first way only.
        $prev = null;
        foreach (DB::table('audit_events')->orderBy('id')->get() as $row) {
            $row = (array) $row;
            $this->assertSame($prev, $row['prev_hash']);
            $this->assertSame(self::oldHash(['occurred_at' => substr((string) $row['occurred_at'], 0, 19), 'before' => json_decode((string) $row['before'], true),
                'after' => json_decode((string) $row['after'], true)] + $row), $row['hash'], 'Event '.$row['id']);
            $prev = $row['hash'];
        }
        $this->assertSame(['ok' => true, 'checked' => 5, 'broken_at' => null], AuditLog::verify());

        // Turned on, the next event records where it came from.
        Setting::put(AuditLog::CONTEXT_SETTING, true);
        $on = AuditContext::with(null, fn () => AuditLog::record('test.web', 'Test'), 'web', AuditContext::newCorrelation());
        $this->assertSame('web', $on->channel);
        $this->assertTrue(AuditLog::verify()['ok']);
    }
}
