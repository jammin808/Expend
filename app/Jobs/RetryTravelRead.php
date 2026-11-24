<?php

namespace App\Jobs;

use App\Domain\Ai\AiUsage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A booking email read again by the nightly cycle (acquire.retry_reads) after it waited for AI budget: the same work as
 * ReadTravelEmail, with its calls made for reading (AiUsage purposes), so they're held to reading's share of the month
 * and counted as reading, never as yours (the assistant's reserve). Refused again for want of budget, ReadTravelEmail
 * leaves it failed on the cap, and the next night tries again.
 */
class RetryTravelRead implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public int $gmailMessageId) {}

    public function handle(): void
    {
        AiUsage::withPurpose('reading', fn () => app()->call([new ReadTravelEmail($this->gmailMessageId), 'handle']));
    }
}
