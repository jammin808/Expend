<?php

namespace App\Domain\Telegram;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Jobs\ReadDocument;
use App\Models\AssistantLog;
use App\Models\Document;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

        return self::api('setWebhook', ['url' => $webhookUrl, 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
    }

    /** Let the bot receive button presses too (bots connected before buttons existed only received messages). */
    public static function allowButtons(): bool
    {
        $secret = Setting::get('telegram_webhook_secret');
        if (! self::token() || ! $secret || Setting::get('telegram_buttons_on')) {
            return (bool) Setting::get('telegram_buttons_on');
        }
        $ok = (bool) (self::api('setWebhook', ['url' => url('/hooks/telegram'), 'secret_token' => $secret, 'allowed_updates' => ['message', 'callback_query']])['ok'] ?? false);
        Setting::put('telegram_buttons_on', $ok);

        return $ok;
    }

    /** Send a message with buttons. Each button is [label, todo action code, id]. */
    public static function sendWithButtons(string|int $chatId, string $text, array $buttons): void
    {
        $row = array_map(fn ($b) => ['text' => $b[0], 'callback_data' => mb_substr('t|'.$b[1].'|'.$b[2], 0, 64)], $buttons);
        if ($row) {
            // A bot paired before buttons existed only gets messages: make sure presses on these will arrive.
            self::allowButtons();
        }
        self::api('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3900), 'disable_web_page_preview' => true]
            + ($row ? ['reply_markup' => ['inline_keyboard' => [$row]]] : []));
    }

    /**
     * The to-do list as a few messages: a summary, then the most urgent items, each with its one-tap buttons.
     *
     * @return int items sent
     */
    public static function sendTodo(string|int $chatId, int $max = 8): int
    {
        $list = TodoList::build(5);
        if ($list['total'] === 0) {
            self::reply($chatId, 'Nothing needs you this week. 👍');

            return 0;
        }
        $summary = collect($list['counts'])->map(fn ($n, $g) => '• '.$n.' '.mb_strtolower(TodoList::GROUPS[$g]))->implode("\n");
        self::reply($chatId, "This week: {$list['total']} ".($list['total'] === 1 ? 'thing needs' : 'things need')." you.\n{$summary}\n\nThe most urgent below. Everything: ".url('/todo'));
        $items = array_slice($list['items'], 0, $max);
        $sent = 0;
        foreach ($items as $item) {
            // In the item's own currency (an invoice in euros, a stay booked in dollars), in hundredths of it whatever
            // the currency, as invoices and bookings are kept. A payment's detail says how it was paid
            // (TodoList::howPaid): the time, account, card, place and the currency it was paid in.
            $amount = isset($item['amount']) && $item['amount'] !== null ? ' ('.Money::format($item['amount'], $item['currency'] ?? 'GBP').')' : '';
            self::sendWithButtons($chatId, $item['title'].$amount.($item['detail'] ? "\n".$item['detail'] : ''),
                array_map(fn ($a) => [$a['label'], $a['code'], $a['id']], $item['actions']));
            $sent++;
        }

        return $sent;
    }

    /** A button press: only from your paired chat, and only the to-do actions. */
    public static function handleButton(array $query, TodoActions $actions): void
    {
        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $paired = (string) Setting::get('telegram_chat_id');
        if (! $paired || ! hash_equals($paired, $chatId)) {
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => 'Not allowed.']);

            return;
        }
        [$tag, $code, $id] = explode('|', (string) ($query['data'] ?? ''), 3) + [null, null, null];
        if ($tag !== 't' || ! in_array($code, TodoActions::CODES, true) || $id === null) {
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => 'Unknown button.']);

            return;
        }
        // Presses wait for the queue (up to a minute), so a second tap on the same button is likely: do it once.
        // The actions themselves also check the item still needs doing, for old buttons on other messages.
        $press = 'telegram_press:'.$chatId.':'.($query['message']['message_id'] ?? 0).':'.$query['data'];
        if (! Cache::add($press, '', now()->addMinutes(10))) {
            // The same answer as the first press, without doing it again.
            $earlier = (string) Cache::get($press);
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => mb_substr($earlier !== '' ? $earlier : 'Already on it.', 0, 190)]);

            return;
        }
        // When the message was sent, so a reminder sent since then makes this press a repeat (and one sent before
        // it, such as last week's, doesn't).
        $offeredAt = is_numeric($query['message']['date'] ?? null) ? Carbon::createFromTimestamp((int) $query['message']['date']) : null;
        try {
            $result = '✓ '.$actions->run($code, $id, $offeredAt);
            Cache::put($press, $result, now()->addMinutes(10));
        } catch (ModelNotFoundException) {
            $result = 'Already dealt with.';
            Cache::put($press, $result, now()->addMinutes(10));
        } catch (Throwable $e) {
            $result = '⚠ '.$e->getMessage();
            Cache::forget($press); // didn't happen: pressing again (once it's sorted out) tries again
        }
        TodoList::forget();
        self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => mb_substr($result, 0, 190)]);
        self::api('editMessageText', [
            'chat_id' => $chatId, 'message_id' => $query['message']['message_id'] ?? 0,
            'text' => mb_substr(($query['message']['text'] ?? '')."\n\n".$result, 0, 4000), 'disable_web_page_preview' => true,
        ]);
    }

    public static function reply(string|int $chatId, string $text): void
    {
        foreach (str_split($text, 3900) as $chunk) {
            self::api('sendMessage', ['chat_id' => $chatId, 'text' => $chunk, 'disable_web_page_preview' => true]);
        }
    }

    public static function handle(array $update, DocumentIntake $intake, Assistant $assistant): void
    {
        if (isset($update['callback_query'])) {
            self::handleButton($update['callback_query'], app(TodoActions::class));

            return;
        }
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

            if ($text === '/todo' || mb_strtolower($text) === 'todo' || mb_strtolower($text) === 'to do') {
                self::sendTodo($chatId);

                return;
            }
            if ($text === '' || $text === '/start' || $text === '/help') {
                self::reply($chatId, 'Send a file or photo to add it, send /todo for what needs you (with buttons), or ask a question, for example: “What time is my call on Saturday?”, “Which invoices are overdue?”, “Where am I staying for the Leeds job?”');

                return;
            }

            $history = AssistantLog::query()->where('channel', 'telegram')->latest()->limit(3)->get()->reverse()
                ->map(fn ($l) => ['question' => $l->question, 'answer' => (string) $l->answer])->values()->all();
            $answer = $assistant->ask($text, 'telegram', $history);
            $reply = $answer['answer'];
            $reply .= self::answerWarnings($answer['unmatched'], $answer['notes'] ?? []);
            self::reply($chatId, $reply);
        } catch (Throwable $e) {
            report($e);
            self::reply($chatId, 'Sorry, that didn’t work: '.$e->getMessage());
        }
    }

    /** Lines under an assistant answer: figures and links not found anywhere, then things to check before relying on it. */
    public static function answerWarnings(array $unmatched, array $notes): string
    {
        $out = '';
        if ($unmatched) {
            $out .= "\n\n⚠ Not found in your records: ".implode(', ', $unmatched).'. Treat these as unverified.';
        }
        if ($notes) {
            $out .= "\n\nℹ Check before relying on: ".implode('; ', $notes).'.';
        }

        return $out;
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
            // A PO's amounts are read in hundredths of its currency (pence, cents), whatever the currency: format()'s.
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
