<?php

namespace Tests\Feature\Domain;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Suggester;
use App\Domain\Tax\ReturnSummary;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\TaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private AllocationWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->writer = app(AllocationWriter::class);
        $account = Account::create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($account, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers']));
    }

    private function cat(string $code): int
    {
        return Category::where('code', $code)->value('id');
    }

    public function test_lines_must_add_up_exactly(): void
    {
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->expectException(InvalidArgumentException::class);
        $this->writer->decide($adobe, [['kind' => 'business_expense', 'category_id' => $this->cat('software'), 'amount_pence' => -5000]]);
    }

    public function test_business_lines_need_a_category_of_the_right_kind(): void
    {
        $income = BankTransaction::where('amount_pence', 120000)->first();
        $this->expectException(InvalidArgumentException::class);
        $this->writer->decide($income, AllocationWriter::lines(120000, 'business_income', $this->cat('software')));
    }

    public function test_business_use_split_and_summary_counts_only_confirmed_items(): void
    {
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->writer->decide($adobe, AllocationWriter::lines(-5698, 'business_expense', $this->cat('software'), null, 60));
        $this->assertSame([-3418, -2280], $adobe->allocations()->orderBy('id')->pluck('amount_pence')->all());

        $income = BankTransaction::where('amount_pence', 120000)->first();
        $this->writer->decide($income, AllocationWriter::lines(120000, 'business_income', $this->cat('turnover')), 'rule', false);

        $summary = ReturnSummary::for('2024-25');
        $this->assertSame(0, $summary['turnover_pence'], 'Unconfirmed suggestions are not counted');
        $this->assertSame(3418, $summary['allowable_expenses_pence']);

        $this->writer->confirm($income);
        $summary = ReturnSummary::for('2024-25');
        $this->assertSame(120000, $summary['turnover_pence']);
        $this->assertSame(120000 - 3418, $summary['net_profit_pence']);
    }

    public function test_automation_never_overwrites_your_decision(): void
    {
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->writer->decide($adobe, AllocationWriter::lines(-5698, 'personal'));

        MerchantRule::create(['merchant_key' => 'ADOBE', 'kind' => 'business_expense', 'category_id' => $this->cat('software'), 'auto_confirm' => true]);
        $applied = $this->writer->decide($adobe, AllocationWriter::lines(-5698, 'business_expense', $this->cat('software')), 'rule', true);

        $this->assertFalse($applied);
        $this->assertSame('personal', $adobe->allocations()->value('kind'));
        $this->assertSame(0, app(Suggester::class)->suggestFor(collect([$adobe])));
    }

    public function test_purchases_need_no_job(): void
    {
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->writer->decide($adobe, AllocationWriter::lines(-5698, 'business_expense', $this->cat('equipment')));
        $this->assertNull($adobe->allocations()->value('work_job_id'));
        $this->assertSame('confirmed', $adobe->fresh()->review_status);
    }

    public function test_locked_years_cannot_change(): void
    {
        TaxYear::track('2024-25')->update(['locked_at' => now()]);
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->expectException(InvalidArgumentException::class);
        $this->writer->decide($adobe, AllocationWriter::lines(-5698, 'personal'));
    }

    public function test_imported_facts_are_immutable(): void
    {
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->expectException(\LogicException::class);
        $adobe->update(['amount_pence' => -1]);
    }

    public function test_the_audit_chain_detects_tampering(): void
    {
        $adobe = BankTransaction::where('merchant_key', 'ADOBE')->first();
        $this->writer->decide($adobe, AllocationWriter::lines(-5698, 'personal'));
        $this->assertTrue(AuditLog::verify()['ok']);

        $last = AuditEvent::orderByDesc('id')->first();
        \DB::table('audit_events')->where('id', $last->id)->update(['reason' => 'edited behind the app’s back']);
        $result = AuditLog::verify();
        $this->assertFalse($result['ok']);
        $this->assertSame($last->id, $result['broken_at']);
    }
}
