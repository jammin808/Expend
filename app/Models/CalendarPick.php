<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pick of days saved (Jobs\DayPick): its token, whether it made a job or added days to one, and the job. Only an
 * idempotency key: a save with a token already here goes to its job and writes nothing.
 */
class CalendarPick extends Model
{
    public const MODES = ['create', 'add'];

    protected $fillable = ['token', 'mode', 'work_job_id'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
