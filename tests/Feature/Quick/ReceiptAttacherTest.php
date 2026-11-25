<?php

namespace Tests\Feature\Quick;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\ReceiptUnmatched;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Quick\CardLineFits;
use App\Domain\Quick\QuickEntries;
use App\Domain\Quick\ReceiptAttacher;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\LinkProvenance;
use App\Models\ManualExpense;
use App\Models\QuickEntry;
use App\Models\TaxYear;
use App\Models\Veto;
use App\Models\WorkJob;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Business-card receipts and their bank lines: attached by themselves only when exactly one line fits and no other
 * receipt fits it, offered on the line for one tap (never confirmed by themselves), matched in the receipt's own
 * currency, left for you when it isn't sure, never into a locked year, moved when an import is done again, and asked
 * about when no line ever comes.
 */
class ReceiptAttacherTest extends TestCase
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

    /** A business-card receipt sent from the phone. */
    private function card(array $over = []): QuickEntry
    {
        $f = $this->fields(array_merge(['paid_with' => 'business', 'account_id' => $this->starling->id], $over));
        $this->send($f)->assertCreated();

        return $this->entry($f['client_key']);
    }

    private function press(QuickEntry $e, string $verb): string
    {
        $id = $e->id.':'.$verb;

        return app(TodoActions::class)->run('qx', $id, null, Fingerprints::of('qx', $id));
    }

    public function test_a_receipt_whose_line_is_in_goes_on_it_and_offers_your_choices_for_one_tap(): void
    {
        $job = WorkJob::query()->create(['title' => 'Leeds Arena Awards', 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-25', 'status' => 'booked']);
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $e = $this->card(['work_job_id' => $job->id]);

        $e->refresh();
        $this->assertSame(['attached', $line->id, 'matcher'], [$e->status, (int) $e->bank_transaction_id, $e->attached_by]);
        $a = Allocation::query()->where('bank_transaction_id', $line->id)->sole();
        $this->assertSame(['business_expense', $this->cat('travel_parking'), $job->id, 'matcher', false], [$a->kind, (int) $a->category_id, (int) $a->work_job_id, $a->decided_by, $a->confirmed_at !== null]);
        $this->assertStringStartsWith('From your receipt photo, 25 Nov 14:05: Parking, tolls and road charges, Leeds Arena Awards', (string) $a->reason);
        $this->assertSame('suggested', $line->fresh()->review_status);
        $this->assertTrue(AuditEvent::query()->where('action', 'quick_entry.attached')->where('subject_id', $e->id)->where('actor', 'matcher')->exists());

        // One tap in the Inbox makes it yours.
        app(AllocationWriter::class)->confirm($line->fresh());
        $this->assertSame('confirmed', $line->fresh()->review_status);
        $this->assertSame(0, ManualExpense::query()->count(), 'Counted once: on the line');
    }

    public function test_the_line_says_why_once_quick_refs_and_receipt_quick_are_known(): void
    {
        $this->needsIntegration();
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->card();

        $a = Allocation::query()->where('bank_transaction_id', $line->id)->sole();
        $this->assertSame('receipt.quick', $a->method);
        $this->assertContains('quick:'.$e->id, $a->evidence);
        $this->assertSame('tx:'.$line->id, LinkProvenance::query()->where('link', 'receipt_payment')->where('subject_ref', 'quick:'.$e->id)->value('object_ref'));
    }

    public function test_a_receipt_in_euros_goes_only_with_a_line_that_was_in_euros(): void
    {
        $pounds = $this->line('2026-11-25', -1500, 'TAXI BERLIN');
        $e = $this->card(['currency' => 'EUR', 'amount' => '15.00', 'category_id' => $this->cat('travel_taxi')]);
        $this->assertSame('waiting', $e->fresh()->status, '€15.00 never goes with £15.00');

        $euros = $this->line('2026-11-25', -1290, 'TAXI BERLIN 2', null, ['original_amount_minor' => -1500, 'original_currency' => 'EUR']);
        app(ReceiptAttacher::class)->forLines([$euros]);

        $this->assertSame($euros->id, (int) $e->fresh()->bank_transaction_id);
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $pounds->id)->first());
    }

    public function test_two_lines_that_fit_are_left_for_you_to_choose(): void
    {
        $a = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $b = $this->line('2026-11-26', -1250, 'Q-PARK LEEDS');
        $e = $this->card();

        $this->assertSame('waiting', $e->fresh()->status);
        $which = $this->findings()['receipt.which'][0];
        $this->assertSame([$e->id.':attach:'.$a->id, $e->id.':attach:'.$b->id], array_column($which->actions, 'id'));

        $this->press($e, 'attach:'.$b->id);
        $e->refresh();
        $this->assertSame(['attached', $b->id, 'you'], [$e->status, (int) $e->bank_transaction_id, $e->attached_by]);
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $a->id)->first());
    }

    public function test_two_receipts_for_one_line_are_left_for_you_too(): void
    {
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS');
        $first = $this->card(['spent_on' => '2026-11-27', 'spent_at' => null]);
        $this->assertSame('attached', $first->fresh()->status);

        // A second receipt of the same amount: the line holds the first, so this one asks whether it's a second copy.
        $second = $this->card(['spent_on' => '2026-11-25']);
        $this->assertSame('waiting', $second->fresh()->status);
        $copy = $this->findings()['receipt.second_copy'][0];
        $this->assertStringContainsString($second->id.':same_copy', implode(',', array_column($copy->actions, 'id')));

        $this->press($second, 'same_copy');
        $this->assertSame(['withdrawn', 'attached'], [$second->fresh()->status, $first->fresh()->status]);
    }

    public function test_a_line_you_decided_keeps_your_decision_and_asks_only_when_the_receipt_differs(): void
    {
        $same = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_parking')], collect([$same]));
        $e = $this->card();
        $this->assertSame([$same->id, 'attached'], [(int) $e->fresh()->bank_transaction_id, $e->fresh()->status]);
        $this->assertArrayNotHasKey('quick.line_differs', $this->findings());

        $other = $this->line('2026-11-25', -4000, 'SHELL M62');
        app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence')], collect([$other]));
        $fuel = $this->card(['amount' => '40.00', 'what' => 'Fuel', 'category_id' => $this->cat('travel_fuel')]);
        $this->assertSame('attached', $fuel->fresh()->status);
        $this->assertSame('you', Allocation::query()->where('bank_transaction_id', $other->id)->sole()->decided_by, 'Your decision stays');
        $this->assertArrayHasKey('quick.line_differs', $this->findings());

        $this->press($fuel->fresh(), 'use_receipt');
        $a = Allocation::query()->where('bank_transaction_id', $other->id)->sole();
        $this->assertSame([$this->cat('travel_fuel'), true], [(int) $a->category_id, $a->is_manual]);
        $this->assertArrayNotHasKey('quick.line_differs', $this->findings());
    }

    public function test_keep_the_line_as_it_is_closes_the_question_for_good(): void
    {
        $other = $this->line('2026-11-25', -4000, 'SHELL M62');
        app(DecisionService::class)->apply(['kind' => 'business_expense', 'category_id' => $this->cat('travel_subsistence')], collect([$other]));
        $fuel = $this->card(['amount' => '40.00', 'what' => 'Fuel', 'category_id' => $this->cat('travel_fuel')]);

        $this->press($fuel->fresh(), 'keep_line');

        $this->assertArrayNotHasKey('quick.line_differs', $this->findings());
        $this->assertSame($this->cat('travel_subsistence'), (int) Allocation::query()->where('bank_transaction_id', $other->id)->sole()->category_id);
    }

    public function test_not_this_payments_receipt_takes_back_the_suggestion_and_never_attaches_that_pair_again(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->card();
        $this->assertSame('attached', $e->fresh()->status);

        $this->post('/quick/entries/'.$e->id.'/detach')->assertSessionHas('success');

        $e->refresh();
        $this->assertSame(['waiting', null], [$e->status, $e->bank_transaction_id]);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $line->id)->count(), 'The matcher’s suggestion went');
        app(ReceiptAttacher::class)->forLines([$line]);
        app(ReceiptAttacher::class)->sweep();
        $this->assertSame('waiting', $e->fresh()->status, 'Never attached to that line again');
        if (ReceiptAttacher::cites()) {
            $this->assertTrue(Veto::query()->where('relation', 'receipt_payment')->where('subject_ref', 'quick:'.$e->id)->where('object_ref', 'tx:'.$line->id)->exists());
        }
    }

    public function test_a_confirmed_suggestion_stays_when_the_receipt_goes(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->card();
        app(AllocationWriter::class)->confirm($line->fresh());

        app(QuickEntries::class)->withdraw($e->client_key);

        $this->assertTrue(Allocation::query()->where('bank_transaction_id', $line->id)->sole()->confirmed_at !== null);
    }

    public function test_a_withdrawn_receipts_open_suggestion_is_taken_back(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->card();

        app(QuickEntries::class)->withdraw($e->client_key);

        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $line->id)->count());
        $this->assertSame('unreviewed', $line->fresh()->review_status);
    }

    public function test_the_nightly_sweep_takes_back_a_suggestion_whose_receipt_is_no_longer_on_the_line(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->card();
        $this->assertSame(1, Allocation::query()->where('bank_transaction_id', $line->id)->count());
        // The receipt left the line some other way (an old release, a row changed by hand): its suggestion lingers.
        QuickEntry::query()->whereKey($e->id)->update(['status' => 'set_aside', 'bank_transaction_id' => null]);

        $r = app(ReceiptAttacher::class)->sweep();

        $this->assertSame(1, $r['taken_back']);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $line->id)->count());
    }

    public function test_a_line_another_receipt_got_first_leaves_this_one_waiting(): void
    {
        $line = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $a = $this->card();
        $b = QuickEntry::query()->create(['client_key' => $this->key(), 'status' => 'waiting', 'paid_with' => 'business', 'spent_on' => '2026-11-25', 'amount_minor' => 1250,
            'currency' => 'GBP', 'category_id' => $this->cat('travel_parking'), 'channel' => 'phone']);

        // Both raced for the line: the one that locked it second sees it taken.
        $this->assertSame('second_copy', app(ReceiptAttacher::class)->attach($b, $line, 'matcher'));

        $this->assertSame(1, QuickEntry::query()->where('bank_transaction_id', $line->id)->count());
        $this->assertSame([$a->id, 'waiting'], [(int) QuickEntry::query()->where('bank_transaction_id', $line->id)->value('id'), $b->fresh()->status]);
    }

    public function test_an_import_done_again_moves_the_receipt_to_the_new_line_once(): void
    {
        $old = $this->line('2026-11-25', -1250, 'NCP LEEDS');
        $e = $this->card();
        $this->assertSame($old->id, (int) $e->fresh()->bank_transaction_id);

        // Undo the import (the line is reversed), then import the statement again: a new line, a new id.
        $old->forceFill(['state' => 'reversed'])->saveQuietly();
        Allocation::query()->where('bank_transaction_id', $old->id)->update(['voided_at' => now()]);
        $this->assertSame(1, QuickEntry::query()->waiting()->count(), 'On a line that isn’t active: waiting again');
        $new = $this->line('2026-11-25', -1250, 'NCP LEEDS', null, ['fingerprint' => hash('sha256', 'again')]);
        app(ReceiptAttacher::class)->forLines([$new]);

        $this->assertSame($new->id, (int) $e->fresh()->bank_transaction_id);
        $this->assertTrue(AuditEvent::query()->where('action', 'quick_entry.moved')->where('subject_id', $e->id)->exists());

        // The old import restored: the receipt stays where it is.
        BankTransaction::query()->withoutGlobalScope('active')->whereKey($old->id)->update(['state' => 'active']);
        app(ReceiptAttacher::class)->forLines([BankTransaction::query()->find($old->id)]);
        $this->assertSame($new->id, (int) $e->fresh()->bank_transaction_id);
        $this->assertSame(1, QuickEntry::query()->whereNotNull('bank_transaction_id')->count());
    }

    public function test_nothing_goes_on_a_line_in_a_locked_year(): void
    {
        $line = $this->line('2026-04-04', -1250, 'NCP LEEDS');
        TaxYear::track('2025-26')->update(['locked_at' => now()]);
        $e = $this->card(['spent_on' => '2026-04-04']);

        $this->assertSame('waiting', $e->fresh()->status);
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $line->id)->count());
    }

    public function test_a_receipt_with_no_line_is_asked_about_once_the_statement_runs_past_it_with_near_misses_first(): void
    {
        $e = $this->card(['spent_on' => '2026-11-20']);
        $this->line('2026-11-20', -1400, 'NCP LEEDS'); // a £14.00 line: a tip, a pre-authorisation?
        $this->assertArrayNotHasKey('receipt.waiting', $this->findings(), 'The statement runs to 20 Nov only');

        $this->line('2026-11-27', -999, 'TESCO'); // the statement now runs to 27 Nov: 7 days past
        $waiting = $this->findings()['receipt.waiting'][0];
        $this->assertStringContainsString('Your Starling statement runs to 27 Nov', (string) $waiting->detail);
        $this->assertStringStartsWith('It’s this one: Starling, 20 Nov, £14.00 (a tip or a pre-authorisation?)', $waiting->actions[0]['label']);
        $this->assertSame('None of these', $waiting->actions[array_key_last($waiting->actions)]['label']);

        $this->press($e, 'none');
        $labels = array_column($this->findings()['receipt.waiting'][0]->actions, 'label');
        $this->assertSame(['Paid by personal card', 'Paid in cash', 'Not a business cost'], $labels);

        $this->press($e->fresh(), 'paid_cash');
        try {
            $this->press($e->fresh(), 'paid_cash');
        } catch (ModelNotFoundException) {
            // pressed twice: dealt with already
        }
        $this->assertSame(1, ManualExpense::query()->count());
        $this->assertSame('cash', ManualExpense::query()->sole()->paid_with);
    }

    public function test_after_21_days_it_asks_whatever_the_statements_say(): void
    {
        $this->card(['spent_on' => '2026-11-01']);
        $this->assertArrayHasKey('receipt.waiting', $this->findings());
    }

    public function test_a_phone_photo_is_never_reported_as_a_receipt_with_no_payment(): void
    {
        [$file, $photo] = $this->photo();
        $f = $this->fields(['paid_with' => 'business', 'photo' => $photo]);
        $this->send($f)->assertCreated();
        $this->sendPhoto($f['client_key'], $file)->assertOk();
        $doc = $this->entry($f['client_key'])->document;
        $doc->update(['extraction' => ['receipt' => ['merchant' => 'NCP', 'amount_minor' => 1250, 'currency' => 'GBP', 'date' => '2026-11-25']], 'status' => 'extracted']);
        $this->line('2026-11-01', -100, 'OLD'); // a statement covering the dates

        $this->assertSame([], (new ReceiptUnmatched)->detect(new CycleContext));
    }

    public function test_cash_is_refused_while_a_card_line_fits_that_you_havent_turned_down(): void
    {
        $line = $this->line('2026-11-26', -1250, 'NCP LEEDS', $this->starling);
        $e = QuickEntry::query()->create(['client_key' => $this->key(), 'status' => 'waiting', 'paid_with' => 'business', 'spent_on' => '2026-11-25', 'amount_minor' => 1250,
            'currency' => 'GBP', 'category_id' => $this->cat('travel_parking'), 'channel' => 'phone']);

        try {
            $this->press($e, 'paid_cash');
            $this->fail('Refused while a card payment fits');
        } catch (CardLineFits $ex) {
            $this->assertSame('A card payment fits this receipt: Starling, 26 Nov, £12.50. Is it this one?', $ex->getMessage());
        }
        $this->assertSame(0, ManualExpense::query()->count());

        $this->press($e->fresh(), 'not_card:'.$line->id);
        $this->press($e->fresh(), 'paid_cash');
        $this->assertSame(1, ManualExpense::query()->count());
    }
}
