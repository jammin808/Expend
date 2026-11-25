<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Your own decision about one cost, keyed by the cost's ref ('tx:381#j45', 'exp:12#j45', 'travel:7', 'trip:3',
 * 'tx:381#c9'): whether it's charged (recharge, no_charge, elsewhere, or invoiced on a given invoice), whether two
 * records are the same cost (pair same, with pair_ref) or not (distinct), and whether a cost you claimed from the
 * client was paid back (outcome). subject_ref is the record behind the cost (tx:381), so one payment is never charged
 * twice across jobs.
 *
 * Only you write these (App\Domain\Charges\CostCharges, audited). When a cost has several rows (its ref and its
 * aliases), the newest decision wins.
 */
class CostCharge extends Model
{
    public const CHARGES = ['recharge', 'no_charge', 'elsewhere', 'invoiced'];

    public const PAIRS = ['same', 'distinct'];

    public const OUTCOMES = ['paid_back', 'not_paid'];

    protected $guarded = ['id'];

    protected $casts = ['client_id' => 'integer', 'invoice_id' => 'integer', 'audit_event_id' => 'integer', 'outcome_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (CostCharge $row) {
            foreach (['charge' => self::CHARGES, 'pair' => self::PAIRS, 'outcome' => self::OUTCOMES] as $field => $allowed) {
                if ($row->{$field} !== null && ! in_array($row->{$field}, $allowed, true)) {
                    throw new InvalidArgumentException("A cost's {$field} can't be '{$row->{$field}}'.");
                }
            }
            if (($row->pair === null) !== ($row->pair_ref === null)) {
                throw new InvalidArgumentException('A pairing names the other cost, and only a pairing does.');
            }
            if ($row->charge === 'invoiced' && $row->invoice_id === null) {
                throw new InvalidArgumentException('Say which invoice the cost was on.');
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
