<?php

namespace App\Jobs;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Telegram\Telegram;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public array $update) {}

    public function handle(DocumentIntake $intake, Assistant $assistant): void
    {
        Telegram::handle($this->update, $intake, $assistant);
    }
}
