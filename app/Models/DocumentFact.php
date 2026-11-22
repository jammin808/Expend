<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One detail read from a document, with the exact words it came from and whether that was verified. */
class DocumentFact extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['on_date' => 'date', 'verified' => 'boolean'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
