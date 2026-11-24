<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something you said no to, so nothing automatic proposes it again (docs/intelligence-cycle.md 3.2, M7). Written and
 * lifted only through App\Domain\Intel\Vetoes, which audits both.
 *
 * @property string $veto_key sha256 of subject, relation, object, shape and method
 * @property string $subject_ref what it's about: tx:381, travel:4, inv:9, job:14
 * @property string $relation what kind of link (RELATIONS)
 * @property string|null $object_ref what it was linked to (tx:382, inv:9, job:14), or null
 * @property string|null $shape a decision's shape (Vetoes::shape(): kind, category, business share and job), or null
 * @property string|null $method the automatic method it holds back, or null for every one
 * @property string $effect block (never propose it) or ask (propose it, never act on it)
 * @property string|null $reason_code
 * @property string|null $reason
 * @property int|null $audit_event_id the audit event that recorded it
 * @property Carbon|null $lifted_at when you lifted it (it no longer counts)
 */
class Veto extends Model
{
    /** What a veto can be about: a decision's shape on a line, or a link between two things. */
    public const RELATIONS = [
        'allocation_shape' => 'what a payment is',
        'transfer_pair' => 'two payments as one transfer',
        'invoice_payment' => 'a payment for an invoice',
        'travel_payment' => 'a payment for a stay or journey',
        'invoice_job' => 'an invoice on a job',
        'mileage_day' => 'mileage for a job',
        'receipt_payment' => 'a receipt for a payment',
    ];

    public const EFFECTS = ['block', 'ask'];

    protected $table = 'vetoes';

    protected $guarded = ['id'];

    protected $casts = ['lifted_at' => 'datetime', 'audit_event_id' => 'integer'];

    /** @param  Builder<Veto>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null;
    }
}
