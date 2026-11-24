<?php

namespace Tests\Feature;

use App\Domain\Export\YearExporter;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Jobs\JobGatherer;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Telegram\Telegram;
use App\Mail\InvoiceEmail;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Document;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobFind;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\StatementBalance;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\Money;
use App\Support\UkTaxYear;
use Brick\Money\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Review fixes for what the pages show: amounts in currencies without pence (yen) or with three decimal places
 * (Kuwaiti dinar), the job page's own ledger lines and what the search found for it, the year spreadsheet and the
 * Telegram to-do list. The page-only fixes (accounts, transactions header, calendar, job form) are checked through
 * the shared formatting code, run in node where it's installed.
 */
class NewFixesFrontendTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
        Queue::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create());
        $this->bank = Account::query()->create(['name' => 'Revolut Business', 'kind' => 'bank', 'provider' => 'revolut']);
    }

    private function tx(string $date, int $pence, string $description, array $extra = []): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $this->bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $description, 'merchant_key' => strtoupper(strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $date.$pence.$description), 'occurrence' => 1,
        ] + $extra);
    }

    // ------------------------------------------------------------------ N34, N60, N100: currency decimal places

    public function test_amounts_use_each_currencys_own_decimal_places(): void
    {
        // An original amount or a pocket's balance, in the currency's own minor units.
        $this->assertSame("\u{2212}JPY 1,500", Money::formatMinor(-1500, 'JPY'));
        $this->assertSame('JPY 17,500', Money::formatMinor(17500, 'JPY', false));
        $this->assertSame('KWD 12.345', Money::formatMinor(12345, 'KWD'));
        $this->assertSame('BHD 0.050', Money::formatMinor(50, 'BHD'));
        $this->assertSame('JPY 1,500', Money::formatMinor(1500, 'jpy'));
        // Two-decimal currencies, and codes Brick doesn't know, are as format() has them.
        $this->assertSame('£1,234.56', Money::formatMinor(123456));
        $this->assertSame('€42.00', Money::formatMinor(4200, 'EUR'));
        $this->assertSame('XYZ 12.34', Money::formatMinor(1234, 'XYZ'));

        // Amounts held in hundredths (invoices, POs, bookings) keep format(), unchanged: ¥150,000 is 15000000.
        $this->assertSame('JPY 150,000.00', Money::format(15000000, 'JPY', false));
        $this->assertSame('KWD 1,250.50', Money::format(125050, 'KWD', false));
        $this->assertSame('£1,234.56', Money::format(123456));

        // Converting between the two.
        $this->assertSame(1500, Money::fromHundredths(150000, 'JPY'));
        $this->assertSame(-1501, Money::fromHundredths(-150050, 'JPY'));
        $this->assertSame(12500, Money::fromHundredths(1250, 'KWD'));
        $this->assertSame(1250, Money::fromHundredths(1250, 'EUR'));
        $this->assertNull(Money::fromHundredths(null, 'JPY'));
        $this->assertSame(150000, Money::toHundredths(1500, 'JPY'));
        $this->assertSame(-1235, Money::toHundredths(-12345, 'KWD'));
        $this->assertSame(1250, Money::toHundredths(1250, 'EUR'));
        $this->assertSame(1500.0, Money::major(1500, 'JPY'));
        $this->assertSame(12.345, Money::major(12345, 'KWD'));
    }

    /** P1: invoices are kept in hundredths whatever the currency, and the client's PDF and email show them so. */
    public function test_an_invoice_in_yen_or_dinars_shows_its_own_total_on_the_pdf_and_in_the_email(): void
    {
        $service = app(InvoiceService::class);
        foreach ([['JPY', '150000', 'JPY 150,000.00', 'JPY 15,000,000'], ['KWD', '1,250.50', 'KWD 1,250.50', 'KWD 125.050']] as [$currency, $price, $shown, $wrong]) {
            $invoice = Invoice::query()->create(['status' => 'draft', 'total_minor' => 0, 'currency' => $currency, 'bill_to_name' => 'Client KK']);
            $invoice = $service->updateDraft($invoice, ['currency' => $currency], [['description' => 'Rigging', 'quantity' => '1', 'unit_price' => $price]]);
            $invoice->forceFill(['number' => 'INV-'.$currency, 'due_date' => '2026-12-31'])->save();

            $pdf = view('pdf.invoice', ['invoice' => $invoice->fresh('lines'), 'business' => ['name' => 'Me', 'address' => null, 'email' => null, 'bank' => null]])->render();
            $this->assertStringContainsString($shown, $pdf, $currency);
            $this->assertStringNotContainsString($wrong, $pdf, $currency);

            $email = (new InvoiceEmail($invoice->fresh()))->content()->htmlString;
            $this->assertStringContainsString('for '.$shown, $email, $currency);
        }
    }

    public function test_the_pages_list_of_decimal_places_matches_the_servers(): void
    {
        $source = file_get_contents(resource_path('js/lib/format.ts'));
        $this->assertSame(1, preg_match('/const DIGITS: Record<string, number> = \{(.*?)\};/s', $source, $m));
        preg_match_all('/([A-Z]{3}): (\d)/', $m[1], $pairs, PREG_SET_ORDER);
        $page = collect($pairs)->mapWithKeys(fn ($p) => [$p[1] => (int) $p[2]])->sortKeys()->all();

        $server = [];
        foreach (array_keys(require base_path('vendor/brick/money/data/iso-currencies.php')) as $code) {
            if (($d = Currency::of($code)->getDefaultFractionDigits()) !== 2) {
                $server[$code] = $d;
            }
        }
        ksort($server);
        $this->assertSame($server, $page);
    }

    public function test_the_page_formatting_code_uses_each_currencys_decimal_places_and_the_uk_date(): void
    {
        $out = $this->node(<<<'JS'
            const RealDate = Date;
            // 00:30 on 15 July 2026 in London (summer time): still 14 July in UTC.
            globalThis.Date = class extends RealDate { constructor(...a) { super(...(a.length ? a : ['2026-07-14T23:30:00Z'])); } };
            const f = await import(process.argv[2]);
            console.log(JSON.stringify([
                f.moneyMinor(-1500, 'JPY'), f.moneyMinor(17500, 'JPY', { signed: false }), f.moneyMinor(-12345, 'KWD'), f.moneyMinor(-3652), f.moneyMinor(4200, 'EUR'),
                f.money(15000000, 'JPY'), f.money(125050, 'KWD'), f.money(-3652), f.londonToday(),
            ]));
            JS);

        // Original amounts in the currency's own units; invoices, POs and bookings (hundredths) as they always were.
        $this->assertSame(["\u{2212}JPY 1,500", 'JPY 17,500', "\u{2212}KWD 12.345", "\u{2212}£36.52", '€42.00', 'JPY 150,000.00', 'KWD 1,250.50', "\u{2212}£36.52", '2026-07-15'], $out);
    }

    /** N58: the rate a line was converted at, in words; a line converted at the exchange it came from has no rate of its own. */
    public function test_the_rate_says_where_the_sterling_came_from_without_an_empty_rate(): void
    {
        $out = $this->node(<<<'JS'
            const f = await import(process.argv[2]);
            console.log(JSON.stringify([
                f.rateText({ units_per_gbp: '1.15', source: 'hmrc_monthly' }, 'EUR'), f.rateText({ units_per_gbp: '', source: 'exchange' }, 'EUR'),
                f.rateText({ units_per_gbp: '190.11', source: 'bank' }, 'JPY'), f.rateText(null, 'EUR') ?? null,
            ]));
            JS);

        $this->assertSame(['1.15 EUR per £1 (HMRC monthly rate)', 'Rate: the exchange it came from', '190.11 JPY per £1 (the bank’s rate)', null], $out);
    }

    /** N36: an overdraft, a card in credit and a pocket's own currency on the Accounts page. */
    public function test_balances_say_overdrawn_or_in_credit_in_the_accounts_own_currency(): void
    {
        $out = $this->node(<<<'JS'
            const f = await import(process.argv[2]);
            console.log(JSON.stringify([
                f.balanceText('bank', -25000, 'GBP'), f.balanceText('bank', 25000, 'GBP'), f.balanceText('bank', 120000, 'EUR'),
                f.balanceText('credit_card', 41250, 'GBP'), f.balanceText('credit_card', -5000, 'GBP'),
                f.balanceText('credit_card', -41250, 'GBP', 'bank'), f.balanceText('credit_card', 5000, 'GBP', 'bank'), f.balanceText('credit_card', 0, 'GBP'),
                f.balanceText('bank', 17500, 'JPY'),
            ]));
            JS);

        $this->assertSame(['Overdrawn £250.00', 'Balance £250.00', 'Balance €1,200.00', 'Owed £412.50', 'In credit £50.00', 'Owed £412.50', 'In credit £50.00', 'Nothing owed',
            'Balance JPY 17,500'], $out);
    }

    /** N81: transfers on a card account (all money in) aren't shown as £0.00 moved. */
    public function test_money_moved_between_your_accounts_shows_both_sides(): void
    {
        $out = $this->node(<<<'JS'
            const f = await import(process.argv[2]);
            console.log(JSON.stringify([f.movedBetweenAccounts(0, 180000), f.movedBetweenAccounts(-50000, 50000), f.movedBetweenAccounts(-50000, 0), f.movedBetweenAccounts(-30000, 50000)]));
            JS);

        $this->assertSame(['£1,800.00 in', '£500.00', '£500.00 out', '£300.00 out, £500.00 in'], $out);
    }

    /** N83: an entry's dates, to compare with a job's own days (a rig and a de-rig days later have a gap). */
    public function test_an_entrys_dates_are_every_day_it_covers(): void
    {
        $out = $this->node(<<<'JS'
            const f = await import(process.argv[2]);
            const job = ['2026-12-01', '2026-12-05'];
            console.log(JSON.stringify([
                f.datesBetween('2026-12-03', '2026-12-03').filter((d) => !job.includes(d)),
                f.datesBetween('2026-12-05', '2026-12-05').filter((d) => !job.includes(d)),
                f.datesBetween('2026-03-28', '2026-03-30'),
            ]));
            JS);

        $this->assertSame([['2026-12-03'], [], ['2026-03-28', '2026-03-29', '2026-03-30']], $out);
    }

    // ------------------------------------------------------------------ N58: a job's own ledger lines

    public function test_the_jobs_own_lines_show_when_where_which_card_and_the_currency_paid(): void
    {
        $job = WorkJob::query()->create(['title' => 'Paris Expo', 'starts_on' => '2025-07-03', 'ends_on' => '2025-07-03', 'status' => 'done']);
        $meal = Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
        $tx = $this->tx('2025-07-03', -3652, 'LE CAFE', ['original_amount_minor' => -4200, 'original_currency' => 'EUR', 'transacted_time' => '20:15',
            'place' => 'Paris, FR', 'card_last4' => '4821', 'payment_type' => 'Card Payment', 'fx_units_per_gbp' => '1.15', 'fx_rate_date' => '2025-07-01', 'fx_source' => 'hmrc_monthly']);
        app(AllocationWriter::class)->decide($tx, [
            ['kind' => 'business_expense', 'category_id' => $meal, 'work_job_id' => $job->id, 'amount_pence' => -2000],
            ['kind' => 'personal', 'amount_pence' => -1652],
        ], 'you', true);
        $cash = ManualExpense::query()->create(['spent_on' => '2025-07-03', 'tax_year' => '2025-26', 'description' => 'Metro ticket', 'merchant_key' => 'METRO',
            'amount_pence' => -250, 'paid_with' => 'cash', 'original_amount_minor' => -290, 'original_currency' => 'EUR', 'fx_units_per_gbp' => '1.16', 'fx_source' => 'hmrc_monthly']);
        app(AllocationWriter::class)->decide($cash, AllocationWriter::lines(-250, 'business_expense', $meal, $job->id), 'you', true);

        $this->get('/jobs/'.$job->id)->assertOk()->assertInertia(fn ($p) => $p
            ->has('lines', 2)
            ->where('lines.0.description', 'LE CAFE')
            ->where('lines.0.time', '20:15')
            ->where('lines.0.place', 'Paris, FR')
            ->where('lines.0.card_last4', '4821')
            ->where('lines.0.payment_type', 'Card Payment')
            ->where('lines.0.account.name', 'Revolut Business')
            ->where('lines.0.original_amount_minor', -4200)
            ->where('lines.0.original_currency', 'EUR')
            ->where('lines.0.fx.source', 'hmrc_monthly')
            ->where('lines.0.amount_pence', -2000)
            ->where('lines.0.payment_pence', -3652)
            ->where('lines.1.account.name', 'Manual (cash)')
            ->where('lines.1.original_currency', 'EUR')
            ->where('lines.1.original_amount_minor', -290));
    }

    // ------------------------------------------------------------------ N58, N60: the year spreadsheet

    public function test_the_year_spreadsheet_has_time_place_card_and_the_rate_and_yen_in_whole_yen(): void
    {
        $this->tx('2026-03-10', -789, 'ICHIRAN RAMEN', ['original_amount_minor' => -1500, 'original_currency' => 'JPY', 'transacted_time' => '19:05',
            'place' => 'Tokyo, JP', 'card_last4' => '4821', 'payment_type' => 'Card Payment', 'fx_units_per_gbp' => '190', 'fx_rate_date' => '2026-03-01', 'fx_source' => 'hmrc_monthly']);
        // A Business-account line with the currency but no stored rate: the bank's own rate, from what it charged.
        $this->tx('2026-03-11', -1000, 'LAWSON', ['original_amount_minor' => -1900, 'original_currency' => 'JPY']);
        ManualExpense::query()->create(['spent_on' => '2026-03-12', 'tax_year' => '2025-26', 'description' => 'Taxi', 'merchant_key' => 'TAXI',
            'amount_pence' => -1000, 'paid_with' => 'cash', 'original_amount_minor' => -2500, 'original_currency' => 'KWD', 'fx_units_per_gbp' => '0.25', 'fx_source' => 'hmrc_monthly']);
        $tools = Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
        app(AllocationWriter::class)->decide(ManualExpense::query()->sole(), AllocationWriter::lines(-1000, 'business_expense', $tools), 'you', true);

        $path = tempnam(sys_get_temp_dir(), 'yx').'.xlsx';
        YearExporter::write('2025-26', $path);
        $rows = $this->sheet($path, 'Transactions');
        @unlink($path);

        $head = array_flip($rows[0]);
        foreach (['Time', 'Card', 'Payment type', 'Place', 'Original amount', 'Currency', 'Rate (per £1)', 'Rate used'] as $col) {
            $this->assertArrayHasKey($col, $head, $col);
        }
        $ramen = collect($rows)->first(fn ($r) => ($r[$head['Description']] ?? null) === 'ICHIRAN RAMEN');
        $this->assertSame(['19:05', '•••• 4821', 'Card Payment', 'Tokyo, JP', -1500, 'JPY', 190, 'HMRC monthly rate'],
            [$ramen[$head['Time']], $ramen[$head['Card']], $ramen[$head['Payment type']], $ramen[$head['Place']], $ramen[$head['Original amount']],
                $ramen[$head['Currency']], $ramen[$head['Rate (per £1)']], $ramen[$head['Rate used']]]);

        $lawson = collect($rows)->first(fn ($r) => ($r[$head['Description']] ?? null) === 'LAWSON');
        $this->assertSame([-1900, 190, 'The bank’s rate'], [$lawson[$head['Original amount']], $lawson[$head['Rate (per £1)']], $lawson[$head['Rate used']]]);

        $taxi = collect($rows)->first(fn ($r) => ($r[$head['Description']] ?? null) === 'Taxi');
        $this->assertEqualsWithDelta(-2.5, $taxi[$head['Original amount']], 0.0001);
        $this->assertSame('KWD', $taxi[$head['Currency']]);
    }

    // ------------------------------------------------------------------ N80: what the search found stays current

    public function test_a_payment_decided_from_the_job_page_shows_as_on_the_job_not_still_undecided(): void
    {
        Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => Client::query()->value('id'), 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'status' => 'done']);
        $hardware = $this->tx('2025-05-15', -1899, 'JOHNSONS HARDWARE');
        $lunch = $this->tx('2025-05-14', -1250, 'CORNER SHOP LTD');
        app(JobGatherer::class)->gather($job);
        $finds = JobFind::query()->where('type', 'payment')->where('status', 'possible')->pluck('ref')->all();
        $this->assertContains((string) $hardware->id, $finds);
        $this->assertContains((string) $lunch->id, $finds);

        $this->get('/jobs/'.$job->id)->assertInertia(fn ($p) => $p
            ->where('finds', fn ($f) => collect($f)->firstWhere('ref', (string) $hardware->id)['undecided'] === true));

        // Decided in the side panel: the hardware for this job, the lunch as personal.
        $tools = Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
        $this->post("/items/bank/{$hardware->id}/decide", ['kind' => 'business_expense', 'category_id' => $tools, 'work_job_id' => $job->id])->assertSessionHas('success');
        $this->post("/items/bank/{$lunch->id}/decide", ['kind' => 'personal'])->assertSessionHas('success');

        $this->get('/jobs/'.$job->id)->assertInertia(fn ($p) => $p->where('finds', function ($f) use ($hardware, $lunch) {
            $f = collect($f);
            $hw = $f->firstWhere('ref', (string) $hardware->id);

            return $hw['status'] === 'linked' && $hw['undecided'] === false && ! str_contains($hw['detail'], 'not decided')
                && $f->firstWhere('ref', (string) $lunch->id) === null;
        }));

        // Its button now takes it off the job again (your own decision, kept as yours).
        $find = JobFind::query()->where('type', 'payment')->where('ref', (string) $hardware->id)->sole();
        $this->post("/job-finds/{$find->id}/reject")->assertSessionHas('success');
        $this->assertNull($hardware->fresh()->allocations()->value('work_job_id'));
        $this->assertSame('you', $hardware->fresh()->allocations()->value('decided_by'));
    }

    // ------------------------------------------------------------------ N58: Telegram

    public function test_telegram_payments_to_decide_say_when_where_which_card_and_the_currency(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_buttons_on', true);
        $this->tx('2025-07-04', -2000, 'BISTRO LYON', ['original_amount_minor' => -2300, 'original_currency' => 'EUR', 'transacted_time' => '21:40',
            'place' => 'Lyon, FR', 'card_last4' => '4821', 'payment_type' => 'Card Payment']);

        Telegram::sendTodo('777');

        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'BISTRO LYON')
            && str_contains($r['text'], '21:40 · Revolut Business · card •••• 4821 · Card Payment · Lyon, FR · paid €23.00'));
    }

    public function test_a_po_in_yen_is_summed_in_yen_in_telegram(): void
    {
        $doc = Document::query()->create(['source' => 'telegram', 'original_name' => 'po.pdf', 'stored_path' => 'x', 'sha256' => str_repeat('b', 64),
            'status' => 'read', 'summary' => 'A PO.', 'extraction' => ['po' => ['po_number' => 'PO-1', 'currency' => 'JPY', 'lines' => [['amount_minor' => 15000000]],
                'lines_total_minor' => 15000000, 'lines_match_total' => true]]]);

        // Read in hundredths (¥150,000.00 is 15000000), and shown so: not 100 times too much.
        $this->assertMatchesRegularExpression('/1 lines, JPY 150,000\.00\.\n/', Telegram::documentSummary($doc->fresh()));
    }

    public function test_telegram_says_what_was_paid_in_yen_in_whole_yen(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_buttons_on', true);
        $this->tx('2026-03-10', -789, 'ICHIRAN RAMEN', ['original_amount_minor' => -1500, 'original_currency' => 'JPY', 'place' => 'Tokyo, JP']);

        Telegram::sendTodo('777');

        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'ICHIRAN RAMEN') && str_contains($r['text'], 'Tokyo, JP · paid JPY 1,500')
            && ! str_contains($r['text'], 'JPY 15.00'));
    }

    // ------------------------------------------------------------------ P5: a find suggested as a business cost since

    public function test_a_payment_found_undecided_and_only_suggested_since_is_still_opened_to_decide(): void
    {
        Client::query()->create(['name' => 'Acme Events Ltd']);
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => Client::query()->value('id'), 'starts_on' => '2025-05-14', 'ends_on' => '2025-05-15', 'status' => 'done']);
        $hardware = $this->tx('2025-05-15', -1899, 'JOHNSONS HARDWARE');
        app(JobGatherer::class)->gather($job);
        $this->assertSame('possible', JobFind::query()->where('type', 'payment')->where('ref', (string) $hardware->id)->value('status'));

        // The categoriser suggests a business cost with no job: nothing you've decided.
        $tools = Category::query()->where('is_allowable', true)->where('kind', 'expense')->value('id');
        app(AllocationWriter::class)->decide($hardware, AllocationWriter::lines(-1899, 'business_expense', $tools), 'matcher', false);

        $find = fn () => collect($this->get('/jobs/'.$job->id)->assertOk()->viewData('page')['props']['finds'])->firstWhere('ref', (string) $hardware->id);
        $f = $find();
        $this->assertSame('possible', $f['status']);
        $this->assertTrue($f['undecided'], 'a suggestion is opened to decide, not linked as yours unseen');
        $this->assertStringNotContainsString('not decided', $f['detail']);
        $this->assertStringContainsString('Suggested as a business cost', $f['detail']);

        // Confirmed by you: now it can be linked with a click.
        app(AllocationWriter::class)->decide($hardware->fresh(), AllocationWriter::lines(-1899, 'business_expense', $tools), 'you', true);
        $f = $find();
        $this->assertFalse($f['undecided']);
        $this->assertSame('Decided as a business cost with no job: was it for this one?', $f['detail']);
    }

    // ------------------------------------------------------------------ P2, P3: amounts kept in hundredths before

    public function test_lines_imported_with_a_local_amount_in_hundredths_are_put_in_the_currencys_own_units(): void
    {
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo',
            'import_profile' => ['preset' => 'custom', 'currencyColumn' => 'Local currency', 'originalAmountColumn' => 'Local amount']]);
        $batch = ImportBatch::query()->create(['account_id' => $monzo->id, 'original_filename' => 'monzo.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x']);
        $revolutBatch = ImportBatch::query()->create(['account_id' => $this->bank->id, 'original_filename' => 'revolut.csv', 'file_sha256' => str_repeat('d', 64), 'stored_path' => 'y']);
        $line = fn (string $desc, int $pence, ?int $orig, ?string $cur, array $raw, ?Account $acc = null) => BankTransaction::query()->create([
            'account_id' => ($acc ?? $monzo)->id, 'import_batch_id' => $acc ? $revolutBatch->id : $batch->id, 'posted_on' => '2026-03-10', 'tax_year' => '2025-26',
            'description' => $desc, 'merchant_key' => $desc, 'amount_pence' => $pence, 'original_amount_minor' => $orig, 'original_currency' => $cur, 'raw' => $raw,
            'fingerprint' => hash('sha256', $desc), 'occurrence' => 1,
        ]);
        // Kept in hundredths by the old importer: ¥1,500 as 150000, KWD 12.34 as 1234.
        $ramen = $line('ICHIRAN', -789, -150000, 'JPY', ['Name' => 'ICHIRAN', 'Amount' => '-7.89', 'Local amount' => '-1500', 'Local currency' => 'JPY']);
        $taxi = $line('KUWAIT TAXI', -3200, -1234, 'KWD', ['Name' => 'KUWAIT TAXI', 'Amount' => '-32.00', 'Local amount' => '-12.34', 'Local currency' => 'KWD']);
        // Already in the currency's own units, or in a two-decimal currency: left alone.
        $euro = $line('LE CAFE', -3652, -4200, 'EUR', ['Name' => 'LE CAFE', 'Amount' => '-36.52', 'Local amount' => '-42.00', 'Local currency' => 'EUR']);
        $newer = $line('LAWSON', -1000, -1900, 'JPY', ['Name' => 'LAWSON', 'Amount' => '-10.00', 'Local amount' => '-1900', 'Local currency' => 'JPY']);
        $revolut = $line('TOKYO HANDS', -1000, -1900, 'JPY', ['Type' => 'CARD_PAYMENT', 'Amount' => '-1900', 'Currency' => 'JPY', 'Balance' => '17500'], $this->bank);

        $migration = require database_path('migrations/2026_12_01_000800_frontend_original_amounts_in_own_units.php');
        $migration->up();

        $this->assertSame([-1500, -12340, -4200, -1900, -1900], [$ramen->fresh()->original_amount_minor, $taxi->fresh()->original_amount_minor,
            $euro->fresh()->original_amount_minor, $newer->fresh()->original_amount_minor, $revolut->fresh()->original_amount_minor]);
        $this->assertSame(-789, $ramen->fresh()->amount_pence);
        $event = AuditEvent::query()->where('action', 'ledger.original_amounts_in_own_units')->sole();
        $this->assertSame(['original_amount_minor' => -150000, 'original_currency' => 'JPY'], $event->before[$ramen->id]);
        $this->assertSame(['original_amount_minor' => -1500, 'original_currency' => 'JPY'], $event->after[$ramen->id]);
        $this->assertCount(2, $event->after);

        // Running it again changes nothing.
        $migration->up();
        $this->assertSame(-1500, $ramen->fresh()->original_amount_minor);
        $this->assertSame(1, AuditEvent::query()->where('action', 'ledger.original_amounts_in_own_units')->count());
    }

    public function test_statement_balances_typed_in_for_a_yen_pocket_are_put_in_whole_yen(): void
    {
        $yen = Account::query()->create(['name' => 'Revolut JPY', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'JPY', 'parent_account_id' => $this->bank->id]);
        $euro = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $this->bank->id]);
        $typed = StatementBalance::query()->create(['account_id' => $yen->id, 'as_of' => '2026-03-31', 'balance_pence' => 1750000]);
        $eur = StatementBalance::query()->create(['account_id' => $euro->id, 'as_of' => '2026-03-31', 'balance_pence' => 120000]);
        $gbp = StatementBalance::query()->create(['account_id' => $this->bank->id, 'as_of' => '2026-03-31', 'balance_pence' => 250000]);

        (require database_path('migrations/2026_12_01_000810_frontend_pocket_statement_balances_in_own_units.php'))->up();

        $this->assertSame([17500, 120000, 250000], [$typed->fresh()->balance_pence, $eur->fresh()->balance_pence, $gbp->fresh()->balance_pence]);
        $event = AuditEvent::query()->where('action', 'account.balances_in_own_units')->sole();
        $this->assertSame(1750000, $event->before[$typed->id]['balance']);
        $this->assertSame(17500, $event->after[$typed->id]['balance']);
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<list<mixed>> */
    private function sheet(string $path, string $name): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== $name) {
                continue;
            }
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        return $rows;
    }

    /** Run a script against resources/js/lib/format.ts in node (which strips the types itself). */
    private function node(string $script): mixed
    {
        $probe = new Process(['node', '-e', 'process.exit(Number(process.versions.node.split(".")[0]) >= 22 ? 0 : 1)']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Node 22 or later is needed to run the page code.');
        }
        $file = tempnam(sys_get_temp_dir(), 'fmt').'.mjs';
        file_put_contents($file, $script);
        $run = new Process(['node', '--no-warnings', $file, 'file://'.resource_path('js/lib/format.ts')], null, ['TZ' => 'Europe/London']);
        $run->run();
        @unlink($file);
        $this->assertTrue($run->isSuccessful(), $run->getErrorOutput());

        return json_decode(trim($run->getOutput()), true);
    }
}
