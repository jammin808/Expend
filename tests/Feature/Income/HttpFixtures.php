<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Shared by the owner HTTP tests (build slice S9): you signed in, a bank account, and helpers for payments, jobs,
 * invoices and lines, plus requests sent as the pages send them (an Inertia visit, or a sheet reading JSON). Generic
 * names only.
 */
trait HttpFixtures
{
    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    protected function signIn(): void
    {
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));
    }

    protected function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    protected function client(string $name = 'Acme Events', array $extra = []): Client
    {
        return Client::query()->create(['name' => $name] + $extra);
    }

    protected function job(string $title, ?Client $client = null, string $on = '2026-05-01', array $extra = []): WorkJob
    {
        return WorkJob::query()->create($extra + ['title' => $title, 'client_id' => $client?->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => 'done']);
    }

    protected function invoice(string $number, int $total, ?WorkJob $job = null, ?Client $client = null, array $extra = []): Invoice
    {
        return Invoice::query()->create($extra + ['status' => 'issued', 'number' => $number, 'client_id' => $client?->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => $client?->name ?? 'Acme Events', 'issue_date' => '2026-05-15', 'due_date' => '2026-06-14']);
    }

    protected function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-28'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('e', 64),
            'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'http-'.$this->n]);
    }

    protected function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence] + $links;
    }

    /** Decided by you and confirmed (or, with $by other than you, an open suggestion). */
    protected function decide(BankTransaction $tx, array $lines, string $by = 'you', string $method = 'suggest.invoice'): void
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines, $by, $by === 'you', $by === 'you' ? null : 'Suggested', null,
            $by === 'you' ? null : new Provenance($method)));
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:bool}> kind, job, invoice, client, amount, confirmed */
    protected function stored(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->invoice_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null])->all();
    }

    /** Sent the way an Inertia page sends it: JSON, back to the page it came from. */
    protected function visit(string $method, string $uri, array $body = [], ?string $from = null): TestResponse
    {
        return $this->from($from ?? '/inbox')->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml', 'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], json_encode($body));
    }

    /** How many queries a request makes. */
    protected function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }
}
