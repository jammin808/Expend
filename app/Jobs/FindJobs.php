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

/** Runs the job finder after new documents, bookings or calendar entries arrive. Several arrivals make one run. */
class FindJobs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public int $uniqueFor = 600;

    public function __construct(public ?string $taxYear = null) {}

    public function handle(JobFinder $finder, Verifier $verifier): void
    {
        if (! Setting::get('job_finder_auto')) {
            return;
        }
        try {
            $finder->run($this->taxYear);
            JobCandidate::query()->where('status', 'open')->get()->each(fn ($c) => $verifier->candidate($c));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
