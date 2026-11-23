<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

class CalendarEvent extends Model
{
    protected $fillable = ['uid', 'starts_at', 'ends_at', 'all_day', 'summary', 'location'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean'];

    /** A key that stays the same when the feed is reloaded: one per occurrence of a repeating entry. */
    public function key(): string
    {
        return self::keyFor($this->uid, $this->starts_at->format('Y-m-d H:i:s'));
    }

    public static function keyFor(string $uid, string $startsAt): string
    {
        return sha1($uid.'|'.$startsAt);
    }

    /** The last day the entry covers (an all-day entry ends at midnight the day after). */
    public function lastDay(): CarbonInterface
    {
        if (! $this->ends_at || $this->ends_at->lte($this->starts_at)) {
            return $this->starts_at->copy()->startOfDay();
        }
        $end = $this->ends_at->copy();

        return ($end->format('H:i:s') === '00:00:00' ? $end->subDay() : $end)->startOfDay();
    }

    public function text(): string
    {
        return trim($this->summary."\n".$this->location);
    }
}
