<?php

namespace App\Domain\Calendar;

use App\Domain\Audit\AuditLog;
use App\Jobs\FindJobs;
use App\Models\CalendarEvent;
use App\Models\CalendarJobLink;
use App\Models\JobCandidate;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Reads your calendar's private iCal feed (read-only) so each transaction can show what was on that day.
 *
 * Cancelled entries (STATUS:CANCELLED) aren't kept as live entries, so one the feed now marks cancelled stops counting
 * at the next refresh. A feed that doesn't answer, can't be read, isn't a calendar, comes back cut short (it doesn't
 * end with END:VCALENDAR) or comes back with no entries at all never wipes the stored entries: they're kept until a
 * good feed says otherwise, and the message says so in plain words.
 */
final class CalendarSync
{
    /**
     * Refresh from the feed, then let the job finder look at what's new (FindJobs, queued, which keeps to your
     * "find jobs automatically" setting). The hourly refresh and Sync now in Settings both come through here.
     *
     * @throws EmptyFeed when the feed had no entries and some are stored (they're kept)
     */
    public function sync(?string $url = null, int $yearsBack = 4): int
    {
        $url ??= Setting::get('calendar_ics_url');
        if (! $url) {
            throw new RuntimeException('Add your calendar feed address in Settings first.');
        }

        try {
            $response = Http::timeout(30)->get($url);
        } catch (ConnectionException $e) {
            // Timed out, or the address couldn't be reached: cURL's own words mean nothing to you.
            throw new RuntimeException('The calendar feed didn’t answer, so the entries already here were kept. Try again in a few minutes.', previous: $e);
        }
        if (! $response->successful()) {
            throw new RuntimeException("The calendar feed couldn't be read (HTTP {$response->status()}), so the entries already here were kept.");
        }

        $count = $this->load($response->body(), $yearsBack);
        try {
            FindJobs::dispatch();
        } catch (Throwable $e) {
            report($e); // the calendar itself is loaded; the nightly run looks for jobs too
        }

        return $count;
    }

