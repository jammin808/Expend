<?php

namespace Tests\Feature;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Telegram\Telegram;
use App\Models\Setting;
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
}
