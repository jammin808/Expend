<?php

namespace App\Jobs;

use App\Domain\Invoices\InvoiceEmails;
use App\Models\Invoice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

/**
 * Puts an issued invoice (or a reminder you asked for) in your Gmail drafts when Gmail didn't answer while you waited:
 * the issuing request tries first, then this tries again, three times over about ten minutes. A retry adopts a draft an
 * earlier try made (InvoiceEmails::prepare is idempotent), so there's never a second one. If it still can't, the row is
 * marked failed with why, and the to-do says "INV-0042 isn't in your Gmail drafts yet" with Try again and Email it from
 * Expend. It acts on your press, so what it records is yours.
 */
class PrepareInvoiceEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Seconds before the second and third tries (the first is a minute after the request): about ten minutes in all. */
    public array $backoff = [180, 360];

    public int $timeout = 120;

    public function __construct(public int $invoiceId, public string $kind = 'invoice') {}

    public function handle(InvoiceEmails $emails): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);
        if ($invoice === null) {
            return;
        }
        try {
            $emails->prepare($invoice, $this->kind, null);
        } catch (InvalidArgumentException $e) {
            $emails->failed($invoice, $this->kind, $e->getMessage()); // refused (sent another way, voided): nothing to retry
        } catch (Throwable $e) {
            if (! InvoiceEmails::transient($e)) {
                $emails->failed($invoice, $this->kind, $e->getMessage());
                report($e);

                return;
            }
            throw $e; // the queue tries again
        }
    }

    /** Every try failed: say so on the row. */
    public function failed(?Throwable $e): void
    {
        if ($invoice = Invoice::query()->find($this->invoiceId)) {
            app(InvoiceEmails::class)->failed($invoice, $this->kind, 'Gmail didn’t answer');
        }
    }
}
