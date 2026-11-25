<?php

namespace Tests\Feature\Invoices;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobDays;
use App\Domain\Jobs\JobStage;
use App\Domain\Jobs\JobStatus;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S6: a draft's lines follow what's linked to its job, keeping what you changed yourself.
 *  - Done's draft holds the job's work, its costs ticked by the arrangement, and its mileage at the rate (the venue
 *    named, never where the trip started); a second sync with nothing new takes no lock and writes nothing.
 *  - Your wording, price and tick survive a change to the cost; a cost that has gone is removed when you hadn't
 *    touched it, and kept (flagged, blocking issue) when you had.
 *  - A booking with no payment is re-keyed to its payment once linked, keeping your edits and untick.
 *  - A cost linked to a job after its invoice went goes ticked on the client's next draft ("From earlier jobs"); never
 *    from a job with its own open draft, or a job billed before line sources (covered).
 *  - A pound cost on a euro draft is listed with no price; a cost maybe on your own line is unticked.
 *  - A draft made before line sources: the work takes over your one line of its amount, or isn't added at all.
 *  - Work lines: agreed days and extra days; a day added after sending goes on the job's follow-up draft only.
 */
class InvoiceDraftSyncTest extends TestCase
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

    private function sync(Invoice $invoice): array
    {
        return app(InvoiceDraftSync::class)->sync($invoice);
    }

    /** A client whose usual is fares and stays at cost, mileage at 45p a mile. */
    private function acme(string $name = 'Acme Events'): Client
    {
        $client = $this->client($name);
        app(ChargeTermsWriter::class)->applyPreset($client, 'mileage', 45);

        return $client;
    }

    /** @return list<array{0:?string, 1:?string, 2:string, 3:string, 4:int, 5:int, 6:bool, 7:?string}> source, ref, words, quantity, price, amount, ticked, flag */
    private static function lines(Invoice $invoice): array
    {
        return InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => [$l->source, $l->source_ref, $l->description, (string) $l->quantity, (int) $l->unit_price_minor, (int) $l->amount_minor, (bool) $l->included, $l->flag])
            ->all();
    }

    /** What an issue sheet shows: the lines as they are, and every change so far (the sync's changes are seen). */
    private static function sheet(Invoice $invoice): array
    {
        return ['lines_version' => InvoiceDraftSync::linesVersion($invoice), 'seen_up_to' => InvoiceDraftSync::lastChange($invoice)];
    }

    private static function line(Invoice $invoice, string $ref): ?InvoiceLine
    {
        return InvoiceLine::query()->where('invoice_id', $invoice->id)->where('source_ref', $ref)->first();
    }

    /** The per-line save of every line as it is, with some changed (by line id). */
    private static function form(Invoice $invoice, array $changes = [], array $new = []): array
    {
        $rows = InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => ($changes[$l->id] ?? []) + ['id' => $l->id, 'version' => InvoiceDraftSync::version($l), 'description' => $l->description,
                'quantity' => (string) $l->quantity, 'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', ''), 'included' => (bool) $l->included, 'sort' => (int) $l->sort])
            ->all();

        return [...$rows, ...$new];
    }

    /** @return list<string> */
    private static function actions(Invoice $invoice): array
    {
        return AuditEvent::query()->where('subject_type', 'Invoice')->where('subject_id', $invoice->id)->orderBy('id')->get()
            ->map(fn (AuditEvent $e) => $e->action.':'.$e->actor)->all();
    }

    public function test_dones_draft_holds_the_work_the_costs_by_the_arrangement_and_the_mileage(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $trip = $this->trip($job, '142.5');

        $draft = $this->service()->draftForJob($job);

        $this->assertSame([
            ['fee', 'job:'.$job->id.'#fee', $job->title.' (3 Jun 2026)', '1.00', 65000, 65000, true, null],
            ['cost', 'tx:'.$train->id.'#j'.$job->id, 'Train: TRAINLINE, 2 Jun 2026 (at cost)', '1.00', 4500, 4500, true, null],
            // 142.5 miles at 45p is £64.125, half up to £64.13. Only the job's venue, never where the trip started.
            ['mileage', 'trip:'.$trip->id, 'Mileage 3 Jun: Leeds Arena and back, 142.5 miles at 45p', '142.50', 45, 6413, true, null],
        ], self::lines($draft));
        $this->assertSame(75913, $draft->fresh()->total_minor);
        $this->assertStringNotContainsString('Oak Road', implode(' ', array_column(self::lines($draft), 2)));
        $this->assertStringNotContainsString('LS2', implode(' ', array_column(self::lines($draft), 2)));
        $this->assertSame(['invoice.line_added:policy', 'invoice.line_added:policy', 'invoice.line_added:policy', 'invoice.drafted:you'], self::actions($draft));
        $this->assertNotNull($draft->fresh()->synced_at);
        $this->assertCount(0, InvoiceDraftSync::changes($draft->fresh()), 'Its first fill isn’t a change since you looked');

        // A second sync with nothing new: no lock, no write, no event, synced_at as it was.
        $synced = $draft->fresh()->synced_at->format('Y-m-d H:i:s');
        $events = AuditEvent::query()->count();
        $this->travel(5)->minutes();
        DB::enableQueryLog();
        $r = $this->sync($draft);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));
        DB::disableQueryLog();
        $this->assertSame([0, false], [$r['changed'], $r['wrote']]);
        $this->assertTrue($queries->every(fn ($q) => ! str_contains($q, 'for update') && ! str_starts_with(ltrim($q), 'insert') && ! str_starts_with(ltrim($q), 'update')
            && ! str_starts_with(ltrim($q), 'delete')), 'Nothing locked or written');
        $this->assertSame([$events, $synced], [AuditEvent::query()->count(), $draft->fresh()->synced_at->format('Y-m-d H:i:s')]);

        // A taxi linked to the job later: added, and listed as a change since you looked.
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        $r = $this->sync($draft);
        $this->assertSame([1, 1, true], [$r['changed'], $r['added'], $r['wrote']]);
        $this->assertSame(['cost', 'tx:'.$taxi->id.'#j'.$job->id, 'Taxi: UBER, 3 Jun 2026 (at cost)', '1.00', 2340, 2340, true, null], self::lines($draft)[3]);
        $changes = InvoiceDraftSync::changes($draft->fresh());
        $this->assertSame(['invoice.line_added'], $changes->pluck('action')->all());
        $this->assertSame([$draft->id => 1], InvoiceDraftSync::changeCounts([$draft->id]));
        $this->assertSame('Draft · 1 change', JobStage::of([$job->fresh()], null, InvoiceDraftSync::changeCounts([$draft->id]))[$job->id]['chip'] ?? null);

        // Got it: seen up to the change shown; a change after it stays listed.
        $this->service()->seen($draft->fresh(), (int) $changes->last()->id);
        $this->assertSame([], InvoiceDraftSync::changeCounts([$draft->id]));
        $parking = $this->tx(-1200, '2026-06-03', 'NCP');
        $this->decide($parking, [$this->cost(1200, 'travel_parking', $job)]);
        $this->sync($draft);
        $this->assertSame([$draft->id => 1], InvoiceDraftSync::changeCounts([$draft->id]));
    }

    public function test_your_wording_price_and_tick_survive_a_change_to_the_cost(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $train = $this->booking($job, 'rail', 'Trainline', 4500, '2026-06-02');
        $trip = $this->trip($job, '100');
        $draft = $this->service()->draftForJob($job);
        $trainLine = self::line($draft, 'travel:'.$train->id);
        $tripLine = self::line($draft, 'trip:'.$trip->id);

        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [
            $trainLine->id => ['description' => 'Train to Leeds', 'unit_price' => '40.00'],
            $tripLine->id => ['included' => false],
        ]));
        $trainLine->refresh();
        $tripLine->refresh();
        $this->assertSame([true, true, null], [$trainLine->own_text, $trainLine->own_amount, $trainLine->ticked_by]);
        $this->assertSame([false, false, 'you', false], [$tripLine->own_text, $tripLine->own_amount, $tripLine->ticked_by, $tripLine->included]);

        // The train cost £50 in the end, and the trip was 120 miles.
        $train->update(['amount_minor' => 5000]);
        $trip->update(['miles' => '120']);
        $r = $this->sync($draft);
        $this->assertSame(1, $r['changed'], 'The trip’s miles; the train’s new cost only in what Expend knows');
        $trainLine->refresh();
        $tripLine->refresh();
        $this->assertSame(['Train to Leeds', 4000, 4000, true, 5000], [$trainLine->description, $trainLine->unit_price_minor, $trainLine->amount_minor, $trainLine->included,
            $trainLine->source_amount_minor]);
        $this->assertSame(['120.00', 5400, false], [(string) $tripLine->quantity, $tripLine->amount_minor, $tripLine->included], 'Follows the miles, keeps your untick');
        $changed = AuditEvent::query()->where('action', 'invoice.line_changed')->orderBy('id')->get();
        $this->assertSame(['policy', 'policy'], $changed->pluck('actor')->all());
        $this->assertSame(['line' => $tripLine->id, 'quantity' => '100.00', 'amount_minor' => 4500],
            array_intersect_key($changed->firstWhere('after.line', $tripLine->id)->before, ['line' => 1, 'quantity' => 1, 'amount_minor' => 1]));

        // A save with a version from before the sync is refused, saying which line.
        $stale = self::form($draft);
        $train->update(['amount_minor' => 5200]);
        $trip->update(['miles' => '125']);
        $this->sync($draft);
        try {
            $this->service()->updateDraft($draft->fresh(), [], $stale);
            $this->fail('A stale line was saved');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('changed since you opened the draft', $e->getMessage());
            $this->assertSame(InvoiceService::CHANGED, $e->getCode());
        }
    }

    public function test_a_cost_gone_from_the_job_is_removed_unless_you_changed_its_line(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $fine = $this->tx(-3000, '2026-06-03', 'LEEDS CITY COUNCIL');
        $this->decide($fine, [$this->cost(3000, 'travel_penalties', $job)]);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        $draft = $this->service()->draftForJob($job);
        $fineLine = self::line($draft, 'tx:'.$fine->id.'#j'.$job->id);
        $this->assertSame([false, null], [$fineLine->included, $fineLine->ticked_by], 'Fines aren’t charged by Acme’s usual: listed unticked');
        $taxiLine = self::line($draft, 'tx:'.$taxi->id.'#j'.$job->id);
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [$taxiLine->id => ['description' => 'Taxi from the station']]));

        // All three moved off the job.
        foreach ([[$fine, 3000, 'travel_penalties'], [$train, 4500, 'travel_rail'], [$taxi, 2340, 'travel_taxi']] as [$tx, $pence, $cat]) {
            $this->decide($tx, [$this->cost($pence, $cat, null)]);
        }
        $r = $this->sync($draft);
        $this->assertSame([2, 1, 3], [$r['removed'], $r['flagged'], $r['changed']]);
        $this->assertNull(self::line($draft, 'tx:'.$fine->id.'#j'.$job->id), 'Unticked: removed');
        $this->assertNull(self::line($draft, 'tx:'.$train->id.'#j'.$job->id), 'Ticked, untouched by you: removed');
        $kept = self::line($draft, 'tx:'.$taxi->id.'#j'.$job->id);
        $this->assertSame(['Taxi from the station', 'gone', true], [$kept->description, $kept->flag, $kept->included], 'You changed it: kept, flagged');
        $this->assertSame(65000 + 2340, $draft->fresh()->total_minor);

        // It blocks issuing until you remove it or keep it as your own line.
        try {
            $this->service()->issue($draft->fresh(), '2026-06-20', self::sheet($draft));
            $this->fail('Issued with a line whose cost has gone');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('“Taxi from the station” is no longer linked to the job: remove it, or keep it as your own line.', $e->getMessage());
        }
        $this->service()->line($draft->fresh(), $kept->id, 'keep', InvoiceDraftSync::version($kept->fresh()));
        $kept->refresh();
        $this->assertSame([null, null, null, 'Taxi from the station'], [$kept->source, $kept->source_ref, $kept->flag, $kept->description]);
        $this->assertSame('issued', $this->service()->issue($draft->fresh(), '2026-06-20', self::sheet($draft))->status);
    }

    public function test_a_booking_line_is_re_keyed_to_its_payment_keeping_your_edits_and_untick(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $hotel = $this->booking($job, 'hotel', 'Premier Inn', 12000, '2026-06-02');
        $draft = $this->service()->draftForJob($job);
        $line = self::line($draft, 'travel:'.$hotel->id);
        $this->assertSame([true, 'no_payment', 12000], [$line->included, $line->flag, $line->amount_minor], 'Charged at the booking’s price, no payment yet');
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [$line->id => ['description' => 'Hotel, 2 nights', 'included' => false]]));

        // Its payment arrives and is linked: one cost, now cited by the payment.
        $pay = $this->tx(-12000, '2026-06-04', 'PREMIER INN');
        $this->decide($pay, [$this->cost(12000, 'travel_accommodation', $job)]);
        $hotel->update(['bank_transaction_id' => $pay->id]);
        $this->sync($draft);

        $line->refresh();
        $this->assertSame(['tx:'.$pay->id.'#j'.$job->id, 'Hotel, 2 nights', false, 'you', null], [$line->source_ref, $line->description, $line->included, $line->ticked_by, $line->flag]);
        $this->assertSame(2, InvoiceLine::query()->where('invoice_id', $draft->id)->count(), 'The fee and the one hotel line');
    }

    public function test_a_cost_linked_after_the_invoice_went_goes_on_the_clients_next_draft(): void
    {
        $client = $this->acme();
        $a = $this->job($client, '2026-06-01', null, ['fee_pence' => 50000]);
        $this->service()->issue($this->service()->draftForJob($a), '2026-06-02');
        $taxi = $this->tx(-2340, '2026-06-01', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $a)]);

        // Billed before Expend tracked line sources, paid without an invoice from Expend, and "Already sorted": none
        // of their costs is pulled onto a draft.
        $legacy = $this->job($client, '2026-05-01', null, ['fee_pence' => 40000]);
        $this->invoice($legacy, [[null, 40000]], tracked: false);
        $legacyCost = $this->tx(-1800, '2026-05-01', 'NCP');
        $this->decide($legacyCost, [$this->cost(1800, 'travel_parking', $legacy)]);
        $sorted = $this->job($client, '2026-05-05');
        $this->invoice($sorted, [['job:'.$sorted->id.'#fee', 20000]]);
        TodoDismissal::query()->create(['key' => 'invoice:'.$sorted->id, 'note' => 'Already sorted']);
        $sortedCost = $this->tx(-900, '2026-05-05', 'NCP');
        $this->decide($sortedCost, [$this->cost(900, 'travel_parking', $sorted)]);

        $b = $this->job($client, '2026-06-10', null, ['fee_pence' => 30000]);
        $draftB = $this->service()->draftForJob($b);
        $this->assertSame([
            ['fee', 'job:'.$b->id.'#fee', $b->title.' (10 Jun 2026)', '1.00', 30000, 30000, true, null],
            ['cost', 'tx:'.$taxi->id.'#j'.$a->id, 'Taxi: UBER, 1 Jun 2026 (at cost), for '.$a->title, '1.00', 2340, 2340, true, null],
        ], self::lines($draftB));
        $this->assertSame($a->id, self::line($draftB, 'tx:'.$taxi->id.'#j'.$a->id)->source_job_id);

        // A follow-up draft for job A now keeps A's costs: B's line goes (you hadn't touched it) and A's draft has it.
        $followUp = $this->service()->draftForJob($a->fresh());
        $this->assertNotSame($draftB->id, $followUp->id);
        $this->sync($draftB);
        $this->sync($followUp);
        $this->assertNull(self::line($draftB, 'tx:'.$taxi->id.'#j'.$a->id));
        $this->assertSame([true, $a->id], [self::line($followUp, 'tx:'.$taxi->id.'#j'.$a->id)->included, self::line($followUp, 'tx:'.$taxi->id.'#j'.$a->id)->source_job_id]);
        $this->assertNull(self::line($followUp, 'job:'.$a->id.'#fee'), 'A follow-up has no fee line: the fee is on INV-0001');
        $this->assertTrue(AuditEvent::query()->where('subject_id', $followUp->id)->where('action', 'invoice.drafted')->sole()->after['follow_up']);
    }

    public function test_a_pound_cost_on_a_euro_draft_has_no_price_and_a_cost_maybe_on_your_line_is_unticked(): void
    {
        $job = $this->job($this->acme(), '2026-06-03');
        $draft = $this->service()->draftForJob($job);
        $this->service()->updateDraft($draft->fresh(), ['currency' => 'EUR'], self::form($draft, [], [
            ['description' => 'Rigging, 3 Jun', 'quantity' => '1', 'unit_price' => '800.00'],
        ]));
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $this->sync($draft);
        $line = self::line($draft, 'tx:'.$train->id.'#j'.$job->id);
        $this->assertSame([0, 0, false, 'no_amount'], [$line->unit_price_minor, $line->amount_minor, $line->included, $line->flag], 'Never converted');

        // Ticked with no price, it blocks issuing; priced by you, it doesn't.
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [$line->id => ['included' => true]]));
        try {
            $this->service()->issue($draft->fresh(), '2026-06-20', self::sheet($draft));
            $this->fail('Issued with no price');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('“Train: TRAINLINE, 2 Jun 2026 (at cost)” has no price: type the price in EUR, or untick it.', $e->getMessage());
        }
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [$line->id => ['unit_price' => '52.00']]));
        $this->sync($draft);
        $this->assertSame([5200, null, true], [$line->fresh()->amount_minor, $line->fresh()->flag, $line->fresh()->included]);

        // A taxi at the amount of a line you typed for travel: maybe the same, so unticked.
        $other = $this->job($this->acme('Beta Crew'), '2026-06-05');
        $otherDraft = $this->service()->draftForJob($other);
        $this->service()->updateDraft($otherDraft->fresh(), [], self::form($otherDraft, [], [['description' => 'Travel expenses', 'quantity' => '1', 'unit_price' => '23.40']]));
        $taxi = $this->tx(-2340, '2026-06-05', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $other)]);
        $this->sync($otherDraft);
        $this->assertSame([false, 'maybe_same'], [self::line($otherDraft, 'tx:'.$taxi->id.'#j'.$other->id)->included, self::line($otherDraft, 'tx:'.$taxi->id.'#j'.$other->id)->flag]);
    }

    public function test_a_draft_made_before_line_sources_never_gets_the_fee_twice(): void
    {
        $client = $this->acme();
        // One typed line of exactly the fee: it becomes the fee line, in your words.
        $one = $this->job($client, '2026-06-03', null, ['fee_pence' => 65000]);
        $old = Invoice::query()->create(['status' => 'draft', 'client_id' => $client->id, 'work_job_id' => $one->id, 'bill_to_name' => 'Acme Events', 'currency' => 'GBP']);
        $typed = $old->allLines()->create(['description' => 'Crew, 3 June', 'quantity' => 2, 'unit_price_minor' => 32500, 'amount_minor' => 65000, 'sort' => 0]);
        $this->sync($old);
        $typed->refresh();
        $this->assertSame(['fee', 'job:'.$one->id.'#fee', 'Crew, 3 June', true, true, '2.00'], [$typed->source, $typed->source_ref, $typed->description, $typed->own_text, $typed->own_amount,
            (string) $typed->quantity]);
        $this->assertSame(1, InvoiceLine::query()->where('invoice_id', $old->id)->count());

        // No typed line of the fee's amount: the fee isn't added, now or later; the costs are.
        $two = $this->job($client, '2026-06-04', null, ['fee_pence' => 65000]);
        $old2 = Invoice::query()->create(['status' => 'draft', 'client_id' => $client->id, 'work_job_id' => $two->id, 'bill_to_name' => 'Acme Events', 'currency' => 'GBP']);
        $old2->allLines()->create(['description' => 'Crew', 'quantity' => 1, 'unit_price_minor' => 30000, 'amount_minor' => 30000, 'sort' => 0]);
        $old2->allLines()->create(['description' => 'Overtime', 'quantity' => 1, 'unit_price_minor' => 35000, 'amount_minor' => 35000, 'sort' => 1]);
        $train = $this->tx(-4500, '2026-06-04', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $two)]);
        $this->sync($old2);
        $this->assertNull(self::line($old2, 'job:'.$two->id.'#fee'));
        $this->assertNotNull(self::line($old2, 'tx:'.$train->id.'#j'.$two->id));
        $event = AuditEvent::query()->where('subject_id', $old2->id)->where('action', InvoiceDraftSync::LEGACY_EVENT)->sole();
        $this->assertSame([['job:'.$two->id.'#fee'], 'policy', InvoiceDraftSync::LEGACY_NOTE], [$event->after['work_not_added'], $event->actor, $event->reason]);
        $this->assertContains(InvoiceDraftSync::LEGACY_NOTE, array_column($this->service()->checks($old2->fresh())['warnings'], 'message'));
        $two->update(['fee_pence' => 70000]);
        $this->sync($old2);
        $this->assertNull(self::line($old2, 'job:'.$two->id.'#fee'), 'Still not added on a later sync');
    }

    public function test_agreed_days_extra_days_and_a_day_added_after_sending(): void
    {
        $client = $this->acme();
        $job = $this->job($client, '2026-06-01', '2026-06-04', ['rate_pence' => 30000, 'rate_unit' => 'day', 'days_agreed' => 3]);
        $draft = $this->service()->draftForJob($job);
        $this->assertSame([
            ['days', 'job:'.$job->id.'#days', $job->title.' (1–4 Jun 2026)', '3.00', 30000, 90000, true, null],
            ['extra', 'job:'.$job->id.'#extra', '1 more day than agreed: '.$job->title, '1.00', 30000, 30000, true, null],
        ], self::lines($draft));
        $issued = $this->service()->issue($draft->fresh(), '2026-06-05');

        // A day added after sending: only on the job's follow-up draft, unticked, never on another job's draft.
        JobDays::add($job->fresh(), '2026-06-05');
        $other = $this->job($client, '2026-06-10', null, ['fee_pence' => 20000]);
        $otherDraft = $this->service()->draftForJob($other);
        $this->assertSame(['job:'.$other->id.'#fee'], array_column(self::lines($otherDraft), 1));
        $followUp = $this->service()->draftForJob($job->fresh());
        $this->assertSame([
            ['extra', 'job:'.$job->id.'#extra', '2 more days than agreed: '.$job->title.': 1 more day than on '.$issued->number, '1.00', 30000, 30000, false, null],
        ], self::lines($followUp));
        $this->assertInstanceOf(WorkJob::class, $job->fresh());
    }

    public function test_a_cancelled_jobs_draft_has_its_cancellation_fee_and_its_costs_and_done_says_what_isnt_set(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000, 'status' => 'booked']);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $r = app(JobStatus::class)->cancel($job, 'fee', 20000);
        $this->assertSame([
            ['cancel', 'job:'.$job->id.'#cancel', 'Cancellation fee: '.$job->title.' (3 Jun 2026)', '1.00', 20000, 20000, true, null],
            ['cost', 'tx:'.$train->id.'#j'.$job->id, 'Train: TRAINLINE, 2 Jun 2026 (at cost)', '1.00', 4500, 4500, true, null],
        ], self::lines($r['invoice']), 'No fee line: a cancelled job has only its cancellation fee');

        // A client with no arrangement yet: Done says the costs aren't ticked.
        $beta = $this->client('Beta Crew');
        $other = $this->job($beta, '2026-06-05', null, ['fee_pence' => 30000, 'status' => 'booked']);
        $taxi = $this->tx(-2340, '2026-06-05', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $other)]);
        $this->assertSame('Marked done. Its invoice is ready to check. Beta Crew’s travel isn’t set yet: 1 cost isn’t ticked.', app(JobStatus::class)->markDone($other)['message']);
    }
}
