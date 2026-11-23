<?php

namespace App\Domain\Invoices;

use App\Domain\Audit\AuditLog;
use App\Domain\Mail\OutgoingMail;
use App\Mail\InvoiceEmail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/** Emails an issued invoice to the client, and reminders when it's overdue. Sent from your mailbox if set up. */
final class InvoiceMailer
{
    public function send(Invoice $invoice, ?string $to = null, bool $reminder = false): string
    {
        if ($invoice->status !== 'issued' && ! ($reminder === false && $invoice->status === 'paid')) {
            throw new InvalidArgumentException('Only issued invoices can be emailed.');
        }
        $to = trim((string) ($to ?: $invoice->bill_to_email));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Add the client’s email address to the invoice first.');
        }
        if (! $invoice->pdf_path) {
            throw new InvalidArgumentException('The invoice PDF is missing.');
        }
        if (OutgoingMail::method() === 'off') {
            throw new InvalidArgumentException('Sending email is switched off in Settings › Integrations.');
        }

        Mail::to($to)->queue(new InvoiceEmail($invoice, $reminder));
        $reminder
            ? $invoice->forceFill(['reminded_at' => now(), 'reminder_count' => $invoice->reminder_count + 1])->save()
            : $invoice->forceFill(['sent_at' => now(), 'sent_to' => $to])->save();
        AuditLog::record($reminder ? 'invoice.reminder_sent' : 'invoice.sent', $invoice, null, ['to' => $to, 'number' => $invoice->number]);

        return ($reminder ? 'Reminder for ' : 'Invoice ').$invoice->number.' is on its way to '.$to.'.';
    }
}
