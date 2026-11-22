<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'account_id', 'original_filename', 'file_sha256', 'stored_path', 'row_count', 'imported_count',
        'duplicate_count', 'first_date', 'last_date', 'balance_check', 'balance_check_notes', 'status',
    ];

    protected $casts = ['first_date' => 'date', 'last_date' => 'date'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
