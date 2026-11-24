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

    private const DO_NOT_CITE = 'Not checked against the judgment: don’t rely on it or quote it as authority.';

    /** What an item says, shaped for the assistant: citable items carry their link, others say why not. */
    public static function present(array $item, string $kind): array
    {
        if ($kind === 'saving') {
            return self::presentSaving($item);
        }
        $out = array_filter([
            'id' => $item['id'] ?? null, 'kind' => $kind, 'name' => $item['name'] ?? null, 'citation' => $item['citation'] ?? null,
            'court' => $item['court'] ?? null, 'year' => $item['year'] ?? null, 'date' => $item['date'] ?? null,
            'principle' => $item['principle'] ?? null, 'what_it_does' => $item['what_it_does'] ?? null, 'what_it_says' => $item['what_it_says'] ?? null,
            'what_changes' => $item['what_changes'] ?? null, 'applies_from' => $item['applies_from'] ?? null,
            'relevance' => $item['relevance'] ?? null, 'status' => $item['status'] ?? null, 'status_note' => $item['status_note'] ?? null,
        ], fn ($v) => $v !== null);
        $citable = $kind !== 'case' || ($item['citable'] ?? false);
        if ($citable) {
            if (! empty($item['url'])) {
                $out['url'] = $item['url'];
            }
            if (! empty($item['source_note'])) {
                $out['source_note'] = $item['source_note'];
            }
        } else {
            $out['do_not_cite'] = self::DO_NOT_CITE;
        }
        if ($kind === 'development' && in_array($item['status'] ?? '', ['announced', 'proposed'], true)) {
            $out['not_law_yet'] = true;
        }

        return $out;
    }

    /**
     * A savings checklist entry (the ids tax_savings returns): what it is, when it applies, what it needs, how to
     * estimate it, its risks, and the law behind it. Each piece of law carries its link only when it may be cited.
     * needs_accountant is there (true) only when the entry itself says to ask one.
     */
    public static function presentSaving(array $item): array
    {
        $ids = $item['legal_basis'] ?? [];
        $ids = is_array($ids) ? $ids : (preg_split('/\s*,\s*/', trim((string) $ids)) ?: []);
        $basis = [];
        foreach ($ids as $id) {
            $law = $id !== '' ? self::find((string) $id) : null;
            if (! $law || $law['type'] === 'saving') {
                continue;
            }
            $citable = $law['type'] !== 'case' || ($law['citable'] ?? false);
            $basis[] = array_filter([
                'id' => $law['id'], 'name' => $law['name'] ?? null,
                'url' => $citable && ! empty($law['url']) ? $law['url'] : null,
                'source_note' => $citable && ! empty($law['source_note']) ? $law['source_note'] : null,
                'do_not_cite' => $citable ? null : self::DO_NOT_CITE,
            ], fn ($v) => $v !== null);
        }
        $text = fn (string $key) => isset($item[$key]) && $item[$key] !== '' ? (string) $item[$key] : null;

        return array_filter([
            'id' => $item['id'] ?? null, 'kind' => 'saving', 'title' => $text('title'), 'conditions' => $text('conditions'),
            'data_needed' => $text('data_needed'), 'how_to_estimate' => $text('how_to_estimate'), 'risks' => $text('risks'),
            // Only when the idea's own wording says so: the checklist doesn't record when one isn't needed, so no "false".
            'needs_accountant' => preg_match('/accountant|adviser/i', ($item['risks'] ?? '').' '.($item['conditions'] ?? '')) ? true : null,
            'legal_basis' => $basis,
        ], fn ($v) => $v !== null);
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
    private const PARTY_NOISE = ['in', 'the', 'see', 'and', 'others', 'another', 'ors', 'anor', 'of', 'for', 'ltd', 'limited', 'plc', 'llp', 'co', 'mr', 'mrs', 'ms',
        'miss', 'dr', 'sir', 'dame', 'his', 'her', 'majesty', 'bc', 'inspector', 'taxes', 'surveyor', 'hmit', 'hm', 'case', 'cases', 'remitted', 'hearing', 'often',
        'cited', 'as', 'with', 'formerly', 'liquidation', 'per', 'also', 'eg', 'cf'];

    /**
     * Words that appear in comparisons of options ("Flat Rate v Actual Costs", "PCP vs HP"), not in parties' names. A side
     * made only of these is an option, unless it ends in Ltd or plc, or the other side is HMRC (casesNamedIn).
     */
    private const TOPIC_WORDS = ['cash', 'accruals', 'basis', 'actual', 'actuals', 'costs', 'cost', 'flat', 'rate', 'rates', 'simplified', 'mileage', 'class', 'vat',
        'sole', 'trader', 'trading', 'company', 'employment', 'self', 'employed', 'self-employed', 'self-employment', 'pension', 'profit', 'profits', 'income', 'tax',
        'allowance', 'allowances', 'salary', 'dividend', 'dividends', 'partnership', 'umbrella', 'ir35', 'paye', 'before', 'after', 'old', 'new', 'rules', 'method',
        'option', 'options', 'plan', 'standard', 'scheme', 'capital', 'lease', 'buy', 'hire', 'renting', 'owning', 'expenses', 'expense', 'home', 'office', 'uk', 'abroad',
        'van', 'car', 'vehicle', 'electric', 'petrol', 'diesel', 'hybrid', 'isa', 'sipp', 'contractor', 'employee', 'freelance', 'freelancer', 'fixed', 'variable',
        'monthly', 'annual', 'quarterly', 'leasing', 'buying', 'rent', 'business', 'personal', 'private', 'relief', 'reliefs', 'deduction', 'deductions',
        'aia', 'wda', 'fya', 'pcp', 'hp', 'mtd', 'sa', 'itsa', 'ni', 'nic', 'nics', 'cis', 'full', 'expensing', 'contribution', 'contributions', 'gift', 'aid',
        'making', 'digital', 'assessment', 'purchase', 'contract', 'day', 'fee', 'fees', 'return', 'returns', 'update', 'updates', 'turnover', 'invoice',
        'invoicing', 'receipts', 'payment', 'payments', 'account', 'accounts', 'software', 'spreadsheet', 'paper', 'online', 'loan', 'finance', 'credit', 'debit',
        'card', 'savings', 'scenario', 'year', 'years', 'month', 'week', 'daily', 'weekly', 'hourly', 'registered', 'unregistered', 'registration', 'voluntary',
        'compulsory', 'claim', 'claiming', 'xero', 'freeagent', 'quickbooks', 'sage', 'monzo', 'starling', 'revolut', 'tide'];

    private const HMRC_NAMES = ['hmrc', 'revenue', 'customs', 'commissioners', 'irc', 'inland', 'crown'];

    /** Words that make a side a company, known by its first word rather than a surname. */
    private const COMPANY_WORDS = ['ltd', 'limited', 'plc', 'llp', 'lp', 'co', 'bv', 'inc', 'llc', 'council', 'gmbh'];

    /** The last word of a company's name ("Private Hire Ltd"): such a side is a party, never an option. */
    private const COMPANY_SUFFIXES = ['ltd', 'limited', 'plc', 'llp', 'lp', 'llc', 'inc', 'co'];

    /** Capitalised words that start a sentence or clause ("Following Hall v Lorimer"), not a party's name. */
    private const LEAD_WORDS = ['a', 'an', 'the', 'in', 'see', 'per', 'cf', 'eg', 'ie', 'and', 'but', 'or', 'nor', 'so', 'yet', 'then', 'thus', 'hence', 'also',
        'both', 'as', 'at', 'by', 'from', 'on', 'to', 'for', 'with', 'without', 'within', 'under', 'after', 'before', 'since', 'following', 'applying', 'applied',
        'citing', 'quoting', 'using', 'like', 'unlike', 'compare', 'contrast', 'note', 'here', 'there', 'this', 'that', 'these', 'those', 'where', 'when', 'while',
        'whilst', 'although', 'though', 'if', 'because', 'its', 'it', 'is', 'was', 'our', 'your', 'my', 'their', 'his', 'her', 'not', 'only', 'even', 'however',
        'therefore', 'indeed', 'similarly', 'likewise', 'again', 'later', 'earlier', 'recently', 'famously', 'notably', 'crucially', 'importantly', 'finally',
        'first', 'next', 'lastly', 'meanwhile', 'accordingly', 'nevertheless', 'moreover', 'furthermore', 'additionally', 'key', 'leading', 'landmark', 'relevant',
        'example', 'examples', 'authority', 'authorities', 'judgment', 'judgement', 'decision', 'ruling', 'case', 'cases', 'read', 'remember', 'consider', 'what',
        'which', 'why', 'how', 'did', 'does', 'do', 'can', 'could', 'would', 'should', 'will', 'may', 'might', 'must', 'yes', 'no',
        'despite', 'whereas', 'neither', 'either', 'against', 'during', 'given', 'through', 'throughout', 'today', 'now', 'take', 'taking', 'post', 'pre',
        'unless', 'until', 'upon', 'once', 'whether', 'ultimately', 'eventually', 'subsequently', 'previously', 'originally', 'historically', 'currently',
        'generally', 'usually', 'often', 'sometimes', 'clearly', 'arguably', 'perhaps', 'equally', 'specifically', 'particularly', 'especially', 'instead',
        'otherwise', 'besides', 'beyond', 'regarding', 'concerning', 'including', 'excluding', 'except', 'via', 'among', 'amongst', 'between', 'about', 'into',
        'over', 'contrary', 'second', 'secondly', 'third', 'thirdly', 'firstly', 'all', 'each', 'every', 'any', 'some', 'many', 'most', 'such', 'look', 'check',
        'january', 'february', 'march', 'april', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /** Court and law report abbreviations that can follow a case name ("Hall v Lorimer CA"). */
    private const TRAIL_WORDS = ['ukhl', 'uksc', 'ukpc', 'ukut', 'ukftt', 'ewca', 'ewhc', 'csih', 'csoh', 'civ', 'crim', 'ch', 'tc', 'tcc', 'stc', 'wlr', 'ac', 'qb',
        'kb', 'icr', 'irlr', 'er', 'hl', 'ca', 'sc', 'ftt', 'ut', 'bailii'];

    /** @var list<array{0:bool, 1:array{words:list<string>, ids:list<string>, exact:bool}, 2:array{words:list<string>, ids:list<string>, exact:bool}}>|null */
    private static ?array $caseIndex = null;

    /** @return list<string> the identifying words of one side of a case name */
    public static function partyWords(string $side): array
    {
        $out = [];
        foreach (self::tokens($side) as $w) {
            if (! in_array($w, self::PARTY_NOISE, true)) {
                $out[] = in_array($w, self::HMRC_NAMES, true) ? 'hmrc' : $w;
            }
        }

        return array_values(array_unique($out));
    }

    /** Lower-case words, with a possessive dropped ("Lorimer’s" → "lorimer"). @return list<string> */
    private static function tokens(string $text): array
    {
        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower(str_replace(['’', '‘'], "'", $text))) ?: [] as $w) {
            $w = trim((string) preg_replace("/'s$/u", '', trim($w, "'")), "'");
            if ($w !== '') {
                $out[] = $w;
            }
        }

        return $out;
    }

    /**
     * One side of a library case name: all its words (with its initials), and the words that name it on their own.
     * A company is named by its first word ("Kickabout", or "Red" for Red White and Green Ltd), a person by their surname
     * ("Healy" for Tim Healy), and each of several people by theirs ("Hill and McCracken"). Initials count with and
     * without the company suffix: "Professional Game Match Officials Ltd" answers to PGMOL and PGMO. Initials written
     * apart are also known run together: "W T Ramsay Ltd" is "WT Ramsay Ltd", "S & L Barnes Ltd" is "SL Barnes Ltd".
     *
     * @return array{words:list<string>, ids:list<string>, exact:bool}
     */
    private static function librarySide(string $side): array
    {
        $side = (string) preg_replace('/\([^()]*\)/u', ' ', $side);
        $words = self::partyWords($side);
        $distinct = array_values(array_diff($words, ['hmrc']));
        $tokens = self::tokens($side);
        $letters = fn (array $ws) => implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_filter($ws, fn ($w) => ! ctype_digit($w))));
        $withSuffix = array_filter($tokens, fn ($w) => in_array($w, ['ltd', 'limited', 'plc', 'llp'], true)
            || (! in_array($w, self::PARTY_NOISE, true) && ! in_array($w, self::HMRC_NAMES, true)));
        $initials = array_values(array_unique(array_filter([$letters($distinct), $letters($withSuffix)], fn ($i) => mb_strlen($i) >= 3)));
        $run = '';
        foreach ([...$tokens, ''] as $w) {
            if (mb_strlen($w) === 1 && ! ctype_digit($w)) {
                $run .= $w;

                continue;
            }
            if (mb_strlen($run) > 1) {
                $words[] = $run;
            }
            $run = '';
        }

        $ids = [];
        if (array_intersect($tokens, self::COMPANY_WORDS)) {
            foreach ($distinct as $w) {
                if (mb_strlen($w) >= 2 && ! ctype_digit($w)) {
                    $ids[] = $w;
                    break;
                }
            }
        } else {
            foreach (preg_split('/,|&|\band\b/u', $side) ?: [] as $part) {
                $named = array_values(array_diff(self::partyWords($part), ['hmrc']));
                if ($named) {
                    $ids[] = end($named);
                }
            }
        }

        return ['words' => array_values(array_unique([...$words, ...$initials])), 'ids' => array_values(array_unique([...$ids, ...$initials])), 'exact' => false];
    }

    /**
     * Every name each library case answers to: its name, each part of a joined name ("HMRC v HFFX LLP; Atkins and others
     * v HMRC"), a name in brackets ("often cited as Powell v Jackman", "with Eilbeck v Rawling"), a former name ("RFC
     * 2012 plc (formerly The Rangers Football Club plc) v ..."), and its aliases. An alias is a short name accepted as
     * written, word for word, so "Scottish Power v HMRC" doesn't let "Power v HMRC" through.
     *
     * @return list<array{0:bool, 1:array{words:list<string>, ids:list<string>, exact:bool}, 2:array{words:list<string>, ids:list<string>, exact:bool}}>
     */
    private static function caseIndex(): array
    {
        if (self::$caseIndex !== null) {
            return self::$caseIndex;
        }
        $index = [];
        foreach (self::data()['cases'] ?? [] as $c) {
            $citable = (bool) ($c['citable'] ?? false);
            $names = [];
            foreach (preg_split('/;\s*/', (string) ($c['name'] ?? '')) ?: [] as $name) {
                preg_match_all('/\(([^()]*\sv\.?\s[^()]*)\)/u', $name, $inner);
                foreach ($inner[1] as $other) {
                    $names[] = (string) preg_replace('/^(?:often cited as|also cited as|cited as|with|and)\s+/iu', '', trim($other));
                }
                if (preg_match('/\(formerly ([^()]+)\)[^()]*?\s+v\.?\s+(.*)$/u', $name, $former)) {
                    $names[] = $former[1].' v '.$former[2];
                }
                $names[] = (string) preg_replace('/\([^()]*\)/u', ' ', $name);
            }
            foreach ($names as $name) {
                if (preg_match('/^(.*?)\s+v\.?\s+(.*)$/u', trim($name), $m)) {
                    $index[] = [$citable, self::librarySide($m[1]), self::librarySide($m[2]), (string) ($c['citation'] ?? '')];
                }
            }
            foreach ($c['aliases'] ?? [] as $alias) {
                if (preg_match('/^(.*?)\s+v\.?\s+(.*)$/u', trim((string) $alias), $m)) {
                    $index[] = [$citable, ['words' => self::partyWords($m[1]), 'ids' => [], 'exact' => true],
                        ['words' => self::partyWords($m[2]), 'ids' => [], 'exact' => true], (string) ($c['citation'] ?? '')];
                }
            }
        }

        return self::$caseIndex = $index;
    }

    /**
     * Whether the words an answer uses for one party name one side of a library case. Every word must belong to that
     * side (or be its initials), and at least one must name it on its own, so "David Jones" isn't "Timothy David Hanlin"
     * and "Green" isn't "Red White and Green Ltd". A side that is only HMRC (or R, the Crown) matches HMRC (or R). An
     * alias's side must be named with exactly its words.
     *
     * @param  list<string>  $mention
     * @param  array{words:list<string>, ids:list<string>, exact:bool}  $side
     */
    private static function sideMatches(array $mention, array $side): bool
    {
        $distinct = array_values(array_diff($mention, ['hmrc', 'r']));
        if (! $distinct) {
            return (bool) array_intersect($mention, $side['words']);
        }
        if ($side['exact']) {
            $named = array_diff($side['words'], ['hmrc', 'r']);

            return ! array_diff($distinct, $named) && ! array_diff($named, $distinct);
        }
        // "Scottish Power" for "ScottishPower".
        if (count($distinct) > 1 && in_array(implode('', $distinct), $side['ids'], true)) {
            return true;
        }

        return ! array_diff($distinct, $side['words']) && array_intersect($distinct, $side['ids']);
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
        $first = self::partyWords(implode(' ', $first));
        $second = self::partyWords(implode(' ', $second));
        $distinct = fn (array $w) => array_diff($w, ['hmrc', 'r']);
        if (! $distinct($first) && ! $distinct($second)) {
            return null;
        }
        $found = null;
        foreach (self::caseIndex() as [$citable, $a, $b]) {
            if ((self::sideMatches($first, $a) && self::sideMatches($second, $b)) || (self::sideMatches($first, $b) && self::sideMatches($second, $a))) {
                if ($citable) {
                    return true;
                }
                $found = false;
            }
        }

        return $found;
    }

    /**
     * Whether a citation written next to a case name ("[2020] UKFTT 416", "[1994] 1 WLR 209", "(1975) 50 TC 222") fits
     * the library's citations for that case: the year and every number must appear in one of them (leading zeros
     * ignored, and an appeal or report citation in the library counts). Null when the case isn't in the library or the
     * citation can't be read. It catches an invented case borrowing a real party's name, such as "Taylor v HMRC [2021]
     * UKFTT 999" for Hamish Taylor v HMRC [2020] UKFTT 416.
     *
     * @param  list<string>  $first
     * @param  list<string>  $second
     */
    public static function citationMatches(array $first, array $second, string $citation): ?bool
    {
        $year = preg_match('/^[\[(](\d{4})[\])]/', trim($citation), $y) ? $y[1] : null;
        $numbers = self::citationNumbers((string) preg_replace('/^[\[(]\d{4}[\])]/', '', trim($citation)));
        if (! $year || ! $numbers) {
            return null;
        }
        $first = self::partyWords(implode(' ', $first));
        $second = self::partyWords(implode(' ', $second));
        $seen = false;
        foreach (self::caseIndex() as [, $a, $b, $libraryCitation]) {
            if (! ((self::sideMatches($first, $a) && self::sideMatches($second, $b)) || (self::sideMatches($first, $b) && self::sideMatches($second, $a)))) {
                continue;
            }
            if ($libraryCitation === '') {
                continue;
            }
            $seen = true;
            foreach (preg_split('/;\s*|\(on appeal from |\(reversing |restoring |\(first instance |first instance /u', $libraryCitation) ?: [] as $one) {
                preg_match_all('/[\[(](\d{4})[\])]/', $one, $years);
                if (in_array($year, $years[1], true) && ! array_diff($numbers, self::citationNumbers((string) preg_replace('/[\[(]\d{4}[\])]/', '', $one)))) {
                    return true;
                }
            }
        }

        if (! $seen) {
            return null;
        }
        // A citation that is another checked case's ("Hall v Lorimer and Ready Mixed Concrete [1968] 2 QB 497") may
        // belong to that one: only a citation no case in the library has is flagged.
        foreach (self::data()['cases'] ?? [] as $c) {
            foreach (preg_split('/;\s*|\(on appeal from |\(reversing |restoring |\(first instance |first instance /u', (string) ($c['citation'] ?? '')) ?: [] as $one) {
                preg_match_all('/[\[(](\d{4})[\])]/', $one, $years);
                if (in_array($year, $years[1], true) && ! array_diff($numbers, self::citationNumbers((string) preg_replace('/[\[(]\d{4}[\])]/', '', $one)))) {
                    return null;
                }
            }
        }

        return false;
    }

    /** @return list<string> the numbers in a citation, without leading zeros */
    private static function citationNumbers(string $citation): array
    {
        preg_match_all('/(?<![\p{L}\d])\d+(?!\d)/u', $citation, $m);

        return array_values(array_unique(array_map(fn ($n) => ltrim($n, '0') === '' ? '0' : ltrim($n, '0'), $m[0])));
    }

    /**
     * Case names written in a piece of text ("Mallalieu v Drummond", "HMRC v Healy", "R v Dimsey"), including the
     * formal styles: "Hall (HM Inspector of Taxes) v Lorimer", "R (on the application of Smith) v HMRC" (Smith v
     * HMRC), "Smith Ltd. v HMRC", "Jones vs HMRC" and **Smith** v **Jones**.
     *
     * @return list<array{text:string, first:list<string>, second:list<string>, citation?:string}>
     */
    public static function casesNamedIn(string $text): array
    {
        $text = self::tidyForCases($text);
        // Capitalised words (or &) joined by single spaces, so a match never runs across punctuation into the next sentence.
        // A name may have "of" in it (Duke of Westminster); the first party may join names with "and" (Red White and Green Ltd)
        // and hold a number (Fakeco 2 Ltd, RFC 2012 plc); the second may have "for" (Advocate General for Scotland).
        $word = '(?:\p{Lu}[\p{L}\'\-&]*|&)';
        $firstSide = $word.'(?: (?:of (?:the )?|and )?(?:'.$word.'|\p{N}+)){0,5}';
        $secondSide = $word.'(?: (?:of (?:the )?|for )?'.$word.'){0,5}';
        preg_match_all('/(?<![\p{L}\p{N}\'\-])('.$firstSide.') vs?\.? ('.$secondSide.')/u', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m as $hit) {
            // A citation straight after the name: "[2020] UKFTT 416", "[1994] 1 WLR 209", "(1975) 50 TC 222".
            $after = substr($text, $hit[0][1] + strlen($hit[0][0]), 80);
            // The name may run on first: "Rogers and Shaw", "Tower MCashback LLP 1", "(with Eilbeck v Rawling)".
            $runOn = '(?: (?:and|&) (?:\p{Lu}[\p{L}\'\-]*\.?|\p{N}+)(?: (?:\p{Lu}[\p{L}\'\-]*\.?|\p{N}+))*| \p{N}+)?(?: \([^()]{0,60}\))?';
            $citation = preg_match('/^'.$runOn.',? ?([\[(]\d{4}[\])](?: (?:\d+|\p{Lu}[\p{L}]*\.?)){1,4})/u', $after, $cm) && preg_match('/\d+$|\d+ \p{Lu}/u', substr($cm[1], 6))
                ? (string) preg_replace('/(?: \p{Lu}[\p{L}]*\.?)+$/u', '', $cm[1]) : null;
            $hit = [$hit[0][0], $hit[1][0], $hit[2][0]];
            $a = self::trimParty($hit[1], true);
            $b = self::trimParty($hit[2], false);
            $first = self::partyWords($a);
            $second = self::partyWords($b);
            // "Ready Mixed Concrete and Hall v Lorimer" names two cases; "Hill and McCracken v HMRC" names one.
            if (preg_match('/^.+ and (\p{Lu}.*)$/u', $a, $and) && self::matchCase($first, $second) === null
                && self::matchCase(self::partyWords($and[1]), $second) !== null) {
                $a = $and[1];
                $first = self::partyWords($a);
            }
            // "Cash Basis v Accruals" and "PCP vs HP" compare options; they aren't cases. HMRC is never an option, so
            // "Private Hire Ltd v HMRC" is a case, and a side ending in Ltd or plc is a party ("Electric Van Ltd v Smith").
            if (! $first || ! $second || (! self::isCrown($a) && ! self::isCrown($b) && (self::isOption($a, $first) || self::isOption($b, $second)))) {
                continue;
            }
            $out[] = ['text' => $a.' v '.$b, 'first' => $first, 'second' => $second] + ($citation ? ['citation' => $citation] : []);
        }

        return $out;
    }

    /**
     * Whether one side, as written, is an option rather than a party: only comparison words, initials and numbers
     * ("PCP", "Plan A", "Option 1"), and not a company ("Private Hire Ltd").
     *
     * @param  list<string>  $words
     */
    private static function isOption(string $side, array $words): bool
    {
        $tokens = self::tokens($side);
        if (in_array(end($tokens), self::COMPANY_SUFFIXES, true)) {
            return false;
        }

        return ! array_diff(array_filter($words, fn ($w) => mb_strlen($w) > 1 && ! ctype_digit($w)), self::TOPIC_WORDS);
    }

    /** Whether one side, as written, is HMRC, the Revenue's commissioners, the Crown or R. */
    private static function isCrown(string $side): bool
    {
        $tokens = self::tokens($side);

        return array_intersect($tokens, ['hmrc', 'irc', 'customs', 'commissioners', 'inland', 'crown']) || $tokens === ['r'];
    }

    /** Text with case names in a plain "Smith v Jones" form, for casesNamedIn. */
    private static function tidyForCases(string $text): string
    {
        $text = str_replace(['’', '‘'], "'", $text);
        $text = (string) preg_replace('/[ \t\x{00A0}\x{202F}]+/u', ' ', $text);
        // Markdown: **Smith** v *Jones*, `Smith v Jones`.
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])[*_`]+|[*_`]+(?![\p{L}\p{N}])/u', '', $text);
        // "post-Ramsay", "Pre-PGMOL": the case is Ramsay's, PGMOL's.
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])(?:[Pp]ost|[Pp]re|[Nn]on|[Aa]nti)-(?=\p{Lu})/u', '', $text);
        // A company suffix in lower case, the usual style ("Johnson Matthey plc"), reads as a name's last word.
        $text = (string) preg_replace_callback('/(?<![\p{L}\p{N}])(plc|ltd|llp)(?![\p{L}\p{N}])/u', fn ($s) => $s[1] === 'llp' ? 'LLP' : ucfirst($s[1]), $text);
        // A court named next to a case isn't part of it ("Mallalieu v Drummond House of Lords").
        $text = (string) preg_replace('/\b(?:House of Lords|Court of Appeal|Supreme Court|Upper Tribunal|First-tier Tribunal|High Court|Court of Session|Privy Council)\b/u', ';', $text);
        // "R (on the application of Smith) v HMRC" and "R (Smith) v HMRC" are Smith's case against HMRC.
        $text = (string) preg_replace('/(?<![\p{L}\p{N}])(?:R|Regina|Rex|The King|The Queen)\.? ?\((?:on the application of |oao |ota )?([^()]{1,80})\) (?=vs?\.? )/u', '$1 ', $text);
        // "Stephen Hoey and others v HMRC", "MedPro Healthcare Ltd & Ors v HMRC".
        $text = (string) preg_replace('/,? (?:and|&) (?:others|another|ors|anor)\b\.?/iu', '', $text);
        // Short brackets that aren't a case name: (Inspector of Taxes), (HMIT), (South East), (No 2). Inner ones first.
        for ($i = 0; $i < 3; $i++) {
            // A year in round brackets starts a citation ("(1975) 50 TC 222"), so it stays.
            $text = (string) preg_replace('/ ?\((?![^()]* vs?\.? )(?!\d{4}\))[^()]{1,60}\)/u', '', $text);
        }

        // "Smith Ltd. v HMRC", "Strong & Co. of Romsey Ltd".
        return (string) preg_replace('/\b(Ltd|Co|Plc|PLC|plc|Inc|Bros|Corp|LLP)\.(?= (?:vs?\.? |of ))/u', '$1', $text);
    }

    /**
     * One party as written, without the words around it: a sentence opener or a date before the first ("Following
     * Hall", "In September 2024 HMRC", "UKSC 29 HMRC"), and a judge, a law report or a possessive after the second
     * ("Drummond Lord Brightman", "Lorimer Mummery J", "Healy UKUT", "Lorimer's"). Nothing is cut that would leave
     * no name ("Given Ltd", "Fakeco 2 Ltd").
     */
    private static function trimParty(string $side, bool $isFirst): string
    {
        $tokens = explode(' ', trim($side));
        $bare = fn (string $t) => self::tokens($t)[0] ?? mb_strtolower($t);
        $joiner = fn (string $t) => in_array(mb_strtolower($t), ['and', 'of', 'the', '&'], true);
        $named = fn (array $ts) => self::partyWords(implode(' ', $ts)) !== [];
        if ($isFirst) {
            // A number ends whatever came before it, unless only "Ltd" or the like follows (Fakeco 2019 Ltd).
            for ($i = count($tokens) - 1; $i >= 0; $i--) {
                if (preg_match('/^\p{N}+$/u', $tokens[$i])) {
                    if ($named(array_slice($tokens, $i + 1))) {
                        $tokens = array_slice($tokens, $i + 1);
                    }
                    break;
                }
            }
            while (count($tokens) > 1 && ($joiner($tokens[0]) || in_array($bare($tokens[0]), self::LEAD_WORDS, true)) && $named(array_slice($tokens, 1))) {
                array_shift($tokens);
            }

            return implode(' ', $tokens);
        }
        foreach ($tokens as $i => $t) {
            if ($i > 0 && in_array($bare($t), ['lord', 'lady'], true)) {
                $tokens = array_slice($tokens, 0, $i);
                break;
            }
        }
        if (count($tokens) > 2 && in_array($bare(end($tokens)), ['j', 'lj', 'jj', 'ljj'], true)) {
            array_splice($tokens, -2);
        }
        while (count($tokens) > 1 && ($joiner(end($tokens)) || in_array($bare(end($tokens)), self::TRAIL_WORDS, true))) {
            array_pop($tokens);
        }

        // "Hall v Lorimer's approach" names Hall v Lorimer.
        return (string) preg_replace("/'s?$/u", '', implode(' ', $tokens));
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
