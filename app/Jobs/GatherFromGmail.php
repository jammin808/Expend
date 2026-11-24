<?php

namespace App\Jobs;

use App\Domain\Gmail\GmailScanner;
use App\Domain\Jobs\JobGatherer;
use App\Models\WorkJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Search Gmail for one job's emails and POs, keep what's found, then match it to the job again. */
class GatherFromGmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public int $jobId) {}

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(GmailScanner $scanner, JobGatherer $gatherer): void
    {
        $job = WorkJob::query()->with('client')->find($this->jobId);
        $query = $job ? JobGatherer::gmailQuery($job) : null;
        if (! $job || ! $query) {
            return;
        }
        try {
            $result = $scanner->scanQuery($query);
            $state = ['gmail' => 'done', 'gmail_found' => $result['found'], 'gmail_new' => $result['new'], 'gmail_at' => now()->format('Y-m-d H:i'), 'gmail_error' => null];
        } catch (Throwable $e) {
            report($e);
            $state = ['gmail' => 'failed', 'gmail_error' => mb_substr($e->getMessage(), 0, 300), 'gmail_at' => now()->format('Y-m-d H:i')];
        }
        $gatherer->gather($job, false);
        $job->refresh();
        $job->forceFill(['gather_state' => $state + (array) $job->gather_state])->save();
    }
}
