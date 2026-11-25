<?php

namespace Tests\Feature;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\TodoActions;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $chatId, string $text): array
    {
        return ['message' => ['chat' => ['id' => $chatId, 'type' => 'private'], 'text' => $text]];
    }

    public function test_the_webhook_rejects_requests_without_the_secret(): void
    {
        Setting::put('telegram_webhook_secret', 'right-secret');
        $this->postJson('/hooks/telegram', $this->message('1', 'hi'))->assertForbidden();
        $this->postJson('/hooks/telegram', $this->message('1', 'hi'), ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
        $this->postJson('/hooks/telegram', $this->message('1', 'hi'), ['X-Telegram-Bot-Api-Secret-Token' => 'right-secret'])->assertOk();
    }

    public function test_only_the_chat_that_sends_the_pairing_code_is_accepted(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_pairing_code', 'ABCD1234');
        $intake = app(DocumentIntake::class);
        $assistant = app(Assistant::class);

        Telegram::handle($this->message('555', '/start wrong'), $intake, $assistant);
        $this->assertNull(Setting::get('telegram_chat_id'));

        Telegram::handle($this->message('777', '/start abcd1234'), $intake, $assistant);
        $this->assertSame('777', Setting::get('telegram_chat_id'));
        $this->assertNull(Setting::get('telegram_pairing_code'));

        // Someone else can't take over, and gets no reply at all.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Telegram::handle($this->message('555', 'What are my invoices?'), $intake, $assistant);
        Http::assertNothingSent();
    }

    public function test_a_token_telegram_refuses_is_not_kept(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)
            ->push(['ok' => true, 'result' => true])]);

        $this->post('/settings/integrations/telegram', ['token' => 'not a token'])->assertSessionHas('error');
        $this->post('/settings/integrations/telegram', ['token' => '123456789:AAHwrongwrongwrongwrongwrongwrong12'])->assertSessionHas('error');
        $this->assertNull(Setting::get('telegram_bot_token'));

        $pasted = "Done! Use this token to access the HTTP API:\n123456789:AAHrightrightrightrightrightright99\nKeep your token secure.";
        $this->post('/settings/integrations/telegram', ['token' => $pasted])->assertSessionHas('success');
        $this->assertSame('123456789:AAHrightrightrightrightrightright99', Setting::get('telegram_bot_token'));
        $this->assertNotNull(Setting::get('telegram_pairing_code'));
    }

    public function test_link_buttons_go_only_to_this_site_or_gmail_and_link_actions_become_links(): void
    {
        // S10: a to-do item's link (TodoList::link) is a link button, never a press; a link anywhere else is left out.
        $buttons = Telegram::keyboard([[
            ['url' => url('/invoices/3'), 'text' => 'Check the invoice'],
            ['url' => Telegram::GMAIL.'mail/u/0/#drafts', 'text' => 'Open in Gmail'],
            ['url' => 'https://example.test/elsewhere', 'text' => 'Somewhere else'],
        ]]);
        $this->assertSame([[['text' => 'Check the invoice', 'url' => url('/invoices/3')], ['text' => 'Open in Gmail', 'url' => Telegram::GMAIL.'mail/u/0/#drafts']]], $buttons);
        $this->assertSame([['url' => url('/transactions?q=ACME'), 'text' => 'I paid it back'], ['Keep £100', 'inv_over_keep', '4']],
            Telegram::actionButtons([['code' => 'link', 'id' => '', 'label' => 'I paid it back', 'style' => 'ghost', 'href' => '/transactions?q=ACME'],
                ['code' => 'inv_over_keep', 'id' => '4', 'label' => 'Keep £100', 'style' => 'default']]));
    }

    public function test_a_press_that_did_something_shows_its_follow_up_and_one_that_failed_drops_the_buttons(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'starts_on' => '2026-06-08', 'ends_on' => '2026-06-08', 'status' => 'booked']);

        Telegram::handleButton(['id' => 'q1', 'data' => Telegram::callbackData('job_not_yet', (string) $job->id),
            'message' => ['message_id' => 7, 'date' => now()->getTimestamp(), 'chat' => ['id' => '42'], 'text' => 'Is it done?']], app(TodoActions::class));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], '✓ OK, I’ll ask again')
            && collect($r['reply_markup']['inline_keyboard'])->flatten(1)->contains(fn ($b) => $b['text'] === 'Undo' && $b['callback_data'] === Telegram::callbackData('todo_undo', 'job_done:'.$job->id)));

        // A press refused (the job was marked done since): the message says so, and no follow-up is offered.
        $job->update(['status' => 'done']);
        Telegram::handleButton(['id' => 'q2', 'data' => 't|job_not_yet|'.$job->id.'|abcdef',
            'message' => ['message_id' => 8, 'date' => now()->getTimestamp(), 'chat' => ['id' => '42'], 'text' => 'Is it done?']], app(TodoActions::class));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'editMessageText') && $r['message_id'] === 8 && ! isset($r['reply_markup']));
    }
}
