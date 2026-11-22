<?php

namespace App\Domain\Ai;

use App\Models\Setting;
use RuntimeException;

/**
 * Tracks token use per month and stops AI calls once the monthly spending cap is reached.
 * Costs are an estimate from published per-token prices (USD) and are labelled as such.
 */
final class AiUsage
{
    /** USD per million tokens: [input, output]. */
    public const PRICES = [
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-fable-5-2' => [8.0, 40.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
    ];

    public static function record(string $model, int $in, int $out): void
    {
        $key = 'ai_usage_'.now()->format('Y_m');
        $usage = Setting::get($key) ?? ['input' => 0, 'output' => 0, 'usd' => 0.0, 'calls' => 0];
        [$pIn, $pOut] = self::PRICES[$model] ?? self::PRICES['claude-opus-5-5'];
        $usage['input'] += $in;
        $usage['output'] += $out;
        $usage['calls'] += 1;
        $usage['usd'] = round($usage['usd'] + ($in * $pIn + $out * $pOut) / 1_000_000, 4);
        Setting::put($key, $usage);
    }

    public static function thisMonth(): array
    {
        return Setting::get('ai_usage_'.now()->format('Y_m')) ?? ['input' => 0, 'output' => 0, 'usd' => 0.0, 'calls' => 0];
    }

    public static function guard(): void
    {
        $cap = (float) (Setting::get('ai_monthly_cap_usd') ?? 40);
        if ($cap > 0 && self::thisMonth()['usd'] >= $cap) {
            throw new RuntimeException(sprintf('This month’s AI spending cap ($%s) is reached. Raise it in Settings › AI to continue.', $cap));
        }
    }
}
