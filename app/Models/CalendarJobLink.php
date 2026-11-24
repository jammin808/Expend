<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A calendar entry linked to a job (or marked "not a job"), kept by the entry's UID and start so it survives a resync. */
class CalendarJobLink extends Model
{
    protected $fillable = ['event_key', 'event_uid', 'event_starts_at', 'summary', 'work_job_id', 'not_job', 'rejected', 'linked_by', 'reason'];

    protected $casts = ['event_starts_at' => 'datetime', 'not_job' => 'boolean', 'rejected' => 'array'];

    protected static function booted(): void
    {
        // A check of a link is about the entry being on that job: once the link is removed, or puts the entry on
        // another job or none, the check no longer applies (a link to a job is checked again in the nightly checks).
        static::deleted(fn (CalendarJobLink $link) => $link->forgetCheck());
        static::saved(function (CalendarJobLink $link) {
            if ($link->wasChanged(['work_job_id', 'not_job']) && ! $link->wasRecentlyCreated) {
                $link->forgetCheck();
            }
        });
    }

    /** Whether it puts the entry on a job (rather than "not a job", or "not this job" with no other). */
    public function onJob(): bool
    {
        return $this->work_job_id !== null && ! $this->not_job;
    }

    public function forgetCheck(): void
    {
        Verification::query()->where('subject_type', 'calendar_link')->where('subject_id', $this->id)->delete();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
