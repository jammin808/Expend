<?php

namespace App\Jobs;

use App\Domain\Jobs\JobGatherer;
use App\Models\WorkJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** After a job is added: find everything that belongs to it (its days, documents, payments, stays, Gmail). */
class GatherForJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public int $uniqueFor = 600;

    public function __construct(public int $jobId) {}

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(JobGatherer $gatherer): void
    {
        $job = WorkJob::query()->find($this->jobId);
        if (! $job || $job->status === 'cancelled') {
            return;
        }
        try {
            $gatherer->gather($job);
        } catch (Throwable $e) {
            report($e); // "Search again" on the job runs it again
        }
    }
}
