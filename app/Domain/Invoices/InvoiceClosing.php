<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\AuditLog;
use App\Domain\Income\IncomeClient;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\JobCreator;
use App\Models\Allocation;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Closing an issued invoice with money still to come, reopening it, and saying which invoice replaced a void one
 * (income design v3). Yours only (a tap in the app or in Telegram), each audited with what it was before and after:
 * invoice.closed (your note as its reason), invoice.reopened and invoice.replaced.
 *
 * Closing writes off what's still to come at that moment (closed_minor), for a reason: they paid less, bank or exchange
 * charges, you won't chase it, it was paid in another currency, or something else. It also keeps what payments naming
 * it had paid by then (closed_linked_minor), so money naming it later shrinks what was written off (InvoicePayments).
 * You're only taxed on what came in, so nothing is claimed for it and no ledger line is written; a locked year doesn't
 * stop it. It waits while a payment that may be for it is in the Inbox: one naming it, or income for its job when it's
 * the job's oldest invoice still owed (the one that money would pay first).
 *
 * A void invoice that was paid can be given the invoice that replaced it (same client), so its payments count there
 * (InvoicePayments follows the chain). Its rules are the Invoice model's: closing only while issued, a replacement only
 * on a void invoice.
 */
final class InvoiceClosing
{
    /**
     * Close it: what's still to come isn't coming.
     *
     * @param  string  $reason  one of Invoice::CLOSED_REASONS
     */
    public function close(Invoice $invoice, string $reason, ?string $note = null): Invoice
    {
        self::yours('close an invoice');
        if (! in_array($reason, Invoice::CLOSED_REASONS, true)) {
            throw new InvalidArgumentException('Say why it’s being closed.');
        }
        $note = self::note($note);

        return DB::transaction(function () use ($invoice, $reason, $note) {
            AuditLog::lockHead();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $name = self::name($invoice);
            if ($invoice->status !== 'issued') {
                throw new InvalidArgumentException(match ($invoice->status) {
                    'draft' => "{$name} is a draft: there’s nothing to close.",
                    'void' => "{$name} is void: there’s nothing to close.",
                    default => "{$name} is already marked paid: there’s nothing to close.",
                });
            }
            $r = InvoicePayments::received([$invoice])[$invoice->id];
            if ($invoice->closed_minor !== null && $r['outstanding'] === 0) {
                throw new InvalidArgumentException("{$name} is already closed.");
            }
            if ($r['outstanding'] <= 0) {
                throw new InvalidArgumentException("{$name} is paid in full: there’s nothing to close.");
            }
            if ($r['waiting_linked'] > 0 || $r['waiting_unmeasured'] > 0 || self::jobMoneyWaiting($invoice)) {
                throw new InvalidArgumentException('Decide the payment waiting in the Inbox first: it may be for '.$name.'.');
            }

            $before = $invoice->only(Invoice::CLOSED_FIELDS);
            // What's written off is all that's still to come: on an invoice closed before (and owed again since a
            // refund), what was written off then as well.
            // And what payments naming it had paid by then: money naming it after this shrinks what's written off.
            $invoice->forceFill(['closed_minor' => $r['closed'] + $r['outstanding'], 'closed_reason' => $reason, 'closed_at' => now(),
                'closed_linked_minor' => $r['paid_linked']])->save();
            AuditLog::record('invoice.closed', $invoice, self::fields($before), self::fields($invoice->only(Invoice::CLOSED_FIELDS)) + ['outstanding' => $r['outstanding']], $note);

            return $invoice;
        });
    }

