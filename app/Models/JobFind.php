<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something the search for a job's related items linked, thinks may belong, or that you said doesn't belong. */
class JobFind extends Model
{
    public const TYPES = ['calendar' => 'Calendar entry', 'document' => 'Document or PO', 'payment' => 'Payment', 'income' => 'Money in', 'travel' => 'Stay or journey', 'invoice' => 'Invoice'];

    protected $fillable = ['work_job_id', 'type', 'ref', 'status', 'label', 'detail', 'on_date', 'amount_pence', 'currency'];

    protected $casts = ['on_date' => 'date', 'amount_pence' => 'integer'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
