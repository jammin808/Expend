<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Spending or income that never went through an imported account: cash, a foreign receipt, and similar. */
class ManualExpense extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'spent_on' => 'date', 'fx_rate_date' => 'date', 'amount_pence' => 'integer', 'original_amount_minor' => 'integer',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /** The phone entry it was added from, if it was. */
    public function quickEntry(): HasOne
    {
        return $this->hasOne(QuickEntry::class);
    }
}
