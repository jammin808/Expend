<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\YearChecks;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Golden pin (build slice S0, income v3 and the client-area design): the return's figures for a legacy year, as
 * ReturnSummary::for and YearChecks::figures give them today (pasted from running HEAD 2d54ad6), and a year locked with
 * its snapshot that verifyLock() still finds unchanged. Every later slice keeps this green: the new columns (a client on
 * a line, closed invoices, charge statuses) change no figure for today's data.
 *
 * The year holds income for a job, income the matcher linked to an invoice (accepted by you), turnover with no job or
 * client, other business income, a refund to a client, allowable and disallowable expenses, a cost refunded to you,
 * a business and personal split, cash spending, money that isn't business, and mileage.
 */
class GoldenReturnFiguresTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        // Only the spreadsheets these tests kept with a lock.
        foreach (AuditEvent::query()->where('action', 'tax_year.locked')->get() as $e) {
            $file = $e->after['snapshot']['export']['file'] ?? null;
            if ($file) {
                @unlink(storage_path('app/private/'.$file));
            }
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function category(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function tx(string $on, int $pence, string $name): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'year.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'golden-year-'.$this->n]);
    }

    /** Decided by you: one kind (and category), optionally for a job, optionally part personal. */
    private function decide(string $on, int $pence, string $name, string $kind, ?string $category = null, ?WorkJob $job = null, int $pct = 100): BankTransaction
    {
        $tx = $this->tx($on, $pence, $name);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, $category ? $this->category($category) : null, $job?->id, $pct));

        return $tx;
    }

    /** The legacy year 2025-26, with a line in 2024-25 that never counts in it. */
    private function legacyYear(): void
    {
        $client = Client::query()->create(['name' => 'Acme Events']);
        $arena = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $client->id, 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-14', 'status' => 'done']);
        $gala = WorkJob::query()->create(['title' => 'Gala dinner', 'client_id' => $client->id, 'starts_on' => '2025-07-10', 'ends_on' => '2025-07-10', 'status' => 'done']);

        // Income: for a job; linked to an invoice by the matcher and accepted by you; turnover with no job or client;
        // other business income; and money paid back to a client.
        $this->decide('2025-06-01', 300000, 'Acme Events Ltd', 'business_income', 'turnover', $arena);
        $invoice = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0001', 'client_id' => $client->id, 'work_job_id' => $gala->id, 'currency' => 'GBP',
            'total_minor' => 120000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2025-07-11', 'due_date' => '2025-08-10', 'issued_at' => '2025-07-11 09:00:00']);
        $linked = $this->tx('2025-08-01', 120000, 'Acme Events Ltd');
        $lines = AllocationWriter::lines(120000, 'business_income', $this->category('turnover'), $gala->id);
        $lines[0]['invoice_id'] = $invoice->id;
        app(AllocationWriter::class)->decide($linked, $lines, 'matcher', false, 'Matches invoice INV-0001', null, new Provenance('suggest.invoice', ['inv:'.$invoice->id, 'job:'.$gala->id], 1));
        app(AllocationWriter::class)->confirm($linked);
        $this->decide('2025-09-01', 50000, 'Market stall', 'business_income', 'turnover');
        $this->decide('2025-10-01', 4000, 'Arts council', 'business_income', 'other_income');
        $this->decide('2025-10-15', -5000, 'Refund Acme Events', 'business_income', 'turnover', $arena);

        // Spending: allowable, on a job, part personal, disallowable (a fine, entertaining), and a cost refunded to you.
        $this->decide('2025-05-20', -12000, 'Adobe', 'business_expense', 'software');
        $this->decide('2025-05-13', -8040, 'Trainline', 'business_expense', 'travel_rail', $arena);
        $this->decide('2025-06-03', -5000, 'EE', 'business_expense', 'phone_internet', pct: 60);
        $this->decide('2025-06-20', -6500, 'Leeds City Council PCN', 'business_expense', 'travel_penalties');
        $this->decide('2025-07-02', -3000, 'Client lunch', 'business_expense', 'entertainment');
        $this->decide('2025-06-25', 2000, 'Adobe refund', 'business_expense', 'software');
        $cash = ManualExpense::query()->create(['spent_on' => '2025-05-14', 'tax_year' => '2025-26', 'description' => 'Parking at the arena', 'merchant_key' => 'PARKING',
            'paid_with' => 'cash', 'amount_pence' => -1500]);
        app(AllocationWriter::class)->decide($cash, AllocationWriter::lines(-1500, 'business_expense', $this->category('travel_parking'), $arena->id));

        // Not business.
        $this->decide('2025-08-15', -10000, 'To savings', 'transfer');
        $this->decide('2025-09-15', -20000, 'Alex Smith', 'drawings');
        $this->decide('2025-11-01', -2500, 'Cinema', 'personal');

        // Mileage at the simplified rate.
        $car = Vehicle::query()->create(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified']);
        MileageTrip::query()->create(['vehicle_id' => $car->id, 'work_job_id' => $arena->id, 'trip_on' => '2025-05-14', 'tax_year' => '2025-26', 'from_place' => 'Home', 'to_place' => 'Arena', 'miles' => '120.5']);
        MileageTrip::query()->create(['vehicle_id' => $car->id, 'work_job_id' => $gala->id, 'trip_on' => '2025-07-10', 'tax_year' => '2025-26', 'from_place' => 'Home', 'to_place' => 'Gala', 'miles' => '64.2']);

        // The year before.
        $this->decide('2025-03-20', 100000, 'Acme Events Ltd', 'business_income', 'turnover');
    }

    private const FIGURES = [
        'turnover_pence' => 465000, 'other_income_pence' => 4000, 'allowable_expenses_pence' => 30852, 'disallowable_expenses_pence' => 9500, 'mileage_pence' => 8312,
        'net_profit_pence' => 438148,
    ];

    private function summary(): array
    {
        return [
            'tax_year' => '2025-26',
            'turnover_pence' => 465000,
            'other_income_pence' => 4000,
            'expense_lines' => [
                ['code' => 'travel', 'name' => 'Car, van and travel expenses', 'box' => '20', 'allowable_pence' => 17852, 'disallowable_pence' => 6500, 'mileage_pence' => 8312],
                ['code' => 'office', 'name' => 'Phone, fax, stationery and other office costs', 'box' => '23', 'allowable_pence' => 13000, 'disallowable_pence' => 0],
                ['code' => 'advertising', 'name' => 'Advertising and business entertainment costs', 'box' => '24', 'allowable_pence' => 0, 'disallowable_pence' => 3000],
            ],
            'allowable_expenses_pence' => 30852,
            'disallowable_expenses_pence' => 9500,
            'mileage_pence' => 8312,
            'net_profit_pence' => 438148,
            'trading_allowance' => ['allowance_pence' => 100000, 'profit_with_allowance_pence' => 369000, 'better' => true],
            'short_form_allowed' => true,
            'pending' => ['unreviewed_count' => 0, 'unreviewed_pence' => 0, 'suggested_count' => 0, 'manual_unreviewed_count' => 0],
        ];
    }

    public function test_the_return_figures_for_a_legacy_year(): void
    {
        $this->legacyYear();

        $this->assertSame($this->summary(), ReturnSummary::for('2025-26'));
        $this->assertSame(self::FIGURES, YearChecks::figures(ReturnSummary::for('2025-26')));
        // The year before holds only its own line.
        $before = ReturnSummary::for('2024-25');
        $this->assertSame([100000, 0, 0, 100000], [$before['turnover_pence'], $before['allowable_expenses_pence'], $before['mileage_pence'], $before['net_profit_pence']]);
    }

    public function test_a_locked_year_keeps_its_snapshot_and_verifies(): void
    {
        $this->legacyYear();
        $year = TaxYear::track('2025-26');

        $checks = collect(YearChecks::checks('2025-26', ReturnSummary::for('2025-26')))->mapWithKeys(fn ($c) => [$c['key'] => $c['status']])->all();
        $this->assertSame(['coverage' => 'warn', 'continuity' => 'ok', 'undecided' => 'ok', 'transfers' => 'ok', 'year_on_year' => 'warn'], $checks);
        $this->post('/years/2025-26/lock')->assertSessionHas('success');
        $this->assertTrue($year->fresh()->isLocked());

        $event = AuditEvent::query()->where('action', 'tax_year.locked')->sole();
        $this->assertEquals(self::FIGURES, $event->after['snapshot']['figures']);
        $this->assertEquals(['unreviewed' => 0, 'suggested' => 0, 'manual_unreviewed' => 0], $event->after['snapshot']['pending']);
        $this->assertArrayNotHasKey('failing', $event->after);
        $verified = ['ok' => true, 'event_ok' => true, 'chain_ok' => true, 'figures' => [], 'export' => 'same', 'findings' => ['then' => 0, 'now' => 0, 'same' => true]];
        $this->assertSame($verified, YearChecks::verifyLock($year->fresh()));

        // Locked, nothing in it changes: a write is refused and the snapshot still verifies.
        $adobe = BankTransaction::query()->where('description', 'Adobe')->sole();
        try {
            app(AllocationWriter::class)->decide($adobe, AllocationWriter::lines(-12000, 'personal'));
            $this->fail('A locked year changed');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Tax year 2025-26 is locked. Unlock it (with a reason) before changing anything in it.', $e->getMessage());
        }
        $this->assertSame($this->summary(), ReturnSummary::for('2025-26'));
        $this->assertSame($verified, YearChecks::verifyLock($year->fresh()));
    }
}
