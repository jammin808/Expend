<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a cycle run (docs/intelligence-cycle.md 6.4): what it did (counts), how far it got (cursor: where it
 * carries on from when its time ran out), how much a detector looked at (coverage), how many times it was started
 * (attempts), and why it stopped (error).
 *
 * pending: not started; running: under way; more: stopped for time, carries on at the next tick; budget: the AI
 * allowance ran out (not a failure: the work carries over to the next run); ok; failed (after three tries); skipped
 * (a shadow run, a step it needs failed, or the run's time ran out: counts.skipped says which).
 */
class CycleRunStep extends Model
{
    public const STATUSES = ['pending', 'running', 'ok', 'more', 'budget', 'failed', 'skipped'];

    /** Where a step stops for the run: nothing more is done for it in this run. */
    public const ENDED = ['ok', 'budget', 'failed', 'skipped'];

    /** How each status reads to you. */
    public const STATUS_WORDS = ['pending' => 'Waiting', 'running' => 'Running', 'ok' => 'Done', 'more' => 'Carries on', 'budget' => 'AI allowance used up',
        'failed' => 'Failed', 'skipped' => 'Skipped'];

    protected $fillable = ['cycle_run_id', 'step', 'stage', 'seq', 'status', 'attempts', 'cursor', 'counts', 'scope', 'coverage', 'detector_version', 'started_at', 'finished_at', 'error'];

    protected $casts = [
        'seq' => 'integer', 'attempts' => 'integer', 'detector_version' => 'integer',
        'cursor' => 'array', 'counts' => 'array', 'scope' => 'array',
        'started_at' => 'datetime', 'finished_at' => 'datetime',
    ];

    /** @return BelongsTo<CycleRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CycleRun::class, 'cycle_run_id');
    }

    public function hasEnded(): bool
    {
        return in_array($this->status, self::ENDED, true);
    }
}
