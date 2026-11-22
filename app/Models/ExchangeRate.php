<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'period_start', 'period_end', 'units_per_gbp', 'source'];

    protected $casts = ['period_start' => 'date', 'period_end' => 'date'];
}
