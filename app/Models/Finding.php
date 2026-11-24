<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A problem the app found, kept with its history: first seen, last seen, and how and when it was resolved. */
class Finding extends Model
{
    protected $fillable = ['key', 'group', 'title', 'detail', 'href', 'amount_pence', 'currency', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolution', 'reopened'];

    protected $casts = ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime', 'amount_pence' => 'integer', 'reopened' => 'integer'];
}
