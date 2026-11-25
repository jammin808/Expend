<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Who you work for. A job's client_id is the paying client (who you invoice and who pays, such as a crewing agency);
 * a job's end_client_id is who the work was for, when that's someone else.
 *
 * A duplicate you merged keeps its row with merged_into_id set to the client it was merged into (always one step:
 * clients merged into it are re-pointed). Merges are resolved when read, and clients are never deleted.
 */
class Client extends Model
{
    /** How an invoice goes to them: in the booking conversation (the default), as a new email, or not at all. */
    public const INVOICE_ROUTES = ['thread', 'new', 'none'];

    protected $fillable = [
        'name', 'notes', 'invoice_name', 'billing_email', 'billing_address', 'payment_terms_days', 'invoice_route', 'merged_into_id',
    ];

    protected $casts = ['payment_terms_days' => 'integer', 'merged_into_id' => 'integer'];

    /** Jobs they pay for. */
    public function jobs(): HasMany
    {
        return $this->hasMany(WorkJob::class);
    }

    /** Jobs done for them through someone else (they're the end client, another client pays). */
    public function endClientJobs(): HasMany
    {
        return $this->hasMany(WorkJob::class, 'end_client_id');
    }

    /** The client this one was merged into, if it was. */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'merged_into_id');
    }

    /** The duplicates merged into this client. */
    public function merged(): HasMany
    {
        return $this->hasMany(Client::class, 'merged_into_id');
    }

    /** How they're charged for each kind of cost, by default for their jobs (a job's own row wins). */
    public function chargeTerms(): HasMany
    {
        return $this->hasMany(ChargeTerm::class);
    }

    /** thread when nothing was chosen. */
    public function invoiceRoute(): string
    {
        return in_array($this->invoice_route, self::INVOICE_ROUTES, true) ? $this->invoice_route : 'thread';
    }

    public function isMerged(): bool
    {
        return $this->merged_into_id !== null;
    }

    /** Clients that weren't merged into another: the ones pickers and lists offer. */
    public function scopeUnmerged(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('merged_into_id'));
    }
}
