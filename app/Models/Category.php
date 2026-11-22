<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['parent_id', 'code', 'name', 'kind', 'hmrc_box', 'is_allowable', 'is_capital', 'sort'];

    protected $casts = ['is_allowable' => 'boolean', 'is_capital' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** The HMRC-level category this rolls up to (itself if top level). */
    public function hmrcCategory(): Category
    {
        return $this->parent ?? $this;
    }
}
