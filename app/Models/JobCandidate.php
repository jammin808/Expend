<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A possible job the job finder put together from calendar entries, documents, stays and journeys. */
class JobCandidate extends Model
{
    protected $fillable = [
        'status', 'fingerprint', 'title', 'client_name', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'postcode', 'po_number',
        'sources', 'reasons', 'score', 'found_by', 'work_job_id', 'edited_at', 'decided_at', 'note',
    ];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'sources' => 'array', 'reasons' => 'array', 'score' => 'integer',
        'edited_at' => 'datetime', 'decided_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    /** @return list<string> */
    public function sourceKeys(): array
    {
        return array_column($this->sources ?? [], 'key');
    }
}
