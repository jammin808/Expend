<?php

namespace App\Domain\Intel\Cycle;

use App\Domain\Intel\Coverage;
use Carbon\CarbonInterface;

/**
 * What a step's run came to (docs/intelligence-cycle.md 6.4): done ('ok'), stopped for time with a cursor to carry on
 * from ('more'), the AI allowance ran out ('budget': not a failure, the work carries over to the next run), skipped
 * (with the reason), or failed. With what it did (counts), how much a detector looked at (coverage), and its version.
 */
final class StepOutcome
{
    public const STATUSES = ['ok', 'more', 'budget', 'skipped', 'failed'];

    /**
     * @param  array<string, mixed>  $counts  what it did, merged into the step's counts kept so far
     * @param  array<string, mixed>|null  $cursor  where to carry on from ('more', and 'budget' to carry over)
     */
    private function __construct(
        public readonly string $status,
        public readonly array $counts = [],
        public readonly ?array $cursor = null,
        public readonly ?Coverage $coverage = null,
        public readonly ?int $detectorVersion = null,
        public readonly ?string $error = null,
        public readonly ?string $notBefore = null,
    ) {}

    /** @param  array<string, mixed>  $counts */
    public static function ok(array $counts = [], ?Coverage $coverage = null, ?int $detectorVersion = null): self
    {
        return new self('ok', $counts, null, $coverage, $detectorVersion);
    }

    /**
     * Stopped for time: the next tick carries on from $cursor.
     *
     * @param  array<string, mixed>  $cursor
     * @param  array<string, mixed>  $counts
     */
    public static function more(array $cursor, array $counts = []): self
    {
        return new self('more', $counts, $cursor);
    }

    /**
     * Not yet: carry on at $at, not before (the morning report waits for its time).
     *
     * @param  array<string, mixed>  $cursor
     * @param  array<string, mixed>  $counts
     */
    public static function waitUntil(CarbonInterface $at, array $cursor = [], array $counts = []): self
    {
        return new self('more', $counts, $cursor, notBefore: $at->toIso8601String());
    }

    /**
     * The AI allowance ran out. Not a failure: what's left carries over to the next run.
     *
     * @param  array<string, mixed>  $counts
     * @param  array<string, mixed>|null  $cursor
     */
    public static function budget(array $counts = [], ?array $cursor = null): self
    {
        return new self('budget', $counts, $cursor);
    }

    /** @param  array<string, mixed>  $counts */
    public static function skipped(string $reason, string $why, array $counts = []): self
    {
        return new self('skipped', ['skipped' => $reason] + $counts, error: $why);
    }

    /** @param  array<string, mixed>  $counts */
    public static function failed(string $error, array $counts = []): self
    {
        return new self('failed', $counts, error: mb_substr($error, 0, 1000));
    }
}
