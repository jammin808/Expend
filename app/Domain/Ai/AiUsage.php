<?php

namespace App\Domain\Ai;

use App\Domain\Documents\AiConfig;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Tracks token use per month and stops AI calls once the monthly spending cap is reached.
 * Costs are an estimate from published per-token prices (USD) and are labelled as such.
 *
 * Reserve, then settle: before a call, guard() sets aside the most the call could cost and refuses it if that could
 * take the month past the cap; after it, record() adds what it really cost and drops the reservation (release() drops
 * it when the call failed). Each changes the month's figures with the settings row locked, so calls running at the
 * same time (the queue worker and a web request) each count in full and can't both squeeze under the cap.
 *
 * Purposes (docs/intelligence-cycle.md 6.2): every call is made for one (withPurpose(); a call made for none is yours:
 * the assistant, or Ask AI or Read with AI pressed on a page). Once the nightly cycle is on (sharesApply()), the cap is
 * shared out: the assistant's share (intel_ai_reserve_pct, 30% by default) is a reserve the cycle never touches; the
 * rest is split between reading documents (40% of the cap), double-checks (10%) and finding jobs and links (20%). From
 * the 21st a purpose may use what the other two haven't. Your own requests may use whatever the month has left, as
 * before: the reserve is there so the cycle can never use up what you need. Spent before purposes were kept (the month
 * this was released) counts against the cycle's part, so the reserve holds from the first night. While the cycle is
 * off or on trial, every call may use what the month has left, as before it (the shares come with the cycle that reads
 * what they hold back again). What each purpose spent is kept in the month's by_purpose either way.
 *
 * A purpose can also carry a ceiling (a cycle run's allowance for the night): a call that could take it past the
 * ceiling is refused, and the code around it is told (withPurpose()'s tally) so it can stop with 'budget'.
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

    /** What AI is used for. A call made for none is the assistant's: your own request. */
    public const PURPOSES = ['assistant', 'reading', 'verify', 'discovery'];

    /** How each purpose reads to you. */
    public const PURPOSE_WORDS = ['assistant' => 'your questions', 'reading' => 'reading documents', 'verify' => 'double-checks', 'discovery' => 'finding jobs and links'];

    /**
     * How the cycle's part of the cap (what the assistant's reserve leaves) is split between its purposes: with the
     * default 30% reserve, reading 40%, verify 10% and discovery 20% of the cap.
     */
    private const CYCLE_WEIGHTS = ['reading' => 40, 'verify' => 10, 'discovery' => 20];

    /** From this day of the month, a purpose may use what the cycle's other purposes haven't (never the assistant's). */
    public const BORROW_FROM_DAY = 21;

    /** The code of every refusal for want of budget: the cap, a purpose's share or a run's allowance (refused()). */
    public const REFUSED = 4029;

    /** @var list<array{purpose:string, ceiling:?float, spent:float, held:array<string, float>, refused:bool}> innermost last */
    private static array $frames = [];

    /**
     * Run $work with every AI call inside it made for $purpose, within $ceilingUsd if given (a cycle run's allowance).
     * Purposes nest: the innermost names the purpose, and every ceiling around it applies.
     *
     * @template T
     *
     * @param  callable():T  $work
     * @param  array{spent:float, refused:bool}|null  $tally  set to what the calls inside cost, and whether one was
     *                                                        refused for want of budget
     * @return T
     */
    public static function withPurpose(string $purpose, callable $work, ?float $ceilingUsd = null, ?array &$tally = null): mixed
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException("Unknown AI purpose “{$purpose}”.");
        }
        self::$frames[] = ['purpose' => $purpose, 'ceiling' => $ceilingUsd === null ? null : max(0.0, $ceilingUsd), 'spent' => 0.0, 'held' => [], 'refused' => false];
        $depth = count(self::$frames);
        try {
            return $work();
        } finally {
            $frame = self::$frames[$depth - 1];
            array_splice(self::$frames, $depth - 1);
            $tally = ['spent' => round($frame['spent'], 6), 'refused' => $frame['refused']];
        }
    }

    /** What the calls made now are for: the innermost withPurpose(), or the assistant (your own request). */
    public static function purpose(): string
    {
        return self::$frames === [] ? 'assistant' : self::$frames[array_key_last(self::$frames)]['purpose'];
    }

    /** Whether $e (or what caused it) is a refusal for want of budget: the cap, a purpose's share or a run's allowance. */
    public static function refused(Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t->getCode() === self::REFUSED || str_contains($t->getMessage(), 'spending cap') || str_contains($t->getMessage(), 'AI allowance')) {
                return true;
            }
        }

        return false;
    }

    /** The monthly cap in USD (0: no cap). */
    public static function cap(): float
    {
        return (float) (Setting::get('ai_monthly_cap_usd') ?? 40);
    }

    /** The assistant's reserve: the share of the cap, in percent, the cycle never uses. */
    public static function reservePct(): float
    {
        return min(100.0, max(0.0, (float) (Setting::get('intel_ai_reserve_pct') ?? 30)));
    }

    /** @return array<string, float> each purpose's share of the cap, in USD */
    public static function shares(?float $cap = null): array
    {
        $cap ??= self::cap();
        $reserve = self::reservePct();
        $out = ['assistant' => round($cap * $reserve / 100, 4)];
        $weights = array_sum(self::CYCLE_WEIGHTS);
        foreach (self::CYCLE_WEIGHTS as $purpose => $weight) {
            $out[$purpose] = round($cap * (100 - $reserve) / 100 * $weight / $weights, 4);
        }

        return $out;
    }

    /** Whether the purposes' shares hold: only once the nightly cycle is on (intel_cycle; CycleRunner::mode()). */
    public static function sharesApply(): bool
    {
        return Setting::get('intel_cycle') === 'on';
    }

    /**
     * What the cycle's purposes (everything but the assistant) have spent this month, in USD, with what was spent
     * before purposes were kept (unlabelled()).
     */
    public static function cycleSpent(): float
    {
        $usage = self::thisMonth();
        $by = array_map('floatval', (array) ($usage['by_purpose'] ?? []));

        return round(array_sum(array_intersect_key($by, self::CYCLE_WEIGHTS)) + self::unlabelled($usage), 4);
    }

    /**
     * What the month spent that no purpose was kept for (before this release): counted against the cycle's part, so
     * the assistant's reserve holds in the month it came in.
     *
     * @param  array<string, mixed>  $usage  the month's figures
     */
    private static function unlabelled(array $usage): float
    {
        $left = (float) ($usage['usd'] ?? 0) - array_sum(array_map('floatval', (array) ($usage['by_purpose'] ?? [])));

        return $left >= 0.001 ? round($left, 4) : 0.0;
    }

    /** How much more $purpose may spend this month, in USD (only reads: nothing is set aside). */
    public static function available(string $purpose): float
    {
        $cap = self::cap();
        if ($cap <= 0) {
            return INF;
        }
        $usage = ((array) (Setting::get(self::key()) ?? [])) + self::EMPTY;
        $stale = now()->subMinutes(self::RESERVATION_MINUTES)->getTimestamp();
        $usage['reservations'] = array_filter((array) ($usage['reservations'] ?? []), fn ($r) => (int) ($r['at'] ?? 0) >= $stale);

        return self::roomFor($purpose, $usage, $cap);
    }

    /**
     * The room left for $purpose: within the month's cap, and for the cycle's purposes (once the cycle is on) within
     * their share (from the 21st, with what the cycle's other purposes haven't used) and within the cycle's part of the
     * cap (less what was spent before purposes were kept).
     *
     * @param  array<string, mixed>  $usage  the month's figures, with only live reservations
     */
    private static function roomFor(string $purpose, array $usage, float $cap): float
    {
        $reservations = (array) ($usage['reservations'] ?? []);
        $room = $cap - (float) $usage['usd'] - array_sum(array_column($reservations, 'usd'));
        if ($purpose === 'assistant' || ! isset(self::CYCLE_WEIGHTS[$purpose]) || ! self::sharesApply()) {
            return max(0.0, round($room, 4));
        }
        $used = array_map('floatval', (array) ($usage['by_purpose'] ?? []));
        foreach ($reservations as $r) {
            $p = (string) ($r['purpose'] ?? 'assistant');
            $used[$p] = ($used[$p] ?? 0.0) + (float) ($r['usd'] ?? 0);
        }
        $shares = self::shares($cap);
        $limit = $shares[$purpose];
        if ((int) now()->day >= self::BORROW_FROM_DAY) {
            foreach (array_keys(self::CYCLE_WEIGHTS) as $other) {
                if ($other !== $purpose) {
                    $limit += max(0.0, $shares[$other] - ($used[$other] ?? 0.0));
                }
            }
        }
        $cycleUsed = array_sum(array_intersect_key($used, self::CYCLE_WEIGHTS)) + self::unlabelled($usage);
        $cyclePart = $cap * (100 - self::reservePct()) / 100;

        return max(0.0, round(min($room, $limit - ($used[$purpose] ?? 0.0), $cyclePart - $cycleUsed), 4));
    }

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
        $current = self::purpose();
        self::change($key, function (array &$usage) use ($in, $out, $searches, $cost, $id, $current) {
            $usage['input'] += $in;
            $usage['output'] += $out;
            $usage['calls'] += 1;
            $usage['web_searches'] = ($usage['web_searches'] ?? 0) + $searches;
            $usage['usd'] = round($usage['usd'] + $cost, 4);
            // Counted for what it was reserved for (or what it's made for now).
            $purpose = (string) ($id !== null ? ($usage['reservations'][$id]['purpose'] ?? $current) : $current);
            $usage['by_purpose'] = (array) ($usage['by_purpose'] ?? []);
            $usage['by_purpose'][$purpose] = round((float) ($usage['by_purpose'][$purpose] ?? 0) + $cost, 4);
            self::drop($usage, $id);
        });
        foreach (self::$frames as $i => $frame) {
            self::$frames[$i]['spent'] += $cost;
            unset(self::$frames[$i]['held'][(string) $id]);
        }
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
        $reserveUsd = max(0.0, $reserveUsd);
        // A run's allowance for tonight, and any other ceiling around this call.
        foreach (self::$frames as $frame) {
            if ($frame['ceiling'] !== null && $frame['spent'] + array_sum($frame['held']) + $reserveUsd > $frame['ceiling'] + 0.000001) {
                self::markRefused();

                throw new RuntimeException(sprintf('Tonight’s AI allowance ($%s) is used up: what’s left carries on in the next run.', number_format($frame['ceiling'], 2)), self::REFUSED);
            }
        }
        $cap = self::cap();
        $key = self::key();
        $purpose = self::purpose();

        try {
            $reservation = self::change($key, function (array &$usage) use ($cap, $reserveUsd, $key, $purpose) {
                $stale = now()->subMinutes(self::RESERVATION_MINUTES)->getTimestamp();
                $usage['reservations'] = array_filter((array) ($usage['reservations'] ?? []), fn ($r) => (int) ($r['at'] ?? 0) >= $stale);
                $reserved = array_sum(array_column($usage['reservations'], 'usd'));
                if ($cap > 0 && $usage['usd'] >= $cap) {
                    throw new RuntimeException(sprintf('This month’s AI spending cap ($%s) is reached. Raise it in Settings › AI to continue.', $cap), self::REFUSED);
                }
                if ($cap > 0 && $cap < $usage['usd'] + $reserved + $reserveUsd) {
                    throw new RuntimeException(sprintf('This month’s AI spending cap ($%s) is nearly reached: this request could go over it. Raise the cap in Settings › AI to continue.', $cap), self::REFUSED);
                }
                if ($cap > 0 && $purpose !== 'assistant' && self::roomFor($purpose, $usage, $cap) < max($reserveUsd, 0.0001)) {
                    throw new RuntimeException(sprintf('This month’s AI spending cap has no room left for %s ($%s of the $%s is set aside for it). It carries on when there’s room: after the 20th, or next month.',
                        self::PURPOSE_WORDS[$purpose] ?? $purpose, number_format(self::shares($cap)[$purpose] ?? 0, 2), $cap), self::REFUSED);
                }
                if ($reserveUsd <= 0) {
                    return null;
                }
                $id = Str::lower(Str::random(12));
                $usage['reservations'][$id] = ['usd' => round($reserveUsd, 4), 'at' => now()->getTimestamp(), 'purpose' => $purpose];
                $usage['reserved'] = round($reserved + $reserveUsd, 4);

                return self::join($key, $id);
            });
        } catch (RuntimeException $e) {
            if ($e->getCode() === self::REFUSED) {
                self::markRefused();
            }

            throw $e;
        }
        if ($reservation !== null) {
            [, $id] = self::split($reservation);
            foreach (self::$frames as $i => $frame) {
                self::$frames[$i]['held'][(string) $id] = $reserveUsd;
            }
        }

        return $reservation;
    }

    /** Tell every purpose around the call that one was refused for want of budget. */
    private static function markRefused(): void
    {
        foreach (self::$frames as $i => $frame) {
            self::$frames[$i]['refused'] = true;
        }
    }

    /** Drop a reservation whose call failed before it cost anything. */
    public static function release(?string $reservation): void
    {
        [$month, $id] = self::split($reservation);
        if ($id === null) {
            return;
        }
        foreach (self::$frames as $i => $frame) {
            unset(self::$frames[$i]['held'][$id]);
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
