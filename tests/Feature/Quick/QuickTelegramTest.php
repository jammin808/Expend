<?php

namespace Tests\Feature\Quick;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\TodoActions;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Texting a cost to the bot ("£12.50 parking Leeds"): one draft per message, buttons that decide in the chat (each
 * bound to what it was shown on), Change… redrawing fresh buttons, a reply that changes the draft, a photo with a
 * caption or just before the amount, and questions still going to the assistant.
 */
class QuickTelegramTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    private int $sent = 100;

    private FakeAiClient $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Setting::put('ai_extraction', 'off');
        Setting::put('anthropic_api_key', 'test-key');
        $this->ai = new FakeAiClient([FakeAiClient::text('You spent £40 on parking this month.')]);
        $this->app->instance(AiClient::class, $this->ai);
        $image = UploadedFile::fake()->image('p.jpg', 30, 30);
        $bytes = (string) file_get_contents($image->getRealPath());
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

    private function message(array $m, int $id, int $chat = 42): void
    {
        Telegram::handle(['message' => $m + ['message_id' => $id, 'chat' => ['id' => $chat, 'type' => 'private'], 'date' => now()->timestamp]],
            app(DocumentIntake::class), app(Assistant::class));
    }

    /** @return list<array{text:string, rows:list<list<array<string, string>>>, url:string}> what the bot sent or edited */
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
        $this->fail('No button “'.$label.'”');
    }

    private function press(string $data, int $messageId, string $text = ''): void
    {
        static $n = 0;
        Telegram::handleButton(['id' => 'q'.(++$n), 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => $text]],
            app(TodoActions::class));
    }

    /** @return array{text:string, rows:list<list<array<string, string>>>} the bot's last edit of a message */
    private function lastEdit(): array
    {
        $edits = $this->botSaid('editMessageText');

        return $edits[count($edits) - 1] ?? ['text' => '', 'rows' => []];
    }

    private function answered(): string
    {
        return (string) Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery'))->last()[0]['text'];
    }

    public function test_a_cost_texted_makes_one_draft_with_buttons_that_add_it_once(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);

        $this->message(['text' => '£12.50 parking Leeds'], 7);

        $draft = QuickEntry::query()->sole();
        $this->assertSame(['tg:42:7', 'draft', 1250, 'parking Leeds', $job->id, 'words', $this->cat('travel_parking'), 101],
            [$draft->client_key, $draft->status, $draft->amount_minor, $draft->what, (int) $draft->work_job_id, $draft->job_source, (int) $draft->category_id, $draft->telegram_message_id]);
        $offer = $this->botSaid()[0];
        $this->assertSame("£12.50 · parking Leeds · today, 25 Nov\nJob: Leeds Arena Awards (on today) · Parking, tolls and road charges\nHow did you pay?", $offer['text']);
        $this->assertSame(['Business card', 'Personal card', 'Cash'], array_column($offer['rows'][0], 'text'));
        $cash = $this->button($offer['rows'], 'Cash');
        $this->assertMatchesRegularExpression('/^t\|qx\|'.$draft->id.':cash\|[0-9a-f]{6}$/', $cash);
        $this->assertSame(url('/go?to=/quick/entries/'.$draft->id), $offer['rows'][1][2]['url']);

        $this->press($cash, 101, $offer['text']);
        $x = ManualExpense::query()->sole();
        $this->assertSame(['cash', -1250, 'parking Leeds'], [$x->paid_with, $x->amount_pence, $x->description]);
        $this->assertStringContainsString('✓ Added as a cash expense: £12.50 parking Leeds, Leeds Arena Awards', $this->lastEdit()['text']);

        $this->press($this->button($offer['rows'], 'Personal card'), 101);
        $this->assertSame('Already added.', $this->answered());
        $this->assertSame(1, ManualExpense::query()->count());

        $this->message(['text' => '£12.50 parking Leeds'], 7); // the same update again
        $this->assertSame(1, QuickEntry::query()->count());
        $this->assertCount(1, $this->botSaid());
        $this->assertSame([], $this->ai->requests, 'Never the assistant');
    }

    public function test_a_question_still_goes_to_the_assistant(): void
    {
        $this->message(['text' => 'How much did I spend on parking?'], 8);

        $this->assertSame(0, QuickEntry::query()->count());
        $this->assertCount(1, $this->ai->requests);
    }

    public function test_a_reply_changes_the_draft_and_an_old_button_is_refused_as_changed(): void
    {
        $this->message(['text' => '£12.50 parking'], 9);
        $offer = $this->botSaid()[0];
        $cash = $this->button($offer['rows'], 'Cash');

        $this->message(['text' => '£15', 'reply_to_message' => ['message_id' => 101]], 10);
        $this->assertSame(1500, QuickEntry::query()->sole()->amount_minor);

        $this->press($cash, 101);
        $this->assertStringContainsString('This changed since', $this->answered());
        $this->assertStringStartsWith("This changed since: here it is now.\n£15.00 · parking", $this->lastEdit()['text']);
        $this->assertSame([0, 'draft'], [ManualExpense::query()->count(), QuickEntry::query()->sole()->status]);

        $fresh = $this->lastEdit();
        $this->press($this->button($fresh['rows'], 'Cash'), 101);
        $this->assertSame(-1500, ManualExpense::query()->sole()->amount_pence);
    }

    public function test_change_shows_category_job_and_day_buttons_that_redraw_with_fresh_buttons(): void
    {
        $this->message(['text' => '£20 lunch'], 11);
        $offer = $this->botSaid()[0];

        $this->press($this->button($offer['rows'], 'Change…'), 101);
        $edit = $this->lastEdit();
        $labels = collect($edit['rows'])->flatten(1)->pluck('text')->map(fn ($l) => preg_replace('/^✓ /u', '', (string) $l))->all();
        $this->assertContains('Parking', $labels);
        $this->assertContains('No job', $labels);
        $this->assertContains('Yesterday (24 Nov)', $labels, 'the button says the day it means');

        $this->press($this->button($edit['rows'], 'Yesterday (24 Nov)'), 101);
        $this->assertSame('2026-11-24', QuickEntry::query()->sole()->spent_on->format('Y-m-d'));
        $main = $this->lastEdit();
        $this->press($this->button($main['rows'], 'Change…'), 101);
        $edit = $this->lastEdit();
        $this->press($this->button($edit['rows'], 'Food'), 101);
        $this->assertSame($this->cat('travel_subsistence'), (int) QuickEntry::query()->sole()->category_id);

        $main = $this->lastEdit();
        $this->press($this->button($main['rows'], 'Personal card'), 101);
        $x = ManualExpense::query()->sole();
        $this->assertSame(['personal_card', '2026-11-24'], [$x->paid_with, $x->spent_on->format('Y-m-d')]);
    }

    public function test_a_photo_with_a_caption_is_a_draft_holding_it(): void
    {
        $this->message(['photo' => [['file_id' => 'f1']], 'caption' => '£4.20 coffee'], 12);

        $draft = QuickEntry::query()->sole();
        $this->assertSame([420, 'coffee', 'draft'], [$draft->amount_minor, $draft->what, $draft->status]);
        $doc = Document::query()->sole();
        $this->assertSame([$doc->id, 'receipt'], [(int) $draft->document_id, $doc->kind]);
    }

    public function test_an_amount_just_after_a_photo_goes_with_it_and_not_that_photo_takes_it_off(): void
    {
        $this->message(['photo' => [['file_id' => 'f2']]], 13);
        $this->assertSame('Saved. If it’s a receipt, send the amount and what it was, for example £12.50 parking.', $this->botSaid()[0]['text']);
        $doc = Document::query()->sole();

        $this->travel(5)->minutes();
        $this->message(['text' => '£12.50 parking'], 14);

        $draft = QuickEntry::query()->sole();
        $this->assertSame($doc->id, (int) $draft->document_id);
        $offer = $this->botSaid()[1];
        $this->assertStringStartsWith('Added to the photo you sent at 14:05.', $offer['text']);

        $this->press($this->button($offer['rows'], 'Not that photo'), $draft->telegram_message_id);
        $this->assertNull($draft->fresh()->document_id);
    }

    public function test_another_chat_is_ignored(): void
    {
        $this->message(['text' => '£12.50 parking'], 15, 99);

        $this->assertSame(0, QuickEntry::query()->count());
        $this->assertSame([], $this->botSaid());
    }

    public function test_a_day_in_a_locked_year_is_kept_not_added(): void
    {
        TaxYear::track('2025-26')->update(['locked_at' => now()]);
        $this->message(['text' => '£12.50 parking 20 Mar'], 16);
        $offer = $this->botSaid()[0];
        $this->assertStringContainsString('2025-26 is locked', $offer['text']);

        $this->press($this->button($offer['rows'], 'Cash'), 101);

        $this->assertStringContainsString('Not added: 2025-26 is locked. Unlock it in Expend first', $this->lastEdit()['text']);
        $this->assertSame([0, 'held'], [ManualExpense::query()->count(), QuickEntry::query()->sole()->status]);
    }

    public function test_the_longest_buttons_fit_in_telegrams_64_bytes(): void
    {
        $e = QuickEntry::query()->create(['client_key' => $this->key(), 'status' => 'added', 'channel' => 'phone', 'paid_with' => 'cash', 'amount_minor' => 1250, 'currency' => 'GBP',
            'spent_on' => '2026-11-25']);
        $e->forceFill(['id' => 9999999])->save();
        foreach (['9999999:attach:99999999', '9999999:not_card:99999999,99999999,99999999', '9999999:same:quick:9999999', '9999999:other_payment:99999999',
            '9999999:use_read:currency', '9999999:not_that_photo'] as $id) {
            $data = Telegram::callbackData('qx', $id);
            $this->assertNotNull($data, $id);
            $this->assertLessThanOrEqual(Telegram::CALLBACK_BYTES, strlen($data), $data);
        }
    }

    public function test_the_go_page_only_goes_to_an_entry_or_to_do(): void
    {
        $this->get('/go?to=/quick/entries/5')->assertOk()->assertSee('location.replace("/quick/entries/5")', false);
        $this->get('/go?to=/todo')->assertOk()->assertSee('location.replace("/todo")', false);
        $this->get('/go?to=https://example.test/x')->assertOk()->assertSee('location.replace("/quick")', false);
        $this->get('/go?to=/settings/profile')->assertOk()->assertSee('location.replace("/quick")', false);
    }
}
