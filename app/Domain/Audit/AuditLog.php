<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
        $prev = AuditEvent::query()->orderByDesc('id')->value('hash');
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
        $event->save();

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
        $checked = 0;
        foreach (AuditEvent::query()->orderBy('id')->cursor() as $event) {
            if ($event->prev_hash !== $prev || self::hashFor($event) !== $event->hash) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->id];
            }
            $prev = $event->hash;
            $checked++;
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
