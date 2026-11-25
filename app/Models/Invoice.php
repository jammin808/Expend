<?php

namespace App\Models;

use App\Domain\Invoices\InvoicePayments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Issued invoices are immutable: void and re-issue instead of editing. */
class Invoice extends Model
{
    /** Why you closed an invoice with money still to come (the note goes in the audit event's reason). */
    public const CLOSED_REASONS = ['short_paid', 'charges', 'wont_chase', 'paid_other_currency', 'other'];

    /**
     * What an owner's closing records: set or cleared only while the invoice is issued. closed_linked_minor is what
     * payments naming it had paid by then (InvoicePayments' paid_linked), so money naming it later shrinks what was
     * written off rather than its share of the job's income.
     */
    public const CLOSED_FIELDS = ['closed_minor', 'closed_reason', 'closed_at', 'closed_linked_minor'];

    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'sent_at' => 'datetime', 'reminded_at' => 'datetime', 'overdue_nudged_at' => 'datetime',
        'total_minor' => 'integer', 'po_total_minor' => 'integer',
        'closed_minor' => 'integer', 'closed_at' => 'datetime', 'replaced_by_id' => 'integer', 'closed_linked_minor' => 'integer',
        'lines_seen_event_id' => 'integer', 'synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            // Its email's Message-ID is fixed once given, so re-sends and reminders all point to the same one.
            if ($invoice->getOriginal('message_id') !== null && $invoice->isDirty('message_id')) {
                throw new LogicException('An invoice’s Message-ID never changes once it has been given.');
            }
            // Its status as stored, not as this model remembers it: a draft loaded before it was issued elsewhere can't be
            // used to change the issued invoice.
            $was = static::query()->whereKey($invoice->getKey())->value('status') ?? $invoice->getOriginal('status');
            // Closing (and reopening) is for an issued invoice with money still to come: never a draft, a void
            // invoice or a legacy 'paid' one.
            if ($invoice->isDirty(self::CLOSED_FIELDS) && $was !== 'issued') {
                throw new LogicException('Only an issued invoice can be closed or reopened.');
            }
            if ($invoice->isDirty('closed_reason') && $invoice->closed_reason !== null && ! in_array($invoice->closed_reason, self::CLOSED_REASONS, true)) {
                throw new LogicException("An invoice can't be closed for '{$invoice->closed_reason}'.");
            }
            // Which invoice replaced it is said of a void invoice, or in the same save that voids it.
            if ($invoice->isDirty('replaced_by_id') && $was !== 'void' && ! ($invoice->isDirty('status') && $invoice->status === 'void')) {
                throw new LogicException('Only a void invoice can say which invoice replaced it.');
            }
            if (in_array($was, ['issued', 'paid', 'void'], true)) {
                // Only its status and when it was emailed or chased can change (and its email's Message-ID, set once
                // when it's first emailed or chased), plus what a closing records and which invoice replaced it, as
                // checked above; the invoice itself can't.
                $allowed = ['status', 'void_reason', 'updated_at', 'sent_at', 'sent_to', 'reminded_at', 'reminder_count', 'overdue_nudged_at', 'message_id',
                    ...self::CLOSED_FIELDS, 'replaced_by_id'];
                foreach (array_keys($invoice->getDirty()) as $field) {
                    if (! in_array($field, $allowed, true)) {
                        throw new LogicException("Issued invoices can't be edited ({$field}). Void it and issue a new one.");
                    }
                }
            }
        });
    }

    /**
     * The lines it charges: ticked lines only. The PDF, total_minor, the mailer and every reader of what was billed
     * see these. Code that needs every line (the draft's own page once it shows ticks, the sync, anything that
     * writes or deletes lines) uses allLines().
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->where('included', true)->orderBy('sort');
    }

    /** Every line, ticked or not. */
    public function allLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkJob::class, 'work_job_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    /** The invoice that replaced this one, when it's void. */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'replaced_by_id');
    }

    /** Its emails and reminders put in Gmail or sent (one open Gmail draft per kind at a time). */
    public function emails(): HasMany
    {
        return $this->hasMany(InvoiceEmail::class);
    }

    /**
     * What has come in for it, in its own currency: payments linked to it, and income you recorded for its job
     * (see InvoicePayments::received(), which works it out for many invoices at once).
     */
    public function paidMinor(): int
    {
        return InvoicePayments::paid($this);
    }
}
