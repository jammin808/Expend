<?php

namespace Tests\Feature\Intel;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaWebFetchToolResultBlock;
use App\Domain\Ai\AiUsage;
use App\Domain\Ai\AnthropicAiClient;
use App\Models\Setting;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\TestCase;

/**
 * The monthly AI cap holds when calls overlap: a call sets aside the most it could cost before it's sent, is refused if
 * that could pass the cap, and settles at its real cost afterwards, each step with the month's figures locked.
 */
class AiBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 10:00:00');
        Setting::put('anthropic_api_key', 'test-key');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Spend $usd this month at Opus input prices ($4 a million tokens). */
    private function spend(float $usd): void
    {
        AiUsage::record('claude-opus-5-5', (int) round($usd * 250_000), 0);
    }

    public function test_two_usage_records_add_up_exactly(): void
    {
        AiUsage::record('claude-opus-5-5', 1000, 200);
        AiUsage::record('claude-opus-5-5', 1000, 200, 1);

        $usage = AiUsage::thisMonth();
        $this->assertSame([2000, 400, 2, 1], [$usage['input'], $usage['output'], $usage['calls'], $usage['web_searches']]);
        $this->assertSame(0.026, $usage['usd'], '2 × (1000 × $4 + 200 × $20) a million, and one search at a cent');
    }

    public function test_a_reserve_that_would_pass_the_cap_is_refused(): void
    {
        Setting::put('ai_monthly_cap_usd', 1);
        $this->spend(0.90);

        try {
            AiUsage::guard(0.20);
            $this->fail('A call that could take the month to $1.10 must not be sent');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AI spending cap ($1) is nearly reached', $e->getMessage());
        }

        $first = AiUsage::guard(0.05);
        $this->assertNotNull($first);
        $this->assertSame(0.05, AiUsage::thisMonth()['reserved']);
        $this->assertArrayNotHasKey('reservations', AiUsage::thisMonth(), 'Only the total is shown');

        // A second call while the first is under way: both together could pass the cap.
        $this->expectExceptionMessage('nearly reached');
        AiUsage::guard(0.06);
    }

    public function test_settling_and_releasing_free_the_reservation(): void
    {
        Setting::put('ai_monthly_cap_usd', 1);
        $this->spend(0.90);
        $first = AiUsage::guard(0.05);

        AiUsage::record('claude-opus-5-5', 2500, 0, 0, $first); // it cost a cent
        $usage = AiUsage::thisMonth();
        $this->assertSame([0.91, 0.0], [$usage['usd'], (float) $usage['reserved']]);

        $second = AiUsage::guard(0.09);
        AiUsage::release($second); // the call failed: nothing owed
        $this->assertSame(0.0, (float) AiUsage::thisMonth()['reserved']);
        $this->assertSame(0.91, AiUsage::thisMonth()['usd']);
        $this->assertNotNull(AiUsage::guard(0.09), 'The released amount is free again');
    }

    public function test_the_cap_reached_stops_every_call_and_a_lost_reservation_lapses(): void
    {
        Setting::put('ai_monthly_cap_usd', 1);
        $this->spend(0.90);
        AiUsage::guard(0.08); // a call whose process stopped before it reported back

        try {
            AiUsage::guard(0.08);
            $this->fail('Both together could pass the cap');
        } catch (RuntimeException) {
        }
        Carbon::setTestNow(now()->addMinutes(AiUsage::RESERVATION_MINUTES + 1));
        $this->assertNotNull(AiUsage::guard(0.08), 'The stale reservation has lapsed');

        $this->spend(0.10);
        $this->expectExceptionMessage('This month’s AI spending cap ($1) is reached.');
        AiUsage::guard();
    }

    public function test_the_months_figures_are_changed_with_the_row_locked(): void
    {
        $this->spend(0.10); // the month's row exists
        $queries = $this->queries(function () {
            $reservation = AiUsage::guard(0.5);
            AiUsage::record('claude-opus-5-5', 10, 10, 0, $reservation);
        });

        $locked = array_values(array_filter($queries, fn ($q) => str_contains($q['sql'], 'from "settings"') && str_contains($q['sql'], 'for update')));
        $this->assertCount(2, $locked, 'Read under lock by both the reservation and the settling');
        foreach ($locked as $q) {
            $this->assertTrue($q['nested'], 'Locked inside a transaction, until the change is saved');
        }
    }

    public function test_the_estimate_covers_output_searches_page_reads_and_pdf_pages(): void
    {
        $text = AiUsage::estimate(['model' => 'claude-opus-5-5', 'maxTokens' => 1000, 'messages' => [['role' => 'user', 'content' => str_repeat('a', 300)]]]);
        $this->assertEqualsWithDelta(0.0204, $text, 0.0002, '1,000 output tokens at $20 a million, and the text at three characters a token');

        $web = AiUsage::estimate(['model' => 'claude-opus-5-5', 'maxTokens' => 1000, 'messages' => [], 'tools' => [
            ['type' => 'web_search_20260209', 'name' => 'web_search', 'maxUses' => 5],
            ['type' => 'web_fetch_20260209', 'name' => 'web_fetch', 'maxUses' => 2, 'maxContentTokens' => 10_000],
        ]]);
        $this->assertGreaterThan(0.02 + 0.05 + (50_000 + 20_000) * 4 / 1_000_000, $web, 'Five searches with their results, and two pages read');

        $pdf = "%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n2 0 obj << /Type /Page >> endobj\n3 0 obj << /Type /Pages >> endobj";
        $doc = AiUsage::estimate(['model' => 'claude-opus-5-5', 'maxTokens' => 0, 'messages' => [['role' => 'user', 'content' => [
            ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($pdf)]],
        ]]]]);
        $this->assertEqualsWithDelta(6000 * 4 / 1_000_000, $doc, 0.0002, 'Two pages, not the length of the file in base64');
    }

    public function test_the_months_row_is_made_on_its_own_before_the_locked_change(): void
    {
        $this->assertNull(Setting::query()->whereKey('ai_usage_2026_11')->value('value'), 'The first AI call of the month');

        $queries = $this->queries(fn () => AiUsage::guard(0.5));

        $insert = collect($queries)->search(fn ($q) => str_starts_with($q['sql'], 'insert') && str_contains($q['sql'], 'into "settings"'));
        $locked = collect($queries)->search(fn ($q) => str_contains($q['sql'], 'from "settings"') && str_contains($q['sql'], 'for update'));
        $this->assertIsInt($insert, 'The row is made');
        $this->assertIsInt($locked, 'and then read under lock');
        $this->assertLessThan($locked, $insert, 'Made before the locking read, so no lock is held on the place of a missing row');
        $this->assertFalse($queries[$insert]['nested'], 'Made outside the change’s transaction, so it holds nothing while the change waits');
        $this->assertTrue($queries[$locked]['nested']);
        $this->assertSame(0.5, AiUsage::thisMonth()['reserved']);
    }

    public function test_the_estimate_counts_earlier_answers_sent_back_as_the_sdk_gave_them(): void
    {
        $page = str_repeat('The trading allowance is £1,000 a year. ', 3000); // about 40,000 tokens at three characters each
        $answer = [
            BetaTextBlock::with(citations: null, text: str_repeat('word ', 6000)), // about 10,000 tokens
            BetaWebFetchToolResultBlock::fromArray(['type' => 'web_fetch_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => [
                'type' => 'web_fetch_result', 'url' => 'https://www.gov.uk/guidance/tax-free-allowances-on-property-and-trading-income', 'retrieved_at' => null,
                'content' => ['type' => 'document', 'title' => 'Trading allowance', 'citations' => null, 'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => $page]],
            ]]),
        ];
        $round = fn (array $content) => AiUsage::estimate(['model' => 'claude-opus-5-5', 'maxTokens' => 1000, 'messages' => [
            ['role' => 'user', 'content' => 'Can I use the trading allowance?'],
            ['role' => 'assistant', 'content' => $content],
            ['role' => 'user', 'content' => 'Go on'],
        ]]);

        $asSent = $round($answer);
        $asArrays = $round(array_map(fn ($block) => json_decode(json_encode($block), true), $answer));

        $this->assertEqualsWithDelta($asArrays, $asSent, 0.0001, 'The same as the same answer written out');
        $this->assertGreaterThan(0.02 + (10_000 + 40_000) * 4 / 1_000_000, $asSent, 'The answer’s text and the page read count, not only the new question');
    }

    // ---- through the real client, against a fake HTTP transport ----

    private function client(array $responses, array &$history): AnthropicAiClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new AnthropicAiClient(['transporter' => new Guzzle(['handler' => $stack]), 'maxRetries' => 0]);
    }

    private function ok(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'content' => [['type' => 'text', 'text' => 'Yes.']],
            'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
        ]));
    }

    public function test_a_call_settles_at_its_real_cost_and_a_failed_one_owes_nothing(): void
    {
        $history = [];
        $params = ['maxTokens' => 1000, 'messages' => [['role' => 'user', 'content' => 'Can I claim meals?']]];

        $this->client([$this->ok()], $history)->send($params);
        $usage = AiUsage::thisMonth();
        $this->assertSame([1, 0.008, 0.0], [$usage['calls'], $usage['usd'], (float) $usage['reserved']]);

        $failing = $this->client([new Response(500, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Overloaded']]))], $history);
        try {
            $failing->send($params);
            $this->fail('The API failed');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
        }
        $usage = AiUsage::thisMonth();
        $this->assertSame([1, 0.008, 0.0], [$usage['calls'], $usage['usd'], (float) $usage['reserved']], 'Nothing added, nothing left set aside');
    }

    public function test_a_call_that_could_pass_the_cap_is_never_sent(): void
    {
        Setting::put('ai_monthly_cap_usd', 1);
        $this->spend(0.99);
        $history = [];

        try {
            $this->client([$this->ok()], $history)->send(['maxTokens' => 16000, 'messages' => [['role' => 'user', 'content' => 'Read this PO']]]);
            $this->fail('Up to $0.32 more could pass the $1 cap');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nearly reached', $e->getMessage());
        }
        $this->assertSame([], $history, 'Nothing was sent');
    }

    /**
     * Run $work and return its queries, with a locking read marked "for update" (SQLite has no row locks, so its
     * grammar normally leaves the clause out; MySQL's writes it) and whether each ran inside a transaction of the
     * work's own.
     *
     * @return list<array{sql:string, nested:bool}>
     */
    private function queries(callable $work): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return $value === true ? '/* for update */' : '';
                }
            });
        }
        $base = DB::transactionLevel();
        $seen = [];
        $on = true;
        DB::listen(function (QueryExecuted $q) use (&$seen, &$on, $base) {
            if ($on) {
                $seen[] = ['sql' => str_replace('`', '"', $q->sql), 'nested' => DB::transactionLevel() > $base];
            }
        });
        try {
            $work();
        } finally {
            $on = false;
            $connection->setQueryGrammar($grammar);
        }

        return $seen;
    }
}
