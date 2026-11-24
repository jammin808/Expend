<?php

namespace Tests\Feature\Planning;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\Planning;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/** The assistant's two planning tools: verified items with their links, traps flagged, the comparison, and no personal data. */
class PlanningToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('anthropic_api_key', 'test-key');
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tools(): AssistantTools
    {
        return app(AssistantTools::class);
    }

    /** The owner's records: Alex Smith, paid by Acme Events. */
    private function records(): void
    {
        Setting::put('business_name', 'Alex Smith');
        Setting::put('business_email', 'alex.smith@example.com');
        Setting::put('own_names', ['Alex Smith']);
        $client = Client::query()->create(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards rig', 'client_id' => $client->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01']);
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-02', 'tax_year' => '2026-27',
            'description' => 'ACME EVENTS LTD INV 12', 'merchant_key' => 'ACME EVENTS', 'amount_pence' => 3_000_000, 'raw' => [], 'fingerprint' => md5('acme'), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(3_000_000, 'business_income', Category::query()->where('code', 'turnover')->value('id'), $job->id), 'you');
    }

    public function test_both_tools_are_offered_in_the_existing_style(): void
    {
        $defs = collect(AssistantTools::definitions())->keyBy('name');
        $this->assertTrue($defs->has('planning'));
        $this->assertTrue($defs->has('compare_structures'));
        $this->assertStringContainsString('never_recommend', $defs['planning']['description']);
        $this->assertStringContainsString('url', $defs['planning']['description']);
        $this->assertSame(Planning::LEGALITIES, $defs['planning']['inputSchema']['properties']['legality']['enum']);
        $this->assertStringContainsString('ESTIMATE', $defs['compare_structures']['description']);
        $this->assertStringContainsString('accountant', $defs['compare_structures']['description']);
        $this->assertStringContainsString('announced', $defs['compare_structures']['description']);
    }

    public function test_planning_returns_verified_items_with_links_and_flags_traps(): void
    {
        $out = $this->tools()->run('planning', ['query' => 'remuneration trust loans']);
        $this->assertSame(Planning::researchedOn(), $out['researched_on']);
        $this->assertNotEmpty($out['results']);
        $this->assertStringContainsString('never_recommend', $out['note']);
        $ids = array_column($out['results'], 'id');
        $this->assertContains('remuneration-revenue-trusts-spotlights', $ids);
        foreach ($out['results'] as $r) {
            $this->assertMatchesRegularExpression('#^https?://#', $r['url'], $r['id'].' carries its link');
            foreach (['name', 'summary', 'status', 'figures'] as $key) {
                $this->assertArrayHasKey($key, $r, $r['id'].' has '.$key);
            }
            if (in_array($r['legality'] ?? null, Planning::AVOID, true)) {
                $this->assertSame(Planning::NEVER_RECOMMEND, $r['never_recommend'], $r['id'].' says never to recommend it');
            } else {
                $this->assertArrayNotHasKey('never_recommend', $r, $r['id'].' is not flagged');
            }
        }

        // Every trap HMRC targets, or that is illegal, comes back flagged.
        foreach (Planning::AVOID as $legality) {
            $traps = $this->tools()->run('planning', ['section' => 'traps', 'legality' => $legality, 'limit' => 12])['results'];
            $this->assertNotEmpty($traps);
            foreach ($traps as $t) {
                $this->assertSame('traps', $t['section']);
                $this->assertSame($legality, $t['legality']);
                $this->assertSame(Planning::NEVER_RECOMMEND, $t['never_recommend']);
                $this->assertNotSame('', $t['summary'], 'a trap says why');
            }
        }

        // One item by id, with that year's figures only.
        $item = $this->tools()->run('planning', ['id' => 'spouse-partnership', 'tax_year' => '2026-27'])['item'];
        $this->assertSame('legal_planning_with_conditions', $item['legality']);
        $this->assertArrayHasKey('conditions', $item);
        $this->assertArrayHasKey('risks', $item);
        $this->assertSame(['2026-27'], array_column($item['figures'], 'label'));
        $this->assertStringStartsWith('https://', $item['url']);
        $this->assertArrayNotHasKey('never_recommend', $item);

        // Funding says who it's for, how much and how it is taxed.
        $grant = $this->tools()->run('planning', ['id' => 'backup-tech'])['item'];
        $this->assertSame('grant', $grant['type']);
        foreach (['who', 'amount', 'how_taxed', 'url'] as $key) {
            $this->assertArrayHasKey($key, $grant);
        }
        $this->assertSame(['error' => 'No item with that id.'], $this->tools()->run('planning', ['id' => 'nothing-here']));
    }

    public function test_compare_structures_uses_the_owners_profit_and_returns_checkable_figures_with_links(): void
    {
        $this->records();
        $out = $this->tools()->run('compare_structures', []);
        $this->assertSame('2026-27', $out['tax_year']);
        $this->assertTrue($out['estimate']);
        $this->assertStringContainsString('projection', $out['profit_basis']);
        $this->assertGreaterThan(3_000_000, $out['profit']['pence'], 'projected for the whole year');
        foreach (['sole_trader', 'company', 'partnership'] as $s) {
            $this->assertIsInt($out[$s]['take_home']['pence']);
            $this->assertStringStartsWith('£', $out[$s]['take_home']['formatted']);
        }
        $this->assertNotEmpty($out['sources']);
        foreach ($out['sources'] as $s) {
            $this->assertStringStartsWith('https://', $s['url']);
        }
        $this->assertContains('ir35', array_column($out['warnings'], 'code'));
        $this->assertContains('not_advice', array_column($out['warnings'], 'code'));
        $this->assertFalse($out['law']['announced']);

        // A profit and options asked for: the 2025-26 £60,000 worked example.
        $asked = $this->tools()->run('compare_structures', ['tax_year' => '2025-26', 'profit' => '60000', 'accountant' => '1000', 'software' => '0']);
        $this->assertSame('The profit asked about.', $asked['profit_basis']);
        $this->assertSame(6_000_000, $asked['profit']['pence']);
        $this->assertSame(['pence' => 4_611_140, 'formatted' => '£46,111.40'], $asked['sole_trader']['take_home']);
        // Confirmation statement left to its default (£34 for most of 2025-26): costs 1,034, so 34 less profit, CT and dividends move.
        $this->assertSame(103_400, $asked['company']['running_costs']['total']['pence']);

        $later = $this->tools()->run('compare_structures', ['tax_year' => '2027-28', 'profit' => 60000, 'pension' => '5000']);
        $this->assertTrue($later['law']['announced']);
        $this->assertContains('announced', array_column($later['warnings'], 'code'));
        $this->assertSame(500_000, $later['sole_trader']['pension']['gross']['pence']);
    }

    public function test_no_personal_data_goes_into_the_planning_answers(): void
    {
        $this->records();
        $json = json_encode([
            $this->tools()->run('planning', ['query' => 'spouse partnership company director']),
            $this->tools()->run('compare_structures', []),
            $this->tools()->run('compare_structures', ['profit' => '45000', 'tax_year' => '2027-28']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach (['Alex Smith', 'alex.smith@example.com', 'Acme', 'ACME', 'INV 12'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        // Nothing was sent anywhere (any outside request would have failed the test).
        Http::assertNothingSent();
    }

    public function test_the_assistant_is_told_to_use_them_and_never_to_present_a_trap_as_advice(): void
    {
        $ai = new FakeAiClient([
            FakeAiClient::tool('planning', ['query' => 'remuneration trust'], 'tu_1'),
            FakeAiClient::tool('compare_structures', ['tax_year' => '2025-26', 'profit' => '60000', 'accountant' => '1000', 'software' => '0'], 'tu_2'),
            FakeAiClient::text('As a sole trader you would take home £46,111.40. A remuneration trust is something to avoid. Ask an accountant before incorporating.'),
        ]);
        $this->app->instance(AiClient::class, $ai);
        $out = app(Assistant::class)->ask('Should I set up a company, or use a trust to cut my tax?');

        $system = $ai->requests[0]['system'];
        foreach (['compare_structures', 'planning', 'never_recommend', 'legal_planning_with_conditions', 'relief_intended_by_parliament',
            'announced but not yet law', 'estimate', 'IR35', 'accountant', 'trusts', 'grants'] as $phrase) {
            $this->assertStringContainsString($phrase, $system);
        }
        // The trap reached the model flagged.
        $first = json_encode($ai->requests[1]['messages'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('never_recommend', $first);
        $this->assertStringContainsString('remuneration-revenue-trusts-spotlights', $first);
        // The comparison's figures are checkable: the take-home quoted isn't flagged as unmatched.
        $this->assertSame([], $out['unmatched']);
        $this->assertSame(['planning', 'compare_structures'], array_column($out['tools'], 'tool'));
    }

    public function test_traps_with_a_lawful_label_reach_the_model_as_pitfalls_not_ideas(): void
    {
        // Selling goodwill to your own company is lawful in itself, but it is in the traps: a warning, never an idea.
        $goodwill = $this->tools()->run('planning', ['id' => 'goodwill-on-incorporation'])['item'];
        $this->assertSame('legal_planning_with_conditions', $goodwill['legality']);
        $this->assertSame(Planning::PITFALL, $goodwill['pitfall']);
        $this->assertArrayNotHasKey('never_recommend', $goodwill);

        // Every trap carries one flag or the other, and no idea carries either.
        foreach (Planning::data()['traps'] as $trap) {
            $item = $this->tools()->run('planning', ['id' => $trap['id']])['item'];
            $this->assertTrue(isset($item['pitfall']) xor isset($item['never_recommend']), $trap['id']);
        }
        foreach (Planning::ideas() as $idea) {
            $item = $this->tools()->run('planning', ['id' => $idea['id']])['item'];
            $this->assertArrayNotHasKey('pitfall', $item, $idea['id']);
            $this->assertArrayNotHasKey('never_recommend', $item, $idea['id']);
        }
        // Asking for lawful items still marks the traps among them.
        $lawful = $this->tools()->run('planning', ['query' => 'director loan company', 'legality' => 'legal_planning_with_conditions', 'limit' => 12]);
        $traps = array_filter($lawful['results'], fn ($r) => $r['section'] === 'traps');
        $this->assertNotEmpty($traps);
        foreach ($traps as $t) {
            $this->assertSame(Planning::PITFALL, $t['pitfall'], $t['id']);
        }
        $this->assertStringContainsString('traps section are warnings, not ideas', $lawful['note']);

        $ai = new FakeAiClient([FakeAiClient::text('Fine.')]);
        $this->app->instance(AiClient::class, $ai);
        app(Assistant::class)->ask('Hello');
        $this->assertStringContainsString('Items from the traps section are warnings, not ideas, whatever their legality', $ai->requests[0]['system']);
        $this->assertStringContainsString('Suggest only items outside the traps section', $ai->requests[0]['system']);
    }

    public function test_compare_structures_keeps_the_salary_choice_and_the_partners_income_type(): void
    {
        $none = $this->tools()->run('compare_structures', ['tax_year' => '2025-26', 'profit' => '60000', 'salary' => 'none']);
        $this->assertSame('none', $none['options']['salary_option']);
        $this->assertNull($none['options']['custom_salary']);
        $custom = $this->tools()->run('compare_structures', ['tax_year' => '2025-26', 'profit' => '60000', 'salary' => 'custom', 'salary_amount' => '9000']);
        $this->assertSame('custom', $custom['options']['salary_option']);
        $this->assertSame(['pence' => 900_000, 'formatted' => '£9,000.00'], $custom['options']['custom_salary']);

        // 2026-27, £40,000, the partner has £30,000 of self-employed profit: their Class 4 on the share is £1,200.00.
        $se = $this->tools()->run('compare_structures', ['tax_year' => '2026-27', 'profit' => '40000', 'partner_other_income' => '30000', 'partner_other_self_employed' => true]);
        $this->assertSame(120_000, $se['partnership']['partner']['class4']['pence']);
        $this->assertTrue($se['options']['partner_other_self_employed']);
        $this->assertArrayHasKey('partner_other_self_employed', collect(AssistantTools::definitions())->firstWhere('name', 'compare_structures')['inputSchema']['properties']);
    }
}
