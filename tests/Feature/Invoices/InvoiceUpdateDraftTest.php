<?php

namespace Tests\Feature\Invoices;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S6: a draft is saved line by line, in place.
 *  - A changed wording keeps your wording (own_text), a changed quantity or price your amount (own_amount), a changed
 *    tick your tick (ticked_by 'you'); new lines are your own typed lines, and your own lines can be removed.
 *  - A line from the job can't be removed (untick it instead); a save tied to a stale line or stale lines is refused.
 *  - "Use Expend's figures again" lets the line follow its cost; "It's the same" moves a cost onto your own line.
 *  - A form from before line sources still saves: unchanged lines stay, a changed one becomes yours and the job's line
 *    is left unticked, never deleted.
 *  - Recorded as invoice.updated (the total, as before) and invoice.lines_edited (the lines that changed).
 */
class InvoiceUpdateDraftTest extends TestCase
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

    private function acme(): Client
    {
        $client = $this->client();
        app(ChargeTermsWriter::class)->applyPreset($client, 'at_cost');

        return $client;
    }

    private static function form(Invoice $invoice, array $changes = [], array $new = [], array $drop = []): array
    {
        $rows = InvoiceLine::query()->where('invoice_id', $invoice->id)->whereNotIn('id', $drop ?: [0])->orderBy('sort')->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => ($changes[$l->id] ?? []) + ['id' => $l->id, 'version' => InvoiceDraftSync::version($l), 'description' => $l->description,
                'quantity' => (string) $l->quantity, 'unit_price' => number_format($l->unit_price_minor / 100, 2, '.', ''), 'included' => (bool) $l->included, 'sort' => (int) $l->sort])
            ->all();

        return [...$rows, ...$new];
    }

    private function refusal(callable $do): ?string
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_each_line_is_saved_in_place_keeping_what_you_set(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        $draft = $this->service()->draftForJob($job);
        $fee = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'fee')->sole();
        $cab = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'cost')->sole();

        $this->service()->updateDraft($draft->fresh(), ['notes' => 'Payment due within 14 days.', 'lines_version' => InvoiceDraftSync::linesVersion($draft->fresh())], self::form($draft, [
            $fee->id => ['description' => 'Crew, 3 June', 'quantity' => '2', 'unit_price' => '325.00'],
            $cab->id => ['included' => false],
        ], [['description' => 'Overtime', 'quantity' => '1.5', 'unit_price' => '33.33']]));

        $fee->refresh();
        $cab->refresh();
        $this->assertSame(['Crew, 3 June', '2.00', 65000, true, true, null], [$fee->description, (string) $fee->quantity, $fee->amount_minor, $fee->own_text, $fee->own_amount, $fee->ticked_by]);
        $this->assertSame([false, 'you', false, false], [$cab->included, $cab->ticked_by, $cab->own_text, $cab->own_amount]);
        $typed = InvoiceLine::query()->where('invoice_id', $draft->id)->whereNull('source')->sole();
        $this->assertSame(['Overtime', 5000, false, false], [$typed->description, $typed->amount_minor, $typed->own_text, $typed->own_amount]);
        $this->assertSame([70000, 'Payment due within 14 days.'], [$draft->fresh()->total_minor, $draft->fresh()->notes]);
        $this->assertSame(['total' => 70000], AuditEvent::query()->where('action', 'invoice.updated')->sole()->after);
        $edited = AuditEvent::query()->where('action', 'invoice.lines_edited')->sole();
        $this->assertSame(['you', [$fee->id, $cab->id]], [$edited->actor, array_column($edited->before['lines'], 'line')]);
        $this->assertSame([$fee->id, $cab->id, $typed->id], array_column($edited->after['lines'], 'line'));

        // A line from the job can't be removed; your own can.
        $this->assertSame('“'.$cab->description.'” comes from the job: untick it instead of removing it.',
            $this->refusal(fn () => $this->service()->updateDraft($draft->fresh(), [], self::form($draft, drop: [$cab->id]))));
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, drop: [$typed->id]));
        $this->assertNull($typed->fresh());

        // "Use Expend's figures again": the fee line follows the job once more.
        $this->service()->line($draft->fresh(), $fee->id, 'reset', InvoiceDraftSync::version($fee->fresh()));
        $fee->refresh();
        $this->assertSame([$job->title.' (3 Jun 2026)', '1.00', 65000, false, false], [$fee->description, (string) $fee->quantity, $fee->amount_minor, $fee->own_text, $fee->own_amount]);
    }

    public function test_a_save_tied_to_lines_that_changed_since_is_refused(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = $this->service()->draftForJob($job);
        $version = InvoiceDraftSync::linesVersion($draft->fresh());
        $stale = self::form($draft);
        $job->update(['fee_pence' => 70000]);
        app(InvoiceDraftSync::class)->sync($draft);

        $this->assertSame('The draft’s lines changed since you opened it: check them and save again.',
            $this->refusal(fn () => $this->service()->updateDraft($draft->fresh(), ['lines_version' => $version], self::form($draft))));
        $this->assertStringContainsString('changed since you opened the draft: check it and save again.', (string) $this->refusal(fn () => $this->service()->updateDraft($draft->fresh(), [], $stale)));
        $this->assertSame(70000, $draft->fresh()->total_minor, 'Nothing was saved over it');
    }

    public function test_a_form_from_before_line_sources_still_saves_as_it_is_shown(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        $draft = $this->service()->draftForJob($job);
        $fee = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'fee')->sole();
        $cab = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'cost')->sole();

        // The old page posts the ticked lines as it showed them, with no ids: the taxi as it was, the fee changed.
        $this->service()->updateDraft($draft->fresh(), [], [
            ['description' => 'Crew day', 'quantity' => '1', 'unit_price' => '700.00'],
            ['description' => $cab->description, 'quantity' => '1', 'unit_price' => '23.40'],
        ]);
        $this->assertSame([false, 'you'], [$fee->fresh()->included, $fee->fresh()->ticked_by], 'The job’s line is left off by you, never deleted');
        $this->assertSame([true, 1], [$cab->fresh()->included, (int) $cab->fresh()->sort]);
        $this->assertSame('Crew day', InvoiceLine::query()->where('invoice_id', $draft->id)->whereNull('source')->sole()->description);
        $this->assertSame(72340, $draft->fresh()->total_minor);
    }

    public function test_its_the_same_moves_the_cost_onto_your_line(): void
    {
        $job = $this->job($this->acme(), '2026-06-03');
        $draft = $this->service()->draftForJob($job);
        $this->service()->updateDraft($draft->fresh(), [], self::form($draft, [], [['description' => 'Travel expenses', 'quantity' => '1', 'unit_price' => '23.40']]));
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        app(InvoiceDraftSync::class)->sync($draft);
        $cost = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'cost')->sole();
        $mine = InvoiceLine::query()->where('invoice_id', $draft->id)->whereNull('source')->sole();
        $this->assertSame([false, 'maybe_same'], [$cost->included, $cost->flag]);

        $this->service()->line($draft->fresh(), $cost->id, 'same', InvoiceDraftSync::version($cost), $mine->id);
        $mine->refresh();
        $this->assertNull($cost->fresh());
        $this->assertSame(['cost', 'tx:'.$taxi->id.'#j'.$job->id, 'Travel expenses', 2340, true, true, true], [$mine->source, $mine->source_ref, $mine->description, $mine->amount_minor,
            $mine->own_text, $mine->own_amount, $mine->included]);
        // It stays yours on the next sync: no second line for the taxi.
        app(InvoiceDraftSync::class)->sync($draft);
        $this->assertSame(1, InvoiceLine::query()->where('invoice_id', $draft->id)->count());
        $this->assertSame('It’s the same as your line', AuditEvent::query()->where('action', 'invoice.lines_edited')->orderByDesc('id')->first()->reason);
    }
}
