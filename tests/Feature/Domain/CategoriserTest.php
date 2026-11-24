<?php

namespace Tests\Feature\Domain;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\Suggester;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every line gets a suggestion where one can be made, and each choice you make teaches the next. */
class CategoriserTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        $this->bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
    }

    private function tx(string $merchant, int $pence = -2500, string $date = '2025-06-10', ?string $bankCategory = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $this->bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $merchant, 'counterparty' => $merchant, 'merchant_key' => MerchantKey::from($merchant, $merchant), 'amount_pence' => $pence, 'bank_category' => $bankCategory,
            'raw' => [], 'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1]);
    }

    private function cat(string $code): int
    {
        return Category::query()->where('code', $code)->value('id');
    }

    private function you(BankTransaction $tx, string $kind, ?string $code = null): void
    {
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines($tx->amount_pence, $kind, $code ? $this->cat($code) : null));
    }

    private function suggested(BankTransaction $tx): ?array
    {
        $a = $tx->fresh()->allocations()->first();

        return $a ? ['kind' => $a->kind, 'category' => $a->category_id ? Category::query()->find($a->category_id)->code : null, 'by' => $a->decided_by, 'confirmed' => (bool) $a->confirmed_at, 'reason' => $a->reason] : null;
    }

    private function suggest(BankTransaction ...$txs): void
    {
        app(Suggester::class)->suggestFor(collect($txs));
    }

    public function test_what_you_chose_before_for_a_merchant_is_suggested_next_time(): void
    {
        $this->you($this->tx('LX STAGE HIRE', -12000, '2025-05-01'), 'business_expense', 'equipment');
        $next = $this->tx('LX STAGE HIRE', -9000, '2025-06-01');
        $this->suggest($next);

        $s = $this->suggested($next);
        $this->assertSame(['business_expense', 'equipment', 'matcher', false], [$s['kind'], $s['category'], $s['by'], $s['confirmed']]);
        $this->assertStringStartsWith('Like the 1 time you decided LX STAGE HIRE', $s['reason']);
    }

    public function test_similar_merchants_and_the_banks_category_are_learned(): void
    {
        $this->you($this->tx('BLACKBURN CABLES LTD'), 'business_expense', 'materials');
        $this->you($this->tx('BLACKBURN CABLES ONLINE'), 'business_expense', 'materials');
        $this->you($this->tx('BLACKBURN CABLES DIRECT'), 'business_expense', 'materials');
        $similar = $this->tx('BLACKBURN CABLE WAREHOUSE');
        foreach (['ZEBRA PRINTS', 'NORTH MAPS', 'KITE PAPER'] as $m) {
            $this->you($this->tx($m, -1000, '2025-05-01', 'SHOPPING'), 'business_expense', 'stationery');
        }
        $shopping = $this->tx('UNHEARD OF GOODS', -1000, '2025-06-01', 'SHOPPING');
        $this->suggest($similar, $shopping);

        $this->assertSame('materials', $this->suggested($similar)['category']);
        $this->assertStringContainsString('other BLACKBURN payments you decided (3 of 3)', $this->suggested($similar)['reason']);
        $this->assertSame('stationery', $this->suggested($shopping)['category']);
        $this->assertStringContainsString('bank’s “Shopping” payments', $this->suggested($shopping)['reason']);
    }

    public function test_common_merchants_are_suggested_and_judged_against_your_job_days(): void
    {
        $adobe = $this->tx('ADOBE CREATIVE CLD');
        $netflix = $this->tx('NETFLIX.COM');
        $lunchAtHome = $this->tx('PRET A MANGER', -650, '2025-06-10');
        $unknown = $this->tx('QWERTY HOLDINGS');
        // Leeds to Glasgow: far enough to be away overnight.
        Setting::put('home_postcode', 'LS1 4AP');
        DB::table('places')->insert([['postcode' => 'LS1 4AP', 'latitude' => 53.7965, 'longitude' => -1.5478, 'created_at' => now(), 'updated_at' => now()],
            ['postcode' => 'G1 1AA', 'latitude' => 55.8609, 'longitude' => -4.2514, 'created_at' => now(), 'updated_at' => now()]]);
        $job = WorkJob::query()->create(['title' => 'Glasgow arena', 'starts_on' => '2025-07-01', 'ends_on' => '2025-07-03', 'postcode' => 'G1 1AA', 'status' => 'done']);
        $lunchAway = $this->tx('PRET A MANGER', -650, '2025-07-02');
        $hotel = $this->tx('PREMIER INN GLASGOW', -9500, '2025-07-01');
        $this->suggest($adobe, $netflix, $lunchAtHome, $unknown, $lunchAway, $hotel);

        $this->assertSame(['business_expense', 'software'], [$this->suggested($adobe)['kind'], $this->suggested($adobe)['category']]);
        $this->assertSame('personal', $this->suggested($netflix)['kind']);
        $this->assertSame('personal', $this->suggested($lunchAtHome)['kind'], 'A meal with no job that day isn’t allowable');
        $this->assertNull($this->suggested($unknown), 'Nothing to go on: left for you (or the AI)');
        $this->assertSame(['business_expense', 'travel_subsistence'], [$this->suggested($lunchAway)['kind'], $this->suggested($lunchAway)['category']], 'Away overnight on a job');
        $this->assertSame($job->id, $lunchAway->fresh()->allocations()->value('work_job_id'));
        $this->assertSame('travel_accommodation', $this->suggested($hotel)['category']);
    }

    public function test_your_own_money_moving_is_left_to_the_transfer_check(): void
    {
        // Never guessed from the list, similar merchants or the bank's category: left to the transfer check.
        $this->assertNull(app(Categoriser::class)->propose($this->tx('ALEX SMITH', -5000, '2025-04-01')));

        // Changed on purpose (N20): your own decisions for the payee come first, as the transfer check no longer
        // guesses "transfer" for a payee you decide otherwise, so it isn't left with no suggestion at all.
        $this->you($this->tx('ALEX SMITH', -5000, '2025-05-01'), 'personal');
        $p = app(Categoriser::class)->propose($this->tx('ALEX SMITH', -5000, '2025-06-01'));
        $this->assertSame('personal', $p['lines'][0]['kind']);
        $this->assertStringStartsWith('Like the 1 time you decided ALEX SMITH', $p['reason']);
    }

    public function test_each_choice_updates_the_payments_waiting_from_that_merchant(): void
    {
        $this->actingAs(User::factory()->create());
        $waiting = [$this->tx('GRIP AND GAFFER', -1500, '2025-06-02'), $this->tx('GRIP AND GAFFER', -2000, '2025-06-09'), $this->tx('GRIP AND GAFFER', -1000, '2025-06-16'), $this->tx('GRIP AND GAFFER', -1200, '2025-06-23')];
        $first = $this->tx('GRIP AND GAFFER', -3000, '2025-06-01');

        $this->post("/items/bank/{$first->id}/decide", ['kind' => 'business_expense', 'category_id' => $this->cat('materials'), 'business_pct' => 100])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Updated the suggestion for 4 other waiting payments'));
        foreach ($waiting as $w) {
            $this->assertSame(['business_expense', 'materials', 'matcher'], [$this->suggested($w)['kind'], $this->suggested($w)['category'], $this->suggested($w)['by']]);
        }

        // You accept one (that's your choice too) and change your mind on another: the rest follow your latest
        // choices (they were only suggestions), and confirmed ones never change.
        $this->post("/items/bank/{$waiting[0]->id}/confirm");
        $this->post("/items/bank/{$waiting[1]->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);
        $this->assertSame('business_expense', $this->suggested($waiting[2])['kind'], 'Two of your three choices are still business');
        $this->assertStringStartsWith('Like 2 of the last 3 times you decided GRIP AND GAFFER', $this->suggested($waiting[2])['reason']);

        $this->post("/items/bank/{$waiting[2]->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);
        $this->assertNull($this->suggested($waiting[3]), 'Your choices are split two and two: no guess, back to undecided');
        $this->assertSame('unreviewed', $waiting[3]->fresh()->review_status);

        $this->post("/items/bank/{$waiting[3]->id}/decide", ['kind' => 'personal', 'business_pct' => 100]);
        $this->assertSame('business_expense', $this->suggested($waiting[0])['kind']);
        $this->assertTrue($this->suggested($waiting[0])['confirmed']);

        $later = $this->tx('GRIP AND GAFFER', -800, '2025-07-01');
        $this->suggest($later);
        $this->assertSame('personal', $this->suggested($later)['kind'], 'Your latest choices win');
        $this->assertStringStartsWith('Like 3 of the last 5 times you decided GRIP AND GAFFER', $this->suggested($later)['reason']);
    }
}
