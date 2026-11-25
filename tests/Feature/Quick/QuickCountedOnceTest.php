<?php

namespace Tests\Feature\Quick;

use App\Domain\Ai\Assistant;
use App\Domain\Documents\DocumentIntake;
use App\Domain\Import\Importer;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\TravelDoubleClaim;
use App\Domain\Intel\Grades;
use App\Domain\Ledger\DecisionService;
use App\Domain\Quick\CardLineFits;
use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\QuickReadCheck;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Never counted twice (the QA of quick add, ledger side): the same receipt from the phone and Telegram, a receipt
 * added as cash and again as the business card, a receipt and money moved between your own accounts, the sterling
 * typed from a foreign receipt, fuel with simplified mileage, the photo's amount used on a receipt already on its line,
 * the same photo sent with other figures, and the audit of what a Telegram draft's photo and a kept copy become.
 */
class QuickCountedOnceTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    private int $tg = 500;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
        Queue::fake();
        $this->needsIntegration();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    private function allowable(string $year = '2026-27'): int
    {
        return (int) ReturnSummary::for($year)['allowable_expenses_pence'];
    }

    private function card(array $over = []): QuickEntry
    {
        $f = $this->fields(array_merge(['paid_with' => 'business', 'account_id' => $this->starling->id], $over));
        $this->send($f)->assertCreated();

        return $this->entry($f['client_key']);
    }

    private function cash(array $over = []): QuickEntry
    {
        $f = $this->fields($over);
        $this->send($f)->assertCreated();

        return $this->entry($f['client_key']);
    }

    private function import(BankTransaction $line): void
    {
        app(Importer::class)->afterImport(ImportBatch::query()->findOrFail($line->import_batch_id));
    }

    private function press(string $code, string $id): string
    {
        return QuickEntries::asYou(fn () => app(TodoActions::class)->run($code, $id, null, Fingerprints::of($code, $id)), 'web');
    }

    private function confirmed(BankTransaction $line): bool
    {
        $a = Allocation::query()->where('bank_transaction_id', $line->id)->get();

        return $a->isNotEmpty() && $a->every(fn ($x) => $x->confirmed_at !== null);
    }

    private function telegramOn(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Setting::put('ai_extraction', 'off');
        Http::fake(['api.telegram.org/*' => fn (Request $r) => Http::response(['ok' => true, 'result' => ['message_id' => ++$this->tg]])]);
    }

    private function text(string $text, int $id): void
    {
        Telegram::handle(['message' => ['text' => $text, 'message_id' => $id, 'chat' => ['id' => 42, 'type' => 'private'], 'date' => now()->timestamp]],
            app(DocumentIntake::class), app(Assistant::class));
    }

    /** @return array{text:string, rows:list<list<array<string, string>>>} the bot's last new message */
    private function lastOffer(): array
    {
        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->last();

        return ['text' => (string) ($sent[0]['text'] ?? ''), 'rows' => $sent[0]['reply_markup']['inline_keyboard'] ?? []];
    }

    private function lastEdit(): array
    {
        $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/editMessageText'))->last();

        return ['text' => (string) ($sent[0]['text'] ?? ''), 'rows' => $sent[0]['reply_markup']['inline_keyboard'] ?? []];
    }

    private function tap(string $data, int $messageId): string
    {
        static $n = 0;
        Telegram::handleButton(['id' => 'cq'.(++$n), 'data' => $data, 'message' => ['message_id' => $messageId, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => 'offer']],
            app(TodoActions::class));

        return (string) Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery'))->last()[0]['text'];
    }

    private function button(array $rows, string $label): string
    {
        foreach ($rows as $row) {
            foreach ($row as $b) {
                if (($b['text'] ?? '') === $label) {
                    return (string) $b['callback_data'];
                }
            }
        }
        $this->fail('No button “'.$label.'” in '.json_encode($rows));
    }

    // ---- the same receipt twice --------------------------------------------------------------------------------------

    public function test_the_same_cost_typed_on_the_phone_and_texted_with_its_place_is_asked_about_before_it_is_added(): void
    {
        $this->telegramOn();
        $this->cash(['what' => 'NCP', 'what_source' => 'typed']);
        $this->travel(40)->minutes();

        $this->text('£12.50 parking Leeds', 7);
        $offer = $this->lastOffer();
        $this->assertStringStartsWith('You added £12.50 NCP on your phone at 14:05. Add another?', $offer['text']);
        $this->assertSame(['Same receipt: don’t add it', 'A different payment'], collect($offer['rows'])->flatten(1)->pluck('text')->all());

        $this->tap($this->button($offer['rows'], 'Same receipt: don’t add it'), $this->tg);
        $this->assertSame([1, 1250], [ManualExpense::query()->count(), $this->allowable()]);
        $this->assertSame('duplicate', QuickEntry::query()->where('channel', 'telegram')->sole()->status);
    }

    public function test_a_place_in_a_text_never_sets_two_records_apart_and_the_bot_warns_after_adding(): void
    {
        $this->telegramOn();
        // Offered before the phone's entry arrives, so its first question can't know of it.
        $this->text('£12.50 parking Leeds', 8);
        $cash = $this->button($this->lastOffer()['rows'], 'Cash');
        $draftMessage = $this->tg;
        $this->cash(['what' => 'NCP', 'what_source' => 'typed']);

        $answer = $this->tap($cash, $draftMessage);
        $this->assertStringStartsWith('✓ Added as a cash expense: £12.50 parking Leeds', $answer);
        $this->assertStringContainsString('⚠ The same receipt twice? £12.50 on 25 Nov', $this->lastEdit()['text']);

        // "NCP" and "parking Leeds": the place isn't a merchant, so they're asked about, as possible.
        $same = $this->findings()['quick.maybe_same'][0];
        $this->assertSame(Grades::POSSIBLE, $same->grade);
        $this->press('qx', collect($same->actions)->firstWhere('label', 'Same receipt: remove the later one')['id']);
        $this->assertSame([1, 1250], [ManualExpense::query()->count(), $this->allowable()]);
    }

    public function test_cash_then_the_same_receipt_as_the_card_removes_the_cash_expense_and_keeps_the_card_line(): void
    {
        $cash = $this->cash();
        $this->travel(4)->hours();
        $card = $this->card();
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $this->import($line);
        $this->assertSame('attached', $card->fresh()->status);
        $this->press('tx_accept', (string) $line->id);
        $this->assertSame(2500, $this->allowable(), 'counted twice until you answer');

        $same = $this->findings()['quick.maybe_same'][0];
        $this->assertStringContainsString('it was the business card: remove the cash one so it isn’t counted twice', (string) $same->detail);
        $remove = collect($same->actions)->firstWhere('label', 'Same receipt, paid by card: remove the cash one');
        $this->assertSame($card->id.':same:quick:'.$cash->id, $remove['id']);
        $this->press('qx', $remove['id']);

        $this->assertSame(['withdrawn', 'attached'], [$cash->fresh()->status, $card->fresh()->status]);
        $this->assertTrue($this->confirmed($line), 'the card line stays yours');
        $this->assertSame([1250, 0], [$this->allowable(), ManualExpense::query()->count()]);
        $this->assertArrayNotHasKey('quick.maybe_same', $this->findings());
    }

    public function test_a_hand_added_expense_and_a_card_receipt_waiting_remove_the_hand_added_one(): void
    {
        $hand = ManualExpense::query()->create(['spent_on' => '2026-11-25', 'tax_year' => '2026-27', 'description' => 'Parking', 'merchant_key' => 'PARKING', 'paid_with' => 'cash',
            'amount_pence' => -1250]);
        $card = $this->card();

        $same = $this->findings()['quick.maybe_same'][0];
        $remove = collect($same->actions)->firstWhere('label', 'Same receipt, paid by card: remove the one added by hand');
        $this->assertSame($card->id.':same:exp:'.$hand->id, $remove['id']);
        $this->press('qx', $remove['id']);
        $this->assertSame([0, 'waiting'], [ManualExpense::query()->count(), $card->fresh()->status]);
    }

    // ---- your own money moving ---------------------------------------------------------------------------------------

    public function test_a_card_receipt_never_goes_on_money_moved_to_your_own_account_on_import(): void
    {
        $e = $this->card(['amount' => '50.00', 'what' => 'Fuel', 'category_id' => $this->cat('travel_fuel')]);
        Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'is_active' => true]);
        $line = $this->line('2026-11-26', -5000, 'TO REVOLUT', null, ['counterparty' => 'Revolut']);
        $this->import($line);

        $this->assertSame('waiting', $e->fresh()->status);
        $this->assertSame(['transfer'], Allocation::query()->where('bank_transaction_id', $line->id)->pluck('kind')->unique()->values()->all());
    }

    public function test_a_receipt_arriving_after_a_transfer_suggestion_leaves_it_alone(): void
    {
        Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'is_active' => true]);
        $line = $this->line('2026-11-26', -5000, 'TO REVOLUT', null, ['counterparty' => 'Revolut']);
        $this->import($line);
        $this->assertSame('transfer.one_sided', Allocation::query()->where('bank_transaction_id', $line->id)->sole()->method);

        $e = $this->card(['amount' => '50.00', 'what' => 'Fuel', 'category_id' => $this->cat('travel_fuel')]);
        $this->assertSame('waiting', $e->fresh()->status);
        $this->assertSame('transfer.one_sided', Allocation::query()->where('bank_transaction_id', $line->id)->sole()->method);
        $this->assertArrayNotHasKey('receipt.which', $this->findings());
    }

    // ---- foreign receipts ------------------------------------------------------------------------------------------------

    public function test_the_sterling_typed_from_a_foreign_card_receipt_finds_its_pounds_line(): void
    {
        $e = $this->card(['currency' => 'EUR', 'amount' => '15.00', 'sterling' => '12.92']);
        $line = $this->line('2026-11-26', -1292, 'CAFE PARIS');
        $this->import($line);

        $this->assertSame([$line->id, 'attached'], [(int) $e->fresh()->bank_transaction_id, $e->fresh()->status]);
        $this->assertFalse($this->confirmed($line), 'offered, never confirmed by itself');
    }

    public function test_paid_by_personal_card_is_guarded_by_the_pounds_line_of_the_sterling_typed(): void
    {
        $line = $this->line('2026-11-02', -1292, 'CAFE PARIS');
        app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence')], collect([$line]));
        $e = $this->card(['currency' => 'EUR', 'amount' => '15.00', 'sterling' => '12.92', 'spent_on' => '2026-11-01', 'spent_at' => null]);
        QuickEntry::query()->whereKey($e->id)->update(['status' => 'waiting', 'bank_transaction_id' => null, 'attached_by' => null]);

        try {
            $this->press('qx', $e->id.':paid_personal');
            $this->fail('A card payment of that sterling fits it: refused until you say it isn’t it');
        } catch (CardLineFits $x) {
            $this->assertSame($line->id, $x->payment->id);
        }
        $this->assertSame([1292, 0], [$this->allowable(), ManualExpense::query()->count()]);
    }

    public function test_a_foreign_receipt_with_only_a_pounds_line_near_hmrcs_conversion_is_asked_which(): void
    {
        ExchangeRate::query()->create(['currency' => 'EUR', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'source' => 'hmrc_monthly', 'units_per_gbp' => '1.16']);
        $e = $this->card(['currency' => 'EUR', 'amount' => '15.00']);
        $line = $this->line('2026-11-26', -1300, 'CAFE PARIS'); // €15.00 at 1.16 is £12.93: within 3%
        $this->import($line);

        $this->assertSame('waiting', $e->fresh()->status, 'never attached by itself');
        $which = $this->findings()['receipt.which'][0];
        $this->assertSame(Grades::POSSIBLE, $which->grade);
        $this->assertStringContainsString('within 3% of HMRC’s conversion', (string) $which->detail);
        $this->assertSame($e->id.':attach:'.$line->id, $which->actions[0]['id']);
    }

    // ---- fuel and simplified mileage ---------------------------------------------------------------------------------

    public function test_fuel_paid_in_cash_on_the_phone_is_caught_with_simplified_mileage_and_the_bot_says_so(): void
    {
        Vehicle::query()->create(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified']);
        $e = $this->cash(['amount' => '60.00', 'what' => 'Fuel', 'what_source' => 'chip', 'category_id' => $this->cat('travel_fuel')]);

        $drafts = collect((new TravelDoubleClaim)->detect(new CycleContext))->filter(fn ($d) => str_starts_with($d->key, 'double:fuel:'))->values();
        $this->assertCount(1, $drafts);
        $this->assertSame(-6000, $drafts[0]->amountPence);
        $this->assertContains('exp:'.$e->manual_expense_id, $drafts[0]->evidence);

        $this->telegramOn();
        $this->text('£40 fuel', 9);
        $this->assertStringContainsString('Fuel for your own car is covered by mileage; hire cars only.', $this->lastOffer()['text']);
    }

    // ---- the photo's figures on a receipt already on its line ---------------------------------------------------------

    private function readAs(QuickEntry $e, array $receipt): void
    {
        $doc = Document::query()->create(['source' => 'quick', 'kind' => 'receipt', 'original_name' => 'r.jpg', 'mime' => 'image/jpeg', 'size' => 10,
            'sha256' => hash('sha256', 'r'.$e->id), 'stored_path' => 'documents/r'.$e->id.'.jpg', 'status' => 'extracted', 'extracted_at' => now(),
            'extraction' => ['receipt' => $receipt]]);
        QuickEntry::query()->whereKey($e->id)->update(['document_id' => $doc->id, 'photo_status' => 'stored']);
        app(QuickReadCheck::class)->afterRead($doc->fresh());
    }

    public function test_using_the_photos_amount_lets_go_of_a_matched_line_it_no_longer_fits_and_matches_again(): void
    {
        $old = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $e = $this->card();
        $this->assertSame([$old->id, 'attached'], [(int) $e->fresh()->bank_transaction_id, $e->fresh()->status]);
        $right = $this->line('2026-11-26', -1520, 'NCP LEEDS');
        $this->readAs($e->fresh(), ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1520, 'currency' => 'GBP']);

        $this->press('qx', $e->id.':use_read:amount');

        $e->refresh();
        $this->assertSame([1520, 'attached', $right->id], [$e->amount_minor, $e->status, (int) $e->bank_transaction_id]);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $old->id)->count(), 'its offer on the £12.50 line went');
        $this->assertSame(1, Allocation::query()->where('bank_transaction_id', $right->id)->where('method', 'receipt.quick')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'quick_entry.detached')->count());
    }

    public function test_using_the_photos_amount_on_a_line_you_decided_asks_whether_it_is_still_this_payment(): void
    {
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $e = $this->card();
        $this->press('tx_accept', (string) $line->id);
        $this->readAs($e->fresh(), ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1520, 'currency' => 'GBP']);
        $this->press('qx', $e->id.':use_read:amount');

        $this->assertSame([1520, 'attached'], [$e->fresh()->amount_minor, $e->fresh()->status], 'your decision on the line is never undone by itself');
        $ask = $this->findings()['quick.line_differs'][0];
        $this->assertStringStartsWith('Still this payment? Your receipt now says £15.20', $ask->title);
        $this->press('qx', $ask->actions[0]['id']); // Not this payment's receipt
        $this->assertSame('waiting', $e->fresh()->status);
        $this->assertTrue($this->confirmed($line));
        $this->assertSame(1250, $this->allowable());
    }

    public function test_a_line_you_chose_that_never_fitted_is_not_asked_about(): void
    {
        $e = $this->card(['amount' => '40.00']);
        $line = $this->line('2026-11-26', -4600, 'RESTAURANT'); // a tip on top
        $this->press('qx', $e->id.':attach:'.$line->id);
        $this->assertArrayNotHasKey('quick.line_differs', $this->findings());
    }

    // ---- the same photo, other figures -------------------------------------------------------------------------------

    public function test_the_same_photo_with_another_amount_is_held_and_asked_about_never_called_already_added(): void
    {
        [, $photo] = $this->photo();
        $this->cash(['photo' => $photo]);
        $f = $this->fields(['photo' => $photo, 'amount' => '60.00', 'paid_with' => 'business', 'account_id' => $this->starling->id, 'what' => 'Fuel',
            'category_id' => $this->cat('travel_fuel')]);
        $this->send($f)->assertCreated()->assertJsonPath('entry.state', 'held')->assertJsonPath('entry.message', 'Kept, not added: the same photo as another entry');

        $held = $this->entry($f['client_key']);
        $ask = $this->findings()['quick.held'][0];
        $this->assertStringStartsWith('Same photo as £12.50 parking (Cash, 25 Nov), but £60.00 business card or account here: which is right?', (string) $ask->detail);
        $this->press('qx', $held->id.':add_held'); // A different payment: add it
        $this->assertSame('waiting', $held->fresh()->status);

        // The same photo with the same figures is a plain duplicate, said so.
        $again = $this->fields(['photo' => $photo]);
        $this->send($again)->assertCreated()->assertJsonPath('entry.state', 'duplicate')
            ->assertJsonPath('entry.message', 'Not added: the same photo as £12.50 parking, added on 25 Nov at 14:05');
        $this->assertSame([1, 1250], [ManualExpense::query()->count(), $this->allowable()]);
    }

    // ---- audit ----------------------------------------------------------------------------------------------------------

    public function test_a_telegram_drafts_photo_is_in_the_received_record_and_a_kept_copys_kind_change_is_audited(): void
    {
        $doc = Document::query()->create(['source' => 'telegram', 'kind' => 'unknown', 'original_name' => 'p.jpg', 'mime' => 'image/jpeg', 'size' => 10, 'sha256' => hash('sha256', 'p'),
            'stored_path' => 'documents/p.jpg', 'status' => 'new']);
        $draft = QuickEntry::query()->create(['client_key' => 'tg:42:5', 'channel' => 'telegram', 'status' => 'draft', 'spent_on' => '2026-11-25', 'amount_minor' => 1250,
            'currency' => 'GBP', 'what' => 'parking', 'what_source' => 'telegram', 'category_id' => $this->cat('travel_parking'), 'document_id' => $doc->id,
            'photo_status' => 'stored', 'photo_sha256' => $doc->sha256, 'captured_at' => now()]);
        QuickEntries::asYou(fn () => app(QuickEntries::class)->submitDraft($draft, 'cash'), 'telegram');

        $received = AuditEvent::query()->where('action', 'quick_entry.received')->sole();
        $this->assertSame([$doc->id, 'stored'], [(int) $received->after['document_id'], $received->after['photo_status']]);
        $this->assertSame([$doc->id, 'stored'], [(int) $draft->fresh()->document_id, $draft->fresh()->photo_status]);

        // A copy kept before (an emailed receipt): becoming your receipt is audited.
        [$file, $photo] = $this->photo();
        $bytes = (string) file_get_contents($file->getRealPath());
        $kept = app(DocumentIntake::class)->store($bytes, 'mail.jpg', 'image/jpeg', 'gmail', ['kind' => 'other', 'read' => false]);
        $f = $this->fields(['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        $this->assertSame('receipt', $kept->fresh()->kind);
        $changed = AuditEvent::query()->where('action', 'document.updated')->where('subject_id', $kept->id)->sole();
        $this->assertSame([['kind' => 'other'], ['kind' => 'receipt']], [$changed->before, $changed->after]);
    }

    public function test_answers_that_write_still_refuse_a_locked_year(): void
    {
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $e = $this->card();
        TaxYear::track('2026-27')->update(['locked_at' => now()]);
        $this->readAs($e->fresh(), ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1520, 'currency' => 'GBP']);
        try {
            $this->press('qx', $e->id.':use_read:amount');
        } catch (InvalidArgumentException) {
            // refused: fine
        }
        $this->assertSame(1, Allocation::query()->where('bank_transaction_id', $line->id)->count(), 'nothing on a locked year’s line is taken back');
        $this->assertSame([$line->id, 'attached'], [(int) $e->fresh()->bank_transaction_id, $e->fresh()->status], 'and the receipt stays with its offer');
    }
}
