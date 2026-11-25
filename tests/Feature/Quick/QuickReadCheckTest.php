<?php

namespace Tests\Feature\Quick;

use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\QuickReadCheck;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Jobs\ReadDocument;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The photo read with AI against what you typed: nothing changes without your answer, "Keep" is never asked again,
 * "Use" corrects the expense as yours, a chip's What takes the name read but what you typed never changes, and a
 * read refused at the AI cap is read again by ledger:receipts while matching goes on without it.
 */
class QuickReadCheckTest extends TestCase
{
    use QuickHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuick();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownQuick();
        parent::tearDown();
    }

    /** An entry sent with its photo, then "read" with this receipt. */
    private int $photos = 0;

    private function read(array $fields, ?array $receipt): QuickEntry
    {
        [$file, $photo] = $this->photo('receipt.jpg', 40 + ++$this->photos, 60); // a photo of its own each time
        $f = $this->fields($fields + ['photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        $doc = Document::query()->latest('id')->first();
        $doc->update(['status' => 'extracted', 'extracted_at' => now(), 'extraction' => ['receipt' => $receipt]]);
        app(QuickReadCheck::class)->afterRead($doc->fresh());

        return $this->entry($f['client_key']);
    }

    private function press(QuickEntry $e, string $verb): string
    {
        $id = $e->id.':'.$verb;

        return app(TodoActions::class)->run('qx', $id, null, Fingerprints::of('qx', $id));
    }

    public function test_the_same_amount_agrees_and_nothing_is_asked(): void
    {
        $e = $this->read([], ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1250, 'currency' => 'GBP']);

        $this->assertSame('agrees', $e->read_check['status']);
        $this->assertArrayNotHasKey('quick.photo_differs', $this->findings());
    }

    public function test_another_amount_is_asked_about_and_use_corrects_the_expense_as_yours(): void
    {
        $e = $this->read([], ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1520, 'currency' => 'GBP']);

        $d = $this->findings()['quick.photo_differs'][0];
        $this->assertSame('The photo looks like £15.20; you typed £12.50', $d->title);
        $this->assertSame(['Use £15.20', 'Keep £12.50'], array_column($d->actions, 'label'));
        $this->assertSame(-1250, ManualExpense::query()->sole()->amount_pence, 'Nothing changes without your answer');

        $this->press($e, 'use_read:amount');

        $x = ManualExpense::query()->sole();
        $this->assertSame(-1520, $x->amount_pence);
        $a = Allocation::query()->where('manual_expense_id', $x->id)->sole();
        $this->assertSame([-1520, 'you', true], [$a->amount_pence, $a->decided_by, $a->confirmed_at !== null]);
        $this->assertSame(1520, $e->fresh()->amount_minor);
        $this->assertTrue(AuditEvent::query()->where('action', 'manual_expense.corrected')->where('subject_id', $x->id)->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'quick_entry.read_answered')->where('subject_id', $e->id)->exists());
        $this->assertArrayNotHasKey('quick.photo_differs', $this->findings());
    }

    public function test_keep_is_remembered_for_that_read(): void
    {
        $e = $this->read([], ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1520, 'currency' => 'GBP']);
        $this->press($e, 'keep_mine:amount');

        app(QuickReadCheck::class)->afterRead(Document::query()->sole()); // read again, the same answer

        $this->assertArrayNotHasKey('quick.photo_differs', $this->findings());
        $this->assertSame(-1250, ManualExpense::query()->sole()->amount_pence);
    }

    public function test_the_name_read_fills_a_chips_what_but_never_what_you_typed(): void
    {
        $chip = $this->read(['what' => 'Parking', 'what_source' => 'chip'], ['merchant' => 'NCP Leeds', 'date' => '2026-11-25', 'amount_minor' => 1250, 'currency' => 'GBP']);
        $this->assertSame(['NCP Leeds', 'photo'], [$chip->what, $chip->what_source]);
        $this->assertSame('NCP Leeds', ManualExpense::query()->findOrFail($chip->manual_expense_id)->description);

        $typed = $this->read(['what' => 'Car park at the arena', 'what_source' => 'typed', 'amount' => '7.00'], ['merchant' => 'Q-Park', 'date' => '2026-11-25', 'amount_minor' => 700, 'currency' => 'GBP']);
        $this->assertSame(['Car park at the arena', 'typed'], [$typed->what, $typed->what_source]);
        $this->assertSame('Car park at the arena', ManualExpense::query()->findOrFail($typed->manual_expense_id)->description);
    }

    public function test_it_was_in_euros_works_the_sterling_out_again(): void
    {
        ExchangeRate::query()->create(['currency' => 'EUR', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'source' => 'hmrc_monthly', 'units_per_gbp' => '1.2']);
        $e = $this->read(['amount' => '15.00', 'category_id' => $this->cat('travel_taxi')], ['merchant' => 'Taxi Berlin', 'date' => '2026-11-25', 'amount_minor' => 1500, 'currency' => 'EUR']);
        $d = $this->findings()['quick.photo_differs'][0];
        $this->assertSame(['It was €15.00', 'Keep £15.00'], array_column($d->actions, 'label'));

        $this->press($e, 'use_read:currency');

        $x = ManualExpense::query()->sole();
        $this->assertSame([-1250, -1500, 'EUR', 'hmrc_monthly'], [$x->amount_pence, $x->original_amount_minor, $x->original_currency, $x->fx_source]);
        $this->assertSame(-1250, (int) Allocation::query()->where('manual_expense_id', $x->id)->sum('amount_pence'));
    }

    public function test_no_receipt_in_the_photo_says_so(): void
    {
        $e = $this->read([], null);

        $this->assertSame('not_receipt', $e->read_check['status']);
        $this->assertStringStartsWith('Expend couldn’t see a receipt in this photo', $e->read_check['note']);
    }

    public function test_a_read_refused_at_the_cap_is_read_again_by_the_nightly_command_and_matching_goes_on(): void
    {
        Setting::put('ai_extraction', 'on');
        Setting::put('anthropic_api_key', 'test-key');
        [$file, $photo] = $this->photo();
        $f = $this->fields(['paid_with' => 'business', 'photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        Document::query()->update(['status' => 'failed', 'error' => 'This month’s AI spending cap ($5) is reached. Raise it in Settings › AI to continue.']);
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        Queue::fake();

        Artisan::call('ledger:receipts');

        Queue::assertPushedOn(QuickEntries::READ_QUEUE, ReadDocument::class);
        $this->assertSame($line->id, (int) $this->entry($f['client_key'])->bank_transaction_id, 'Matched on what you typed, read or not');
    }
}
