<?php

use App\Domain\Audit\AuditLog;
use App\Domain\Import\LineDetails;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two details of a statement line were misread, and lines can't be edited, so the ones already kept are put right
 * here, from the statement row each was imported from (kept unchanged in "raw"):
 *
 * - The place read from a fixed-width line's text took any short code at the end for a country, so a bank line
 *   like "BRITISH GAS     DIRECT DEBIT    DD" got the place "Direct Debit, DD". Only places read from the text
 *   are looked at (a place from the statement's own Town or Country columns is left alone), and each is read again
 *   the stricter way. One that's really a payment's kind or description is cleared. One whose code it doesn't know
 *   (no country, or US, Canadian or Australian state, on its list) may still be right, so it's kept as it was.
 * - A time given in UTC was shown in UK time on the line's UTC date, so a payment at 00:30 UK time on 4 July showed
 *   as 00:30 on 3 July. That time is now left off (and a time given with its own time zone is shown in UK time).
 *
 * Nothing about the money changes. What changed is recorded in the audit log, and rolling back puts it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $before = [];
        $after = [];
        DB::table('bank_transactions')->where(fn ($q) => $q->whereNotNull('place')->orWhereNotNull('transacted_time'))
            ->select(['id', 'raw', 'description', 'transaction_on', 'posted_on', 'place', 'transacted_time'])->orderBy('id')
            ->chunkById(500, function ($rows) use (&$before, &$after) {
                foreach ($rows as $r) {
                    $raw = json_decode((string) $r->raw, true) ?: [];
                    $now = LineDetails::from($raw, $r->description, CarbonImmutable::parse(substr((string) ($r->transaction_on ?? $r->posted_on), 0, 10)));
                    $changes = [];
                    if ($r->place !== null && LineDetails::from($raw)['place'] === null && $now['place'] !== $r->place
                        && ($now['place'] !== null || LineDetails::isPaymentText((string) $r->description))) {
                        $changes['place'] = $now['place'];
                    }
                    if ($r->transacted_time !== null && $now['time'] !== $r->transacted_time) {
                        $changes['transacted_time'] = $now['time'];
                    }
                    if ($changes) {
                        DB::table('bank_transactions')->where('id', $r->id)->update($changes);
                        $before[$r->id] = array_intersect_key(['place' => $r->place, 'transacted_time' => $r->transacted_time], $changes);
                        $after[$r->id] = $changes;
                    }
                }
            });
        if ($after) {
            AuditLog::record('ledger.line_details_repaired', 'BankTransaction', ['lines' => $before], ['lines' => $after],
                'A place or time was misread from the statement', 'system');
        }
    }

    public function down(): void
    {
        // What each line had before, from the audit log, where it still has what this put there.
        $event = AuditEvent::query()->where('action', 'ledger.line_details_repaired')->latest('id')->first();
        if (! $event) {
            return;
        }
        $restored = [];
        foreach ($event->before['lines'] ?? [] as $id => $was) {
            $set = $event->after['lines'][$id] ?? [];
            $now = DB::table('bank_transactions')->where('id', $id)->first(array_keys($set));
            if ($now && array_intersect_key((array) $now, $set) == $set) {
                DB::table('bank_transactions')->where('id', $id)->update($was);
                $restored[$id] = $was;
            }
        }
        if ($restored) {
            AuditLog::record('ledger.line_details_restored', 'BankTransaction', ['lines' => $event->after['lines']], ['lines' => $restored],
                'The repair of places and times was rolled back', 'system');
        }
    }
};
