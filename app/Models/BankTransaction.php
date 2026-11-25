<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A row exactly as imported from a statement. The financial facts are immutable;
 * only review state and transfer pairing can change. Meaning lives in allocations.
 */
class BankTransaction extends Model
{
    public const IMMUTABLE = [
        'account_id', 'import_batch_id', 'posted_on', 'transaction_on', 'description', 'counterparty',
        'reference', 'amount_pence', 'balance_after_pence', 'original_amount_minor', 'original_currency',
        'raw', 'fingerprint', 'occurrence', 'transacted_time', 'place', 'card_last4', 'payment_type',
        'balance_after_minor', 'fee_minor', 'merchant_amount_minor', 'merchant_currency',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'posted_on' => 'date',
        'transaction_on' => 'date',
        'raw' => 'array',
        'amount_pence' => 'integer',
        'balance_after_pence' => 'integer',
        'original_amount_minor' => 'integer',
        'balance_after_minor' => 'integer',
        'fee_minor' => 'integer',
        'merchant_amount_minor' => 'integer',
        'fx_rate_date' => 'date',
    ];

    public const STATES = ['active', 'quarantined', 'reversed'];

    protected static function booted(): void
    {
        // Only active rows count anywhere in the app. Quarantined and reversed rows are kept, and reached
        // deliberately with withoutGlobalScope('active') (import review, undo and restore).
        static::addGlobalScope('active', fn ($query) => $query->where('bank_transactions.state', 'active'));

        static::updating(function (BankTransaction $tx) {
            foreach (self::IMMUTABLE as $field) {
                if ($tx->isDirty($field)) {
                    throw new LogicException("Imported transactions can't be edited ({$field}). Add or change an allocation instead.");
                }
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function transferPair(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'transfer_pair_id');
    }

    /** Your receipt photo from the phone that is on this line (a business-card QuickEntry attached to it), if any. */
    public function quickReceipt(): HasOne
    {
        return $this->hasOne(QuickEntry::class)->where('status', 'attached')->whereNotNull('document_id')->orderBy('id');
    }

    /** The date that decides the tax year: the card transaction date where known, otherwise the posting date. */
    public function effectiveDate(): CarbonInterface
    {
        return $this->transaction_on ?? $this->posted_on;
    }
}
