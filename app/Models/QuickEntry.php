<?php

namespace App\Models;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expense added on the phone, shared from Android, or texted to the Telegram bot (App\Domain\Quick\QuickEntries).
 * Written only through QuickEntries and ReceiptAttacher, which audit every change.
 *
 * @property string $client_key
 * @property string $status STATUSES
 * @property string|null $hold_reason HOLD_REASONS
 * @property string|null $paid_with PAID_WITH
 * @property int|null $amount_minor in the currency's own minor units
 * @property string $currency
 * @property array|null $read_check
 * @property array|null $answers
 */
class QuickEntry extends Model
{
    public const STATUSES = [
        'receiving' => 'Arriving', 'draft' => 'Not added yet', 'added' => 'Added', 'waiting' => 'Waiting for its bank line', 'attached' => 'On its bank line',
        'held' => 'Kept, not added', 'duplicate' => 'Not added: the same photo', 'set_aside' => 'Not a business cost', 'withdrawn' => 'Removed', 'dismissed' => 'Not an expense',
    ];

    /** Not counted anywhere: never an expense, never a receipt for a line. */
    public const NOT_LIVE = ['draft', 'withdrawn', 'dismissed', 'duplicate'];

    public const HOLD_REASONS = ['locked_year' => 'Its tax year is locked', 'needs_rate' => 'No exchange rate yet', 'needs_details' => 'Details missing',
        'same_photo' => 'The same photo as another entry'];

    /** How it was paid: cash and a personal card add the expense now; a business card or account waits for its bank line. */
    public const PAID_WITH = ['cash' => 'Cash', 'personal_card' => 'Personal card', 'business' => 'Business card or account'];

    public const CHANNELS = ['phone', 'share', 'telegram'];

    public const WHAT_SOURCES = ['typed', 'chip', 'photo', 'telegram'];

    public const JOB_SOURCES = ['today', 'you', 'words'];

    protected $guarded = ['id'];

    protected $casts = [
        'spent_on' => 'date', 'captured_at' => 'datetime', 'attached_at' => 'datetime',
        'amount_minor' => 'integer', 'sterling_pence' => 'integer', 'business_pct' => 'integer', 'photo_size' => 'integer',
        'telegram_message_id' => 'integer', 'read_check' => 'array', 'answers' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function manualExpense(): BelongsTo
    {
        return $this->belongsTo(ManualExpense::class);
    }

    /** Its bank line, whatever state the line is in now (an undone import reverses it). */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class)->withoutGlobalScope('active');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(QuickEntry::class, 'duplicate_of_id');
    }

    /** @param  Builder<QuickEntry>  $query */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('quick_entries.status', self::NOT_LIVE);
    }

    /**
     * A business-card receipt still waiting for its bank line: waiting, or attached to a line that's no longer active
     * (its import was undone). Matching, the nightly sweep and the checks treat both alike.
     *
     * @param  Builder<QuickEntry>  $query
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('quick_entries.status', 'waiting')
            ->orWhere(fn (Builder $q) => $q->where('quick_entries.status', 'attached')->whereNotExists(fn ($s) => $s->selectRaw('1')->from('bank_transactions')
                ->whereColumn('bank_transactions.id', 'quick_entries.bank_transaction_id')->where('bank_transactions.state', 'active'))));
    }

    /**
     * Card receipts for a job whose bank line hasn't come in yet (the hook for invoices: "1 receipt is still waiting
     * for its bank line").
     *
     * @param  Builder<QuickEntry>  $query
     */
    public function scopeWaitingOnJob(Builder $query, int $jobId): Builder
    {
        return $query->where('quick_entries.work_job_id', $jobId)->waiting();
    }

    public function isLive(): bool
    {
        return ! in_array($this->status, self::NOT_LIVE, true);
    }

    /** Whether it waits for a bank line: status waiting, or attached to a line that's no longer active. */
    public function isWaiting(): bool
    {
        if ($this->status === 'waiting') {
            return true;
        }

        return $this->status === 'attached' && ! BankTransaction::query()->whereKey($this->bank_transaction_id)->exists();
    }

    public function isCard(): bool
    {
        return $this->paid_with === 'business';
    }

    public function ref(): string
    {
        return 'quick:'.$this->id;
    }

    /** The amount in hundredths of its currency, as receipts are compared (null when none was typed). */
    public function hundredths(): ?int
    {
        return $this->amount_minor === null ? null : Money::toHundredths(abs($this->amount_minor), $this->currency);
    }

    /**
     * What you typed, for matching (ReceiptMatch::figures()): the amount in hundredths and in the currency's own
     * units, its currency (certain: you chose it), the date and where the figures came from.
     *
     * @return array{hundredths:?int, minor:?int, currency:string, date:?CarbonImmutable, source:string, certain:bool}
     */
    public function figures(): array
    {
        return [
            'hundredths' => $this->hundredths(), 'minor' => $this->amount_minor === null ? null : abs($this->amount_minor), 'currency' => $this->currency ?: 'GBP',
            'date' => $this->spent_on ? CarbonImmutable::parse($this->spent_on->format('Y-m-d')) : null, 'source' => 'quick', 'certain' => true,
        ];
    }

    /** "£12.50 parking", as the phone, Telegram and To do say it. */
    public function words(): string
    {
        $amount = $this->amount_minor !== null ? Money::formatMinor(abs($this->amount_minor), $this->currency ?: 'GBP', false) : 'An amount not typed';
        $what = trim((string) ($this->what ?: ''));
        // A chip's or a category's words read in a sentence ("£12.50 parking"); what you typed stays as you typed it.
        if ($what === '' || $this->what_source === 'chip') {
            $what = $what !== '' ? $what : trim((string) $this->category?->name);
            $what = $what !== '' ? mb_strtolower(mb_substr($what, 0, 1)).mb_substr($what, 1) : '';
        }

        return trim($amount.($what !== '' ? ' '.$what : ''));
    }

    /** One answer of yours to a check (answers.$key), as a list. */
    public function answered(string $key): array
    {
        return array_values((array) (($this->answers ?? [])[$key] ?? []));
    }
}
