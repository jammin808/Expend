<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\BadRequestException;
use App\Domain\Ai\AiClient;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\AnthropicAiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Ai\AssistantTools;
use App\Domain\Telegram\Telegram;
use App\Models\AssistantLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/** The checks on an assistant answer: reading every kind of API block safely, keeping the whole answer, and flagging only what needs it. */
class AssistantAnswerChecksTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private const S57A = 'https://www.legislation.gov.uk/ukpga/2005/5/section/57A';

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

    // ---- The real client against a fake HTTP transport ----

    private function message(array $content, string $stop = 'end_turn', int $searches = 0): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_'.(++$this->n), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => $content,
            'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 200, 'server_tool_use' => ['web_search_requests' => $searches, 'web_fetch_requests' => 1]],
        ]));
    }

    private function badRequest(string $message): Response
    {
        return new Response(400, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $message]]));
    }

    private function client(array $responses, array &$history): AnthropicAiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new AnthropicAiClient(['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]);
    }

    private function webParams(): array
    {
        return ['maxTokens' => 1000, 'tools' => [...AssistantTools::definitions(), ...Assistant::webTools()], 'messages' => [['role' => 'user', 'content' => 'Meals?']]];
    }

    public function test_a_page_that_could_not_be_read_still_gives_an_answer_and_is_costed(): void
    {
        $history = [];
        $content = [
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_fetch', 'input' => ['url' => 'https://www.gov.uk/missing']],
            ['type' => 'web_fetch_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible']],
            ['type' => 'text', 'text' => 'That page could not be read; from the library: yes.'],
        ];
        $ai = $this->client([$this->message($content), $this->message($content)], $history);

        $result = $ai->send($this->webParams());

        $this->assertSame('That page could not be read; from the library: yes.', $result->text());
        $this->assertSame([], $result->sources);
        $fetch = collect($result->blocks)->firstWhere('type', 'web_fetch_tool_result');
        $this->assertNull($fetch['url']);
        $this->assertSame('url_not_accessible', $fetch['error']);
        $this->assertSame(1, AiUsage::thisMonth()['calls']);

        // Through the assistant, the answer is given and logged, and the call is counted.
        $this->app->instance(AiClient::class, $ai);
        $out = app(Assistant::class)->ask('Can I claim meals?');
        $this->assertSame('That page could not be read; from the library: yes.', $out['answer']);
        $this->assertSame(1, AssistantLog::query()->count());
        $this->assertSame(2, AiUsage::thisMonth()['calls']);
    }

    public function test_a_page_that_was_read_is_a_source_with_its_title(): void
    {
        $history = [];
        $ai = $this->client([$this->message([
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_fetch', 'input' => ['url' => self::S57A]],
            ['type' => 'web_fetch_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => ['type' => 'web_fetch_result', 'url' => self::S57A, 'retrieved_at' => '2026-11-24T10:00:00Z',
                'content' => ['type' => 'document', 'citations' => null, 'title' => 'ITTOIA 2005 s 57A', 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Meals...']]]],
            ['type' => 'text', 'text' => 'Section 57A allows meals.'],
        ])], $history);

        $result = $ai->send($this->webParams());

        $this->assertSame([['url' => self::S57A, 'title' => 'ITTOIA 2005 s 57A']], $result->sources);
    }

    public function test_a_failed_web_search_still_gives_an_answer(): void
    {
        $history = [];
        $ai = $this->client([$this->message([
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'ITTOIA s57A']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => ['type' => 'web_search_tool_result_error', 'error_code' => 'max_uses_exceeded']],
            ['type' => 'text', 'text' => 'I couldn’t search again; the library says meals away overnight are allowable.'],
        ], 'end_turn', 1)], $history);

        $result = $ai->send($this->webParams());

        $this->assertStringContainsString('meals away overnight are allowable', $result->text());
        $this->assertSame([], $result->sources);
        $search = collect($result->blocks)->firstWhere('type', 'web_search_tool_result');
        $this->assertSame(0, $search['count']);
        $this->assertSame('max_uses_exceeded', $search['error']);
        $this->assertSame(1, AiUsage::thisMonth()['web_searches']);
    }

    public function test_citations_of_every_kind_are_read_and_only_web_pages_become_sources(): void
    {
        $history = [];
        $doc = ['cited_text' => 'meals', 'document_index' => 0, 'document_title' => 'Upload', 'file_id' => null];
        $ai = $this->client([$this->message([
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'ITTOIA s57A']],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                ['type' => 'web_search_result', 'url' => self::S57A, 'title' => 'ITTOIA 2005 s 57A', 'encrypted_content' => 'abc', 'page_age' => null],
            ]],
            ['type' => 'text', 'text' => 'Meals away overnight are allowable', 'citations' => [
                ['type' => 'char_location', 'start_char_index' => 0, 'end_char_index' => 5] + $doc,
                ['type' => 'page_location', 'start_page_number' => 1, 'end_page_number' => 2] + $doc,
                ['type' => 'content_block_location', 'start_block_index' => 0, 'end_block_index' => 1] + $doc,
                ['type' => 'search_result_location', 'cited_text' => 'meals', 'search_result_index' => 0, 'source' => 'kb://meals', 'title' => 'Notes', 'start_block_index' => 0, 'end_block_index' => 1],
                ['type' => 'web_search_result_location', 'url' => self::S57A, 'title' => 'ITTOIA 2005 s 57A', 'encrypted_index' => 'x', 'cited_text' => 'meals'],
            ]],
            ['type' => 'text', 'text' => '.'],
        ], 'end_turn', 1)], $history);

        $result = $ai->send($this->webParams());

        $this->assertSame('Meals away overnight are allowable.', $result->text());
        $this->assertSame([self::S57A], collect($result->blocks)->firstWhere('type', 'text')['citations']);
        $this->assertSame([self::S57A], array_column($result->sources, 'url'));
    }

    // ---- Keeping the whole answer (EA-2) ----

    private static function aiResult(array $blocks, string $stop = 'end_turn', array $sources = []): AiResult
    {
        return new AiResult($stop, $blocks, $blocks, 'claude-opus-5-5', 100, 20, $sources);
    }

    public function test_cited_text_before_a_later_search_is_kept_and_only_a_bridging_preamble_is_dropped(): void
    {
        $search = [['type' => 'server_tool_use', 'name' => 'web_search', 'input' => []], ['type' => 'web_search_tool_result', 'count' => 1]];

        $text = self::aiResult([
            ['type' => 'text', 'text' => 'Let me check the current rate.'],
            ...$search,
            ['type' => 'text', 'text' => 'You can pay voluntary Class 2 at '],
            ['type' => 'text', 'text' => '£3.50 a week', 'citations' => ['https://www.gov.uk/voluntary-national-insurance-contributions']],
            ['type' => 'text', 'text' => '. It protects your State Pension record. Let me confirm the deadline.'],
            ...$search,
            ['type' => 'text', 'text' => 'Let me also read the manual.'],
            ...$search,
            ['type' => 'text', 'text' => 'That confirms it.'],
        ])->text();

        $this->assertSame("You can pay voluntary Class 2 at £3.50 a week. It protects your State Pension record. Let me confirm the deadline.\n\nThat confirms it.", $text);

        // A preamble with nothing after it is all there is, so it stays.
        $this->assertSame('Let me check the Act.', self::aiResult([['type' => 'text', 'text' => 'Let me check the Act.'], ...$search])->text());
    }

    public function test_only_a_short_announcement_of_a_lookup_is_dropped(): void
    {
        $search = [['type' => 'server_tool_use', 'name' => 'web_search', 'input' => []], ['type' => 'web_search_tool_result', 'count' => 1]];
        $before = fn (string $text) => self::aiResult([['type' => 'text', 'text' => $text], ...$search, ['type' => 'text', 'text' => 'GOV.UK agrees.']])->text();

        foreach (['I’ll search GOV.UK for the current rate.', 'Let me quickly check the 2026-27 rate.', 'Checking HMRC’s manual.', "I'm going to look up section 57A."] as $preamble) {
            $this->assertSame('GOV.UK agrees.', $before($preamble), $preamble);
        }

        // A short answer, a figure or a link, or more than one sentence, may be advice: kept.
        foreach ([
            'Yes: meals away overnight are allowable under s 57A.',
            'Let me check: the rate is £3.50 a week.',
            'Let me check https://www.gov.uk/mileage.',
            'Yes, you can. Let me check it’s still current.',
        ] as $answer) {
            $this->assertSame($answer."\n\nGOV.UK agrees.", $before($answer), $answer);
        }
    }

    public function test_an_answer_from_the_law_library_checked_by_a_search_is_kept(): void
    {
        $answer = 'Yes. Under ITTOIA 2005 s 57A you can deduct reasonable meals when you stay away overnight for work; day-to-day lunches near your usual base are not allowable. I will check the rule is still current.';
        $ai = new FakeAiClient([
            FakeAiClient::tool('tax_law', ['id' => 'ittoia-s57a']),
            self::aiResult([
                ['type' => 'text', 'text' => $answer],
                ['type' => 'server_tool_use', 'name' => 'web_search', 'input' => ['query' => 'ITTOIA s57A']],
                ['type' => 'web_search_tool_result', 'count' => 1],
                ['type' => 'text', 'text' => 'GOV.UK confirms this is still current.'],
            ]),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask('Can I claim meals when I stay away?');

        $this->assertSame($answer."\n\nGOV.UK confirms this is still current.", $out['answer']);
    }

    private function fetched(string $id, string $url, string $title): array
    {
        return [
            ['type' => 'server_tool_use', 'id' => $id, 'name' => 'web_fetch', 'input' => ['url' => $url]],
            ['type' => 'web_fetch_tool_result', 'tool_use_id' => $id, 'content' => ['type' => 'web_fetch_result', 'url' => $url, 'retrieved_at' => '2026-11-24T10:00:00Z',
                'content' => ['type' => 'document', 'citations' => ['enabled' => true], 'title' => $title, 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Meals...']]]],
        ];
    }

    public function test_an_answer_from_a_page_read_before_a_second_page_is_kept(): void
    {
        $bim = 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim47705';
        $answer = 'Yes. Under ITTOIA 2005 s 57A you can deduct reasonable meals while you stay away overnight for a job, as long as the trip is for work only. Keep the receipts and note the job. Let me check HMRC’s manual as well.';
        $history = [];
        $ai = $this->client([$this->message([
            ...$this->fetched('srvtoolu_1', self::S57A, 'ITTOIA 2005 s 57A'),
            ['type' => 'text', 'text' => $answer],
            ...$this->fetched('srvtoolu_2', $bim, 'BIM47705'),
            ['type' => 'text', 'text' => 'HMRC’s manual agrees.'],
        ])], $history);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask('Can I claim meals when I stay away?');

        $this->assertStringStartsWith($answer."\n\nHMRC’s manual agrees.", $out['answer']);
        $this->assertSame([self::S57A, $bim], array_column($out['sources'], 'url'));

        // Pages read are cited: the request asks for it.
        $sent = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame(['enabled' => true], collect($sent['tools'])->firstWhere('name', 'web_fetch')['citations']);
    }

    public function test_text_citing_a_page_read_counts_as_cited_and_runs_on(): void
    {
        $bim = 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim47705';
        $history = [];
        $ai = $this->client([$this->message([
            ...$this->fetched('srvtoolu_1', self::S57A, 'ITTOIA 2005 s 57A'),
            ['type' => 'text', 'text' => 'Meals away overnight are deductible', 'citations' => [
                ['type' => 'char_location', 'cited_text' => 'meals', 'document_index' => 0, 'document_title' => 'ITTOIA 2005 s 57A', 'start_char_index' => 0, 'end_char_index' => 5, 'file_id' => null],
            ]],
            ['type' => 'text', 'text' => '. Let me check the manual.'],
            ...$this->fetched('srvtoolu_2', $bim, 'BIM47705'),
            ['type' => 'text', 'text' => 'HMRC’s manual agrees.'],
        ])], $history);

        $result = $ai->send($this->webParams());

        $first = collect($result->blocks)->firstWhere('type', 'text');
        $this->assertTrue($first['cited']);
        $this->assertArrayNotHasKey('citations', $first);
        $this->assertSame("Meals away overnight are deductible. Let me check the manual.\n\nHMRC’s manual agrees.", $result->text());
    }

    public function test_an_answer_split_by_a_paused_search_is_kept_whole_with_its_sources(): void
    {
        $ai = new FakeAiClient([
            self::aiResult([
                ['type' => 'server_tool_use', 'name' => 'web_search', 'input' => ['query' => 's57A']],
                ['type' => 'web_search_tool_result', 'count' => 1],
                ['type' => 'text', 'text' => 'Meals away overnight are allowable under section 57A.', 'citations' => [self::S57A]],
                ['type' => 'server_tool_use', 'name' => 'web_fetch', 'input' => ['url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim47705']],
            ], 'pause_turn', [['url' => self::S57A, 'title' => 'ITTOIA 2005 s 57A']]),
            self::aiResult([
                ['type' => 'web_fetch_tool_result', 'url' => 'https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim47705'],
                ['type' => 'text', 'text' => 'HMRC guidance agrees.'],
            ]),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask('Can I claim meals when I stay away?');

        $this->assertStringStartsWith("Meals away overnight are allowable under section 57A.\n\nHMRC guidance agrees.", $out['answer']);
        $this->assertStringContainsString("Sources:\n- ITTOIA 2005 s 57A: ".self::S57A, $out['answer']);
    }

    public function test_text_from_before_a_records_lookup_is_not_part_of_the_answer(): void
    {
        $ai = new FakeAiClient([
            self::aiResult([['type' => 'text', 'text' => 'I’ll look at your invoices.'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'invoices', 'input' => []]], 'tool_use'),
            FakeAiClient::text('You have no unpaid invoices.'),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $this->assertSame('You have no unpaid invoices.', app(Assistant::class)->ask('Anything unpaid?')['answer']);
    }

    // ---- Case names (F4, F5) ----

    public function test_a_fixture_from_the_records_is_not_a_court_case(): void
    {
        WorkJob::query()->create(['title' => 'Arsenal v Chelsea OB', 'starts_on' => '2026-03-15', 'ends_on' => '2026-03-15']);
        $ai = new FakeAiClient([
            FakeAiClient::tool('list_jobs', ['query' => 'Arsenal']),
            FakeAiClient::text('Your job on 15 March was Arsenal v Chelsea OB (job 1). Before that, Then Arsenal v Chelsea was the plan. Smith v Jones says nothing about it.'),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask('What was my job on 15 March?');

        // The job name isn't flagged, in full or with a word in front; an invented case still is.
        $this->assertSame(['case Smith v Jones (not in the checked library or the official pages read)'], $out['notes']);
        $this->assertSame([], $out['unmatched']);
    }

    public function test_names_from_the_records_count_but_the_law_library_does_not_vouch_for_a_case(): void
    {
        $records = [json_encode(['jobs' => [['title' => 'England vs Wales', 'venue' => 'Principality Stadium']]])];
        $this->assertSame([], Assistant::unknownCases('The OB for England v Wales (job 12).', [], $records));
        $this->assertSame(['case Smith v Jones (not in the checked library or the official pages read)'], Assistant::unknownCases('Smith v Jones applies.', [], $records));

        // R v Dimsey is in what tax_law returned, but the library marks it unchecked, so it is still flagged.
        $ai = new FakeAiClient([
            FakeAiClient::tool('tax_law', ['id' => 'r-v-dimsey']),
            FakeAiClient::text('R v Dimsey is about offshore income.'),
        ]);
        $this->app->instance(AiClient::class, $ai);
        $out = app(Assistant::class)->ask('What did R v Dimsey decide?');
        $this->assertSame(['case R v Dimsey (not checked against the judgment: don’t rely on it)'], $out['notes']);
    }

    public function test_a_case_counts_as_read_only_when_one_page_title_names_both_parties(): void
    {
        $sources = [
            ['url' => 'https://www.gov.uk/working-for-yourself', 'title' => 'Working for yourself'],
            ['url' => 'https://www.gov.uk/guidance/making-tax-digital-for-income-tax', 'title' => 'Making Tax Digital for Income Tax'],
            ['url' => 'https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/1', 'title' => 'Smith v The Commissioners for HMRC [2026] UKFTT 1 (TC)'],
            ['url' => 'https://assets.publishing.service.gov.uk/media/abc/guide.pdf', 'title' => 'Losses: carry forward of trading losses'],
            ['url' => 'https://www.gov.uk/hmrc-internal-manuals/x', 'title' => 'Jones and HMRC: a note'],
        ];

        $flags = Assistant::unknownCases('King v HMRC, Ward v HMRC, Law v HMRC, Nation v HMRC, Digital Ltd v HMRC and Jones v HMRC. But Smith v HMRC is real.', $sources);

        foreach (['King', 'Ward', 'Law', 'Nation', 'Digital Ltd', 'Jones'] as $party) {
            $this->assertContains("case {$party} v HMRC (not in the checked library or the official pages read)", $flags);
        }
        $this->assertCount(6, $flags);
    }

    public function test_a_page_title_must_name_every_word_the_answer_uses_for_a_party(): void
    {
        $sources = [['url' => 'https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/2', 'title' => 'Timothy David Hanlin v The Commissioners for HMRC [2026] UKFTT 2 (TC)']];

        // One shared word ("David") isn't enough: the page is someone else's case.
        $this->assertSame(['case David Jones v HMRC (not in the checked library or the official pages read)'], Assistant::unknownCases('David Jones v HMRC applies.', $sources));
        $this->assertSame([], Assistant::unknownCases('Timothy David Hanlin v HMRC applies, as does Hanlin v HMRC.', $sources));
    }

    // ---- Links (F6) ----

    public function test_a_link_must_match_a_known_one_whole_not_just_its_start(): void
    {
        $known = [
            json_encode(['item' => ['url' => 'https://www.legislation.gov.uk/ukpga/2005/5/section/34']]),
            json_encode(['item' => ['url' => 'https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/911', 'act' => 'https://www.legislation.gov.uk/ukpga/2005/5']], JSON_UNESCAPED_SLASHES),
            'https://caselaw.nationalarchives.gov.uk/uksc/2021/16',
        ];

        // Shortened links to a different section or judgment are flagged, as is a section under a known Act.
        $answer = 'See https://www.legislation.gov.uk/ukpga/2005/5/section/3, https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/91, '
            .'https://caselaw.nationalarchives.gov.uk/uksc/2021/1 and https://www.legislation.gov.uk/ukpga/2005/5/section/57A.';
        $this->assertSame([
            'link https://www.legislation.gov.uk/ukpga/2005/5/section/3',
            'link https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/91',
            'link https://caselaw.nationalarchives.gov.uk/uksc/2021/1',
            'link https://www.legislation.gov.uk/ukpga/2005/5/section/57A',
        ], Assistant::unmatchedLinks($answer, $known));

        // The same page written differently, or a version of it, passes.
        $same = 'See http://WWW.legislation.gov.uk/ukpga/2005/5/section/34/#p1, https://legislation.gov.uk/ukpga/2005/5/section/34/enacted, '
            .'https://www.legislation.gov.uk/ukpga/2005/5/section/34/2024-04-06/data.pdf, (https://caselaw.nationalarchives.gov.uk/uksc/2021/16/) and '
            .'https://caselaw.nationalarchives.gov.uk/ukftt/tc/2026/911.';
        $this->assertSame([], Assistant::unmatchedLinks($same, $known));
    }

    // ---- Web search switched off, or refused for another reason (F10) ----

    public function test_only_a_switched_off_web_search_falls_back_to_the_library(): void
    {
        $this->assertTrue(AnthropicAiClient::webToolsSwitchedOff('web_search is not enabled for this organization'));
        $this->assertTrue(AnthropicAiClient::webToolsSwitchedOff('Web fetch is not enabled.'));
        $this->assertTrue(AnthropicAiClient::webToolsSwitchedOff('The web search tool is disabled for your organisation'));
        $this->assertTrue(AnthropicAiClient::webToolsSwitchedOff('web_fetch is not allowed for this API key'));
        $this->assertFalse(AnthropicAiClient::webToolsSwitchedOff("tools.1.allowed_domains: bailii.org is not in your organization's allowed domains for web_search"));
        $this->assertFalse(AnthropicAiClient::webToolsSwitchedOff('web_search: allowed_domains entry bailii.org is not allowed by your organization'));
        $this->assertFalse(AnthropicAiClient::webToolsSwitchedOff('tools.0.max_uses: must be at least 1'));

        // The tool given by its type, after a field path, or with a colon, is still switched off.
        foreach ([
            'tools.12: web_search_20260209 is not enabled for this organization',
            'tools.1.type: web_search_20260209 is not enabled for your organization',
            'The web_fetch_20260209 tool is not enabled for your organization.',
            'web_search: not enabled for this organization',
            'tools.1.web_search: this tool is not enabled for your organization',
            'Web search is not available for your organization',
            // Domains named in another sentence don't matter.
            'Web search is not enabled for your organization. An admin can turn it on and set allowed domains in the Console.',
        ] as $message) {
            $this->assertTrue(AnthropicAiClient::webToolsSwitchedOff($message, Assistant::OFFICIAL_DOMAINS), $message);
        }

        // A sentence that names a domain, by the word or one the request sent, is a setting to fix; so is a model that lacks the tool.
        foreach ([
            'web_search is not enabled for domain bailii.org',
            'web_search: bailii.org is not enabled',
            'web_fetch_20260209: www.judiciary.uk is disabled for your organization',
            'web_search_20260209 is not available for this model',
            'web_searches_total is not enabled',
        ] as $message) {
            $this->assertFalse(AnthropicAiClient::webToolsSwitchedOff($message, Assistant::OFFICIAL_DOMAINS), $message);
        }
    }

    public function test_a_switched_off_tool_named_by_its_type_falls_back_to_the_library(): void
    {
        $history = [];
        $ai = $this->client([
            $this->badRequest('tools.12: web_search_20260209 is not enabled for this organization'),
            $this->message([['type' => 'text', 'text' => 'From the library: yes.']]),
        ], $history);

        $result = $ai->send($this->webParams());

        $this->assertTrue($result->webUnavailable);
        $this->assertSame('From the library: yes.', $result->text());
        $this->assertCount(2, $history);
        $retry = json_decode((string) $history[1]['request']->getBody(), true);
        $this->assertSame([], array_values(array_filter(array_column($retry['tools'], 'name'), fn ($n) => str_starts_with($n, 'web_'))));
    }

    public function test_a_web_tool_setting_the_api_rejects_is_reported_not_hidden(): void
    {
        $history = [];
        $ai = $this->client([
            $this->badRequest("tools.1.allowed_domains: bailii.org is not in your organization's allowed domains for web_search"),
            $this->message([['type' => 'text', 'text' => 'Should not be reached.']]),
        ], $history);

        try {
            $ai->send($this->webParams());
            $this->fail('The 400 should be reported.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Web search couldn’t run: tools.1.allowed_domains: bailii.org is not in your organization', $e->getMessage());
            $this->assertStringContainsString('Capabilities', $e->getMessage());
        }
        $this->assertCount(1, $history);
    }

    public function test_other_bad_requests_are_not_mistaken_for_web_search(): void
    {
        $history = [];
        $ai = $this->client([$this->badRequest('max_tokens: 999999 is greater than the maximum')], $history);

        $this->expectException(BadRequestException::class);
        $ai->send($this->webParams());
    }

    public function test_the_fallback_is_tried_once_and_never_loops(): void
    {
        $history = [];
        $ai = $this->client([
            $this->badRequest('web_search is not enabled for this organization'),
            $this->badRequest('messages: something else is wrong'),
            $this->message([['type' => 'text', 'text' => 'Should not be reached.']]),
        ], $history);

        try {
            $ai->send($this->webParams());
            $this->fail('The second 400 should be thrown.');
        } catch (BadRequestException) {
        }
        $this->assertCount(2, $history);
    }

    // ---- Notes kept apart from unmatched figures and links (EA-7) ----

    public function test_case_notes_and_web_off_are_notes_not_missing_records_and_are_kept_in_history(): void
    {
        $ai = new FakeAiClient([
            new AiResult('tool_use', [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'invoices', 'input' => []]], [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'invoices', 'input' => []]],
                'claude-opus-5-5', 100, 20, [], 0, true),
            FakeAiClient::text('Smith v Jones says you can claim £99.00. See https://example.com/made-up.'),
        ]);
        $this->app->instance(AiClient::class, $ai);

        $out = app(Assistant::class)->ask('Can I claim it?');

        // The account has web search off: it isn't offered again in the same question.
        $this->assertContains('web_search', array_column($ai->requests[0]['tools'], 'name'));
        $this->assertNotContains('web_search', array_column($ai->requests[1]['tools'], 'name'));

        $this->assertSame(['£99.00', 'link https://example.com/made-up'], $out['unmatched']);
        $this->assertSame(['case Smith v Jones (not in the checked library or the official pages read)', Assistant::WEB_OFF_NOTE], $out['notes']);

        $log = AssistantLog::query()->find($out['log_id']);
        $this->assertSame($out['notes'], $log->notes);
        $this->assertSame($out['unmatched'], $log->unmatched_figures);

        // The page shows them from history, and the chat reply words them on their own.
        $this->actingAs(User::factory()->create());
        $this->get('/assistant')->assertOk()->assertInertia(fn ($page) => $page
            ->where('history.0.notes', $out['notes'])->where('history.0.unmatched', $out['unmatched']));

        $reply = Telegram::answerWarnings($out['unmatched'], $out['notes']);
        $this->assertStringContainsString('⚠ Not found in your records: £99.00, link https://example.com/made-up. Treat these as unverified.', $reply);
        $this->assertStringContainsString('ℹ Check before relying on: case Smith v Jones (not in the checked library or the official pages read); anything newer than the built-in law library (web search is off for this API key).', $reply);
        $this->assertStringNotContainsString('Not found in your records: case', $reply);
    }

    public function test_the_ask_endpoint_returns_notes_apart(): void
    {
        $this->app->instance(AiClient::class, new FakeAiClient([FakeAiClient::text('Smith v Jones settles it.')]));
        $this->actingAs(User::factory()->create());

        $this->postJson('/assistant/ask', ['question' => 'Is there a case?'])->assertOk()
            ->assertJsonPath('unmatched', [])
            ->assertJsonPath('notes', ['case Smith v Jones (not in the checked library or the official pages read)']);
    }
}
