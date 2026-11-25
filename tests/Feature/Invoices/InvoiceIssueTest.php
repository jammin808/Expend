<?php

namespace Tests\Feature\Invoices;

use App\Domain\Charges\ChargeTerms;
use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceService;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\WorkJob;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Charges\ChargeFixtures;
use Tests\TestCase;

/**
 * Build slice S6: issuing is tied to what you saw, and checked first.
 *  - Refused when the sync changed something since the issue sheet loaded, or the lines or the changes you were shown
 *    aren't the ones there now; nothing is issued unseen.
 *  - Refused without your name and address; a second invoice for a job's work needs you to say so.
 *  - Confirm and issue: a ticked cost whose payment isn't confirmed is confirmed as you, only for the payment whose
 *    whole split you were shown (its fingerprint); a changed payment is refused, and one in a locked year must be
 *    unticked.
 *  - A cost ticked on two drafts is refused at the second issue. Unticked lines stay on the issued invoice, left off.
 *  - Money already waiting that may be for it is suggested once it's issued.
 */
class InvoiceIssueTest extends TestCase
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

    private function refusal(callable $do): array
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return [$e->getMessage(), $e->getCode()];
        }

        return [null, null];
    }

    /** What the issue sheet showed, as it is now. */
    private static function shown(Invoice $draft, array $extra = []): array
    {
        $draft = $draft->fresh();

        return $extra + ['lines_version' => InvoiceDraftSync::linesVersion($draft), 'seen_up_to' => InvoiceDraftSync::changes($draft)->last()?->id];
    }

    public function test_nothing_is_issued_unseen(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = $this->service()->draftForJob($job);
        $sheet = self::shown($draft);

        // A taxi linked to the job while the sheet was open.
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        $this->assertSame(['1 thing changed since you looked: check it and issue again.', InvoiceService::CHANGED],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20', $sheet)));
        $this->assertSame(['draft', null], [$draft->fresh()->status, $draft->fresh()->number]);

        // The sheet shown again from before: its lines are stale. Then the lines as they are, without the change seen.
        $this->assertSame(['The lines changed since you opened the issue sheet: check them and issue again.', InvoiceService::CHANGED],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20', $sheet)));
        $this->assertSame(['1 thing changed since you looked: check it and issue again.', InvoiceService::CHANGED],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20', self::shown($draft, ['seen_up_to' => null]))));

        // With the change shown: issued, and the change marked seen.
        $last = InvoiceDraftSync::changes($draft->fresh())->last()->id;
        $issued = $this->service()->issue($draft->fresh(), '2026-06-20', self::shown($draft));
        $this->assertSame(['issued', 'INV-0001', 67340, $last], [$issued->status, $issued->number, $issued->total_minor, $issued->lines_seen_event_id]);
        $this->assertSame(['number' => 'INV-0001', 'total' => 67340, 'pdf_sha256' => $issued->pdf_sha256], AuditEvent::query()->where('action', 'invoice.issued')->sole()->after);
    }

    public function test_with_no_issue_sheet_a_change_you_havent_seen_is_refused(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = $this->service()->draftForJob($job);
        $taxi = $this->tx(-2340, '2026-06-03', 'UBER');
        $this->decide($taxi, [$this->cost(2340, 'travel_taxi', $job)]);
        // Brought up to date by "Draft the invoice" pressed again (from the to-do list or Telegram): the taxi is a change.
        $this->service()->draftForJob($job->fresh());
        $this->assertCount(1, InvoiceDraftSync::changes($draft->fresh()));

        $this->assertSame(['1 thing changed since you looked: check it and issue again.', InvoiceService::CHANGED],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20')));
        $this->assertSame('draft', $draft->fresh()->status);

        // Shown (the page sends the lines and the last change it showed): issued.
        $this->assertSame(67340, (int) $this->service()->issue($draft->fresh(), '2026-06-20', self::shown($draft))->total_minor);
    }

    public function test_a_line_changed_after_the_checks_and_before_the_lock_is_refused(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $hotel = $this->tx(-12000, '2026-06-02', 'PREMIER INN');
        $this->decide($hotel, [$this->cost(12000, 'travel_accommodation', $job)]);
        $draft = $this->service()->draftForJob($job);
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source', 'cost')->sole();
        $this->service()->line($draft->fresh(), $line->id, 'untick');
        $sheet = self::shown($draft);

        // Another tab ticks the hotel just as the issue starts its transaction.
        $armed = true;
        Event::listen(TransactionBeginning::class, function () use (&$armed, $line, $draft) {
            if ($armed) {
                $armed = false;
                DB::table('invoice_lines')->where('id', $line->id)->update(['included' => true, 'ticked_by' => 'you']);
                DB::table('invoices')->where('id', $draft->id)->update(['total_minor' => 77000]);
            }
        });

        $this->assertSame(['The lines changed since you opened the issue sheet: check them and issue again.', InvoiceService::CHANGED],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20', $sheet)));
        $this->assertSame(['draft', null], [$draft->fresh()->status, $draft->fresh()->number]);
    }

    public function test_your_details_and_a_second_invoice_for_the_work(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $draft = $this->service()->draftForJob($job);
        Setting::put('business_address', '');
        $this->assertSame(['Your name and address must be on the invoice: add them in Settings first.', 0],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20')));
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        $this->service()->issue($draft->fresh(), '2026-06-20');

        // The PO arrives after the invoice went: a draft from it bills the work again, so you say it's a second invoice.
        $po = Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'po'), 'stored_path' => 'x', 'kind' => 'po',
            'status' => 'extracted', 'work_job_id' => $job->id, 'job_linked_by' => 'you',
            'extraction' => ['po' => ['po_number' => 'PO-4471', 'currency' => 'GBP', 'stated_total_minor' => 65000, 'bill_to_name' => 'Acme Events',
                'lines' => [['description' => 'Crew', 'quantity' => 1, 'unit_price_minor' => 65000, 'amount_minor' => 65000, 'quote' => 'Crew']]]]]);
        $second = $this->service()->draftFromDocument($po);
        $this->assertSame(['It’s a second invoice for '.$job->title.' (INV-0001 has its work): say so to issue it.', InvoiceService::CHANGED],
            $this->refusal(fn () => $this->service()->issue($second->fresh(), '2026-06-21')));
        $this->assertSame('INV-0002', $this->service()->issue($second->fresh(), '2026-06-21', self::shown($second, ['second_invoice' => true]))->number);
    }

    public function test_confirm_and_issue_confirms_only_the_payment_you_were_shown(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)], confirmed: false);
        $draft = $this->service()->draftForJob($job);
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', 'tx:'.$train->id.'#j'.$job->id)->sole();
        $this->assertSame([true, 'unconfirmed'], [$line->included, $line->flag], 'Ticked: issuing confirms it');

        $confirm = $this->service()->toConfirm($draft->fresh());
        $this->assertCount(1, $confirm);
        $this->assertSame(['tx:'.$train->id, false, [$line->id]], [$confirm[0]['source'], $confirm[0]['locked'], $confirm[0]['lines']]);
        $this->assertSame('business_expense', $confirm[0]['state']['lines'][0][0], 'Its whole split, as the sheet shows it');

        $notConfirmed = ['A cost isn’t confirmed yet. Issuing confirms it: check each payment’s split and issue again.', InvoiceService::CHANGED];
        $this->assertSame($notConfirmed, $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20')));
        $this->assertSame($notConfirmed, $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20',
            self::shown($draft, ['confirm' => [['source' => 'tx:'.$train->id, 'fp' => 'abc123']]]))));

        // The payment's suggestion changed after the sheet was shown: refused, nothing confirmed.
        $fp = $confirm[0]['fp'];
        $this->decide($train, [$this->cost(4000, 'travel_rail', $job), ['kind' => 'personal', 'category_id' => null, 'work_job_id' => null, 'amount_pence' => -500]], confirmed: false);
        app(InvoiceDraftSync::class)->sync($draft);
        $this->assertSame($notConfirmed, $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20',
            self::shown($draft, ['confirm' => [['source' => 'tx:'.$train->id, 'fp' => $fp]]]))));
        $this->assertSame(0, Allocation::query()->where('bank_transaction_id', $train->id)->whereNotNull('confirmed_at')->count());

        // As shown now: confirmed as you, then issued.
        $fp = $this->service()->toConfirm($draft->fresh())[0]['fp'];
        $issued = $this->service()->issue($draft->fresh(), '2026-06-20', self::shown($draft, ['confirm' => [['source' => 'tx:'.$train->id, 'fp' => $fp]]]));
        $this->assertSame('issued', $issued->status);
        $this->assertSame(['you'], Allocation::query()->where('bank_transaction_id', $train->id)->pluck('confirmed_by')->unique()->values()->all());
        $this->assertSame('you', AuditEvent::query()->where('action', 'allocation.confirmed')->sole()->actor);
    }

    public function test_a_cost_in_a_locked_year_must_be_unticked(): void
    {
        $job = $this->job($this->acme(), '2026-03-20', null, ['fee_pence' => 65000]);
        $train = $this->tx(-4500, '2026-03-19', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)], confirmed: false);
        TaxYear::track('2025-26')->update(['locked_at' => now()]);
        $draft = $this->service()->draftForJob($job);
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', 'tx:'.$train->id.'#j'.$job->id)->sole();
        $this->assertTrue($this->service()->toConfirm($draft->fresh())[0]['locked']);
        $this->assertSame(['“'.$line->description.'” isn’t confirmed and 2025-26 is locked: untick it, or unlock the year first.', 0],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20')));
    }

    public function test_a_cost_ticked_on_two_drafts_is_refused_and_unticked_lines_stay_left_off(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $hotel = $this->tx(-12000, '2026-06-02', 'PREMIER INN');
        $this->decide($hotel, [$this->cost(12000, 'travel_accommodation', $job)]);
        $draft = $this->service()->draftForJob($job);
        $ref = 'tx:'.$hotel->id.'#j'.$job->id;
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', $ref)->sole();
        // You tick it on another draft as well (a draft for the client's costs).
        $other = Invoice::query()->create(['status' => 'draft', 'client_id' => $job->client_id, 'bill_to_name' => 'Acme Events', 'currency' => 'GBP']);
        $other->allLines()->create(['description' => 'Hotel', 'quantity' => 1, 'unit_price_minor' => 12000, 'amount_minor' => 12000, 'source' => 'cost', 'source_ref' => $ref,
            'source_job_id' => $job->id, 'included' => true, 'ticked_by' => 'you']);
        $line->forceFill(['ticked_by' => 'you'])->save();
        $this->assertSame(['“'.$line->description.'” is ticked on draft #'.$other->id.' too: untick it on one of them.', 0],
            $this->refusal(fn () => $this->service()->issue($draft->fresh(), '2026-06-20')));

        // Unticked here: issued without it, and it stays on the invoice as left off.
        $this->service()->line($draft->fresh(), $line->id, 'untick');
        $issued = $this->service()->issue($draft->fresh(), '2026-06-20');
        $this->assertSame([65000, 1, 2], [$issued->total_minor, $issued->lines()->count(), $issued->allLines()->count()]);
        $this->assertFalse($line->fresh()->included);
        $this->expectException(LogicException::class);
        $line->fresh()->forceFill(['included' => true])->save();
    }

    public function test_money_already_waiting_is_suggested_once_it_is_issued(): void
    {
        $job = $this->job($this->acme(), '2026-06-03', null, ['fee_pence' => 65000]);
        $payment = $this->tx(65000, '2026-06-19', 'ACME EVENTS LTD');
        $draft = $this->service()->draftForJob($job);
        $service = $this->service();
        $issued = $service->issue($draft->fresh(), '2026-06-20');

        $this->assertSame(1, $service->matched);
        $line = Allocation::query()->where('bank_transaction_id', $payment->id)->sole();
        $this->assertSame([$issued->id, null], [(int) $line->invoice_id, $line->confirmed_at], 'A suggestion: never confirmed by itself');
        $this->assertInstanceOf(WorkJob::class, $job);
    }

    public function test_your_ticks_can_be_saved_as_the_clients_usual(): void
    {
        $client = $this->client('Beta Crew');
        $job = $this->job($client, '2026-06-03', null, ['fee_pence' => 65000]);
        $train = $this->tx(-4500, '2026-06-02', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        $hotel = $this->tx(-12000, '2026-06-02', 'PREMIER INN');
        $this->decide($hotel, [$this->cost(12000, 'travel_accommodation', $job)]);
        $draft = $this->service()->draftForJob($job);
        $line = InvoiceLine::query()->where('invoice_id', $draft->id)->where('source_ref', 'tx:'.$train->id.'#j'.$job->id)->sole();
        $this->service()->line($draft->fresh(), $line->id, 'tick');

        $rows = $this->service()->ticksAsUsual($draft->fresh());
        $this->assertSame(['rail' => 'at_cost', 'hotel' => 'none'], $rows);
        app(ChargeTermsWriter::class)->set($client, $rows, 'Saved from your ticks');
        $this->assertSame('at_cost', ChargeTerms::forClient($client)['rail']['charge']);
    }
}
