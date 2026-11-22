<?php

namespace App\Domain\Calendar;

use App\Models\CalendarEvent;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sabre\VObject\Reader;

/**
 * Reads your calendar's private iCal feed (read-only) so each transaction can show what was on that day.
 */
final class CalendarSync
{
    public function sync(?string $url = null, int $yearsBack = 4): int
    {
        $url ??= Setting::get('calendar_ics_url');
        if (! $url) {
            throw new RuntimeException('Add your calendar feed address in Settings first.');
        }

        $response = Http::timeout(30)->get($url);
        if (! $response->successful()) {
            throw new RuntimeException("The calendar feed couldn't be read (HTTP {$response->status()}).");
        }

        return $this->load($response->body(), $yearsBack);
    }

    public function load(string $ics, int $yearsBack = 4): int
    {
        $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        $start = CarbonImmutable::now()->subYears($yearsBack)->startOfYear();
        $end = CarbonImmutable::now()->addYear();
        $expanded = $calendar->expand($start, $end, new DateTimeZone('Europe/London'));

        $rows = [];
        foreach ($expanded->VEVENT ?? [] as $event) {
            $allDay = ! $event->DTSTART->hasTime();
            $startsAt = CarbonImmutable::instance($event->DTSTART->getDateTime());
            $endsAt = isset($event->DTEND) ? CarbonImmutable::instance($event->DTEND->getDateTime()) : null;
            $rows[] = [
                'uid' => (string) ($event->UID ?? md5($startsAt.(string) $event->SUMMARY)),
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt?->format('Y-m-d H:i:s'),
                'all_day' => $allDay,
                'summary' => mb_substr((string) ($event->SUMMARY ?? ''), 0, 250),
                'location' => mb_substr((string) ($event->LOCATION ?? ''), 0, 250) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::transaction(function () use ($rows) {
            CalendarEvent::query()->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                CalendarEvent::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /** @return list<array{summary:?string,location:?string,time:?string}> */
    public static function eventsOn(string $date): array
    {
        $day = CarbonImmutable::parse($date);

        return CalendarEvent::query()
            ->where('starts_at', '<', $day->addDay()->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('ends_at', '>', $day->format('Y-m-d H:i:s'))->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', $day->format('Y-m-d H:i:s'))))
            ->orderBy('starts_at')->limit(10)->get()
            ->map(fn (CalendarEvent $e) => [
                'summary' => $e->summary,
                'location' => $e->location,
                'time' => $e->all_day ? null : $e->starts_at->format('H:i').($e->ends_at ? '–'.$e->ends_at->format('H:i') : ''),
            ])->all();
    }
}
