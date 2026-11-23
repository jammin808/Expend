<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident, append-only record of every change: who, what, when, why, before and after.
 */
final class AuditLog
{
    public static function record(
        string $action,
        Model|string $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        string $actor = 'you',
    ): AuditEvent {
        $type = $subject instanceof Model ? class_basename($subject) : $subject;
        $id = $subject instanceof Model ? $subject->getKey() : null;

        // One writer at a time: the chain head row is locked until this write (and any surrounding
        // transaction) commits, so two processes can never both chain onto the same previous event.
        return DB::transaction(function () use ($action, $type, $id, $before, $after, $reason, $actor) {
            $head = DB::table('audit_heads')->where('id', 1)->lockForUpdate()->first();
            if (! $head) {
                DB::table('audit_heads')->insertOrIgnore(['id' => 1, 'hash' => null, 'event_id' => null, 'created_at' => now(), 'updated_at' => now()]);
                $head = DB::table('audit_heads')->where('id', 1)->lockForUpdate()->first();
            }
            $event = self::make($action, $type, $id, $before, $after, $reason, $actor, $head->hash);
            $event->save();
            DB::table('audit_heads')->where('id', 1)->update(['hash' => $event->hash, 'event_id' => $event->id, 'updated_at' => now()]);

            return $event;
        });
    }

    private static function make(string $action, string $type, mixed $id, ?array $before, ?array $after, ?string $reason, string $actor, ?string $prev): AuditEvent
    {
        $at = Carbon::now()->utc()->format('Y-m-d H:i:s');

        $event = new AuditEvent([
            'occurred_at' => $at,
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            'before' => $before,
            'after' => $after,
            // Shortened to fit the column before hashing, so the stored row and its hash agree.
            'reason' => $reason !== null && mb_strlen($reason) > 255 ? mb_substr($reason, 0, 254).'…' : $reason,
            'prev_hash' => $prev,
        ]);
        $event->hash = self::hashFor($event);

        return $event;
    }

    public static function hashFor(AuditEvent $event): string
    {
        $at = $event->occurred_at instanceof \DateTimeInterface
            ? $event->occurred_at->format('Y-m-d H:i:s')
            : (string) $event->occurred_at;

        return hash('sha256', implode('|', [
            $event->prev_hash ?? '',
            $at,
            $event->actor,
            $event->action,
            $event->subject_type,
            (string) $event->subject_id,
            json_encode($event->before),
            json_encode($event->after),
            $event->reason ?? '',
        ]));
    }

    /** @return array{ok:bool, checked:int, broken_at:?int} */
    public static function verify(): array
    {
        $prev = null;
        $last = null;
        $checked = 0;
        foreach (AuditEvent::query()->orderBy('id')->cursor() as $event) {
            if ($event->prev_hash !== $prev || self::hashFor($event) !== $event->hash) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->id];
            }
            $prev = $event->hash;
            $last = $event->id;
            $checked++;
        }
        // The head must point at the last event: a deleted tail would otherwise go unnoticed.
        $head = DB::table('audit_heads')->where('id', 1)->first();
        if ($head && $head->hash !== $prev) {
            return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) ($head->event_id ?? ($last ?? 0))];
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
