<?php

namespace App\Domain\Audit;

use App\Domain\Intel\AuditContext;
use App\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tamper-evident, append-only record of every change: who, what, when, why, before and after.
 */
final class AuditLog
{
    /**
     * @param  string|null  $actor  who did it; when not given, the actor of the surrounding AuditContext ('you' outside one)
     * @param  int|null  $subjectId  the subject's id when $subject is given as a type name rather than a model
     */
    public static function record(
        string $action,
        Model|string $subject,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?string $actor = null,
        ?int $subjectId = null,
    ): AuditEvent {
        $type = $subject instanceof Model ? class_basename($subject) : $subject;
        $id = $subject instanceof Model ? $subject->getKey() : $subjectId;
        $actor ??= AuditContext::actor();

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

    /**
     * The event's hash. deploy/restore-check.sh computes the same in SQL to check a restored copy: change both together.
     */
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

    /**
     * Check every event follows on from the one before, and that the chain's head points at the last one (a deleted
     * tail would otherwise go unnoticed).
     *
     * Safe while events are being written (by the queue, a Telegram message, a page): the head is read first, and
     * only the events up to the one it points at are checked against it. Every write moves the head in the same
     * transaction as its event, under the head's lock, so those events are all there by the time the head is read.
     * Events written after that are checked by reading the head again, until it stays put; one the head never reaches
     * wasn't written by record(), and is a break.
     *
     * @return array{ok:bool, checked:int, broken_at:?int}
     */
    public static function verify(): array
    {
        $prev = null;
        $last = null;
        $checked = 0;
        $head = DB::table('audit_heads')->where('id', 1)->first();
        if (! $head) {
            // No head yet (no event was ever recorded through record()): only the chain itself can be checked.
            foreach (AuditEvent::query()->orderBy('id')->cursor() as $event) {
                if ($event->prev_hash !== $prev || self::hashFor($event) !== $event->hash) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->id];
                }
                $prev = $event->hash;
                $checked++;
            }

            return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
        }

        // A few rounds at most: a round is needed only when events were written while the one before ran.
        for ($round = 0; $round < 10; $round++) {
            $upTo = (int) ($head->event_id ?? 0);
            foreach (AuditEvent::query()->where('id', '>', $last ?? 0)->where('id', '<=', $upTo)->orderBy('id')->cursor() as $event) {
                if ($event->prev_hash !== $prev || self::hashFor($event) !== $event->hash) {
                    return ['ok' => false, 'checked' => $checked, 'broken_at' => $event->id];
                }
                $prev = $event->hash;
                $last = $event->id;
                $checked++;
            }
            if ($head->hash !== $prev) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => $upTo ?: ($last ?? 0)];
            }
            // Events after the head as it was read: written since (the head, read again after them, has moved on to
            // them), or added without moving it.
            $newest = (int) AuditEvent::query()->max('id');
            if ($newest <= $upTo) {
                break;
            }
            $head = DB::table('audit_heads')->where('id', 1)->first();
            $reached = (int) ($head?->event_id ?? 0);
            if (! $head || $reached < $newest) {
                return ['ok' => false, 'checked' => $checked, 'broken_at' => (int) AuditEvent::query()->where('id', '>', $reached)->min('id')];
            }
        }

        return ['ok' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
