<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A balance copied from a statement: a bank account's balance, or what a card says you owe, at the end of a day. */
class StatementBalance extends Model
{
    protected $fillable = ['account_id', 'as_of', 'balance_pence', 'note'];

    protected $casts = ['as_of' => 'date', 'balance_pence' => 'integer'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
