<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'raw', 'fingerprint', 'occurrence',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'posted_on' => 'date',
        'transaction_on' => 'date',
        'raw' => 'array',
        'amount_pence' => 'integer',
        'balance_after_pence' => 'integer',
        'original_amount_minor' => 'integer',
    ];

    protected static function booted(): void
    {
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

    /** The date that decides the tax year: the card transaction date where known, otherwise the posting date. */
    public function effectiveDate(): CarbonInterface
    {
        return $this->transaction_on ?? $this->posted_on;
    }
}
