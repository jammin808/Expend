<?php

namespace App\Domain\Tax;

use RuntimeException;

/**
 * The verified research on structures and tax planning for a UK sole trader (resources/tax/planning.json, dated):
 * business structures (sole trader, company, partnership, LLP), planning techniques, family and trust measures,
 * grants, loans and support, traps to avoid, leading cases, and the rates for each tax year. Every item was checked
 * against a primary source (legislation.gov.uk, GOV.UK, HMRC manuals) and carries its link, its legality, its status
 * (law, announced or proposed) and its figures for each year.
 *
 * Legality decides where an item may appear: only legal_planning_with_conditions and relief_intended_by_parliament
 * are ever offered as ideas. avoid_targeted_by_hmrc and illegal items are shown only as things to avoid.
 */
final class Planning
{
    public const SECTIONS = ['structures', 'techniques', 'family_and_trusts', 'funding', 'traps', 'cases'];

    public const LEGALITIES = ['legal_planning_with_conditions', 'relief_intended_by_parliament', 'avoid_targeted_by_hmrc', 'illegal'];

    /** Legal planning that may be suggested. */
    public const LEGAL = ['legal_planning_with_conditions', 'relief_intended_by_parliament'];

    /** Never suggested: shown only as something to avoid. */
    public const AVOID = ['avoid_targeted_by_hmrc', 'illegal'];

    public const FUNDING_TYPES = ['grant', 'loan', 'bursary', 'support'];

    /** The tax years the rates cover. 2027-28 is held as "2027-28_announced": parts of it are announced, not yet law. */
    public const YEARS = ['2024-25', '2025-26', '2026-27', '2027-28'];

    /** What an assistant or page must say about an item it must never recommend. */
    public const NEVER_RECOMMEND = 'HMRC targets this as tax avoidance, or it is illegal. Never suggest it: explain it only as something to avoid, and why.';

    /**
     * What an assistant or page must say about a trap that is lawful in itself (goodwill on incorporation, director's
     * loans, IR35, spouse shares): it is a warning about where lawful planning goes wrong, never an idea.
     */
    public const PITFALL = 'A pitfall of lawful planning, from the research’s traps: explain it as a warning, never as a way to save tax.';

