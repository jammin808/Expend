<?php

namespace App\Domain\Intel\Cycle\Steps;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleStep;
use App\Domain\Intel\Cycle\Stage;
use App\Domain\Intel\Cycle\StepOutcome;
use App\Jobs\ReadDocument;
use App\Jobs\RetryTravelRead;
use App\Models\Document;
use App\Models\GmailMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * acquire.retry_reads (docs/intelligence-cycle.md 4.1): reads that waited for AI budget are tried again once there's
 * room. Documents waiting_budget (and ones from before this release that failed on the spending cap), and booking
 * emails that failed on it, are queued again for the worker (ReadDocument, and RetryTravelRead: ReadTravelEmail made
 * for reading, so the cycle never spends the assistant's share), oldest first, as far as the run's allowance and
 * reading's share of the month go: each read counts about READ_USD against them (an estimate: what a read really costs
 * is held to reading's share when it's made). When the allowance runs out, the rest wait for the next night ('budget').
 */
final class AcquireRetryReads implements CycleStep
{
    public const KEY = 'acquire.retry_reads';

    /** About what one read costs, counted against the run's allowance when it's queued. */
    public const READ_USD = 0.15;

    /** The most reads queued in one night. */
    public const MAX = 25;

    public function key(): string
    {
        return self::KEY;
    }

    public function stage(): Stage
    {
        return Stage::Acquire;
    }

    public function writes(): bool
    {
        return true;
    }

    public function run(CycleContext $ctx): StepOutcome
    {
        $docs = self::waitingDocuments()->orderBy('id')->limit(self::MAX)->get();
        $emails = self::waitingEmails()->orderBy('id')->limit(self::MAX)->get();
        $queued = 0;
        $waiting = $docs->count() + $emails->count();
        foreach ([...$docs->all(), ...$emails->all()] as $read) {
            if ($queued >= self::MAX || ! $ctx->ai->allow(self::READ_USD, 'reading')) {
                break;
            }
            if ($read instanceof Document) {
                $read->update(['status' => 'queued', 'error' => null]);
                ReadDocument::dispatch($read->id);
            } else {
                $read->update(['status' => 'stored', 'error' => null]); // as "Read again" on the travel page does
                RetryTravelRead::dispatch($read->id); // read for reading's share, never the assistant's
            }
            $queued++;
        }
        $counts = ['queued' => $queued, 'waiting' => $waiting - $queued];

        return $queued < $waiting && $ctx->ai->refused() ? StepOutcome::budget($counts) : StepOutcome::ok($counts);
    }

    /** @return Builder<Document> documents whose read waits for AI budget */
    public static function waitingDocuments(): Builder
    {
        return Document::query()->where(fn ($q) => $q->where('status', ReadDocument::WAITING)
            ->orWhere(fn ($q) => $q->where('status', 'failed')->where('error', 'like', '%spending cap%')));
    }

    /** @return Builder<GmailMessage> booking emails whose read failed on the AI spending cap */
    public static function waitingEmails(): Builder
    {
        return GmailMessage::query()->where('status', 'failed')->where('error', 'like', '%spending cap%')->whereNotNull('document_id');
    }
}
