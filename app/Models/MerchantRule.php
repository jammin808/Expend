<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantRule extends Model
{
    protected $fillable = ['merchant_key', 'kind', 'category_id', 'work_job_id', 'business_pct', 'auto_confirm'];

    protected $casts = ['auto_confirm' => 'boolean', 'business_pct' => 'integer'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
