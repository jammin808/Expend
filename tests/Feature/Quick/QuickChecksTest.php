<?php

namespace Tests\Feature\Quick;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\DupeCrossSource;
use App\Domain\Intel\Detectors\QuickEntryChecks;
use App\Domain\Intel\Grades;
use App\Domain\Ledger\DecisionService;
use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\ReceiptAttacher;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Veto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Never counted twice: a cost added as cash (or a personal card) that a card line of the same amount also shows
 * (quick.maybe_card, which needs no merchant words: a chip says only "Parking"), and the same receipt added twice
 * (quick.maybe_same). Each answer is remembered. The checks only read.
 */
class QuickChecksTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    private function press(QuickEntry $e, string $verb): string
    {
        $id = $e->id.':'.$verb;

        return app(TodoActions::class)->run('qx', $id, null, Fingerprints::of('qx', $id));
    }

    public function test_a_cash_parking_chip_and_a_card_line_the_next_day_ask_whether_it_was_the_card(): void
    {
        $f = $this->fields();
        $this->send($f)->assertCreated();
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS ARENA');

        $card = $this->findings()['quick.maybe_card'][0];
        $this->assertSame('Added as cash, and a card payment of the same amount: £12.50, Starling, 26 Nov (NCP LEEDS ARENA)', $card->title);
        $this->assertSame([$this->entry($f['client_key'])->id.':to_card:'.$line->id, $this->entry($f['client_key'])->id.':not_card:'.$line->id], array_column($card->actions, 'id'));
        $this->assertNull(DupeCrossSource::merchantMatch('PARKING', 'NCP LEEDS ARENA'), 'Merchant words could never have paired these');

        $this->press($this->entry($f['client_key']), 'to_card:'.$line->id);

        $e = $this->entry($f['client_key']);
        $this->assertSame(['attached', $line->id, 'you', 'business'], [$e->status, (int) $e->bank_transaction_id, $e->attached_by, $e->paid_with]);
        $this->assertSame(0, ManualExpense::query()->count(), 'The cash expense went');
        $a = Allocation::query()->where('bank_transaction_id', $line->id)->sole();
        $this->assertSame([true, $this->cat('travel_parking')], [$a->is_manual, (int) $a->category_id], 'Decided as yours, with the receipt’s category');
        $this->assertArrayNotHasKey('quick.maybe_card', $this->findings());
    }

    public function test_the_reply_warns_when_a_card_line_is_in_already(): void
    {
        $this->line('2026-11-25', -1250, 'NCP LEEDS ARENA');
        $this->send($this->fields())->assertCreated()->assertJsonPath('warnings.0.code', 'quick.maybe_card')
            ->assertJsonPath('warnings.0.message', 'Looks like a Starling, 25 Nov, £12.50 card payment: check To do');
    }

    public function test_two_payments_is_remembered_and_never_asked_again(): void
    {
        $f = $this->fields(['paid_with' => 'personal_card']);
        $this->send($f)->assertCreated();
        $line = $this->line('2026-11-25', -1250, 'COSTA');

        $this->press($this->entry($f['client_key']), 'not_card:'.$line->id);

        $this->assertArrayNotHasKey('quick.maybe_card', $this->findings());
        $this->assertSame(1, ManualExpense::query()->count());
        if (ReceiptAttacher::cites()) {
            $this->assertTrue(Veto::query()->where('relation', 'receipt_payment')->where('method', 'quick.maybe_card')->where('object_ref', 'tx:'.$line->id)->exists());
        }
    }

    public function test_lines_decided_as_personal_or_on_a_cash_account_are_not_asked_about(): void
    {
        $this->send($this->fields())->assertCreated();
        $personal = $this->line('2026-11-25', -1250, 'TESCO');
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$personal]));
        $cash = Account::query()->create(['name' => 'Petty cash', 'kind' => 'cash', 'is_active' => true]);
        $this->line('2026-11-25', -1250, 'CASH', $cash);

        $this->assertArrayNotHasKey('quick.maybe_card', $this->findings());
    }

    public function test_the_same_receipt_from_the_phone_and_telegram_is_asked_about_and_the_later_one_removed(): void
    {
        $phone = $this->fields(['what' => 'Parking', 'what_source' => 'chip']);
        $this->send($phone)->assertCreated();
        $this->travel(20)->minutes();
        $tg = QuickEntry::query()->create(['client_key' => 'tg:42:9', 'channel' => 'telegram', 'status' => 'draft', 'spent_on' => '2026-11-25', 'amount_minor' => 1250,
            'currency' => 'GBP', 'what' => 'NCP Leeds', 'what_source' => 'telegram', 'category_id' => $this->cat('travel_parking'), 'captured_at' => now()]);
        app(QuickEntries::class)->submitDraft($tg, 'cash');

        $same = $this->findings()['quick.maybe_same'][0];
        $this->assertSame('The same receipt twice? £12.50 on 25 Nov', $same->title);
        $later = collect($same->actions)->firstWhere('label', 'Same receipt: remove the later one');
        $this->assertStringEndsWith(':same:quick:'.$tg->id, $later['id']);

        app(TodoActions::class)->run('qx', $later['id'], null, Fingerprints::of('qx', $later['id']));

        $this->assertSame(['added', 'withdrawn'], [$this->entry($phone['client_key'])->status, $tg->fresh()->status]);
        $this->assertSame(1, ManualExpense::query()->count());
    }

    public function test_two_merchants_read_from_the_photos_that_differ_are_two_payments_and_typed_words_only_make_it_less_sure(): void
    {
        $a = $this->fields(['what' => 'NCP', 'what_source' => 'typed']);
        $b = $this->fields(['what' => 'Q-Park', 'what_source' => 'typed']);
        $this->send($a)->assertCreated();
        $this->send($b)->assertCreated();

        // What you typed may name a place or the kind of cost ("parking Leeds"): asked about, as possible.
        $same = $this->findings()['quick.maybe_same'][0];
        $this->assertSame(Grades::POSSIBLE, $same->grade);

        // Read from the photos: two merchants that plainly differ are two payments.
        foreach ([[$a, 'NCP Leeds'], [$b, 'Q-Park Wellington Place']] as [$f, $merchant]) {
            QuickEntry::query()->whereKey($this->entry($f['client_key'])->id)->update(['read_check' => json_encode(['status' => 'agrees', 'read' => ['merchant' => $merchant]])]);
        }
        $this->assertArrayNotHasKey('quick.maybe_same', $this->findings());
    }

    public function test_a_hand_added_expense_of_the_same_amount_is_compared_too_and_two_payments_settles_it(): void
    {
        $f = $this->fields();
        $this->send($f)->assertCreated();
        $hand = ManualExpense::query()->create(['spent_on' => '2026-11-25', 'tax_year' => '2026-27', 'description' => 'Parking', 'merchant_key' => 'PARKING', 'paid_with' => 'cash', 'amount_pence' => -1250]);

        $same = $this->findings()['quick.maybe_same'][0];
        $two = collect($same->actions)->firstWhere('label', 'Two payments');
        $this->assertSame($this->entry($f['client_key'])->id.':not_same:exp:'.$hand->id, $two['id']);

        app(TodoActions::class)->run('qx', $two['id'], null, Fingerprints::of('qx', $two['id']));
        $this->assertArrayNotHasKey('quick.maybe_same', $this->findings());
    }

    public function test_the_checks_only_read(): void
    {
        $this->send($this->fields())->assertCreated();
        $this->line('2026-11-26', -1250, 'NCP LEEDS ARENA');
        $this->send($this->fields(['paid_with' => 'business', 'spent_on' => '2026-10-01', 'amount' => '9.99']))->assertCreated();
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        (new QuickEntryChecks)->detect(new CycleContext);

        $this->assertSame([], $writes);
    }
}
