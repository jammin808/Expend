<?php

namespace Tests\Feature\Invoices;

use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoiceDraftSync;
use App\Domain\Invoices\InvoiceMailer;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\TodoList;
use App\Mail\InvoiceEmail;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Golden pin (build slice S0, the client-area design): today's invoice flow, pasted from running HEAD 2d54ad6.
 *  - draftForJob, updateDraft and issue for a fixed job: the lines, the number, the total and the PDF (its sha256 with
 *    the times and the random file id dompdf writes taken out, so the same invoice always gives the same hash);
 *  - InvoiceMailer sending the invoice and a reminder, with what each email says and when each is held back;
 *  - the to-do list's invoice items: invoice a finished job, send an invoice, overdue (unpaid, part-paid, paid in
 *    another currency, a payment waiting), less than its PO and recharge a booking.
 *
 * Later slices keep the outputs: drafts that follow the job (S6) list the same lines for a job with only a fee and nothing
 * linked to it, and an invoice made before the release prints and reads as it does now. If a later slice changes how
 * these are called (updateDraft's line payload, issue()'s arguments), it changes the call here, not the values; a value
 * changes only where the design changes that behaviour on purpose, and that slice says so.
 */
class GoldenInvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    /** sha256 of the Arena invoice's PDF, with its creation and change times and its random file id blanked. */
    private const ARENA_PDF = '7531ebc0b99e427e07345230b2ae75b26c28662a1d7964e04e030cd5dae14fee';

    private ?Account $account = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('business_email', 'alex@example.test');
        Setting::put('business_bank_details', "Alex Smith\nSort code 00-00-00\nAccount 00000000");
        Setting::put('mail_method', 'server');
        config(['app.url' => 'https://expend.example.test', 'mail.from.address' => 'noreply@expend.example.test']);
        $this->at('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $when): void
    {
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }

    private function client(): Client
    {
        return Client::query()->firstOrCreate(['name' => 'Acme Events']);
    }

    private function job(string $title, string $from, ?string $to = null, ?int $fee = null, bool $client = true): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $client ? $this->client()->id : null, 'starts_on' => $from, 'ends_on' => $to ?? $from,
            'fee_pence' => $fee, 'status' => 'booked']);
    }

    /** The PDF as bytes that are the same each time it's made: dompdf's times and random file id blanked. */
    private static function steady(string $pdf): string
    {
        return (string) preg_replace(['/\/(CreationDate|ModDate) \(D:[^)]*\)/', '/\/ID\s*\[\s*<[0-9a-f]+>\s*<[0-9a-f]+>\s*\]/'], ['/$1 (D:)', '/ID[<><>]'], $pdf);
    }

    /** @return list<array{0:string, 1:string, 2:int, 3:int, 4:int, 5:?string}> */
    private static function lines(Invoice $invoice): array
    {
        return InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn (InvoiceLine $l) => [$l->description, (string) $l->quantity, $l->unit_price_minor, $l->amount_minor, (int) $l->sort, $l->source_quote])->all();
    }

    private static function fields(Invoice $invoice): array
    {
        return [$invoice->status, $invoice->number, $invoice->bill_to_name, $invoice->bill_to_email, $invoice->bill_to_address, $invoice->po_number, $invoice->currency,
            $invoice->total_minor, $invoice->po_total_minor, $invoice->notes, $invoice->issue_date?->format('Y-m-d'), $invoice->due_date?->format('Y-m-d')];
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

    /**
     * The three lines the Arena draft is saved with: the job's fee line changed in place (by its id, S6's per-line
     * save), and two lines of your own.
     */
    private static function arenaLines(Invoice $draft): array
    {
        $fee = $draft->allLines()->sole();

        return [
            ['id' => $fee->id, 'version' => InvoiceDraftSync::version($fee), 'description' => 'Arena load-in: crew, 3 and 4 Jun 2026', 'quantity' => '2', 'unit_price' => '325.00'],
            ['description' => 'Taxi to the venue', 'quantity' => '1', 'unit_price' => '18.40'],
            ['description' => 'Overtime (hours)', 'quantity' => '1.5', 'unit_price' => '33.33'],
        ];
    }

    /** The Arena invoice as it's made today: a draft from the job, its lines changed, then issued on 5 June. */
    private function arena(): Invoice
    {
        $service = app(InvoiceService::class);
        $job = $this->job('Arena load-in', '2026-06-03', '2026-06-04', 65000);
        $draft = $service->draftForJob($job);
        $draft = $service->updateDraft($draft, [
            'bill_to_email' => 'accounts@example.test', 'bill_to_address' => "Acme Events\n2 Dock Road\nLondon E16 1XL",
        ], self::arenaLines($draft));

        return $service->issue($draft->fresh(), '2026-06-05')->fresh();
    }

    public function test_draft_for_a_job_update_and_issue(): void
    {
        $service = app(InvoiceService::class);
        $job = $this->job('Arena load-in', '2026-06-03', '2026-06-04', 65000);

        // A draft from the job: its fee as one line, the client's name, the usual terms. S6 changes the line's words on
        // purpose: a fee line names the job's dates (CostWords::dates), so this two-day job's says 3–4 Jun, not 3 Jun.
        $draft = $service->draftForJob($job);
        $this->assertSame(['draft', null, 'Acme Events', null, null, null, 'GBP', 65000, null, 'Payment due within 30 days.', null, null], self::fields($draft->fresh()));
        $this->assertSame([$this->client()->id, $job->id], [$draft->client_id, $draft->work_job_id]);
        $this->assertSame([['Arena load-in (3–4 Jun 2026)', '1.00', 65000, 65000, 0, null]], self::lines($draft));
        $drafted = AuditEvent::query()->where('action', 'invoice.drafted')->sole();
        $this->assertSame(['you', null, ['from_job' => $job->id]], [$drafted->actor, $drafted->before, $drafted->after]);

        // Its lines changed: each priced exactly in pence (1.5 × £33.33 rounds half up to £50.00).
        $draft = $service->updateDraft($draft, ['bill_to_email' => 'accounts@example.test', 'bill_to_address' => "Acme Events\n2 Dock Road\nLondon E16 1XL"],
            self::arenaLines($draft));
        $this->assertSame([
            ['Arena load-in: crew, 3 and 4 Jun 2026', '2.00', 32500, 65000, 0, null],
            ['Taxi to the venue', '1.00', 1840, 1840, 1, null],
            ['Overtime (hours)', '1.50', 3333, 5000, 2, null],
        ], self::lines($draft));
        $this->assertSame(['draft', null, 'Acme Events', 'accounts@example.test', "Acme Events\n2 Dock Road\nLondon E16 1XL", null, 'GBP', 71840, null, 'Payment due within 30 days.', null, null],
            self::fields($draft->fresh()));
        $this->assertSame(['total' => 71840], AuditEvent::query()->where('action', 'invoice.updated')->sole()->after);

        // A line it can't price is refused, and the draft stays as it was.
        $this->assertSame('Line 2 needs a description, a quantity (up to 2 decimals) and a price.', $this->refusal(fn () => $service->updateDraft($draft->fresh(), [], [
            ['description' => 'Crew', 'quantity' => '1', 'unit_price' => '650.00'], ['description' => 'Taxi', 'quantity' => '1.255', 'unit_price' => '18.40'],
        ])));
        $this->assertSame(71840, $draft->fresh()->total_minor);
        $this->assertCount(3, self::lines($draft));

        // Issued on 5 June: the next number, due in 30 days, and its PDF kept with its sha256.
        $issued = $service->issue($draft->fresh(), '2026-06-05')->fresh();
        $this->assertSame(['issued', 'INV-0001', 'Acme Events', 'accounts@example.test', "Acme Events\n2 Dock Road\nLondon E16 1XL", null, 'GBP', 71840, null, 'Payment due within 30 days.',
            '2026-06-05', '2026-07-05'], self::fields($issued));
        $this->assertSame(['2026-06-10 10:00:00', 'invoices/INV-0001.pdf'], [$issued->issued_at->format('Y-m-d H:i:s'), $issued->pdf_path]);
        $pdf = Storage::disk('local')->get('invoices/INV-0001.pdf');
        $this->assertSame(hash('sha256', $pdf), $issued->pdf_sha256);
        $this->assertSame(self::ARENA_PDF, hash('sha256', self::steady($pdf)), 'The PDF reads as it did');
        $this->assertSame(self::ARENA_PDF, hash('sha256', self::steady($service->renderPdf($issued->fresh(['lines', 'client'])))), 'Made again, the same');
        $this->assertSame(3, count(self::lines($issued)));
        $this->assertEquals(['number' => 'INV-0001', 'total' => 71840, 'pdf_sha256' => $issued->pdf_sha256], AuditEvent::query()->where('action', 'invoice.issued')->sole()->after);

        // Issued: it can't change, or be issued again.
        $locked = 'This invoice has been issued and can’t be changed. Void it and issue a new one.';
        $this->assertSame([$locked, $locked], [
            $this->refusal(fn () => $service->updateDraft($issued, [], [['description' => 'Crew', 'quantity' => '1', 'unit_price' => '1.00']])),
            $this->refusal(fn () => $service->issue($issued)),
        ]);

        // A draft with nothing on it, or for no one, isn't issued; the next one issued takes the next number.
        $empty = $service->draftForJob($this->job('Gala dinner', '2026-06-20'));
        $this->assertSame([], self::lines($empty));
        $this->assertSame('Add at least one line before issuing.', $this->refusal(fn () => $service->issue($empty->fresh())));
        $nobody = $service->draftForJob($this->job('Private party', '2026-06-21', fee: 20000, client: false));
        $this->assertNull($nobody->bill_to_name);
        $this->assertSame('Add who the invoice is for before issuing.', $this->refusal(fn () => $service->issue($nobody->fresh())));
        $service->updateDraft($empty->fresh(), ['bill_to_name' => 'Acme Events'], [['description' => 'Gala dinner', 'quantity' => '1', 'unit_price' => '400.00']]);
        $this->assertSame('INV-0002', $service->issue($empty->fresh(), '2026-06-21')->number);
    }

    public function test_the_mailer_sends_the_invoice_and_a_reminder(): void
    {
        Mail::fake();
        $invoice = $this->arena();
        $mailer = app(InvoiceMailer::class);

        // Too early for a reminder.
        $this->assertSame('Invoice INV-0001 isn’t overdue (it’s due on 5 Jul 2026), so it’s too early for a reminder.',
            $this->refusal(fn () => $mailer->send($invoice->fresh(), reminder: true)));
        $this->assertSame('That email address doesn’t look right. Send to one address at a time, like accounts@client.co.uk.',
            $this->refusal(fn () => $mailer->send($invoice->fresh(), 'accounts@example.test, finance@example.test')));

        // Sent to the address on the invoice.
        $this->assertSame('Invoice INV-0001 is on its way to accounts@example.test.', $mailer->send($invoice->fresh()));
        $invoice->refresh();
        $this->assertSame(['2026-06-10 10:00:00', 'accounts@example.test', null, 0], [$invoice->sent_at->format('Y-m-d H:i:s'), $invoice->sent_to, $invoice->reminded_at, (int) $invoice->reminder_count]);
        $this->assertMatchesRegularExpression('/^invoice-inv-0001\.[0-9a-f]{16}@example\.test$/', $invoice->message_id);
        $sent = AuditEvent::query()->where('action', 'invoice.sent')->sole();
        $this->assertEquals(['to' => 'accounts@example.test', 'number' => 'INV-0001', 'message_id' => $invoice->message_id], $sent->after);
        $this->assertSame('you', $sent->actor);
        $queued = Mail::queued(InvoiceEmail::class)->values();
        $this->assertCount(1, $queued);
        /** @var InvoiceEmail $email */
        $email = $queued[0];
        $this->assertTrue($email->hasTo('accounts@example.test'));
        $this->assertFalse($email->reminder);
        $this->assertSame('Invoice INV-0001 from Alex Smith Sound', $email->envelope()->subject);
        $this->assertSame('<p>Hello,</p><p>Please find attached invoice INV-0001 for £718.40, due by 5 July 2026.</p>'
            .'<p>Bank details:<br>Alex Smith<br />'."\n".'Sort code 00-00-00<br />'."\n".'Account 00000000</p><p>Many thanks,<br>Alex Smith Sound</p>', $email->content()->htmlString);
        $this->assertSame(['INV-0001.pdf'], array_map(fn ($a) => $a->as, $email->attachments()));

        // A second press of a one-tap button doesn't send it again.
        $this->assertSame('Invoice INV-0001 was already emailed to accounts@example.test on 10 Jun 2026, so it wasn’t sent again.', $mailer->send($invoice->fresh(), once: true));

        // Overdue: a reminder, to the same address.
        $this->at('2026-07-20 10:00:00');
        $this->assertSame('Reminder for INV-0001 is on its way to accounts@example.test.', $mailer->send($invoice->fresh(), reminder: true));
        $invoice->refresh();
        $this->assertSame(['2026-07-20 10:00:00', 1], [$invoice->reminded_at->format('Y-m-d H:i:s'), (int) $invoice->reminder_count]);
        $this->assertEquals(['to' => 'accounts@example.test', 'number' => 'INV-0001', 'message_id' => $invoice->message_id],
            AuditEvent::query()->where('action', 'invoice.reminder_sent')->sole()->after);
        $reminder = Mail::queued(InvoiceEmail::class)->values()[1];
        $this->assertTrue($reminder->reminder);
        $this->assertSame('Reminder: invoice INV-0001 from Alex Smith Sound', $reminder->envelope()->subject);
        $this->assertSame('<p>Hello,</p><p>A reminder that invoice INV-0001, for £718.40, was due on 5 July 2026 and I can’t see a payment yet. The invoice is attached again.</p>'
            .'<p>If it’s already been paid, thank you, and please ignore this.</p>'
            .'<p>Bank details:<br>Alex Smith<br />'."\n".'Sort code 00-00-00<br />'."\n".'Account 00000000</p><p>Many thanks,<br>Alex Smith Sound</p>', $reminder->content()->htmlString);

        // Held back: a repeat press the same day, then something come in, then paid in full.
        $this->assertSame('A reminder for invoice INV-0001 went on 20 Jul 2026 at 10:00, so another wasn’t sent. To send one anyway, use the invoice’s page.',
            $mailer->send($invoice->fresh(), reminder: true, once: true));
        $this->at('2026-07-23 10:00:00');
        $job = WorkJob::query()->where('title', 'Arena load-in')->sole();
        $this->income(10000, $job);
        $this->assertSame('No reminder was sent: £100.00 of it has come in, and the reminder tells the client no payment has been seen. To send one anyway, use the invoice’s page.',
            $mailer->send($invoice->fresh(), reminder: true, once: true));
        $this->income(61840, $job);
        $this->assertSame('Invoice INV-0001 is paid in full, so no reminder was sent.', $mailer->send($invoice->fresh(), reminder: true, once: true));
        $this->assertSame('Invoice INV-0001 is paid in full, so no reminder was sent.', $this->refusal(fn () => $mailer->send($invoice->fresh(), reminder: true)));
        $this->assertCount(2, Mail::queued(InvoiceEmail::class));
        $this->assertSame(1, (int) $invoice->fresh()->reminder_count);
    }

    private function tx(int $pence, string $name, string $on = '2026-06-08'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->account->id, 'original_filename' => 'june.csv'],
            ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'golden-flow-'.$this->n]);
    }

    /** Income decided by you for the job (not naming an invoice). */
    private function income(int $pence, WorkJob $job, ?BankTransaction $tx = null): void
    {
        $tx ??= $this->tx($pence, 'Acme Events Ltd');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, 'business_income', (int) Category::query()->where('code', 'turnover')->value('id'), $job->id));
    }

    /** An invoice as an existing ledger holds it (made before this release). */
    private function invoice(WorkJob $job, ?string $number, int $total, ?string $issued, array $extra = []): Invoice
    {
        return Invoice::query()->create($extra + [
            'status' => $issued ? 'issued' : 'draft', 'number' => $number, 'client_id' => $job->client_id, 'work_job_id' => $job->id, 'currency' => 'GBP', 'total_minor' => $total,
            'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@example.test', 'issue_date' => $issued,
            'due_date' => $issued ? CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d') : null, 'issued_at' => $issued ? $issued.' 09:00:00' : null,
            'notes' => 'Payment due within 30 days.',
        ]);
    }

    private function recharge(WorkJob $job, int $amount, string $provider, string $on): TravelItem
    {
        return TravelItem::query()->create(['kind' => 'hotel', 'provider' => $provider, 'place' => $provider.' '.$job->title, 'status' => 'booked', 'starts_on' => $on, 'ends_on' => $on,
            'amount_minor' => $amount, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'decision' => 'recharge',
            'decided_at' => now()]);
    }

    public function test_the_to_do_lists_invoice_items(): void
    {
        $turnover = (int) Category::query()->where('code', 'turnover')->value('id');

        // Finished with no invoice issued: invoice it (one with a draft ready, one without).
        $leeds = $this->job('Leeds conference', '2026-06-01');
        $bristol = $this->job('Bristol fair', '2026-06-02');
        $bristolDraft = $this->invoice($bristol, null, 30000, null);

        // Overdue: unpaid and never emailed; part-paid; paid in another currency; a payment waiting in the Inbox;
        // reminded this morning.
        $cardiff = $this->job('Cardiff tour', '2026-05-01');
        $unpaid = $this->invoice($cardiff, 'INV-0001', 50000, '2026-05-02');
        $gala = $this->job('Gala dinner', '2026-05-05');
        $partPaid = $this->invoice($gala, 'INV-0002', 45000, '2026-05-06', ['sent_at' => '2026-05-06 10:00:00', 'sent_to' => 'accounts@example.test']);
        $this->income(10000, $gala);
        $vienna = $this->job('Vienna gala', '2026-05-07');
        $euros = $this->invoice($vienna, 'INV-0003', 100000, '2026-05-08', ['currency' => 'EUR', 'sent_at' => '2026-05-08 10:00:00', 'sent_to' => 'accounts@example.test']);
        $this->income(85000, $vienna);
        $birmingham = $this->job('Birmingham launch', '2026-05-03');
        $waiting = $this->invoice($birmingham, 'INV-0004', 20000, '2026-05-04', ['sent_at' => '2026-05-04 10:00:00', 'sent_to' => 'accounts@example.test']);
        $tx = $this->tx(20000, 'Acme Events Ltd');
        $lines = AllocationWriter::lines(20000, 'business_income', $turnover, $birmingham->id);
        $lines[0]['invoice_id'] = $waiting->id;
        app(AllocationWriter::class)->decide($tx, $lines, 'matcher', false, 'Matches invoice INV-0004', null, new Provenance('suggest.invoice', ['inv:'.$waiting->id], 1));
        $manchester = $this->job('Manchester awards', '2026-05-08');
        $reminded = $this->invoice($manchester, 'INV-0005', 30000, '2026-05-09',
            ['sent_at' => '2026-05-09 10:00:00', 'sent_to' => 'accounts@example.test', 'reminded_at' => '2026-06-10 08:00:00', 'reminder_count' => 1]);

        // Issued and never emailed: with an address, and without one.
        $paris = $this->job('Paris show', '2026-06-06');
        $unsent = $this->invoice($paris, 'INV-0006', 30000, '2026-06-08');
        $bath = $this->job('Bath festival', '2026-06-07');
        $noAddress = $this->invoice($bath, 'INV-0007', 12500, '2026-06-08', ['bill_to_email' => null]);

        // Under way: less than the PO (a draft and an issued invoice), and bookings to recharge (one job invoiced, one not).
        $edinburgh = $this->job('Edinburgh fringe', '2026-06-08', '2026-06-20');
        $short = $this->invoice($edinburgh, 'INV-0008', 120000, '2026-06-09', ['po_total_minor' => 150000, 'sent_at' => '2026-06-09 10:00:00', 'sent_to' => 'accounts@example.test']);
        $glasgow = $this->job('Glasgow tour', '2026-06-09', '2026-06-25');
        $shortDraft = $this->invoice($glasgow, null, 80000, null, ['po_total_minor' => 95000]);
        $hotel = $this->recharge($edinburgh, 18000, 'Premier Inn', '2026-06-08');
        $glasgowHotel = $this->recharge($glasgow, 21000, 'Travelodge', '2026-06-09');

        $items = collect(TodoList::build(100)['items'])->keyBy('key');
        $fields = ['group', 'priority', 'title', 'detail', 'href', 'actions', 'amount', 'currency', 'due_on'];
        $got = fn (string $key) => $items->has($key) ? Arr::only($items[$key], $fields) : null;
        $action = fn (string $code, int $id, string $label) => ['code' => $code, 'id' => (string) $id, 'label' => $label, 'style' => 'default'];

        // S10 (changed on purpose): a booked job that finished in the last 60 days asks "Is it done?" (job_done:) in
        // place of "Invoice <job>", with Done, Not yet and Cancelled; marking it done gets its invoice ready.
        $ghost = fn (string $code, int|string $id, string $label) => ['code' => $code, 'id' => (string) $id, 'label' => $label, 'style' => 'ghost'];
        $this->assertSame([
            'job_done:'.$leeds->id => ['group' => 'invoice', 'priority' => 2, 'title' => 'Is Leeds conference done?',
                'detail' => 'Acme Events · last day was Mon 1 Jun 2026. Marking it done gets its invoice ready to check.', 'href' => '/jobs/'.$leeds->id,
                'actions' => [$action('job_done', $leeds->id, 'Done'), $ghost('job_not_yet', $leeds->id, 'Not yet'), $ghost('job_cancel', $leeds->id, 'Cancelled…')]],
            'job_done:'.$bristol->id => ['group' => 'invoice', 'priority' => 2, 'title' => 'Is Bristol fair done?',
                'detail' => 'Acme Events · last day was Tue 2 Jun 2026. Marking it done gets its invoice ready to check.', 'href' => '/jobs/'.$bristol->id,
                'actions' => [$action('job_done', $bristol->id, 'Done'), $ghost('job_not_yet', $bristol->id, 'Not yet'), $ghost('job_cancel', $bristol->id, 'Cancelled…')]],
        ], ['job_done:'.$leeds->id => $got('job_done:'.$leeds->id), 'job_done:'.$bristol->id => $got('job_done:'.$bristol->id)]);
        $this->assertNotNull($bristolDraft);
        foreach ([$leeds, $bristol, $cardiff, $gala, $vienna, $birmingham, $manchester, $paris, $bath, $edinburgh, $glasgow] as $job) {
            $this->assertNull($got('invoice:'.$job->id), $job->title.': asked whether it’s done, invoiced, or not finished');
        }

        // S10 (changed on purpose): an issued invoice that hasn't been sent (INV-0001) is listed to send, never as
        // overdue; part-paid offers "Close it" for what's short (income design v3), and money in another currency
        // "It's paid (in another currency)". The reminder button (inv_chase) keeps its label "Send a reminder" while
        // Gmail can't be used: it emails the reminder from Expend, only when you press it.
        $this->assertSame([
            'unpaid' => null,
            'part-paid' => ['group' => 'income', 'priority' => 1, 'title' => 'Invoice INV-0002 part-paid',
                'detail' => '£100.00 of £450.00 received from Acme Events, due 5 Jun 2026 (5 days ago); £350.00 still to come.',
                'href' => '/invoices/'.$partPaid->id, 'actions' => [$ghost('inv_close', $partPaid->id.':sp', 'Close it: £350 isn’t coming')], 'amount' => 35000, 'currency' => 'GBP', 'due_on' => '2026-06-05'],
            'another currency' => ['group' => 'income', 'priority' => 1, 'title' => 'Invoice INV-0003: check it’s been paid',
                'detail' => '€1,000.00 from Acme Events, due 7 Jun 2026 (3 days ago). £850.00 came in for this job in another currency, so Expend can’t tell how much of the invoice it covers. If it’s all paid, mark this not relevant.',
                'href' => '/invoices/'.$euros->id, 'actions' => [$ghost('inv_close', $euros->id.':fx', 'It’s paid (in another currency)')], 'amount' => 100000, 'currency' => 'EUR', 'due_on' => '2026-06-07'],
            'waiting' => ['group' => 'income', 'priority' => 1, 'title' => 'Invoice INV-0004 unpaid',
                'detail' => '£200.00 from Acme Events, due 3 Jun 2026 (7 days ago). £200.00 that may be for it is waiting in the Inbox: decide it first.',
                'href' => '/inbox', 'actions' => [], 'amount' => 20000, 'currency' => 'GBP', 'due_on' => '2026-06-03'],
            'reminded' => ['group' => 'income', 'priority' => 1, 'title' => 'Invoice INV-0005 unpaid',
                'detail' => '£300.00 from Acme Events, due 8 Jun 2026 (2 days ago). Reminder sent 10 Jun 2026, 08:00.',
                'href' => '/invoices/'.$reminded->id, 'actions' => [], 'amount' => 30000, 'currency' => 'GBP', 'due_on' => '2026-06-08'],
        ], [
            'unpaid' => $got('invoice_overdue:'.$unpaid->id), 'part-paid' => $got('invoice_overdue:'.$partPaid->id), 'another currency' => $got('invoice_overdue:'.$euros->id),
            'waiting' => $got('invoice_overdue:'.$waiting->id), 'reminded' => $got('invoice_overdue:'.$reminded->id),
        ]);

        $this->assertSame([
            'overdue' => ['group' => 'invoice', 'priority' => 2, 'title' => 'Send invoice INV-0001', 'detail' => '£500.00 to Acme Events at accounts@example.test',
                'href' => '/invoices/'.$unpaid->id, 'actions' => [$action('inv_send', $unpaid->id, 'Email it')], 'amount' => 50000, 'currency' => 'GBP'],
            'with address' => ['group' => 'invoice', 'priority' => 2, 'title' => 'Send invoice INV-0006', 'detail' => '£300.00 to Acme Events at accounts@example.test',
                'href' => '/invoices/'.$unsent->id, 'actions' => [$action('inv_send', $unsent->id, 'Email it')], 'amount' => 30000, 'currency' => 'GBP'],
            'no address' => ['group' => 'invoice', 'priority' => 2, 'title' => 'Send invoice INV-0007', 'detail' => '£125.00 to Acme Events: add their email address on the invoice',
                'href' => '/invoices/'.$noAddress->id, 'actions' => [], 'amount' => 12500, 'currency' => 'GBP'],
        ], ['overdue' => $got('unsent:'.$unpaid->id), 'with address' => $got('unsent:'.$unsent->id), 'no address' => $got('unsent:'.$noAddress->id)]);
        foreach ([$partPaid, $euros, $waiting, $reminded, $short] as $sent) {
            $this->assertNull($got('unsent:'.$sent->id), $sent->number.' was emailed');
        }

        $this->assertSame([
            'issued' => ['group' => 'revenue', 'priority' => 1, 'title' => 'Invoice INV-0008 is £300.00 less than its PO',
                'detail' => 'The PO authorises £1,500.00 and the invoice totals £1,200.00. Check nothing was left off (an issued invoice can be voided and re-issued).',
                'href' => '/invoices/'.$short->id, 'actions' => [], 'amount' => 30000, 'currency' => 'GBP'],
            'draft' => ['group' => 'revenue', 'priority' => 1, 'title' => 'Draft invoice is £150.00 less than its PO',
                'detail' => 'The PO authorises £950.00 and the invoice totals £800.00. Check nothing was left off.',
                'href' => '/invoices/'.$shortDraft->id, 'actions' => [], 'amount' => 15000, 'currency' => 'GBP'],
            'recharge, invoiced' => ['group' => 'revenue', 'priority' => 1, 'title' => 'Recharge Premier Inn to the client',
                'detail' => "Hotel 8 Jun 2026 for Edinburgh fringe: check it's on invoice INV-0008.", 'href' => '/invoices/'.$short->id, 'actions' => [], 'amount' => 18000, 'currency' => 'GBP'],
            'recharge, not invoiced' => ['group' => 'revenue', 'priority' => 1, 'title' => 'Recharge Travelodge to the client',
                'detail' => 'Hotel 9 Jun 2026 for Glasgow tour: add it to the invoice when you draft it.', 'href' => '/jobs/'.$glasgow->id, 'actions' => [], 'amount' => 21000, 'currency' => 'GBP'],
        ], [
            'issued' => $got('underbilled:'.$short->id), 'draft' => $got('underbilled:'.$shortDraft->id),
            'recharge, invoiced' => $got('recharge:'.$hotel->id), 'recharge, not invoiced' => $got('recharge:'.$glasgowHotel->id),
        ]);
    }
}
