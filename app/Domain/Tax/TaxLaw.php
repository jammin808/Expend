<?php

namespace App\Domain\Tax;

use RuntimeException;

/**
 * The tax law the assistant works from: leading cases (with what they decided and why they matter to a freelance
 * events technician), the legislation, HMRC guidance, recent and coming changes, the line between legal planning and
 * avoidance, and a checklist of legal ways to pay less tax. Each item was checked against an official source by two
 * independent checkers (resources/tax/law.json, dated); only items marked citable may be quoted as authority.
 */
final class TaxLaw
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $path = resource_path('tax/law.json');
            $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (! is_array($json)) {
                throw new RuntimeException('The tax law library is missing.');
            }
            self::$data = $json;
        }

        return self::$data;
    }

    public static function researchedOn(): ?string
    {
        return self::data()['researched_on'] ?? null;
    }

    /** One item of any kind by its id (a case, an Act's section, a manual page, a development, a checklist entry). */
    public static function find(string $id): ?array
    {
        foreach (['cases' => 'case', 'legislation' => 'legislation', 'guidance' => 'guidance', 'developments' => 'development', 'savings_checklist' => 'saving'] as $list => $type) {
            foreach (self::data()[$list] ?? [] as $item) {
                if (($item['id'] ?? null) === $id) {
                    return $item + ['type' => $type];
                }
            }
        }

        return null;
    }

    /**
     * Search by words (and optionally by kind or topic), most relevant first.
     *
     * @return list<array>
     */
    public static function search(string $query, ?string $type = null, ?string $topic = null, int $limit = 8): array
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [], fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['the', 'and', 'for', 'can', 'what', 'how', 'claim', 'tax'], true)));
        $lists = ['case' => 'cases', 'legislation' => 'legislation', 'guidance' => 'guidance', 'development' => 'developments'];
        $hits = [];
        foreach ($lists as $kind => $list) {
            if ($type && $type !== $kind) {
                continue;
            }
            foreach (self::data()[$list] ?? [] as $item) {
                if ($topic && ! in_array($topic, $item['topics'] ?? [], true)) {
                    continue;
                }
                $title = mb_strtolower(($item['name'] ?? '').' '.($item['citation'] ?? ''));
                $body = mb_strtolower(implode(' ', array_filter([$item['principle'] ?? null, $item['what_it_does'] ?? null, $item['what_it_says'] ?? null, $item['what_changes'] ?? null,
                    $item['relevance'] ?? null, implode(' ', $item['topics'] ?? [])])));
                $score = $words ? 0 : 1;
                foreach ($words as $w) {
                    $score += (str_contains($title, $w) ? 5 : 0) + min(substr_count($body, $w), 3);
                }
                if ($score > 0) {
                    $hits[] = ['score' => $score, 'item' => self::present($item, $kind)];
                }
            }
        }
        usort($hits, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($h) => $h['item'], array_slice($hits, 0, $limit));
    }

    /** What an item says, shaped for the assistant: citable items carry their link, others say why not. */
    public static function present(array $item, string $kind): array
    {
        $out = array_filter([
            'id' => $item['id'] ?? null, 'kind' => $kind, 'name' => $item['name'] ?? null, 'citation' => $item['citation'] ?? null,
            'court' => $item['court'] ?? null, 'year' => $item['year'] ?? null, 'date' => $item['date'] ?? null,
            'principle' => $item['principle'] ?? null, 'what_it_does' => $item['what_it_does'] ?? null, 'what_it_says' => $item['what_it_says'] ?? null,
            'what_changes' => $item['what_changes'] ?? null, 'applies_from' => $item['applies_from'] ?? null,
            'relevance' => $item['relevance'] ?? null, 'status' => $item['status'] ?? null, 'status_note' => $item['status_note'] ?? null,
        ], fn ($v) => $v !== null);
        $citable = $kind !== 'case' || ($item['citable'] ?? false);
        if ($citable) {
            $out['url'] = $item['url'] ?? null;
            if (! empty($item['source_note'])) {
                $out['source_note'] = $item['source_note'];
            }
        } else {
            $out['do_not_cite'] = 'Not checked against the judgment: don’t rely on it or quote it as authority.';
        }
        if ($kind === 'development' && in_array($item['status'] ?? '', ['announced', 'proposed'], true)) {
            $out['not_law_yet'] = true;
        }

        return $out;
    }

    /** @return array{allowed:list<string>, refuse:list<string>, explanation:string} */
    public static function planningBoundary(): array
    {
        return self::data()['planning_boundary'] ?? ['allowed' => [], 'refuse' => [], 'explanation' => ''];
    }

    /** @return list<array> */
    public static function savingsChecklist(): array
    {
        return self::data()['savings_checklist'] ?? [];
    }

    /** @return list<array> */
    public static function developments(): array
    {
        return array_map(fn ($d) => self::present($d, 'development'), self::data()['developments'] ?? []);
    }

    /** Every link an answer may cite from the library (citable items only). @return list<string> */
    public static function citableUrls(): array
    {
        $urls = [];
        foreach (['cases', 'legislation', 'guidance', 'developments'] as $list) {
            foreach (self::data()[$list] ?? [] as $item) {
                if ($list === 'cases' && ! ($item['citable'] ?? false)) {
                    continue;
                }
                foreach (array_filter([$item['url'] ?? null, ...($item['alt_urls'] ?? [])]) as $u) {
                    $urls[] = $u;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /** Case names in the library (for checking that an answer only names real, checked cases). @return array<string, bool> lower-case short name => citable */
    public static function caseNames(): array
    {
        $out = [];
        foreach (self::data()['cases'] ?? [] as $c) {
            $short = self::shortName((string) ($c['name'] ?? ''));
            if ($short !== '') {
                $out[$short] = (bool) ($c['citable'] ?? false);
            }
        }

        return $out;
    }

    /** Words that don't identify a party ("Ltd", "Inspector of Taxes"); HMRC's many names become "hmrc". */
    private const PARTY_NOISE = ['in', 'the', 'see', 'and', 'others', 'another', 'of', 'for', 'ltd', 'limited', 'plc', 'llp', 'co', 'mr', 'mrs', 'ms', 'dr',
        'inspector', 'taxes', 'surveyor', 'hmit', 'hm', 'case', 'cases', 'remitted', 'hearing', 'often', 'cited', 'as', 'with', 'formerly', 'liquidation', 'per', 'also', 'eg', 'cf'];

    /** Words that appear in comparisons of options ("Flat Rate v Actual Costs"), not in parties' names. */
    private const TOPIC_WORDS = ['cash', 'accruals', 'basis', 'actual', 'actuals', 'costs', 'cost', 'flat', 'rate', 'rates', 'simplified', 'mileage', 'class', 'vat',
        'sole', 'trader', 'trading', 'company', 'employment', 'self', 'employed', 'self-employed', 'self-employment', 'pension', 'profit', 'profits', 'income', 'tax',
        'allowance', 'allowances', 'salary', 'dividend', 'dividends', 'partnership', 'umbrella', 'ir35', 'paye', 'before', 'after', 'old', 'new', 'rules', 'method',
        'option', 'plan', 'standard', 'scheme', 'capital', 'lease', 'buy', 'hire', 'renting', 'owning', 'expenses', 'expense', 'home', 'office', 'uk', 'abroad'];

    private const HMRC_NAMES = ['hmrc', 'revenue', 'customs', 'commissioners', 'irc', 'inland', 'crown'];

    /** @return list<string> the identifying words of one side of a case name */
    public static function partyWords(string $side): array
    {
        $words = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower(str_replace('’', "'", $side))) ?: [];
        $out = [];
        foreach ($words as $w) {
            $w = trim($w, "'");
            if ($w === '' || in_array($w, self::PARTY_NOISE, true)) {
                continue;
            }
            $out[] = in_array($w, self::HMRC_NAMES, true) ? 'hmrc' : $w;
        }

        return array_values(array_unique($out));
    }

    /** A side's words plus its initials, so "Barclays Mercantile Business Finance Ltd" also answers to "BMBF". */
    private static function withInitials(string $side): array
    {
        $words = self::partyWords($side);
        $initials = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_diff($words, ['hmrc'])));

        return mb_strlen($initials) >= 3 ? [...$words, $initials] : $words;
    }

    /**
     * Whether a case named "first v second" is in the library: true if it is and may be cited, false if it is but
     * mustn't be relied on, null if it isn't there. Either order of the parties matches (Powell v Jackman).
     *
     * @param  list<string>  $first
     * @param  list<string>  $second
     */
    public static function matchCase(array $first, array $second): ?bool
    {
        $distinct = fn (array $w) => array_values(array_diff($w, ['hmrc', 'r']));
        if (! $distinct($first) && ! $distinct($second)) {
            return null;
        }
        $found = null;
        foreach (self::data()['cases'] ?? [] as $c) {
            foreach (preg_split('/;\s*/', (string) ($c['name'] ?? '')) as $name) {
                $name = preg_replace('/\((?:often cited as\s*)?([^)]*)\)/i', ' ( $1 ) ', $name);
                if (! preg_match('/^(.*?)\s+v\.?\s+(.*)$/u', $name, $m)) {
                    continue;
                }
                [$a, $b] = [self::withInitials(preg_replace('/\([^)]*\)/', '', $m[1])), self::withInitials(preg_replace('/\([^)]*\)/', '', $m[2]))];
                $also = self::partyWords($name);
                $sideMatches = fn (array $mention, array $side) => (bool) array_intersect($distinct($mention) ?: $mention, $side);
                $hit = ($sideMatches($first, $a) && $sideMatches($second, $b)) || ($sideMatches($first, $b) && $sideMatches($second, $a))
                    || (str_contains(mb_strtolower($c['name']), 'often cited as') && (bool) array_intersect($distinct($first), $also) && (bool) array_intersect($distinct($second), $also));
                if ($hit) {
                    if ($c['citable'] ?? false) {
                        return true;
                    }
                    $found = false;
                }
            }
        }

        return $found;
    }

    /**
     * Case names written in a piece of text ("Mallalieu v Drummond", "HMRC v Healy", "R v Dimsey").
     *
     * @return list<array{text:string, first:list<string>, second:list<string>}>
     */
    public static function casesNamedIn(string $text): array
    {
        // Capitalised words (or &) joined by single spaces, so a match never runs across punctuation into the next sentence.
        $word = '(?:[A-Z][\p{L}\'’\-]*|&)';
        preg_match_all('/((?:'.$word.' ){0,3}'.$word.') v\.? ('.$word.'(?: '.$word.'){0,3})/u', $text, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $hit) {
            $first = self::partyWords($hit[1]);
            $second = self::partyWords($hit[2]);
            // "Cash Basis v Accruals" compares two options; it isn't a case.
            $topic = fn (array $w) => ! array_diff($w, self::TOPIC_WORDS);
            if (! $first || ! $second || $topic($first) || $topic($second)) {
                continue;
            }
            $out[] = ['text' => trim($hit[1]).' v '.trim($hit[2]), 'first' => $first, 'second' => $second];
        }

        return $out;
    }

    /** "Mallalieu (Inspector of Taxes) v Drummond" → "mallalieu v drummond". */
    public static function shortName(string $name): string
    {
        $name = preg_replace('/\([^)]*\)/', '', $name);
        if (! preg_match('/([\p{L}\'\-]+)[\p{L}\s.,&\'\-]*?\s+v\.?\s+([\p{L}\'\-]+)/u', $name, $m)) {
            return '';
        }

        return mb_strtolower($m[1].' v '.$m[2]);
    }
}
