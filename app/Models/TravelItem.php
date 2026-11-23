<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TravelItem extends Model
{
    public const KINDS = [
        'hotel' => 'Hotel', 'rail' => 'Train', 'taxi' => 'Taxi or ride', 'flight' => 'Flight', 'car_hire' => 'Car hire',
        'coach' => 'Coach or bus', 'parking' => 'Parking', 'other' => 'Other travel',
    ];

    /** The ledger category each kind of business travel goes to. */
    public const CATEGORY = [
        'hotel' => 'travel_accommodation', 'rail' => 'travel_rail', 'taxi' => 'travel_taxi', 'flight' => 'travel_flights',
        'car_hire' => 'travel_car_hire', 'coach' => 'travel_local', 'parking' => 'travel_parking', 'other' => 'travel_local',
    ];

    public const DECISIONS = [
        'business' => 'Business', 'personal' => 'Personal', 'mixed' => 'Part business', 'client_booked' => 'Booked and paid by the client',
        'recharge' => 'Business, recharged to the client',
    ];

    protected $fillable = [
        'kind', 'provider', 'reference', 'status', 'starts_on', 'ends_on', 'start_time', 'place', 'destination', 'postcode',
        'amount_minor', 'currency', 'payment', 'booked_for', 'booker', 'source', 'evidence', 'unverified_count', 'document_id', 'source_sent_at',
        'bank_transaction_id', 'work_job_id', 'job_linked_by', 'suggestion', 'decision', 'business_nights', 'decided_at', 'note',
    ];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'amount_minor' => 'integer', 'evidence' => 'array', 'suggestion' => 'array',
        'decided_at' => 'datetime', 'source_sent_at' => 'datetime', 'business_nights' => 'integer', 'unverified_count' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    /** Nights of a hotel stay (the evening dates), or the single travel day for a journey. */
    public function nights(): array
    {
        $end = $this->ends_on && $this->ends_on->gt($this->starts_on) ? $this->ends_on : $this->starts_on->copy()->addDay();
        $nights = [];
        for ($d = $this->starts_on->copy(); $d->lt($end) && count($nights) < 60; $d->addDay()) {
            $nights[] = $d->format('Y-m-d');
        }

        return $nights;
    }
}
