<?php

namespace App\Jobs;

use App\Domain\Ai\DocumentReader;
use App\Domain\Telegram\Telegram;
use App\Domain\Verification\Verifier;
use App\Models\Document;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReadDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $documentId, public ?string $telegramChatId = null) {}

    public function handle(DocumentReader $reader, Verifier $verifier): void
    {
        $document = Document::query()->find($this->documentId);
        if (! $document || $document->status === 'held') {
            return;
        }

        try {
            $document->update(['status' => 'queued']);
            $document = $reader->read($document);
            try {
                $verifier->document($document, $document->text_status !== 'extracted' && Setting::get('ai_extraction') === 'on');
            } catch (Throwable $e) {
                report($e);
            }
            FindJobs::dispatch();
        } catch (Throwable $e) {
            $document->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e);
        }

        if ($this->telegramChatId) {
            Telegram::reply($this->telegramChatId, Telegram::documentSummary($document->fresh(['facts', 'job'])));
        }
    }
}
