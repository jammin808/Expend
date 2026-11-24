<?php

namespace App\Domain\Intel;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Who is acting right now, where it came from and what it belongs to, for the audit trail. Code that works on its
 * own (a matcher taking back its own suggestion, the Gmail label, a check) runs inside with(), so what it writes is
 * recorded as its own and never as yours. Outside any with(), the actor is 'you': an owner's tap on the web or in
 * Telegram.
 *
 * Once recording it is turned on (AuditLog::recordsContext(), setting intel_audit_context), each event also records
 * (AuditLog::record):
 *  - the channel: web (App\Http\Middleware\AuditChannel), telegram (App\Jobs\HandleTelegramUpdate), queue
 *    (App\Jobs\Middleware\AuditQueueContext), schedule or cli;
 *  - the correlation: a ULID shared by an action and every knock-on write it causes, carried into queued jobs, so a
 *    decision in the Inbox, the other side of its transfer confirmed because of it and the suggestions learned from
 *    it read as one;
 *  - the cycle run's id, for work done by a nightly run.
 *
 * Contexts nest: the innermost wins, and whatever it doesn't name (the channel, the correlation, the run, even the
 * actor) carries on from the one around it.
 */
final class AuditContext
{
    /** The actors an audit event can name (the plan's shared vocabulary, section 2.2). */
    public const ACTORS = ['you', 'rule', 'matcher', 'ai', 'policy', 'checker', 'system'];

    /** Where an action came from. */
    public const CHANNELS = ['web', 'telegram', 'schedule', 'queue', 'cli'];

    /**
     * Actor names older code wrote, and what they are in the vocabulary: 'rules' was the job finder's, the calendar
     * sync's and the opportunity finder's automatic work. Events already in the log keep what they say.
     */
    public const LEGACY_ACTORS = ['rules' => 'matcher', 'server' => 'system'];

    /** @var list<array{actor:string, channel:?string, correlation:?string, run_id:?int}> innermost last, each as it applies */
    private static array $stack = [];

    /**
     * Run $work with $actor as the default actor of every audit event written inside it.
     *
     * @template T
     *
     * @param  string|null  $actor  null: the actor of the context around it ('you' outside any)
     * @param  callable():T  $work
     * @param  string|null  $correlation  a ULID (AuditContext::newCorrelation()); null carries on the outer one
     * @return T
     */
    public static function with(?string $actor, callable $work, ?string $channel = null, ?string $correlation = null, ?int $runId = null): mixed
    {
        if ($actor !== null && ! in_array($actor, self::ACTORS, true)) {
            throw new InvalidArgumentException("Unknown audit actor “{$actor}”.");
        }
        if ($channel !== null && ! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown audit channel “{$channel}”.");
        }
        if ($correlation !== null && ! self::isCorrelation($correlation)) {
            throw new InvalidArgumentException("Not a correlation id “{$correlation}”: it must be a ULID.");
        }
        if ($runId !== null && $runId <= 0) {
            throw new InvalidArgumentException('A run id is a positive number.');
        }
        $outer = self::current();
        self::$stack[] = [
            'actor' => $actor ?? $outer['actor'] ?? 'you',
            'channel' => $channel ?? $outer['channel'] ?? null,
            'correlation' => $correlation ?? $outer['correlation'] ?? null,
            'run_id' => $runId ?? $outer['run_id'] ?? null,
        ];
        $depth = count(self::$stack);
        try {
            return $work();
        } finally {
            // Back to exactly the outer context, even if $work left one open by mistake.
            array_splice(self::$stack, $depth - 1);
        }
    }

    /** The actor to record when the caller names none: the innermost context's, or 'you'. */
    public static function actor(): string
    {
        return self::current()['actor'] ?? 'you';
    }

    public static function channel(): ?string
    {
        return self::current()['channel'] ?? null;
    }

    public static function correlation(): ?string
    {
        return self::current()['correlation'] ?? null;
    }

    public static function runId(): ?int
    {
        return self::current()['run_id'] ?? null;
    }

    /** @return array{actor:string, channel:?string, correlation:?string, run_id:?int}|null the innermost context, or null outside any */
    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }

    /** An actor as the vocabulary names it: a legacy name ('rules') becomes what it means now ('matcher'). */
    public static function normalise(string $actor): string
    {
        return self::LEGACY_ACTORS[$actor] ?? $actor;
    }

    /** A new correlation id: a ULID, 26 characters of Crockford base 32 in capitals, sortable by time. */
    public static function newCorrelation(): string
    {
        return strtoupper((string) Str::ulid());
    }

    public static function isCorrelation(string $value): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;
    }
}
