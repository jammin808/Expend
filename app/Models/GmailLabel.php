<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailLabel extends Model
{
    protected $fillable = ['message_id', 'work_job_id', 'invoice_id', 'document_id', 'found_by'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
