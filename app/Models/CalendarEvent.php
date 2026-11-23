<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry from your calendar feed. Times are stored in UTC (as the feed gives them); all-day entries are dates.
 * Use localStart(), localEnd() and day() for UK time.
 */
class CalendarEvent extends Model
{
    public const TZ = 'Europe/London';

    protected $fillable = ['uid', 'recurrence_id', 'starts_at', 'ends_at', 'all_day', 'summary', 'location'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'all_day' => 'boolean'];

    /**
     * A key that stays the same when the feed is reloaded and when the entry is moved: the UID for a one-off entry,
     * the UID and original start (RECURRENCE-ID) for an occurrence of a repeating one.
     */
    public function key(): string
    {
        return self::keyFor($this->uid, $this->recurrence_id);
    }

    public static function keyFor(string $uid, ?string $recurrenceId): string
    {
        return sha1($uid.'|'.($recurrenceId ?? ''));
    }

    public function localStart(): CarbonInterface
    {
        return $this->all_day ? $this->starts_at->copy() : $this->starts_at->copy()->setTimezone(self::TZ);
    }

    public function localEnd(): ?CarbonInterface
    {
        return $this->ends_at ? ($this->all_day ? $this->ends_at->copy() : $this->ends_at->copy()->setTimezone(self::TZ)) : null;
    }

    /** The UK date it starts on. */
    public function day(): string
    {
        return $this->localStart()->format('Y-m-d');
    }

    /** The last UK date the entry covers (an all-day entry ends at midnight the day after). */
    public function lastDay(): CarbonInterface
    {
        $start = $this->localStart();
        $end = $this->localEnd();
        if (! $end || $end->lte($start)) {
            return $start->copy()->startOfDay();
        }

        return ($end->format('H:i:s') === '00:00:00' ? $end->copy()->subDay() : $end)->startOfDay();
    }

    public function text(): string
    {
        return trim($this->summary."\n".$this->location);
    }
}
