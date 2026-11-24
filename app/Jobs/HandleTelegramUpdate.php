<?php

namespace App\Jobs;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Intel\AuditContext;
use App\Domain\Telegram\Telegram;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One Telegram update (a message, a file, a button press). Only your paired chat is acted on (Telegram::handle), so
 * what it changes is yours, recorded from Telegram under a correlation of its own: a button that decides a payment,
 * and everything that decision sets off, read as one action in the history.
 */
class HandleTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public array $update) {}

    public function handle(DocumentIntake $intake, Assistant $assistant): void
    {
        AuditContext::with('you', fn () => Telegram::handle($this->update, $intake, $assistant), channel: 'telegram', correlation: AuditContext::newCorrelation());
    }
}
