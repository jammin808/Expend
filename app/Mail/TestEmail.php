<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TestEmail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Expense Ledger: test email');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>This is a test from Expense Ledger. Password reset emails will arrive the same way.</p>');
    }
}
