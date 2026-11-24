<?php

namespace App\Domain\Intel;

use Carbon\CarbonImmutable;

/**
 * Orders findings within each priority of the to-do list, and says how serious each is (docs/intelligence-cycle.md
 * section 4.5). The to-do list's fixed group priorities stay as floors: the Ranker only orders the items of one
 * priority, most at stake first.
 *
 *   score    = |amount at stake, in one direction| × grade weight (Grades::WEIGHTS) × urgency
 *   urgency  = 3 when due within 7 days (or overdue), 2 within 30, 1.5 within 60, otherwise 1
 *
 * Money with no direction, or 'none' (a payment to decide, a stay, a payment to HMRC to record), and an item with no
 * amount score 0, and keep the list's own order. Amounts are compared as they are, in hundredths of their currency:
 * the order is a guide, never a total.
 *
 * Severity: critical for a broken audit log, a deadline or penalty step within 7 days, or a score of £500 or more;
 * important for a score of £50 or more, anything due within 60 days, a failed check, or Expend not working;
 * otherwise info.
 */
final class Ranker
{
    public const SEVERITIES = ['critical', 'important', 'info'];

    public const CRITICAL_PENCE = 50000;

    public const IMPORTANT_PENCE = 5000;

    /** Kinds whose due date is a deadline or a penalty step. */
    private const DEADLINES = ['tax.deadline', 'tax.penalty', 'tax.submission'];

    public static function urgency(?string $dueOn): float
    {
        $days = self::daysUntil($dueOn);

        return match (true) {
            $days === null => 1.0,
            $days <= 7 => 3.0,
            $days <= 30 => 2.0,
            $days <= 60 => 1.5,
            default => 1.0,
        };
    }

    public static function score(?int $amount, ?string $direction, ?string $grade, ?string $dueOn): float
    {
        if ($amount === null || $amount === 0 || $direction === null || $direction === 'none') {
            return 0.0;
        }

        return round(abs($amount) * Grades::weight($grade) * self::urgency($dueOn), 2);
    }

    /** @param  array{code?:?string, group?:string, amount?:?int, direction?:?string, grade?:?string, due_on?:?string}  $item */
    public static function severity(array $item): string
    {
        $code = $item['code'] ?? null;
        $score = self::score(isset($item['amount']) ? (int) $item['amount'] : null, $item['direction'] ?? null, $item['grade'] ?? null, $item['due_on'] ?? null);
        $days = self::daysUntil($item['due_on'] ?? null);
        if ($code === 'health.audit_chain' || (in_array($code, self::DEADLINES, true) && $days !== null && $days <= 7) || $score >= self::CRITICAL_PENCE) {
            return 'critical';
        }
        if ($score >= self::IMPORTANT_PENCE || ($days !== null && $days <= 60) || $code === 'check.failed' || ($item['group'] ?? null) === 'health' || str_starts_with((string) $code, 'health.')) {
            return 'important';
        }

        return 'info';
    }

    /**
     * The item with its score and severity added.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function annotate(array $item): array
    {
        return $item + [
            'score' => self::score(isset($item['amount']) ? (int) $item['amount'] : null, $item['direction'] ?? null, $item['grade'] ?? null, $item['due_on'] ?? null),
            'severity' => self::severity($item),
        ];
    }

    /**
     * Most urgent first: by priority, then severity (critical before important before info), then most at stake
     * (score), then the list's own order of kinds, then as found. So a deadline days away, with no money of its own,
     * still comes before money that can wait.
     *
     * @param  list<array<string, mixed>>  $items  annotated (annotate())
     * @param  array<string, int>  $groupOrder  each kind's place in the list
     * @return list<array<string, mixed>>
     */
    public static function sort(array $items, array $groupOrder): array
    {
        $n = 0;
        $keyed = array_map(function ($i) use (&$n) {
            return [$i, $n++];
        }, $items);
        $severity = array_flip(self::SEVERITIES);
        $rank = fn (array $p) => [$p[0]['priority'], $severity[$p[0]['severity'] ?? 'info'] ?? 2, -($p[0]['score'] ?? 0), $groupOrder[$p[0]['group']] ?? PHP_INT_MAX, $p[1]];
        usort($keyed, fn ($a, $b) => $rank($a) <=> $rank($b));

        return array_map(fn ($p) => $p[0], $keyed);
    }

    /** Days from today until a date (negative when past), or null with no date. */
    private static function daysUntil(?string $dueOn): ?int
    {
        if ($dueOn === null || $dueOn === '') {
            return null;
        }

        return (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($dueOn)->startOfDay(), false);
    }
}
