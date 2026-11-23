<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Anything you send the app: an email, a PO, a production pack, a receipt photo. */
class Document extends Model
{
    public const KINDS = [
        'po' => 'Purchase order',
        'job_invite' => 'Job invite or booking',
        'production_pack' => 'Production pack',
        'call_sheet' => 'Call sheet or schedule',
        'booking' => 'Travel or hotel booking',
        'receipt' => 'Receipt',
        'invoice' => 'Invoice',
        'email' => 'Email',
        'other' => 'Other',
        'unknown' => 'Not read yet',
    ];

    protected $guarded = ['id'];

    protected $casts = ['job_rejected' => 'array', 'extraction' => 'array', 'received_at' => 'datetime', 'extracted_at' => 'datetime'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(DocumentFact::class);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf' || str_ends_with(strtolower($this->original_name), '.pdf');
    }

    public function isImage(): bool
    {
        return in_array($this->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }
}
