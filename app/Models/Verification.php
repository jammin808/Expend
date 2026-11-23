<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The latest double-check of an AI finding against its source (the document, the email in Gmail, the calendar or your records). */
class Verification extends Model
{
    public const LABELS = [
        'verified' => 'Checked against the source',
        'partly' => 'Partly checked',
        'mismatch' => 'Doesn’t match the source',
        'unverifiable' => 'Can’t be checked word for word',
        'source_missing' => 'Source no longer available',
        'second_read_agreed' => 'A second read agreed',
        'second_read_disagreed' => 'A second read disagreed',
    ];

    /** Statuses that mean "look at this before relying on it". */
    public const WARN = ['partly', 'mismatch', 'unverifiable', 'source_missing', 'second_read_disagreed'];

    protected $fillable = ['subject_type', 'subject_id', 'status', 'method', 'checks', 'summary', 'checked_at'];

    protected $casts = ['checks' => 'array', 'checked_at' => 'datetime'];

    public static function for(string $type, int $id): ?self
    {
        return self::query()->where('subject_type', $type)->where('subject_id', $id)->first();
    }

    /** @return array<int, array> latest checks for many subjects of one type, keyed by subject id, ready for the page */
    public static function forMany(string $type, iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return self::query()->where('subject_type', $type)->whereIn('subject_id', $ids)->get()
            ->mapWithKeys(fn (self $v) => [$v->subject_id => $v->present()])->all();
    }

    public function present(): array
    {
        return [
            'status' => $this->status, 'label' => self::LABELS[$this->status] ?? $this->status, 'warn' => in_array($this->status, self::WARN, true),
            'summary' => $this->summary, 'method' => $this->method, 'checked_at' => $this->checked_at->toIso8601String(), 'checks' => $this->checks,
        ];
    }
}
