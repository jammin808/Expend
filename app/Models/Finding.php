<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A problem the app found, kept with its history: first seen, last seen, and how and when it ended.
 *
 * Since M3 it also says what it's about in the shared vocabulary (docs/intelligence-cycle.md section 2): its code
 * (tax.hmrc_unallocated), the record it's about and the job, account or year it belongs to (refs: tx:381, job:14),
 * the evidence it rests on, which way the money goes (owed_to_you, owed_by_you, unrecorded, at_risk, claimable,
 * none), its grade and severity, its state, your reason for hiding it and what closed it. Every change is an event
 * (FindingEvent). A finding kept before M3 has a null state until the findings backfill reads it (legacyState()).
 */
class Finding extends Model
{
    /** open: on the list; snoozed or dismissed: hidden by you; resolved: gone; unseen: not looked for in full this time. */
    public const STATES = ['open', 'snoozed', 'dismissed', 'resolved', 'unseen'];

    protected $fillable = ['key', 'group', 'title', 'detail', 'href', 'amount_pence', 'peak_amount_pence', 'currency', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolution', 'reopened',
        'code', 'detector_version', 'subject_ref', 'scope_ref', 'evidence', 'direction', 'grade', 'severity', 'due_on', 'state', 'note', 'resolution_ref', 'last_run_id'];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'amount_pence' => 'integer', 'peak_amount_pence' => 'integer', 'reopened' => 'integer',
        'evidence' => 'array', 'due_on' => 'date', 'detector_version' => 'integer', 'last_run_id' => 'integer'];

    /** @return HasMany<FindingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FindingEvent::class)->orderBy('id');
    }

    /**
     * Its state: the one recorded, or for a finding kept before M3, the one its resolution gives. When the two
     * disagree (an earlier version of Expend, rolled back to for a while, ended or reopened it without knowing of
     * states), how it ended wins until the next findings run puts the state right (FindingsRegistry).
     */
    public function currentState(): string
    {
        $ended = $this->resolved_at !== null;
        if ($this->state === null || $ended !== self::endedState($this->state)) {
            return self::legacyState($ended, $this->resolution);
        }

        return $this->state;
    }

    /** Whether a state is one of a finding that's ended (hidden by you or gone), not one still on the list. */
    public static function endedState(string $state): bool
    {
        return in_array($state, ['snoozed', 'dismissed', 'resolved'], true);
    }

    /** The state of a finding kept before M3, from how it ended (resolved_at, resolution). */
    public static function legacyState(bool $ended, ?string $resolution): string
    {
        if (! $ended) {
            return 'open';
        }

        return match ($resolution) {
            'snoozed' => 'snoozed',
            'dismissed' => 'dismissed',
            default => 'resolved', // resolved, and a gap joined into another (superseded)
        };
    }
}
