<?php

namespace Tests\Feature\Domain;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\User;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Money moving between your own accounts is never spending or income, and is kept out of every total. */
class TransfersTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private Account $revolut;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        OwnAccounts::flush();
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
        $this->revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
    }

    private function tx(Account $account, string $date, int $pence, string $description, ?string $counterparty = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => strtoupper($counterparty ?: strtok($description, ' ')), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', $account->id.$date.$pence.$description.$counterparty), 'occurrence' => 1,
        ]);
    }

    private function kind(BankTransaction $tx): ?string
    {
        return $tx->fresh()->allocations()->value('kind');
    }

    public function test_money_to_and_from_your_own_name_is_paired_as_a_transfer(): void
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-03', 50000, 'Payment from Alex Smith', 'ALEX SMITH');

        $this->assertSame(1, app(TransferPairer::class)->pairAll());
        $this->assertSame([$in->id, $out->id], [$out->fresh()->transfer_pair_id, $in->fresh()->transfer_pair_id]);
        $this->assertSame(['transfer', 'transfer'], [$this->kind($out), $this->kind($in)]);
        $this->assertNull($out->fresh()->allocations()->value('confirmed_at'), 'Suggested, not confirmed');
    }

    public function test_initials_and_titles_count_but_a_relative_with_your_surname_does_not(): void
    {
        foreach (['A SMITH', 'MR A J SMITH', 'SMITH ALEX', 'Alex Smith'] as $name) {
            $this->assertTrue(OwnAccounts::isYou($name), $name);
        }
        foreach (['JO SMITH', 'J SMITH', 'SMITH', 'SMITH PLUMBING LTD', 'ALEX SMITHSON', null] as $name) {
            $this->assertFalse(OwnAccounts::isYou($name), (string) $name);
        }

        $relative = $this->tx($this->starling, '2025-06-02', -20000, 'Birthday', 'JO SMITH');
        $this->tx($this->revolut, '2025-06-02', 20000, 'Payment', 'JO SMITH');
        app(TransferPairer::class)->pairAll();
        app(TransferPairer::class)->suggestOneSided();
        $this->assertNull($relative->fresh()->transfer_pair_id);
        $this->assertNull($this->kind($relative), 'Paying a relative is spending, not a transfer');
    }

    public function test_one_sided_moves_are_suggested_as_transfers(): void
    {
        $toSelf = $this->tx($this->starling, '2025-07-01', -30000, 'To savings', 'MR A J SMITH');
        $topUp = $this->tx($this->starling, '2025-07-02', -10000, 'REVOLUT*TOP-UP', null);
        $space = $this->tx($this->starling, '2025-07-03', -2500, 'Transfer to savings space Holiday', null);
        $named = $this->tx($this->starling, '2025-07-04', -4000, 'Card 4821 load', 'REVOLUT LTD');
        $shop = $this->tx($this->starling, '2025-07-05', -4000, 'SCREWFIX', 'SCREWFIX');

        app(TransferPairer::class)->suggestOneSided();

        foreach ([$toSelf, $topUp, $space, $named] as $tx) {
            $this->assertSame('transfer', $this->kind($tx), $tx->description);
            $this->assertSame('matcher', $tx->fresh()->allocations()->value('decided_by'));
        }
        $this->assertNull($this->kind($shop));
    }

    public function test_other_accounts_you_list_count_as_yours(): void
    {
        $tx = $this->tx($this->starling, '2025-07-01', -30000, 'NATIONWIDE SAVINGS 1234', null);
        app(TransferPairer::class)->suggestOneSided();
        $this->assertNull($this->kind($tx));

        Setting::put('own_other_accounts', ['Nationwide savings']);
        app(TransferPairer::class)->suggestOneSided();
        $this->assertSame('transfer', $this->kind($tx));
    }

    public function test_a_client_payment_of_the_same_amount_is_not_paired(): void
    {
        $client = $this->tx($this->starling, '2025-08-01', 50000, 'INV-0042', 'ACME EVENTS LTD');
        $spend = $this->tx($this->revolut, '2025-08-01', -50000, 'Lighting hire', 'PRO HIRE LTD');

        $this->assertSame(0, app(TransferPairer::class)->pairAll());
        $this->assertNull($client->fresh()->transfer_pair_id);
        $this->assertNull($spend->fresh()->transfer_pair_id);
    }

    public function test_your_rules_win_over_a_transfer_guess(): void
    {
        MerchantRule::query()->create(['merchant_key' => 'A SMITH', 'kind' => 'personal', 'business_pct' => 100]);
        $tx = $this->tx($this->starling, '2025-07-01', -30000, 'Pocket money', 'A SMITH');
        app(TransferPairer::class)->suggestOneSided();
        $this->assertNull($this->kind($tx), 'Left for your rule (the suggester applies it)');
    }

    public function test_deciding_one_side_settles_the_other(): void
    {
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-02', 50000, 'Payment', 'ALEX SMITH');
        app(TransferPairer::class)->pairAll();

        app(AllocationWriter::class)->confirm($out->fresh());
        $this->assertNotNull($in->fresh()->allocations()->value('confirmed_at'), 'Confirming one side confirms the other');

        // A different pair: you say one side isn't a transfer, so the pair is broken and the other side waits for you.
        $out2 = $this->tx($this->starling, '2025-09-02', -7000, 'Rent', 'A SMITH');
        $in2 = $this->tx($this->revolut, '2025-09-02', 7000, 'Payment', 'ALEX SMITH');
        app(TransferPairer::class)->pairAll();
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$out2->fresh()]));
        $this->assertNull($out2->fresh()->transfer_pair_id);
        $this->assertNull($in2->fresh()->transfer_pair_id);
        $this->assertNull($this->kind($in2));
        $this->assertSame('unreviewed', $in2->fresh()->review_status);
    }

    public function test_transfers_are_never_in_spending_totals_or_tax_figures(): void
    {
        $this->actingAs(User::factory()->create());
        $tools = Category::query()->where('code', 'equipment')->value('id') ?? Category::query()->where('is_allowable', true)->value('id');
        $spend = $this->tx($this->starling, '2025-06-01', -12000, 'SCREWFIX', 'SCREWFIX');
        app(AllocationWriter::class)->decide($spend, AllocationWriter::lines(-12000, 'business_expense', $tools));
        $out = $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $in = $this->tx($this->revolut, '2025-06-02', 50000, 'Payment', 'ALEX SMITH');
        app(TransferPairer::class)->pairAll();
        app(AllocationWriter::class)->confirm($out->fresh());

        $this->get('/transactions?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p
            ->where('totals.count', 3)
            ->where('totals.out_pence', -12000)
            ->where('totals.in_pence', 0)
            ->where('totals.transfer_count', 2)
            ->where('totals.transfer_out_pence', -50000)
            ->where('totals.transfer_in_pence', 50000));

        $summary = ReturnSummary::for('2025-26');
        $this->assertSame(0, $summary['turnover_pence']);
        $this->assertSame(12000, $summary['allowable_expenses_pence'] + $summary['disallowable_expenses_pence']);
    }

    public function test_the_to_do_list_confirms_suggested_transfers_in_one_tap(): void
    {
        $this->tx($this->starling, '2025-06-02', -50000, 'Savings', 'A SMITH');
        $this->tx($this->revolut, '2025-06-02', 50000, 'Payment', 'ALEX SMITH');
        $this->tx($this->starling, '2025-07-02', -10000, 'REVOLUT*TOP-UP');
        app(TransferPairer::class)->pairAll();
        app(TransferPairer::class)->suggestOneSided();

        $todo = TodoList::build();
        $item = collect($todo['items'])->firstWhere('group', 'transfers');
        $this->assertNotNull($item);
        $this->assertStringContainsString('Confirm 3 transfers', $item['title']);
        $this->assertSame(0, collect($todo['items'])->where('group', 'payments')->count(), 'Not listed twice');

        $message = app(TodoActions::class)->run('transfers_ok', '0');
        $this->assertStringContainsString('Confirmed 3', $message);
        $this->assertSame(0, BankTransaction::query()->where('review_status', 'suggested')->count());
        $this->assertSame(3, BankTransaction::query()->whereHas('allocations', fn ($a) => $a->where('kind', 'transfer')->where('decided_by', 'matcher')->whereNotNull('confirmed_at'))->count());
    }

    public function test_saving_your_names_finds_the_transfers(): void
    {
        Setting::put('own_names', []);
        $this->actingAs($user = User::factory()->create(['name' => 'Someone Else']));
        $tx = $this->tx($this->starling, '2025-07-01', -30000, 'To me', 'A SMITH');
        $this->put('/settings/ledger', [
            'home_postcode' => '', 'overnight_near_miles' => 12, 'overnight_far_miles' => 40, 'early_start' => '08:00', 'late_finish' => '22:00',
            'mileage_threshold_scope' => 'all_vehicles', 'scottish_taxpayer' => false, 'own_names' => "Alex Smith\nA J Smith", 'own_other_accounts' => '',
        ])->assertSessionHas('success');
        $this->assertSame(['Alex Smith', 'A J Smith'], Setting::get('own_names'));
        $this->assertSame('transfer', $this->kind($tx));
    }
}
