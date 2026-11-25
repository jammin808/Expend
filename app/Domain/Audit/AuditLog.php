<?php

namespace App\Domain\Audit;

use App\Domain\Intel\AuditContext;
use App\Models\AuditEvent;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Tamper-evident, append-only record of every change: who, what, when, why, before and after.
 */
final class AuditLog
{
    /**
     * The setting that turns on recording where each event came from (recordsContext()). On by default from M7: see there.
     */
    public const CONTEXT_SETTING = 'intel_audit_context';

    /** @var array<string, bool> by connection: whether its JSON columns keep their own key order (reordersJson()) */
    private static array $reorders = [];

    /**
     * @param  string|null  $actor  who did it; when not given, the actor of the surrounding AuditContext ('you' outside one).
     *                              A legacy name ('rules') is recorded as what it means now ('matcher').
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
        $actor = AuditContext::normalise($actor ?? AuditContext::actor());
        $context = ['channel' => AuditContext::channel(), 'correlation' => AuditContext::correlation(), 'run_id' => AuditContext::runId()];
        // Recorded only once turned on, so the release before this one can still verify every event (recordsContext()).
        if (array_filter($context, fn ($v) => $v !== null) !== [] && ! self::recordsContext()) {
            $context = ['channel' => null, 'correlation' => null, 'run_id' => null];
        }
        if (array_filter($context, fn ($v) => $v !== null) === [] && self::reordersJson()) {
            // A database that reorders JSON keys (MySQL's native JSON) would break an event hashed the first way, which
            // hashes the JSON as stored: name where the event came from, so it's hashed the second way (canonically).
            $context['channel'] = app()->runningInConsole() ? 'cli' : 'web';
        }

        // One writer at a time: the chain head row is locked until this write (and any surrounding
        // transaction) commits, so two processes can never both chain onto the same previous event.
        return DB::transaction(function () use ($action, $type, $id, $before, $after, $reason, $actor, $context) {
            $head = self::lockHead();
            $event = self::make($action, $type, $id, $before, $after, $reason, $actor, $head->hash, $context);
            $event->save();
            DB::table('audit_heads')->where('id', 1)->update(['hash' => $event->hash, 'event_id' => $event->id, 'updated_at' => now()]);

            return $event;
        });
    }

    /**
     * Lock the chain's head row (adding it the first time) until the surrounding transaction commits, and return it.
     * record() takes it for each event. Work that writes several things and audits them (picking a job's days) takes it
     * first, before locking anything else, so every audited writer takes its locks in one order: the head, then the
     * job, then the rows it changes.
     *
     * @throws LogicException outside a transaction, where the lock would be let go at once
     */
    public static function lockHead(): object
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The audit head can only be locked inside a transaction.');
        }
        $head = DB::table('audit_heads')->where('id', 1)->lockForUpdate()->first();
        if (! $head) {
            DB::table('audit_heads')->insertOrIgnore(['id' => 1, 'hash' => null, 'event_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            $head = DB::table('audit_heads')->where('id', 1)->lockForUpdate()->first();
        }

        return $head;
    }

    /** @param  array{channel:?string, correlation:?string, run_id:?int}  $context */
    private static function make(string $action, string $type, mixed $id, ?array $before, ?array $after, ?string $reason, string $actor, ?string $prev, array $context): AuditEvent
    {
        $at = Carbon::now()->utc()->format('Y-m-d H:i:s');
        // Only what's set is written: an event with none of them is stored (and hashed) exactly as before they existed.
        $context = array_filter($context, fn ($v) => $v !== null);
        $v2 = $context !== [];

        $event = new AuditEvent([
            'occurred_at' => $at,
            'actor' => $actor,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id,
            // The second way stores the JSON as it's hashed: plain arrays (a collection, model or object inside becomes
            // one, as it reads back) with their keys in order, so the stored text is the canonical text
            // (deploy/restore-check.sh hashes the stored text).
            'before' => $v2 ? self::canonical(self::plain($before)) : $before,
            'after' => $v2 ? self::canonical(self::plain($after)) : $after,
            // Shortened to fit the column before hashing, so the stored row and its hash agree.
            'reason' => $reason !== null && mb_strlen($reason) > 255 ? mb_substr($reason, 0, 254).'…' : $reason,
            'prev_hash' => $prev,
        ] + $context);
        $event->hash = self::hashFor($event);

        return $event;
    }

    /**
     * The event's hash. deploy/restore-check.sh computes the same in SQL to check a restored copy: change both together.
     *
     * Two ways, told apart by the event itself:
     *  - v1, an event with no channel, correlation or run id (every event written before they existed): its fields
     *    joined by "|", with before and after as PHP encodes them, unchanged since the first release;
     *  - v2, an event with any of them: "v2|", the channel, correlation and run id joined by "|", then "|" and the same
     *    fields as v1, with before and after re-encoded canonically (keys in order at every level, PHP's own encoding).
     *    A database that reorders a JSON column's keys (MySQL's native JSON) or spaces them out can't break it, and the
     *    text written is the canonical text, so the SQL check of a MariaDB copy can hash the stored text as it is.
     *
     * The v2 text starts with them, after a mark no v1 text can start with (v1 text starts with the previous event's
     * hash, 64 hexadecimal digits, or with "|" for the first event), so neither way's text can be made from the other's
     * fields. Were they at the end, after the free-text reason, an event's channel, correlation and run id could be
     * emptied and their text added to its reason, and it would hash as v1 to the very same hash: where it came from
     * removed without a trace. Between the mark and the previous event's hash (which verify() checks on its own) none of
     * them can hold a "|", so none can be moved into another either.
     */
    public static function hashFor(AuditEvent $event): string
    {
        $at = $event->occurred_at instanceof \DateTimeInterface
            ? $event->occurred_at->format('Y-m-d H:i:s')
            : (string) $event->occurred_at;
        $v2 = $event->channel !== null || $event->correlation !== null || $event->run_id !== null;

        $hashed = implode('|', [
            $event->prev_hash ?? '',
            $at,
            $event->actor,
            $event->action,
            $event->subject_type,
            (string) $event->subject_id,
            $v2 ? self::canonicalJson($event->before) : json_encode($event->before),
            $v2 ? self::canonicalJson($event->after) : json_encode($event->after),
            $event->reason ?? '',
        ]);
        if ($v2) {
            $hashed = 'v2|'.$event->channel.'|'.$event->correlation.'|'.$event->run_id.'|'.$hashed;
        }

        return hash('sha256', $hashed);
    }

    /** A value as v2 hashes it: canonical() encoded the way PHP (and the model's array cast) encodes JSON. */
    public static function canonicalJson(mixed $value): string
    {
        return (string) json_encode(self::canonical(self::plain($value)));
    }

    /**
     * A value as it reads back from the database: encoded and decoded, so a collection, model or object inside it is
     * the plain array it's stored as. canonical() orders only arrays' keys, so without this an object's keys would be
     * stored in its own order but hashed in order, and the SQL check, which hashes the stored text, would fail.
     */
    private static function plain(mixed $value): mixed
    {
        return $value === null || is_scalar($value) ? $value : json_decode(json_encode($value, JSON_THROW_ON_ERROR), true);
    }

    /**
     * Keys in order at every level (a list keeps its order). Encoding the result, decoding it and doing this again
     * gives the same text, so an event reads back as it was hashed whatever order the database keeps its keys in.
     *
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => self::canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * Whether new events record where they came from: the channel, correlation and run id of the AuditContext, hashed the
     * second way. On by default from M7 (the setting intel_audit_context, read with its default: Setting::DEFAULTS), and
     * off only where it was turned off by hand (stored as false).
     *
     * Releases before M6 know only the first way, and would read an event hashed the second way as a broken chain (the
     * nightly check's alarm). M6, the first release that reads the second way, wrote only the first way, so redeploying
     * the release before it stayed safe. From M7 on, roll back no further than M6 (docs/intelligence-cycle.md 3.1 and
     * M7). A chain written the first way before this and the second way after verifies as one (verify(), and
     * deploy/restore-check.sh on a restored copy). A database that reorders JSON keys writes every event the second way
     * regardless (reordersJson()).
     */
    public static function recordsContext(): bool
    {
        return (bool) Setting::get(self::CONTEXT_SETTING);
    }

    /**
     * Whether the audit log's JSON columns keep their own key order (MySQL's native JSON type does; MariaDB keeps the
     * text as written), so every event has to be written the second way. Asked once per connection.
     */
    public static function reordersJson(): bool
    {
        $connection = DB::connection();
        $name = (string) $connection->getName();
        if (isset(self::$reorders[$name])) {
            return self::$reorders[$name];
        }
        if ($connection->getDriverName() !== 'mysql') {
            return self::$reorders[$name] = false;
        }
        try {
            $version = (string) $connection->selectOne('select version() as v')?->v;
            $columns = collect($connection->select("select column_name as c, data_type as t from information_schema.columns where table_schema = database() and table_name = 'audit_events'"))
                ->mapWithKeys(fn ($r) => [strtolower((string) ($r->c ?? $r->C ?? '')) => strtolower((string) ($r->t ?? $r->T ?? ''))]);
        } catch (\Throwable) {
            return false; // not known yet: asked again next time
        }

        $native = ! str_contains(strtolower($version), 'mariadb') && ($columns['before'] ?? '') === 'json';
        if ($native && ! $columns->has('channel')) {
            return false; // the migrations adding the channel haven't run yet: nothing to write it in, asked again later
        }

        return self::$reorders[$name] = $native;
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
