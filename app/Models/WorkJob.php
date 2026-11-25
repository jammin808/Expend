<?php

namespace App\Models;

use App\Domain\Audit\AuditLog;
use App\Domain\Jobs\JobDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of work (a "job" in the UI). Named WorkJob to avoid Laravel's queue jobs table.
 *
 * client_id is the paying client (who you invoice and who pays); end_client_id is who the work was for when that's
 * someone else, stored as null when it's the same client. A day or hour rate is rate_pence with rate_unit; a rate for
 * the whole job stays fee_pence.
 */
class WorkJob extends Model
{
    public const RATE_UNITS = ['day', 'hour'];

    /** What a cancelled job is charged: nothing, a cancellation fee, or its costs. */
    public const CANCEL_CHARGES = ['none', 'fee', 'costs'];

    protected $fillable = [
        'client_id', 'title', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'postcode',
        'fee_pence', 'po_number', 'po_not_required', 'status', 'notes', 'gathered_at', 'gather_state',
        'end_client_id', 'rate_pence', 'rate_unit', 'days_agreed', 'done_at', 'cancel_charge', 'cancel_fee_pence',
    ];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'fee_pence' => 'integer', 'po_not_required' => 'boolean', 'gathered_at' => 'datetime', 'gather_state' => 'array',
        'end_client_id' => 'integer', 'rate_pence' => 'integer', 'days_agreed' => 'integer', 'done_at' => 'datetime', 'cancel_fee_pence' => 'integer',
    ];

    protected static function booted(): void
    {
        // The end client is only someone other than the paying client.
        static::saving(function (WorkJob $job) {
            if ($job->end_client_id !== null && $job->client_id !== null && (int) $job->end_client_id === (int) $job->client_id) {
                $job->end_client_id = null;
            }
        });
        static::created(fn (WorkJob $job) => JobDays::fromRange($job));
        static::updated(function (WorkJob $job) {
            if ($job->wasChanged(['starts_on', 'ends_on'])) {
                JobDays::rangeChanged($job, CarbonImmutable::parse($job->getOriginal('starts_on'))->format('Y-m-d'), CarbonImmutable::parse($job->getOriginal('ends_on'))->format('Y-m-d'));
            }
            if ($job->wasChanged(['start_time', 'end_time'])) {
                $job->timesChanged();
            }
        });
    }

    /** The days the job covers, which needn't be consecutive. */
    public function days(): HasMany
    {
        return $this->hasMany(JobDay::class)->orderBy('on_date');
    }

    /** @return list<string> the dates worked (Y-m-d), oldest first */
    public function workDates(): array
    {
        $days = $this->relationLoaded('days') ? $this->days : $this->days()->get();
        if ($days->isNotEmpty()) {
            return $days->map(fn (JobDay $d) => $d->on_date->format('Y-m-d'))->values()->all();
        }
        $out = [];
        for ($d = CarbonImmutable::parse($this->starts_on->format('Y-m-d')), $i = 0; $d->lte($this->ends_on) && $i < JobDays::MAX_DAYS; $d = $d->addDay(), $i++) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    /**
     * Times are kept to the minute: MariaDB gives a TIME column back as "23:00:00", so saving the form's "23:00" over
     * it is no change (and moves no day's time).
     */
    public function setStartTimeAttribute(mixed $value): void
    {
        $this->attributes['start_time'] = $this->sameMinute('start_time', $value);
    }

    public function setEndTimeAttribute(mixed $value): void
    {
        $this->attributes['end_time'] = $this->sameMinute('end_time', $value);
    }

    private function sameMinute(string $field, mixed $value): ?string
    {
        $value = $value === null || $value === '' ? null : (string) $value;
        $old = $this->original[$field] ?? null;

        return $value !== null && $old !== null && substr((string) $old, 0, 5) === substr($value, 0, 5) ? (string) $old : $value;
    }

    /**
     * The job's start or finish time was changed: its first day's start (or last day's finish) follows when the day
     * still has the job's old time, so the time you type is the one the night-before and night-after checks use. On a
     * job of one day, a time copied from a calendar entry gives way too (the job's time is that day's). On a job of
     * several days, a different time on its first or last day is that day's own (a de-rig's finish), and a time you
     * set on a day yourself is always kept.
     */
    private function timesChanged(): void
    {
        $days = $this->days()->get();
        $single = $days->count() === 1;
        foreach (['start_time' => $days->first(), 'end_time' => $days->last()] as $field => $day) {
            $old = $this->getOriginal($field) ? substr((string) $this->getOriginal($field), 0, 5) : null;
            $new = $this->{$field} ? substr((string) $this->{$field}, 0, 5) : null;
            if (! $day || $old === $new || $day->{$field} === null) {
                continue;
            }
            $current = substr((string) $day->{$field}, 0, 5);
            $follows = $current === $old || ($single && $day->source !== 'you');
            if (! $follows || $current === $new) {
                continue;
            }
            $before = $day->only([$field]);
            $day->update([$field => $new]);
            AuditLog::record('job.day_updated', $this, ['date' => $day->on_date->format('Y-m-d')] + $before, ['date' => $day->on_date->format('Y-m-d')] + $day->only([$field]), 'Follows the job’s '.($field === 'start_time' ? 'start' : 'finish').' time');
        }
        $this->unsetRelation('days');
    }

    public function worksOn(string $date): bool
    {
        return in_array($date, $this->workDates(), true);
    }

    /**
     * Whether the date is one of the job's days or within $margin days of one (the day before or after: travel there
     * and back). The days between a rig and a de-rig a week later aren't near it.
     */
    public function isNear(string $date, int $margin = 1): bool
    {
        $d = CarbonImmutable::parse($date);
        $dates = array_flip($this->workDates());
        for ($i = -$margin; $i <= $margin; $i++) {
            if (isset($dates[$d->addDays($i)->format('Y-m-d')])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{date:string, kind:string, start_time:?string, end_time:?string}> its days, for the assistant and the job page */
    public function dayList(): array
    {
        $days = $this->relationLoaded('days') ? $this->days : $this->days()->get();

        return $days->map(fn (JobDay $d) => ['date' => $d->on_date->format('Y-m-d'), 'kind' => $d->kind, 'start_time' => $d->start_time, 'end_time' => $d->end_time])->values()->all();
    }

    public function dayOn(string $date): ?JobDay
    {
        return ($this->relationLoaded('days') ? $this->days : $this->days()->get())->first(fn (JobDay $d) => $d->on_date->format('Y-m-d') === $date);
    }

    /** The paying client: who you invoice and who pays. */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Who the work was for, when that isn't the paying client (a production company or venue). */
    public function endClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'end_client_id');
    }

    /** How this job charges each kind of cost, where it differs from the client's usual. */
    public function chargeTerms(): HasMany
    {
        return $this->hasMany(ChargeTerm::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function mileageTrips(): HasMany
    {
        return $this->hasMany(MileageTrip::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'work_job_id');
    }
}
