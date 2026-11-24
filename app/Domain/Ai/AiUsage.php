<?php

namespace App\Domain\Ai;

use App\Domain\Documents\AiConfig;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonSerializable;
use RuntimeException;
use stdClass;

/**
 * Tracks token use per month and stops AI calls once the monthly spending cap is reached.
 * Costs are an estimate from published per-token prices (USD) and are labelled as such.
 *
 * Reserve, then settle: before a call, guard() sets aside the most the call could cost and refuses it if that could
 * take the month past the cap; after it, record() adds what it really cost and drops the reservation (release() drops
 * it when the call failed). Each changes the month's figures with the settings row locked, so calls running at the
 * same time (the queue worker and a web request) each count in full and can't both squeeze under the cap.
 */
final class AiUsage
{
    /** USD per million tokens: [input, output]. */
    public const PRICES = [
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-fable-5-2' => [8.0, 40.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
    ];

    /** USD per web search (reading a page found has no extra charge beyond its tokens). */
    public const WEB_SEARCH_USD = 0.01;

    /** A reservation whose call never reported back (the process stopped mid-call) lapses after this long. */
    public const RESERVATION_MINUTES = 30;

    /** For the estimate: tokens for one page of a PDF, one image, the results of one web search, one page read. */
    private const PDF_PAGE_TOKENS = 3000;

    private const IMAGE_TOKENS = 2000;

    private const SEARCH_RESULT_TOKENS = 10_000;

    private const FETCH_TOKENS = 40_000;

    private const EMPTY = ['input' => 0, 'output' => 0, 'usd' => 0.0, 'calls' => 0];

    /**
     * Add a call's real cost to this month, and drop its reservation.
     *
     * @param  string|null  $reservation  what guard() returned for this call
     */
    public static function record(string $model, int $in, int $out, int $searches = 0, ?string $reservation = null): void
    {
        [$pIn, $pOut] = self::PRICES[$model] ?? self::PRICES['claude-opus-5-5'];
        $cost = ($in * $pIn + $out * $pOut) / 1_000_000 + $searches * self::WEB_SEARCH_USD;
        [$month, $id] = self::split($reservation);

        $key = self::key();
        self::change($key, function (array &$usage) use ($in, $out, $searches, $cost, $id) {
            $usage['input'] += $in;
            $usage['output'] += $out;
            $usage['calls'] += 1;
            $usage['web_searches'] = ($usage['web_searches'] ?? 0) + $searches;
            $usage['usd'] = round($usage['usd'] + $cost, 4);
            self::drop($usage, $id);
        });
        if ($id !== null && $month !== $key) {
            // Reserved last month, settled this month. Done after, on its own: should it not happen, the reservation
            // lapses anyway, and last month's figures can't hold up this month's.
            self::release(self::join($month, $id));
        }
    }

    /** This month's figures (the reservations of calls under way only as their total, 'reserved'). */
    public static function thisMonth(): array
    {
        return array_diff_key((array) (Setting::get(self::key()) ?? []), ['reservations' => true]) + self::EMPTY;
    }

    /**
     * Check the cap before a call, and set aside the most it could cost.
     *
     * @param  float  $reserveUsd  the call's maximum cost (see estimate()); 0 only checks the cap
     * @return string|null the reservation, for record() or release(); null when nothing was set aside
     *
     * @throws RuntimeException when the cap is reached, or the call could take the month past it
     */
    public static function guard(float $reserveUsd = 0.0): ?string
    {
        $cap = (float) (Setting::get('ai_monthly_cap_usd') ?? 40);
        $key = self::key();

        return self::change($key, function (array &$usage) use ($cap, $reserveUsd, $key) {
            $stale = now()->subMinutes(self::RESERVATION_MINUTES)->getTimestamp();
            $usage['reservations'] = array_filter((array) ($usage['reservations'] ?? []), fn ($r) => (int) ($r['at'] ?? 0) >= $stale);
            $reserved = array_sum(array_column($usage['reservations'], 'usd'));
            if ($cap > 0 && $usage['usd'] >= $cap) {
                throw new RuntimeException(sprintf('This month’s AI spending cap ($%s) is reached. Raise it in Settings › AI to continue.', $cap));
            }
            if ($cap > 0 && $cap < $usage['usd'] + $reserved + max(0.0, $reserveUsd)) {
                throw new RuntimeException(sprintf('This month’s AI spending cap ($%s) is nearly reached: this request could go over it. Raise the cap in Settings › AI to continue.', $cap));
            }
            if ($reserveUsd <= 0) {
                return null;
            }
            $id = Str::lower(Str::random(12));
            $usage['reservations'][$id] = ['usd' => round($reserveUsd, 4), 'at' => now()->getTimestamp()];
            $usage['reserved'] = round($reserved + $reserveUsd, 4);

            return self::join($key, $id);
        });
    }

