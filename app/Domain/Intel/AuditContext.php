<?php

namespace App\Domain\Intel;

use InvalidArgumentException;

/**
 * Who is acting right now, for the audit trail. Code that works on its own (a matcher taking back its own suggestion,
 * the Gmail label, a check) runs inside with(), so what it writes is recorded as its own and never as yours. Outside
 * any with(), the actor is 'you': an owner's tap on the web or in Telegram.
 *
 * Held in memory for the length of the call only (M2). The channel, correlation and run id are kept for later
 * stages, which store them with each event; nothing reads them yet.
 */
final class AuditContext
{
    /** The actors an audit event can name (the plan's shared vocabulary, section 2.2). */
    public const ACTORS = ['you', 'rule', 'matcher', 'ai', 'policy', 'checker', 'system'];

    /** Where an action came from. */
    public const CHANNELS = ['web', 'telegram', 'schedule', 'queue', 'cli'];

    /** @var list<array{actor:string, channel:?string, correlation:?string, run_id:?int}> innermost last */
    private static array $stack = [];

    /**
     * Run $work with $actor as the default actor of every audit event written inside it. Contexts nest: the
     * innermost wins, and the outer one is back as soon as $work returns or throws.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public static function with(string $actor, callable $work, ?string $channel = null, ?string $correlation = null, ?int $runId = null): mixed
    {
        if (! in_array($actor, self::ACTORS, true)) {
            throw new InvalidArgumentException("Unknown audit actor “{$actor}”.");
        }
        if ($channel !== null && ! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown audit channel “{$channel}”.");
        }
        self::$stack[] = ['actor' => $actor, 'channel' => $channel, 'correlation' => $correlation, 'run_id' => $runId];
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

    /** @return array{actor:string, channel:?string, correlation:?string, run_id:?int}|null the innermost context, or null outside any */
    public static function current(): ?array
    {
        return self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
    }
}
