<?php

namespace Tests\Feature\Domain;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Models\Account;
use App\Models\BankTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportTest extends TestCase
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
        $profile = ProfileDetector::detect(CsvReader::read($path)['headers'], $account->kind);

        return app(Importer::class)->commit($account, $path, $fixture, $profile);
    }

    public function test_starling_import_reads_amounts_balances_and_tax_years(): void
    {
        $account = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = $this->import($account, 'starling.csv');

        $this->assertSame('passed', $batch->balance_check);
        $this->assertSame(7, $batch->imported_count);
        $this->assertSame(-5698, BankTransaction::where('merchant_key', 'ADOBE')->value('amount_pence'));
        $this->assertSame('2024-25', BankTransaction::where('description', 'like', '%PRET%')->value('tax_year'));
        $this->assertSame('2025-26', BankTransaction::where('description', 'like', '%TFL%')->value('tax_year'));
        // Two genuine identical coffees on the same day are both kept.
        $this->assertSame(2, BankTransaction::where('description', 'like', '%PRET%')->count());
        $this->assertSame('starling', $account->fresh()->import_profile['preset']);
    }

    public function test_overlapping_statements_never_duplicate_rows(): void
    {
        $account = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $this->import($account, 'starling.csv');
        $batch = $this->import($account, 'starling_overlap.csv');

        $this->assertSame(2, $batch->duplicate_count);
        $this->assertSame(1, $batch->imported_count);
        $this->assertSame(8, BankTransaction::count());

        // Importing the very same file again adds nothing.
        $again = $this->import($account, 'starling.csv');
        $this->assertSame(0, $again->imported_count);
    }

    public function test_a_broken_running_balance_is_flagged(): void
    {
        $account = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = $this->import($account, 'starling_broken.csv');

        $this->assertSame('failed', $batch->balance_check);
    }

    public function test_amex_signs_are_flipped_so_spending_is_negative(): void
    {
        $account = Account::create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->import($account, 'amex.csv');

        $this->assertSame(-8900, BankTransaction::where('description', 'like', 'PREMIER INN%')->value('amount_pence'));
        $this->assertSame(25000, BankTransaction::where('description', 'like', 'PAYMENT RECEIVED%')->value('amount_pence'));
        $this->assertSame(1500, BankTransaction::where('description', 'like', 'AMAZON%')->value('amount_pence'));
        $this->assertSame('amex', $account->fresh()->import_profile['preset']);
    }

    public function test_capital_one_debit_and_credit_columns_and_transaction_dates(): void
    {
        $account = Account::create(['name' => 'Capital One', 'kind' => 'credit_card', 'provider' => 'capital_one']);
        $this->import($account, 'capital_one.csv');

        $train = BankTransaction::where('description', 'THETRAINLINE.COM')->first();
        $this->assertSame(-6430, $train->amount_pence);
        $this->assertSame('2025-05-01', $train->transaction_on->format('Y-m-d'));
        $this->assertSame(10000, BankTransaction::where('description', 'PAYMENT THANK YOU')->value('amount_pence'));
    }

    public function test_card_bill_payments_are_paired_as_transfers_and_hmrc_payments_suggested_as_tax(): void
    {
        $bank = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $amex = Account::create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->import($bank, 'starling.csv');
        $this->import($amex, 'amex.csv');

        $out = BankTransaction::where('description', 'like', '%AMEX PAYMENT%')->first();
        $in = BankTransaction::where('description', 'like', 'PAYMENT RECEIVED%')->first();
        $this->assertSame($in->id, $out->transfer_pair_id);
        $this->assertSame('transfer', $out->allocations()->value('kind'));
        $this->assertNull($out->allocations()->value('confirmed_at'));

        $hmrc = BankTransaction::where('merchant_key', 'HMRC')->first();
        $this->assertSame('tax_payment', $hmrc->allocations()->value('kind'));
        $this->assertSame('suggested', $hmrc->review_status);
    }

    public function test_reverting_an_import_removes_its_rows(): void
    {
        $account = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $batch = $this->import($account, 'starling.csv');
        app(Importer::class)->revert($batch, 'Wrong account');

        $this->assertSame(0, BankTransaction::count());
        $this->assertSame('reverted', $batch->fresh()->status);
    }
}
