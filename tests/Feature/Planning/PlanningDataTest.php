<?php

namespace Tests\Feature\Planning;

use App\Domain\Tax\Planning;
use App\Domain\Tax\StructureCalculator;
use App\Domain\Tax\TaxParameters;
use Tests\TestCase;

/**
 * The verified planning research (resources/tax/planning.json): its shape, its legality and funding labels, its links,
 * unique ids, rates for the four years, and the figures the calculator takes from its text agreeing with it.
 */
class PlanningDataTest extends TestCase
{
    /** Fields every item of a section must have (beyond id, name, url, status, figures, figures_disputed, relevance). */
    private const REQUIRED = [
        'structures' => ['summary', 'pros', 'cons', 'costs', 'when_it_fits', 'risks', 'legality'],
        'techniques' => ['summary', 'legality', 'conditions', 'risks', 'data_needed', 'how_to_estimate_saving'],
        'family_and_trusts' => ['summary', 'legality', 'conditions', 'risks'],
        'funding' => ['type', 'who', 'amount', 'how_taxed', 'conditions', 'risks'],
        'traps' => ['why', 'legality'],
        'cases' => ['citation', 'principle'],
    ];

    private const COMMON = ['id', 'name', 'url', 'status', 'figures', 'figures_disputed', 'relevance'];

    private const RATE_KEYS = ['personal_allowance', 'pa_taper_start', 'basic_rate_band', 'higher_rate_threshold', 'additional_rate_threshold', 'basic_rate',
        'higher_rate', 'additional_rate', 'dividend_allowance', 'dividend_ordinary_rate', 'dividend_upper_rate', 'dividend_additional_rate', 'ct_small_profits_rate',
        'ct_main_rate', 'ct_lower_limit', 'ct_upper_limit', 'ct_marginal_fraction', 'employee_nic_primary_threshold', 'employee_nic_main_rate',
        'employee_nic_upper_rate', 'upper_earnings_limit', 'employer_nic_secondary_threshold', 'employer_nic_rate', 'employment_allowance',
        'class4_lower_profits_limit', 'class4_main_rate', 'class4_upper_rate', 'class2_weekly_voluntary', 's455_rate', 'badr_rate', 'trading_allowance',
        'pension_annual_allowance'];

    public function test_every_section_has_its_required_fields_valid_labels_and_links(): void
    {
        $data = Planning::data();
        foreach (['researched_on', 'rates', ...Planning::SECTIONS, 'notes', 'calculator_notes'] as $key) {
            $this->assertArrayHasKey($key, $data, "planning.json has {$key}");
        }
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['researched_on']);

        $ids = [];
        foreach (self::REQUIRED as $section => $fields) {
            $this->assertNotEmpty($data[$section], "{$section} has items");
            foreach ($data[$section] as $n => $item) {
                $where = "{$section}[{$n}] ".($item['id'] ?? '?');
                foreach ([...self::COMMON, ...$fields] as $field) {
                    $this->assertArrayHasKey($field, $item, "{$where} has {$field}");
                }
                $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*$/', $item['id'], "{$where}: id");
                $ids[] = $item['id'];
                $this->assertIsString($item['name']);
                $this->assertNotSame('', trim($item['name']), "{$where}: name");
                $this->assertMatchesRegularExpression('#^https?://[^\s]+$#', (string) $item['url'], "{$where}: url is a web link");
                $this->assertNotSame('', trim((string) $item['status']), "{$where}: status");
                $this->assertIsArray($item['figures'], "{$where}: figures");
                $disputed = $item['figures_disputed'];
                $this->assertTrue(is_bool($disputed) || (is_array($disputed) && array_is_list($disputed)), "{$where}: figures_disputed is true/false or a list");
                if (in_array('legality', $fields, true)) {
                    $this->assertContains($item['legality'], Planning::LEGALITIES, "{$where}: legality");
                }
                if ($section === 'funding') {
                    $this->assertContains($item['type'], Planning::FUNDING_TYPES, "{$where}: funding type");
                    foreach (['who', 'amount', 'how_taxed'] as $field) {
                        $this->assertIsString($item[$field], "{$where}: {$field}");
                    }
                }
                if (in_array($section, ['structures'], true)) {
                    $this->assertIsArray($item['pros']);
                    $this->assertIsArray($item['cons']);
                }
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'ids are unique across every section: '.implode(', ', array_keys(array_filter(array_count_values($ids), fn ($c) => $c > 1))));

        foreach ($data['calculator_notes'] as $n) {
            $this->assertNotSame('', trim($n['item'] ?? ''));
            $this->assertNotSame('', trim($n['note'] ?? ''));
        }
        $this->assertContainsOnly('string', $data['notes']);
    }

