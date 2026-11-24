<?php

namespace App\Jobs\Middleware;

use App\Domain\Intel\AuditContext;
use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Bus;

/**
 * Carries the audit context from where a job is dispatched to where it runs: what a queued job writes is recorded
 * from the queue, under the correlation of the action that started it (a web request, a Telegram message, a cycle
 * run) and with its actor and run id. A job started with no context gets a correlation of its own.
 *
 * Registered for every job and queued listener at once (register(), from AppServiceProvider): the context goes into
 * the job's payload when it's queued, and this runs around the job's handle(). A job run there and then (the sync
 * queue, dispatchSync) is already inside the context that started it, so it's left as it is.
 */
final class AuditQueueContext
{
    /** Where in a job's payload the context is kept. */
    public const KEY = 'expend_audit';

    /** Once per application boot (a test's teardown clears the queue's payload hooks; the next boot adds this again). */
    public static function register(): void
    {
        Queue::createPayloadUsing(fn () => [self::KEY => self::carried()]);
        Bus::pipeThrough([self::class]);
    }

    /** @return array{actor:string, correlation:?string, run_id:?int}|null the context to carry, or null outside any */
    public static function carried(): ?array
    {
        $c = AuditContext::current();

        return $c ? ['actor' => $c['actor'], 'correlation' => $c['correlation'], 'run_id' => $c['run_id']] : null;
    }

    public function handle(object $command, Closure $next): mixed
    {
        $job = property_exists($command, 'job') ? $command->job : null;
        if (! $job instanceof Job || $job instanceof SyncJob) {
            return $next($command);
        }
        $carried = $job->payload()[self::KEY] ?? null;
        $carried = is_array($carried) ? $carried : [];
        $actor = in_array($carried['actor'] ?? null, AuditContext::ACTORS, true) ? $carried['actor'] : null;
        $correlation = is_string($carried['correlation'] ?? null) && AuditContext::isCorrelation($carried['correlation']) ? $carried['correlation'] : AuditContext::newCorrelation();
        $runId = is_int($carried['run_id'] ?? null) && $carried['run_id'] > 0 ? $carried['run_id'] : null;

        return AuditContext::with($actor, fn () => $next($command), channel: 'queue', correlation: $correlation, runId: $runId);
    }
}
