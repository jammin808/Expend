<?php

namespace Tests\Feature\Planning;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\Planning;
use App\Domain\Tax\TaxSavings;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The planning page: signed in only, the owner's own profit by default (projected while the year runs), and the lists. */
class PlanningPageTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // The pages live in resources/js/pages: check the page file really exists there.
        config(['inertia.testing.page_paths' => [resource_path('js/pages')]]);
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Confirmed business income from Acme Events. */
    private function income(string $date, int $pence): void
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date,
            'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(), 'description' => 'ACME EVENTS '.$this->n, 'merchant_key' => 'ACME EVENTS',
            'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, 'business_income', Category::query()->where('code', 'turnover')->value('id')), 'you');
    }

    public function test_only_the_signed_in_owner_sees_it(): void
    {
        $this->get('/planning')->assertRedirect('/login');
    }

    public function test_it_starts_from_this_years_projected_profit_and_shows_the_lists(): void
    {
        $this->income('2026-06-01', 3_000_000);
        $this->actingAs(User::factory()->create());
        $year = UkTaxYear::fromLabel('2026-27');
        $projected = TaxSavings::projection($year, CarbonImmutable::today(), 3_000_000);

        $this->get('/planning')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('planning')
            ->where('year', '2026-27')
            ->where('years', ['2024-25', '2025-26', '2026-27', '2027-28'])
            ->where('researched_on', Planning::researchedOn())
            ->where('profit_basis.basis', 'projected')
            ->where('profit_basis.records_pence', 3_000_000)
            ->where('profit_basis.profit_pence', $projected)
            ->where('profit_basis.label', fn ($l) => str_contains($l, 'projection') && str_contains($l, 'not a forecast'))
            ->where('comparison.tax_year', '2026-27')
            ->where('comparison.profit_pence', $projected)
            ->where('comparison.estimate', true)
            ->where('comparison.law.announced', false)
            ->has('comparison.sole_trader.take_home_pence')
            ->has('comparison.company.salaries_tried', 3)
            ->has('comparison.partnership.you')
            ->has('comparison.break_even.note')
            ->where('comparison.warnings', fn ($w) => collect($w)->pluck('code')->contains('ir35') && collect($w)->pluck('code')->contains('not_advice'))
            ->where('ideas', function ($ideas) {
                $ideas = collect($ideas);

                return $ideas->isNotEmpty() && $ideas->every(fn ($i) => in_array($i['legality'], Planning::LEGAL, true) && str_starts_with((string) $i['url'], 'http')
                    && isset($i['conditions'], $i['risks'], $i['status']) && in_array($i['fit'], ['likely', 'company', 'later', 'some'], true))
                    && ! $ideas->contains('id', 'settlements-legislation-overview') && $ideas->contains('id', 'employer-pension-contributions')
                    // A sole trader's own ideas come before company-only ones, and narrow ones last.
                    && $ideas->firstWhere('id', 'pension-annual-allowance')['fit'] === 'likely' && $ideas->firstWhere('id', 'company-running-costs')['fit'] === 'company'
                    && $ideas->firstWhere('id', 'founders-relief')['fit'] === 'some'
                    && $ideas->pluck('fit')->unique()->values()->all() === ['likely', 'company', 'later', 'some'];
            })
            ->where('funding', function ($funding) {
                $funding = collect($funding);
                // Grants for events, film, TV, theatre and music first, then general schemes; each says how it is taxed.
                $firstGeneral = $funding->search(fn ($f) => ! $f['sector']);

                return $funding->count() === count(Planning::funding()) && $funding->take($firstGeneral)->every(fn ($f) => $f['sector'])
                    && $funding->skip($firstGeneral)->every(fn ($f) => ! $f['sector'])
                    && $funding->firstWhere('id', 'backup-tech')['sector'] && ! $funding->firstWhere('id', 'start-up-loans')['sector']
                    && $funding->every(fn ($f) => $f['how_taxed'] !== null && $f['who'] !== null && str_starts_with((string) $f['url'], 'http'));
            })
            ->where('avoid', function ($avoid) {
                $avoid = collect($avoid);
                $trust = $avoid->firstWhere('id', 'remuneration-revenue-trusts-spotlights');

                return $trust && $trust['never_recommend'] && $trust['why'] !== '' && $avoid->firstWhere('id', 's629-parental-settlements')['never_recommend']
                    && $avoid->firstWhere('id', 'ir35-for-incorporated-events-tech')['never_recommend'] === false
                    && $avoid->every(fn ($a) => str_starts_with((string) $a['url'], 'http') && $a['why'] !== '');
            }));
    }

    public function test_the_owner_can_change_the_year_profit_and_options(): void
    {
        $this->actingAs(User::factory()->create());
        // The 2025-26 £60,000 worked example (running costs £1,000): sole trader 46,111.40, company 46,092.05.
        $this->get('/planning?year=2025-26&profit=60000&accountant=1000&software=0&statement=0&share=30&pension=5000&class2=1')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('year', '2025-26')
                ->where('inputs.profit', 6_000_000)
                ->where('inputs.accountant', 100_000)
                ->where('inputs.share', '30')
                ->where('inputs.class2', true)
                ->where('comparison.sole_trader.take_home_pence', 4_611_140)
                ->where('comparison.company.take_home_pence', 4_609_205)
                ->where('comparison.company.running_costs.total_pence', 100_000)
                ->where('comparison.partnership.partner_share_pct', '30')
                ->where('comparison.sole_trader.pension.gross_pence', 500_000)
                ->where('comparison.options.voluntary_class2', true));

        // A typed profit replaces the owner's; nonsense is ignored and the defaults used.
        $this->get('/planning?year=1999-00&profit=abc&salary=evil&share=500&pension=-5')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('year', '2026-27')
                ->where('inputs.profit', null)
                ->where('inputs.salary', 'best')
                ->where('inputs.share', null)
                ->where('inputs.pension', null)
                ->where('comparison.partnership.partner_share_pct', '50')
                ->where('comparison.company.pension', null));
    }

    public function test_a_finished_year_uses_its_records_and_a_later_year_this_years_projection(): void
    {
        $this->income('2025-09-01', 2_000_000);
        $this->income('2026-06-01', 3_000_000);
        $this->actingAs(User::factory()->create());

        $this->get('/planning?year=2025-26')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('profit_basis.basis', 'records')
            ->where('profit_basis.profit_pence', 2_000_000)
            ->where('comparison.profit_pence', 2_000_000));

        $projected = TaxSavings::projection(UkTaxYear::fromLabel('2026-27'), CarbonImmutable::today(), 3_000_000);
        $this->get('/planning?year=2027-28')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('profit_basis.basis', 'later_year')
            ->where('profit_basis.profit_pence', $projected)
            ->where('profit_basis.label', fn ($l) => str_contains($l, '2026-27') && str_contains($l, 'projected'))
            ->where('comparison.law.announced', true)
            ->where('comparison.law.label', 'Announced, not yet law'));
    }

    public function test_changing_a_figure_reloads_only_the_comparison(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/planning?profit=40000')->assertInertia(fn (AssertableInertia $p) => $p
            ->reloadOnly(['comparison', 'inputs', 'profit_basis'], fn (AssertableInertia $reload) => $reload
                ->has('comparison')
                ->missing('ideas')
                ->missing('funding')
                ->missing('avoid')));
    }

    public function test_the_menu_links_to_it(): void
    {
        $sidebar = (string) file_get_contents(resource_path('js/components/app-sidebar.tsx'));
        $this->assertStringContainsString("{ title: 'Planning', url: '/planning'", $sidebar);
    }

    /**
     * Trusts and inheritance planning can't lower the tax on day-rate earnings (planning: trusts-cannot-shelter-trading-income),
     * so they aren't shown as most likely to matter now; a company exit relief goes with the company ideas.
     */
    public function test_trusts_and_inheritance_are_not_ideas_for_now(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/planning?profit=40000')->assertInertia(fn (AssertableInertia $p) => $p->where('ideas', function ($ideas) {
            $fit = collect($ideas)->pluck('fit', 'id');
            foreach (['interest-in-possession-trust', 'discretionary-accumulation-trust', 'bare-trust', 'trusts-cannot-shelter-trading-income',
                'iht-unused-pensions-april-2027', 'apr-bpr-allowance-april-2026', 'iht-lifetime-gift-exemptions'] as $id) {
                $this->assertSame('later', $fit[$id], $id);
            }
            $this->assertSame('company', $fit['badr-and-founders-relief']);
            // The research's verdict answers "can my family or a trust cut my tax?", and the main lawful ideas stay first.
            foreach (['verdict-family-and-trusts', 'pension-annual-allowance', 'employ-spouse-reasonable-wage', 'spouse-partnership'] as $id) {
                $this->assertSame('likely', $fit[$id], $id);
            }
            foreach (collect($ideas)->filter(fn ($i) => preg_match('/\btrusts?\b/i', $i['name']) && $i['id'] !== 'verdict-family-and-trusts') as $i) {
                $this->assertNotSame('likely', $i['fit'], $i['id']);
            }

            return true;
        }));
        $page = (string) file_get_contents(resource_path('js/pages/planning.tsx'));
        $this->assertStringContainsString('No trust can lower the tax on your day-rate earnings', $page);
    }

    public function test_a_general_grant_finder_is_not_listed_for_the_industry(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/planning?profit=40000')->assertInertia(fn (AssertableInertia $p) => $p->where('funding', function ($funding) {
            $sector = collect($funding)->pluck('sector', 'id');
            // Its "who" says "business stage": not a stage hand.
            $this->assertFalse($sector['local-growth-hubs-council-grants']);
            foreach (['stage-hand', 'theatrical-guild', 'backup-tech', 'screenskills-bursary', 'help-musicians'] as $id) {
                $this->assertTrue($sector[$id], $id);
            }

            return true;
        }));
    }

    public function test_the_owners_profit_is_read_once_a_request(): void
    {
        $this->income('2026-06-01', 3_000_000);
        $this->actingAs(User::factory()->create());
        $reads = 0;
        // Only the planning page's own reads of the year's records (the menu's to-do count reads them too).
        DB::listen(function ($q) use (&$reads) {
            $fromPlanning = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))->contains(fn ($f) => str_ends_with($f['file'] ?? '', 'StructureCalculator.php'));
            if ($fromPlanning && preg_match('/from [`"]?allocations[`"]? where [`"]?tax_year[`"]?/i', $q->sql)) {
                $reads++;
            }
        });
        $this->get('/planning')->assertOk();
        $this->assertSame(1, $reads, 'the full page');

        $reads = 0;
        $this->get('/planning?year=2026-27&pension=1000', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'planning',
            'X-Inertia-Partial-Data' => 'comparison,inputs,profit_basis', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])->assertOk();
        $this->assertSame(1, $reads, 'a partial reload');
    }

    public function test_the_partners_other_income_can_be_self_employment(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/planning?year=2026-27&profit=40000&partner_other=30000&partner_se=1')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('inputs.partner_se', true)
            ->where('comparison.options.partner_other_self_employed', true)
            ->where('comparison.partnership.partner.class4_pence', 120_000));
        $this->get('/planning?year=2026-27&profit=40000&partner_other=30000')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('inputs.partner_se', false)
            ->where('comparison.partnership.partner.class4_pence', 44_580));
    }

    /** The page's own wording and timing: labels in plain English, and the last change wins. */
    public function test_the_page_labels_salaries_in_plain_english_and_a_year_change_wins(): void
    {
        $page = (string) file_get_contents(resource_path('js/pages/planning.tsx'));
        // A menu label lower-cased would read "an amount i choose".
        $this->assertStringNotContainsString('toLowerCase() ?? c.company.salary_option', $page);
        $this->assertStringNotContainsString('?.toLowerCase() ?? s.option', $page);
        $this->assertMatchesRegularExpression("/SALARY_ROW[^=]*= \\{[^}]*custom: 'your figure'/s", $page);
        // A reload (the year selector's included) cancels a figure's pending reload first.
        $this->assertMatchesRegularExpression('/const reload = \(f: Form, y: string, all = false\) => \{\s*if \(timer\.current\) clearTimeout\(timer\.current\);/', $page);
    }
}