    public function test_the_rates_cover_the_four_years_with_every_figure(): void
    {
        $rates = Planning::data()['rates'];
        $this->assertSame(['2024-25', '2025-26', '2026-27', '2027-28_announced'], array_keys($rates));
        foreach ($rates as $year => $r) {
            foreach (self::RATE_KEYS as $key) {
                $this->assertArrayHasKey($key, $r, "{$year} has {$key}");
                $this->assertTrue(is_int($r[$key]) || is_float($r[$key]), "{$year} {$key} is a number");
                $this->assertGreaterThanOrEqual(0, $r[$key]);
            }
            foreach (['basic_rate', 'higher_rate', 'additional_rate', 'dividend_ordinary_rate', 'dividend_upper_rate', 'dividend_additional_rate', 'ct_small_profits_rate',
                'ct_main_rate', 'employer_nic_rate', 'employee_nic_main_rate', 'class4_main_rate'] as $rate) {
                $this->assertLessThan(1, $r[$rate], "{$year} {$rate} is a fraction");
            }
            $this->assertSame($r['personal_allowance'] + $r['basic_rate_band'], $r['higher_rate_threshold'], "{$year}: PA + basic band = higher rate threshold");
        }
        // 2027-28 is held as announced; the planning helpers find it either way.
        $this->assertSame('2027-28_announced', Planning::ratesKey('2027-28'));
        $this->assertTrue(Planning::announced('2027-28'));
        $this->assertFalse(Planning::announced('2026-27'));
        $this->assertNull(Planning::rates('2023-24'));
        $this->assertSame(Planning::YEARS, StructureCalculator::years());
    }

    public function test_the_apps_own_tax_rates_agree_with_the_research(): void
    {
        foreach (['2024-25', '2025-26', '2026-27'] as $year) {
            $p = TaxParameters::for($year);
            $r = Planning::rates($year);
            $this->assertSame($r['personal_allowance'] * 100, $p['personal_allowance'], "{$year} personal allowance");
            $this->assertSame($r['pa_taper_start'] * 100, $p['personal_allowance_taper_from']);
            $this->assertSame($r['basic_rate_band'] * 100, $p['basic_rate_limit']);
            $this->assertSame($r['additional_rate_threshold'] * 100, $p['additional_rate_threshold']);
            $this->assertEquals($r['basic_rate'] * 100, (float) $p['rates']['basic']);
            $this->assertEquals($r['higher_rate'] * 100, (float) $p['rates']['higher']);
            $this->assertEquals($r['additional_rate'] * 100, (float) $p['rates']['additional']);
            $this->assertSame($r['class4_lower_profits_limit'] * 100, $p['class4']['lower']);
            $this->assertSame($r['upper_earnings_limit'] * 100, $p['class4']['upper']);
            $this->assertEquals($r['class4_main_rate'] * 100, (float) $p['class4']['main_rate']);
            $this->assertEquals($r['class4_upper_rate'] * 100, (float) $p['class4']['upper_rate']);
            $this->assertSame($r['pension_annual_allowance'] * 100, $p['pension_annual_allowance']);
            $this->assertSame((int) round($r['class2_weekly_voluntary'] * 100), $p['class2_voluntary']['weekly']);
        }
    }

    public function test_lists_for_the_owner_keep_legal_ideas_apart_from_things_to_avoid(): void
    {
        $ideas = Planning::ideas();
        $this->assertNotEmpty($ideas);
        foreach ($ideas as $i) {
            $this->assertContains($i['legality'], Planning::LEGAL, $i['id'].' is legal planning');
            $this->assertContains($i['section'], ['techniques', 'family_and_trusts']);
            foreach (['url', 'legality', 'status', 'figures'] as $kept) {
                $this->assertArrayHasKey($kept, $i);
            }
        }
        $ideaIds = array_column($ideas, 'id');
        // Every technique or family measure HMRC targets, or that is illegal, is only ever in the list to avoid.
        $avoidIds = array_column(Planning::avoid(), 'id');
        foreach (['techniques', 'family_and_trusts'] as $section) {
            foreach (Planning::data()[$section] as $i) {
                if (in_array($i['legality'], Planning::AVOID, true)) {
                    $this->assertNotContains($i['id'], $ideaIds);
                    $this->assertContains($i['id'], $avoidIds);
                }
            }
        }
        $this->assertContains('settlements-legislation-overview', $avoidIds);
        $this->assertContains('s629-parental-settlements', $avoidIds);
        foreach (Planning::data()['traps'] as $t) {
            $this->assertContains($t['id'], $avoidIds, 'every trap is in the list to avoid');
        }
        foreach (Planning::avoid() as $a) {
            $this->assertNotSame('', trim((string) $a['why']), $a['id'].' says why');
            $this->assertNotContains($a['id'], $ideaIds);
        }
        $this->assertCount(count(Planning::data()['structures']), Planning::structures());
        foreach (Planning::funding() as $f) {
            foreach (['who', 'amount', 'how_taxed', 'url', 'status', 'figures'] as $kept) {
                $this->assertArrayHasKey($kept, $f);
            }
        }
    }

