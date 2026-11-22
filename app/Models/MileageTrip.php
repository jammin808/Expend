<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MileageTrip extends Model
{
    protected $fillable = ['vehicle_id', 'work_job_id', 'trip_on', 'tax_year', 'from_place', 'to_place', 'miles', 'purpose'];

    protected $casts = ['trip_on' => 'date', 'miles' => 'decimal:1'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }
}
