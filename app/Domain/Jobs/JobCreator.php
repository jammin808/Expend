<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Clients\ClientIds;
use App\Domain\Documents\Grounding;
use App\Jobs\GatherForJob;
use App\Models\Client;
use App\Models\JobDay;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The one way a job is made: from the jobs page, a calendar entry, a possible job, a document, an opportunity you
 * accept, or an instruction you give the assistant or Telegram. Only you make jobs: automation can find and propose
 * work, but a call from the rules or the AI is refused.
 *
 * It checks the details, finds the client by name (or adds them), sets the job's days (a rig day and a de-rig days
 * later, without the days between), records what it was made from in the job's history, and then searches for
 * everything that belongs to the job (its documents, payments, stays) in the background.
 *
 * The client is the paying client (who you invoice and who pays); the end client, when there's one, is who the work
 * was for (a production company or venue). A client you merged into another is never given: its name finds the
 * client it was merged into. A day or hour rate in pounds is the job's rate (rate_pence and rate_unit); a rate for
 * the whole job is its fee; a rate in another currency stays a note. With a fee or a day rate, the days agreed are the
 * job's working days (travel days left out) unless given. A job made already done is done from then (done_at), and
 * no invoice is drafted for it: JobStatus::offersDraft() says whether to offer one.
 */
final class JobCreator
{
    /** Who can make a job: you, in the app, or through an instruction you gave the assistant or Telegram. */
    public const OWNER = ['you', 'assistant', 'telegram'];

    /**
     * @param  array{
     *     title: string,
     *     client_id?: ?int, client_name?: ?string, client?: ?string,
     *     starts_on?: ?string, ends_on?: ?string,
     *     dates?: list<string|array{date: string, kind?: string, start_time?: ?string, end_time?: ?string, note?: ?string}>,
     *     start_time?: ?string, end_time?: ?string,
     *     venue?: ?string, location?: ?string, postcode?: ?string,
     *     po_number?: ?string, po_not_required?: bool,
     *     rate?: ?array{amount_minor: int, currency?: ?string, unit?: ?string}, fee_pence?: ?int,
     *     rate_pence?: ?int, rate_unit?: ?string, days_agreed?: ?int,
     *     end_client_id?: ?int, end_client_name?: ?string, new_end_client?: ?string,
     *     note?: ?string, notes?: ?string, status?: ?string,
     * }  $fields  a "rate" for the whole job becomes its fee; a day or hour rate in pounds its rate; a rate in another
     *     currency goes in its notes
     * @param  string  $by  you, assistant or telegram (who asked for it)
     * @param  list<array>  $evidence  what it was made from (documents, calendar entries), kept in its history
     * @param  string|null  $reason  why, for its history ("From the job finder")
     * @param  bool  $gather  search for what belongs to it afterwards (in the background); off when the caller does that itself
     *
     * @throws InvalidArgumentException when a detail is missing or wrong, or automation asks
     */
    public function create(array $fields, string $by, array $evidence = [], ?string $reason = null, bool $gather = true): WorkJob
    {
        if (! in_array($by, self::OWNER, true)) {
            throw new InvalidArgumentException('Only you can add a job. Automation can only suggest one.');
        }
        $data = self::check($fields);

        $job = DB::transaction(function () use ($data, $by, $evidence, $reason) {
            // The audit head first, as every audited writer does: the job row written next locks its client's row
            // (a foreign key), which a writer holding the head (a draft's sync) may be waiting for.
            AuditLog::lockHead();
            $clientId = $data['client_id'] ?? null;
            if (! $clientId && $data['client_name'] !== null) {
                $clientId = self::client($data['client_name'], $by)->id;
            }
            $endClientId = $data['end_client_id'] ?? null;
            if (! $endClientId && $data['end_client_name'] !== null) {
                $endClientId = self::client($data['end_client_name'], $by, 'For a new job, as its end client')->id;
            }
            $make = fn () => WorkJob::query()->create([
                'title' => $data['title'], 'client_id' => $clientId, 'end_client_id' => $endClientId,
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
                'location' => $data['location'], 'postcode' => $data['postcode'],
                'fee_pence' => $data['fee_pence'], 'po_number' => $data['po_number'], 'po_not_required' => $data['po_not_required'],
                'rate_pence' => $data['rate_pence'], 'rate_unit' => $data['rate_unit'], 'days_agreed' => $data['days_agreed'],
                // Made already done (work you've done): done from now. No invoice is drafted for it (owner decision).
                'status' => $data['status'], 'done_at' => $data['status'] === 'done' ? now() : null, 'notes' => $data['notes'],
            ]);
            // With its days listed, only those are made (not every date from the first to the last, then deleted).
            $job = $data['dates'] !== null ? JobDays::withoutRange($make) : $make();
            self::setDays($job, $data['dates']);

            AuditLog::record('job.created', $job, null, $job->fresh()->toArray() + [
                'days' => self::dayList($job),
                'evidence' => self::evidenceForHistory($evidence),
            ], $reason, $by);

            return $job;
        });

        if ($gather) {
            try {
                GatherForJob::dispatch($job->id)->afterCommit();
            } catch (Throwable $e) {
                report($e); // the job is made either way; "Search again" on the job finds its things
            }
        }

        return $job->fresh();
    }