    public function test_items_can_be_found_by_id_and_by_words(): void
    {
        $item = Planning::item('employer-pension-contributions');
        $this->assertSame('techniques', $item['section']);
        $this->assertSame('relief_intended_by_parliament', $item['legality']);
        $this->assertNull(Planning::item('no-such-item'));

        $hits = array_column(Planning::search('employer pension contributions', null, null, 3), 'id');
        $this->assertContains('employer-pension-contributions', $hits);

        $traps = Planning::search('loan', 'traps', null, 20);
        $this->assertNotEmpty($traps);
        foreach ($traps as $t) {
            $this->assertSame('traps', $t['section']);
        }
        foreach (Planning::search('trust', null, 'avoid_targeted_by_hmrc', 20) as $t) {
            $this->assertSame('avoid_targeted_by_hmrc', $t['legality']);
        }
        // A trap's "why" counts as its summary: a remuneration trust is found by what it does.
        $this->assertContains('remuneration-revenue-trusts-spotlights', array_column(Planning::search('fiduciary receipts personal management company', null, null, 5), 'id'));

        // Figures for a year: that year's entry, "now", and figures not tied to a year; never another year's.
        $this->assertSame(['2026-27'], array_column(Planning::figureLines(Planning::item('limited-company-psc'), '2026-27'), 'label'));
        $stopGap = Planning::figureLines(Planning::item('film-tv-charity-stop-gap'), '2026-27');
        $this->assertContains('Max grant (£) generally', array_column($stopGap, 'label'));
        $this->assertSame([], array_filter(array_column($stopGap, 'label'), fn ($l) => preg_match('/^20\d\d-\d\d/', $l) && $l !== '2026-27'));
        // A figure the research couldn't find says so.
        $this->assertContains('Not published (the research found no official figure).', array_column(Planning::figureLines(Planning::item('theatrical-guild'), '2026-27'), 'text'));
    }

    /** Figures the calculator states in words are the research's own. */
    public function test_the_calculators_fixed_figures_come_from_the_research(): void
    {
        $costs = Planning::item('company-running-costs');
        $text = json_encode([$costs['summary'], $costs['figures']], JSON_UNESCAPED_UNICODE);
        // Confirmation statement online: £34 from 1 May 2024 to 31 January 2026, £50 from 1 February 2026.
        $this->assertStringContainsString('confirmation statement £34', $text);
        $this->assertStringContainsString('confirmation statement is £50', $text);
        $this->assertSame(['2024-25' => 3_400, '2025-26' => 3_400, '2026-27' => 5_000, '2027-28' => 5_000], StructureCalculator::CONFIRMATION_STATEMENT_PENCE);
        // No official accountancy or software figure: the calculator's are its own placeholders.
        $this->assertStringContainsString('Accountancy and software costs: null', $text);
        // The admin warning's penalties and deadlines.
        foreach (['£150', '£1,500', '£200', '£400', '9 months', '12 months after'] as $figure) {
            $this->assertStringContainsString($figure, $text, "company-running-costs says {$figure}");
        }
        // The small profits threshold announced for 2027-28.
        $this->assertStringContainsString('SPT £7,355', json_encode(Planning::item('sole-trader')['figures'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(735_500, StructureCalculator::SMALL_PROFITS_THRESHOLD_2027_28_PENCE);
        // What is announced, not law, for 2027-28 (the research's own status note).
        $status = implode(' ', array_filter(Planning::notes(), fn ($n) => str_contains($n, '2027-28_announced rates status')));
        foreach (['ANNOUNCED ONLY', '2027-28 main income tax rates 20/40/45', 'Class 1 thresholds', 'Class 2 SPT £7,355 / voluntary £3.80/wk', 'LAW already',
            'dividend rates 10.75/35.75/39.35%', 'employer NIC rate 15%', 'pension annual allowance £60,000'] as $phrase) {
            $this->assertStringContainsString($phrase, $status);
        }
        // Employer NIC on a solo director's salary has no Employment Allowance.
        $this->assertStringContainsString('NICA 2014 s2(4A)', Planning::item('employment-allowance-single-director')['summary']);
        // Partners can't use the trading allowance.
        $this->assertStringContainsString('s783AA(1)(a)', json_encode(Planning::item('general-partnership')['cons']));
    }
}
