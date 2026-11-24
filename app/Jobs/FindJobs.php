<?php

namespace App\Jobs;

use App\Domain\Jobs\JobFinder;
use App\Domain\Verification\Verifier;
use App\Models\JobCandidate;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the job finder after new documents, bookings or calendar entries arrive. Several arrivals make one run.
 *
 * One run at a time (JobFinder::exclusively(), shared with ledger:find-jobs and the nightly cycle's relate.jobs): if
 * another is under way, this one leaves it to that run, which looks at the same records. It doesn't wait: the worker
 * it runs in also answers Telegram presses.
 */
class FindJobs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public int $uniqueFor = 600;

    /** @param bool $asked you pressed "Find jobs now": runs even when automatic runs are switched off */
    public function __construct(public ?string $taxYear = null, public bool $asked = false) {}

    public function uniqueId(): string
    {
        return ($this->taxYear ?? 'recent').($this->asked ? '-asked' : '');
    }

    public function handle(JobFinder $finder, Verifier $verifier): void
    {
        if (! $this->asked && ! Setting::get('job_finder_auto')) {
            return;
        }
        try {
            JobFinder::exclusively(function () use ($finder, $verifier) {
                $finder->run($this->taxYear);
                JobCandidate::query()->where('status', 'open')->get()->each(fn ($c) => $verifier->candidate($c));
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
