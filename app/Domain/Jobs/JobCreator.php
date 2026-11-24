<?php

namespace App\Domain\Jobs;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Grounding;
use App\Jobs\GatherForJob;
use App\Models\Client;
use App\Models\JobDay;
use App\Models\WorkJob;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
 */
final class JobCreator
{
    /** Who can make a job: you, in the app, or through an instruction you gave the assistant or Telegram. */
    public const OWNER = ['you', 'assistant', 'telegram'];

    /**
     * @param  array{
     *     title: string,
     *     client_id?: ?int, client_name?: ?string, client?: ?string,
     *     starts_on?: ?string, ends_on?: ?string, dates?: list<string|array{date: string, kind?: string}>,
     *     start_time?: ?string, end_time?: ?string,
     *     venue?: ?string, location?: ?string, postcode?: ?string,
     *     po_number?: ?string, po_not_required?: bool,
     *     rate?: ?array{amount_minor: int, currency?: ?string, unit?: ?string}, fee_pence?: ?int,
     *     note?: ?string, notes?: ?string, status?: ?string,
     * }  $fields  a "rate" for the whole job becomes its fee; a day or hour rate goes in its notes
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
            $clientId = $data['client_id'] ?? null;
            if (! $clientId && $data['client_name'] !== null) {
                $clientId = self::client($data['client_name'], $by)->id;
            }
            $job = WorkJob::query()->create([
                'title' => $data['title'], 'client_id' => $clientId,
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'],
                'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
                'location' => $data['location'], 'postcode' => $data['postcode'],
                'fee_pence' => $data['fee_pence'], 'po_number' => $data['po_number'], 'po_not_required' => $data['po_not_required'],
                'status' => $data['status'], 'notes' => $data['notes'],
            ]);
            self::setDays($job, $data['dates']);

            AuditLog::record('job.created', $job, null, $job->fresh()->toArray() + [
                'days' => $job->dayList(),
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
     */
    public static function client(string $name, string $by = 'you'): Client
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            throw new InvalidArgumentException('Give the client a name.');
        }
        $name = mb_substr($name, 0, 120);
        $found = Client::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->orderBy('id')->first();
        if ($found) {
            return $found;
        }
        $key = self::clientKey($name);
        if ($key !== '') {
            $same = Client::query()->get(['id', 'name'])->filter(fn (Client $c) => self::clientKey($c->name) === $key);
            if ($same->count() === 1) {
                return Client::query()->findOrFail($same->first()->id);
            }
        }
        $client = Client::query()->create(['name' => $name]);
        AuditLog::record('client.created', $client, null, ['name' => $name], 'For a new job', $by);

        return $client;
    }

    /** A client's name for comparing: lower case, without Ltd, Limited, PLC, LLP or The, letters and digits only. */
    public static function clientKey(?string $name): string
    {
        return Grounding::normalise(preg_replace('/\b(ltd|limited|plc|llp|the)\b\.?/iu', ' ', (string) $name) ?? '');
    }

    /**
     * The details, checked and tidied.
     *
     * @return array{title: string, client_id: ?int, client_name: ?string, starts_on: string, ends_on: string,
     *     dates: ?list<array{date: string, kind: string}>, start_time: ?string, end_time: ?string, location: ?string,
     *     postcode: ?string, fee_pence: ?int, po_number: ?string, po_not_required: bool, status: string, notes: ?string}
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

        $clientId = isset($fields['client_id']) && $fields['client_id'] !== '' ? (int) $fields['client_id'] : null;
        if ($clientId !== null && ! Client::query()->whereKey($clientId)->exists()) {
            throw new InvalidArgumentException('That client isn’t in your list.');
        }
        $clientName = $clientId ? null : $text('client_name', 'client', 'new_client');

        // Its days: the dates listed (each with its kind), or every day from the first to the last.
        $dates = null;
        if (! empty($fields['dates']) && is_array($fields['dates'])) {
            $dates = [];
            foreach ($fields['dates'] as $d) {
                $date = self::date(is_array($d) ? ($d['date'] ?? null) : $d, 'date');
                $kind = is_array($d) && array_key_exists((string) ($d['kind'] ?? ''), JobDay::KINDS) ? $d['kind'] : 'work';
                $dates[$date] = ['date' => $date, 'kind' => $kind];
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

        $time = function (string $key) use ($fields): ?string {
            $v = $fields[$key] ?? null;
            if ($v === null || $v === '') {
                return null;
            }
            if (! is_string($v) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) {
                throw new InvalidArgumentException('Give times as HH:MM (24-hour).');
            }

            return substr($v, 0, 5);
        };

        $status = $text('status') ?? 'booked';
        if (! in_array($status, ['booked', 'done', 'cancelled'], true)) {
            throw new InvalidArgumentException('A job is booked, done or cancelled.');
        }

        // A fee for the whole job is the job's fee; a day or hour rate is noted (what the job pays depends on its days).
        $fee = isset($fields['fee_pence']) && is_int($fields['fee_pence']) ? $fields['fee_pence'] : null;
        $notes = $text('notes', 'note');
        $rate = is_array($fields['rate'] ?? null) && is_int($fields['rate']['amount_minor'] ?? null) && $fields['rate']['amount_minor'] > 0 ? $fields['rate'] : null;
        if ($rate) {
            $currency = preg_match('/^[A-Z]{3}$/', (string) ($rate['currency'] ?? '')) ? $rate['currency'] : 'GBP';
            $unit = in_array($rate['unit'] ?? null, ['day', 'job', 'hour'], true) ? $rate['unit'] : 'job';
            if ($unit === 'job' && $currency === 'GBP' && $fee === null) {
                $fee = $rate['amount_minor'];
            } else {
                $per = ['day' => ' a day', 'hour' => ' an hour', 'job' => ' for the job'][$unit];
                $line = 'Rate: '.Money::formatMinor($rate['amount_minor'], $currency, false).$per;
                $notes = $notes ? (str_contains($notes, $line) ? $notes : $notes."\n".$line) : $line;
            }
        }
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw new InvalidArgumentException('The notes are too long (2,000 characters at most).');
        }

        $location = $text('location', 'venue');
        $postcode = $text('postcode');
        $po = $text('po_number');

        return [
            'title' => $title,
            'client_id' => $clientId,
            'client_name' => $clientName !== null ? mb_substr($clientName, 0, 120) : null,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'dates' => $dates,
            'start_time' => $time('start_time'),
            'end_time' => $time('end_time'),
            'location' => $location !== null ? mb_substr($location, 0, 200) : null,
            'postcode' => $postcode !== null ? mb_substr(strtoupper($postcode), 0, 10) : null,
            'fee_pence' => $fee,
            'po_number' => $po !== null ? mb_substr($po, 0, 100) : null,
            'po_not_required' => (bool) ($fields['po_not_required'] ?? false),
            'status' => $status,
            'notes' => $notes,
        ];
    }

    /**
     * A new job gets every day from its first to last (JobDays::fromRange). With dates listed, only those days are
     * kept, each with its kind (a rig, then a de-rig days later), as days you set.
     *
     * @param  ?list<array{date: string, kind: string}>  $dates
     */
    private static function setDays(WorkJob $job, ?array $dates): void
    {
        if ($dates === null) {
            return;
        }
        $wanted = array_column($dates, 'kind', 'date');
        foreach (JobDay::query()->where('work_job_id', $job->id)->get() as $day) {
            $date = $day->on_date->format('Y-m-d');
            if (! isset($wanted[$date])) {
                $day->delete();
            } else {
                $day->update(['kind' => $wanted[$date], 'source' => 'you']);
                unset($wanted[$date]);
            }
        }
        foreach ($wanted as $date => $kind) {
            JobDay::query()->create(['work_job_id' => $job->id, 'on_date' => $date, 'kind' => $kind, 'source' => 'you']);
        }
        $job->unsetRelation('days');
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