    /** Whether an item is a warning, never an idea: every trap, whatever its legality. */
    public static function isPitfall(array $item): bool
    {
        return ($item['section'] ?? null) === 'traps' && ! self::mustAvoid($item);
    }

    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $path = resource_path('tax/planning.json');
            $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($json)) {
                throw new RuntimeException('The planning research is missing.');
            }
            self::$data = $json;
        }

        return self::$data;
    }

    public static function researchedOn(): ?string
    {
        return self::data()['researched_on'] ?? null;
    }

    /** The key in the rates block for a tax year: 2027-28 is "2027-28_announced". */
    public static function ratesKey(string $year): ?string
    {
        $rates = self::data()['rates'] ?? [];
        foreach ([$year, $year.'_announced'] as $key) {
            if (isset($rates[$key])) {
                return $key;
            }
        }

        return null;
    }

    /** The rates for one tax year (amounts in pounds, rates as fractions), or null for a year not researched. */
    public static function rates(string $year): ?array
    {
        $key = self::ratesKey($year);

        return $key ? self::data()['rates'][$key] : null;
    }

    /** Whether a year's rates include figures only announced, not yet law. */
    public static function announced(string $year): bool
    {
        return str_ends_with((string) self::ratesKey($year), '_announced');
    }

    /** One item of any section by its id, with the section it came from. */
    public static function item(string $id): ?array
    {
        foreach (self::SECTIONS as $section) {
            foreach (self::data()[$section] ?? [] as $item) {
                if (($item['id'] ?? null) === $id) {
                    return $item + ['section' => $section];
                }
            }
        }

        return null;
    }

    /**
     * Find items by words, most relevant first, over name, summary (a trap's "why", a case's principle), conditions and
     * relevance. Optionally only one section or one legality. With no words, every item that passes the filters, in order.
     *
     * @return list<array> items with their section
     */
    public static function search(string $words, ?string $section = null, ?string $legality = null, int $limit = 10): array
    {
        $terms = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($words)) ?: [],
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['the', 'and', 'for', 'can', 'what', 'how', 'tax', 'with', 'should', 'does', 'are', 'about', 'use'], true)));
        $hits = [];
        $n = 0;
        foreach (self::SECTIONS as $s) {
            if ($section && $section !== $s) {
                continue;
            }
            foreach (self::data()[$s] ?? [] as $item) {
                $n++;
                if ($legality && ($item['legality'] ?? null) !== $legality) {
                    continue;
                }
                $name = mb_strtolower((string) ($item['name'] ?? ''));
                $body = mb_strtolower(implode(' ', array_map('strval', array_filter([
                    self::summaryOf($item), $item['conditions'] ?? null, $item['relevance'] ?? null,
                ], fn ($v) => is_string($v)))));
                $score = $terms ? 0 : 1;
                foreach ($terms as $w) {
                    $score += (str_contains($name, $w) ? 5 : 0) + min(substr_count($body, $w), 3);
                }
                if ($score > 0) {
                    $hits[] = ['score' => $score, 'order' => $n, 'item' => $item + ['section' => $s]];
                }
            }
        }
        usort($hits, fn ($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_map(fn ($h) => $h['item'], array_slice($hits, 0, max(1, $limit)));
    }

    /** What an item is about, in its own words: a summary, a trap's "why" or a case's principle. */
    public static function summaryOf(array $item): string
    {
        return (string) ($item['summary'] ?? $item['why'] ?? $item['principle'] ?? '');
    }

    /** Whether an item may be suggested: legal planning or a relief Parliament intends. */
    public static function isLegal(array $item): bool
    {
        return in_array($item['legality'] ?? null, self::LEGAL, true);
    }

    /** Whether an item must never be recommended (HMRC targets it, or it is illegal). */
    public static function mustAvoid(array $item): bool
    {
        return in_array($item['legality'] ?? null, self::AVOID, true);
    }

    /** @return list<array> the business structures */
    public static function structures(): array
    {
        return self::tagged('structures');
    }

    /**
     * Ideas the owner may use: techniques and family and trust measures that are legal planning with conditions or a
     * relief Parliament intends. Anything HMRC targets or that is illegal is left out (it is in avoid()).
     *
     * @return list<array>
     */
    public static function ideas(): array
    {
        return array_values(array_filter([...self::tagged('techniques'), ...self::tagged('family_and_trusts')], fn ($i) => self::isLegal($i)));
    }

    /** @return list<array> grants, loans, bursaries and support, each with who, amount and how it is taxed */
    public static function funding(): array
    {
        return self::tagged('funding');
    }

    /**
     * Things to avoid: every trap, then any technique or family measure that HMRC targets as avoidance or that is
     * illegal (those are never offered as ideas). Each carries why.
     *
     * @return list<array>
     */
    public static function avoid(): array
    {
        $others = array_filter([...self::tagged('techniques'), ...self::tagged('family_and_trusts')], fn ($i) => self::mustAvoid($i));

        return array_values([...self::tagged('traps'), ...array_map(fn ($i) => $i + ['why' => $i['summary'] ?? ''], $others)]);
    }

    /** @return list<string> */
    public static function notes(): array
    {
        return self::data()['notes'] ?? [];
    }

    /** One item's link, name and id, for listing as a source. */
    public static function source(string $id): ?array
    {
        $item = self::item($id);

        return $item && ! empty($item['url']) ? ['id' => $id, 'title' => $item['name'] ?? $id, 'url' => $item['url']] : null;
    }

    /**
     * An item's figures that matter for one tax year, as labelled lines: that year's entry (or the announced one),
     * "current", and any figure not tied to a year (a grant's maximum, a rate). Other years are left out. A figure the
     * research couldn't find (null) says so.
     *
     * @return list<array{label:string, text:string}>
     */
    public static function figureLines(array $item, string $year): array
    {
        $figures = $item['figures'] ?? null;
        if (! is_array($figures)) {
            return is_string($figures) && $figures !== '' ? [['label' => $year, 'text' => $figures]] : [];
        }
        $lines = [];
        foreach ($figures as $key => $value) {
            $key = (string) $key;
            $isYear = (bool) preg_match('/^\d{4}-\d{2}/', $key);
            if ($isYear && ! str_starts_with($key, $year)) {
                continue;
            }
            $text = match (true) {
                $value === null => 'Not published (the research found no official figure).',
                is_bool($value) => $value ? 'Yes' : 'No',
                is_int($value) => number_format($value),
                is_float($value) => rtrim(rtrim(sprintf('%.6F', $value), '0'), '.'),
                is_string($value) => $value,
                default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
            $label = $isYear || $key === 'current' ? ($key === 'current' ? 'Now' : str_replace('_announced', '', $key))
                : ucfirst(trim((string) preg_replace(['/_gbp(?=_|$)/', '/_pct(?=_|$)/', '/_/', '/\s+/'], [' (£)', ' (%)', ' ', ' '], $key)));
            $lines[] = ['label' => $label, 'text' => $text];
        }

        return $lines;
    }

    /** @return list<array> a section's items, each with its section */
    private static function tagged(string $section): array
    {
        return array_map(fn ($i) => $i + ['section' => $section], self::data()[$section] ?? []);
    }
}