    /**
     * The client with this name, whatever its capitals ("acme events" is "Acme Events"), or with the same name
     * without "Ltd", "Limited" or "The" when exactly one client has it; otherwise a new client.
     *
     * Merges are resolved first: the name of a client you merged into another finds the one it was merged into, and
     * two names that now mean one client count once ("Acme Events Limited" finds Acme Events once ACME EVENTS LTD is
     * merged into it, rather than adding a third). A merged client is never given back.
     *
     * @param  string  $reason  why a new client is added, for its history (client.created)
     */
    public static function client(string $name, string $by = 'you', string $reason = 'For a new job'): Client
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            throw new InvalidArgumentException('Give the client a name.');
        }
        $name = mb_substr($name, 0, 120);
        $named = self::resolved(Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orderBy('id')->pluck('id'));
        if ($named->isNotEmpty() && ($found = Client::query()->find($named->first()))) {
            return $found;
        }
        $key = self::clientKey($name);
        if ($key !== '') {
            $same = self::resolved(Client::query()->orderBy('id')->get(['id', 'name'])->filter(fn (Client $c) => self::clientKey($c->name) === $key)->pluck('id'));
            if ($same->count() === 1 && ($found = Client::query()->find($same->first()))) {
                return $found;
            }
        }
        $client = Client::query()->create(['name' => $name]);
        AuditLog::record('client.created', $client, null, ['name' => $name], $reason, $by);

        return $client;
    }

    /**
     * Client ids as they are now: each merged client as the one it was merged into, each once, in the order given.
     *
     * @param  Collection<int, mixed>  $ids
     * @return Collection<int, int>
     */
    private static function resolved(Collection $ids): Collection
    {
        $now = ClientIds::resolveMany($ids);

        return $ids->map(fn ($id) => $now[(int) $id] ?? (int) $id)->unique()->values();
    }

    /**
     * The job's working days, as the days agreed for a fee or day rate count them: its days other than travel days
     * (every day from the first to the last when it has none stored).
     */
    public static function workingDays(WorkJob $job): int
    {
        $days = $job->relationLoaded('days') ? $job->days : $job->days()->get();
        if ($days->isEmpty()) {
            return count($job->workDates());
        }

        return $days->filter(fn (JobDay $d) => $d->kind !== 'travel')->count();
    }

    /** A client's name for comparing: lower case, without Ltd, Limited, PLC, LLP or The, letters and digits only. */
    public static function clientKey(?string $name): string
    {
        return Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b\.?/iu', ' ', (string) $name) ?? '');
    }

    /**
     * The details, checked and tidied.
     *
     * @return array{title: string, client_id: ?int, client_name: ?string, end_client_id: ?int, end_client_name: ?string,
     *     starts_on: string, ends_on: string,
     *     dates: ?list<array{date: string, kind: string, start_time?: string, end_time?: string, note?: string}>,
     *     start_time: ?string, end_time: ?string, location: ?string,
     *     postcode: ?string, fee_pence: ?int, rate_pence: ?int, rate_unit: ?string, days_agreed: ?int,
     *     po_number: ?string, po_not_required: bool, status: string, notes: ?string}
     */
    public static function check(array $fields): array
    {
        $text = function (string ...$keys) use ($fields): ?string {
            foreach ($keys as $key) {
                if (isset($fields[$key]) && is_scalar($fields[$key]) && trim((string) $fields[$key]) !== '') {
                    return trim((string) $fields[$key]);
                }
            }

            return null;
        };

        $title = $text('title');
        if ($title === null) {
            throw new InvalidArgumentException('Give the job a title.');
        }
        if (mb_strlen($title) > 150) {
            throw new InvalidArgumentException('The title is too long (150 characters at most).');
        }

        // A client you merged into another (a form loaded before the merge) is the one it was merged into.
        $clientId = isset($fields['client_id']) && $fields['client_id'] !== '' ? (int) $fields['client_id'] : null;
        if ($clientId !== null && ! Client::query()->whereKey($clientId)->exists()) {
            throw new InvalidArgumentException('That client isn’t in your list.');
        }
        $clientId = ClientIds::resolve($clientId);
        $clientName = $clientId ? null : $text('client_name', 'client', 'new_client');
        $endClientId = isset($fields['end_client_id']) && $fields['end_client_id'] !== '' ? (int) $fields['end_client_id'] : null;
        if ($endClientId !== null && ! Client::query()->whereKey($endClientId)->exists()) {
            throw new InvalidArgumentException('That end client isn’t in your list.');
        }
        $endClientId = ClientIds::resolve($endClientId);
        $endClientName = $endClientId ? null : $text('end_client_name', 'new_end_client');

        $time = function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (! is_string($v) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) {
                throw new InvalidArgumentException('Give times as HH:MM (24-hour).');
            }

            return substr($v, 0, 5);
        };

        // Its days: the dates listed (each with its kind, and its own times and note if it has them), or every day
        // from the first to the last. Which calendar entry a day came from is never taken from here: only the calendar
        // pick (DayPick) sets it, from the entries it links.
        $dates = null;
        if (! empty($fields['dates']) && is_array($fields['dates'])) {
            $dates = [];
            foreach ($fields['dates'] as $d) {
                $date = self::date(is_array($d) ? ($d['date'] ?? null) : $d, 'date');
                $kind = is_array($d) && array_key_exists((string) ($d['kind'] ?? ''), JobDay::KINDS) ? $d['kind'] : 'work';
                $day = ['date' => $date, 'kind' => $kind];
                if (is_array($d)) {
                    $note = isset($d['note']) && is_scalar($d['note']) && trim((string) $d['note']) !== '' ? trim((string) $d['note']) : null;
                    if ($note !== null && mb_strlen($note) > 200) {
                        throw new InvalidArgumentException('A day’s note is too long (200 characters at most).');
                    }
                    $day += array_filter(['start_time' => $time($d['start_time'] ?? null), 'end_time' => $time($d['end_time'] ?? null), 'note' => $note], fn ($v) => $v !== null);
                }
                $dates[$date] = $day;
            }
            ksort($dates);
            $dates = array_values($dates);
            $starts = $dates[0]['date'];
            $ends = $dates[count($dates) - 1]['date'];
        } else {
            $starts = self::date($fields['starts_on'] ?? null, 'start date');
            $ends = isset($fields['ends_on']) && $fields['ends_on'] !== '' && $fields['ends_on'] !== null ? self::date($fields['ends_on'], 'end date') : $starts;
        }
        if ($ends < $starts) {
            throw new InvalidArgumentException('The job can’t end before it starts.');
        }
        if ((int) CarbonImmutable::parse($starts)->diffInDays(CarbonImmutable::parse($ends)) + 1 > JobDays::MAX_DAYS) {
            throw new InvalidArgumentException('A job can cover at most '.JobDays::MAX_DAYS.' days. Make it separate jobs.');
        }

        $status = $text('status') ?? 'booked';
        if (! in_array($status, ['booked', 'done', 'cancelled'], true)) {
            throw new InvalidArgumentException('A job is booked, done or cancelled.');
        }

        // A fee for the whole job is the job's fee; a day or hour rate in pounds is the job's rate (what the job pays
        // depends on its days or hours). A rate in another currency, or a second rate, is noted.
        $fee = isset($fields['fee_pence']) && is_int($fields['fee_pence']) ? $fields['fee_pence'] : null;
        $ratePence = isset($fields['rate_pence']) && $fields['rate_pence'] !== '' ? $fields['rate_pence'] : null;
        $rateUnit = $text('rate_unit'); // a unit with no amount (the form's default) is no rate
        if ($ratePence !== null && (! is_int($ratePence) || $ratePence <= 0 || ! in_array($rateUnit, WorkJob::RATE_UNITS, true))) {
            throw new InvalidArgumentException('Give the rate as an amount above zero, a day or an hour.');
        }
        $notes = $text('notes', 'note');
        $rate = is_array($fields['rate'] ?? null) && is_int($fields['rate']['amount_minor'] ?? null) && $fields['rate']['amount_minor'] > 0 ? $fields['rate'] : null;
        if ($rate) {
            $currency = preg_match('/^[A-Z]{3}$/', (string) ($rate['currency'] ?? '')) ? $rate['currency'] : 'GBP';
            $unit = in_array($rate['unit'] ?? null, ['day', 'job', 'hour'], true) ? $rate['unit'] : 'job';
            if ($unit === 'job' && $currency === 'GBP' && $fee === null) {
                $fee = $rate['amount_minor'];
            } elseif ($unit !== 'job' && $currency === 'GBP' && $ratePence === null) {
                [$ratePence, $rateUnit] = [$rate['amount_minor'], $unit];
            } else {
                $per = ['day' => ' a day', 'hour' => ' an hour', 'job' => ' for the job'][$unit];
                $line = 'Rate: '.Money::formatMinor($rate['amount_minor'], $currency, false).$per;
                $notes = $notes ? (str_contains($notes, $line) ? $notes : $notes."\n".$line) : $line;
            }
        }
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('The notes are too long (2,000 characters at most).');
        }

        // The days agreed: given, or (with a fee or a day rate) the job's working days, travel days left out. Nothing
        // fills them later on its own.
        $agreed = isset($fields['days_agreed']) && $fields['days_agreed'] !== '' ? $fields['days_agreed'] : null;
        if ($agreed !== null && (! is_int($agreed) || $agreed < 1 || $agreed > JobDays::MAX_DAYS)) {
            throw new InvalidArgumentException('The days agreed are a whole number from 1 to '.JobDays::MAX_DAYS.'.');
        }
        if ($agreed === null && ($fee !== null || ($ratePence !== null && $rateUnit === 'day'))) {
            $working = $dates !== null ? count(array_filter($dates, fn (array $d) => $d['kind'] !== 'travel'))
                : (int) CarbonImmutable::parse($starts)->diffInDays(CarbonImmutable::parse($ends)) + 1;
            $agreed = $working > 0 ? $working : null;
        }

        $location = $text('location', 'venue');
        $postcode = $text('postcode');
        $po = $text('po_number');

        return [
            'title' => $title,
            'client_id' => $clientId,
            'client_name' => $clientName !== null ? mb_substr($clientName, 0, 120) : null,
            'end_client_id' => $endClientId,
            'end_client_name' => $endClientName !== null ? mb_substr($endClientName, 0, 120) : null,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'dates' => $dates,
            'start_time' => $time($fields['start_time'] ?? null),
            'end_time' => $time($fields['end_time'] ?? null),
            'location' => $location !== null ? mb_substr($location, 0, 200) : null,
            'postcode' => $postcode !== null ? mb_substr(strtoupper($postcode), 0, 10) : null,
            'fee_pence' => $fee,
            'rate_pence' => $ratePence,
            'rate_unit' => $ratePence !== null ? $rateUnit : null,
            'days_agreed' => $agreed,
            'po_number' => $po !== null ? mb_substr($po, 0, 100) : null,
            'po_not_required' => (bool) ($fields['po_not_required'] ?? false),
            'status' => $status,
            'notes' => $notes,
        ];
    }

    /**
     * A new job gets every day from its first to last (JobDays::fromRange). With dates listed, only those days are
     * made (create() skips the range), each with its kind (a rig, then a de-rig days later) and any times and note
     * given for it, as days you set.
     *
     * @param  ?list<array{date: string, kind: string, start_time?: string, end_time?: string, note?: string}>  $dates
     */
    private static function setDays(WorkJob $job, ?array $dates): void
    {
        if ($dates === null) {
            return;
        }
        $wanted = array_column($dates, null, 'date');
        $fields = fn (array $d) => ['kind' => $d['kind'], 'start_time' => $d['start_time'] ?? null, 'end_time' => $d['end_time'] ?? null, 'note' => $d['note'] ?? null];
        foreach (JobDay::query()->where('work_job_id', $job->id)->get() as $day) {
            $date = $day->on_date->format('Y-m-d');
            if (! isset($wanted[$date])) {
                $day->delete();
            } else {
                $day->update(array_filter($fields($wanted[$date]), fn ($v) => $v !== null) + ['source' => 'you']);
                unset($wanted[$date]);
            }
        }
        foreach ($wanted as $date => $d) {
            // Named after its calendar entry only when the days were picked on the calendar (JobDays::withCalendarKeys).
            JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => $date, 'source' => 'you', 'calendar_key' => JobDays::pendingKey($date)] + $fields($d));
        }
        $job->unsetRelation('days');
    }

    /**
     * The job's days for its history: WorkJob::dayList(), with a day's note and the calendar entry it's named after
     * only when it has them, so a job made without either is recorded exactly as before.
     *
     * @return list<array{date: string, kind: string, start_time: ?string, end_time: ?string, note?: string, calendar_key?: string}>
     */
    private static function dayList(WorkJob $job): array
    {
        $extra = [];
        foreach (JobDay::query()->where('work_job_id', $job->id)->get(['on_date', 'note', 'calendar_key']) as $day) {
            $extra[$day->on_date->format('Y-m-d')] = array_filter(['note' => $day->note, 'calendar_key' => $day->calendar_key], fn ($v) => $v !== null && $v !== '');
        }

        return array_map(fn (array $d) => $d + ($extra[$d['date']] ?? []), $job->dayList());
    }

    private static function date(mixed $value, string $what): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Give the job its '.$what.'.');
        }
        try {
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))
                ? CarbonImmutable::createFromFormat('!Y-m-d', trim($value))
                : CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            $d = null;
        }
        if (! $d || (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) && $d->format('Y-m-d') !== trim($value))) {
            throw new InvalidArgumentException('“'.mb_substr($value, 0, 40).'” isn’t a date.');
        }

        return $d->format('Y-m-d');
    }

    /** What it was made from, short enough to keep in the job's history. */
    private static function evidenceForHistory(array $evidence): array
    {
        return array_values(array_map(fn ($e) => is_array($e) ? array_filter([
            'type' => $e['type'] ?? null, 'id' => $e['id'] ?? null, 'field' => $e['field'] ?? null,
            'quote' => isset($e['quote']) ? mb_substr((string) $e['quote'], 0, 300) : null,
            'verified' => isset($e['verified']) ? (bool) $e['verified'] : null,
        ], fn ($v) => $v !== null) : ['note' => mb_substr((string) $e, 0, 300)], array_slice($evidence, 0, 40)));
    }
}
