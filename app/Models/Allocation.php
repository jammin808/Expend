<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a transaction (or part of one) means: business expense, income, personal, transfer...
 * Allocations for one source must sum exactly to the source amount.
 */
class Allocation extends Model
{
    public const KINDS = [
        'business_expense' => 'Business expense',
        'business_income' => 'Business income',
        'personal' => 'Personal',
        'transfer' => 'Transfer between my accounts',
        'tax_payment' => 'Tax payment to HMRC',
        'drawings' => 'Drawings',
        'capital_introduced' => 'Money put into the business',
        'loan' => 'Loan',
    ];

    public const NEEDS_CATEGORY = ['business_expense', 'business_income'];

    protected $guarded = ['id'];

    protected $casts = [
        'amount_pence' => 'integer', 'business_use_pct' => 'integer', 'is_manual' => 'boolean', 'confirmed_at' => 'datetime',
        'voided_at' => 'datetime',
        // Provenance (App\Domain\Intel\Provenance): what proposed the line, on what evidence, against which runner-ups.
        'method_version' => 'integer', 'score' => 'integer', 'evidence' => 'array', 'alternatives' => 'array', 'run_id' => 'integer',
        'client_id' => 'integer',
    ];

    protected static function booted(): void
    {
        // Decisions on undone transactions are voided, not deleted: kept for the record, counted nowhere.
        static::addGlobalScope('not_voided', fn ($query) => $query->whereNull('allocations.voided_at'));
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    public function manualExpense(): BelongsTo
    {
        return $this->belongsTo(ManualExpense::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The client the line is for when neither its invoice nor its job names one (client-only income, a refund paid
     * back to a client, a deduction with no job). The client a line counts for is worked out from its invoice, then
     * its job, then this (App\Domain\Income\IncomeClient), never read from here alone.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(MerchantRule::class, 'merchant_rule_id');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
