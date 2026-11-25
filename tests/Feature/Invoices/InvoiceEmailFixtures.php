<?php

namespace Tests\Feature\Invoices;

use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\GmailMessage;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Support\Facades\Storage;

/**
 * Shared by the invoice email tests: you, Acme Events with a billing address, the Arena load-in job whose PO came by
 * Gmail (so it has a conversation), and its invoice drafted and issued as today. Generic names only.
 */
trait InvoiceEmailFixtures
{
    protected FakeGmail $gmail;

    /** The PO email's conversation in the fake Gmail: its id, thread and Message-ID. */
    protected array $po = [];

    private int $txs = 0;

    protected function setUpInvoiceEmails(): void
    {
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('business_name', 'Alex Smith Sound');
        Setting::put('business_address', "1 High Street\nLeeds LS1 1AA");
        Setting::put('business_email', 'alex@example.test');
        Setting::put('business_bank_details', "Alex Smith\nSort code 00-00-00\nAccount 00000000");
        config(['app.url' => 'https://expend.example.test']);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->gmail = FakeGmail::install();
    }

    protected function client(array $extra = []): Client
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events'], ['billing_email' => 'accounts@acme.example.test'] + $extra);
        if ($extra !== []) {
            $client->forceFill($extra)->save();
        }

        return $client;
    }

    /** The Arena job, with its PO email from the client's buyer in a Gmail conversation. */
    protected function job(string $title = 'Arena load-in', array $extra = [], bool $po = true): WorkJob
    {
        $job = WorkJob::query()->create($extra + ['title' => $title, 'client_id' => $this->client()->id, 'starts_on' => '2026-06-03', 'ends_on' => '2026-06-04',
            'fee_pence' => 65000, 'status' => 'done', 'po_number' => 'PO 4471']);
        if ($po) {
            $this->po = $this->gmail->receive('Acme Buyer <buyer@acme.example.test>', 'PO 4471 for Arena load-in');
            $doc = Document::query()->create(['source' => 'email', 'original_name' => 'po.eml', 'mime' => 'message/rfc822', 'sha256' => hash('sha256', $title),
                'stored_path' => 'x', 'kind' => 'po', 'work_job_id' => $job->id, 'email_from' => 'Acme Buyer <buyer@acme.example.test>',
                'email_subject' => 'PO 4471 for Arena load-in', 'received_at' => '2026-05-20 09:00:00', 'extraction' => ['po' => ['po_number' => 'PO 4471']]]);
            GmailMessage::query()->create(['message_id' => $this->po['id'], 'thread_id' => $this->po['thread'], 'search' => 'job_documents', 'document_id' => $doc->id]);
        }

        return $job;
    }

    /** The job's invoice, drafted and issued on 5 June. */
    protected function issuedInvoice(?WorkJob $job = null, string $date = '2026-06-05'): Invoice
    {
        $service = app(InvoiceService::class);
        $draft = $service->draftForJob($job ?? $this->job());

        return $service->issue($draft->fresh(), $date)->fresh();
    }

    protected function draftInvoice(?WorkJob $job = null): Invoice
    {
        return app(InvoiceService::class)->draftForJob($job ?? $this->job())->fresh();
    }

    /** Money in from the client, decided by you as income for the job (or confirmed or not, naming the invoice). */
    protected function income(int $pence, WorkJob $job, ?Invoice $invoice = null, bool $confirmed = true, string $on = '2026-07-03'): BankTransaction
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank', 'provider' => 'starling']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 'july.csv'],
            ['file_sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => 'ACME EVENTS LTD', 'counterparty' => 'Acme Events Ltd', 'merchant_key' => 'ACME EVENTS',
            'amount_pence' => $pence, 'raw' => [], 'fingerprint' => 'invoice-email-'.(++$this->txs)]);
        $lines = AllocationWriter::lines($pence, 'business_income', (int) Category::query()->where('code', 'turnover')->value('id'), $job->id);
        if ($invoice !== null) {
            $lines[0]['invoice_id'] = $invoice->id;
        }
        $confirmed
            ? app(AllocationWriter::class)->decide($tx, $lines)
            : app(AllocationWriter::class)->decide($tx, $lines, 'matcher', false, 'Matches invoice', null, new Provenance('suggest.invoice', ['inv:'.$invoice?->id], 1));

        return $tx;
    }
}
