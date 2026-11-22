<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['name', 'kind', 'provider', 'currency', 'last4', 'import_profile', 'is_active'];

    protected $casts = ['import_profile' => 'array', 'is_active' => 'boolean'];

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function isCard(): bool
    {
        return $this->kind === 'credit_card';
    }
}
