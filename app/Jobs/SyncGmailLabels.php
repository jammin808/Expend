<?php

namespace App\Jobs;

use App\Domain\Gmail\GmailLabeller;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Updates the "✓ Invoiced" label in Gmail for one job (or all), in the background. */
class SyncGmailLabels implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public ?int $jobId = null) {}

    public function handle(GmailLabeller $labeller): void
    {
        try {
            $labeller->sync($this->jobId);
        } catch (Throwable $e) {
            report($e); // the hourly run tries again
        }
    }
}
