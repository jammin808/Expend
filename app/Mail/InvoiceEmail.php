<?php

namespace App\Mail;

use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceMailer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** An issued invoice (or a reminder about one), with its PDF attached. Replies go to your business email. */
class InvoiceEmail extends Mailable
{
    /** @var array<string, mixed> the invoice's sent or reminder fields before this email, put back if it fails */
    public array $before = [];

    /** When this email marked the invoice as sent or reminded (declared with defaults so emails queued before these existed still load). */
    public ?string $claimedAt = null;

    /**
     * The invoice's fixed Message-ID, without the angle brackets (InvoiceMailer::newMessageId; docs/mail-and-calendar.md
     * 2D step 1). The invoice goes with it every time it's sent; a reminder gets a Message-ID of its own and points back
     * to it with In-Reply-To and References, so mail programs can show them as one conversation. Declared with a default,
     * like claimedAt, so emails queued before it existed still load, and a release before it still sends (and, if the
     * email fails, un-marks) one queued by this release.
     */
    public ?string $threadId = null;

    /** @param  array<string, mixed>  $before */
    public function __construct(public Invoice $invoice, public bool $reminder = false, array $before = [], ?string $claimedAt = null, ?string $threadId = null)
    {
        $this->before = $before;
        $this->claimedAt = $claimedAt;
        $this->threadId = $threadId;
    }

    /** The threading headers, set on the message itself, so they're the same whichever way the email goes. */
    public function headers(): Headers
    {
        if ($this->threadId === null || $this->threadId === '') {
            return new Headers;
        }

        return $this->reminder
            ? new Headers(references: [$this->threadId], text: ['In-Reply-To' => '<'.$this->threadId.'>'])
            : new Headers(messageId: $this->threadId);
    }

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
        // Part paid, the reminder thanks them for what came in and asks for what's still to come (as the Gmail reminder
        // does), never "I can't see a payment yet" for the whole amount.
        $body = $this->reminder
            ? '<p>Hello,</p><p>'.(ChaseLadder::partPaid($i) ? e(ChaseLadder::opening($i, 1))
                : 'A reminder that invoice '.e($i->number)."{$po}, for {$amount}, was due on {$due} and I can’t see a payment yet.").' The invoice is attached again.</p>'
                .'<p>If it’s already been paid, thank you, and please ignore this.</p>'
            : '<p>Hello,</p><p>Please find attached invoice '.e($i->number)."{$po} for {$amount}, due by {$due}.</p>";
        $bank = Setting::get('business_bank_details');

        return new Content(htmlString: $body.($bank ? '<p>Bank details:<br>'.nl2br(e($bank)).'</p>' : '')."<p>Many thanks,<br>{$name}</p>");
    }

    /** Called by the queue when sending fails for good: the invoice mustn't stay marked as sent. */
    public function failed(Throwable $e): void
    {
        app(InvoiceMailer::class)->failed($this->invoice, $this->reminder, $this->before, (string) $this->claimedAt, (string) ($this->to[0]['address'] ?? $this->invoice->bill_to_email), $e);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => (string) Storage::disk('local')->get($this->invoice->pdf_path), $this->invoice->number.'.pdf')->withMime('application/pdf')];
    }
}
