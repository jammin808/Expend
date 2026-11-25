<?php

namespace Tests\Feature\Charges;

use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Records for the charges tests (build slice S5): payments and hand-entered expenses decided by you (or suggested),
 * bookings, trips, and invoices whose lines cite their sources. Generic names only.
 */
trait ChargeFixtures
{
    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    private function startCharges(string $now = '2026-06-20 10:00:00'): void
    {
        Queue::fake();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    private function stopCharges(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function client(string $name = 'Acme Events'): Client
    {
        return Client::query()->create(['name' => $name]);
    }

    private function job(?Client $client, string $from = '2026-06-03', ?string $to = null, array $extra = []): WorkJob
    {
        return WorkJob::query()->create($extra + ['title' => 'Arena load-in '.(++$this->n), 'client_id' => $client?->id, 'starts_on' => $from, 'ends_on' => $to ?? $from,
            'status' => 'done', 'location' => 'Leeds Arena, LS2 8BY']);
    }

    /** A bank line: negative for money out. */
    private function tx(int $pence, string $on = '2026-06-03', string $payee = 'TRAINLINE', array $extra = []): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'june.csv', 'file_sha256' => str_repeat('c', 64),
            'stored_path' => 'x', 'status' => 'committed']);

        return BankTransaction::query()->create($extra + ['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $payee, 'counterparty' => $payee, 'merchant_key' => $payee, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'charge-'.(++$this->n)]);
    }

    /** An expense you added by hand: negative for spending. */
    private function expense(int $pence, string $on = '2026-06-03', string $description = 'Trainline ticket'): ManualExpense
    {
        return ManualExpense::query()->create(['spent_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $description,
            'merchant_key' => strtoupper($description), 'paid_with' => 'cash', 'amount_pence' => $pence]);
    }

    /**
     * Decide a payment's lines as you (confirmed), or suggest them unconfirmed.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function decide(BankTransaction|ManualExpense $source, array $lines, bool $confirmed = true): void
    {
        $lines = array_map(fn (array $l) => $l + ['business_use_pct' => 100], $lines);
        $ok = $confirmed ? app(AllocationWriter::class)->decide($source, $lines) : app(AllocationWriter::class)->decide($source, $lines, 'matcher', false, 'Looks like travel');
        $this->assertTrue($ok, 'The lines were written');
    }

    /** A cost line: what it cost (positive), on a job (or with a client and no job). */
    private function cost(int $pence, string $category = 'travel_rail', ?WorkJob $job = null, ?int $client = null): array
    {
        return ['kind' => 'business_expense', 'category_id' => $this->cat($category), 'work_job_id' => $job?->id, 'client_id' => $client, 'amount_pence' => -$pence];
    }

    private function income(int $pence, ?WorkJob $job = null, ?int $client = null, ?int $invoice = null): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $job?->id, 'client_id' => $client, 'invoice_id' => $invoice, 'amount_pence' => $pence];
    }

    private function booking(WorkJob $job, string $kind, string $provider, ?int $amount, string $on = '2026-06-03', array $extra = []): TravelItem
    {
        return TravelItem::query()->create($extra + ['kind' => $kind, 'provider' => $provider, 'place' => $kind === 'hotel' ? $provider.' Leeds' : 'London',
            'destination' => $kind === 'hotel' ? null : 'Leeds', 'status' => 'booked', 'starts_on' => $on, 'ends_on' => $kind === 'hotel' ? CarbonImmutable::parse($on)->addDay()->format('Y-m-d') : $on,
            'amount_minor' => $amount, 'currency' => 'GBP', 'payment' => 'paid', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'job_linked_by' => 'you']);
    }

    private function trip(WorkJob $job, string $miles, string $on = '2026-06-03', string $method = 'simplified'): MileageTrip
    {
        $vehicle = Vehicle::query()->firstOrCreate(['name' => 'Van'], ['type' => 'car_van', 'method' => $method]);

        return MileageTrip::query()->create(['vehicle_id' => $vehicle->id, 'work_job_id' => $job->id, 'trip_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'from_place' => 'Home, 12 Oak Road, LS6 2AB', 'to_place' => 'Leeds Arena, LS2 8BY', 'miles' => $miles, 'purpose' => 'Arena load-in']);
    }

    /**
     * An invoice for a job, its lines written while it's a draft, then given its status. Each line:
     * [ref, amount, included?, quantity?, source?].
     *
     * @param  list<array{0:?string, 1:int, 2?:bool, 3?:string, 4?:?string}>  $lines
     */
    private function invoice(?WorkJob $job, array $lines, string $status = 'issued', ?string $number = null, bool $tracked = true, string $issued = '2026-06-10', string $currency = 'GBP', array $extra = []): Invoice
    {
        $invoice = Invoice::query()->create($extra + ['status' => 'draft', 'number' => $status === 'draft' ? null : ($number ?? 'INV-'.str_pad((string) (++$this->n), 4, '0', STR_PAD_LEFT)),
            'client_id' => $job?->client_id, 'work_job_id' => $job?->id, 'currency' => $currency, 'bill_to_name' => 'Acme Events',
            'issue_date' => $status === 'draft' ? null : $issued, 'issued_at' => $status === 'draft' ? null : $issued.' 09:00:00',
            'synced_at' => $tracked ? $issued.' 08:00:00' : null]);
        $total = 0;
        foreach (array_values($lines) as $i => $l) {
            [$ref, $amount] = $l;
            $included = $l[2] ?? true;
            $source = $l[4] ?? ($ref === null ? null : (str_starts_with($ref, 'trip:') ? 'mileage' : (str_starts_with($ref, 'job:') ? explode('#', $ref)[1] : 'cost')));
            $invoice->allLines()->create(['description' => 'Line '.($i + 1), 'quantity' => $l[3] ?? '1', 'unit_price_minor' => $amount, 'amount_minor' => $amount, 'sort' => $i,
                'source' => $source, 'source_ref' => $ref, 'source_job_id' => $job?->id, 'included' => $included]);
            $total += $included ? $amount : 0;
        }
        $invoice->update(['total_minor' => $total]);
        if ($status !== 'draft') {
            $invoice->update(['status' => $status]);
        }

        return $invoice->fresh();
    }
}
