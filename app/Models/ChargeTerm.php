<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * How one kind of cost is charged, for a client (their usual, for every job of theirs) or for one job (where it
 * differs). One row per owner and kind of cost. A job's row wins for its own kind only, so arrangements mix: trains
 * at cost, driving per mile, hotels not charged.
 *
 * Only you write these (App\Domain\Charges\ChargeTermsWriter, audited as charge_terms.updated). They aren't ledger
 * data: no tax figure reads them, and locked years don't block them.
 */
class ChargeTerm extends Model
{
    /**
     * Each kind of cost and how it may be charged.
     *   per_mile, per_job, per_day: at rate_minor (each mile, once a job, each day)
     *   at_cost: what it cost
     *   their_form: the client pays it through their own expense form, never the invoice
     *   day_rate: travel days at the job's day rate
     *   none: not charged
     */
    public const CHARGES = [
        'mileage' => ['per_mile', 'their_form', 'none'],
        'rail' => ['at_cost', 'their_form', 'none'],
        'bus' => ['at_cost', 'their_form', 'none'],
        'flight' => ['at_cost', 'their_form', 'none'],
        'taxi' => ['at_cost', 'their_form', 'none'],
        'car_hire' => ['at_cost', 'their_form', 'none'],
        'parking' => ['at_cost', 'their_form', 'none'],
        'hotel' => ['at_cost', 'their_form', 'none'],
        'other' => ['at_cost', 'their_form', 'none'],
        'fuel' => ['at_cost', 'their_form', 'none'],
        'meals' => ['at_cost', 'per_day', 'their_form', 'none'],
        'fines' => ['at_cost', 'none'],
        'travel_fee' => ['per_job', 'per_day', 'none'],
        'travel_days' => ['day_rate', 'per_day', 'none'],
    ];

    /** Charges that need a rate above 0. Mileage on their form needs one too (what their form pays a mile). */
    public const RATED = ['per_mile', 'per_job', 'per_day'];

    protected $guarded = ['id'];

    protected $casts = ['client_id' => 'integer', 'work_job_id' => 'integer', 'rate_minor' => 'integer'];

    protected static function booted(): void
    {
        static::saving(fn (ChargeTerm $term) => $term->check());
    }

    /** 'client:{id}' or 'job:{id}'. */
    public static function ownerKey(Client|WorkJob $owner): string
    {
        return ($owner instanceof Client ? 'client:' : 'job:').$owner->getKey();
    }

    /** The rows of one client or one job. */
    public function scopeOwnedBy(Builder $query, Client|WorkJob $owner): Builder
    {
        return $query->where('owner_key', self::ownerKey($owner));
    }

    public static function needsRate(string $class, string $charge): bool
    {
        return in_array($charge, self::RATED, true) || ($class === 'mileage' && $charge === 'their_form');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    private function check(): void
    {
        if (($this->client_id === null) === ($this->work_job_id === null)) {
            throw new InvalidArgumentException('A charge arrangement belongs to one client or one job.');
        }
        $key = $this->client_id !== null ? "client:{$this->client_id}" : "job:{$this->work_job_id}";
        $this->owner_key ??= $key;
        if ($this->owner_key !== $key) {
            throw new InvalidArgumentException("A charge arrangement for {$key} can't be filed as '{$this->owner_key}'.");
        }
        $allowed = self::CHARGES[$this->class] ?? null;
        if ($allowed === null) {
            throw new InvalidArgumentException("'{$this->class}' isn't a kind of cost.");
        }
        if (! in_array($this->charge, $allowed, true)) {
            throw new InvalidArgumentException("'{$this->class}' can't be charged '{$this->charge}'.");
        }
        if (self::needsRate($this->class, $this->charge) && ($this->rate_minor === null || $this->rate_minor <= 0)) {
            throw new InvalidArgumentException("'{$this->class}' charged '{$this->charge}' needs a rate above 0.");
        }
    }
}
