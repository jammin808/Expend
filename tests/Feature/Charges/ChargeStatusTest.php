<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostUnits;
use App\Models\Client;
use App\Models\Document;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Build slice S5: where each cost stands (ChargeStatus). Covered jobs never add work or costs; a cost charged once is
 * never charged again when split or moved; a voided invoice frees its costs; the statuses come in their order; the
 * arrangement says what's ticked; a refund is ticked only against a charged original.
 */
class ChargeStatusTest extends TestCase
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

    /** @return array<string, array<string, mixed>> */
    private function standing(WorkJob $job, bool $work = true): array
    {
        return ChargeStatus::forJob($job, $work);
    }

    private function atCost(Client $client): void
    {
        app(ChargeTermsWriter::class)->applyPreset($client, 'mileage', 45);
    }

    /** A train on the job, decided by you. */
    private function train(WorkJob $job, int $pence = 4500, string $on = '2026-06-02'): string
    {
        $tx = $this->tx(-$pence, $on, 'TRAINLINE');
        $this->decide($tx, [$this->cost($pence, 'travel_rail', $job)]);

        return "tx:{$tx->id}#j{$job->id}";
    }

    public function test_covered_jobs_add_no_fee_day_mileage_or_cost_line(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        // Invoiced before this release (no sync), from a PO before it too, paid with no Expend invoice, and set aside.
        $legacy = $this->job($acme, '2026-05-04', null, ['fee_pence' => 65000]);
        $this->invoice($legacy, [[null, 65000]], 'issued', 'INV-0031', false, '2026-05-10');
        $poJob = $this->job($acme, '2026-05-05', null, ['fee_pence' => 50000]);
        $po = Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted', 'work_job_id' => $poJob->id]);
        $this->invoice($poJob, [[null, 50000]], 'issued', 'INV-0032', false, '2026-05-10', 'GBP', ['document_id' => $po->id]);
        $paid = $this->job($acme, '2026-05-06', null, ['fee_pence' => 40000]);
        $this->decide($this->tx(40000, '2026-05-20', 'ACME PAYROLL'), [$this->income(40000, $paid)]);
        $sorted = $this->job($acme, '2026-05-07', null, ['rate_pence' => 25000, 'rate_unit' => 'day']);
        TodoDismissal::query()->create(['key' => 'invoice:'.$sorted->id, 'note' => 'Already sorted']);

        foreach (['INV-0031' => $legacy, 'INV-0032' => $poJob, 'income' => $paid, 'sorted' => $sorted] as $why => $job) {
            $train = $this->train($job, 4500, $job->starts_on->format('Y-m-d'));
            $trip = $this->trip($job, '20', $job->starts_on->format('Y-m-d'));
            $status = $this->standing($job);
            $this->assertNotEmpty($status, $why);
            foreach ($status as $ref => $s) {
                $this->assertSame('covered', $s['status'], "{$why}: {$ref}");
                $this->assertFalse($s['tick'], "{$why}: {$ref} is never ticked");
            }
            $this->assertArrayHasKey($train, $status);
            $this->assertArrayHasKey("trip:{$trip->id}", $status);
        }
        $this->assertSame('Covered by INV-0031', $this->standing($legacy)["job:{$legacy->id}#fee"]['note']);
        $this->assertSame('Paid without an invoice from Expend', $this->standing($paid)["job:{$paid->id}#fee"]['note']);
        $this->assertSame('You said it was invoiced another way', $this->standing($sorted)["job:{$sorted->id}#days"]['note']);

        // "Charge it" on the legacy job's train: a recharge decision newer than the invoice makes it owed late.
        $train = collect(CostUnits::forJob($legacy))->firstWhere('kind', 'cost')['ref'];
        app(CostCharges::class)->decide($train, 'recharge');
        $this->assertSame(['owed_late', true], [$this->standing($legacy)[$train]['status'], $this->standing($legacy)[$train]['tick']]);
        $this->assertSame('covered', $this->standing($legacy)["job:{$legacy->id}#fee"]['status'], 'Its work stays covered');
    }

    public function test_a_tracked_invoice_made_from_a_po_covers_only_its_work(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $job = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $po = Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted', 'work_job_id' => $job->id]);
        $this->invoice($job, [['doc:'.$po->id.'#l0', 50000, true, '1', 'po']], 'issued', 'INV-0042', true, '2026-06-10', 'GBP', ['document_id' => $po->id]);
        $train = $this->train($job);

        $status = $this->standing($job);

        $this->assertSame(['covered', 'Covered by the PO on INV-0042'], [$status["job:{$job->id}#fee"]['status'], $status["job:{$job->id}#fee"]['note']]);
        $this->assertSame(['owed_late', true], [$status[$train]['status'], $status[$train]['tick']], 'A late cost: the PO set only the work');
    }

    public function test_a_ticket_charged_once_has_nothing_left_when_its_split_between_jobs_or_moved(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $showA = $this->job($acme, '2026-06-03', null, ['title' => 'Show A']);
        $showB = $this->job($acme, '2026-06-04', null, ['title' => 'Show B']);
        $tx = $this->tx(-8450, '2026-06-02', 'TRAINLINE');
        $this->decide($tx, [$this->cost(8450, 'travel_rail', $showA)]);
        $this->invoice($showA, [["tx:{$tx->id}#j{$showA->id}", 8450]], 'issued', 'INV-0041');
        $this->assertSame(['on_invoice', 0, 8450], [$this->standing($showA)["tx:{$tx->id}#j{$showA->id}"]['status'], $this->standing($showA)["tx:{$tx->id}#j{$showA->id}"]['remaining'], $this->standing($showA)["tx:{$tx->id}#j{$showA->id}"]['charged']]);

        // Split £42.25 each: nothing to offer on either job.
        $this->decide($tx, [$this->cost(4225, 'travel_rail', $showA), $this->cost(4225, 'travel_rail', $showB)]);
        $a = $this->standing($showA)["tx:{$tx->id}#j{$showA->id}"];
        $b = $this->standing($showB)["tx:{$tx->id}#j{$showB->id}"];
        $this->assertSame(['on_invoice', 0, false], [$a['status'], $a['remaining'], $a['tick']]);
        $this->assertSame(['on_invoice', 0, false, 0, 8450], [$b['status'], $b['remaining'], $b['tick'], $b['charged'], $b['subject_charged']]);
        $this->assertSame('Charged on INV-0041 (Show A)', $b['note']);

        // Moved to Show B altogether: still charged.
        $this->decide($tx, [$this->cost(8450, 'travel_rail', $showB)]);
        $this->assertSame(['on_invoice', 0], [$this->standing($showB)["tx:{$tx->id}#j{$showB->id}"]['status'], $this->standing($showB)["tx:{$tx->id}#j{$showB->id}"]['remaining']]);
    }

    public function test_a_payment_split_between_two_jobs_is_charged_half_on_each(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $showA = $this->job($acme, '2026-06-03', null, ['title' => 'Show A']);
        $showB = $this->job($acme, '2026-06-04', null, ['title' => 'Show B']);
        $tx = $this->tx(-10000, '2026-06-02', 'TRAINLINE');
        $this->decide($tx, [$this->cost(5000, 'travel_rail', $showA), $this->cost(5000, 'travel_rail', $showB)]);
        $this->invoice($showA, [["tx:{$tx->id}#j{$showA->id}", 5000]], 'issued', 'INV-0041');

        $b = $this->standing($showB)["tx:{$tx->id}#j{$showB->id}"];

        $this->assertSame(['owed', 5000, true, false, null], [$b['status'], $b['remaining'], $b['tick'], $b['difference'], $b['note']], 'Show A’s half being charged isn’t Show B’s');
        $this->assertSame('on_invoice', $this->standing($showA)["tx:{$tx->id}#j{$showA->id}"]['status']);

        // Moved to Show B after its first half was charged on Show A's invoice: only what's left, as a difference.
        $this->decide($tx, [$this->cost(10000, 'travel_rail', $showB)]);
        $b = $this->standing($showB)["tx:{$tx->id}#j{$showB->id}"];
        $this->assertSame(['owed', 5000, false, true], [$b['status'], $b['remaining'], $b['tick'], $b['difference']]);
    }

    public function test_a_cost_that_went_up_after_sending_offers_only_the_difference_unticked(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $job = $this->job($acme);
        // A £145 hotel bill, first decided as £45 for the job and the rest personal, and charged at £45.
        $tx = $this->tx(-14500, '2026-06-02', 'PREMIER INN');
        $this->decide($tx, [$this->cost(4500, 'travel_accommodation', $job), ['kind' => 'personal', 'amount_pence' => -10000]]);
        $ref = "tx:{$tx->id}#j{$job->id}";
        $this->invoice($job, [[$ref, 4500]], 'issued', 'INV-0041');
        $this->assertSame('on_invoice', $this->standing($job)[$ref]['status']);

        // All of it was for the job after all.
        $this->decide($tx, [$this->cost(14500, 'travel_accommodation', $job)]);
        $s = $this->standing($job)[$ref];

        $this->assertSame(['owed_late', 10000, false, true], [$s['status'], $s['remaining'], $s['tick'], $s['difference']]);
        $this->assertSame('Difference on INV-0041: you paid £145.00, charged £45.00', $s['note']);

        // Charged for more than it costs now: shown, never credited.
        $this->decide($tx, [$this->cost(4000, 'travel_accommodation', $job), ['kind' => 'personal', 'amount_pence' => -10500]]);
        $s = $this->standing($job)[$ref];
        $this->assertSame(['on_invoice', 0, 4500, 4000], [$s['status'], $s['remaining'], $s['charged'], $s['amount']]);
    }

    public function test_the_same_cost_ticked_on_two_drafts_and_a_voided_invoice_frees_its_cost(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $job = $this->job($acme);
        $train = $this->train($job);
        $one = $this->invoice($job, [[$train, 4500]], 'draft');
        $two = $this->invoice(null, [[$train, 4500]], 'draft');

        $s = $this->standing($job)[$train];
        $this->assertSame(['on_draft', [$one->id, $two->id]], [$s['status'], $s['drafts']], 'Both drafts are listed: the second issue is refused');

        $issued = $this->invoice($job, [[$train, 4500]], 'issued', 'INV-0031');
        $this->assertSame('on_invoice', $this->standing($job)[$train]['status']);
        $issued->update(['status' => 'void', 'void_reason' => 'Wrong client']);
        $this->assertSame('on_draft', $this->standing($job)[$train]['status'], 'Voided: the cost is free again');
        $one->allLines()->delete();
        $two->allLines()->delete();
        $this->assertSame(['owed', true], [$this->standing($job)[$train]['status'], $this->standing($job)[$train]['tick']], 'Not late: a void invoice isn’t an issued one');
    }

    public function test_it_was_on_a_voided_invoice_follows_the_replacement(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $job = $this->job($acme, '2026-06-03', null, ['fee_pence' => 65000]);
        $train = $this->train($job);
        $old = $this->invoice($job, [[null, 69500]], 'issued', 'INV-0031', false);
        app(CostCharges::class)->decide($train, 'invoiced', null, $old->id);
        $this->assertSame(['on_invoice', 'On INV-0031'], [$this->standing($job, false)[$train]['status'], $this->standing($job, false)[$train]['note']]);

        // Voided and replaced by an invoice that doesn't carry it: to charge again, saying so.
        $new = $this->invoice($job, [["job:{$job->id}#fee", 65000]], 'issued', 'INV-0040');
        $old->update(['status' => 'void', 'void_reason' => 'Wrong amount', 'replaced_by_id' => $new->id]);
        $s = $this->standing($job, false)[$train];
        $this->assertSame(['owed_late', true, 'Was on INV-0031, since voided'], [$s['status'], $s['tick'], $s['note']]);

        // The replacement carries it: on the replacement.
        $newer = $this->invoice($job, [["job:{$job->id}#fee", 65000], [$train, 4500]], 'issued', 'INV-0043');
        $new->update(['status' => 'void', 'void_reason' => 'Missed the train', 'replaced_by_id' => $newer->id]);
        $s = $this->standing($job, false)[$train];
        $this->assertSame(['on_invoice', [$newer->id]], [$s['status'], $s['invoices']]);
    }

    public function test_the_statuses_come_in_order(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        app(ChargeTermsWriter::class)->set($acme, ['rail' => 'at_cost', 'taxi' => 'their_form', 'hotel' => 'none']);
        $rail = $this->train($job);
        $taxi = $this->tx(-1500, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(1500, 'travel_taxi', $job)]);
        $taxi = "tx:{$taxi->id}#j{$job->id}";
        $hotel = $this->tx(-9000, '2026-06-03', 'PREMIER INN');
        $this->decide($hotel, [$this->cost(9000, 'travel_accommodation', $job)]);
        $hotel = "tx:{$hotel->id}#j{$job->id}";
        $flight = $this->tx(-9900, '2026-06-03', 'EASYJET');
        $this->decide($flight, [$this->cost(9900, 'travel_flights', $job)]);
        $flight = "tx:{$flight->id}#j{$job->id}";

        $s = $this->standing($job, false);
        $this->assertSame(['owed', true, true], [$s[$rail]['status'], $s[$rail]['expected'], $s[$rail]['tick']]);
        $this->assertSame(['to_claim', 'To claim on Acme Events’s expense form', false], [$s[$taxi]['status'], $s[$taxi]['note'], $s[$taxi]['tick']]);
        $this->assertSame(['not_charged', 'arrangement', 'Not charged by the arrangement'], [$s[$hotel]['status'], $s[$hotel]['reason'], $s[$hotel]['note']]);
        $this->assertSame(['not_charged', 'arrangement', 'Acme Events has no arrangement for flight yet', false], [$s[$flight]['status'], $s[$flight]['reason'], $s[$flight]['note'], $s[$flight]['tick']]);

        app(CostCharges::class)->claimed($taxi);
        app(CostCharges::class)->decide($hotel, 'recharge');
        app(CostCharges::class)->decide($flight, 'no_charge');
        $s = $this->standing($job, false);
        $this->assertSame(['elsewhere', true, 'Claimed on Acme Events’s expense form'], [$s[$taxi]['status'], $s[$taxi]['claimed'], $s[$taxi]['note']]);
        $this->assertSame(['owed', true], [$s[$hotel]['status'], $s[$hotel]['tick']], 'Your recharge beats the arrangement');
        $this->assertSame(['not_charged', 'yours'], [$s[$flight]['status'], $s[$flight]['reason']]);
        $this->assertSame(['recharge', 'no_charge', 'clear'], ChargeStatus::actions($s[$taxi]));
        $this->assertSame(['recharge', 'elsewhere', 'clear'], ChargeStatus::actions($s[$flight]));
        $this->assertSame('elsewhere', ChargeStatus::chip($s[$taxi]['status']));

        // Ticked on a draft, then left off (unticked) an issued invoice; a recharge decided since makes it owed again.
        $draft = $this->invoice($job, [[$rail, 4500]], 'draft');
        $this->assertSame('on_draft', $this->standing($job, false)[$rail]['status']);
        $draft->allLines()->delete();
        $this->invoice($job, [[$rail, 4500, false]], 'issued', 'INV-0050', true, '2026-06-10');
        $this->assertSame(['not_charged', 'left_off', 'Left off INV-0050'], [$this->standing($job, false)[$rail]['status'], $this->standing($job, false)[$rail]['reason'], $this->standing($job, false)[$rail]['note']]);
        app(CostCharges::class)->decide($rail, 'recharge');
        $this->assertSame(['owed_late', true], [$this->standing($job, false)[$rail]['status'], $this->standing($job, false)[$rail]['tick']]);
    }

    public function test_a_client_default_a_job_override_and_a_mix_give_the_expected_ticks(): void
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $writer = app(ChargeTermsWriter::class);
        $writer->set($acme, ['rail' => 'at_cost', 'taxi' => 'none', 'hotel' => 'at_cost', 'meals' => 'none', 'mileage' => ['charge' => 'per_mile', 'rate_minor' => 45]]);
        $writer->set($job, ['taxi' => 'at_cost']);
        $rail = $this->train($job);
        $taxi = $this->tx(-1500, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(1500, 'travel_taxi', $job)]);
        // A hotel bill with dinner on it: only the room is charged.
        $bill = $this->tx(-11000, '2026-06-04', 'PREMIER INN');
        $this->decide($bill, [$this->cost(9000, 'travel_accommodation', $job), $this->cost(2000, 'travel_subsistence', $job)]);
        $trip = $this->trip($job, '142.5');
        $fuel = $this->tx(-5000, '2026-06-03', 'SHELL');
        $this->decide($fuel, [$this->cost(5000, 'travel_fuel', $job)]);

        $s = $this->standing($job, false);

        $this->assertTrue($s[$rail]['tick'], 'Client default');
        $this->assertTrue($s["tx:{$taxi->id}#j{$job->id}"]['tick'], 'Job override');
        $this->assertSame([true, 9000], [$s["tx:{$bill->id}#j{$job->id}"]['tick'], $s["tx:{$bill->id}#j{$job->id}"]['amount']], 'Only the charged part');
        $this->assertSame(['owed', true, '142.50', 'quantity'], [$s["trip:{$trip->id}"]['status'], $s["trip:{$trip->id}"]['tick'], $s["trip:{$trip->id}"]['remaining'], $s["trip:{$trip->id}"]['measure']]);
        $this->assertSame(['not_charged', false], [$s["tx:{$fuel->id}#j{$job->id}"]['status'], $s["tx:{$fuel->id}#j{$job->id}"]['tick']], 'Per mile sets fuel to none');

        // Fuel charged at cost beside ticked mileage: listed, unticked, flagged.
        $writer->set($job, ['taxi' => 'at_cost', 'fuel' => 'at_cost']);
        $this->assertSame(['owed', false], [$this->standing($job, false)["tx:{$fuel->id}#j{$job->id}"]['status'], $this->standing($job, false)["tx:{$fuel->id}#j{$job->id}"]['tick']]);

        // A trip charged on an invoice is counted by miles: a correction offers only what's left.
        $this->invoice($job, [["trip:{$trip->id}", 4500, true, '100']], 'issued', 'INV-0060');
        $s = $this->standing($job, false)["trip:{$trip->id}"];
        $this->assertSame(['owed_late', '42.50', '42.5 more miles than on INV-0060', false], [$s['status'], $s['remaining'], $s['note'], $s['tick']]);
    }

    public function test_a_booking_and_its_payment_tick_once_and_someone_elses_booking_is_unticked(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $job = $this->job($acme, '2026-06-03', '2026-06-04');
        $stay = $this->booking($job, 'hotel', 'Booking.com', 12000);
        $card = $this->tx(-12340, '2026-06-05', 'PREMIER INN LEEDS');
        $this->decide($card, [$this->cost(12340, 'travel_accommodation', $job)]);
        $theirs = $this->booking($job, 'flight', 'easyJet', 9900, '2026-06-03', ['payment' => 'paid_by_other']);

        $s = $this->standing($job, false);

        $this->assertSame([true, false, false], [$s["tx:{$card->id}#j{$job->id}"]['tick'], $s["travel:{$stay->id}"]['tick'], $s["travel:{$theirs->id}"]['tick']]);
        $this->assertSame('owed', $s["travel:{$stay->id}"]['status']);
    }

    public function test_a_refund_is_ticked_only_against_a_charged_original(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $job = $this->job($acme);
        $refund = $this->tx(2000, '2026-06-08', 'TRAINLINE');
        $this->decide($refund, [$this->cost(-2000, 'travel_rail', $job)]);
        $credit = "tx:{$refund->id}#j{$job->id}";

        $this->assertSame(['not_charged', 'unsure', false, 'Was the original charged to Acme Events?'],
            [$this->standing($job, false)[$credit]['status'], $this->standing($job, false)[$credit]['reason'], $this->standing($job, false)[$credit]['tick'], $this->standing($job, false)[$credit]['note']]);

        $train = $this->train($job, 4500);
        $this->invoice($job, [[$train, 4500]], 'issued', 'INV-0041');
        $s = $this->standing($job, false)[$credit];
        $this->assertSame(['owed_late', true, 2000], [$s['status'], $s['tick'], $s['remaining']]);
    }

    public function test_a_day_added_after_sending_offers_one_more_day(): void
    {
        $acme = $this->client();
        $job = $this->job($acme, '2026-06-01', '2026-06-03', ['rate_pence' => 25000, 'rate_unit' => 'day']);
        $this->invoice($job, [["job:{$job->id}#days", 75000, true, '3']], 'issued', 'INV-0041');
        $this->assertSame('on_invoice', $this->standing($job)["job:{$job->id}#days"]['status']);

        $job->update(['ends_on' => '2026-06-04']);
        $s = $this->standing($job->fresh())["job:{$job->id}#days"];

        $this->assertSame(['owed_late', '1.00', '1 more day than on INV-0041', false], [$s['status'], $s['remaining'], $s['note'], $s['tick']]);
    }

    public function test_it_works_in_batches(): void
    {
        $acme = $this->client();
        $this->atCost($acme);
        $count = function (int $jobs): int {
            $units = [];
            foreach (range(1, $jobs) as $i) {
                $job = $this->job(Client::query()->first(), '2026-06-0'.$i);
                $this->train($job);
                $this->trip($job, '10');
                $this->booking($job, 'hotel', 'Booking.com', 12000, '2026-06-0'.$i);
                $units = [...$units, ...CostUnits::forJob($job), ...CostUnits::work($job)];
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            ChargeStatus::for($units);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($count(2), $count(6), 'The same queries for 6 jobs as for 2');
        $this->assertSame(['to_charge', 'on_draft', 'charged', 'to_claim', 'not_charged', 'elsewhere'], array_keys(ChargeStatus::CHIPS));
    }
}
