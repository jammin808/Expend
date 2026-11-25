<?php

namespace App\Jobs;

use App\Domain\Intel\AuditContext;
use App\Domain\Invoices\ChaseLadder;
use App\Domain\Invoices\InvoiceEmails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Looks at the invoice drafts Expend put in your Gmail (every open one, or one invoice's): sent from Gmail (recorded),
 * deleted unsent, edited, or still waiting; then takes Expend's own unedited reminder drafts out for invoices that are
 * paid, closed or voided, or have a payment waiting that may be for them. Run when the invoice's page opens, 2, 10 and
 * 30 minutes after issuing (or Open in Gmail), and hourly (the schedule is the to-do slice's).
 *
 * While Gmail can't be read it does nothing else, and "Expend can't see Gmail" is kept (InvoiceEmails::cantSee) until a
 * check works, so no "not sent" message goes meanwhile. What it records is the system's, never yours.
 */
class CheckInvoiceEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    /** Minutes after issuing (or Open in Gmail) that it looks. */
    public const LATER = [2, 10, 30];

    public function __construct(public ?int $invoiceId = null) {}

    public function handle(InvoiceEmails $emails, ChaseLadder $ladder): void
    {
        AuditContext::with('system', function () use ($emails, $ladder) {
            $checked = $emails->checkAll($this->invoiceId);
            if (! $checked['blind']) {
                $ladder->tidy($this->invoiceId);
            }
        });
    }

    /** Look at one invoice's draft 2, 10 and 30 minutes from now (nothing on a queue that runs jobs at once). */
    public static function later(int $invoiceId): void
    {
        if (Queue::connection() instanceof SyncQueue) {
            return;
        }
        foreach (self::LATER as $minutes) {
            self::dispatch($invoiceId)->delay(now()->addMinutes($minutes));
        }
    }
}
