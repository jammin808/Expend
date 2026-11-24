<?php

namespace App\Jobs;

use App\Domain\Ai\AiUsage;
use App\Domain\Travel\TravelReader;
use App\Domain\Verification\Verifier;
use App\Models\GmailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a booking email with AI. Its calls are made for reading (AiUsage purposes: reading's share of the month's cap,
 * once the nightly cycle is on), whether Gmail's search queued it or you pressed Read again. A read refused for want of
 * budget is left failed with the cap in its error; the nightly cycle (acquire.retry_reads) reads it again when there's
 * room.
 */
class ReadTravelEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public int $gmailMessageId) {}

    public function handle(TravelReader $reader, Verifier $verifier): void
    {
        $message = GmailMessage::query()->with('document')->find($this->gmailMessageId);
        if (! $message?->document) {
            return;
        }

        AiUsage::withPurpose('reading', function () use ($message, $reader, $verifier) {
            try {
                $items = $reader->read($message->document);
                $message->update($items === null
                    ? ['status' => 'needs_ai', 'error' => 'This email has no booking data, so it needs AI to read. Switch on reading documents in Settings › Integrations.']
                    : ['status' => $items ? 'read' : 'no_booking', 'error' => null]);
                foreach ($items ?? [] as $item) {
                    try {
                        $verifier->travelItem($item->fresh(), false); // just fetched from Gmail; the scheduled re-check fetches it again
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                if ($items) {
                    FindJobs::dispatch();
                }
            } catch (Throwable $e) {
                $message->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                report($e);
            }
        });
    }
}
