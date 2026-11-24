<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * When a kind is demoted by its false alarms: never on a handful of findings, and a kind shown in full before M4 (M1's
 * three and charges in twice) only by section 2.3's rule (at least ten dismissals, more than half saying it was wrong).
 */
class DemotionRulesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function charge(string $on, int $pence, string $name): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n),
            'occurrence' => 1]);
    }

    /** Findings of one kind, as the findings run would have kept them. @return list<Finding> */
    private function findings(string $code, int $n): array
    {
        return array_map(fn ($i) => Finding::query()->create(['key' => str_replace('.', '_', $code).':'.$i, 'code' => $code, 'group' => 'records', 'title' => 'Finding '.$i,
            'state' => 'open', 'first_seen_at' => now()->subDays(20), 'last_seen_at' => now()->subDay()]), range(1, $n));
    }

    private function dismissed(Finding $f, string $note): void
    {
        FindingsRegistry::event($f, 'dismissed', actor: 'you', note: $note);
    }

    public function test_one_both_charges_were_real_among_a_few_duplicates_demotes_nothing(): void
    {
        foreach (['2026-05-01', '2026-05-08', '2026-05-15'] as $on) {
            $this->charge($on, -2500, 'NCP PARKING');
            $this->charge($on, -2500, 'NCP PARKING');
        }
        FindingsRegistry::sync();
        $first = Finding::query()->where('code', 'tx.duplicate')->orderBy('id')->first();
        Dismissals::dismiss($first->key, null, Dismissals::note('real', null, false, 'tx.duplicate'));
        FindingsRegistry::sync();
        $this->assertSame('normal', DetectorLevels::levelOf('tx.duplicate'));

        // A new genuine double charge is on the list, in the count and on Telegram as before.
        $this->charge('2026-06-09', -35000, 'AMAZON');
        $this->charge('2026-06-09', -35000, 'AMAZON');
        TodoList::forget();
        $list = TodoList::build();
        $this->assertSame(3, $list['counts']['duplicates'] ?? 0);
        $this->assertSame([], $list['below_counts']);
    }

    public function test_one_not_a_problem_on_mileage_demotes_nothing(): void
    {
        [$a] = $this->findings('travel.unclaimed', 2);
        $this->dismissed($a, 'Not a problem');
        $this->assertSame([], DetectorLevels::settle());
        $this->assertSame('normal', DetectorLevels::levelOf('travel.unclaimed'));
    }

    public function test_a_long_standing_kind_needs_ten_dismissals_most_of_them_saying_it_was_wrong(): void
    {
        $fs = $this->findings('tx.duplicate', 14);
        // Ten dismissals, five of them false alarms: not more than half.
        foreach (array_slice($fs, 0, 5) as $f) {
            $this->dismissed($f, 'Both charges were real');
        }
        foreach (array_slice($fs, 5, 5) as $f) {
            $this->dismissed($f, 'Refunded');
        }
        $this->assertSame([], DetectorLevels::settle());
        $this->assertSame('normal', DetectorLevels::levelOf('tx.duplicate'));

        // Eleven, six wrong: more than half.
        $this->dismissed($fs[10], 'Not a problem');
        $this->assertSame(['tx.duplicate'], array_keys(DetectorLevels::settle()));
        $this->assertSame('demoted', DetectorLevels::levelOf('tx.duplicate'));
        $this->assertStringStartsWith('6 of the 11 you marked not relevant since', DetectorLevels::all()['tx.duplicate']['reason']);
    }

    public function test_a_new_kind_you_raised_is_not_demoted_on_fewer_than_ten_findings(): void
    {
        DetectorLevels::raise('income.unlinked');
        Carbon::setTestNow('2026-06-10 11:00:00');
        CarbonImmutable::setTestNow('2026-06-10 11:00:00');
        [$a, $b] = $this->findings('income.unlinked', 3);
        $this->dismissed($a, 'Not mine');
        $this->dismissed($b, 'Not a problem');
        $this->assertSame([], DetectorLevels::settle(), '2 of 3: too few to tell');
        $this->assertSame('raised', DetectorLevels::levelOf('income.unlinked'));

        // A new kind you haven't raised is below the fold already: nothing to demote.
        [$c, $d] = $this->findings('dupe.cross_source', 12);
        $this->dismissed($c, 'Not mine');
        $this->dismissed($d, 'Not mine');
        $this->assertSame([], DetectorLevels::settle());
        $this->assertSame('info', DetectorLevels::levelOf('dupe.cross_source'));
    }
}
