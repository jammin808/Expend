<?php

namespace App\Domain\Telegram;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\AiConfig;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\OpportunityRules;
use App\Domain\Todo\ChangedSince;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Jobs\ReadDocument;
use App\Models\AssistantLog;
use App\Models\Document;
use App\Models\Finding;
use App\Models\Opportunity;
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
 * A message you forward to it from someone else (a crew booker's "are you free on the 12th?") is kept as a document
 * and read for work it offers, like an email: it's never taken as your instruction.
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

    /** Telegram's limit on a button's data, in bytes. */
    public const CALLBACK_BYTES = 64;

    /**
     * Send a message with buttons. Each button is [label, todo action code, id]; $more is a second row (a finding's
     * Snooze a week and Not relevant, under its own actions).
     */
    public static function sendWithButtons(string|int $chatId, string $text, array $buttons, array $more = []): bool
    {
        $rows = array_values(array_filter([self::buttons($buttons), self::buttons($more)]));
        if ($rows) {
            // A bot paired before buttons existed only gets messages: make sure presses on these will arrive.
            self::allowButtons();
        }

        return (bool) (self::api('sendMessage', ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 3900), 'disable_web_page_preview' => true]
            + ($rows ? ['reply_markup' => ['inline_keyboard' => $rows]] : []))['ok'] ?? false);
    }

    /**
     * A message from the nightly cycle to your paired chat: the morning report, or an alert (with a finding's buttons
     * when given, as sendWithButtons() takes them). Nothing is sent when Telegram isn't paired.
     *
     * @return bool whether Telegram took it
     */
    public static function notify(string $text, array $buttons = [], array $more = []): bool
    {
        $chat = Setting::get('telegram_chat_id');
        if (! $chat || ! self::token()) {
            return false;
        }
        try {
            return self::sendWithButtons($chat, $text, $buttons, $more);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * A row of buttons, each [label, todo action code, id], as Telegram takes them: data t|code|id|fp, within 64 bytes
     * (callbackData()). A button whose data wouldn't fit is left out rather than cut: a cut id would act on something else.
     */
    private static function buttons(array $buttons): array
    {
        $row = [];
        foreach ($buttons as $b) {
            $data = self::callbackData($b[1], (string) $b[2]);
            if ($data === null) {
                report(new \LengthException("A Telegram button for {$b[1]} doesn’t fit in ".self::CALLBACK_BYTES.' bytes, so it was left out.'));

                continue;
            }
            $row[] = ['text' => $b[0], 'callback_data' => $data];
        }

        return $row;
    }

    /**
     * A button's data: t|code|id, and for an action that decides something, |fp: the fingerprint of the state it's offered
     * on (Todo\Fingerprints), so a press on something that has changed since is refused. Null when it wouldn't fit in
     * Telegram's 64 bytes.
     */
    public static function callbackData(string $code, string $id): ?string
    {
        $fp = Fingerprints::of($code, $id);
        $data = 't|'.$code.'|'.$id.($fp !== null ? '|'.$fp : '');

        return strlen($data) <= self::CALLBACK_BYTES ? $data : null;
    }

    /**
     * The buttons that hide a finding from the chat: Snooze a week, and Not relevant (which asks why: reasonButtons()),
     * by the finding's id (fnd_snooze, fnd_dismiss). None for an item that isn't a finding; no Not relevant for one
     * that can only be snoozed.
     *
     * @return list<array{0:string, 1:string, 2:string}>
     */
    public static function findingButtons(array $item, ?int $findingId): array
    {
        if ($findingId === null) {
            return [];
        }

        return array_values(array_filter([
            ['Snooze a week', 'fnd_snooze', $findingId.':later'],
            ($item['snooze_only'] ?? false) ? null : ['Not relevant…', 'fnd_dismiss', (string) $findingId],
        ]));
    }

    /** The reasons offered after Not relevant…, two to a row. */
    public static function reasonButtons(Finding $finding): array
    {
        $buttons = [];
        foreach (Dismissals::reasonsFor($finding->code) as $reason => $label) {
            if (($data = self::callbackData('fnd_dismiss', $finding->id.':'.$reason)) !== null) {
                $buttons[] = ['text' => $label, 'callback_data' => $data];
            }
        }

        return array_chunk($buttons, 2);
    }

    /**
     * The to-do list as a few messages: a summary, then the most urgent items, each with its one-tap buttons.
     *
     * @return int items sent
     */
    public static function sendTodo(string|int $chatId, int $max = 8): int
    {
        // The findings brought up to date with the list first (when anything it's made from has changed since the
        // last run: a snooze that's ended, a new statement), so a finding new since then has its buttons and each
        // finding's recorded state agrees with what's sent.
        FindingsRegistry::refresh();
        $list = TodoList::build(5);
        if ($list['total'] === 0) {
            self::reply($chatId, 'Nothing needs you this week. 👍');

            return 0;
        }
        $summary = collect($list['counts'])->map(fn ($n, $g) => '• '.$n.' '.mb_strtolower(TodoList::GROUPS[$g]))->implode("\n");
        self::reply($chatId, "This week: {$list['total']} ".($list['total'] === 1 ? 'thing needs' : 'things need')." you.\n{$summary}\n\nThe most urgent below. Everything: ".url('/todo'));
        $items = array_slice($list['items'], 0, $max);
        // Findings (not routine items) can be snoozed or set aside from the chat, by the finding's id.
        $findings = Finding::query()->whereIn('key', array_column($items, 'key') ?: [''])->pluck('id', 'key');
        $sent = 0;
        foreach ($items as $item) {
            // In the item's own currency (an invoice in euros, a stay booked in dollars), in hundredths of it whatever
            // the currency, as invoices and bookings are kept. A payment's detail says how it was paid
            // (TodoList::howPaid): the time, account, card, place and the currency it was paid in.
            $amount = isset($item['amount']) && $item['amount'] !== null ? ' ('.Money::format($item['amount'], $item['currency'] ?? 'GBP').')' : '';
            self::sendWithButtons($chatId, $item['title'].$amount.($item['detail'] ? "\n".$item['detail'] : ''),
                array_map(fn ($a) => [$a['label'], $a['code'], $a['id']], $item['actions']), self::findingButtons($item, $findings[$item['key']] ?? null));
            $sent++;
        }

        return $sent;
    }

    /**
     * The alarm when the nightly check finds the audit log altered (ledger:verify-audit), sent at once to your paired
     * chat. It says only what was found and where to look: no amounts, names or account details.
     *
     * @param  array{checked:int, broken_at:int|null}  $result  AuditLog::verify()'s
     * @return bool whether Telegram took it
     */
    public static function auditAlarm(array $result): bool
    {
        $chat = Setting::get('telegram_chat_id');
        if (! $chat || ! self::token()) {
            return false;
        }
        try {
            $sent = self::api('sendMessage', ['chat_id' => $chat, 'disable_web_page_preview' => true,
                'text' => '⚠ Expend’s nightly check found the audit log altered: event '.($result['broken_at'] ?? '?').' doesn’t follow on from the '.$result['checked']
                    .' before it, so something changed the records outside the app. Nothing was changed because of it. Compare the records with a backup before you lock or file a year: '.url('/todo')]);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return (bool) ($sent['ok'] ?? false);
    }

    /**
     * A button press: only from your paired chat, and only the to-do actions. Its data is t|code|id|fp: a button that
     * decides something carries the fingerprint of what it was offered on, and is refused when that has changed since
     * ("This changed since: open it in Expend"). An old button without one (t|code|id) still works for what decides
     * nothing (a re-check, or "Not relevant…" asking why); for anything else it's refused, with the same advice.
     */
    public static function handleButton(array $query, TodoActions $actions): void
    {
        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $paired = (string) Setting::get('telegram_chat_id');
        if (! $paired || ! hash_equals($paired, $chatId)) {
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => 'Not allowed.']);

            return;
        }
        [$tag, $code, $id, $fp] = explode('|', (string) ($query['data'] ?? ''), 4) + [null, null, null, null];
        if ($tag !== 't' || ! in_array($code, TodoActions::CODES, true) || $id === null || $id === '' || ($fp !== null && ! preg_match('/^[0-9a-f]{6}$/', $fp))) {
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => 'Unknown button.']);

            return;
        }
        if ($fp === null && Fingerprints::decides($code, $id)) {
            // From a message sent before buttons were bound to what they showed: it may be about something changed since.
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => self::changedText()]);

            return;
        }
        // Not relevant… asks why first: the reasons replace the buttons, and the one you press does it.
        if ($code === 'fnd_dismiss' && ctype_digit($id)) {
            // Gone or set aside now, as the list reads it (not the state recorded at the last findings run).
            $finding = Finding::query()->find((int) $id);
            if (! $finding || ! TodoActions::stillShown($finding, false)) {
                self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => 'Already dealt with.']);

                return;
            }
            self::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => 'Why isn’t it relevant?']);
            self::api('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $query['message']['message_id'] ?? 0,
                'reply_markup' => ['inline_keyboard' => self::reasonButtons($finding)]]);

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
            // Your tap, from Telegram: recorded as yours, with where it came from.
            $result = '✓ '.AuditContext::with('you', fn () => $actions->run($code, $id, $offeredAt, $fp), 'telegram');
            Cache::put($press, $result, now()->addMinutes(10));
        } catch (ModelNotFoundException) {
            $result = 'Already dealt with.';
            Cache::put($press, $result, now()->addMinutes(10));
        } catch (ChangedSince) {
            // Nothing was done: the same answer to a second press, which would be refused the same way.
            $result = '⚠ '.self::changedText();
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

    /** What a press on something changed since it was sent (or on an old button) is told: nothing was done. */
    public static function changedText(): string
    {
        return 'This changed since: open it in Expend. Nothing was done. '.url('/todo');
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

            if (self::forwarded($message)) {
                self::reply($chatId, self::takeForwarded($message, $text, $intake));

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

    /** A message forwarded from someone else (their words, not yours). */
    public static function forwarded(array $message): bool
    {
        return isset($message['forward_origin']) || isset($message['forward_from']) || isset($message['forward_from_chat'])
            || isset($message['forward_sender_name']) || isset($message['forward_date']);
    }

    /**
     * Keep a forwarded message as a document (from whoever wrote it) and look for work it offers: read with AI when
     * that's on (the reply comes when it's read), otherwise spotted by its words. Only ever proposed, never a job.
     */
    private static function takeForwarded(array $message, string $text, DocumentIntake $intake): string
    {
        if ($text === '') {
            return 'There’s no text in that forwarded message to keep.';
        }
        $origin = $message['forward_origin'] ?? [];
        $name = match ($origin['type'] ?? null) {
            'user' => trim(($origin['sender_user']['first_name'] ?? '').' '.($origin['sender_user']['last_name'] ?? '')),
            'hidden_user' => (string) ($origin['sender_user_name'] ?? ''),
            'chat', 'channel' => (string) ($origin['chat']['title'] ?? $origin['sender_chat']['title'] ?? ''),
            default => trim(($message['forward_from']['first_name'] ?? '').' '.($message['forward_from']['last_name'] ?? '')) ?: (string) ($message['forward_sender_name'] ?? ''),
        };
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 120) : 'someone';
        $when = is_numeric($origin['date'] ?? $message['forward_date'] ?? null) ? Carbon::createFromTimestamp((int) ($origin['date'] ?? $message['forward_date'])) : now();
        // Headed like an email, so the date it was sent isn't read as a day of the work.
        $document = $intake->store("From: {$name} (forwarded in Telegram)\nDate: ".$when->format('j M Y H:i')."\n\n".$text,
            'telegram-forward-'.$when->format('Ymd-His').'.txt', 'text/plain', 'telegram',
            ['from' => $name, 'subject' => 'Forwarded in Telegram from '.$name, 'received_at' => $when]);

        if (Setting::get('ai_extraction') !== 'off' && AiConfig::ready()) {
            ReadDocument::dispatch($document->id, (string) ($message['chat']['id'] ?? ''));

            return 'Kept the message from '.$name.'. Reading it for any work it offers… Nothing is added as a job unless you say so.';
        }
        try {
            $o = app(OpportunityRules::class)->spot($document->fresh());
        } catch (Throwable $e) {
            report($e);
            $o = null;
        }

        return $o
            ? 'Kept the message from '.$name.'. It looks like work: '.$o->title.' ('.mb_strtolower(Opportunity::STATUSES[$o->status] ?? $o->status).'). Check it, and make it a job if you take it: '.url('/opportunities/'.$o->id)
            : 'Kept the message from '.$name.'. Nothing in it looks like work offered: '.url('/documents/'.$document->id);
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
        if ($document->status === ReadDocument::WAITING) {
            return "I saved {$document->original_name}. It’s waiting to be read: this month’s AI budget for reading documents is used up for now. "
                .'Expend reads it on its own once there’s room (after the 20th, or next month), or press Read with AI on it: '.url('/documents/'.$document->id);
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
        $top = $document->parent_id ? ($document->parent ?? $document) : $document;
        if ($o = Opportunity::query()->where('fingerprint', sha1('document:'.$top->id))->first()) {
            $lines[] = 'Possible work: '.$o->title.' ('.mb_strtolower(Opportunity::STATUSES[$o->status] ?? $o->status).'). It isn’t a job until you say so: '.url('/opportunities/'.$o->id);
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
