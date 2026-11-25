<?php

namespace Tests\Feature\Intel;

use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\MerchantKey;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\ChangedSince;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\User;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Actions bound to what was shown (M7): each to-do button that decides something carries a fingerprint of the state it
 * was offered on, on the web and in Telegram (t|code|id|fp, within 64 bytes). A press on something that has changed
 * since is refused with a plain message, and nothing is done. Old three-part buttons still work for what decides
 * nothing.
 */
class FingerprintTest extends TestCase
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

    private function press(?string $data, int $message = 7, string $query = 'q1'): void
    {
        Telegram::handleButton(['id' => $query, 'data' => $data, 'message' => ['message_id' => $message, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => 'To do']],
            app(TodoActions::class));
    }

    private static function answered(string $query, string $text): \Closure
    {
        return fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && ($r['callback_query_id'] ?? null) === $query && str_contains((string) ($r['text'] ?? ''), $text);
    }

    public function test_a_press_on_a_payment_changed_since_it_was_offered_is_refused_with_a_message(): void
    {
        $tx = $this->tx('2025-06-12', -4500, 'ENTERPRISE RENT-A-CAR');
        $writer = app(AllocationWriter::class);
        $writer->decide($tx, AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks like personal');
        $button = Telegram::callbackData('tx_accept', (string) $tx->id);
        $this->assertMatchesRegularExpression('/^t\|tx_accept\|\d+\|[0-9a-f]{6}$/', $button);
        $shown = Fingerprints::of('tx_accept', (string) $tx->id);

        // Changed since: another suggestion replaced it. Accepting now would confirm what you never saw.
        $writer->clear($tx, 'Looked at again', 'matcher');
        $writer->decide($tx->fresh(), AllocationWriter::lines(-4500, 'business_expense', (int) Category::query()->where('code', 'travel_car_hire')->value('id')), 'matcher', false, 'Looks like car hire');
        $this->press($button);
        Http::assertSent(self::answered('q1', 'This changed since: open it in Expend'));
        $this->assertSame('suggested', $tx->fresh()->review_status);

        // On the web too: refused with a message, nothing done.
        $this->actingAs(User::factory()->create());
        $this->post("/todo/tx_accept/{$tx->id}", ['fp' => $shown])->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $tx->id)->value('confirmed_at'));
        $this->expectException(ChangedSince::class);
        app(TodoActions::class)->run('tx_accept', (string) $tx->id, null, $shown);
    }

    public function test_a_press_on_what_was_shown_acts(): void
    {
        $tx = $this->tx('2025-06-12', -4500, 'ENTERPRISE RENT-A-CAR');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-4500, 'personal'), 'matcher', false, 'Looks like personal');
        $this->press(Telegram::callbackData('tx_accept', (string) $tx->id));
        Http::assertSent(self::answered('q1', 'Accepted'));
        $this->assertSame('confirmed', $tx->fresh()->review_status);

        $this->actingAs(User::factory()->create());
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $this->post("/todo/job_no_po/{$job->id}", ['fp' => Fingerprints::of('job_no_po', (string) $job->id)])->assertSessionHas('success');
        $this->assertTrue($job->fresh()->po_not_required);
        // A malformed fingerprint isn't taken.
        $this->post("/todo/job_no_po/{$job->id}", ['fp' => 'not-hex'])->assertSessionHasErrors('fp');
    }

    public function test_old_three_part_buttons_work_for_what_decides_nothing_and_are_refused_for_the_rest(): void
    {
        $doc = Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted']);
        $this->press('t|recheck|document:'.$doc->id, 1, 'q1');
        Http::assertNotSent(self::answered('q1', 'This changed since'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageText') && str_contains((string) $r['text'], 'To do'));

        // "Not relevant…" by a finding's id only asks why: an old one still does.
        $finding = Finding::query()->create(['key' => 'no_po:9', 'group' => 'no_po', 'title' => 'No PO', 'code' => 'job.no_po', 'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $this->press('t|fnd_dismiss|'.$finding->id, 2, 'q2');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'editMessageReplyMarkup'));

        // Anything that decides, from before buttons carried a fingerprint: refused, with what to do.
        $candidate = JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);
        $this->press('t|cand_add|'.$candidate->id, 3, 'q3');
        Http::assertSent(self::answered('q3', 'This changed since: open it in Expend'));
        $this->assertSame('open', $candidate->fresh()->status);
        $this->assertSame(0, WorkJob::query()->count());
        // A fingerprint that isn't one: an unknown button.
        $this->press('t|cand_add|'.$candidate->id.'|zzzzzz', 4, 'q4');
        Http::assertSent(self::answered('q4', 'Unknown button.'));
    }

    public function test_callback_data_stays_within_64_bytes_for_every_action(): void
    {
        $big = '9999999999';
        $ids = [
            'rule_make' => $big, 'rule_no' => $big, 'tx_accept' => $big, 'tx_personal' => $big, 'tx_tax' => $big, 'cand_add' => $big, 'cand_merge' => $big.':'.$big, 'cand_dismiss' => $big,
            'job_invoice' => $big, 'job_no_po' => $big, 'stay_accept' => $big, 'stay_personal' => $big, 'doc_approve' => $big, 'recheck' => 'calendar_link:'.$big,
            'inv_chase' => $big, 'inv_send' => $big, 'transfers_ok' => $big, 'opp_add' => $big, 'opp_decline' => $big, 'opp_dismiss' => $big,
            'fnd_snooze' => $big.':waiting', 'fnd_dismiss' => $big.':not_problem', 'trips_add' => '2025:0123456789:'.$big.':abcdef01',
            'det_raise' => 'receipt:unmatched', 'det_lower' => 'receipt:unmatched', 'veto_lift' => $big,
            'qx' => '9999999:not_card:99999999,99999999,99999999',
        ];
        $this->assertSame([], array_values(array_diff(TodoActions::CODES, array_keys($ids))), 'Every action is tried');
        foreach ($ids as $code => $id) {
            $data = Telegram::callbackData($code, $id);
            $this->assertNotNull($data, $code);
            $this->assertLessThanOrEqual(Telegram::CALLBACK_BYTES, strlen($data), $data);
            $this->assertSame(Fingerprints::decides($code, $id) ? 4 : 3, count(explode('|', $data)), $data);
        }
        // One that wouldn't fit is never cut (a cut id would act on something else): it's left out.
        $this->assertNull(Telegram::callbackData('fnd_dismiss', str_repeat('9', 60).':other'));
    }

    public function test_the_to_do_page_sends_each_action_with_its_fingerprint(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        TodoList::forget();
        $this->actingAs(User::factory()->create());
        $this->get('/todo')->assertOk()->assertInertia(fn ($page) => $page->where('items', fn ($items) => collect($items)->contains(fn ($i) => $i['key'] === 'no_po:'.$job->id
            && collect($i['actions'])->contains(fn ($a) => $a['code'] === 'job_no_po' && $a['fp'] === Fingerprints::of('job_no_po', (string) $job->id)))));
    }

    public function test_lifting_a_veto_is_bound_to_it_as_shown(): void
    {
        $tx = $this->tx('2025-06-12', -650, 'PRET A MANGER');
        $veto = Vetoes::record('allocation_shape', 'tx:'.$tx->id, null, Vetoes::shape([['kind' => 'personal']]));
        $fp = Fingerprints::of('veto_lift', (string) $veto->id);
        $this->actingAs(User::factory()->create());
        $this->post('/todo/veto_lift/'.$veto->id, ['fp' => $fp])->assertSessionHas('success');
        $this->assertNotNull($veto->fresh()->lifted_at);
        $this->assertNotSame($fp, Fingerprints::of('veto_lift', (string) $veto->id), 'Lifted: a new state');
        $this->assertSame(1, Veto::query()->count());
    }
}
