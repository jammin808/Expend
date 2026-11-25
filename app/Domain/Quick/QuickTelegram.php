<?php

namespace App\Domain\Quick;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\ReceiptMatch;
use App\Domain\Ledger\ManualExpenses;
use App\Domain\Matching\Categoriser;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\ChangedSince;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Category;
use App\Models\Document;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Expenses texted to the Telegram bot ("£12.50 parking Leeds"), or sent as a photo with such a caption: a draft
 * (a quick entry with status draft, keyed tg:<chat>:<message id>, so an update delivered twice makes one) and one
 * message with buttons that decide in the chat: how you paid (or Add it, when the text said), Change… (category, job
 * and day, redrawn with fresh buttons after each press), Not an expense, and Open in Expend. Nothing is added until a
 * button is pressed.
 *
 * Every button is the to-do action qx ("<entry>:<verb>"), bound to what it was shown on (Todo\Fingerprints): a press
 * after the draft changed is refused. A reply to the bot's message changes that draft (a new amount); an amount sent
 * within half an hour of a photo with no caption goes with that photo ("Not that photo" takes it off).
 */
final class QuickTelegram
{
    /** An amount sent this long after a photo with no caption goes with the photo. */
    public const CLAIM_MINUTES = 30;

    /** Buttons that only redraw the draft or set one of its values: safe to press again with the same data. */
    private const REDRAW_VERBS = ['edit', 'back', 'cat', 'job', 'day', 'none'];

    public function __construct(private QuickEntries $entries) {}

    /**
     * A text message that reads as a cost: a draft and its buttons. A reply to one of the bot's drafts changes it.
     * False when the text isn't a cost (it goes to the assistant).
     */
    public function fromText(array $message, string $text): bool
    {
        $chatId = (string) ($message['chat']['id'] ?? '');
        $today = CarbonImmutable::now('Europe/London')->startOfDay();
        $replyTo = $message['reply_to_message']['message_id'] ?? null;
        if (is_numeric($replyTo) && ($draft = QuickEntry::query()->where('telegram_chat_id', $chatId)->where('telegram_message_id', (int) $replyTo)->first())) {
            return $this->amend($draft, $text, $today);
        }
        $parsed = QuickText::parse($text, $today, QuickText::accountNames());
        if ($parsed === null) {
            return false;
        }
        if (QuickEntry::query()->where('client_key', 'tg:'.$chatId.':'.(int) ($message['message_id'] ?? 0))->exists()) {
            return true; // the same update again: its draft is made already
        }
        $photo = $this->unclaimedPhoto();
        $entry = $this->draft($chatId, (int) ($message['message_id'] ?? 0), $parsed, $photo,
            $photo ? 'Added to the photo you sent at '.$photo->created_at?->timezone('Europe/London')->format('H:i').'.' : null);

        return $entry !== null;
    }

    /** A photo or file whose caption reads as a cost: a draft holding it. */
    public function fromCaption(array $message, Document $document, string $caption): ?QuickEntry
    {
        $parsed = QuickText::parse($caption, CarbonImmutable::now('Europe/London')->startOfDay(), QuickText::accountNames());
        if ($parsed === null) {
            return null;
        }

        return $this->draft((string) ($message['chat']['id'] ?? ''), (int) ($message['message_id'] ?? 0), $parsed, $document);
    }

    /**
     * A photo read as a receipt with an amount, sent with no caption: offered as a draft labelled as read from the photo
     * (only a press makes it yours). When it looks like a receipt you added already, that's asked first.
     */
    public function offerRead(Document $document, string $chatId): ?QuickEntry
    {
        $r = $document->extraction['receipt'] ?? null;
        if (! is_array($r) || ! is_int($r['amount_minor'] ?? null) || ReceiptMatch::owner($document) !== null) {
            return null;
        }
        $currency = strtoupper((string) ($r['currency'] ?? 'GBP')) ?: 'GBP';
        try {
            $on = ! empty($r['date']) ? CarbonImmutable::parse($r['date']) : CarbonImmutable::now('Europe/London');
        } catch (Throwable) {
            $on = CarbonImmutable::now('Europe/London');
        }
        $parsed = ['minor' => (int) Money::fromHundredths((int) $r['amount_minor'], $currency), 'currency' => $currency, 'amount' => '', 'what' => trim((string) ($r['merchant'] ?? '')),
            'paid_with' => null, 'account_id' => null, 'date' => $on->format('Y-m-d'), 'date_word' => null];

        return $this->draft($chatId, 0, $parsed, $document, null, 'doc:'.$document->id);
    }

