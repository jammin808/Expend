<?php

namespace Tests\Feature;

use App\Domain\Fx\ExchangeRates;
use App\Domain\Import\CsvReader;
use App\Domain\Import\Importer;
use App\Domain\Import\NormalizedRow;
use App\Domain\Import\ProfileDetector;
use App\Domain\Import\RevolutImport;
use App\Http\Presenters\Present;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\ExchangeRate;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Revolut statements: each pocket keeps going into the account it went into before (you say which is the main
 * account when it isn't clear), a locked year is reported, UK dates for UTC times (rows imported under their UTC date
 * aren't imported again), the currency actually charged, an exchange's fee named while both sides stay one transfer,
 * exchanges paired across separately added accounts, and a missing rate can be entered on the import page. Also Amex
 * foreign spending keeps the currency used, with its rate in that currency's own units.
 */
class NewFixesRevolutTest extends TestCase
{
    use RefreshDatabase;

    private const PERSONAL = "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n";

    private const BUSINESS = ['Date started (UTC)', 'Date completed (UTC)', 'ID', 'Type', 'State', 'Description', 'Reference', 'Payer', 'Card number', 'Orig currency', 'Orig amount', 'Payment currency', 'Amount', 'Total amount', 'Fee', 'Fee currency', 'Balance', 'Account'];

    private Account $revolut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Http::fake(); // no live rate fetches
        Setting::put('own_names', ['Alex Smith']);
        foreach (['EUR' => '1.150000', 'USD' => '1.300000', 'JOD' => '0.900000'] as $cur => $rate) {
            ExchangeRate::query()->create(['currency' => $cur, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'units_per_gbp' => $rate, 'source' => 'hmrc_monthly']);
        }
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
    }

    private function file(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rv').'.csv';
        file_put_contents($path, $csv);

        return $path;
    }

    /** A Revolut Business CSV from rows keyed by column (others blank, COMPLETED, total = amount). */
    private function business(array $rows, array $extraHeaders = []): string
    {
        $headers = [...self::BUSINESS, ...$extraHeaders];
        $out = implode(',', $headers)."\n";
        foreach ($rows as $r) {
            $r += ['State' => 'COMPLETED', 'Payment currency' => 'GBP', 'Fee' => '0.00'];
            $r['Total amount'] ??= $r['Amount'] ?? '';
            $r['Fee currency'] ??= $r['Payment currency'];
            $out .= implode(',', array_map(fn ($h) => $r[$h] ?? '', $headers))."\n";
        }

        return $this->file($out);
    }

    private function commit(Account $account, string $path): array
    {
        return app(RevolutImport::class)->commit($account->fresh(), $path, basename($path));
    }

    // ---------------------------------------------------------------- N13 / N68 / N98: which pocket is which

    public function test_a_savings_statement_on_its_own_never_becomes_the_main_account(): void
    {
        $savings = self::PERSONAL
            ."Transfer,Savings,2026-03-09 08:00:00,2026-03-09 08:00:00,To GBP Savings,50.00,0.00,GBP,COMPLETED,50.00\n"
            ."Interest,Savings,2026-03-31 08:00:00,2026-03-31 08:00:00,Interest earned,0.10,0.00,GBP,COMPLETED,50.10\n";
        $this->commit($this->revolut, $this->file($savings));
        $this->assertSame(0, BankTransaction::query()->where('account_id', $this->revolut->id)->count(), 'Savings rows stay out of the current account');
        $pot = Account::query()->where('parent_account_id', $this->revolut->id)->sole();
        $this->assertSame(['Revolut GBP savings', 'GBP|savings'], [$pot->name, $pot->import_profile['pocket']]);

        // Then the whole statement: the current account goes into Revolut, the savings rows are already in.
        $both = self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,500.00,0.00,GBP,COMPLETED,500.00\n"
            ."Transfer,Current,2026-03-09 08:00:00,2026-03-09 08:00:00,To GBP Savings,-50.00,0.00,GBP,COMPLETED,450.00\n"
            .substr($savings, strlen(self::PERSONAL));
        $read = app(RevolutImport::class)->read($this->revolut->fresh(), $this->file($both));
        $this->assertSame([['Revolut', 2, 0], ['Revolut GBP savings', 0, 2]], array_map(fn ($p) => [$p['name'], $p['new'], $p['duplicates']], $read['pockets']));
        $this->commit($this->revolut, $this->file($both));
        $this->assertSame(1, BankTransaction::query()->where('description', 'Interest earned')->count(), 'Interest is counted once');
        $this->assertSame('GBP', $this->revolut->fresh()->import_profile['pocket']);
        $this->assertSame(2, Account::query()->count());
    }

    public function test_a_savings_statement_after_the_full_one_goes_into_the_savings_account(): void
    {
        $this->commit($this->revolut, base_path('tests/fixtures/revolut_personal.csv'));
        $savings = self::PERSONAL
            ."Transfer,Savings,2026-03-09 08:00:00,2026-03-09 08:00:00,To GBP Savings,50.00,0.00,GBP,COMPLETED,50.00\n"
            ."Interest,Savings,2026-03-31 08:00:00,2026-03-31 08:00:00,Interest earned,0.10,0.00,GBP,COMPLETED,50.10\n";
        $read = app(RevolutImport::class)->read($this->revolut->fresh(), $this->file($savings));
        $this->assertSame([['Revolut GBP savings', 1, 1]], array_map(fn ($p) => [$p['name'], $p['new'], $p['duplicates']], $read['pockets']));
        $this->commit($this->revolut, $this->file($savings));
        $this->assertSame(1, BankTransaction::query()->where('description', 'To GBP Savings')->where('amount_pence', 5000)->count());
        $this->assertSame('GBP', $this->revolut->fresh()->import_profile['pocket'], 'The main account keeps its pocket');
    }

    public function test_business_accounts_without_a_main_one_keep_their_own_accounts_whatever_the_order(): void
    {
        $first = $this->business([
            ['Date started (UTC)' => '2026-03-08', 'ID' => 'b2', 'Type' => 'TOPUP', 'Description' => 'Money added from ACME EVENTS LTD', 'Amount' => '100.00', 'Balance' => '100.00', 'Account' => 'Operating'],
            ['Date started (UTC)' => '2026-03-07', 'ID' => 'b1', 'Type' => 'TRANSFER', 'Description' => 'To Tax pot', 'Amount' => '20.00', 'Balance' => '20.00', 'Account' => 'Tax pot'],
        ]);
        $second = $this->business([
            ['Date started (UTC)' => '2026-03-12', 'ID' => 'b3', 'Type' => 'TRANSFER', 'Description' => 'To Tax pot', 'Amount' => '30.00', 'Balance' => '50.00', 'Account' => 'Tax pot'],
            ['Date started (UTC)' => '2026-03-08', 'ID' => 'b2', 'Type' => 'TOPUP', 'Description' => 'Money added from ACME EVENTS LTD', 'Amount' => '100.00', 'Balance' => '100.00', 'Account' => 'Operating'],
            ['Date started (UTC)' => '2026-03-07', 'ID' => 'b1', 'Type' => 'TRANSFER', 'Description' => 'To Tax pot', 'Amount' => '20.00', 'Balance' => '20.00', 'Account' => 'Tax pot'],
        ]);
        $this->commit($this->revolut, $first);
        $this->commit($this->revolut, $second);
        $this->assertSame(1, BankTransaction::query()->where('description', 'like', 'Money added from ACME%')->count(), 'The client payment is imported once');
        $this->assertSame(1, BankTransaction::query()->where('amount_pence', 2000)->count());
        $acme = BankTransaction::query()->where('description', 'like', 'Money added from ACME%')->sole();
        $pot = BankTransaction::query()->where('amount_pence', 3000)->sole();
        $this->assertNotSame($acme->account_id, $pot->account_id, 'Operating and Tax pot never share an account');
    }

    public function test_choosing_a_pocket_as_the_account_imports_into_the_same_family(): void
    {
        $this->commit($this->revolut, base_path('tests/fixtures/revolut_personal.csv'));
        $eur = Account::query()->where('name', 'Revolut EUR')->sole();
        $accounts = Account::query()->count();

        $read = app(RevolutImport::class)->read($eur, base_path('tests/fixtures/revolut_personal.csv'));
        $this->assertSame(0, array_sum(array_column($read['pockets'], 'new')));
        $this->assertSame(['Revolut', 'Revolut EUR', 'Revolut GBP savings'], array_column($read['pockets'], 'name'));
        $this->commit($eur, base_path('tests/fixtures/revolut_personal.csv'));
        $this->assertSame($accounts, Account::query()->count(), 'No pocket of a pocket');
        $this->assertSame(7, BankTransaction::query()->count());
    }

    public function test_a_currency_statement_imported_into_its_own_new_account_stays_there(): void
    {
        $eurAccount = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $eur = self::PERSONAL
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from GBP,115.20,0.00,EUR,COMPLETED,115.20\n"
            ."Card Payment,Current,2026-03-06 19:30:22,2026-03-07 06:00:00,Brasserie du Port,-42.00,0.00,EUR,COMPLETED,73.20\n";
        $read = app(RevolutImport::class)->read($eurAccount, $this->file($eur));
        $this->assertSame(['Revolut EUR'], array_column($read['pockets'], 'name'));
        $this->commit($eurAccount, $this->file($eur));

        $this->assertSame(2, BankTransaction::query()->where('account_id', $eurAccount->id)->count());
        $this->assertSame('EUR', $eurAccount->fresh()->currency, 'The account is kept in the statement’s currency');
        $this->assertFalse(Account::query()->where('name', 'Revolut EUR EUR')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'account.currency_set')->exists());
    }

    // ---------------------------------------------------------------- N37: the currency actually charged

    public function test_a_dollar_purchase_from_the_euro_account_keeps_the_dollars(): void
    {
        $this->commit($this->revolut, base_path('tests/fixtures/revolut_business.csv'));
        $usd = BankTransaction::query()->where('description', 'B&H Photo')->sole();
        $this->assertSame([-7664, 'EUR', -6664], [$usd->original_amount_minor, $usd->original_currency, $usd->amount_pence], 'The pocket’s own amount is kept');
        $this->assertSame([-9000, 'USD'], [(int) $usd->merchant_amount_minor, $usd->merchant_currency], 'And the currency it was paid in');
        $leg = BankTransaction::query()->where('description', 'like', 'Main%')->where('amount_pence', '>', 0)->sole();
        $this->assertNull($leg->merchant_currency, 'An exchange is no purchase');
    }

    // ---------------------------------------------------------------- N62: a locked year

    public function test_rows_in_a_locked_year_are_reported_and_the_file_is_refused(): void
    {
        TaxYear::track('2024-25')->update(['locked_at' => now()]);
        $path = $this->file(self::PERSONAL
            ."Card Payment,Current,2025-03-30 10:00:00,2025-03-30 10:00:00,Old thing,-5.00,0.00,GBP,COMPLETED,95.00\n"
            ."Card Payment,Current,2025-04-10 10:00:00,2025-04-10 10:00:00,New thing,-5.00,0.00,GBP,COMPLETED,90.00\n");
        $read = app(RevolutImport::class)->read($this->revolut, $path);
        $this->assertNotEmpty(array_filter($read['errors'], fn ($e) => str_contains($e, '1 rows fall in a locked tax year (2024-25)')));

        try {
            $this->commit($this->revolut, $path);
            $this->fail('A file with rows in a locked year is refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('locked tax year', $e->getMessage());
        }
        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertSame(0, BankTransaction::query()->count());
    }

    // ---------------------------------------------------------------- N63: three-decimal currencies

    public function test_amounts_in_three_decimal_currencies_are_read(): void
    {
        $path = $this->business([
            // An odd shop-currency figure on a sterling line never stops the statement.
            ['Date started (UTC)' => '2026-03-12', 'ID' => 'c4', 'Type' => 'CARD_PAYMENT', 'Description' => 'Odd figure', 'Orig currency' => 'USD', 'Orig amount' => 'n/a', 'Amount' => '-1.05', 'Balance' => '90.00', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-03-10', 'ID' => 'c2', 'Type' => 'CARD_PAYMENT', 'Description' => 'Paddock cafe', 'Orig currency' => 'BHD', 'Orig amount' => '4.250', 'Amount' => '-8.95', 'Balance' => '91.05', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-03-09', 'ID' => 'c1', 'Type' => 'TOPUP', 'Description' => 'Top-up', 'Amount' => '100.00', 'Balance' => '100.00', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-03-11', 'ID' => 'c3', 'Type' => 'CARD_PAYMENT', 'Description' => 'Petra taxi', 'Payment currency' => 'JOD', 'Amount' => '-10.125', 'Balance' => '89.875', 'Account' => 'JOD Main'],
        ]);
        $read = app(RevolutImport::class)->read($this->revolut, $path);
        $this->assertSame([], $read['errors']);
        $this->commit($this->revolut, $path);
        $cafe = BankTransaction::query()->where('description', 'Paddock cafe')->sole();
        $this->assertSame([-895, -4250, 'BHD'], [$cafe->amount_pence, $cafe->original_amount_minor, $cafe->original_currency]);
        $this->assertSame([-105, null], [BankTransaction::query()->where('description', 'Odd figure')->value('amount_pence'), BankTransaction::query()->where('description', 'Odd figure')->value('original_currency')]);
        $taxi = BankTransaction::query()->where('description', 'Petra taxi')->sole();
        $this->assertSame([-10125, 'JOD', 89875, -1125], [$taxi->original_amount_minor, $taxi->original_currency, $taxi->balance_after_minor, $taxi->amount_pence]);
    }

    // ---------------------------------------------------------------- N64: UTC times, UK dates

    public function test_a_late_night_payment_in_utc_gets_its_uk_date_and_tax_year(): void
    {
        $this->commit($this->revolut, $this->business([
            ['Date started (UTC)' => '2026-04-05 23:30:00', 'Date completed (UTC)' => '2026-04-05 23:31:00', 'ID' => 'd1', 'Type' => 'CARD_PAYMENT', 'Description' => 'Late bar', 'Amount' => '-12.00', 'Balance' => '88.00', 'Account' => 'GBP Main'],
        ]));
        $tx = BankTransaction::query()->where('description', 'Late bar')->sole();
        $this->assertSame(['2026-04-06', '2026-27', '00:30'], [$tx->effectiveDate()->format('Y-m-d'), $tx->tax_year, $tx->transacted_time]);
        $this->assertSame('2026-04-06', $tx->posted_on->format('Y-m-d'));
    }

    public function test_a_local_time_column_is_used_for_the_date(): void
    {
        $this->commit($this->revolut, $this->business([
            ['Date started (UTC)' => '2026-04-05', 'Date completed (UTC)' => '2026-04-05', 'Date started (Europe/London)' => '2026-04-06 00:30:00', 'Date completed (Europe/London)' => '2026-04-06 00:31:00',
                'ID' => 'd1', 'Type' => 'CARD_PAYMENT', 'Description' => 'Late bar', 'Amount' => '-12.00', 'Balance' => '88.00', 'Account' => 'GBP Main'],
        ], ['Date started (Europe/London)', 'Date completed (Europe/London)']));
        $tx = BankTransaction::query()->where('description', 'Late bar')->sole();
        $this->assertSame(['2026-04-06', '2026-27'], [$tx->effectiveDate()->format('Y-m-d'), $tx->tax_year]);
    }

    // ---------------------------------------------------------------- N65: exchange fees

    public function test_an_exchange_fee_is_named_and_the_euros_count_without_it(): void
    {
        $this->commit($this->revolut, $this->file(self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,500.00,0.00,GBP,COMPLETED,500.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,-100.00,1.00,GBP,COMPLETED,399.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from GBP,115.20,0.00,EUR,COMPLETED,115.20\n"));
        $out = BankTransaction::query()->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('description', 'Exchanged from GBP')->sole();
        $this->assertSame([-10100, 10000], [$out->amount_pence, $in->amount_pence], 'The euros count as the £100 exchanged, not the fee');
        $this->assertSame([$in->id, $out->id], [$out->transfer_pair_id, $in->transfer_pair_id]);
        // Changed on purpose at integration (N65): the fee is split off the side sold as a bank charge, for you to
        // check; a pair with its fee split off stays a pair (TransferPairer::movesMoney). The side bought is wholly a
        // transfer.
        $this->assertSame([['transfer', -10000, null], ['business_expense', -100, 'finance']],
            $out->allocations()->with('category')->orderBy('id')->get()->map(fn ($a) => [$a->kind, $a->amount_pence, $a->category?->code])->all());
        $this->assertSame('The exchange fee', $out->allocations()->where('kind', 'business_expense')->value('note'));
        $this->assertSame([['transfer', 10000]], $in->allocations()->get()->map(fn ($a) => [$a->kind, $a->amount_pence])->all());
        $this->assertStringContainsString('its £1.00 fee is a bank charge', (string) $out->allocations()->value('reason'));
    }

    public function test_confirming_an_exchange_with_a_fee_keeps_the_pair_and_counts_only_the_fee_as_spending(): void
    {
        $this->actingAs(User::factory()->create());
        $this->commit($this->revolut, $this->file(self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,500.00,0.00,GBP,COMPLETED,500.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,-100.00,1.00,GBP,COMPLETED,399.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from GBP,115.20,0.00,EUR,COMPLETED,115.20\n"));
        $out = BankTransaction::query()->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('description', 'Exchanged from GBP')->sole();
        // No money exchanged is spending or income; only the £1 fee split off it is spending (totalled line by line).
        $totals = fn () => $this->get('/transactions?q=Exchanged')->assertInertia(fn ($p) => $p
            ->where('totals.out_pence', -100)->where('totals.in_pence', 0)->where('totals.transfer_out_pence', -10000)->where('totals.transfer_in_pence', 10000));
        $totals();

        // Confirming the side bought keeps the pair, and leaves the side sold (with its fee) for you to look at.
        $this->post("/items/bank/{$in->id}/confirm")->assertSessionHasNoErrors();
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id], 'The pair stays');
        $this->assertSame(['suggested', 'confirmed'], [$out->fresh()->review_status, $in->fresh()->review_status], 'The fee is never confirmed unseen');

        $this->post("/items/bank/{$out->id}/confirm")->assertSessionHasNoErrors();
        $out->refresh();
        $in->refresh();
        $this->assertSame([$in->id, $out->id], [$out->transfer_pair_id, $in->transfer_pair_id], 'The pair stays');
        $this->assertSame(['confirmed', 'confirmed'], [$out->review_status, $in->review_status]);
        $this->assertSame([['transfer', 10000]], $in->allocations()->get()->map(fn ($a) => [$a->kind, $a->amount_pence])->all());
        $totals();
        $this->assertFalse(AuditEvent::query()->where('action', 'transfer.unpaired')->exists());
    }

    public function test_a_business_exchange_with_a_fee_stays_paired_when_confirmed(): void
    {
        $this->commit($this->revolut, $this->business([
            ['Date started (UTC)' => '2026-03-09', 'ID' => 'x1', 'Type' => 'EXCHANGE', 'Description' => 'Main · GBP → Main · EUR', 'Orig currency' => 'GBP', 'Orig amount' => '-1000.00', 'Payment currency' => 'EUR', 'Amount' => '1150.00', 'Balance' => '1150.00', 'Account' => 'EUR Main'],
            ['Date started (UTC)' => '2026-03-09', 'ID' => 'x1', 'Type' => 'EXCHANGE', 'Description' => 'Main · GBP → Main · EUR', 'Orig currency' => 'EUR', 'Orig amount' => '1150.00', 'Amount' => '-1000.00', 'Fee' => '6.00', 'Total amount' => '-1006.00', 'Balance' => '994.00', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-03-08', 'ID' => 'x0', 'Type' => 'TOPUP', 'Description' => 'Top-up', 'Amount' => '2000.00', 'Balance' => '2000.00', 'Account' => 'GBP Main'],
        ]));
        $out = BankTransaction::query()->where('description', 'like', 'Main%')->where('amount_pence', '<', 0)->sole();
        $in = BankTransaction::query()->where('description', 'like', 'Main%')->where('amount_pence', '>', 0)->sole();
        $this->assertSame([-100600, 100000, $in->id], [$out->amount_pence, $in->amount_pence, $out->transfer_pair_id]);
        // Changed on purpose at integration (N65): the £6 fee is split off as a bank charge.
        $this->assertSame([['transfer', -100000], ['business_expense', -600]], $out->allocations()->orderBy('id')->get()->map(fn ($a) => [$a->kind, $a->amount_pence])->all());
        $this->assertStringContainsString('its £6.00 fee is a bank charge', (string) $out->allocations()->value('reason'));

        // Confirming the side sold (in bulk) confirms the other side too and keeps both sides paired, as transfers.
        $this->actingAs(User::factory()->create());
        $this->post('/decisions/confirm', ['ids' => [$out->id]]);
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id]);
        $this->assertSame(['confirmed', 'confirmed'], [$out->fresh()->review_status, $in->fresh()->review_status]);
        $this->assertSame([['transfer', 100000]], $in->allocations()->get()->map(fn ($a) => [$a->kind, $a->amount_pence])->all());
    }

    // ---------------------------------------------------------------- N66: business exchanges across per-account files

    public function test_a_business_exchange_split_across_account_statements_is_a_transfer(): void
    {
        $this->commit($this->revolut, $this->business([
            ['Date started (UTC)' => '2026-03-09', 'ID' => 'e4', 'Type' => 'EXCHANGE', 'Description' => 'Main · GBP → Main · EUR', 'Orig currency' => 'EUR', 'Orig amount' => '1150.00', 'Amount' => '-1000.00', 'Balance' => '1000.00', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-03-08', 'ID' => 'e3', 'Type' => 'TOPUP', 'Description' => 'Top-up', 'Amount' => '2000.00', 'Balance' => '2000.00', 'Account' => 'GBP Main'],
        ]));
        $this->commit($this->revolut, $this->business([
            ['Date started (UTC)' => '2026-03-09', 'ID' => 'e4', 'Type' => 'EXCHANGE', 'Description' => 'Main · GBP → Main · EUR', 'Orig currency' => 'GBP', 'Orig amount' => '-1000.00', 'Payment currency' => 'EUR', 'Amount' => '1150.00', 'Balance' => '1150.00', 'Account' => 'EUR Main'],
        ]));
        $out = BankTransaction::query()->where('amount_pence', -100000)->sole();
        $in = BankTransaction::query()->where('amount_pence', 100000)->sole();
        $this->assertSame([$in->id, $out->id], [$out->transfer_pair_id, $in->fresh()->transfer_pair_id]);
        $this->assertSame(['transfer', 'transfer'], [$out->allocations()->value('kind'), $in->allocations()->value('kind')]);
    }

    // ---------------------------------------------------------------- N86 / N87: how the sterling was worked out

    public function test_a_rate_you_set_is_recorded_as_yours(): void
    {
        app(ExchangeRates::class)->setManual('EUR', CarbonImmutable::parse('2026-03-01'), '1.2');
        $this->commit($this->revolut, base_path('tests/fixtures/revolut_personal.csv'));
        $dinner = BankTransaction::query()->where('description', 'Brasserie du Port')->sole();
        $this->assertSame(['manual', -3500], [$dinner->fx_source, $dinner->amount_pence]);
    }

    public function test_an_exchange_between_two_other_currencies_shows_the_rate_it_was_counted_at(): void
    {
        $this->commit($this->revolut, $this->file(self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Euros in,200.00,0.00,EUR,COMPLETED,200.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to USD,-100.00,0.00,EUR,COMPLETED,100.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from EUR,108.00,0.00,USD,COMPLETED,108.00\n"));
        $eur = BankTransaction::query()->where('description', 'Exchanged to USD')->sole();
        $usd = BankTransaction::query()->where('description', 'Exchanged from EUR')->sole();
        $this->assertSame([-8696, 8696], [$eur->amount_pence, $usd->amount_pence]);
        $this->assertSame(['exchange', '2026-03-04'], [$usd->fx_source, $usd->fx_rate_date->format('Y-m-d')]);
        $this->assertEqualsWithDelta(108 / 86.96, (float) $usd->fx_units_per_gbp, 0.000001, 'The rate matches the pounds it counts as');
    }

    // ---------------------------------------------------------------- N99: nothing moves on a zero row

    public function test_a_zero_row_is_skipped_not_a_penny_in(): void
    {
        $path = $this->file(self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Euros in,20.00,0.00,EUR,COMPLETED,20.00\n"
            ."Card Payment,Current,2026-03-02 09:00:00,2026-03-02 09:00:00,Card check,0.00,0.00,EUR,COMPLETED,20.00\n");
        $read = app(RevolutImport::class)->read($this->revolut, $path);
        $this->assertSame(['ZERO' => 1], $read['skipped']);
        $this->commit($this->revolut, $path);
        $this->assertFalse(BankTransaction::query()->where('description', 'Card check')->exists());
        $this->assertSame([1739], BankTransaction::query()->pluck('amount_pence')->all());
    }

    // ---------------------------------------------------------------- N101: a held exchange leg

    public function test_a_corrected_statement_pairs_again_after_the_held_one_is_discarded(): void
    {
        $broken = str_replace('13923.36', '13900.00', (string) file_get_contents(base_path('tests/fixtures/revolut_business.csv')));
        $batches = $this->commit($this->revolut, $this->file($broken));
        $held = collect($batches)->firstWhere('status', 'quarantined');
        $this->assertNotNull($held);
        app(Importer::class)->discard($held, 'Wrong balance');

        $this->commit($this->revolut, base_path('tests/fixtures/revolut_business.csv'));
        $gbp = BankTransaction::query()->where('account_id', $this->revolut->id)->where('description', 'like', 'Main%')->sole();
        $eur = BankTransaction::query()->where('account_id', '!=', $this->revolut->id)->where('description', 'like', 'Main%')->sole();
        $this->assertSame([$eur->id, $gbp->id], [$gbp->fresh()->transfer_pair_id, $eur->transfer_pair_id]);
        $this->assertSame('transfer', $eur->allocations()->value('kind'));
    }

    // ---------------------------------------------------------------- N32 / N35: the import page

    public function test_a_missing_rate_can_be_entered_on_the_import_page(): void
    {
        ExchangeRate::query()->where('currency', 'EUR')->delete();
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->createWithContent('revolut.csv', (string) file_get_contents(base_path('tests/fixtures/revolut_personal.csv')));
        $this->post('/imports/preview', ['file' => $file, 'account_id' => $this->revolut->id])->assertRedirect('/imports');
        $this->get('/imports')->assertInertia(fn ($p) => $p
            ->where('preview.ok', true)
            ->where('preview.error_count', 1)
            ->where('preview.missing_rates.0.currency', 'EUR')
            ->where('preview.missing_rates.0.month', '2026-03'));

        $this->post('/imports/preview', ['account_id' => $this->revolut->id, 'rates' => [['currency' => 'EUR', 'month' => '2026-03', 'units_per_gbp' => '1.16']]])->assertRedirect('/imports');
        $this->assertEqualsWithDelta(1.16, (float) ExchangeRate::query()->where('currency', 'EUR')->where('source', 'manual')->value('units_per_gbp'), 0.0000001);
        $this->assertTrue(AuditEvent::query()->where('action', 'fx.manual_rate')->exists());
        $this->get('/imports')->assertInertia(fn ($p) => $p->where('preview.error_count', 0)->where('preview.missing_rates', []));
        $this->post('/imports/commit')->assertSessionHas('success');
        $this->assertSame('manual', BankTransaction::query()->where('description', 'Brasserie du Port')->value('fx_source'));
    }

    public function test_the_preview_shows_each_line_in_its_own_currency(): void
    {
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->createWithContent('revolut.csv', (string) file_get_contents(base_path('tests/fixtures/revolut_personal.csv')));
        $this->post('/imports/preview', ['file' => $file, 'account_id' => $this->revolut->id]);
        $this->get('/imports')->assertInertia(function ($p) {
            $sample = collect($p->toArray()['props']['preview']['sample']);
            $dinner = $sample->firstWhere('description', 'Brasserie du Port');
            $this->assertSame(['EUR', -4200, 7320, -3652], [$dinner['balance_currency'], $dinner['native_minor'], $dinner['balance_pence'], $dinner['amount_pence']]);
            $this->assertSame('GBP', $sample->firstWhere('description', 'Pret A Manger')['balance_currency']);
        });
    }

    // ---------------------------------------------------------------- N59: Amex foreign spending

    public function test_an_amex_purchase_abroad_keeps_the_currency_used(): void
    {
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $path = $this->file("Date,Description,Amount,Extended Details,Appears On Your Statement As,Address,Town/City,Postcode,Country,Reference,Category\n"
            ."04/04/2025,B&H PHOTO NEW YORK,70.51,\"Foreign Spend Amount: 90.00 US DOLLARS Commission Amount: 1.94 Currency Exchange Rate: 1.3127\",B&H PHOTO,420 9TH AVE,NEW YORK,10001,UNITED STATES,'AT1',Shopping\n"
            ."05/04/2025,SOMEWHERE ODD,10.00,\"Foreign Spend Amount: 12.00 MARTIAN CREDITS Commission Amount: 0.20\",SOMEWHERE,,,,,'AT2',Shopping\n"
            ."06/04/2025,TESCO STORES,20.00,,TESCO,,LEEDS,,UNITED KINGDOM,'AT3',Groceries\n");
        $profile = ProfileDetector::detect(CsvReader::read($path)['headers'], 'credit_card');
        app(Importer::class)->commit($amex, $path, 'amex.csv', $profile);

        $bh = BankTransaction::query()->where('description', 'B&H PHOTO NEW YORK')->sole();
        $this->assertSame([-7051, -9000, 'USD'], [$bh->amount_pence, $bh->original_amount_minor, $bh->original_currency]);
        $this->assertNull(BankTransaction::query()->where('description', 'SOMEWHERE ODD')->value('original_currency'), 'An unknown currency name is left, not guessed');
        $this->assertNull(BankTransaction::query()->where('description', 'TESCO STORES')->value('original_currency'));
    }
    // ---------------------------------------------------------------- checks of the fixes

    /** N13: when it isn't clear which Business account is the main one, you're asked, and it's remembered. */
    public function test_you_choose_which_business_account_is_the_main_one_when_it_isnt_clear(): void
    {
        $rows = [
            ['Date started (UTC)' => '2026-03-08', 'ID' => 'b2', 'Type' => 'TOPUP', 'Description' => 'Money added from ACME EVENTS LTD', 'Amount' => '100.00', 'Balance' => '100.00', 'Account' => 'Operating'],
            ['Date started (UTC)' => '2026-03-07', 'ID' => 'b1', 'Type' => 'TRANSFER', 'Description' => 'To Tax pot', 'Amount' => '20.00', 'Balance' => '20.00', 'Account' => 'Tax pot'],
        ];
        $path = $this->business($rows);
        $read = app(RevolutImport::class)->read($this->revolut, $path);
        $this->assertSame(['root' => 'Revolut', 'options' => [['key' => 'acct|Operating', 'label' => 'Operating'], ['key' => 'acct|Tax pot', 'label' => 'Tax pot']], 'chosen' => null], $read['main']);
        try {
            app(RevolutImport::class)->commit($this->revolut, $path, 'b.csv', mustChoose: true);
            $this->fail('Not imported until you say which is the main account');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is Revolut itself', $e->getMessage());
        }
        $this->assertSame(0, BankTransaction::query()->count());

        $this->actingAs(User::factory()->create());
        $this->post('/imports/preview', ['file' => UploadedFile::fake()->createWithContent('b.csv', (string) file_get_contents($path)), 'account_id' => $this->revolut->id]);
        $this->get('/imports')->assertInertia(fn ($p) => $p->where('preview.main.chosen', null)->has('preview.main.options', 2)->where('preview.main.none', RevolutImport::NO_MAIN));
        $this->post('/imports/commit')->assertSessionHas('error', fn ($m) => str_contains($m, 'is Revolut itself'));
        $this->assertSame(0, BankTransaction::query()->count());

        $this->post('/imports/preview', ['account_id' => $this->revolut->id, 'main_pocket' => 'acct|Operating']);
        $this->get('/imports')->assertInertia(fn ($p) => $p->where('preview.main.chosen', 'acct|Operating')
            ->where('preview.pockets', fn ($pockets) => collect($pockets)->pluck('name', 'label')->all() === ['Operating' => 'Revolut', 'Tax pot' => 'Revolut Tax pot']));
        $this->post('/imports/commit')->assertSessionHas('success');
        $this->assertSame(10000, (int) BankTransaction::query()->where('account_id', $this->revolut->id)->sum('amount_pence'), 'Operating is in Revolut');
        $this->assertSame('acct|Operating', $this->revolut->fresh()->import_profile['pocket']);

        // The next statement goes the same way without asking.
        $next = $this->business([
            ['Date started (UTC)' => '2026-03-12', 'ID' => 'b3', 'Type' => 'TRANSFER', 'Description' => 'To Tax pot', 'Amount' => '30.00', 'Balance' => '50.00', 'Account' => 'Tax pot'],
            ['Date started (UTC)' => '2026-03-11', 'ID' => 'b4', 'Type' => 'CARD_PAYMENT', 'Description' => 'Gaffer tape', 'Amount' => '-10.00', 'Balance' => '90.00', 'Account' => 'Operating'],
        ]);
        $read = app(RevolutImport::class)->read($this->revolut->fresh(), $next);
        $this->assertNull($read['main']);
        $this->commit($this->revolut, $next);
        $this->assertSame($this->revolut->id, BankTransaction::query()->where('description', 'Gaffer tape')->value('account_id'));
        $this->assertSame(2, Account::query()->count());
    }

    public function test_none_of_them_keeps_each_business_account_apart(): void
    {
        $path = $this->business([
            ['Date started (UTC)' => '2026-03-08', 'ID' => 'b2', 'Type' => 'TOPUP', 'Description' => 'Money added from ACME EVENTS LTD', 'Amount' => '100.00', 'Balance' => '100.00', 'Account' => 'Operating'],
            ['Date started (UTC)' => '2026-03-07', 'ID' => 'b1', 'Type' => 'TRANSFER', 'Description' => 'To Tax pot', 'Amount' => '20.00', 'Balance' => '20.00', 'Account' => 'Tax pot'],
        ]);
        app(RevolutImport::class)->commit($this->revolut, $path, 'b.csv', RevolutImport::NO_MAIN, mustChoose: true);
        $this->assertSame(0, BankTransaction::query()->where('account_id', $this->revolut->id)->count());
        $this->assertSame(['Revolut Operating', 'Revolut Tax pot'], Account::query()->where('parent_account_id', $this->revolut->id)->orderBy('name')->pluck('name')->all());
    }

    /** N64: a Business row imported under its UTC date before UK dates were used is never imported a second time. */
    public function test_a_business_row_imported_under_its_utc_date_is_not_imported_again(): void
    {
        $path = $this->business([
            ['Date started (UTC)' => '2026-06-05 23:30:00', 'Date completed (UTC)' => '2026-06-05 23:31:00', 'ID' => 'f2', 'Type' => 'CARD_PAYMENT', 'Description' => 'Late bar', 'Amount' => '-12.00', 'Balance' => '88.00', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-06-05 10:00:00', 'Date completed (UTC)' => '2026-06-05 10:00:00', 'ID' => 'f1', 'Type' => 'TOPUP', 'Description' => 'Top-up', 'Amount' => '100.00', 'Balance' => '100.00', 'Account' => 'GBP Main'],
        ]);
        // As they were imported before: both dated 5 June, the UTC date.
        $raw = CsvReader::read($path)['rows'];
        $old = fn (int $i, string $description, int $pence, int $balance) => new NormalizedRow(postedOn: CarbonImmutable::parse('2026-06-05'), transactionOn: null,
            description: $description, counterparty: null, reference: null, amountPence: $pence, balanceAfterPence: $balance, originalAmountMinor: null,
            originalCurrency: null, bankCategory: null, raw: $raw[$i], line: $i + 2);
        app(Importer::class)->store($this->revolut, [$old(0, 'Late bar', -1200, 8800), $old(1, 'Top-up', 10000, 10000)], $path, 'old.csv',
            ['preset' => 'revolut_business', 'pocket' => 'acct|GBP Main'], ['status' => 'passed', 'notes' => null], '2026-06-05', '2026-06-05');

        $read = app(RevolutImport::class)->read($this->revolut->fresh(), $path);
        $this->assertSame([['Revolut', 0, 2]], array_map(fn ($p) => [$p['name'], $p['new'], $p['duplicates']], $read['pockets']));
        $this->commit($this->revolut, $path);
        $this->assertSame(1, BankTransaction::query()->where('description', 'Late bar')->count(), 'Imported once');

        // A late-night row that's new still gets its UK date.
        $more = $this->business([
            ['Date started (UTC)' => '2026-06-06 23:10:00', 'Date completed (UTC)' => '2026-06-06 23:10:00', 'ID' => 'f3', 'Type' => 'CARD_PAYMENT', 'Description' => 'Taxi home', 'Amount' => '-20.00', 'Balance' => '68.00', 'Account' => 'GBP Main'],
            ['Date started (UTC)' => '2026-06-05 23:30:00', 'Date completed (UTC)' => '2026-06-05 23:31:00', 'ID' => 'f2', 'Type' => 'CARD_PAYMENT', 'Description' => 'Late bar', 'Amount' => '-12.00', 'Balance' => '88.00', 'Account' => 'GBP Main'],
        ]);
        $this->commit($this->revolut, $more);
        $this->assertSame(1, BankTransaction::query()->where('description', 'Late bar')->count());
        $this->assertSame('2026-06-07', BankTransaction::query()->where('description', 'Taxi home')->sole()->posted_on->format('Y-m-d'));
    }

    /** N98: the euros' statement in an account added for it pairs with the pounds side, in either order. */
    public function test_a_currency_statement_in_an_account_of_its_own_pairs_with_the_pounds_side(): void
    {
        $gbp = $this->file(self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,300.00,0.00,GBP,COMPLETED,300.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,-100.00,1.00,GBP,COMPLETED,199.00\n");
        $eur = $this->file(self::PERSONAL
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from GBP,115.20,0.00,EUR,COMPLETED,115.20\n"
            ."Card Payment,Current,2026-03-06 19:30:22,2026-03-07 06:00:00,Brasserie du Port,-42.00,0.00,EUR,COMPLETED,73.20\n");
        $this->commit($this->revolut, $gbp);
        $eurAccount = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $this->commit($eurAccount, $eur);

        $out = BankTransaction::query()->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('description', 'Exchanged from GBP')->sole();
        $this->assertSame([$eurAccount->id, 'EUR', null], [$in->account_id, $eurAccount->fresh()->currency, $eurAccount->fresh()->parent_account_id]);
        $this->assertSame([10000, 'exchange'], [$in->amount_pence, $in->fx_source], 'The euros count as the £100 they were bought with');
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->transfer_pair_id]);
        $this->assertSame(['transfer', 'transfer'], [$out->allocations()->value('kind'), $in->allocations()->value('kind')]);
    }

    public function test_the_pounds_side_imported_after_the_euros_account_pairs_too(): void
    {
        $eurAccount = Account::query()->create(['name' => 'Euro account', 'kind' => 'bank', 'currency' => 'GBP']);
        $this->commit($eurAccount, $this->file(self::PERSONAL
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged from GBP,115.20,0.00,EUR,COMPLETED,115.20\n"));
        $this->commit($this->revolut, $this->file(self::PERSONAL
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,300.00,0.00,GBP,COMPLETED,300.00\n"
            ."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,-100.00,0.00,GBP,COMPLETED,200.00\n"));
        $out = BankTransaction::query()->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('description', 'Exchanged from GBP')->sole();
        $this->assertSame([$in->id, $out->id], [$out->transfer_pair_id, $in->fresh()->transfer_pair_id]);
        $this->assertSame(['transfer', 'transfer'], [$out->allocations()->value('kind'), $in->allocations()->value('kind')]);
    }

    /** N59: a card line in yen keeps the rate in yen (¥187.50 per £1), not hundredths of it. */
    public function test_a_card_line_in_yen_keeps_its_rate_in_yen(): void
    {
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $path = $this->file("Date,Description,Amount,Extended Details,Appears On Your Statement As,Address,Town/City,Postcode,Country,Reference,Category\n"
            ."04/04/2025,LAWSON TOKYO,8.00,\"Foreign Spend Amount: 1,500 JAPANESE YEN Commission Amount: 0.22 Currency Exchange Rate: 191.08\",LAWSON,,TOKYO,,JAPAN,'AT1',Shopping\n");
        app(Importer::class)->commit($amex, $path, 'amex.csv', ProfileDetector::detect(CsvReader::read($path)['headers'], 'credit_card'));
        $tx = BankTransaction::query()->where('description', 'LAWSON TOKYO')->sole();
        $this->assertSame([-800, -1500, 'JPY', 'bank', '2025-04-04'], [$tx->amount_pence, $tx->original_amount_minor, $tx->original_currency, $tx->fx_source, $tx->fx_rate_date->format('Y-m-d')]);
        $this->assertSame('187.5', Present::tx($tx)['fx']['units_per_gbp']);
    }

    /** N59: the Amex backfill finds the foreign amount whatever its case or spacing (MariaDB compares JSON by case). */
    public function test_the_amex_backfill_finds_foreign_spending_in_any_case(): void
    {
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $path = $this->file("x\n1\n");
        $row = new NormalizedRow(postedOn: CarbonImmutable::parse('2025-04-04'), transactionOn: null, description: 'LAWSON TOKYO', counterparty: null, reference: null,
            amountPence: -800, balanceAfterPence: null, originalAmountMinor: null, originalCurrency: null, bankCategory: null,
            raw: ['Date' => '04/04/2025', 'Amount' => '8.00', 'Extended Details' => 'FOREIGN  SPEND AMOUNT: 1,500 JAPANESE YEN'], line: 2);
        app(Importer::class)->store($amex, [$row], $path, 'amex.csv', [], ['status' => 'not_available', 'notes' => null], '2025-04-04', '2025-04-04');

        (require database_path('migrations/2026_12_01_000700_revolut_currency_used.php'))->fill();
        $tx = BankTransaction::query()->where('description', 'LAWSON TOKYO')->sole();
        $this->assertSame([-1500, 'JPY', 'bank', '2025-04-04'], [$tx->original_amount_minor, $tx->original_currency, $tx->fx_source, $tx->fx_rate_date->format('Y-m-d')]);
        $this->assertSame('187.5', Present::tx($tx)['fx']['units_per_gbp']);
    }
}
