<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\ChargeStatus;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostUnits;
use App\Domain\Clients\ClientFigures;
use App\Domain\Income\Lines;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * One cost reaches the client once: a booking charged before its payment came in, a hand-entered expense charged
 * before its card line, several refunds of one charged cost, a foreign payment split between jobs, money paid to the
 * client itself, a recharge to another client, and the costs of a job cancelled with nothing owed.
 */
class ChargedOnceTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->startCharges();
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    private function service(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function atCost(string $name = 'Acme Events'): Client
    {
        $client = $this->client($name);
        app(ChargeTermsWriter::class)->applyPreset($client, 'at_cost');

        return $client;
    }

    private static function line(Invoice $invoice, string $ref): ?InvoiceLine
    {
        return InvoiceLine::query()->where('invoice_id', $invoice->id)->where('source_ref', $ref)->first();
    }

    public function test_a_payment_arriving_after_its_booking_was_charged_is_left_unticked_as_maybe_the_same(): void
    {
        $acme = $this->atCost();
        $a = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $hotel = $this->booking($a, 'hotel', 'Premier Inn', 12000, '2026-06-02', ['payment' => 'pay_at_property']);
        $issued = $this->service()->issue($this->service()->draftForJob($a)->fresh(), '2026-06-05');
        $this->assertSame(62000, (int) $issued->total_minor, 'The fee, and the hotel at the booking’s price');

        // The bill paid at the hotel, with city tax: not linked to the booking (the amounts differ).
        $bill = $this->tx(-12350, '2026-06-04', 'PREMIER INN LEEDS');
        $this->decide($bill, [$this->cost(12350, 'travel_accommodation', $a)]);
        $ref = "tx:{$bill->id}#j{$a->id}";

        $s = ChargeStatus::forJob($a->fresh(), false)[$ref];
        $this->assertSame(['owed_late', false, "travel:{$hotel->id}"], [$s['status'], $s['tick'], $s['pair_ref']]);
        $this->assertStringStartsWith('Maybe the same cost as Hotel: Premier Inn', $s['flags']['maybe_same']);

        // The client's next draft lists it unticked, flagged; the stay isn't charged twice.
        $b = $this->job($acme, '2026-06-10', null, ['fee_pence' => 30000]);
        $draft = $this->service()->draftForJob($b);
        $this->assertSame([false, 'maybe_same'], [(bool) self::line($draft, $ref)->included, self::line($draft, $ref)->flag]);
        $this->assertSame(30000, (int) $this->service()->issue($draft->fresh(), '2026-06-12')->total_minor);

        // [Same cost]: one cost, and only the city tax is left, as a difference.
        app(CostCharges::class)->pair("travel:{$hotel->id}", $ref, 'same');
        $s = ChargeStatus::forJob($a->fresh(), false)[$ref];
        $this->assertSame([350, true, false], [$s['remaining'], $s['difference'], $s['tick']]);
    }

    public function test_a_card_line_arriving_after_its_hand_entered_twin_was_charged_is_left_unticked(): void
    {
        $acme = $this->atCost();
        $a = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $receipt = $this->expense(-4500, '2026-06-02', 'Trainline');
        $this->decide($receipt, [$this->cost(4500, 'travel_rail', $a)]);
        $this->assertSame(54500, (int) $this->service()->issue($this->service()->draftForJob($a)->fresh(), '2026-06-05')->total_minor);

        // The card line comes in a day later: it's the one recorded second, so it's the one flagged.
        Carbon::setTestNow('2026-06-21 10:00:00');
        CarbonImmutable::setTestNow('2026-06-21 10:00:00');
        $card = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($card, [$this->cost(4500, 'travel_rail', $a)]);
        $exp = "exp:{$receipt->id}#j{$a->id}";
        $ref = "tx:{$card->id}#j{$a->id}";
        $units = collect(CostUnits::forJob($a->fresh()))->keyBy('ref');
        $this->assertSame([$exp, []], [$units[$ref]['pair_ref'], $units[$exp]['flags']]);
        $this->assertArrayHasKey('maybe_same', $units[$ref]['flags']);

        $b = $this->job($acme, '2026-06-10', null, ['fee_pence' => 30000]);
        $draft = $this->service()->draftForJob($b);
        $this->assertSame([false, 'maybe_same'], [(bool) self::line($draft, $ref)->included, self::line($draft, $ref)->flag]);
    }

    public function test_a_card_line_charged_first_and_its_hand_entered_twin_added_later_charge_once_whichever_is_flagged(): void
    {
        $acme = $this->atCost();
        $a = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        // Recorded in the same moment: the hand-entered one is flagged, as before.
        $card = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($card, [$this->cost(4500, 'travel_rail', $a)]);
        $receipt = $this->expense(-4500, '2026-06-02', 'Trainline');
        $this->decide($receipt, [$this->cost(4500, 'travel_rail', $a)]);
        $draft = $this->service()->draftForJob($a);
        $exp = "exp:{$receipt->id}#j{$a->id}";
        $this->assertSame([true, false], [(bool) self::line($draft, "tx:{$card->id}#j{$a->id}")->included, (bool) self::line($draft, $exp)->included]);

        // You tick the hand-entered one and untick the card line: once charged, the card line is maybe the same too.
        $this->service()->line($draft->fresh(), self::line($draft, $exp)->id, 'tick');
        $this->service()->line($draft->fresh(), self::line($draft, "tx:{$card->id}#j{$a->id}")->id, 'untick');
        $this->service()->issue($draft->fresh(), '2026-06-05');
        $s = ChargeStatus::forJob($a->fresh(), false)["tx:{$card->id}#j{$a->id}"];
        $this->assertSame(['not_charged', 'left_off'], [$s['status'], $s['reason']], 'Left off by you: never offered again');
    }

    public function test_two_refunds_of_one_charged_train_credit_it_once(): void
    {
        $acme = $this->atCost();
        $a = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $out = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($out, [$this->cost(4500, 'travel_rail', $a)]);
        $back = $this->tx(-4500, '2026-06-03', 'TRAINLINE');
        $this->decide($back, [$this->cost(4500, 'travel_rail', $a)]);
        $draft = $this->service()->draftForJob($a);
        $this->service()->line($draft->fresh(), self::line($draft, "tx:{$back->id}#j{$a->id}")->id, 'untick');
        $this->assertSame(54500, (int) $this->service()->issue($draft->fresh(), '2026-06-05')->total_minor, 'One train charged');

        $r1 = $this->tx(4500, '2026-06-08', 'TRAINLINE');
        $this->decide($r1, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'work_job_id' => $a->id, 'amount_pence' => 4500]]);
        $r2 = $this->tx(4500, '2026-06-09', 'TRAINLINE');
        $this->decide($r2, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'work_job_id' => $a->id, 'amount_pence' => 4500]]);

        $s = ChargeStatus::forJob($a->fresh(), false);
        $this->assertSame([true, false], [$s["tx:{$r1->id}#j{$a->id}"]['tick'], $s["tx:{$r2->id}#j{$a->id}"]['tick']], 'The oldest refund is credited; the other asks');
        $this->assertSame('Was the original charged to Acme Events?', $s["tx:{$r2->id}#j{$a->id}"]['note']);

        $b = $this->job($acme, '2026-06-10', null, ['fee_pence' => 30000]);
        $credited = (int) InvoiceLine::query()->where('invoice_id', $this->service()->draftForJob($b)->id)->where('included', true)->where('amount_minor', '<', 0)->sum('amount_minor');
        $this->assertSame(-4500, $credited);
    }

    public function test_a_foreign_payment_split_between_jobs_adds_up_to_what_it_cost(): void
    {
        $acme = $this->atCost();
        $a = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $b = $this->job($acme, '2026-06-03', null, ['fee_pence' => 30000]);
        // €52.31 paid from a pound account (£45.00), half on each job.
        $ferry = $this->tx(-4500, '2026-06-02', 'DFDS', ['original_amount_minor' => -5231, 'original_currency' => 'EUR']);
        $this->decide($ferry, [$this->cost(2250, 'travel_local', $a), $this->cost(2250, 'travel_local', $b)]);
        $halves = collect([...CostUnits::forJob($a), ...CostUnits::forJob($b)])->where('kind', 'cost')->pluck('amount_minor')->all();
        $this->assertSame(5231, array_sum($halves), 'The halves add up to the payment');
        $this->assertSame([2616, 2615], $halves);
        $this->assertSame(['measure' => 'minor', 'currency' => 'EUR', 'amount' => 5231], CostUnits::subjects(["tx:{$ferry->id}"])["tx:{$ferry->id}"]);

        foreach ([$a, $b] as $job) {
            $draft = $this->service()->draftForJob($job);
            $this->service()->updateDraft($draft->fresh(), ['currency' => 'EUR'], self::form($draft));
            app(InvoiceDraftSync::class)->sync($draft->fresh());
            if ($job->is($a)) {
                $this->service()->issue($draft->fresh(), '2026-06-05', self::sheet($draft));
            }
        }
        $line = self::line(Invoice::query()->where('work_job_id', $b->id)->where('status', 'draft')->sole(), "tx:{$ferry->id}#j{$b->id}");
        $this->assertSame([true, 2615, null], [(bool) $line->included, (int) $line->amount_minor, $line->flag], 'B’s half is its own cost, ticked');
    }

    public function test_money_paid_to_the_client_itself_is_listed_to_check_never_ticked(): void
    {
        $agency = $this->atCost('Acme Crew');
        $earlier = $this->job($agency, '2026-05-20', null, ['fee_pence' => 40000]);
        $paid = $this->tx(37000, '2026-05-28', 'ACME CREW');
        $this->decide($paid, [$this->income(40000, $earlier), Lines::deduction(3000, null, $earlier->id)]);

        $job = $this->job($agency, '2026-06-03', null, ['fee_pence' => 50000]);
        $commission = $this->tx(-5000, '2026-06-04', 'ACME CREW');
        $this->decide($commission, [$this->cost(5000, 'professional', $job)]);
        $ref = "tx:{$commission->id}#j{$job->id}";

        $unit = collect(CostUnits::forJob($job))->firstWhere('ref', $ref);
        $this->assertSame('Paid to Acme Crew itself: commission or a fee isn’t charged back to them.', $unit['flags']['check']);
        $line = self::line($this->service()->draftForJob($job), $ref);
        $this->assertSame([false, 'check'], [(bool) $line->included, $line->flag]);

        // Someone else's money out is charged as before.
        $hire = $this->tx(-8000, '2026-06-04', 'SPEEDY HIRE');
        $this->decide($hire, [$this->cost(8000, 'equipment', $job)]);
        $this->assertSame([], collect(CostUnits::forJob($job))->firstWhere('ref', "tx:{$hire->id}#j{$job->id}")['flags']);
    }

    public function test_a_cost_on_a_job_is_recharged_to_the_jobs_client_only(): void
    {
        $acme = $this->client('Acme Events');
        $beta = $this->client('Beta Crew');
        $job = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $hire = $this->tx(-8000, '2026-06-03', 'SPEEDY HIRE');
        $this->decide($hire, [$this->cost(8000, 'equipment', $job)]);

        try {
            app(CostCharges::class)->rechargeToClient($hire, $beta->id);
            $this->fail('Recharged to a client the job isn’t for');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('It’s on '.$job->title.' for Acme Events: recharge it there, or move it to one of Beta Crew’s jobs.', $e->getMessage());
        }
        $this->assertNull(CostCharge::query()->where('cost_ref', "tx:{$hire->id}#j{$job->id}")->first(), 'Nothing written');

        $this->expectExceptionMessage('recharge it there');
        app(CostCharges::class)->decide("tx:{$hire->id}#j{$job->id}", 'recharge', $beta->id);
    }

    public function test_a_job_cancelled_with_nothing_owed_drops_its_untouched_draft_and_owes_no_costs(): void
    {
        $acme = $this->atCost();
        $job = $this->job($acme, '2026-06-03', null, ['fee_pence' => 65000, 'status' => 'booked']);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $draft = app(JobStatus::class)->markDone($job)['invoice'];
        $this->assertSame(69500, (int) $draft->fresh()->total_minor);

        $r = app(JobStatus::class)->cancel($job->fresh(), 'none');
        $this->assertSame(['Cancelled.', null], [$r['message'], $r['invoice']]);
        $this->assertNull(Invoice::query()->find($draft->id), 'The draft Done made, untouched, is removed');
        $this->assertSame('Cancelled with nothing owed: the draft, untouched',
            AuditEvent::query()->where('action', 'invoice.deleted')->where('subject_id', $draft->id)->sole()->reason);
        $s = ChargeStatus::forJob($job->fresh(), false)["tx:{$train->id}#j{$job->id}"];
        $this->assertSame(['not_charged', 'cancelled'], [$s['status'], $s['reason']]);
        $this->assertSame(0, ClientFigures::stillToCharge($acme)['pence']);

        // Undo: back to done, with its draft made again.
        $undo = app(JobStatus::class)->undoCancel($job->fresh());
        $this->assertSame(['Back to done. Its invoice is ready to check.', 69500], [$undo['message'], (int) $undo['invoice']->fresh()->total_minor]);

        // A recharge you decided stands, cancelled or not.
        app(JobStatus::class)->cancel($job->fresh(), 'none');
        app(CostCharges::class)->decide("tx:{$train->id}#j{$job->id}", 'recharge');
        $this->assertSame('owed', ChargeStatus::forJob($job->fresh(), false)["tx:{$train->id}#j{$job->id}"]['status']);
    }

    public function test_a_draft_you_changed_stays_when_the_job_is_cancelled_with_nothing_owed_and_its_job_lines_go(): void
    {
        $acme = $this->atCost();
        $job = $this->job($acme, '2026-06-03', null, ['fee_pence' => 65000, 'status' => 'booked']);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $draft = app(JobStatus::class)->markDone($job)['invoice'];
        $this->service()->updateDraft($draft->fresh(), ['notes' => 'Payment due within 14 days.'], self::form($draft, [['description' => 'Backline hire', 'quantity' => '1', 'unit_price' => '90.00']]));

        $r = app(JobStatus::class)->cancel($job->fresh(), 'none');

        $this->assertSame('Cancelled. Its draft stays: you’ve changed it. They owe nothing for the job now, so check it.', $r['message']);
        $this->assertSame(['Backline hire'], InvoiceLine::query()->where('invoice_id', $draft->id)->where('included', true)->pluck('description')->all());
    }

    public function test_still_to_charge_leaves_out_a_booking_that_may_be_the_same_as_its_bill(): void
    {
        $acme = $this->atCost();
        $a = $this->job($acme, '2026-06-03', null, ['fee_pence' => 50000]);
        $this->booking($a, 'hotel', 'Premier Inn', 12000, '2026-06-02', ['payment' => 'pay_at_property']);
        $bill = $this->tx(-12350, '2026-06-04', 'PREMIER INN LEEDS');
        $this->decide($bill, [$this->cost(12350, 'travel_accommodation', $a)]);

        $this->assertSame(['pence' => 12350, 'other' => [], 'count' => 1], ClientFigures::stillToCharge($acme));
        $this->service()->draftForJob($a);
        $this->assertSame(0, ClientFigures::stillToCharge($acme)['pence'], 'The bill is ticked on the draft; the booking waits for you');
    }

    public function test_a_cost_unticked_as_maybe_on_your_own_line_stays_unticked_until_you_say(): void
    {
        $acme = $this->client('Beta Crew');
        app(ChargeTermsWriter::class)->applyPreset($acme, 'mileage', 45);
        $job = $this->job($acme, '2026-06-05');
        $draft = $this->service()->draftForJob($job);
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [['description' => 'Travel expenses', 'quantity' => '1', 'unit_price' => '23.40']]));
        $taxi = $this->tx(-2340, '2026-06-05', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        $ref = "tx:{$taxi->id}#j{$job->id}";

        app(InvoiceDraftSync::class)->sync($draft);
        $this->assertSame([false, 'maybe_same'], [(bool) self::line($draft, $ref)->included, self::line($draft, $ref)->flag]);
        $this->assertSame(0, app(InvoiceDraftSync::class)->sync($draft)['changed'], 'Nothing new: nothing changes');
        $this->assertSame([false, 'maybe_same', 2340], [(bool) self::line($draft, $ref)->included, self::line($draft, $ref)->flag, (int) $draft->fresh()->total_minor]);
        $this->assertSame(2340, (int) $this->service()->issue($draft->fresh(), '2026-06-20', self::sheet($draft))->total_minor);
    }

    public function test_a_cost_unticked_as_maybe_on_your_own_line_follows_its_cost_once_your_line_goes(): void
    {
        $acme = $this->client('Beta Crew');
        app(ChargeTermsWriter::class)->applyPreset($acme, 'mileage', 45);
        $job = $this->job($acme, '2026-06-05');
        $draft = $this->service()->draftForJob($job);
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [['description' => 'Travel expenses', 'quantity' => '1', 'unit_price' => '23.40']]));
        $taxi = $this->tx(-2340, '2026-06-05', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        app(InvoiceDraftSync::class)->sync($draft);
        $ref = "tx:{$taxi->id}#j{$job->id}";

        // You remove your own line: the taxi is no longer maybe on it, and is ticked as its cost says.
        $typed = InvoiceLine::query()->where('invoice_id', $draft->id)->whereNull('source')->sole();
        $this->service()->line($draft->fresh(), $typed->id, 'remove');
        app(InvoiceDraftSync::class)->sync($draft);
        $this->assertSame([true, null], [(bool) self::line($draft, $ref)->included, self::line($draft, $ref)->flag]);
    }

    /** What an issue sheet shows: the lines as they are, and every change so far. */
    private static function sheet(Invoice $invoice): array
    {
        return ['lines_version' => InvoiceDraftSync::linesVersion($invoice), 'seen_up_to' => InvoiceDraftSync::lastChange($invoice)];
    }

    /** The per-line save of every line as it is, plus new typed lines. */
    private static function form(Invoice $invoice, array $new = []): array
    {
        return [...InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => ['id' => $l->id, 'version' => InvoiceDraftSync::version($l), 'description' => $l->description,
                'quantity' => (string) $l->quantity, 'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', ''), 'included' => (bool) $l->included, 'sort' => (int) $l->sort])
            ->all(), ...$new];
    }
}
