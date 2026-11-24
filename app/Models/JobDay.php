<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day a job covers: a rig, show, de-rig, travel or ordinary work day, with its own times if they differ. */
class JobDay extends Model
{
    public const KINDS = ['work' => 'Work', 'rig' => 'Rig / load-in', 'show' => 'Show', 'derig' => 'De-rig / load-out', 'travel' => 'Travel'];

    protected $fillable = ['work_job_id', 'on_date', 'kind', 'start_time', 'end_time', 'calendar_key', 'source', 'note'];

    protected $casts = ['on_date' => 'date'];

    /** Stored as a plain date in every database, so a day can be found by its date. */
    public function setOnDateAttribute(mixed $value): void
    {
        $this->attributes['on_date'] = CarbonImmutable::parse($value)->format('Y-m-d');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