    /**
     * The receipt this cost may be a second copy of: a live entry (not a draft) or an expense you added by hand, of the
     * same amount and currency within a day. Asked about before the draft's buttons, so a cost texted twice (or sent
     * on the phone and in Telegram) isn't added twice.
     *
     * @return array{ref:string, words:string, where:string, at:?\DateTimeInterface}|null
     */
    public static function sameAs(int $minor, string $currency, CarbonImmutable $on): ?array
    {
        $from = $on->subDay()->format('Y-m-d');
        $to = $on->addDay()->format('Y-m-d');
        $entry = QuickEntry::query()->whereNotIn('status', ['draft', 'withdrawn', 'dismissed', 'duplicate', 'receiving', 'set_aside'])->where('amount_minor', $minor)
            ->where('currency', $currency)->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)->orderByDesc('id')->first();
        if ($entry) {
            return ['ref' => 'quick:'.$entry->id, 'words' => $entry->words(), 'where' => $entry->channel === 'telegram' ? 'here' : 'on your phone', 'at' => $entry->captured_at];
        }
        $own = QuickEntry::query()->whereNotNull('manual_expense_id')->pluck('manual_expense_id')->all();
        $hand = ManualExpense::query()->whereNotIn('id', $own ?: [0])->whereDate('spent_on', '>=', $from)->whereDate('spent_on', '<=', $to)
            ->where(fn ($q) => $currency === 'GBP' ? $q->where('amount_pence', -$minor)->whereNull('original_currency')
                : $q->where('original_currency', $currency)->where('original_amount_minor', -$minor))
            ->orderByDesc('id')->first();

