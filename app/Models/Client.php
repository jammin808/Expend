<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['name', 'notes'];

    public function jobs(): HasMany
    {
        return $this->hasMany(WorkJob::class);
    }
}
