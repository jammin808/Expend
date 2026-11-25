<?php

namespace Tests\Feature\Quick;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Quick\QuickReadCheck;
use App\Domain\Quick\QuickWorker;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\TodoActions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * The bot and the phone's endpoints at their edges (the QA of quick add): questions without a "?", currencies in
 * words, days that don't exist, the hour after midnight, buttons pressed again after going back, a read that agrees,
 * a reply that's a question, amounts too big to be a cost, uploads over PHP's limits and an Inertia visit to the shell.
 */
class QuickTelegramEdgesTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    private int $sent = 100;

    private FakeAiClient $ai;

    private int $q = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Setting::put('ai_extraction', 'off');
        Setting::put('anthropic_api_key', 'test-key');
        $this->ai = new FakeAiClient(array_fill(0, 20, FakeAiClient::text('An answer from the assistant.')));
        $this->app->instance(AiClient::class, $this->ai);
        $image = UploadedFile::fake()->image('p.jpg', 30, 30);
        $bytes = (string) file_get_contents($image->getRealPath());
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/file/*' => Http::response($bytes),
            'api.telegram.org/*' => function (Request $r) {
                if (str_ends_with($r->url(), '/getFile')) {
                    return Http::response(['ok' => true, 'result' => ['file_path' => 'photos/p.jpg']]);
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => ++$this->sent]]);
            },
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    private function message(array $m, int $id): void
    {
        Telegram::handle(['message' => $m + ['message_id' => $id, 'chat' => ['id' => 42, 'type' => 'private'], 'date' => now()->timestamp]],
            app(DocumentIntake::class), app(Assistant::class));
    }

    /** @return list<array{text:string, rows:array}> */
    private function botSaid(string $method = 'sendMessage'): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/'.$method))
            ->map(fn ($pair) => ['text' => (string) ($pair[0]['text'] ?? ''), 'rows' => $pair[0]['reply_markup']['inline_keyboard'] ?? []])->values()->all();
    }

    private function button(array $rows, string $label): string
    {
        foreach ($rows as $row) {
            foreach ($row as $b) {
                if (($b['text'] ?? '') === $label || str_ends_with((string) ($b['text'] ?? ''), $label)) {
                    return (string) $b['callback_data'];
                }
            }
        }
        $this->fail('No button “'.$label.'” in '.json_encode($rows));
    }

    /** A press; the same press delivered again by Telegram carries the same id. */
    private function press(string $data, int $messageId, ?string $id = null): void
    {
        Telegram::handleButton(['id' => $id ?? 'q'.(++$this->q), 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => 42], 'date' => now()->timestamp,
            'text' => 'offer']], app(TodoActions::class));
    }

    private function lastEdit(): array
    {
        $edits = $this->botSaid('editMessageText');

        return $edits[count($edits) - 1] ?? ['text' => '', 'rows' => []];
    }

    private function answered(): string
    {
        return (string) Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery'))->last()[0]['text'];
    }

    // ---- what a text is ---------------------------------------------------------------------------------------------

    public function test_questions_without_a_question_mark_and_bank_notifications(): void
    {
        foreach (['can I claim parking £12.50', 'Should I keep receipts under £10', 'why was I charged £12.50', 'what is 20% of £1,200', 'tax on £1,200'] as $i => $t) {
            $this->message(['text' => $t], 10 + $i);
        }
        $this->assertSame(0, QuickEntry::query()->count());
        $this->assertCount(5, $this->ai->requests, 'every one reached the assistant');

        $this->message(['text' => 'You spent £12.50 at NCP'], 20);
        $this->message(['text' => 'parking 8'], 21);
        $this->assertSame([['NCP', 1250], ['parking', 800]], QuickEntry::query()->orderBy('id')->get()->map(fn ($e) => [$e->what, (int) $e->amount_minor])->all());
        $this->assertCount(5, $this->ai->requests);
    }

    public function test_a_currency_written_as_a_word_is_that_currency_never_pounds(): void
    {
        ExchangeRate::query()->create(['currency' => 'EUR', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'source' => 'hmrc_monthly', 'units_per_gbp' => '1.2']);
        $this->message(['text' => '15 euros taxi'], 50);
        $this->message(['text' => '20 francs taxi'], 51);
        $this->assertSame([[1500, 'EUR', 'taxi']], QuickEntry::query()->get()->map(fn ($e) => [(int) $e->amount_minor, $e->currency, $e->what])->all());
        $this->assertCount(1, $this->ai->requests, 'a currency it can’t name goes to the assistant');

        $this->press($this->button($this->botSaid()[0]['rows'], 'Cash'), 101);
        $x = ManualExpense::query()->sole();
        $this->assertSame([-1250, -1500, 'EUR'], [(int) $x->amount_pence, (int) $x->original_amount_minor, $x->original_currency]);
    }

    public function test_a_day_that_does_not_exist_is_never_moved_to_another_day(): void
    {
        Carbon::setTestNow('2027-03-10 12:00:00');
        $this->message(['text' => '£12.50 parking 29 Feb'], 120);
        $this->assertSame('2024-02-29', QuickEntry::query()->sole()->spent_on->format('Y-m-d'), 'the last 29 February there was');
        $this->assertStringStartsWith('£12.50 · parking · Thu 29 Feb 2024', $this->botSaid()[0]['text']);

        $this->postJson('/quick/entries', $this->fields(['spent_on' => '2027-02-29']))->assertStatus(422)->assertJson(['message' => 'That date isn’t a date.']);
        $this->postJson('/quick/entries', $this->fields(['spent_on' => '2026-02-30']))->assertStatus(422);
        $this->assertSame(0, ManualExpense::query()->count());
    }

    public function test_a_day_long_ago_or_in_another_tax_year_says_its_year(): void
    {
        $this->message(['text' => '£12.50 parking 30 Nov'], 130);
        $this->assertStringStartsWith('£12.50 · parking · Sun 30 Nov 2025', $this->botSaid()[0]['text']);
    }

    public function test_amounts_too_big_to_be_a_cost_are_refused_in_plain_words(): void
    {
        $this->message(['text' => '£92233720368547758.07 hire'], 143);
        $this->message(['text' => '£10,000,000 hire'], 144);
        $this->assertSame(0, QuickEntry::query()->count());

        foreach (['99999999999999999999', '92233720368547758.07', '10000000'] as $amount) {
            $r = $this->postJson('/quick/entries', $this->fields(['amount' => $amount]));
            $r->assertStatus(422);
            $this->assertContains($r->json('message'), ['That’s too big to add from your phone: add it in Expend.', 'That amount can’t be read.']);
        }
        $this->postJson('/quick/entries', $this->fields(['amount' => '9999999']))->assertCreated();
        $this->assertSame(0, ManualExpense::query()->where('amount_pence', '<', -999_999_900)->count());
    }

    // ---- buttons ----------------------------------------------------------------------------------------------------

    public function test_change_back_and_change_again_works_and_a_press_delivered_twice_is_done_once(): void
    {
        $this->message(['text' => '£20 lunch'], 70);
        $change = $this->button($this->botSaid()[0]['rows'], 'Change…');
        $this->press($change, 101);
        $this->press($this->button($this->lastEdit()['rows'], 'Back'), 101);
        $again = $this->button($this->lastEdit()['rows'], 'Change…');
        $this->assertSame($change, $again);

        $edits = count($this->botSaid('editMessageText'));
        $this->press($again, 101, 'same-press');
        $this->assertSame($edits + 1, count($this->botSaid('editMessageText')), 'Change… works again straight away');
        $this->assertContains('Yesterday (24 Nov)', collect($this->lastEdit()['rows'])->flatten(1)->pluck('text')->all());

        // Telegram delivering that same press again redraws nothing.
        $this->press($again, 101, 'same-press');
        $this->assertSame($edits + 1, count($this->botSaid('editMessageText')));

        // A button that writes is still done once, however often it's pressed.
        $cash = $this->button($this->botSaid()[0]['rows'], 'Cash');
        $this->press($this->button($this->lastEdit()['rows'], 'Back'), 101);
        $cash = $this->button($this->lastEdit()['rows'], 'Cash');
        $this->press($cash, 101);
        $this->press($cash, 101);
        $this->assertSame(1, ManualExpense::query()->count());
    }

    public function test_yesterday_offered_before_midnight_still_means_that_day_when_pressed_after_it(): void
    {
        Carbon::setTestNow('2026-11-25 23:55:00');
        $this->message(['text' => '£12.50 parking'], 150);
        $this->press($this->button($this->botSaid()[0]['rows'], 'Change…'), 101);
        $yesterday = $this->button($this->lastEdit()['rows'], 'Yesterday (24 Nov)');

        Carbon::setTestNow('2026-11-26 00:05:00');
        $this->press($yesterday, 101);
        $this->assertSame('2026-11-24', QuickEntry::query()->sole()->spent_on->format('Y-m-d'));
    }

    public function test_a_read_that_agrees_leaves_a_caption_drafts_buttons_working(): void
    {
        $this->message(['photo' => [['file_id' => 'f1']], 'caption' => '£12.50 parking'], 90);
        $draft = QuickEntry::query()->sole();
        $cash = $this->button($this->botSaid()[0]['rows'], 'Cash');
        $doc = Document::query()->sole();
        $doc->forceFill(['extraction' => ['receipt' => ['amount_minor' => 1250, 'currency' => 'GBP', 'date' => '2026-11-25', 'merchant' => 'NCP']], 'extracted_at' => now()])->save();
        app(QuickReadCheck::class)->afterRead($doc->fresh());
        $this->assertSame('agrees', $draft->fresh()->read_check['status']);

        $this->press($cash, (int) $draft->telegram_message_id);
        $this->assertSame(['added', 1], [$draft->fresh()->status, ManualExpense::query()->count()]);
    }

    // ---- the clock --------------------------------------------------------------------------------------------------

    public function test_after_midnight_last_nights_job_is_picked_as_on_the_phone(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena Awards', 'location' => 'Manchester', 'starts_on' => '2026-10-24', 'ends_on' => '2026-10-24', 'status' => 'booked']);
        Carbon::setTestNow('2026-10-24 23:30:00'); // 00:30 BST on 25 Oct: the load-out after the show
        $this->message(['text' => '£12.50 parking'], 110);
        $this->assertSame($job->id, (int) QuickEntry::query()->sole()->work_job_id);

        Carbon::setTestNow('2026-10-25 07:30:00'); // after 06:00: last night's job no longer counts
        $this->message(['text' => '£3 coffee'], 111);
        $this->assertNull(QuickEntry::query()->orderByDesc('id')->first()->work_job_id);
    }

    // ---- replies ------------------------------------------------------------------------------------------------------

    public function test_a_question_replied_to_an_added_draft_goes_to_the_assistant(): void
    {
        $this->message(['text' => '£12.50 parking'], 40);
        $this->press($this->button($this->botSaid()[0]['rows'], 'Cash'), 101);
        $this->message(['text' => 'is this allowable for a sole trader?', 'reply_to_message' => ['message_id' => 101]], 41);
        $this->assertCount(1, $this->ai->requests);

        // A new amount in reply to it is still told where to change it.
        $this->message(['text' => '£15', 'reply_to_message' => ['message_id' => 101]], 42);
        $this->assertStringStartsWith('That one is added as a cash expense already', collect($this->botSaid())->last()['text']);
    }

    // ---- the phone's endpoints --------------------------------------------------------------------------------------

    public function test_a_post_over_phps_limit_gets_the_phones_own_words(): void
    {
        $post = QuickWorker::bytes((string) ini_get('post_max_size'));
        $big = $this->call('POST', '/quick/entries/'.$this->key().'/photo', ['_method' => 'PUT'], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) ($post + 1)]);
        $big->assertStatus(413)->assertJson(['error' => 'too_big']);
        $this->assertStringStartsWith('Too big to send from your phone (over ', (string) $big->json('message'));
    }

    public function test_an_inertia_visit_to_the_shell_goes_to_the_screen(): void
    {
        $version = app(HandleInertiaRequests::class)->version(request());
        $this->get('/quick/shell', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version, 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/quick');
        $this->get('/quick/shell')->assertOk()->assertHeader('X-Expend-Shell', '1');
    }

    public function test_the_suggestion_names_a_job_by_its_title_only(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena load-in', 'location' => 'Leeds', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);
        $r = $this->getJson('/quick/suggest?what=NCP&amount=12.50&date=2026-11-25')->assertOk();
        $this->assertStringNotContainsString('(job '.$job->id.')', (string) $r->json('reason'));
    }
}
