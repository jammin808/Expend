<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An invoice, or a reminder for it, put in Gmail as a draft (route gmail), sent by Expend's own mailer (mailer), or
 * marked as sent by you (by_hand). A Gmail draft keeps its ids, the conversation, To and subject, so Expend can see
 * when you sent it, or that it was deleted unsent.
 *
 * One route at a time: while a drafted row is open for an invoice and kind, nothing else sends that kind. There is
 * only ever one drafted row per invoice and kind; writers check that under the invoice's lock (lockForUpdate on its
 * row), and this refuses a second one.
 *
 * Not App\Mail\InvoiceEmail, which is the email itself.
 */
class InvoiceEmail extends Model
{
    public const KINDS = ['invoice', 'reminder'];

    public const ROUTES = ['gmail', 'mailer', 'by_hand'];

    public const STATUSES = ['drafted', 'sent', 'deleted', 'failed', 'replaced', 'removed'];

    protected $guarded = ['id'];

    protected $casts = [
        'invoice_id' => 'integer', 'stage' => 'integer',
        'drafted_at' => 'datetime', 'sent_at' => 'datetime', 'checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (InvoiceEmail $email) {
            foreach (['kind' => self::KINDS, 'route' => self::ROUTES, 'status' => self::STATUSES] as $field => $allowed) {
                if (! in_array($email->{$field}, $allowed, true)) {
                    throw new LogicException("An invoice email's {$field} can't be '{$email->{$field}}'.");
                }
            }
            if ($email->status === 'drafted' && $email->isDirty(['status', 'invoice_id', 'kind'])
                && self::query()->open()->where('invoice_id', $email->invoice_id)->where('kind', $email->kind)
                    ->when($email->exists, fn (Builder $q) => $q->whereKeyNot($email->getKey()))->exists()) {
                throw new LogicException("Invoice {$email->invoice_id} already has an open {$email->kind} draft.");
            }
        });
    }

    /** Drafts in Gmail that haven't been sent, deleted or replaced yet. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), 'drafted');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
