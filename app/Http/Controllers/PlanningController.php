<?php

namespace App\Http\Controllers;

use App\Domain\Tax\Planning;
use App\Domain\Tax\StructureCalculator;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Structures and planning: a side-by-side estimate of sole trader, company and partnership for a year and a profit
 * (the owner's own by default), then legal ideas that fit, grants, loans and support, and things to avoid, each with
 * its source. Nothing here is sent anywhere or saved: the inputs live in the address bar.
 */
class PlanningController extends Controller
{
    /** Query names for the amounts in pounds, and the calculator options they set. */
    private const AMOUNTS = [
        'salary_amount' => 'salary_pence', 'retain' => 'retain_pence', 'accountant' => 'accountant_pence', 'software' => 'software_pence',
        'statement' => 'confirmation_statement_pence', 'partner_other' => 'partner_other_income_pence', 'pension' => 'pension_pence',
    ];

    /** No amount above £10m is taken: the research covers a small business. */
    private const MAX_PENCE = 1_000_000_000;

    public function index(Request $request): Response
    {
        $years = StructureCalculator::years();
        $year = in_array($request->query('year'), $years, true) ? (string) $request->query('year') : StructureCalculator::defaultYear();
        $inputs = self::inputs($request);
        // The owner's own profit reads the year's records: work it out once a request, for both props that need it.
        $basis = null;
        $owner = function () use (&$basis, $year): array {
            return $basis ??= StructureCalculator::ownerProfit($year);
        };

        return Inertia::render('planning', [
            'years' => $years,
            'year' => $year,
            'researched_on' => Planning::researchedOn(),
            'inputs' => $inputs,
            'profit_basis' => $owner,
            'comparison' => function () use ($year, $inputs, $owner) {
                $profit = $inputs['profit'] ?? $owner()['profit_pence'];

                return StructureCalculator::compare($year, $profit, self::options($inputs));
            },
            'ideas' => fn () => array_map(fn ($i) => self::idea($i, $year), self::fitFirst(Planning::ideas())),
            'funding' => fn () => array_map(fn ($i) => self::funding($i, $year), self::sectorFirst(Planning::funding())),
            'avoid' => fn () => array_map(fn ($i) => self::avoid($i, $year), Planning::avoid()),
        ]);
    }

    /**
     * What the owner typed, checked: amounts in pounds become pence (anything unreadable or out of range is ignored, so
     * the default is used), the salary choice must be one the calculator knows, a share is 0 to 100.
     *
     * @return array<string, mixed>
     */
    private static function inputs(Request $request): array
    {
        $pence = function (string $name) use ($request): ?int {
            $value = $request->query($name);
            if (! is_string($value) || trim($value) === '') {
                return null;
            }
            try {
                $p = Money::parse($value);
            } catch (\Throwable) {
                return null;
            }

            return $p !== null && $p >= 0 && $p <= self::MAX_PENCE ? $p : null;
        };
        $out = ['profit' => $pence('profit')];
        foreach (array_keys(self::AMOUNTS) as $name) {
            $out[$name] = $pence($name);
        }
        $salary = $request->query('salary');
        $out['salary'] = in_array($salary, StructureCalculator::SALARY_OPTIONS, true) ? $salary : 'best';
        $share = $request->query('share');
        $out['share'] = is_numeric($share) && $share >= 0 && $share <= 100 ? (string) $share : null;
        $out['class2'] = $request->boolean('class2');
        $out['partner_se'] = $request->boolean('partner_se');

        return $out;
    }

    private static function options(array $inputs): array
    {
        $options = ['salary' => $inputs['salary'], 'voluntary_class2' => $inputs['class2'], 'partner_other_self_employed' => $inputs['partner_se']];
        foreach (self::AMOUNTS as $name => $option) {
            if ($inputs[$name] !== null) {
                $options[$option] = $inputs[$name];
            }
        }
        if ($inputs['share'] !== null) {
            $options['partner_share_pct'] = $inputs['share'];
        }

        return $options;
    }

    /**
     * The research's own verdict on family and trust measures answers "can my family or a trust cut my tax?", so it
     * goes with the ideas most likely to matter now, though its name mentions trusts.
     */
    private const FIT = ['verdict-family-and-trusts' => 'likely'];

    /**
     * Ideas grouped by how likely they are to fit a sole trader now: first those whose relevance doesn't narrow who they
     * are for; then those that only matter with a company or LLP; then family savings, trusts and inheritance, which
     * the research says do nothing for the tax on day-rate earnings (trusts-cannot-shelter-trading-income); then the
     * rest ("Only if…", "Marginal", "Low", "does nothing"). Each group keeps the research's order.
     *
     * @return list<array>
     */
    private static function fitFirst(array $items): array
    {
        $fit = function (array $i): string {
            if (isset(self::FIT[$i['id'] ?? ''])) {
                return self::FIT[$i['id']];
            }
            $name = (string) ($i['name'] ?? '');
            $relevance = (string) ($i['relevance'] ?? '');
            if (preg_match('/^\s*(only|low|marginal|mostly irrelevant|irrelevant|rarely|little effect|small|not relevant|no effect|watch item)\b/i', $relevance)) {
                return 'some';
            }
            if (preg_match('/\b(trusts?|inheritance tax|IHT|business property relief)\b/i', $name)
                || preg_match('/\b(will-planning|wealth-stage|cannot shelter|does nothing for the freelancer|does not reduce the freelancer)/i', $relevance)) {
                return 'later';
            }
            if (preg_match('/\b(incorporat\w*|company|companies|director\w*|LLP|PSC|CT600)\b/i', $name.' '.$relevance)) {
                return 'company';
            }

            return preg_match('/\b(does nothing|this only matters|matters only|no effect|little effect)\b/i', $relevance) ? 'some' : 'likely';
        };
        $grouped = ['likely' => [], 'company' => [], 'later' => [], 'some' => []];
        foreach ($items as $i) {
            $grouped[$fit($i)][] = $i + ['fit' => $fit($i)];
        }

        return [...$grouped['likely'], ...$grouped['company'], ...$grouped['later'], ...$grouped['some']];
    }

    /**
     * Grants and support aimed at events, film, TV, theatre, music and the arts first, then general schemes. A bare
     * "stage" isn't enough ("business stage" is in a general finder): it has to be backstage or a stage hand.
     *
     * @return list<array>
     */
    private static function sectorFirst(array $items): array
    {
        $sector = fn (array $i) => (bool) preg_match('/\b(events?|film|tv|television|theatres?|theatrical|backstage|stage ?hands?|crew|music|musicians|broadcast|screen|creative|arts?|entertainment)\b/i',
            ($i['name'] ?? '').' '.($i['who'] ?? ''));
        $first = array_values(array_filter($items, $sector));
        $rest = array_values(array_filter($items, fn ($i) => ! $sector($i)));

        return [...array_map(fn ($i) => $i + ['sector' => true], $first), ...array_map(fn ($i) => $i + ['sector' => false], $rest)];
    }

    /** The parts of an item every list shows: its name, link, legality, status and figures for the year. */
    private static function base(array $i, string $year): array
    {
        $disputed = $i['figures_disputed'] ?? false;

        return [
            'id' => $i['id'], 'section' => $i['section'], 'name' => $i['name'] ?? $i['id'], 'url' => $i['url'] ?? null,
            'legality' => $i['legality'] ?? null, 'status' => $i['status'] ?? null,
            'figures' => Planning::figureLines($i, $year),
            'figures_disputed' => is_array($disputed) ? $disputed !== [] : (bool) $disputed,
            'disputes' => is_array($disputed) ? array_values(array_filter($disputed, 'is_string')) : [],
            'relevance' => $i['relevance'] ?? null,
        ];
    }

    private static function idea(array $i, string $year): array
    {
        return self::base($i, $year) + ['summary' => Planning::summaryOf($i), 'conditions' => $i['conditions'] ?? null, 'risks' => $i['risks'] ?? null, 'fit' => $i['fit']];
    }

    private static function funding(array $i, string $year): array
    {
        return self::base($i, $year) + ['type' => $i['type'] ?? null, 'who' => $i['who'] ?? null, 'amount' => $i['amount'] ?? null,
            'how_taxed' => $i['how_taxed'] ?? null, 'conditions' => $i['conditions'] ?? null, 'risks' => $i['risks'] ?? null, 'sector' => $i['sector']];
    }

    private static function avoid(array $i, string $year): array
    {
        return self::base($i, $year) + ['why' => $i['why'] ?? Planning::summaryOf($i), 'never_recommend' => Planning::mustAvoid($i)];
    }
}
