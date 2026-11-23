<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/** An issued invoice (or a reminder about one), with its PDF attached. Replies go to your business email. */
class InvoiceEmail extends Mailable
{
    public function __construct(public Invoice $invoice, public bool $reminder = false) {}

    public function envelope(): Envelope
    {
        $business = Setting::get('business_name') ?: 'Invoice';
        $replyTo = Setting::get('business_email');

        return new Envelope(
            subject: ($this->reminder ? 'Reminder: invoice ' : 'Invoice ').$this->invoice->number.' from '.$business
                .($this->invoice->po_number ? ' (PO '.$this->invoice->po_number.')' : ''),
            replyTo: $replyTo ? [new Address($replyTo, Setting::get('business_name'))] : [],
        );
    }

    public function content(): Content
    {
        $i = $this->invoice;
        $name = e(Setting::get('business_name') ?: '');
        $amount = e(Money::format($i->total_minor, $i->currency, false));
        $due = $i->due_date?->format('j F Y');
        $po = $i->po_number ? ' for PO '.e($i->po_number) : '';
        $body = $this->reminder
            ? '<p>Hello,</p><p>A reminder that invoice '.e($i->number)."{$po}, for {$amount}, was due on {$due} and I can’t see a payment yet. The invoice is attached again.</p>"
                .'<p>If it’s already been paid, thank you, and please ignore this.</p>'
            : '<p>Hello,</p><p>Please find attached invoice '.e($i->number)."{$po} for {$amount}, due by {$due}.</p>";
        $bank = Setting::get('business_bank_details');

        return new Content(htmlString: $body.($bank ? '<p>Bank details:<br>'.nl2br(e($bank)).'</p>' : '')."<p>Many thanks,<br>{$name}</p>");
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => (string) Storage::disk('local')->get($this->invoice->pdf_path), $this->invoice->number.'.pdf')->withMime('application/pdf')];
    }
}
