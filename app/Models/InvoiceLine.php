<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * One line of an invoice. A line you typed (or one made before Expend tracked where lines come from) has no source.
 * A source line follows what it came from (the job's fee or days, a cost, a trip) while the invoice is a draft,
 * keeping whatever you changed yourself: own_text (its description), own_amount (its quantity and price) and
 * ticked_by 'you' (its tick). Only ticked lines (included) are charged: Invoice::lines() gives those.
 *
 * Lines can change only while their invoice is a draft, as its stored row says: once it's issued, paid or void they
 * are fixed, like the invoice itself.
 */
class InvoiceLine extends Model
{
    /** Where a source line comes from (null: typed, or made before this release). */
    public const SOURCES = ['po', 'fee', 'days', 'hours', 'extra', 'tdays', 'tfee', 'meals', 'cancel', 'mileage', 'cost'];

    /** Why a line needs a look before the invoice is issued. */
    public const FLAGS = ['gone', 'unconfirmed', 'no_payment', 'no_amount', 'maybe_same', 'check'];

    protected $guarded = ['id'];

    protected $casts = [
        'unit_price_minor' => 'integer', 'amount_minor' => 'integer', 'quantity' => 'decimal:2',
        'source_job_id' => 'integer', 'included' => 'boolean', 'own_text' => 'boolean', 'own_amount' => 'boolean',
        'source_amount_minor' => 'integer', 'source_quantity' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(function (InvoiceLine $line) {
            self::assertDraft($line);
            if ($line->source !== null && ! in_array($line->source, self::SOURCES, true)) {
                throw new InvalidArgumentException("An invoice line can't come from '{$line->source}'.");
            }
            if ($line->flag !== null && ! in_array($line->flag, self::FLAGS, true)) {
                throw new InvalidArgumentException("An invoice line can't be flagged '{$line->flag}'.");
            }
            if ($line->ticked_by !== null && $line->ticked_by !== 'you') {
                throw new InvalidArgumentException('Only you tick or untick a line yourself.');
            }
        });
        static::deleting(fn (InvoiceLine $line) => self::assertDraft($line));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sourceJob(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'source_job_id');
    }

    /**
     * The invoice's status as stored, not as a model in memory says: a draft loaded before it was issued elsewhere
     * can't be used to change an issued invoice's lines.
     */
    private static function assertDraft(InvoiceLine $line): void
    {
        // A line moved from one invoice to another changes both.
        $ids = array_unique(array_filter([$line->invoice_id, $line->exists ? $line->getOriginal('invoice_id') : null], fn ($id) => $id !== null));
        $drafts = $ids === [] ? 0 : Invoice::query()->whereKey($ids)->where('status', 'draft')->count();
        if ($ids === [] || $drafts !== count($ids)) {
            throw new LogicException('Lines of an issued invoice can’t change. Void it and issue a new one.');
        }
    }
}
