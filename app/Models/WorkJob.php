<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of work (a "job" in the UI). Named WorkJob to avoid Laravel's queue jobs table. */
class WorkJob extends Model
{
    protected $fillable = [
        'client_id', 'title', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location', 'postcode',
        'fee_pence', 'po_number', 'po_not_required', 'status', 'notes',
    ];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'fee_pence' => 'integer', 'po_not_required' => 'boolean'];

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
