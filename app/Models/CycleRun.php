<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the nightly cycle (docs/intelligence-cycle.md 6.4): the nightly run, the morning run that reports, a run
 * after an import, or one you started with "Check now". Its steps (CycleRunStep) say what each part did. A run in
 * shadow mode only looked: it changed nothing outside the run log.
 *
 * The run's AI allowance and what it spent are kept in millionths of a US dollar (ai_budget_micro, ai_spent_micro).
 * When a run ends, one cycle.completed audit event seals it (seal_audit_id), carrying its counts and the digest of
 * the findings open then.
 */
class CycleRun extends Model
{
    public const KINDS = ['nightly', 'morning', 'import', 'manual'];

    public const MODES = ['on', 'shadow'];

    public const STATUSES = ['running', 'ok', 'partial', 'failed', 'abandoned'];

    /** How each kind reads to you. */
    public const KIND_WORDS = ['nightly' => 'Nightly check', 'morning' => 'Morning report', 'import' => 'After an import', 'manual' => 'Check now'];

    /** How each status reads to you. */
    public const STATUS_WORDS = ['running' => 'Running', 'ok' => 'Finished', 'partial' => 'Finished in part', 'failed' => 'Failed', 'abandoned' => 'Stopped'];

    protected $fillable = [
        'run_key', 'kind', 'mode', 'status', 'started_at', 'heartbeat_at', 'deadline_at', 'finished_at', 'ai_budget_micro', 'ai_spent_micro',
        'counts', 'report', 'reported_at', 'digest', 'seal_audit_id', 'error',
    ];

    protected $casts = [
        'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'deadline_at' => 'datetime', 'finished_at' => 'datetime', 'reported_at' => 'datetime',
        'ai_budget_micro' => 'integer', 'ai_spent_micro' => 'integer', 'seal_audit_id' => 'integer',
        'counts' => 'array', 'report' => 'array',
    ];

    /** @return HasMany<CycleRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(CycleRunStep::class)->orderBy('seq')->orderBy('id');
    }

    public function isShadow(): bool
    {
        return $this->mode === 'shadow';
    }

    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /** The run as a ref (run:44), for findings, events and the report. */
    public function ref(): string
    {
        return 'run:'.$this->id;
    }
}
