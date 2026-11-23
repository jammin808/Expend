<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A to-do item you snoozed until a date, or marked not relevant (no date). */
class TodoDismissal extends Model
{
    protected $fillable = ['key', 'until', 'note'];

    protected $casts = ['until' => 'datetime'];

    /** @return array<string, true> keys hidden right now */
    public static function activeKeys(): array
    {
        return self::query()->where(fn ($q) => $q->whereNull('until')->orWhere('until', '>', now()))->pluck('key')->flip()->map(fn () => true)->all();
    }
}
