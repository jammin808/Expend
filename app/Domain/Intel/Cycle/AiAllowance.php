<?php

namespace App\Domain\Intel\Cycle;

use App\Domain\Ai\AiUsage;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A cycle run's AI allowance (docs/intelligence-cycle.md 6.2), in millionths of a US dollar.
 *
 * The nightly run may spend at most min(intel_ai_night_usd, (cap × the cycle's part − what the cycle has spent this
 * month) ÷ the days left in the month), where the cycle's part is what the assistant's reserve leaves (70% of the cap
 * by default): so the assistant's share is never touched, and the month's budget lasts the month. Other runs (the
 * morning report, after an import, "Check now") use no AI and get none.
 *
 * Within the run, each call is also held to its purpose's share of the month (AiUsage). Running out ends the step with
 * 'budget', which is not a failure: the work carries over.
 */
final class AiAllowance
{
    private bool $refused = false;

    public function __construct(public readonly int $budgetMicro = 0, private int $spentMicro = 0) {}

    public static function none(): self
    {
        return new self(0);
    }

    /** Tonight's allowance, in millionths of a dollar (worked out when the nightly run starts). */
    public static function forNight(?CarbonInterface $at = null): int
    {
        $at = CarbonImmutable::instance($at ?? now());
        $cap = AiUsage::cap();
        $night = max(0.0, (float) (Setting::get('intel_ai_night_usd') ?? 1.5));
        if ($cap <= 0) {
            return (int) round($night * 1_000_000); // no monthly cap: the nightly ceiling alone
        }
        $left = max(0.0, $cap * (100 - AiUsage::reservePct()) / 100 - AiUsage::cycleSpent());
        $days = max(1, (int) $at->daysInMonth - (int) $at->day + 1);

        return (int) floor(min($night, $left / $days) * 1_000_000);
    }

    public function spentMicro(): int
    {
        return $this->spentMicro;
    }

    public function remainingUsd(): float
    {
        return max(0, $this->budgetMicro - $this->spentMicro) / 1_000_000;
    }

    /** Whether it's used up, or a call was refused for want of budget (the run's, or a purpose's share of the month). */
    public function exhausted(): bool
    {
        return $this->refused || $this->spentMicro >= $this->budgetMicro;
    }

    /** Whether some work was refused for want of budget (the run's allowance, or a purpose's share of the month). */
    public function refused(): bool
    {
        return $this->refused;
    }

    /**
     * Whether work costing about $estimateUsd may be done for $purpose now: within what's left of the run's allowance
     * and of the purpose's share of the month. When it may, the estimate is counted against the allowance (for work
     * done elsewhere, such as a document read queued for the worker).
     */
    public function allow(float $estimateUsd, string $purpose): bool
    {
        $micro = (int) ceil(max(0.0, $estimateUsd) * 1_000_000);
        if ($this->spentMicro + $micro > $this->budgetMicro || AiUsage::available($purpose) < $estimateUsd) {
            $this->refused = true;

            return false;
        }
        $this->spentMicro += $micro;

        return true;
    }

    /**
     * Run $work with the AI calls inside it made for $purpose and held to what's left of the allowance; what they cost
     * is counted against it afterwards.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @return T
     */
    public function within(string $purpose, callable $work): mixed
    {
        $tally = null;
        try {
            return AiUsage::withPurpose($purpose, $work, $this->remainingUsd(), $tally);
        } finally {
            $this->spentMicro += (int) ceil(((float) ($tally['spent'] ?? 0)) * 1_000_000);
            $this->refused = $this->refused || (bool) ($tally['refused'] ?? false);
        }
    }
}
