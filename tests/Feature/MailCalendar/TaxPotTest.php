<?php

namespace Tests\Feature\MailCalendar;

use App\Domain\Banks\StarlingSync;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\BillPreview;
use App\Domain\Tax\TaxEstimator;
use App\Domain\Tax\TaxPot;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Mail and calendar batch 1: the tax pot, phase 1. An estimate of what to put aside, and what's due by 31 January. */
class TaxPotTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array> messages sent to Telegram */
    private array $telegram = [];

    /** What Starling's Spaces call answers: a status and body. */
    private array $spaces = [200, ['savingsGoals' => [['savingsGoalUid' => 'goal-1', 'name' => 'Tax', 'totalSaved' => ['currency' => 'GBP', 'minorUnits' => 310000]]],
        'spendingSpaces' => [['spaceUid' => 'space-1', 'name' => 'Bills', 'balance' => ['currency' => 'GBP', 'minorUnits' => 12000]]]]];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-24 10:00:00'));
    }

    /**
     * A TaxPot reading these figures as each year's return summary (turnover and profit, in pence), and these as each
     * year's suggested money in, suggested costs and money in still to decide (none unless given).
     */
    private function pot(array $years, array $unconfirmed = []): TaxPot
    {
        return new TaxPot(fn (string $label) => ['turnover_pence' => $years[$label][0] ?? 0, 'net_profit_pence' => $years[$label][1] ?? 0],
            fn (string $label) => ['income_pence' => $unconfirmed[$label][0] ?? 0, 'costs_pence' => $unconfirmed[$label][1] ?? 0, 'undecided_in_pence' => $unconfirmed[$label][2] ?? 0]);
    }

    /** Income tax and NI for a year's profit, as TaxEstimator works it out. */
    private function tax(string $label, int $profit): int
    {
        return TaxEstimator::estimate($label, $profit)['total_pence'];
    }

    public function test_put_aside_is_the_years_tax_over_its_profit_times_the_profit_share(): void
    {
        // This year so far scales to less than last year's £36,000: last year's is used, with last year's profit share.
        $pot = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [2_000_000, 1_800_000]]);
        $rate = $pot->rate();
        $this->assertSame(3_600_000, $rate['profit_pence']);
        $this->assertSame('last year’s profit', $rate['basis']);
        $this->assertFalse($rate['fallback']);
        // £6,091.80 of income tax and Class 4 on £36,000: about 16.9%, not the 26% of the next pound.
        $this->assertSame(609_180, $rate['tax_pence']);
        $this->assertEqualsWithDelta(609_180 / 3_600_000, $rate['rate'], 0.00001);
        $this->assertSame(0.9, $rate['margin'], 'Profit is 90% of last year’s income');
        // £1,380 × 90% × 16.92% = £210.17: about £210.
        $this->assertSame(21_000, $pot->forPayment(138_000)['pence']);
        $this->assertSame(1_500, $pot->forPayment(10_000)['pence'], 'Small amounts are rounded to the pound: £100 × 90% × 16.92% = £15.23');

        // A busier year: £38,000 profit in 7½ months scales to about £59,500, more than last year's: this year's share.
        $busy = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [4_000_000, 3_800_000]])->rate();
        $this->assertSame('this year so far, scaled to a full year', $busy['basis']);
        $this->assertSame(0.95, $busy['margin']);
        $this->assertEqualsWithDelta($this->tax('2026-27', $busy['profit_pence']) / $busy['profit_pence'], $busy['rate'], 0.00001);
    }

    public function test_a_steady_years_payments_put_aside_about_the_years_tax(): void
    {
        foreach ([[4_000_000, 3_600_000], [6_500_000, 6_000_000], [12_000_000, 11_000_000]] as [$turnover, $profit]) {
            // Seven and a half months of the same year so far, and twelve equal payments adding up to its turnover.
            $pot = $this->pot(['2025-26' => [$turnover, $profit], '2026-27' => [(int) round($turnover * 233 / 365), (int) round($profit * 233 / 365)]]);
            $aside = 0;
            for ($i = 0; $i < 12; $i++) {
                $aside += $pot->forPayment(intdiv($turnover, 12))['pence'];
            }
            $tax = $this->tax('2026-27', $profit);
            $this->assertEqualsWithDelta(1.0, $aside / $tax, 0.03, sprintf('Profit £%s: put aside £%s against £%s of tax', $profit / 100, $aside / 100, $tax / 100));
        }
    }

    public function test_the_profit_share_comes_from_the_same_year_as_the_profit(): void
    {
        // This year's records are behind on income (£10,000 in, £9,000 of costs confirmed): its projection is low, so
        // last year's profit is used, and so is last year's 90% share, not this year's 10%.
        $pot = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [1_000_000, 100_000]]);
        $this->assertSame('last year’s profit', $pot->rate()['basis']);
        $this->assertSame(0.9, $pot->rate()['margin']);
        $this->assertSame(21_000, $pot->forPayment(138_000)['pence']);
    }

    public function test_suggested_money_in_counts_and_no_profit_falls_back_to_the_basic_rate_and_class_4(): void
    {
        // Under the personal allowance there's nothing to put aside.
        $low = $this->pot(['2025-26' => [1_000_000, 800_000]]);
        $this->assertSame(0.0, $low->rate()['rate']);
        $this->assertSame(0, $low->forPayment(100_000)['pence']);

        // Nothing recorded: the basic rate plus Class 4's main rate on all of it, and it says so.
        $none = $this->pot([])->rate();
        $this->assertEqualsWithDelta(0.26, $none['rate'], 0.0001);
        $this->assertSame(1.0, $none['margin']);
        $this->assertTrue($none['fallback']);
        $this->assertSame('no profit recorded yet', $none['basis']);

        // Last year's £40,000 in is all suggested (matched to invoices), not confirmed, with £4,000 of costs confirmed.
        $behind = $this->pot(['2025-26' => [0, -400_000]], ['2025-26' => [4_000_000, 0, 0]])->rate();
        $this->assertSame(3_600_000, $behind['profit_pence']);
        $this->assertSame(0.9, $behind['margin']);

        Setting::put('scottish_taxpayer', true);
        $this->assertNull($this->pot(['2025-26' => [4_000_000, 3_600_000]])->rate());
        $this->assertNull($this->pot(['2025-26' => [4_000_000, 3_600_000]])->need());
    }

    public function test_the_need_is_the_balance_plus_the_first_payment_on_account_by_the_next_31_january(): void
    {
        $pot = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [2_000_000, 1_800_000]]);
        // 2025-26 on £36,000: income tax £4,686 + Class 4 £1,405.80 = £6,091.80; plus half of it on account.
        $need = $pot->need();
        $this->assertSame(['2027-01-31', '2025-26', '2026-27', 'records'], [$need['due_on'], $need['year'], $need['next_year'], $need['basis']]);
        $this->assertSame(609_180, $need['balancing_pence']);
        $this->assertSame(304_590, $need['payment_on_account_pence']);
        $this->assertSame(913_770, $need['pence']);
        $this->assertNull($need['july'], 'No 31 July payment comes first between August and January');
        $this->assertSame($need['pence'], $need['january']['pence']);
        $this->assertFalse($need['at_least']);

        // Payments on account already made for 2025-26 come off the balance.
        TaxYear::track('2025-26')->update(['payments_on_account_paid_pence' => 100_000]);
        $this->assertSame(813_770, $this->pot(['2025-26' => [4_000_000, 3_600_000]])->need()['pence']);
        TaxYear::query()->where('label', '2025-26')->update(['payments_on_account_paid_pence' => 0]);

        // On 31 January it's still that day.
        $this->assertSame('2027-01-31', $pot->need(CarbonImmutable::parse('2027-01-31'))['due_on']);
    }

    public function test_from_february_the_31_july_payment_comes_first_and_an_unfinished_year_is_projected(): void
    {
        // A steady £36,000 year: on 15 February 2027, 316 days into 2026-27, the records show 316/365 of it.
        $pot = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [(int) round(4_000_000 * 316 / 365), (int) round(3_600_000 * 316 / 365)]]);
        $need = $pot->need(CarbonImmutable::parse('2027-02-15'));

        // The second payment on account for 2026-27 (half of 2025-26's bill) is due first, on 31 July.
        $this->assertSame(['2027-07-31', 304_590, '2nd payment on account for 2026-27'], [$need['due_on'], $need['pence'], $need['label']]);
        $this->assertSame($need['july'], ['due_on' => '2027-07-31', 'pence' => 304_590, 'label' => '2nd payment on account for 2026-27']);
        // Then 31 January 2028: 2026-27 hasn't ended, so it's on the year's expected profit (about £36,000), less what
        // 31 July pays towards it, plus the first payment on account for 2027-28.
        $this->assertSame(['2028-01-31', '2026-27', 'expected'], [$need['january']['due_on'], $need['year'], $need['basis']]);
        $this->assertEqualsWithDelta(3_600_000, $need['profit_pence'], 200);
        $full = BillPreview::for('2026-27', TaxEstimator::estimate('2026-27', 3_600_000))['due'][0]['pence'];
        $this->assertEqualsWithDelta($full - 304_590, $need['january']['pence'], 100);

        // Both payments on account for 2026-27 recorded as paid: nothing more by 31 July.
        TaxYear::track('2026-27')->update(['payments_on_account_paid_pence' => 609_180]);
        $paid = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [4_000_000, 3_600_000]])->need(CarbonImmutable::parse('2027-02-15'));
        $this->assertNull($paid['july']);
        $this->assertSame('2028-01-31', $paid['due_on']);
        TaxYear::query()->where('label', '2026-27')->update(['payments_on_account_paid_pence' => 0]);

        // 15 May 2027: 2026-27 has ended and is read from its records; 31 July still comes first.
        $may = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [4_000_000, 3_600_000]])->need(CarbonImmutable::parse('2027-05-15'));
        $this->assertSame(['2027-07-31', 304_590, 'records'], [$may['due_on'], $may['pence'], $may['basis']]);
        $this->assertSame($full - 304_590, $may['january']['pence']);

        // From 1 August, only 31 January.
        $august = $this->pot(['2025-26' => [4_000_000, 3_600_000], '2026-27' => [4_000_000, 3_600_000]])->need(CarbonImmutable::parse('2027-08-01'));
        $this->assertNull($august['july']);
        $this->assertSame('2028-01-31', $august['due_on']);
    }

    public function test_money_in_still_to_confirm_is_counted_or_said_on_the_dashboard(): void
    {
        $this->seed();
        TaxYear::track('2025-26');
        TaxYear::track('2026-27');
        // You're behind: 2025-26's £40,000 of invoice payments are matched (suggested) but not confirmed; £4,000 of
        // costs are confirmed by your rules.
        for ($m = 0; $m < 10; $m++) {
            $this->line(CarbonImmutable::parse('2025-05-10')->addMonths($m)->format('Y-m-d'), 400_000, 'business_income', 'turnover', false);
            $this->line(CarbonImmutable::parse('2025-05-12')->addMonths($m)->format('Y-m-d'), -40_000, 'business_expense', 'travel', true);
        }
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));

        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('taxPot.need.due_on', '2027-01-31')->where('taxPot.need.pence', 913_770)->where('taxPot.need.suggested_pence', 4_000_000)
            ->where('taxPot.need.at_least', false)->where('taxPot.basis', 'last year’s profit')->where('taxPot.basis_profit_pence', 3_600_000));

        // £2,000 more money in for 2025-26 is still to decide: the figure is a least, and says so.
        $this->line('2026-03-02', 200_000, null, null, false, 'unreviewed');
        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('taxPot.need.at_least', true)->where('taxPot.need.undecided_pence', 200_000)->where('taxPot.need.pence', 913_770));
    }

    private int $n = 0;

    /** A Starling line, decided as $kind by the matcher ($confirm: confirmed), or left to decide ($kind null). */
    private function line(string $date, int $pence, ?string $kind, ?string $code, bool $confirm, string $status = 'suggested'): void
    {
        $account = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 'statement.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date,
            'tax_year' => UkTaxYear::fromDate($date)->label(), 'description' => 'Line '.++$this->n, 'merchant_key' => 'LINE'.$this->n, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => md5((string) $this->n), 'occurrence' => 1, 'review_status' => $kind ? $status : 'unreviewed']);
        if ($kind) {
            app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($pence, $kind, Category::query()->where('code', $code)->value('id')), 'matcher', $confirm);
        }
    }

    private function connectStarling(): Account
    {
        $account = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        Setting::put('starling_token', 'good-token-1234567890abcdef');
        Setting::put('starling_accounts', ['uid-1' => ['account_id' => $account->id, 'name' => 'Business', 'currency' => 'GBP', 'synced_to' => null]]);
        $csv = file_get_contents(base_path('tests/fixtures/starling.csv'));
        Http::fake(function (HttpRequest $r) use ($csv) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (str_contains($path, '/statement/downloadForDateRange')) {
                return Http::response($csv, 200, ['Content-Type' => 'text/csv']);
            }
            if (str_ends_with($path, '/api/v2/account/uid-1/spaces')) {
                return Http::response($this->spaces[1], $this->spaces[0]);
            }
            if (str_contains($r->url(), 'api.telegram.org')) {
                $this->telegram[] = $r->data();

                return Http::response(['ok' => true]);
            }

            return Http::response('', 404);
        });

        return $account;
    }

    public function test_a_starling_import_with_business_income_says_what_to_put_aside_and_the_pot_against_the_need(): void
    {
        $this->connectStarling();
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put(TaxPot::SPACE, ['account_uid' => 'uid-1', 'kind' => 'goal', 'uid' => 'goal-1', 'name' => 'Tax']);
        // The client's £1,200 payment in the statement matches this invoice, so it's suggested as business income.
        $client = Client::query()->create(['name' => 'Acme Events']);
        Invoice::query()->create(['client_id' => $client->id, 'number' => 'INV-1042', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 120_000,
            'issue_date' => '2025-04-01', 'due_date' => '2025-05-01', 'bill_to_name' => 'Acme Events Ltd']);

        // The year before the payment (2023-24) made £36,000 profit on £40,000; 2025-26, whose bill is due next, the same.
        $this->app->instance(TaxPot::class, $this->pot(['2023-24' => [4_000_000, 3_600_000], '2025-26' => [4_000_000, 3_600_000]]));

        app(StarlingSync::class)->sync();

        $this->assertSame('business_income', Allocation::query()->where('invoice_id', Invoice::query()->value('id'))->value('kind'));
        $this->assertCount(1, $this->telegram);
        // £1,200 × 90% × 16.92% (2024-25's tax on £36,000) = £182.75: about £180.
        $this->assertSame("Acme Events paid £1,200: put about £180 aside (estimate).\nTax pot £3,100 of about £9,138 needed by 31 Jan 2027.", $this->telegram[0]['text']);
        $this->assertDoesNotMatchRegularExpression('/\d{5,}|INV 1042|good-token/', $this->telegram[0]['text'], 'No account numbers, references or tokens');
        $this->assertSame(['ok' => true, 'pence' => 310_000, 'currency' => 'GBP', 'name' => 'Tax'], array_intersect_key(Setting::get(TaxPot::BALANCE), array_flip(['ok', 'pence', 'currency', 'name'])));

        // The same days again bring nothing new: no message.
        app(StarlingSync::class)->sync();
        $this->assertCount(1, $this->telegram);
    }

    public function test_a_token_without_the_spaces_permission_carries_on_without_the_pot(): void
    {
        $this->connectStarling();
        $this->spaces = [403, ['error' => 'insufficient_scope']];
        Setting::put(TaxPot::SPACE, ['account_uid' => 'uid-1', 'kind' => 'goal', 'uid' => 'goal-1', 'name' => 'Tax']);

        $results = app(StarlingSync::class)->sync();

        $this->assertTrue($results[0]['ok'], 'The import itself went ahead');
        $this->assertSame(['ok' => false, 'error' => 'scope'], array_intersect_key(Setting::get(TaxPot::BALANCE), array_flip(['ok', 'error'])));
        $this->assertSame('scope', TaxPot::pot()['status']);

        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->get('/settings/integrations?spaces=1')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('starling.tax_pot.error', 'scope')->where('starling.tax_pot.scopes', 'space:read and balance:read'));
        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('taxPot.pot_status', 'scope')->where('taxPot.pot', null)->where('taxPot.rate_pct', 26));
    }

    public function test_choosing_the_space_in_settings_reads_its_balance_and_is_audited(): void
    {
        $this->connectStarling();
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));

        $this->get('/settings/integrations')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('starling.tax_pot.spaces', null));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/spaces'));
        $this->get('/settings/integrations?spaces=1')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('starling.tax_pot.spaces', 2)->where('starling.tax_pot.spaces.0.key', 'uid-1|goal|goal-1'));

        $this->put('/settings/starling/tax-pot', ['tax_pot' => 'uid-1|goal|goal-1'])->assertSessionHas('success', fn ($m) => str_contains($m, 'never moves money'));
        $this->assertSame('goal-1', Setting::get(TaxPot::SPACE)['uid']);
        $this->assertSame(310_000, Setting::get(TaxPot::BALANCE)['pence']);
        $event = AuditEvent::query()->where('action', 'settings.tax_pot')->sole();
        $this->assertSame('you', $event->actor);

        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('taxPot.pot.pence', 310_000)->where('taxPot.pot_status', 'ok')
            ->where('taxPot.need.due_on', '2027-01-31'));

        $this->put('/settings/starling/tax-pot', ['tax_pot' => 'uid-1|goal|gone'])->assertSessionHas('error');
        $this->put('/settings/starling/tax-pot', ['tax_pot' => ''])->assertSessionHas('success');
        $this->assertNull(Setting::get(TaxPot::SPACE));
        $this->assertSame('none', TaxPot::pot()['status']);
    }
}
