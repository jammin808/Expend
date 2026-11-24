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
    protected $guarded = ['id'];

    protected $casts = [
        'issue_date' => 'date', 'due_date' => 'date', 'issued_at' => 'datetime', 'sent_at' => 'datetime', 'reminded_at' => 'datetime', 'overdue_nudged_at' => 'datetime',
        'total_minor' => 'integer', 'po_total_minor' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            // Its email's Message-ID is fixed once given, so re-sends and reminders all point to the same one.
            if ($invoice->getOriginal('message_id') !== null && $invoice->isDirty('message_id')) {
                throw new LogicException('An invoice’s Message-ID never changes once it has been given.');
            }
            if (in_array($invoice->getOriginal('status'), ['issued', 'paid', 'void'], true)) {
                // Only its status and when it was emailed or chased can change (and its email's Message-ID, set once
                // when it's first emailed or chased); the invoice itself can't.
                $allowed = ['status', 'void_reason', 'updated_at', 'sent_at', 'sent_to', 'reminded_at', 'reminder_count', 'overdue_nudged_at', 'message_id'];
                foreach (array_keys($invoice->getDirty()) as $field) {
                    if (! in_array($field, $allowed, true)) {
                        throw new LogicException("Issued invoices can't be edited ({$field}). Void it and issue a new one.");
                    }
                }
            }
        });
    }

    public function lines(): HasMany
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

    /**
     * What has come in for it, in its own currency: payments linked to it, and income you recorded for its job
     * (see InvoicePayments::received(), which works it out for many invoices at once).
     */
    public function paidMinor(): int
    {
        return InvoicePayments::paid($this);
    }
}
