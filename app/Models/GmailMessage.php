<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailMessage extends Model
{
    protected $fillable = ['message_id', 'thread_id', 'search', 'email_from', 'subject', 'sent_at', 'document_id', 'status', 'error'];

    protected $casts = ['sent_at' => 'datetime'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