    /** Reopen a closed invoice: what was written off is owed again (less anything that came in since). */
    public function reopen(Invoice $invoice, ?string $note = null): Invoice
    {
        self::yours('reopen an invoice');
        $note = self::note($note);

        return DB::transaction(function () use ($invoice, $note) {
            AuditLog::lockHead();
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued' || $invoice->closed_minor === null) {
                throw new InvalidArgumentException(self::name($invoice).' isn’t closed.');
            }
            $before = $invoice->only(Invoice::CLOSED_FIELDS);
            $invoice->forceFill(['closed_minor' => null, 'closed_reason' => null, 'closed_at' => null, 'closed_linked_minor' => null])->save();
            AuditLog::record('invoice.reopened', $invoice, self::fields($before), self::fields($invoice->only(Invoice::CLOSED_FIELDS)), $note);

            return $invoice;
        });
    }

    /**
     * Say which invoice replaced a void one (null: none did), so what was paid against it counts for the replacement.
     * The replacement must be issued (or marked paid by an earlier release), not the void invoice itself, and for the
     * same client (worked out, merges resolved; for invoices with no client, the same bill-to name), and its own chain
     * of replacements must never lead back to the void one.
     */
    public function replaceWith(Invoice $void, ?Invoice $to, ?string $note = null): Invoice
    {
        self::yours('say which invoice replaced another');
        $note = self::note($note);

        return DB::transaction(function () use ($void, $to, $note) {
            AuditLog::lockHead();
            $void = Invoice::query()->lockForUpdate()->findOrFail($void->id);
            if ($void->status !== 'void') {
                throw new InvalidArgumentException('Only a void invoice can say which invoice replaced it.');
            }
            $to = $to === null ? null : Invoice::query()->lockForUpdate()->findOrFail($to->id);
            if ($to !== null) {
                if ($to->id === $void->id) {
                    throw new InvalidArgumentException('An invoice can’t replace itself.');
                }
                if (! in_array($to->status, InvoicePayments::PAYABLE, true)) {
                    throw new InvalidArgumentException(self::name($to).' isn’t issued: pick an issued invoice.');
                }
                if (! self::sameClient($void, $to)) {
                    throw new InvalidArgumentException(self::name($to).' is for another client: pick one of '.($void->bill_to_name ?: 'this client').'’s invoices.');
                }
                if (self::leadsTo($to, $void->id)) {
                    throw new InvalidArgumentException('That would go round in a circle: '.self::name($to).' was replaced by '.self::name($void).'.');
                }
            }
            if ((int) $void->replaced_by_id === (int) $to?->id) {
                return $void; // already says so
            }
            $before = ['replaced_by_id' => $void->replaced_by_id];
            $void->forceFill(['replaced_by_id' => $to?->id])->save();
            AuditLog::record('invoice.replaced', $void, $before, ['replaced_by_id' => $void->replaced_by_id], $note);

            return $void;
        });
    }

    /**
     * Whether income for the invoice's job is waiting in the Inbox (business income on the job, not yet confirmed, that
     * names no invoice that can be paid) while this is the job's oldest invoice still owed: that money would pay it first.
     */
    private static function jobMoneyWaiting(Invoice $invoice): bool
    {
        if ($invoice->work_job_id === null) {
            return false;
        }
        $waiting = Allocation::query()->where('kind', 'business_income')->whereNull('confirmed_at')->where('work_job_id', $invoice->work_job_id)
            ->where(fn ($q) => $q->whereNull('bank_transaction_id')->orWhereHas('transaction'))
            ->where(fn ($q) => $q->whereNull('invoice_id')->orWhereHas('invoice', fn ($i) => $i->whereNotIn('status', InvoicePayments::PAYABLE)))
            ->exists();
        if (! $waiting) {
            return false;
        }
        $jobInvoices = Invoice::query()->where('work_job_id', $invoice->work_job_id)->whereIn('status', InvoicePayments::PAYABLE)->get();
        $received = InvoicePayments::received($jobInvoices);
        $oldest = $jobInvoices->filter(fn (Invoice $i) => ($received[$i->id]['outstanding'] ?? 0) > 0)
            ->sortBy(fn (Invoice $i) => [$i->issue_date?->format('Y-m-d') ?? '9999-12-31', $i->id])->first();

        return $oldest !== null && $oldest->id === $invoice->id;
    }

    private static function sameClient(Invoice $a, Invoice $b): bool
    {
        $ca = IncomeClient::ofInvoice($a);
        $cb = IncomeClient::ofInvoice($b);
        if ($ca !== null || $cb !== null) {
            return $ca === $cb;
        }
        $key = JobCreator::clientKey($a->bill_to_name);

        return $key !== '' && $key === JobCreator::clientKey($b->bill_to_name);
    }

    /** Whether following an invoice's replacements (at most 5) reaches this one. */
    private static function leadsTo(Invoice $from, int $target): bool
    {
        $id = $from->replaced_by_id;
        $seen = [$from->id => true];
        for ($hop = 0; $hop < 5 && $id !== null; $hop++) {
            if ((int) $id === $target) {
                return true;
            }
            if (isset($seen[(int) $id])) {
                return false;
            }
            $seen[(int) $id] = true;
            $id = Invoice::query()->whereKey($id)->value('replaced_by_id');
        }

        return false;
    }

    /** Closing, reopening and replacing are yours: never automation's. */
    private static function yours(string $what): void
    {
        if (AuditContext::actor() !== 'you') {
            throw new InvalidArgumentException("Only you can {$what}.");
        }
    }

    private static function note(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === '' ? null : mb_substr($note, 0, 250);
    }

    /**
     * The closing fields as the history keeps them (the date as text); what payments naming it had paid only when it's
     * set, so a closing's history before it was kept reads as it did.
     */
    private static function fields(array $f): array
    {
        return [
            'closed_minor' => $f['closed_minor'] ?? null,
            'closed_reason' => $f['closed_reason'] ?? null,
            'closed_at' => isset($f['closed_at']) ? $f['closed_at']->format('Y-m-d H:i:s') : null,
        ] + (isset($f['closed_linked_minor']) ? ['closed_linked_minor' => $f['closed_linked_minor']] : []);
    }

    private static function name(Invoice $invoice): string
    {
        return $invoice->number ?: 'This invoice';
    }
}
