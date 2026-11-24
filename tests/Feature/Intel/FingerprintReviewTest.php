<?php

namespace Tests\Feature\Intel;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\MerchantKey;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\JobCandidate;
use App\Models\Opportunity;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buttons bound to what was shown, after review (M7): bound to what the item shows and the press acts on, never to what
 * a night's run or a follow-up email refreshes; a press after you did it yourself is told it's done; and the web never
 * takes a deciding press that says nothing about what it was shown.
 */
class FingerprintReviewTest extends TestCase
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

    private function tx(string $date, int $pence, string $description): BankTransaction
    {
        $bank = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'merchant_key' => MerchantKey::from($description, null), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    private function press(string $data, string $query = 'q1', int $message = 7): void
    {
        Telegram::handleButton(['id' => $query, 'data' => $data, 'message' => ['message_id' => $message, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => 'To do']],
            app(TodoActions::class));
    }

    private static function answered(string $query, string $text): \Closure
    {
        return fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && ($r['callback_query_id'] ?? null) === $query && str_contains((string) ($r['text'] ?? ''), $text);
    }

    private function opportunity(): Opportunity
    {
        return Opportunity::query()->create(['status' => 'offered', 'title' => 'Acme Awards', 'client_name' => 'Acme Events', 'starts_on' => '2025-07-20', 'ends_on' => '2025-07-20',
            'reply_by' => '2025-07-03', 'source' => 'gmail', 'found_by' => 'ai', 'evidence' => [['field' => 'title', 'quote' => 'Acme Awards', 'doc' => 1]],
            'fingerprint' => Str::random(40), 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_an_opportunitys_button_still_works_after_a_follow_up_email_that_changes_nothing_shown(): void
    {
        $o = $this->opportunity();
        $button = Telegram::callbackData('opp_decline', (string) $o->id);

        // An hour later, "Just checking you got this?" in the thread: its words join the evidence, and it was seen again.
        Carbon::setTestNow('2025-07-01 10:00:00');
        $o->update(['evidence' => [...$o->evidence, ['field' => 'title', 'quote' => 'Acme Awards', 'doc' => 2]], 'last_seen_at' => now(), 'confidence' => 80]);
        $this->press($button);
        Http::assertSent(self::answered('q1', 'Marked as declined: Acme Awards'));
        $this->assertSame('declined', $o->fresh()->status);

        // A follow-up that moves what's shown (the date) refuses the button sent before it.
        $other = $this->opportunity();
        $button = Telegram::callbackData('opp_decline', (string) $other->id);
        $other->update(['starts_on' => '2025-07-27', 'ends_on' => '2025-07-27']);
        $this->press($button, 'q2', 8);
        Http::assertSent(self::answered('q2', 'This changed since: open it in Expend'));
        $this->assertSame('offered', $other->fresh()->status);
    }

    public function test_an_alerts_snooze_still_works_the_next_night_when_only_its_title_counted_one_more(): void
    {
        $f = Finding::query()->create(['key' => 'health:step:verify.audit_chain', 'group' => 'health', 'code' => 'health.cycle_step', 'title' => 'Checking the audit trail has failed 2 nights running',
            'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        [[, $code, $id]] = Telegram::findingButtons(['snooze_only' => true], $f->id);
        $this->assertSame('fnd_snooze', $code);
        $button = Telegram::callbackData($code, $id);

        Carbon::setTestNow('2025-07-02 08:00:00');
        $f->update(['title' => 'Checking the audit trail has failed 3 nights running', 'amount_pence' => 1234, 'last_seen_at' => now()]);
        $this->press($button);
        Http::assertSent(self::answered('q1', 'Snoozed for a week'));
        $this->assertSame(1, TodoDismissal::query()->count());

        // Gone and back again since (reopened): a new one, so the old button is refused.
        $g = Finding::query()->create(['key' => 'penalty:2024-25', 'group' => 'penalty', 'code' => 'tax.penalty', 'title' => 'Fines and interest for 2024-25: £100.00',
            'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $button = Telegram::callbackData('fnd_snooze', $g->id.':later');
        $g->update(['reopened' => 1, 'title' => 'Fines and interest for 2024-25: £100.03']);
        $this->press($button, 'q2', 8);
        Http::assertSent(self::answered('q2', 'This changed since'));
        $this->assertSame(1, TodoDismissal::query()->count());
    }

    public function test_a_press_after_you_did_it_yourself_says_it_is_done(): void
    {
        $tx = $this->tx('2025-06-12', -4500, 'ENTERPRISE RENT-A-CAR');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks like personal');
        $button = Telegram::callbackData('tx_accept', (string) $tx->id);
        $fp = Fingerprints::of('tx_accept', (string) $tx->id);
        app(AllocationWriter::class)->confirm($tx->fresh());

        $this->press($button);
        Http::assertSent(self::answered('q1', 'Already dealt with: ENTERPRISE RENT-A-CAR is confirmed.'));
        Http::assertNotSent(self::answered('q1', 'Nothing was done'));
        $this->actingAs(User::factory()->create());
        $this->post("/todo/tx_accept/{$tx->id}", ['fp' => $fp])->assertSessionHas('success', 'Already dealt with: ENTERPRISE RENT-A-CAR is confirmed.');
    }

    public function test_the_web_refuses_a_deciding_press_that_doesnt_say_what_it_was_shown(): void
    {
        $tx = $this->tx('2025-06-12', -4500, 'ENTERPRISE RENT-A-CAR');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks like personal');
        $this->actingAs(User::factory()->create());

        $this->post("/todo/tx_accept/{$tx->id}")->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertSame('suggested', $tx->fresh()->review_status);
        $this->post("/todo/tx_accept/{$tx->id}", ['fp' => Fingerprints::of('tx_accept', (string) $tx->id)])->assertSessionHas('success');
        $this->assertSame('confirmed', $tx->fresh()->review_status);

        // What decides nothing needs none. A possible job's Add and Not a job need one too (the connections map sends it).
        $this->post('/todo/recheck/bogus:1')->assertSessionHas('error', 'Unknown check.');
        $c = JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);
        $this->post("/todo/cand_add/{$c->id}")->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->post("/todo/cand_dismiss/{$c->id}")->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertSame(['open', 0], [$c->fresh()->status, WorkJob::query()->count()]);
        // Shown, then its dates moved before the press (the checkers' example): refused, and nothing is added.
        $shown = Fingerprints::of('cand_add', (string) $c->id);
        $c->update(['starts_on' => '2025-08-02', 'ends_on' => '2025-08-02']);
        $this->post("/todo/cand_add/{$c->id}", ['fp' => $shown])->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertSame(0, WorkJob::query()->count());
        $this->post("/todo/cand_add/{$c->id}", ['fp' => Fingerprints::of('cand_add', (string) $c->id)])->assertSessionHas('success');
        $this->assertSame(1, WorkJob::query()->count());
    }
}
