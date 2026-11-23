<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A calendar entry linked to a job (or marked "not a job"), kept by the entry's UID and start so it survives a resync. */
class CalendarJobLink extends Model
{
    protected $fillable = ['event_key', 'event_uid', 'event_starts_at', 'summary', 'work_job_id', 'not_job', 'rejected', 'linked_by', 'reason'];

    protected $casts = ['event_starts_at' => 'datetime', 'not_job' => 'boolean', 'rejected' => 'array'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
