<?php

namespace App\Domain\Telegram;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Jobs\ReadDocument;
use App\Models\AssistantLog;
use App\Models\Document;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * A private Telegram bot: send it files or photos and they become documents; ask it questions and the
 * assistant answers from your records. Only the one chat you pair with a code from Settings is accepted.
 */
final class Telegram
{
    public static function token(): ?string
    {
        return Setting::get('telegram_bot_token');
    }

    public static function api(string $method, array $params = []): array
    {
        $token = self::token();
        if (! $token) {
            return ['ok' => false, 'description' => 'No bot token'];
        }

        return Http::timeout(20)->asJson()->post("https://api.telegram.org/bot{$token}/{$method}", $params)->json() ?? ['ok' => false];
    }

    /** Point Telegram at this site and require a secret header on every delivery. */
    public static function connect(string $webhookUrl): array
    {
        $secret = Str::random(48);
        Setting::put('telegram_webhook_secret', $secret);
        Setting::put('telegram_pairing_code', strtoupper(Str::random(8)));

        return self::api('setWebhook', ['url' => $webhookUrl, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
    }

    public static function reply(string|int $chatId, string $text): void
    {
        foreach (str_split($text, 3900) as $chunk) {
            self::api('sendMessage', ['chat_id' => $chatId, 'text' => $chunk, 'disable_web_page_preview' => true]);
        }
    }

    public static function handle(array $update, DocumentIntake $intake, Assistant $assistant): void
    {
        $message = $update['message'] ?? null;
        if (! $message) {
            return;
        }
        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $paired = (string) Setting::get('telegram_chat_id');

        if (! $paired) {
            $code = Setting::get('telegram_pairing_code');
            if ($code && preg_match('/^\/start\s+(\S+)/', $text, $m) && hash_equals($code, strtoupper($m[1])) && ($message['chat']['type'] ?? '') === 'private') {
                Setting::put('telegram_chat_id', $chatId);
                Setting::put('telegram_pairing_code', null);
                self::reply($chatId, 'Paired. Send me POs, job invites, production packs, receipts or photos, or ask me anything about your jobs and money.');
            }

            return;
        }
        if (! hash_equals($paired, $chatId)) {
            return; // ignore everyone else, silently
        }

        try {
            if ($file = self::fileOf($message)) {
                $info = self::api('getFile', ['file_id' => $file['id']]);
                $path = $info['result']['file_path'] ?? null;
                if (! $path) {
                    self::reply($chatId, 'I couldn’t download that file (Telegram bots can only fetch files up to 20 MB).');

                    return;
                }
                $contents = Http::timeout(60)->get('https://api.telegram.org/file/bot'.self::token().'/'.$path)->body();
                $document = $intake->store($contents, $file['name'] ?? basename($path), $file['mime'] ?? null, 'telegram', ['subject' => $text ?: null]);

                if (Setting::get('ai_extraction') !== 'off' && AiConfig::ready()) {
                    self::reply($chatId, "Got {$document->original_name}. Reading it now…");
                    ReadDocument::dispatch($document->id, $chatId);
                } else {
                    self::reply($chatId, "Saved {$document->original_name}. Reading documents with AI is switched off, so open it in the app to add details.");
                }

                return;
            }

            if ($text === '' || $text === '/start' || $text === '/help') {
                self::reply($chatId, 'Send a file or photo to add it, or ask a question, for example: “What time is my call on Saturday?”, “Which invoices are overdue?”, “Where am I staying for the Leeds job?”');

                return;
            }

            $history = AssistantLog::query()->where('channel', 'telegram')->latest()->limit(3)->get()->reverse()
                ->map(fn ($l) => ['question' => $l->question, 'answer' => (string) $l->answer])->values()->all();
            $answer = $assistant->ask($text, 'telegram', $history);
            $reply = $answer['answer'];
            if ($answer['unmatched']) {
                $reply .= "\n\n⚠ Not found in your records: ".implode(', ', $answer['unmatched']).'. Treat these as unverified.';
            }
            self::reply($chatId, $reply);
        } catch (Throwable $e) {
            report($e);
            self::reply($chatId, 'Sorry, that didn’t work: '.$e->getMessage());
        }
    }

    public static function documentSummary(Document $document): string
    {
        if ($document->status === 'failed') {
            return "I saved {$document->original_name} but couldn’t read it: {$document->error}";
        }

        $lines = [($document->summary ?: "Read {$document->original_name}.")];
        if ($document->job) {
            $lines[] = 'Linked to: '.$document->job->title.' ('.$document->job->starts_on->format('j M').').';
        } elseif ($new = $document->extraction['new_job'] ?? null) {
            $lines[] = 'Looks like a new job: '.$new['title'].($new['starts_on'] ? ' on '.$new['starts_on'] : '').'. Create it in the app.';
        }
        foreach ($document->facts->take(8) as $fact) {
            $lines[] = '• '.$fact->label.': '.$fact->value.($fact->verified ? '' : ' (unverified)');
        }
        if ($po = $document->extraction['po'] ?? null) {
            $lines[] = 'PO '.($po['po_number'] ?? '').': '.count($po['lines']).' lines, '.Money::format($po['lines_total_minor'], $po['currency'] ?? 'GBP')
                .($po['lines_match_total'] === false ? ' — doesn’t match the stated total, check it' : '').'.';
        }
        $lines[] = url('/documents/'.$document->id);

        return implode("\n", $lines);
    }

    /** @return array{id:string, name:?string, mime:?string}|null */
    private static function fileOf(array $message): ?array
    {
        if (isset($message['document'])) {
            return ['id' => $message['document']['file_id'], 'name' => $message['document']['file_name'] ?? null, 'mime' => $message['document']['mime_type'] ?? null];
        }
        if (! empty($message['photo'])) {
            $largest = end($message['photo']);

            return ['id' => $largest['file_id'], 'name' => 'photo-'.now()->format('Ymd-His').'.jpg', 'mime' => 'image/jpeg'];
        }

        return null;
    }
}