        return $hand ? ['ref' => 'exp:'.$hand->id, 'words' => Money::formatMinor($minor, $currency, false).' '.$hand->description, 'where' => 'by hand', 'at' => $hand->created_at] : null;
    }

    /**
     * Make (or find) the draft for a message, and send its message with buttons. When it looks like a receipt you
     * added already, that's asked first.
     *
     * @param  array{minor:int, currency:string, what:string, paid_with:?string, account_id:?int, date:string, date_word:?string}  $parsed
     */
    public function draft(string $chatId, int $messageId, array $parsed, ?Document $doc = null, ?string $lead = null, ?string $keyFor = null): ?QuickEntry
    {
        $key = 'tg:'.$chatId.':'.($keyFor ?? (string) $messageId);
        if (QuickEntry::query()->where('client_key', $key)->exists()) {
            return null; // the same update again: one draft
        }
        $on = CarbonImmutable::parse($parsed['date']);
        $category = QuickHints::categoryFor($parsed['what']);
        if ($parsed['what'] !== '' && ($p = app(Categoriser::class)->preview($parsed['what'], (int) Money::toHundredths($parsed['minor'], $parsed['currency']), $on))
            && $p['category_id'] !== null) {
            $category = Category::query()->find($p['category_id']) ?? $category;
        }
        $job = QuickText::jobFor($parsed['what'], $on);
        $jobCost = $category === null || in_array($category->code, Categoriser::JOB_COSTS, true);
        try {
            $entry = DB::transaction(function () use ($key, $chatId, $parsed, $doc, $on, $category, $job, $jobCost) {
                $entry = QuickEntry::query()->create([
                    'client_key' => $key, 'channel' => 'telegram', 'status' => 'draft', 'paid_with' => $parsed['paid_with'], 'account_id' => $parsed['account_id'],
                    'spent_on' => $on->format('Y-m-d'), 'tax_year' => UkTaxYear::fromDate($on)->label(), 'captured_at' => now(),
                    'amount_minor' => $parsed['minor'], 'currency' => $parsed['currency'], 'what' => $parsed['what'] !== '' ? mb_substr($parsed['what'], 0, 500) : null,
                    'what_source' => $parsed['what'] !== '' ? 'telegram' : null, 'category_id' => $category?->id,
                    'work_job_id' => $job && $jobCost ? $job['job']->id : null, 'job_source' => $job && $jobCost ? ($job['source'] === 'words' ? 'words' : 'today') : null,
                    'business_pct' => 100, 'document_id' => $doc?->id, 'photo_status' => $doc ? 'stored' : null, 'photo_sha256' => $doc?->sha256,
                    'telegram_chat_id' => $chatId,
                ]);
                AuditLog::record('quick_entry.drafted', $entry, null, QuickEntries::snapshot($entry), 'Sent in Telegram');

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $text = ($lead ? $lead."\n" : '').$this->offerText($entry);
        $rows = $this->mainKeyboard($entry);
        $sameAs = self::sameAs((int) $parsed['minor'], (string) $parsed['currency'], $on);
        if ($sameAs) {
            $when = $sameAs['at'] ? ' at '.CarbonImmutable::parse($sameAs['at'])->timezone('Europe/London')->format('H:i') : '';
            $text = ($doc ? 'This looks like '.$sameAs['words'].' you added '.$sameAs['where'].$when.'.' : 'You added '.$sameAs['words'].' '.$sameAs['where'].$when.'. Add another?')
                ."\n".$this->offerText($entry);
            $rows = [[[$doc ? 'Same receipt: keep this photo with it' : 'Same receipt: don’t add it', 'qx', $entry->id.':same:'.$sameAs['ref']]],
                [['A different payment', 'qx', $entry->id.':not_same:'.$sameAs['ref']]]];
        } elseif ($keyFor !== null) {
            $text = $this->offerText($entry)."\nAdd it as an expense? (read from the photo: check the amount)";
        } elseif ($doc && $lead) {
            $rows[] = [['Not that photo', 'qx', $entry->id.':not_that_photo']];
        }
        $sent = Telegram::sendWithButtonsId($chatId, $text, $rows);
        if ($sent !== null) {
            QuickEntry::query()->whereKey($entry->id)->update(['telegram_message_id' => $sent]);
        }

        return $entry->fresh();
    }

    /** A reply to the bot's message about a draft: a new amount (or a whole new text) changes it, and it's shown again. */
    private function amend(QuickEntry $draft, string $text, CarbonImmutable $today): bool
    {
        $parsed = QuickText::parse($text, $today, QuickText::accountNames());
        if ($parsed === null) {
            return false; // a question or a remark about it: for the assistant
        }
        if ($draft->status !== 'draft') {
            Telegram::reply((string) $draft->telegram_chat_id, 'That one is '.mb_strtolower(QuickEntries::outcome($draft)).' already: change it in Expend. '.url('/go?to=/quick/entries/'.$draft->id));

            return true;
        }
        $changes = ['amount_minor' => $parsed['minor'], 'currency' => $parsed['currency']];
        if ($parsed['what'] !== '') {
            $changes['what'] = $parsed['what'];
            $changes['what_source'] = 'telegram';
        }
        if ($parsed['date_word'] !== null) {
            $changes['spent_on'] = $parsed['date'];
        }
        if ($parsed['paid_with'] !== null) {
            $changes['paid_with'] = $parsed['paid_with'];
        }
        $row = $this->entries->correctFields($draft, $changes, 'Changed by your reply in Telegram');
        $this->redraw($row, 'Changed. '.$this->offerText($row), $this->mainKeyboard($row));

        return true;
    }

    /** The draft's words: "£12.50 · parking Leeds · today, 25 Nov / Job: … · Category / How did you pay?". */
    public function offerText(QuickEntry $e): string
    {
        $e->loadMissing(['category', 'job']);
        $today = CarbonImmutable::now('Europe/London')->startOfDay();
        $lines = [QuickText::summary(['minor' => (int) $e->amount_minor, 'currency' => $e->currency, 'what' => (string) $e->what, 'date' => $e->spent_on?->format('Y-m-d') ?? $today->format('Y-m-d'),
            'date_word' => null], $today)];
        $job = $e->job ? 'Job: '.$e->job->title.($e->job->worksOn((string) $e->spent_on?->format('Y-m-d')) ? ($e->spent_on?->isSameDay($today) ? ' (on today)' : ' (on that day)') : '') : 'No job';
        $lines[] = $job.' · '.($e->category?->name ?? 'Choose what it was');
        if ($e->category?->code === 'travel_fuel' && ($n = Vehicle::query()->count()) > 0 && Vehicle::query()->where('method', 'simplified')->count() === $n) {
            $lines[] = 'Fuel for your own car is covered by mileage; hire cars only.';
        }
        $label = $e->spent_on ? UkTaxYear::fromDate($e->spent_on)->label() : null;
        if ($label && ManualExpenses::locked($label) && $e->paid_with !== 'business') {
            $lines[] = "{$label} is locked: it’ll be kept, not added, until you unlock it.";
        }
        $lines[] = $e->paid_with ? 'Paid with: '.(QuickEntry::PAID_WITH[$e->paid_with] ?? $e->paid_with).($e->account ? ' · '.$e->account->name : '') : 'How did you pay?';

        return implode("\n", $lines);
    }

    /** @return list<list<array>> how you paid (or Add it), then Change…, Not an expense and Open in Expend */
    public function mainKeyboard(QuickEntry $e): array
    {
        $pay = $e->paid_with
            ? [['Add it', 'qx', $e->id.':add']]
            : [['Business card', 'qx', $e->id.':card'], ['Personal card', 'qx', $e->id.':personal'], ['Cash', 'qx', $e->id.':cash']];

        return [$pay, [['Change…', 'qx', $e->id.':edit'], ['Not an expense', 'qx', $e->id.':cancel'], ['url' => url('/go?to=/quick/entries/'.$e->id), 'text' => 'Open in Expend']]];
    }

    /** @return list<list<array>> six categories (your chips), up to three jobs near the day and No job, Today and Yesterday, Back */
    public function editKeyboard(QuickEntry $e): array
    {
        $today = CarbonImmutable::now('Europe/London')->startOfDay();
        $chips = array_slice(QuickPocket::chips($today), 0, 6);
        $cats = array_map(fn (array $c) => [($e->category_id === $c['category_id'] ? '✓ ' : '').$c['label'], 'qx', $e->id.':cat:'.$c['category_id']], $chips);
        $on = $e->spent_on ? CarbonImmutable::parse($e->spent_on->format('Y-m-d')) : CarbonImmutable::now('Europe/London');
        $jobs = WorkJob::query()->where('status', '!=', 'cancelled')->whereDate('starts_on', '<=', $on->addDays(2)->format('Y-m-d'))
            ->whereDate('ends_on', '>=', $on->subDays(2)->format('Y-m-d'))->orderBy('starts_on')->limit(3)->get(['id', 'title']);
        $jobRow = $jobs->map(fn (WorkJob $j) => [($e->work_job_id === $j->id ? '✓ ' : '').mb_substr($j->title, 0, 24), 'qx', $e->id.':job:'.$j->id])->values()->all();
        $jobRow[] = [($e->work_job_id === null ? '✓ ' : '').'No job', 'qx', $e->id.':job:0'];

        return [array_slice($cats, 0, 3), array_slice($cats, 3, 3), $jobRow,
            // The day each button means is in the button, so one pressed after midnight still means the day it said.
            [['Today ('.$today->format('j M').')', 'qx', $e->id.':day:'.$today->format('Ymd')], ['Yesterday ('.$today->subDay()->format('j M').')', 'qx', $e->id.':day:'.$today->subDay()->format('Ymd')],
                ['Back', 'qx', $e->id.':back']]];
    }

    /** @return list<list<array>> after "None of these": how else it was paid */
    public function otherWaysKeyboard(QuickEntry $e): array
    {
        return [[['Paid by personal card', 'qx', $e->id.':paid_personal'], ['Paid in cash', 'qx', $e->id.':paid_cash']], [['Not a business cost', 'qx', $e->id.':aside']]];
    }

    /**
     * A qx button pressed in Telegram (Telegram::handleButton has checked the chat and the data): done once (a second
     * press gets the first answer), as yours from Telegram, refused when what it was offered on has changed. The
     * message is then edited: the draft's keyboards are redrawn with fresh buttons; anything else gets the result.
     */
    public function press(array $query, string $id, ?string $fp): void
    {
        $chatId = (string) ($query['message']['chat']['id'] ?? '');
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        $parsed = QuickEntries::parseAction($id);
        $verb = $parsed[1] ?? '';
        // A button that writes is done once (a second press gets the first answer). One that only redraws or sets a
        // value again (Change…, Back, a category, a job, a day) can come round again with the same data after going
        // back: only Telegram delivering the same press twice is held back.
        $press = in_array($verb, self::REDRAW_VERBS, true)
            ? 'telegram_press:'.$chatId.':'.$messageId.':q:'.($query['id'] ?? '')
            : 'telegram_press:'.$chatId.':'.$messageId.':'.($query['data'] ?? '');
        if (! Cache::add($press, '', now()->addMinutes(10))) {
            $earlier = (string) Cache::get($press);
            Telegram::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => mb_substr($earlier !== '' ? $earlier : 'Already on it.', 0, 190)]);

            return;
        }
        $offeredAt = is_numeric($query['message']['date'] ?? null) ? Carbon::createFromTimestamp((int) $query['message']['date']) : null;
        $keyboard = null;
        $text = null;
        try {
            $result = AuditContext::with('you', fn () => app(TodoActions::class)->run('qx', $id, $offeredAt, $fp), 'telegram');
            $entry = $parsed ? QuickEntry::query()->find($parsed[0]) : null;
            if ($entry && $entry->status === 'draft' && in_array($verb, ['edit', 'back', 'cat', 'job', 'day', 'not_that_photo', 'not_same', 'not_card'], true)) {
                $text = $this->offerText($entry);
                $keyboard = $verb === 'edit' ? $this->editKeyboard($entry) : $this->mainKeyboard($entry);
                $answer = $verb === 'edit' ? 'Change what it was, the job or the day.' : ($result !== '' ? $result : 'Changed.');
            } elseif ($entry && $verb === 'none') {
                $text = ($query['message']['text'] ?? '')."\n\nThen how did you pay?";
                $keyboard = $this->otherWaysKeyboard($entry);
                $answer = 'Then how did you pay?';
            } else {
                $answer = '✓ '.($result !== '' ? $result : 'Done.');
            }
            Cache::put($press, $answer, now()->addMinutes(10));
        } catch (ModelNotFoundException) {
            $answer = in_array($verb, ['card', 'personal', 'cash', 'add'], true) ? 'Already added.' : 'Already dealt with.';
            Cache::put($press, $answer, now()->addMinutes(10));
        } catch (ChangedSince) {
            $answer = '⚠ '.Telegram::changedText();
            Cache::put($press, $answer, now()->addMinutes(10));
            // A draft changed since (a reply, another button): shown again as it is now, with buttons for that.
            $entry = $parsed ? QuickEntry::query()->find($parsed[0]) : null;
            if ($entry && $entry->status === 'draft') {
                $answer = '⚠ This changed since: here it is now. Nothing was done.';
                Cache::put($press, $answer, now()->addMinutes(10));
                $text = "This changed since: here it is now.\n".$this->offerText($entry);
                $keyboard = $this->mainKeyboard($entry);
            }
        } catch (CardLineFits $e) {
            Cache::forget($press);
            $answer = $e->getMessage();
            $entryId = $parsed[0] ?? 0;
            $text = ($query['message']['text'] ?? '')."\n\n".$e->getMessage();
            $keyboard = QuickEntry::query()->whereKey($entryId)->value('status') === 'draft'
                ? [[['It’s this one', 'qx', $entryId.':card'], ['No', 'qx', $entryId.':not_card:'.$e->payment->id]]]
                : [[['It’s this one', 'qx', $entryId.':attach:'.$e->payment->id], ['No', 'qx', $entryId.':not_card:'.$e->payment->id]]];
        } catch (InvalidArgumentException $e) {
            $answer = '⚠ '.$e->getMessage();
            Cache::forget($press); // nothing happened: pressing again (once it's sorted out) tries again
        } catch (Throwable $e) {
            report($e);
            $answer = '⚠ '.$e->getMessage();
            Cache::forget($press);
        }
        TodoList::forget();
        Telegram::api('answerCallbackQuery', ['callback_query_id' => $query['id'] ?? '', 'text' => mb_substr($answer, 0, 190)]);
        if ($keyboard !== null) {
            Telegram::api('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => mb_substr((string) $text, 0, 4000), 'disable_web_page_preview' => true,
                'reply_markup' => ['inline_keyboard' => Telegram::keyboard($keyboard)]]);

            return;
        }
        Telegram::api('editMessageText', ['chat_id' => $chatId, 'message_id' => $messageId, 'disable_web_page_preview' => true,
            'text' => mb_substr(($query['message']['text'] ?? '')."\n\n".$answer, 0, 4000)]);
    }

    /** Show a draft's message again (after a reply changed it), with fresh buttons. */
    private function redraw(QuickEntry $e, string $text, array $rows): void
    {
        if ($e->telegram_message_id) {
            Telegram::api('editMessageText', ['chat_id' => $e->telegram_chat_id, 'message_id' => $e->telegram_message_id, 'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => true, 'reply_markup' => ['inline_keyboard' => Telegram::keyboard($rows)]]);

            return;
        }
        $sent = Telegram::sendWithButtonsId((string) $e->telegram_chat_id, $text, $rows);
        if ($sent !== null) {
            QuickEntry::query()->whereKey($e->id)->update(['telegram_message_id' => $sent]);
        }
    }

    /** The newest photo sent in Telegram with no caption in the last half hour that no entry holds yet. */
    private function unclaimedPhoto(): ?Document
    {
        $owned = QuickEntry::query()->whereNotNull('document_id')->pluck('document_id')->all();

        return Document::query()->where('source', 'telegram')->whereNull('parent_id')->whereNull('email_subject')->where('kind', '!=', 'email')
            ->where('created_at', '>=', now()->subMinutes(self::CLAIM_MINUTES))->whereNotIn('id', $owned ?: [0])
            ->where(fn ($q) => $q->where('mime', 'like', 'image/%')->orWhere('mime', 'application/pdf'))
            ->orderByDesc('id')->first();
    }
}
