<?php

namespace App\Jobs;

use App\Domain\Gmail\GmailScanner;
use App\Domain\Jobs\JobGatherer;
use App\Models\WorkJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Search Gmail for one job's emails and POs, keep what's found, then match it to the job again. Your Sent mail is left
 * out (-in:sent): an email you sent isn't the client's PO or booking, and your own invoices would match every job.
 */
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
        $query = $job ? self::query($job) : null;
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

    /**
     * The job's Gmail search (JobGatherer::gmailQuery), leaving out your Sent mail. gmailQuery leaves it out itself (so
     * the job page shows the search as run); this makes sure of it for a search made some other way.
     */
    public static function query(WorkJob $job): ?string
    {
        $query = JobGatherer::gmailQuery($job);
        if ($query === null) {
            return null;
        }

        return preg_match('/(^|\s)-in:sent(\s|$)/', $query) ? $query : $query.' -in:sent';
    }
}
