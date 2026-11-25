<?php

namespace Tests\Feature\Invoices;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Models\AuditEvent;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S6: ledger:sync-drafts.
 *  - Nightly (and by hand) every open draft is brought up to date as 'policy'; a draft already up to date writes
 *    nothing.
 *  - --before-rollback leaves no unticked line on any draft, from the job or typed by you (the release before would
 *    bill it), and the lines that stay read as your own lines again, with synced_at cleared. Issued invoices are never
 *    touched.
 */
class RollbackPrepTest extends TestCase
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

    public function test_the_nightly_sync_brings_every_draft_up_to_date_and_writes_nothing_when_nothing_is_new(): void
    {
        $client = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($client, 'at_cost');
        $job = $this->job($client, '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = app(InvoiceService::class)->draftForJob($job);
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);

        $this->artisan('ledger:sync-drafts')->expectsOutput('1 draft checked: 1 changed.')->assertSuccessful();
        $this->assertSame('policy', AuditEvent::query()->where('action', 'invoice.line_added')->orderByDesc('id')->first()->actor);
        $this->assertSame(67340, $draft->fresh()->total_minor);

        $events = AuditEvent::query()->count();
        $this->artisan('ledger:sync-drafts')->expectsOutput('1 draft checked: 0 changed.')->assertSuccessful();
        $this->assertSame($events, AuditEvent::query()->count());
    }

    public function test_before_a_rollback_no_unticked_line_from_the_job_is_left_on_a_draft(): void
    {
        $client = $this->client();
        $job = $this->job($client, '2026-06-03', null, ['fee_pence' => 65000]);
        $hotel = $this->tx(-12000, '2026-06-02', 'PREMIER INN');
        $this->decide($hotel, [$this->cost(12000, 'travel_accommodation', $job)]);
        $draft = app(InvoiceService::class)->draftForJob($job);
        $this->assertFalse(InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'cost')->sole()->included, 'No arrangement yet: unticked');
        // An issued invoice with a line left off stays exactly as issued.
        $other = $this->job($client, '2026-06-05', null, ['fee_pence' => 30000]);
        $parking = $this->tx(-1200, '2026-06-05', 'NCP');
        $this->decide($parking, [$this->cost(1200, 'travel_parking', $other)]);
        $issued = app(InvoiceService::class)->issue(app(InvoiceService::class)->draftForJob($other), '2026-06-06');

        $this->artisan('ledger:sync-drafts', ['--before-rollback' => true])
            ->expectsOutput('1 draft made ready for the release before: 1 unticked line removed.')->assertSuccessful();

        $this->assertSame(0, InvoiceLine::query()->whereIn('invoice_id', Invoice::query()->where('status', 'draft')->pluck('id'))
            ->where(fn ($q) => $q->whereNotNull('source')->orWhere('included', false))->count(), 'No unticked line and no line from the job left on a draft');
        $fee = InvoiceLine::query()->where('invoice_id', $draft->id)->sole();
        $this->assertSame([null, null, true, 65000], [$fee->source, $fee->source_ref, $fee->included, $fee->amount_minor], 'Kept as your own line');
        $this->assertNull($draft->fresh()->synced_at);
        $event = AuditEvent::query()->where('action', 'invoice.rollback_prepared')->sole();
        $this->assertSame(['policy', $draft->id, 1], [$event->actor, (int) $event->subject_id, $event->after['kept_as_typed']]);
        $this->assertSame([2, 1], [$issued->allLines()->count(), $issued->lines()->count()], 'The issued invoice is untouched');

        // Back on this release, the draft reads as one made before line sources: the fee isn't added twice.
        app(InvoiceDraftSync::class)->sync($draft);
        $this->assertSame(['fee'], InvoiceLine::query()->where('invoice_id', $draft->id)->where('included', true)->pluck('source')->all());
    }

    public function test_before_a_rollback_your_own_unticked_line_goes_too(): void
    {
        $job = $this->job($this->client(), '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = app(InvoiceService::class)->draftForJob($job);
        $typed = $draft->allLines()->create(['description' => 'Backline hire', 'quantity' => '1.00', 'unit_price_minor' => 9000, 'amount_minor' => 9000, 'sort' => 5]);
        app(InvoiceService::class)->line($draft->fresh(), $typed->id, 'untick', InvoiceDraftSync::version($typed->fresh()));
        $this->assertSame(65000, (int) $draft->fresh()->total_minor);

        $this->artisan('ledger:sync-drafts', ['--before-rollback' => true])
            ->expectsOutput('1 draft made ready for the release before: 1 unticked line removed.')->assertSuccessful();

        $this->assertSame([[], 65000], [InvoiceLine::query()->where('invoice_id', $draft->id)->where('included', false)->pluck('description')->all(),
            (int) InvoiceLine::query()->where('invoice_id', $draft->id)->sum('amount_minor')], 'Every line left is ticked: the release before bills only those');
        $this->assertSame([$typed->id], AuditEvent::query()->where('action', 'invoice.rollback_prepared')->sole()->after['removed']);
    }
}
