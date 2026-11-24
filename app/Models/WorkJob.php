<?php

namespace App\Models;

use App\Domain\Jobs\JobDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of work (a "job" in the UI). Named WorkJob to avoid Laravel's queue jobs table. */
class WorkJob extends Model
{
    protected $fillable = [
        'client_id', 'title', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'postcode',
        'fee_pence', 'po_number', 'po_not_required', 'status', 'notes', 'gathered_at', 'gather_state',
    ];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'fee_pence' => 'integer', 'po_not_required' => 'boolean', 'gathered_at' => 'datetime', 'gather_state' => 'array'];

    protected static function booted(): void
    {
        static::created(fn (WorkJob $job) => JobDays::fromRange($job));
        static::updated(function (WorkJob $job) {
            if ($job->wasChanged(['starts_on', 'ends_on'])) {
                JobDays::rangeChanged($job, CarbonImmutable::parse($job->getOriginal('starts_on'))->format('Y-m-d'), CarbonImmutable::parse($job->getOriginal('ends_on'))->format('Y-m-d'));
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
        for ($d = CarbonImmutable::parse($this->starts_on->format('Y-m-d')), $i = 0; $d->lte($this->ends_on) && $i < 62; $d = $d->addDay(), $i++) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }

    public function worksOn(string $date): bool
    {
        return in_array($date, $this->workDates(), true);
    }

    public function dayOn(string $date): ?JobDay
    {
        return ($this->relationLoaded('days') ? $this->days : $this->days()->get())->first(fn (JobDay $d) => $d->on_date->format('Y-m-d') === $date);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
