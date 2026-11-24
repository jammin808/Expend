<?php

namespace Tests\Feature\Domain;

use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\ProfileDetector;
use App\Domain\Ledger\Reconciler;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\StatementBalance;
use App\Models\User;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Each account is checked against the bank's own figures, so missing payments are found. */
class ReconcileTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function row(Account $a, string $date, int $pence, ?int $balance, string $desc = 'Line'): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $a->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $a->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $desc, 'merchant_key' => 'X', 'amount_pence' => $pence, 'balance_after_pence' => $balance, 'raw' => [], 'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1]);
    }

    public function test_an_imported_statement_with_a_running_balance_matches(): void
    {
        Storage::fake('local');
        $this->seed();
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $path = base_path('tests/fixtures/starling.csv');
        app(Importer::class)->commit($bank, $path, 'starling.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'bank'));

        $r = Reconciler::account($bank);
        $this->assertSame('running_balance', $r['method']);
        $this->assertSame([], $r['gaps']);
        $this->assertSame(BankTransaction::query()->max('posted_on'), $r['checked_to'].(strlen((string) BankTransaction::query()->max('posted_on')) > 10 ? ' 00:00:00' : ''));
        $this->assertStringContainsString('Matches the bank', $r['text']);
    }

    public function test_a_missing_statement_shows_as_a_gap_with_the_money_and_dates(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-02', 20000, 120000);
        // 3 to 9 June not imported: £150 went out.
        $this->row($bank, '2025-06-10', -1000, 104000);

        $r = Reconciler::account($bank);
        $this->assertCount(1, $r['gaps']);
        $this->assertSame(['2025-06-03', '2025-06-09', -15000], [$r['gaps'][0]['from'], $r['gaps'][0]['to'], $r['gaps'][0]['missing_pence']]);
        $this->assertStringContainsString('£150.00 of money out isn’t in Expend from 3 Jun 2025 to 9 Jun 2025', $r['gaps'][0]['text']);
    }

    public function test_rows_on_the_same_day_can_be_in_any_order(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->row($bank, '2025-06-01', -5000, 100000);
        // Newest first within the day: 06-02 rows were 100000 → 90000 → 95000.
        $this->row($bank, '2025-06-02', 5000, 95000);
        $this->row($bank, '2025-06-02', -10000, 90000);
        $this->row($bank, '2025-06-03', -500, 94500);

        $this->assertSame([], Reconciler::account($bank)['gaps']);
    }

    public function test_cards_are_checked_against_the_balances_you_copy_from_statements(): void
    {
        $this->actingAs(User::factory()->create());
        $card = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->row($card, '2025-04-04', -8900, null, 'PREMIER INN');
        $this->row($card, '2025-04-08', -1840, null, 'UBER');
        $this->row($card, '2025-04-20', 10000, null, 'PAYMENT RECEIVED');
        $this->assertSame('none', Reconciler::account($card)['method']);

        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-01', 'balance' => '100.00'])->assertSessionHas('success');
        // Owed went from £100 to £120.00: purchases £107.40, payment £100, so £12.60 of purchases are missing.
        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-30', 'balance' => '120.00'])->assertSessionHas('error');
        $r = Reconciler::account($card);
        $this->assertSame('statement_balances', $r['method']);
        $this->assertSame(-1260, $r['gaps'][0]['missing_pence']);

        $this->row($card, '2025-04-25', -1260, null, 'FORGOTTEN');
        $this->assertSame([], Reconciler::account($card)['gaps']);
        $this->get('/accounts')->assertOk()->assertInertia(fn ($p) => $p->where('accounts.0.reconcile.method', 'statement_balances')->has('accounts.0.balances', 2));

        $this->delete("/accounts/{$card->id}/balances/".StatementBalance::query()->value('id'))->assertSessionHas('success');
        $this->assertSame(1, StatementBalance::query()->count());
    }

    public function test_gaps_and_old_statements_are_on_the_to_do_list(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-10', -1000, 104000);
        $todo = collect(TodoList::build()['items'])->where('group', 'reconcile')->values();
        $this->assertCount(2, $todo);
        $this->assertStringContainsString('£50.00 missing', $todo[0]['title']);
        $this->assertStringContainsString('nothing imported since 10 Jun 2025', $todo[1]['title']);

        $bank->update(['is_active' => false]);
        $this->assertCount(0, collect(TodoList::build()['items'])->where('group', 'reconcile'));
    }
}
