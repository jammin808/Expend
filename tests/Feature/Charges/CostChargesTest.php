<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostUnits;
use App\Domain\Intel\AuditContext;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\CostCharge;
use App\Models\TaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S5: your decisions about costs (CostCharges). Yours only and audited. The newest decision across a cost's
 * ref and its aliases wins, counting a booking decided 'recharge'. A decision whose cost has gone stays with the
 * payment and is never applied to the new job. "Recharge to Acme" writes only cost_charges, plus the cost's client
 * when its payment is confirmed in an open year, and says why when it isn't.
 */
class CostChargesTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCharges();
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    private function charges(): CostCharges
    {
        return app(CostCharges::class);
    }

    private function later(int $minutes): void
    {
        Carbon::setTestNow(now()->addMinutes($minutes));
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes($minutes));
    }

    public function test_each_decision_is_yours_audited_and_kept_on_its_row(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $tx = $this->tx(-4500);
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)]);
        $ref = "tx:{$tx->id}#j{$job->id}";

        $row = $this->charges()->decide($ref, 'recharge', null, null, 'Their crew rate covers it');

        $this->assertSame(["tx:{$tx->id}", 'recharge', null, 'Their crew rate covers it'], [$row->subject_ref, $row->charge, $row->client_id, $row->note]);
        $event = AuditEvent::query()->where('action', 'cost.charge_set')->sole();
        $this->assertSame([(int) $event->id, 'you', 'CostCharge', $ref], [$row->audit_event_id, $event->actor, $event->subject_type, $event->after['cost_ref']]);
        $this->assertNull($event->before);

        $this->charges()->decide($ref, 'no_charge');
        $this->assertSame(['no_charge', 1], [CostCharge::query()->sole()->charge, CostCharge::query()->count()]);
        $this->assertSame('recharge', AuditEvent::query()->where('action', 'cost.charge_set')->latest('id')->first()->before['charge']);

        // "It was on INV-0031": only an issued invoice.
        $draft = $this->invoice($job, [], 'draft');
        try {
            $this->charges()->decide($ref, 'invoiced', null, $draft->id);
            $this->fail('A draft isn’t where a cost was charged');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Pick the issued invoice it was on.', $e->getMessage());
        }
        $issued = $this->invoice($job, [[null, 65000]], 'issued', 'INV-0031', false);
        $this->assertSame($issued->id, $this->charges()->decide($ref, 'invoiced', null, $issued->id)->invoice_id);

        // Claimed on their form, then paid back.
        $row = $this->charges()->claimed($ref);
        $this->assertSame(['elsewhere', CostCharges::CLAIMED_NOTE, null], [$row->charge, $row->note, $row->invoice_id]);
        $row = $this->charges()->outcome($ref, 'paid_back');
        $this->assertSame(['paid_back', true], [$row->outcome, $row->outcome_at !== null]);
        $this->assertSame(1, AuditEvent::query()->where('action', 'cost.claimed')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'cost.outcome_set')->count());

        // Cleared: the row goes, and the arrangement applies again.
        $this->assertNull($this->charges()->clear($ref));
        $this->assertSame(0, CostCharge::query()->count());
        $this->assertSame('elsewhere', AuditEvent::query()->where('action', 'cost.charge_cleared')->sole()->before['charge']);
    }

    public function test_refused_for_automation_and_for_what_isnt_a_cost(): void
    {
        $job = $this->job($this->client());
        $ref = 'tx:1#j'.$job->id;
        foreach (['policy', 'matcher', 'ai'] as $actor) {
            foreach ([
                fn () => $this->charges()->decide($ref, 'recharge'),
                fn () => $this->charges()->claimed($ref),
                fn () => $this->charges()->clear($ref),
                fn () => $this->charges()->pair($ref, 'travel:1', 'same'),
                fn () => $this->charges()->outcome($ref, 'not_paid'),
            ] as $write) {
                try {
                    AuditContext::with($actor, $write);
                    $this->fail("Refused for {$actor}");
                } catch (InvalidArgumentException $e) {
                    $this->assertStringStartsWith('Only you can', $e->getMessage());
                }
            }
        }
        foreach (['job:'.$job->id.'#fee', 'tx:1', 'nonsense'] as $bad) {
            try {
                $this->charges()->decide($bad, 'no_charge');
                $this->fail("{$bad} isn't a cost");
            } catch (InvalidArgumentException $e) {
                $this->assertSame('That isn’t a cost Expend knows.', $e->getMessage());
            }
        }
        $this->assertSame(0, CostCharge::query()->count());
    }

    public function test_the_newest_decision_across_a_cost_and_its_aliases_wins_counting_a_booking_decided_recharge(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $ticket = $this->booking($job, 'rail', 'Trainline', 4500, '2026-06-03', ['decision' => 'recharge', 'decided_at' => now()]);
        $this->assertSame('recharge', CostCharges::winning(CostUnits::forJob($job))["travel:{$ticket->id}"]['charge'], 'A booking you decided to recharge, nothing backfilled');
        $this->assertTrue(CostCharges::winning(CostUnits::forJob($job))["travel:{$ticket->id}"]['booking']);

        // Its payment is linked: one cost, under the payment's ref, with the booking's ref as an alias.
        $this->later(5);
        $tx = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)]);
        $ticket->update(['bank_transaction_id' => $tx->id]);
        $ref = "tx:{$tx->id}#j{$job->id}";
        $this->assertSame(['recharge', "travel:{$ticket->id}"], [CostCharges::winning(CostUnits::forJob($job))[$ref]['charge'], CostCharges::winning(CostUnits::forJob($job))[$ref]['from']]);

        // You say it isn't charged, later: that wins.
        $this->later(5);
        $this->charges()->decide($ref, 'no_charge');
        $this->assertSame(['no_charge', $ref], [CostCharges::winning(CostUnits::forJob($job))[$ref]['charge'], CostCharges::winning(CostUnits::forJob($job))[$ref]['from']]);

        // A decision under the booking's ref, newer still, wins over the payment's.
        $this->later(5);
        $this->charges()->decide("travel:{$ticket->id}", 'elsewhere');
        $this->assertSame('elsewhere', CostCharges::winning(CostUnits::forJob($job))[$ref]['charge']);
        $this->assertSame('elsewhere', ChargeStatus::forJob($job, false)[$ref]['status']);
    }

    public function test_a_pairing_is_remembered_one_way_round(): void
    {
        $this->charges()->pair('travel:5', 'tx:9#j1', 'same');
        $this->assertSame(['travel:5' => ['tx:9#j1' => 'same'], 'tx:9#j1' => ['travel:5' => 'same']], CostCharges::pairs(['tx:9#j1']));

        // Said the other way round: one answer for the two.
        $this->charges()->pair('tx:9#j1', 'travel:5', 'distinct');
        $this->assertSame(['tx:9#j1' => ['travel:5' => 'distinct'], 'travel:5' => ['tx:9#j1' => 'distinct']], CostCharges::pairs(['travel:5']));
        $this->assertSame(1, CostCharge::query()->count());
        $this->assertSame(2, AuditEvent::query()->where('action', 'cost.paired')->count());

        // A pairing keeps its row when its charge is cleared.
        $this->charges()->decide('tx:9#j1', 'no_charge');
        $row = $this->charges()->clear('tx:9#j1');
        $this->assertSame([null, 'distinct'], [$row->charge, $row->pair]);
    }

    public function test_a_decision_whose_cost_moved_to_another_job_stays_with_the_payment(): void
    {
        $acme = $this->client();
        $showA = $this->job($acme);
        $showB = $this->job($acme, '2026-06-04');
        $tx = $this->tx(-4500);
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $showA)]);
        $this->charges()->decide("tx:{$tx->id}#j{$showA->id}", 'no_charge');
        $this->assertSame([], CostCharges::gone("tx:{$tx->id}")->all());

        $this->decide($tx, [$this->cost(4500, 'travel_rail', $showB)]);

        $this->assertSame(["tx:{$tx->id}#j{$showA->id}"], CostCharges::gone("tx:{$tx->id}")->pluck('cost_ref')->all());
        $this->assertNull(CostCharges::winning(CostUnits::forJob($showB))["tx:{$tx->id}#j{$showB->id}"], 'Never applied to the new job');
    }

    public function test_recharge_to_the_client_counts_a_confirmed_cost_in_their_costs(): void
    {
        $acme = $this->client();
        $tx = $this->tx(-1800, '2026-06-04', 'NCP');
        $this->decide($tx, [$this->cost(1800, 'travel_parking')]);

        $done = $this->charges()->rechargeToClient($tx, $acme->id);

        $this->assertTrue($done['counted']);
        $this->assertSame('Charged to Acme Events and counted in their costs. It goes on Acme Events’s next draft.', $done['message']);
        $this->assertSame(["tx:{$tx->id}#c{$acme->id}", 'recharge', $acme->id], [$done['row']->cost_ref, $done['row']->charge, $done['row']->client_id]);
        $this->assertSame($acme->id, Allocation::query()->where('bank_transaction_id', $tx->id)->sole()->client_id, 'setExpenseClient, as you');
        $this->assertSame(["tx:{$tx->id}#c{$acme->id}"], collect(CostUnits::forClient($acme, false))->pluck('ref')->all());
    }

    public function test_recharge_to_the_client_on_a_suggestion_or_in_a_locked_year_writes_only_the_decision(): void
    {
        $acme = $this->client();
        $suggested = $this->tx(-1800, '2026-06-04', 'NCP');
        $this->decide($suggested, [$this->cost(1800, 'travel_parking')], false);

        $done = $this->charges()->rechargeToClient($suggested, $acme->id);

        $this->assertFalse($done['counted']);
        $this->assertSame('Charged to Acme Events. Confirm the payment to count it in Acme Events’s costs. It goes on Acme Events’s next draft.', $done['message']);
        $this->assertNull(Allocation::query()->where('bank_transaction_id', $suggested->id)->sole()->client_id);

        // A cost paid in a locked year: no allocation is written, and it still goes on Acme's next draft.
        $old = $this->tx(-3200, '2025-03-10', 'GWR');
        $this->decide($old, [$this->cost(3200, 'travel_rail')]);
        TaxYear::track('2024-25')->forceFill(['locked_at' => now()])->save();
        $before = Allocation::query()->where('bank_transaction_id', $old->id)->get(['id', 'client_id', 'updated_at'])->toArray();

        $done = $this->charges()->rechargeToClient($old, $acme->id);

        $this->assertSame('The 2024-25 records stay as filed. This cost is charged to Acme Events but isn’t counted in Acme Events’s 2024-25 costs. It goes on Acme Events’s next draft.', $done['message']);
        $this->assertSame($before, Allocation::query()->where('bank_transaction_id', $old->id)->get(['id', 'client_id', 'updated_at'])->toArray());
        $units = collect(CostUnits::forClient($acme, false))->keyBy('ref');
        $this->assertTrue($units->has("tx:{$old->id}#c{$acme->id}"), 'A client-only cost from cost_charges.client_id');
        $this->assertSame(['owed', true], [ChargeStatus::for($units->values()->all())["tx:{$old->id}#c{$acme->id}"]['status'], ChargeStatus::for($units->values()->all())["tx:{$old->id}#c{$acme->id}"]['tick']]);
    }

    public function test_recharge_to_the_client_of_a_cost_on_a_job_is_a_recharge_decision_for_that_job(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $tx = $this->tx(-4500);
        $this->decide($tx, [$this->cost(4500, 'travel_rail', $job)]);

        $done = $this->charges()->rechargeToClient($tx, $acme->id);

        $this->assertSame(["tx:{$tx->id}#j{$job->id}", 'recharge'], [$done['row']->cost_ref, $done['row']->charge]);
        $this->assertSame('Charged to Acme Events: it goes on the job’s next draft.', $done['message']);
        $this->expectExceptionMessage('Decide it as a business cost first, then recharge it.');
        $this->charges()->rechargeToClient($this->tx(-100), $acme->id);
    }
}