    /** Drop a reservation whose call failed before it cost anything. */
    public static function release(?string $reservation): void
    {
        [$month, $id] = self::split($reservation);
        if ($id === null) {
            return;
        }
        self::change($month, fn (array &$usage) => self::drop($usage, $id));
    }

    /**
     * The most a request could cost, in USD: its text (counted generously at three characters a token), each PDF page
     * and image, the web searches and page reads its tools allow, and every output token it may write.
     *
     * @param  array<string, mixed>  $params  the request as sent (camelCase keys, as the SDK takes them)
     */
    public static function estimate(array $params): float
    {
        [$pIn, $pOut] = self::PRICES[(string) ($params['model'] ?? AiConfig::model())] ?? self::PRICES['claude-opus-5-5'];
        $input = self::tokensIn([$params['system'] ?? null, $params['messages'] ?? [], $params['tools'] ?? []]);
        $searches = 0;
        foreach ((array) ($params['tools'] ?? []) as $tool) {
            $type = (string) (is_array($tool) ? ($tool['type'] ?? '') : '');
            $uses = (int) ($tool['maxUses'] ?? $tool['max_uses'] ?? 5);
            if (str_starts_with($type, 'web_search_')) {
                $searches += $uses;
                $input += $uses * self::SEARCH_RESULT_TOKENS;
            } elseif (str_starts_with($type, 'web_fetch_')) {
                $input += $uses * (int) ($tool['maxContentTokens'] ?? $tool['max_content_tokens'] ?? self::FETCH_TOKENS);
            }
        }
        $output = (int) ($params['maxTokens'] ?? $params['max_tokens'] ?? 4096);

        return round(($input * $pIn + $output * $pOut) / 1_000_000 + $searches * self::WEB_SEARCH_USD, 4);
    }

    /** Tokens for a piece of a request, counted generously: three characters a token, files by their pages. */
    private static function tokensIn(mixed $part): int
    {
        if ($part instanceof JsonSerializable) {
            // An earlier answer sent back as the SDK gave it (its text, the search results and pages read with it).
            $part = $part->jsonSerialize();
        }
        if ($part instanceof stdClass) {
            $part = (array) $part;
        }
        if (is_string($part)) {
            return intdiv(strlen($part), 3) + 1;
        }
        if (! is_array($part)) {
            return 0;
        }
        if (($part['type'] ?? null) === 'base64' && isset($part['data'])) {
            $media = (string) ($part['mediaType'] ?? $part['media_type'] ?? '');
            if ($media !== 'application/pdf') {
                return self::IMAGE_TOKENS;
            }
            $bytes = (string) base64_decode((string) $part['data'], true);
            $pages = preg_match_all('/\/Type\s*\/Page(?![a-zA-Z])/', $bytes) ?: max(1, intdiv(strlen($bytes), 50_000));

            return $pages * self::PDF_PAGE_TOKENS;
        }
        $tokens = 0;
        foreach ($part as $value) {
            $tokens += self::tokensIn($value);
        }

        return $tokens;
    }

    private static function key(): string
    {
        return 'ai_usage_'.now()->format('Y_m');
    }

    /**
     * Change one month's figures with its settings row locked until the change is saved.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>&):T  $change
     * @return T
     */
    private static function change(string $key, callable $change): mixed
    {
        // The month's row is made first, on its own. Made inside the locked change, the first two calls of a month
        // could each lock the place of the missing row and then wait for the other to let go of it to add it, and
        // the database would stop one of them.
        if (! Setting::query()->whereKey($key)->exists()) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => json_encode(self::EMPTY), 'created_at' => now(), 'updated_at' => now()]);
        }

        // Should the database still stop it in favour of another call, it's tried again from a fresh read.
        return DB::transaction(function () use ($key, $change) {
            $value = Setting::query()->whereKey($key)->lockForUpdate()->value('value');
            $usage = ((array) json_decode((string) ($value ?? json_encode(self::EMPTY)), true)) + self::EMPTY;
            $result = $change($usage);
            Setting::put($key, $usage);

            return $result;
        }, 3);
    }

    private static function drop(array &$usage, ?string $id): void
    {
        if ($id === null || ! isset($usage['reservations'][$id])) {
            return;
        }
        unset($usage['reservations'][$id]);
        $usage['reserved'] = round(array_sum(array_column($usage['reservations'], 'usd')), 4);
    }

    private static function join(string $month, string $id): string
    {
        return $month.'|'.$id;
    }

    /** @return array{0:string, 1:?string} the month's settings key and the reservation's id */
    private static function split(?string $reservation): array
    {
        if ($reservation === null || ! str_contains($reservation, '|')) {
            return [self::key(), null];
        }
        [$month, $id] = explode('|', $reservation, 2);

        return [preg_match('/^ai_usage_\d{4}_\d{2}$/', $month) ? $month : self::key(), $id !== '' ? $id : null];
    }
}
