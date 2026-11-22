<?php

namespace App\Jobs;

use App\Domain\Ai\DocumentReader;
use App\Domain\Telegram\Telegram;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReadDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $documentId, public ?string $telegramChatId = null) {}

    public function handle(DocumentReader $reader): void
    {
        $document = Document::query()->find($this->documentId);
        if (! $document || $document->status === 'held') {
            return;
        }

        try {
            $document->update(['status' => 'queued']);
            $document = $reader->read($document);
        } catch (Throwable $e) {
            $document->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
        }

        if ($this->telegramChatId) {
            Telegram::reply($this->telegramChatId, Telegram::documentSummary($document->fresh(['facts', 'job'])));
        }
    }
}
