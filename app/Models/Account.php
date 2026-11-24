<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['name', 'kind', 'provider', 'currency', 'last4', 'import_profile', 'is_active', 'parent_account_id'];

    protected $casts = ['import_profile' => 'array', 'is_active' => 'boolean'];

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    /** A currency pocket's main account (Revolut EUR → Revolut). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id');
    }

    public function isForeign(): bool
    {
        return $this->currency && strtoupper($this->currency) !== 'GBP';
    }

    public function isCard(): bool
    {
        return $this->kind === 'credit_card';
    }
}
