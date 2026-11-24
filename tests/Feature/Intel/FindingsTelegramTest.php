<?php

namespace Tests\Feature\Intel;

use App\Domain\Telegram\Telegram;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Snooze a week and Not relevant from Telegram (fnd_snooze, fnd_dismiss): the finding's id and a preset reason in the
 * button, within Telegram's 64 bytes; the reason is kept with the dismissal and the finding, audited as yours from
 * Telegram. Since M7 a button that decides carries the fingerprint of what it was sent on (Telegram::callbackData()), and
 * an old three-part one is refused for anything but what decides nothing.
 */
class FindingsTelegramTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Carbon::setTestNow('2025-07-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function charge(string $date, int $pence, string $name): BankTransaction
    {
        $bank = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $name, 'merchant_key' => $name, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    private function press(string $data, int $message = 7): void
    {
        Telegram::handleButton(['id' => 'q'.$message, 'data' => $data, 'message' => ['message_id' => $message, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => 'Charged twice?']],
            app(TodoActions::class));
    }

    /** @return list<array{text:string, rows:list<list<string>>}> */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'sendMessage'))
            ->map(fn ($pair) => ['text' => (string) $pair[0]['text'], 'rows' => array_map(fn ($row) => array_column($row, 'callback_data'), $pair[0]['reply_markup']['inline_keyboard'] ?? [])])
            ->values()->all();
    }

    public function test_not_relevant_refunded_from_telegram_keeps_the_reason_and_an_audit_event(): void
    {
        $tx = $this->charge('2025-06-12', -2500, 'PRET A MANGER');
        $this->charge('2025-06-12', -2500, 'PRET A MANGER');
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'dupe:'.$tx->id)->sole();

        Telegram::sendTodo('42');
        $message = collect($this->sent())->first(fn ($m) => str_starts_with($m['text'], 'Charged twice?'));
        // Snooze decides (it carries the fingerprint of the finding as shown); Not relevant… only asks why.
        $this->assertSame([[Telegram::callbackData('fnd_snooze', $finding->id.':later'), 't|fnd_dismiss|'.$finding->id]], $message['rows']);
        $this->assertMatchesRegularExpression('/^t\|fnd_snooze\|\d+:later\|[0-9a-f]{6}$/', $message['rows'][0][0]);

        // Not relevant… asks why, with the reasons for a duplicate.
        $this->press('t|fnd_dismiss|'.$finding->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageReplyMarkup')
            && in_array(Telegram::callbackData('fnd_dismiss', $finding->id.':refunded'), array_column(array_merge(...$r['reply_markup']['inline_keyboard']), 'callback_data'), true)
            && in_array('Both charges were real', array_column(array_merge(...$r['reply_markup']['inline_keyboard']), 'text'), true));
        $this->assertNull(TodoDismissal::query()->first(), 'Nothing hidden until a reason is chosen');

        $this->press(Telegram::callbackData('fnd_dismiss', $finding->id.':refunded'), 8);
        $dismissal = TodoDismissal::query()->where('key', 'dupe:'.$tx->id)->sole();
        $this->assertSame([null, 'Refunded'], [$dismissal->until, $dismissal->note]);
        $finding->refresh();
        $this->assertSame(['dismissed', 'Refunded', 'dismissed'], [$finding->state, $finding->note, $finding->resolution]);
        $audit = AuditEvent::query()->where('action', 'todo.dismissed')->sole();
        $this->assertSame(['you', 'Refunded', 'dupe:'.$tx->id], [$audit->actor, $audit->reason, $audit->after['key']]);
        $event = FindingEvent::query()->where('finding_id', $finding->id)->where('event', 'dismissed')->sole();
        $this->assertSame(['you', 'Refunded', 'audit:'.$audit->id.'@'.substr($audit->hash, 0, 8)], [$event->actor, $event->note, $event->cause_ref]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageText') && str_contains($r['text'], '✓ Not relevant (Refunded)'));
        $this->assertNotContains('dupe:'.$tx->id, array_column(TodoList::build()['items'], 'key'));

        // The next sync agrees, and writes nothing more.
        FindingsRegistry::sync();
        $this->assertSame(2, FindingEvent::query()->where('finding_id', $finding->id)->count());
    }

    public function test_snooze_a_week_from_telegram(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        $snooze = Telegram::callbackData('fnd_snooze', $finding->id.':later');
        $this->press($snooze);
        $dismissal = TodoDismissal::query()->where('key', 'no_po:'.$job->id)->sole();
        $this->assertSame(['2025-07-08', 'Later'], [$dismissal->until->format('Y-m-d'), $dismissal->note]);
        $this->assertSame('snoozed', $finding->fresh()->state);
        // Pressed again (another message): already hidden, nothing changes.
        $this->press($snooze, 9);
        $this->assertSame(1, FindingEvent::query()->where('finding_id', $finding->id)->where('event', 'snoozed')->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && ($r['text'] ?? '') === 'Already dealt with.');
    }

    public function test_one_that_can_only_be_snoozed_has_no_not_relevant_and_refuses_it(): void
    {
        Setting::put('calendar_ics_url', 'https://calendar.example/feed.ics');
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'health:calendar')->sole();
        Telegram::sendTodo('42');
        $message = collect($this->sent())->first(fn ($m) => str_starts_with($m['text'], 'Calendar not updated'));
        $this->assertSame([[Telegram::callbackData('fnd_snooze', $finding->id.':later')]], $message['rows']);

        $this->press(Telegram::callbackData('fnd_dismiss', $finding->id.':other'));
        $this->assertNull(TodoDismissal::query()->first());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && str_contains((string) ($r['text'] ?? ''), 'can only be snoozed'));
    }

    public function test_button_data_stays_within_64_bytes_and_old_buttons_keep_working(): void
    {
        $finding = Finding::query()->create(['key' => 'no_po:1', 'group' => 'no_po', 'title' => 'No PO', 'code' => 'tx.duplicate', 'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $finding->forceFill(['id' => 9_999_999_999])->save();
        $buttons = Telegram::findingButtons(['snooze_only' => false], $finding->id);
        foreach ([...$buttons, ...array_merge(...Telegram::reasonButtons($finding))] as $b) {
            $data = is_array($b) && isset($b['callback_data']) ? $b['callback_data'] : Telegram::callbackData($b[1], $b[2]);
            $this->assertNotNull($data);
            $this->assertLessThanOrEqual(Telegram::CALLBACK_BYTES, strlen($data), $data);
        }

        // An old three-part button for a code that decides something is refused since M7 (it carries no fingerprint of
        // what it was sent on), with what to do instead; with its fingerprint, it acts.
        $candidate = JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);
        $this->press('t|cand_dismiss|'.$candidate->id);
        $this->assertSame('open', $candidate->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && str_starts_with((string) ($r['text'] ?? ''), 'This changed since: open it in Expend.'));
        $this->press(Telegram::callbackData('cand_dismiss', (string) $candidate->id), 11);
        $this->assertSame('dismissed', $candidate->fresh()->status);
        // A finding button with a reason it doesn't offer is refused.
        $this->press(Telegram::callbackData('fnd_dismiss', $finding->id.':bogus'), 10);
        $this->assertNull(TodoDismissal::query()->first());
    }
}
