<?php

namespace App\Jobs;

use App\Domain\Travel\TravelReader;
use App\Domain\Verification\Verifier;
use App\Models\GmailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
    }
}
