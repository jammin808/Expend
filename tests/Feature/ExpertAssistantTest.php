<?php

namespace Tests\Feature;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\AnthropicAiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\TaxLaw;
use App\Domain\Tax\TaxSavings;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/** The assistant as accountant: checked law, official-source search only, citation checks, and a legal tax-savings review. */
class ExpertAssistantTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('anthropic_api_key', 'test-key');
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function tx(string $date, int $pence, string $kind, ?string $code = null): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $kind.' '.$this->n, 'merchant_key' => strtoupper($kind).$this->n, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $code ? Category::query()->where('code', $code)->value('id') : null));

        return $tx;
    }

    private function item(array $review, string $id): ?array
    {
        return collect($review['items'])->firstWhere('id', $id);
    }

    // ---- The law library ----

    public function test_the_law_library_finds_cases_and_never_offers_unchecked_ones_as_authority(): void
    {
        $hits = TaxLaw::search('hotel meals overnight', 'case');
        $this->assertNotEmpty($hits);
        foreach ($hits as $h) {
            $this->assertTrue(isset($h['url']) || isset($h['do_not_cite']));
        }

        $dimsey = TaxLaw::present(TaxLaw::find('r-v-dimsey'), 'case');
        $this->assertArrayHasKey('do_not_cite', $dimsey);
        $this->assertArrayNotHasKey('url', $dimsey);

        foreach (TaxLaw::citableUrls() as $url) {
            $this->assertMatchesRegularExpression('#^https?://#', $url);
        }
    }

    public function test_case_names_in_an_answer_are_checked_against_the_library_and_the_pages_read(): void
    {
        $answer = 'In Mallalieu v Drummond the Lords refused the clothing. See HMRC v Healy on hotels, and Powell v Jackman. '
            .'Barclays Mercantile v Mawson is the modern approach. R v Dimsey is about offshore income. Smith v Jones is not real. '
            .'Cash Basis v Accruals is a choice, not a case.';

        $flags = Assistant::unknownCases($answer, []);
        $this->assertSame([
            'case R v Dimsey (not checked against the judgment: don’t rely on it)',
            'case Smith v Jones (not in the checked library or the official pages read)',
        ], $flags);

        // A case the assistant found on an official page it actually read is fine.
        $read = [['url' => 'https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/1', 'title' => 'Smith v Jones [2026] UKFTT 1 (TC)']];
        $this->assertSame(['case R v Dimsey (not checked against the judgment: don’t rely on it)'], Assistant::unknownCases($answer, $read));
    }

    public function test_only_official_sites_count_as_sources(): void
    {
        $this->assertTrue(Assistant::isOfficial('https://www.legislation.gov.uk/ukpga/2005/5/section/57A'));
        $this->assertTrue(Assistant::isOfficial('https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim47705'));
        $this->assertTrue(Assistant::isOfficial('https://caselaw.nationalarchives.gov.uk/uksc/2024/29'));
        $this->assertTrue(Assistant::isOfficial('https://www.bailii.org/uk/cases/UKHL/1983/TC_57_330.html'));
        $this->assertFalse(Assistant::isOfficial('https://www.taxadvice-blog.example/meals'));
        $this->assertFalse(Assistant::isOfficial('https://gov.uk.evil.example/'));
    }

    // ---- The assistant loop ----

    public function test_the_assistant_offers_official_only_web_search_and_resumes_a_paused_search(): void
    {
        $ai = new FakeAiClient([
            new AiResult('pause_turn', [['type' => 'server_tool_use', 'name' => 'web_search', 'input' => ['query' => 'ITTOIA 2005 s57A meals']], ['type' => 'web_search_tool_result', 'count' => 2]],
                [['type' => 'server_tool_use', 'id' => 'srv_1', 'name' => 'web_search', 'input' => ['query' => 'ITTOIA 2005 s57A meals']]], 'claude-opus-5-5', 100, 20,
                [['url' => 'https://www.legislation.gov.uk/ukpga/2005/5/section/57A', 'title' => 'Income Tax (Trading and Other Income) Act 2005, s 57A'],
                    ['url' => 'https://someblog.example/meals', 'title' => 'A blog']], 1),
            new AiResult('end_turn', [
                ['type' => 'text', 'text' => 'Meals while away overnight for work are allowable. '],
                ['type' => 'text', 'text' => 'Section 57A covers the rules', 'citations' => ['https://www.legislation.gov.uk/ukpga/2005/5/section/57A']],
                ['type' => 'text', 'text' => ' (see https://someblog.example/meals).'],
            ], [], 'claude-opus-5-5', 100, 40, [], 0),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask('Can I claim meals when I stay away on a job?');

        // Web tools are offered, limited to official domains, with a cap on uses.
        $tools = collect($ai->requests[0]['tools'])->keyBy('name');
        $this->assertSame('web_search_20260209', $tools['web_search']['type']);
        $this->assertSame(Assistant::OFFICIAL_DOMAINS, $tools['web_search']['allowedDomains']);
        $this->assertSame(5, $tools['web_search']['maxUses']);
        $this->assertSame('web_fetch_20260209', $tools['web_fetch']['type']);
        $this->assertArrayHasKey('tax_law', $tools);
        $this->assertArrayHasKey('tax_savings', $tools);

        // A paused turn is sent back as it is: the conversation ends with the assistant's partial turn, no new user message.
        $this->assertCount(2, $ai->requests);
        $second = $ai->requests[1]['messages'];
        $this->assertSame('assistant', end($second)['role']);
        $this->assertCount(2, $second);

        // The answer is joined back together around the citation, the cited law is listed, the blog is not a source.
        $this->assertStringContainsString('allowable. Section 57A covers the rules (see', $out['answer']);
        $this->assertStringContainsString("Sources:\n- Income Tax (Trading and Other Income) Act 2005, s 57A: https://www.legislation.gov.uk/ukpga/2005/5/section/57A", $out['answer']);
        $this->assertSame(['https://www.legislation.gov.uk/ukpga/2005/5/section/57A'], array_column($out['sources'], 'url'));
        $this->assertSame(['link https://someblog.example/meals'], $out['unmatched']);
        $this->assertSame([], $out['notes']);
        $this->assertSame(1, $out['web_searches']);
        $this->assertSame('web_search', $out['tools'][0]['tool']);
    }

    public function test_web_search_can_be_switched_off(): void
    {
        Setting::put('ai_web_research', false);
        $ai = new FakeAiClient([FakeAiClient::text('Fine.')]);
        $this->app->instance(AiClient::class, $ai);
        app(Assistant::class)->ask('Hello');

        $names = array_column($ai->requests[0]['tools'], 'name');
        $this->assertNotContains('web_search', $names);
        $this->assertNotContains('web_fetch', $names);
        $this->assertStringContainsString('Web search is off', $ai->requests[0]['system']);
    }

    public function test_the_prompt_carries_the_planning_boundary(): void
    {
        $ai = new FakeAiClient([FakeAiClient::text('Fine.')]);
        $this->app->instance(AiClient::class, $ai);
        app(Assistant::class)->ask('Hello');
        $system = $ai->requests[0]['system'];

        $this->assertStringContainsString('accountant and tax adviser', $system);
        $this->assertStringContainsString('Hiding or not declaring income', $system);
        $this->assertStringContainsString('never put names, clients, amounts or other personal details in a query', $system);
        $this->assertStringContainsString(TaxLaw::researchedOn(), $system);
    }

    // ---- The real client, against a fake HTTP transport ----

    private function message(array $content, string $stop = 'end_turn', int $searches = 0): array
    {
        return ['id' => 'msg_'.(++$this->n), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => $content,
            'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'server_tool_use' => ['web_search_requests' => $searches, 'web_fetch_requests' => 0]]];
    }

    private function client(array $responses, array &$history): AnthropicAiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new AnthropicAiClient(['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]);
    }

    public function test_the_client_reads_search_results_and_citations_and_sends_them_back_intact(): void
    {
        $history = [];
        $search = [
            ['type' => 'text', 'text' => 'Let me check the Act.'],
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'ITTOIA 2005 section 57A']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                ['type' => 'web_search_result', 'url' => 'https://www.legislation.gov.uk/ukpga/2005/5/section/57A', 'title' => 'ITTOIA 2005 s 57A', 'encrypted_content' => 'abc123', 'page_age' => null],
            ]],
        ];
        $answer = [
            ['type' => 'text', 'text' => 'Meals on overnight work trips are allowable', 'citations' => [
                ['type' => 'web_search_result_location', 'url' => 'https://www.legislation.gov.uk/ukpga/2005/5/section/57A', 'title' => 'ITTOIA 2005 s 57A', 'encrypted_index' => 'x', 'cited_text' => 'meals'],
            ]],
            ['type' => 'text', 'text' => '.'],
        ];
        $ai = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->message($search, 'pause_turn', 1))),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->message($answer))),
        ], $history);

        $tools = [...AssistantTools::definitions(), ...Assistant::webTools()];
        $first = $ai->send(['maxTokens' => 1000, 'tools' => $tools, 'messages' => [['role' => 'user', 'content' => 'Meals?']]]);
        $this->assertSame('pause_turn', $first->stopReason);
        $this->assertSame(1, $first->webSearches);
        $this->assertSame('https://www.legislation.gov.uk/ukpga/2005/5/section/57A', $first->sources[0]['url']);

        // The request carried the web tools in the API's own field names.
        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $web = collect($sent['tools'])->firstWhere('name', 'web_search');
        $this->assertSame(Assistant::OFFICIAL_DOMAINS, $web['allowed_domains']);
        $this->assertSame(5, $web['max_uses']);

        // Sending the paused turn back keeps the search blocks (including the encrypted result) as the API sent them.
        $second = $ai->send(['maxTokens' => 1000, 'tools' => $tools, 'messages' => [['role' => 'user', 'content' => 'Meals?'], ['role' => 'assistant', 'content' => $first->assistantContent]]]);
        $body = json_decode((string) $history[1]['request']->getBody(), true);
        $back = $body['messages'][1]['content'];
        $this->assertSame(['text', 'server_tool_use', 'web_search_tool_result'], array_column($back, 'type'));
        $this->assertSame('srvtoolu_1', $back[1]['id']);
        $this->assertSame('srvtoolu_1', $back[2]['tool_use_id']);
        $this->assertSame('abc123', $back[2]['content'][0]['encrypted_content']);

        $this->assertSame('Meals on overnight work trips are allowable.', $second->text());
        $this->assertSame(['https://www.legislation.gov.uk/ukpga/2005/5/section/57A'], $second->blocks[0]['citations']);

        // The search is costed.
        $this->assertSame(1, AiUsage::thisMonth()['web_searches']);
    }

    public function test_an_account_without_web_search_still_gets_an_answer(): void
    {
        $history = [];
        $ai = $this->client([
            new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'web_search is not enabled for this organization']])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($this->message([['type' => 'text', 'text' => 'From the library: yes.']]))),
        ], $history);

        $result = $ai->send(['maxTokens' => 1000, 'tools' => [...AssistantTools::definitions(), ...Assistant::webTools()], 'messages' => [['role' => 'user', 'content' => 'Meals?']]]);

        // Only "not enabled" falls back; a rejected domain or setting is reported instead (AssistantAnswerChecksTest).
        $this->assertTrue($result->webUnavailable);
        $this->assertSame('From the library: yes.', $result->text());
        $retry = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertNotContains('web_search', array_column($retry['tools'], 'name'));
    }

    // ---- Ways to pay less tax ----

    public function test_a_higher_rate_year_suggests_pension_relief_and_the_missing_home_claim(): void
    {
        $this->tx('2025-06-10', 7_000_000, 'business_income', 'turnover');
        $this->tx('2025-07-10', -50_000, 'business_expense', 'software');

        $review = TaxSavings::review('2025-26');
        $pension = $this->item($review, 'pension-contributions');
        $this->assertNotNull($pension);
        $higher = 7_000_000 - 50_000 - (1_257_000 + 3_770_000);
        $this->assertSame(intdiv($higher * 20, 100), $pension['saving_pence']);
        $this->assertSame('action', $pension['status']);
        $this->assertNotEmpty($pension['sources']);
        foreach ($pension['sources'] as $s) {
            $this->assertContains($s['url'], TaxLaw::citableUrls());
        }

        $home = $this->item($review, 'use-of-home');
        $this->assertSame(31_200, $home['deduction_pence']);
        // The saving on the home claim is at the marginal rate (40% + 2%).
        $this->assertSame('42', $review['marginal_pct']);
        $this->assertSame(intdiv(31_200 * 42, 100), $home['saving_pence']);

        $this->assertNull($this->item($review, 'voluntary-class-2'));
        $this->assertNotEmpty($review['more']);
        $this->assertStringContainsString('estimates', $review['note']);
    }

    public function test_a_low_profit_year_suggests_voluntary_class_2_and_claiming_home_is_not_repeated_once_claimed(): void
    {
        $this->tx('2025-06-10', 500_000, 'business_income', 'turnover');
        $this->tx('2025-07-10', -31_200, 'business_expense', 'use_of_home');
        $this->tx('2025-07-11', -12_000, 'business_expense', 'phone_internet');

        $review = TaxSavings::review('2025-26');
        $c2 = $this->item($review, 'voluntary-class-2');
        $this->assertNotNull($c2);
        $this->assertStringContainsString('£3.50 a week', $c2['finding']);
        $this->assertNull($this->item($review, 'use-of-home'));
        $this->assertNull($this->item($review, 'phone-broadband'));
        $this->assertNull($this->item($review, 'pension-contributions'));
    }

    public function test_the_assistant_tool_returns_savings_as_checkable_money(): void
    {
        $this->tx('2025-06-10', 7_000_000, 'business_income', 'turnover');
        $out = app(AssistantTools::class)->run('tax_savings', ['tax_year' => '2025-26']);

        $pension = collect($out['items'])->firstWhere('id', 'pension-contributions');
        $this->assertSame(['pence', 'formatted'], array_keys($pension['saving_estimate']));
        $this->assertArrayNotHasKey('saving_pence', $pension);
        // The headline and the fines are money too, and no bare pence figure is left for the model to misread.
        $this->assertFalse($out['in_progress']);
        $this->assertSame(['pence', 'formatted'], array_keys($out['total_saving_estimate']));
        $this->assertGreaterThan(0, $out['total_saving_estimate']['pence']);
        $this->assertSame(['pence' => 0, 'formatted' => '£0.00'], $out['fines_and_interest_so_far']);
        array_walk_recursive($out, fn ($v, $k) => $this->assertFalse(is_string($k) && str_ends_with($k, '_pence'), "{$k} is a bare pence figure"));
        $total = $out['total_saving_estimate']['formatted'];
        $this->assertSame([], Assistant::unmatchedFigures("Together these could save you {$total}.", [json_encode($out, JSON_UNESCAPED_UNICODE)]));

        $law = app(AssistantTools::class)->run('tax_law', ['query' => 'travel temporary workplace', 'kind' => 'legislation']);
        $this->assertNotEmpty($law['results']);
        $one = app(AssistantTools::class)->run('tax_law', ['id' => 'r-v-dimsey']);
        $this->assertArrayHasKey('do_not_cite', $one['item']);
    }
}