    /**
     * Replace the stored entries with the feed's. Nothing is changed when the feed can't be read, isn't a calendar or
     * was cut short (it throws first), or when it has no entries at all while some are stored (EmptyFeed).
     */
    public function load(string $ics, int $yearsBack = 4): int
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            $calendar = null; // empty, cut short mid-entry, or a web page (a sign-in page) instead of the feed
        }
        if (! $calendar instanceof VCalendar) {
            throw new RuntimeException('The calendar feed address didn’t return a calendar, so the entries already here were kept.');
        }
        // The forgiving reader accepts a feed that stops after any entry, and reads only the entries before the cut: a
        // whole feed ends with END:VCALENDAR.
        if (strcasecmp(substr(rtrim($ics), -13), 'END:VCALENDAR') !== 0) {
            throw new RuntimeException('The calendar feed came back cut short, so the entries already here were kept. Try again in a few minutes.');
        }
        // No entries at all, not even past or cancelled ones: a feed having a bad moment, not an empty calendar.
        if (count($calendar->select('VEVENT')) === 0 && ($kept = CalendarEvent::query()->count()) > 0) {
            throw new EmptyFeed($kept);
        }
        $start = CarbonImmutable::now()->subYears($yearsBack)->startOfYear();
        $end = CarbonImmutable::now()->addYear();
        $expanded = $calendar->expand($start, $end, new DateTimeZone('Europe/London'));

        $rows = [];
        foreach ($expanded->VEVENT ?? [] as $event) {
            // A cancelled entry (or a cancelled occurrence of a repeating one) isn't on: left out, so it stops
            // counting for the job finder, the calendar page and what was on each day.
            if (strtoupper(trim((string) ($event->STATUS ?? ''))) === 'CANCELLED') {
                continue;
            }
            $allDay = ! $event->DTSTART->hasTime();
            $startsAt = CarbonImmutable::instance($event->DTSTART->getDateTime());
            $endsAt = isset($event->DTEND) ? CarbonImmutable::instance($event->DTEND->getDateTime()) : null;
            $uid = (string) ($event->UID ?? md5($startsAt.(string) $event->SUMMARY));
            // Set on each occurrence of a repeating entry: its original start, which stays the same if it's moved.
            $recurrenceId = isset($event->{'RECURRENCE-ID'}) ? CarbonImmutable::instance($event->{'RECURRENCE-ID'}->getDateTime())->utc()->format('Y-m-d H:i:s') : null;
            $rows[] = [
                'uid' => $uid,
                'recurrence_id' => $recurrenceId,
                'event_key' => CalendarEvent::keyFor($uid, $recurrenceId),
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
        try {
            self::repairKeys();
        } catch (Throwable $e) {
            report($e); // the calendar itself is loaded
        }

        return count($rows);
    }

    /**
     * Links and possible jobs whose key no longer matches any entry are matched to their entry again. Keys changed
     * once (they used to include the start time, so a moved entry lost its link), and the upgrade couldn't always
     * tell an occurrence of a repeating entry from a one-off. A link is matched by its UID with the start it was
     * linked at (as the start, or as the original start of a moved occurrence), or by UID alone for a one-off entry.
     * If the entry has a link of its own by then, your decision is kept: an automatic link gives way to yours,
     * never the other way round.
     *
     * @return array{links:int, candidates:int} how many were matched again
     */
    public static function repairKeys(bool $allCandidates = false): array
    {
        $byUid = [];
        foreach (DB::table('calendar_events')->select(['uid', 'recurrence_id', 'starts_at'])->get() as $e) {
            $byUid[$e->uid][] = ['rid' => $e->recurrence_id, 'start' => substr((string) $e->starts_at, 0, 19), 'key' => CalendarEvent::keyFor($e->uid, $e->recurrence_id)];
        }
        $keys = [];
        $legacy = []; // the old key (UID + start) of each entry, to its key now
        foreach ($byUid as $uid => $events) {
            foreach ($events as $e) {
                $keys[$e['key']] = true;
                // A key is only trusted once the sync has said which entries repeat: before that, every occurrence
                // of a repeating entry looks like the same one-off entry.
                if ($e['rid'] !== null || count($events) === 1) {
                    $legacy[CalendarEvent::keyFor($uid, $e['start'])] = $e['key'];
                }
            }
        }

        $links = 0;
        $all = CalendarJobLink::query()->orderByRaw("case when linked_by = 'you' then 0 else 1 end")->orderByDesc('updated_at')->get();
        $byKey = $all->keyBy('event_key');
        foreach ($all as $link) {
            if (isset($keys[$link->event_key]) || ! ($events = $byUid[$link->event_uid] ?? null)) {
                continue; // still matches its entry, or the entry is gone
            }
            $start = $link->event_starts_at?->format('Y-m-d H:i:s');
            $match = collect($events)->firstWhere('start', $start) ?? collect($events)->firstWhere('rid', $start)
                ?? (count($events) === 1 && $events[0]['rid'] === null ? $events[0] : null);
            if (! $match || ($match['rid'] === null && count($events) > 1)) {
                continue;
            }
            $holder = $byKey[$match['key']] ?? null;
            if ($holder && $holder->id !== $link->id) {
                if ($link->linked_by === 'you' && $holder->linked_by === 'you') {
                    continue; // two decisions of yours: neither is removed
                }
                // The automatic one of the two gives way. Any "not this job" you'd said on it moves to the one kept,
                // unless the one kept was linked automatically to that very job: then both are left for you.
                [$keep, $drop] = $link->linked_by === 'you' ? [$link, $holder] : [$holder, $link];
                if ($keep->linked_by !== 'you' && $keep->work_job_id && in_array($keep->work_job_id, $drop->rejected ?? [], true)) {
                    continue;
                }
                $rejected = array_values(array_unique(array_diff([...($keep->rejected ?? []), ...($drop->rejected ?? [])], array_filter([$keep->work_job_id]))));
                if ($rejected !== array_values($keep->rejected ?? [])) {
                    $before = $keep->only(['rejected']);
                    $keep->update(['rejected' => $rejected ?: null]);
                    AuditLog::record('calendar.linked', $keep, $before, $keep->only(['rejected']), 'Kept your “not this job” from a duplicate link to the same entry', 'matcher');
                }
                AuditLog::record('calendar.link_removed', $drop, $drop->only(['work_job_id', 'linked_by', 'not_job', 'rejected', 'event_key']), null,
                    match (true) {
                        $drop === $holder => 'Made automatically while your earlier decision on this entry wasn’t found; yours is kept',
                        $holder->linked_by === 'you' => 'Made automatically; your decision on this entry is kept',
                        default => 'Made automatically; the entry already has a link of its own',
                    }, 'matcher');
                $drop->delete();
                if ($drop === $link) {
                    continue;
                }
            }
            $before = $link->only(['event_key']);
            $link->update(['event_key' => $match['key']]);
            $byKey[$match['key']] = $link;
            AuditLog::record('calendar.link_rekeyed', $link, $before, ['event_key' => $match['key']], 'Matched to its calendar entry again', 'matcher');
            $links++;
        }

        $candidates = 0;
        $query = JobCandidate::query()->when(! $allCandidates, fn ($q) => $q->where('status', 'open'));
        foreach ($query->get() as $candidate) {
            $sources = $candidate->sources ?? [];
            $changed = false;
            foreach ($sources as $i => $s) {
                if (($s['type'] ?? null) === 'calendar' && ! isset($keys[$s['id']]) && ($new = $legacy[$s['id']] ?? null)) {
                    $sources[$i] = ['key' => 'cal:'.$new, 'id' => $new] + $s;
                    $changed = true;
                }
            }
            if ($changed) {
                $candidate->update(['sources' => $sources, 'fingerprint' => $candidate->fingerprint ? sha1(collect($sources)->pluck('key')->sort()->implode('|')) : null]);
                AuditLog::record('jobs.candidate_rekeyed', $candidate, null, ['sources' => $candidate->sourceKeys()], 'Matched to its calendar entries again', 'matcher');
                $candidates++;
            }
        }

        return ['links' => $links, 'candidates' => $candidates];
    }

    /**
     * What was on in your calendar on a UK day. Timed entries are stored in UTC, so the day runs from UK midnight
     * (23:00 UTC the day before in summer); all-day entries are stored as dates.
     *
     * @return list<array{summary:?string,location:?string,time:?string}>
     */
    public static function eventsOn(string $date): array
    {
        $day = CarbonImmutable::parse(substr($date, 0, 10), CalendarEvent::TZ)->startOfDay();
        $within = fn ($q, string $from, string $to) => $q->where('starts_at', '<', $to)
            ->where(fn ($q) => $q->where('ends_at', '>', $from)->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', $from)));

        return CalendarEvent::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $within($q->where('all_day', false), $day->utc()->format('Y-m-d H:i:s'), $day->addDay()->utc()->format('Y-m-d H:i:s')))
                ->orWhere(fn ($q) => $within($q->where('all_day', true), $day->format('Y-m-d 00:00:00'), $day->addDay()->format('Y-m-d 00:00:00'))))
            ->orderBy('starts_at')->limit(10)->get()
            ->map(fn (CalendarEvent $e) => [
                'summary' => $e->summary,
                'location' => $e->location,
                'time' => $e->all_day ? null : $e->localStart()->format('H:i').($e->ends_at ? '–'.$e->localEnd()->format('H:i') : ''),
            ])->all();
    }
}
