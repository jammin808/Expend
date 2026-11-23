<?php

namespace App\Domain\Tax;

use RuntimeException;

/**
 * The UK tax guide the app explains things with: what each expense category is, whether HMRC allows it and when,
 * what evidence to keep, the rules on travel, mileage, use of home, capital allowances, payments on account and
 * more, and legal ways to reduce tax. Researched from GOV.UK and HMRC's manuals, with the source of every rule
 * (resources/tax/guide.json, dated). It explains; it doesn't decide anything.
 */
final class TaxGuide
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $path = resource_path('tax/guide.json');
            $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($json)) {
                throw new RuntimeException('The tax guide is missing.');
            }
            self::$data = $json;
        }

        return self::$data;
    }

    public static function researchedOn(): ?string
    {
        return self::data()['researched_on'] ?? null;
    }

    /** A category's guide, falling back to its parent (travel_rail → travel). */
    public static function category(?string $code): ?array
    {
        if (! $code) {
            return null;
        }
        $categories = self::data()['categories'] ?? [];
        $entry = $categories[$code] ?? $categories[strtok($code, '_')] ?? null;

        return $entry ? $entry + ['code' => isset($categories[$code]) ? $code : strtok($code, '_')] : null;
    }

    public static function topic(string $slug): ?array
    {
        $t = self::data()['topics'][$slug] ?? null;

        return $t ? $t + ['slug' => $slug] : null;
    }

    /** @return list<array> */
    public static function ways(): array
    {
        return self::data()['ways_to_reduce_tax_legally'] ?? [];
    }

    /** @return array<string, string> topic slug => title */
    public static function topicTitles(): array
    {
        return collect(self::data()['topics'] ?? [])->map(fn ($t) => $t['title'] ?? '')->all();
    }

    /**
     * Find guide entries by words, most relevant first.
     *
     * @return list<array{type:string, key:string, title:string, summary:string, score:int}>
     */
    public static function search(string $query, int $limit = 6): array
    {
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [], fn ($w) => mb_strlen($w) >= 3);
        if (! $words) {
            return [];
        }
        $hits = [];
        $score = function (array $entry) use ($words): int {
            $title = mb_strtolower((string) ($entry['title'] ?? ''));
            $body = mb_strtolower(json_encode([$entry['summary'] ?? '', $entry['rules'] ?? [], $entry['how'] ?? '', $entry['examples_allowed'] ?? [], $entry['examples_not_allowed'] ?? []], JSON_UNESCAPED_UNICODE));
            $s = 0;
            foreach ($words as $w) {
                $s += (str_contains($title, $w) ? 5 : 0) + min(substr_count($body, $w), 3);
            }

            return $s;
        };
        foreach (self::data()['categories'] ?? [] as $code => $c) {
            $hits[] = ['type' => 'category', 'key' => $code, 'title' => $c['title'] ?? $code, 'summary' => $c['summary'] ?? '', 'score' => $score($c)];
        }
        foreach (self::data()['topics'] ?? [] as $slug => $t) {
            $hits[] = ['type' => 'topic', 'key' => $slug, 'title' => $t['title'] ?? $slug, 'summary' => $t['summary'] ?? '', 'score' => $score($t)];
        }
        foreach (self::ways() as $i => $w) {
            $hits[] = ['type' => 'way', 'key' => (string) $i, 'title' => $w['title'] ?? '', 'summary' => $w['how'] ?? '', 'score' => $score($w)];
        }
        $hits = array_filter($hits, fn ($h) => $h['score'] > 0);
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_values($hits), 0, $limit);
    }

    /** Every source URL in the guide, for checking that an answer only cites real ones. */
    public static function sourceUrls(): array
    {
        preg_match_all('#https?://[^"\s]+#', json_encode(self::data(), JSON_UNESCAPED_SLASHES), $m);

        return array_values(array_unique($m[0]));
    }
}
