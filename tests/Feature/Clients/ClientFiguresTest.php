<?php

namespace Tests\Feature\Clients;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostCharges;
use App\Domain\Clients\ClientFigures;
use App\Domain\Income\Lines;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\ReturnSummary;
use App\Models\MileageTrip;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S5: costs and profit per client and per end client, by tax year (ClientFigures). Read only. Every
 * client's profit plus 'Not for any client' is ReturnSummary's net profit for the year; merged clients count as one;
 * years before the cash basis was the default carry the note. Recharging changes no tax figure: the cost keeps its
 * own treatment and year, and the recharge is turnover when it's paid.
 */
class ClientFiguresTest extends TestCase
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

    /**
     * An agency (Acme Events, with a duplicate merged into it) that books you for two end clients and direct, a client
     * of your own, client-only money, overheads and mileage, all in 2026-27.
     *
     * @return array<string, mixed>
     */
    private function year(): array
    {
        $acme = $this->client();
        $dupe = $this->client('ACME EVENTS LTD');
        $big = $this->client('Big Prod Co');
        $venue = $this->client('Venue X');
        app(ChargeTermsWriter::class)->applyPreset($acme, 'mileage', 45);
        $forBig = $this->job($acme, '2026-05-04', null, ['end_client_id' => $big->id, 'title' => 'Expo rig']);
        $forVenue = $this->job($acme, '2026-05-11', null, ['end_client_id' => $venue->id, 'title' => 'Gala']);
        $direct = $this->job($acme, '2026-05-18', null, ['fee_pence' => 30000, 'title' => 'Arena load-in']);
        $merged = $this->job($dupe, '2026-05-25', null, ['title' => 'Launch']);
        $own = $this->job($big, '2026-06-01', null, ['title' => 'Conference']);

        // £970 received for the Expo rig: £1,000 turnover, £30 commission taken off.
        $this->decide($this->tx(97000, '2026-05-30', 'ACME PAYROLL'), [$this->income(100000, $forBig), Lines::deduction(3000, null, $forBig->id)]);
        $this->decide($this->tx(20000, '2026-06-02', 'ACME PAYROLL'), [$this->income(20000, null, $acme->id)]);
        $this->decide($this->tx(15000, '2026-06-03', 'ACME EVENTS LTD'), [$this->income(15000, $merged)]);
        $this->decide($this->tx(50000, '2026-06-05', 'BIG PROD CO'), [$this->income(50000, $own)]);
        $this->decide($this->tx(500, '2026-06-06', 'STARLING INTEREST'), [['kind' => 'business_income', 'category_id' => $this->cat('other_income'), 'amount_pence' => 500]]);
        $waiting = $this->tx(9500, '2026-06-07', 'ACME PAYROLL');
        $this->decide($waiting, [$this->income(9500, null, $acme->id)], false);

        $train = $this->tx(-4500, '2026-05-03', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $forBig)]);
        $this->decide($this->tx(-3500, '2026-05-11', 'LEEDS CITY COUNCIL'), [$this->cost(3500, 'travel_penalties', $forVenue)]);
        $this->decide($this->tx(-1500, '2026-05-11', 'UBER'), [$this->cost(1500, 'travel_taxi', $forVenue)]);
        $this->decide($this->tx(-9000, '2026-05-18', 'PREMIER INN'), [$this->cost(9000, 'travel_accommodation', $direct)]);
        $this->decide($this->tx(-1800, '2026-06-04', 'NCP'), [$this->cost(1800, 'travel_parking', null, $acme->id)]);
        $this->decide($this->tx(-1200, '2026-06-04', 'ADOBE'), [$this->cost(1200, 'software')]);

        $this->trip($forBig, '100', '2026-05-04');
        $vehicle = Vehicle::query()->first();
        MileageTrip::query()->create(['vehicle_id' => $vehicle->id, 'trip_on' => '2026-06-08', 'tax_year' => '2026-27', 'from_place' => 'Home', 'to_place' => 'Office', 'miles' => '10', 'purpose' => 'Supplies']);

        // The Arena load-in's invoice also charges the Expo rig's train ('From earlier jobs').
        $invoice = $this->invoice($direct, [["job:{$direct->id}#fee", 30000], ["tx:{$train->id}#j{$forBig->id}", 4500]], 'issued', 'INV-0041', true, '2026-06-10');
        DB::table('invoice_lines')->where('invoice_id', $invoice->id)->where('source', 'cost')->update(['source_job_id' => $forBig->id]);
        $dupe->forceFill(['merged_into_id' => $acme->id])->save();

        return compact('acme', 'dupe', 'big', 'venue', 'forBig', 'forVenue', 'direct', 'merged', 'own', 'invoice', 'train');
    }

    public function test_every_clients_profit_and_not_for_any_client_add_up_to_the_years_net_profit(): void
    {
        $this->year();
        $figures = ClientFigures::forYear('2026-27');
        $return = ReturnSummary::for('2026-27');

        $profit = array_sum(array_column($figures['clients'], 'profit_pence')) + $figures['none']['profit_pence'];
        $this->assertSame($return['net_profit_pence'], $profit);
        $this->assertSame($return['net_profit_pence'], $figures['totals']['profit_pence']);
        $this->assertSame($return['turnover_pence'] + $return['other_income_pence'], $figures['totals']['made_pence']);
        $this->assertSame($return['mileage_pence'], $figures['totals']['mileage_pence']);
        $this->assertSame($return['allowable_expenses_pence'] - $return['mileage_pence'], $figures['totals']['spent_pence'] + $figures['totals']['fees_taken_pence']);
        $this->assertSame($return['disallowable_expenses_pence'], $figures['totals']['disallowable_pence']);
    }

    public function test_a_clients_figures_merged_clients_counted_as_one(): void
    {
        ['acme' => $acme, 'dupe' => $dupe, 'big' => $big] = $this->year();
        $figures = ClientFigures::forYear('2026-27');

        $this->assertSame([$acme->id, $big->id], array_keys($figures['clients']), 'The duplicate counts as Acme');
        $a = $figures['clients'][$acme->id];
        // Made: 1,000 + 200 + 150. Spent: train 45, taxi 15, hotel 90, parking 18. Fees taken off: 30. Mileage: 100 at 55p.
        $this->assertSame([135000, 16800, 3000, 3500, 5500, 9500], [$a['made_pence'], $a['spent_pence'], $a['fees_taken_pence'], $a['disallowable_pence'], $a['mileage_pence'], $a['waiting_pence']]);
        $this->assertSame(135000 - 16800 - 3000 - 5500, $a['profit_pence'], 'The fine is shown apart, as the return leaves it out');
        $this->assertSame(['GBP' => 4500], $a['charged_back'], 'Ticked cost lines on issued invoices');
        // The Expo rig's own income pays its train on INV-0041 (InvoicePayments rule v): £300 is outstanding.
        $this->assertSame([['GBP' => 34500], ['GBP' => 30000], ['GBP' => 0]], [$a['invoiced'], $a['outstanding'], $a['closed']]);
        $this->assertSame([500, 1200, 550, 500 - 1200 - 550], [$figures['none']['made_pence'], $figures['none']['spent_pence'], $figures['none']['mileage_pence'], $figures['none']['profit_pence']]);

        $one = ClientFigures::forClient($dupe, '2026-27');
        $this->assertSame([$acme->id, 135000, null, 'Confirmed records only, counted when paid.'], [$one['client_id'], $one['made_pence'], $one['cash_basis_note'], $one['footnote']]);
        // Still to charge now: the Gala's taxi and the Arena load-in's hotel (the Expo rig and the Launch were paid
        // without an invoice: covered).
        $this->assertSame(['pence' => 10500, 'other' => [], 'count' => 2], $one['still_to_charge']);
    }

    public function test_by_end_client_with_the_agencys_direct_work_and_its_lines_with_no_job_apart(): void
    {
        ['acme' => $acme] = $this->year();

        $rows = collect(ClientFigures::byEndClient($acme, '2026-27'))->keyBy('name');

        $this->assertSame(['Big Prod Co', 'Venue X', 'Acme Events direct', 'Not for a job'], $rows->keys()->all());
        $this->assertSame([100000, 4500, 3000, 5500, 1, 'Includes £45.00 recharged on another job’s invoice'],
            [$rows['Big Prod Co']['made_pence'], $rows['Big Prod Co']['spent_pence'], $rows['Big Prod Co']['fees_taken_pence'], $rows['Big Prod Co']['mileage_pence'], $rows['Big Prod Co']['jobs'], $rows['Big Prod Co']['note']]);
        $this->assertSame([0, 1500, 3500, -1500], [$rows['Venue X']['made_pence'], $rows['Venue X']['spent_pence'], $rows['Venue X']['disallowable_pence'], $rows['Venue X']['profit_pence']]);
        $this->assertSame([15000, 9000, 2, null], [$rows['Acme Events direct']['made_pence'], $rows['Acme Events direct']['spent_pence'], $rows['Acme Events direct']['jobs'], $rows['Acme Events direct']['note']]);
        $this->assertSame([20000, 1800, 18200], [$rows['Not for a job']['made_pence'], $rows['Not for a job']['spent_pence'], $rows['Not for a job']['profit_pence']]);
        $this->assertSame(ClientFigures::forYear('2026-27')['clients'][$acme->id]['profit_pence'], $rows->sum('profit_pence'));
    }

    public function test_years_before_the_cash_basis_was_the_default_say_so(): void
    {
        $this->assertSame('Counted when paid, as the cash basis does. If you didn’t use the cash basis for 2023-24, ask an accountant.', ClientFigures::forYear('2023-24')['cash_basis_note']);
        $this->assertNotNull(ClientFigures::cashBasisNote('2021-22'));
        $this->assertNull(ClientFigures::cashBasisNote('2024-25'));
        $this->assertNull(ClientFigures::forYear('2026-27')['cash_basis_note']);
    }

    public function test_a_recharge_is_turnover_when_paid_and_the_cost_keeps_its_own_treatment_and_year(): void
    {
        $acme = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($acme, 'mileage', 45);
        $job = $this->job($acme, '2026-03-30', null, ['fee_pence' => 30000]);
        $train = $this->tx(-4500, '2026-03-30', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $fine = $this->tx(-3500, '2026-03-30', 'LEEDS CITY COUNCIL');
        $this->decide($fine, [$this->cost(3500, 'travel_penalties', $job)]);
        $trip = $this->trip($job, '100', '2026-03-30');
        $before = [ReturnSummary::for('2025-26'), MileageCalculator::forYear('2025-26')['total_pence']];

        // Recharged on an invoice issued in April, with the fine and the mileage, and paid in full.
        app(CostCharges::class)->decide("tx:{$fine->id}#j{$job->id}", 'recharge');
        $invoice = $this->invoice($job, [["job:{$job->id}#fee", 30000], ["tx:{$train->id}#j{$job->id}", 4500], ["tx:{$fine->id}#j{$job->id}", 3500], ["trip:{$trip->id}", 4500, true, '100']],
            'issued', 'INV-0050', true, '2026-04-10');
        $this->decide($this->tx(42500, '2026-04-30', 'ACME PAYROLL'), [$this->income(42500, null, null, $invoice->id)]);

        $this->assertSame($before, [ReturnSummary::for('2025-26'), MileageCalculator::forYear('2025-26')['total_pence']], 'The cost’s year is unchanged');
        $old = ReturnSummary::for('2025-26');
        $this->assertSame([4500 + 4500, 3500], [$old['allowable_expenses_pence'], $old['disallowable_expenses_pence']], 'An allowable £45 train and the mileage claim; the fine stays disallowable');
        $new = ReturnSummary::for('2026-27');
        $this->assertSame([42500, 0, 0], [$new['turnover_pence'], $new['allowable_expenses_pence'], $new['mileage_pence']], 'The recharge, mileage line included, is turnover when paid');

        $last = ClientFigures::forYear('2025-26')['clients'][$acme->id];
        $this->assertSame([0, 4500, 3500, 4500], [$last['made_pence'], $last['spent_pence'], $last['disallowable_pence'], $last['mileage_pence']]);
        $this->assertSame(['GBP' => 4500 + 3500 + 4500], ClientFigures::forYear('2026-27')['clients'][$acme->id]['charged_back'], 'By the invoice’s issue date');
        $this->assertSame(42500, ClientFigures::forYear('2026-27')['clients'][$acme->id]['made_pence']);
        $this->assertSame(['GBP' => 0], ClientFigures::forYear('2026-27')['clients'][$acme->id]['outstanding']);
        $this->assertInstanceOf(WorkJob::class, $job);
    }
}
