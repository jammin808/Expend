<?php

namespace Tests\Feature\Domain;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\BillPreview;
use App\Domain\Tax\MileageCalculator;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\YearChecks;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ManualExpense;
use App\Models\MerchantRule;
use App\Models\MileageTrip;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** Bugs found by an independent review of the financial core, each pinned by a test. */
class ReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function import(Account $account, string $fixture)
    {
        $path = base_path("tests/fixtures/{$fixture}");

        return app(Importer::class)->commit($account, $path, $fixture, ProfileDetector::detect(CsvReader::read($path)['headers'], $account->kind));
    }

    private function cat(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    private function starling(): Account
    {
        return Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
    }

    public function test_an_import_cannot_be_reverted_out_of_a_locked_year(): void
    {
        $batch = $this->import($this->starling(), 'starling.csv');
        $acme = BankTransaction::where('amount_pence', 120000)->first();
        app(AllocationWriter::class)->decide($acme, AllocationWriter::lines(120000, 'business_income', $this->cat('turnover')));
        TaxYear::track('2024-25')->update(['locked_at' => now()]);

        try {
            app(Importer::class)->revert($batch, 'oops');
            $this->fail('Revert should be refused');
        } catch (RuntimeException) {
        }
        $this->assertSame(120000, ReturnSummary::for('2024-25')['turnover_pence']);
    }

    public function test_rows_in_a_locked_year_block_the_import_instead_of_half_importing(): void
    {
        TaxYear::track('2024-25')->update(['locked_at' => now()]);
        $this->expectException(RuntimeException::class);
        try {
            $this->import($this->starling(), 'starling.csv');
        } finally {
            $this->assertSame(0, BankTransaction::count());
        }
    }

    public function test_a_card_purchase_is_not_paired_with_unrelated_income_and_rules_apply(): void
    {
        MerchantRule::create(['merchant_key' => 'SCREWFIX', 'kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'auto_confirm' => true]);
        $this->import($this->starling(), 'bank_income.csv');
        $this->import(Account::create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']), 'card_purchase.csv');

        $this->assertSame(0, BankTransaction::whereNotNull('transfer_pair_id')->count());
        $screwfix = BankTransaction::where('description', 'like', 'SCREWFIX%')->first();
        $this->assertSame('business_expense', $screwfix->allocations()->value('kind'));
        $this->assertSame('confirmed', $screwfix->review_status);
    }

    public function test_decimal_commas_are_refused_not_misread(): void
    {
        $this->assertSame(123456, Money::parse('1,234.56'));
        $this->assertSame(123456700, Money::parse('1,234,567'));
        foreach (['12,50', '1,5', '1,2345'] as $bad) {
            try {
                Money::parse($bad);
                $this->fail("{$bad} should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_split_lines_must_keep_the_transactions_sign(): void
    {
        $this->import($this->starling(), 'starling.csv');
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->expectException(InvalidArgumentException::class);
        app(AllocationWriter::class)->decide($adobe, [
            ['kind' => 'business_expense', 'category_id' => $this->cat('software'), 'amount_pence' => -100000],
            ['kind' => 'personal', 'amount_pence' => 100000 - 5698],
        ]);
    }

    public function test_card_balances_flip_with_the_amounts(): void
    {
        $batch = $this->import(Account::create(['name' => 'Other card', 'kind' => 'credit_card', 'provider' => 'other']), 'card_bal.csv');
        $this->assertSame('passed', $batch->balance_check);
    }

    public function test_newest_first_statements_show_no_false_gap(): void
    {
        $batch = $this->import($this->starling(), 'newest_first.csv');
        $this->assertSame('passed', $batch->balance_check);
        $this->assertSame('ok', YearChecks::coverage('2025-26')['accounts'][0]['months']['2025-05']['continuity']);
    }

    public function test_manual_expenses_respect_locks_and_never_leave_orphans(): void
    {
        $this->actingAs(User::factory()->create());
        TaxYear::track('2024-25')->update(['locked_at' => now()]);
        $base = ['description' => 'Cash taxi', 'direction' => 'out', 'amount' => '20.00', 'currency' => 'GBP', 'paid_with' => 'cash', 'kind' => 'business_expense'];

        $this->post('/expenses', $base + ['spent_on' => '2024-06-01', 'category_id' => $this->cat('travel_taxi')])->assertSessionHas('error');
        $this->post('/expenses', $base + ['spent_on' => '2025-06-01'])->assertSessionHas('error');
        $this->post('/expenses', $base + ['spent_on' => '2025-06-01'])->assertSessionHas('error');
        $this->assertSame(0, ManualExpense::count());
    }

    public function test_locked_year_figures_cannot_be_edited(): void
    {
        $this->actingAs(User::factory()->create());
        TaxYear::track('2024-25')->update(['locked_at' => now()]);
        $this->patch('/years/2024-25', ['payments_on_account_paid' => '999.00'])->assertSessionHas('error');
        $this->assertSame(0, TaxYear::where('label', '2024-25')->value('payments_on_account_paid_pence'));
    }

    public function test_payments_on_account_apply_from_exactly_1000(): void
    {
        $estimate = ['income_tax' => ['total_pence' => 100_000], 'class4_pence' => 0, 'class2_pence' => 0];
        $this->assertSame(50_000, BillPreview::for('2024-25', $estimate)['payment_on_account_pence']);
    }

    public function test_bicycles_get_no_simplified_mileage(): void
    {
        $bike = Vehicle::create(['name' => 'Bike', 'type' => 'bicycle', 'method' => 'simplified']);
        MileageTrip::create(['vehicle_id' => $bike->id, 'trip_on' => '2025-06-01', 'tax_year' => '2025-26', 'from_place' => 'A', 'to_place' => 'B', 'miles' => '10']);
        $this->assertSame(0, MileageCalculator::forYear('2025-26')['total_pence']);
    }
}
