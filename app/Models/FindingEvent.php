<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One change to a finding: found, its money changed, snoozed, its snooze ended, marked not relevant, back again,
 * dealt with, not looked for in full this time (unseen), its check went wrong (detector_failed), or kept under a new
 * key (rekeyed). With the money it involved then, what caused it (cause_ref: a ref such as batch:7 or audit:123@…),
 * who did it (the shared actors: you, system, …) and a note (your reason, or what changed).
 *
 * Append-only, like the audit log: an event is never changed or removed.
 */
class FindingEvent extends Model
{
    public const EVENTS = ['detected', 'changed', 'snoozed', 'snooze_ended', 'dismissed', 'reopened', 'resolved', 'unseen', 'detector_failed', 'rekeyed'];

    public const UPDATED_AT = null;

    protected $fillable = ['finding_id', 'event', 'amount_pence', 'currency', 'cause_ref', 'run_id', 'actor', 'note', 'created_at'];

    protected $casts = ['amount_pence' => 'integer', 'run_id' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (FindingEvent $e) {
            if (! in_array($e->event, self::EVENTS, true)) {
                throw new LogicException("Unknown finding event “{$e->event}”.");
            }
            if ($e->note !== null && mb_strlen($e->note) > 255) {
                $e->note = mb_substr($e->note, 0, 254).'…';
            }
        });
        static::updating(fn () => throw new LogicException('Finding events are append-only.'));
        static::deleting(fn () => throw new LogicException('Finding events are append-only.'));
    }

    /** @return BelongsTo<Finding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }
}
