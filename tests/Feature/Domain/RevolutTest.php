<?php

namespace Tests\Feature\Domain;

use App\Domain\Import\RevolutImport;
use App\Domain\Ledger\Reconciler;
use App\Domain\Matching\OwnAccounts;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Revolut statements: each currency in its own pocket, sterling for every line, exchanges as transfers. */
class RevolutTest extends TestCase
{
    use RefreshDatabase;

    private Account $revolut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Http::fake(); // no live rate fetches: HMRC's March 2026 rates are stored below
        Setting::put('own_names', ['Alex Smith']);
        foreach (['EUR' => '1.150000', 'USD' => '1.300000'] as $cur => $rate) {
            ExchangeRate::query()->create(['currency' => $cur, 'period_start' => '2026-03-01', 'period_end' => '2026-03-31', 'units_per_gbp' => $rate, 'source' => 'hmrc_monthly']);
        }
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP', 'last4' => '4821']);
    }

    private function fixture(string $name): string
    {
        return base_path("tests/fixtures/{$name}");
    }

    public function test_a_personal_statement_goes_into_a_pocket_per_currency(): void
    {
        $read = app(RevolutImport::class)->read($this->revolut, $this->fixture('revolut_personal.csv'));
        $this->assertSame('personal', $read['kind']);
        $this->assertSame(['PENDING' => 1], $read['skipped'], 'Pending rows are left until they complete');
        $this->assertSame(1, $read['exchanges']);
        $this->assertSame(['Revolut', 'Revolut EUR', 'Revolut GBP savings'], array_column($read['pockets'], 'name'));
        $this->assertSame(['passed', 'passed', 'not_available'], array_map(fn ($p) => $p['balance']['status'], $read['pockets']));

        app(RevolutImport::class)->commit($this->revolut, $this->fixture('revolut_personal.csv'), 'revolut.csv');
        $eur = Account::query()->where('name', 'Revolut EUR')->sole();
        $this->assertSame(['EUR', $this->revolut->id], [$eur->currency, $eur->parent_account_id]);

        // The exchange: £100 out of GBP, €115.20 into EUR, counted as £100 each way and paired as a transfer.
        $out = BankTransaction::query()->where('account_id', $this->revolut->id)->where('description', 'Exchanged to EUR')->sole();
        $in = BankTransaction::query()->where('account_id', $eur->id)->where('description', 'Exchanged from GBP')->sole();
        $this->assertSame([-10000, 10000, 11520, 'EUR', 'exchange'], [$out->amount_pence, $in->amount_pence, $in->original_amount_minor, $in->original_currency, $in->fx_source]);
        $this->assertSame([$in->id, $out->id], [$out->transfer_pair_id, $in->transfer_pair_id]);
        $this->assertSame('transfer', $in->allocations()->value('kind'));

        // A euro dinner: its own €42.00, and £36.52 at HMRC's March 2026 rate (1.15 per £1).
        $dinner = BankTransaction::query()->where('description', 'Brasserie du Port')->sole();
        $this->assertSame([-4200, -3652, 'hmrc_monthly', '2026-03-01', '2026-03-06', '19:30'], [$dinner->original_amount_minor, $dinner->amount_pence, $dinner->fx_source,
            $dinner->fx_rate_date->format('Y-m-d'), $dinner->effectiveDate()->format('Y-m-d'), $dinner->transacted_time]);
        $this->assertSame(7320, $dinner->balance_after_minor, 'The pocket’s own balance, in euros');

        // Each pocket matches its statement in its own currency, and importing again adds nothing.
        $this->assertSame([], Reconciler::account($eur)['gaps']);
        $this->assertSame('EUR', Reconciler::account($eur)['currency']);
        $again = app(RevolutImport::class)->read($this->revolut->fresh(), $this->fixture('revolut_personal.csv'));
        $this->assertSame(0, array_sum(array_column($again['pockets'], 'new')));
        $this->assertNotNull($again['pockets'][1]['account'], 'The EUR pocket is found again');
    }

    public function test_a_business_statement_with_several_accounts_in_one_file(): void
    {
        app(RevolutImport::class)->commit($this->revolut, $this->fixture('revolut_business.csv'), 'business.csv');
        $eur = Account::query()->where('name', 'Revolut EUR')->sole();
        $this->assertSame(3, BankTransaction::query()->where('account_id', $this->revolut->id)->count(), 'The declined payment is skipped');

        $gbp = BankTransaction::query()->where('account_id', $this->revolut->id)->where('description', 'like', 'Main%')->sole();
        $leg = BankTransaction::query()->where('account_id', $eur->id)->where('description', 'like', 'Main%')->sole();
        $this->assertSame([-1223955, 1223955, 1400000], [$gbp->amount_pence, $leg->amount_pence, $leg->original_amount_minor]);
        $this->assertSame($leg->id, $gbp->transfer_pair_id);

        // A dollar purchase from the euro account: €76.64 counted at HMRC's euro rate.
        $usd = BankTransaction::query()->where('description', 'B&H Photo')->sole();
        $this->assertSame([-7664, 'EUR', -6664, '7331'], [$usd->original_amount_minor, $usd->original_currency, $usd->amount_pence, $usd->card_last4]);

        // Money in from a client is income, not a transfer.
        $fee = BankTransaction::query()->where('description', 'like', 'Money added from ACME%')->sole();
        $this->assertNull($fee->transfer_pair_id);
        $this->assertNotSame('transfer', $fee->allocations()->value('kind'));
        $this->assertSame([], Reconciler::account($this->revolut)['gaps']);
        $this->assertSame([], Reconciler::account($eur)['gaps']);
    }

    public function test_uploading_a_revolut_file_shows_its_pockets_then_imports_them(): void
    {
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->createWithContent('revolut.csv', (string) file_get_contents($this->fixture('revolut_personal.csv')));
        $this->post('/imports/preview', ['file' => $file, 'account_id' => $this->revolut->id])->assertRedirect('/imports');
        $this->get('/imports')->assertOk()->assertInertia(fn ($p) => $p
            ->where('preview.ok', true)
            ->where('preview.profile.preset', 'revolut_personal')
            ->where('preview.new', 7)
            ->has('preview.pockets', 3)
            ->where('preview.pockets.1.name', 'Revolut EUR')
            ->where('preview.pockets.1.exists', false)
            ->where('preview.skipped.PENDING', 1));
        $this->post('/imports/commit')->assertSessionHas('success', fn ($m) => str_contains($m, 'into Revolut EUR'));
        $this->assertSame(7, BankTransaction::query()->count());

        // The exchange (both sides), the top-up from another bank and the move to savings: all your own money moving.
        $this->get('/transactions')->assertInertia(fn ($p) => $p->where('totals.transfer_count', 4));
    }

    private function file(string $csv): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rv').'.csv';
        file_put_contents($path, $csv);

        return $path;
    }

    public function test_an_exchange_split_across_two_currency_statements_is_paired_in_either_order(): void
    {
        $head = "Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n";
        $gbp = $this->file($head."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:00,Top-up by *4821,300.00,0.00,GBP,COMPLETED,300.00\nExchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,-100.00,0.00,GBP,COMPLETED,200.00\n");
        $eur = $this->file($head."Exchange,Current,2026-03-04 10:00:00,2026-03-04 10:00:00,Exchanged to EUR,115.20,0.00,EUR,COMPLETED,115.20\nCard Payment,Current,2026-03-06 19:30:22,2026-03-07 06:00:00,Brasserie du Port,-42.00,0.00,EUR,COMPLETED,73.20\n");

        // Pounds first, then euros: the euro side counts as exactly the £100 it was bought with.
        app(RevolutImport::class)->commit($this->revolut, $gbp, 'gbp.csv');
        app(RevolutImport::class)->commit($this->revolut->fresh(), $eur, 'eur.csv');
        $out = BankTransaction::query()->where('amount_pence', -10000)->sole();
        $in = BankTransaction::query()->where('original_currency', 'EUR')->where('original_amount_minor', 11520)->sole();
        $this->assertSame([10000, 'exchange', $out->id, $in->id], [$in->amount_pence, $in->fx_source, $in->transfer_pair_id, $out->fresh()->transfer_pair_id]);
        $this->assertSame('transfer', $in->allocations()->value('kind'));

        // A fresh account, euros first: the euro side is converted at HMRC's rate, and the pounds side pairs with it later.
        BankTransaction::query()->update(['transfer_pair_id' => null]);
        $other = Account::query()->create(['name' => 'Revolut 2', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        app(RevolutImport::class)->commit($other, $eur, 'eur.csv');
        app(RevolutImport::class)->commit($other->fresh(), $gbp, 'gbp.csv');
        $in2 = BankTransaction::query()->whereHas('account', fn ($q) => $q->where('parent_account_id', $other->id))->where('original_amount_minor', 11520)->sole();
        $out2 = BankTransaction::query()->where('account_id', $other->id)->where('amount_pence', -10000)->sole();
        $this->assertSame([10017, 'hmrc_monthly'], [$in2->amount_pence, $in2->fx_source]);
        $this->assertSame($out2->id, $in2->fresh()->transfer_pair_id);
        $this->assertSame('transfer', $out2->allocations()->value('kind'));
    }

    public function test_an_excel_statement_is_read_like_the_csv(): void
    {
        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", (string) file_get_contents($this->fixture('revolut_personal.csv')))));
        $path = tempnam(sys_get_temp_dir(), 'rv').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $strings = [];
        $sheet = '';
        foreach ($rows as $r => $cells) {
            $sheet .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => $v) {
                $ref = chr(65 + $c).($r + 1);
                if ($v !== '' && is_numeric($v) && $c !== 2 && $c !== 3) {
                    $sheet .= '<c r="'.$ref.'"><v>'.$v.'</v></c>';
                } elseif ($v !== '') {
                    $strings[] = htmlspecialchars($v, ENT_XML1);
                    $sheet .= '<c r="'.$ref.'" t="s"><v>'.(count($strings) - 1).'</v></c>';
                }
            }
            $sheet .= '</row>';
        }
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Statement" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($t) => '<si><t>'.$t.'</t></si>', $strings)).'</sst>');
        $zip->close();

        $read = app(RevolutImport::class)->read($this->revolut, $path);
        $this->assertSame('personal', $read['kind']);
        $this->assertSame(7, array_sum(array_column($read['pockets'], 'new')));
        $this->assertSame(['passed', 'passed', 'not_available'], array_map(fn ($p) => $p['balance']['status'], $read['pockets']));
    }

    public function test_moves_to_and_from_savings_and_pockets_are_your_own_money(): void
    {
        foreach (['To EUR Savings', 'From EUR Family Savings', 'To pocket GBP Holiday from GBP', 'Pocket Withdrawal', 'To GBP Rainy Day Vault'] as $text) {
            $this->assertMatchesRegularExpression(OwnAccounts::OWN_PATTERN, $text, $text);
        }
        foreach (['To Euro Car Hire', 'Pocket Money Toys Ltd', 'From EUR'] as $text) {
            $this->assertDoesNotMatchRegularExpression(OwnAccounts::OWN_PATTERN, $text, $text);
        }
    }
}
