<?php

namespace Tests\Feature\Intel;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Matching\OwnAccounts;
use App\Domain\Matching\TransferPairer;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Support\UkTaxYear;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The one writer of decisions locks the line while it checks and replaces it, writes nothing (and records nothing) when
 * the line already says the same, and the transfer matcher never guesses between two partners that fit equally well.
 */
class AllocationWriterLockTest extends TestCase
{
    use RefreshDatabase;

    private Account $starling;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        Setting::put('job_finder_auto', false);
        OwnAccounts::flush();
        $this->starling = Account::query()->create(['name' => 'Starling Business', 'kind' => 'bank', 'provider' => 'starling']);
    }

    private function tx(Account $account, string $date, int $pence, string $description, ?string $counterparty = null, string $file = 't.csv'): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => $file], ['file_sha256' => hash('sha256', $file), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => MerchantKey::from($description, $counterparty), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1,
        ]);
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function events(BankTransaction $tx): int
    {
        return AuditEvent::query()->where('subject_type', 'BankTransaction')->where('subject_id', $tx->id)->count();
    }

    // ---- nothing written when nothing changes ---------------------------------------------------------------------

    public function test_a_second_identical_suggestion_writes_no_audit_event(): void
    {
        $writer = app(AllocationWriter::class);
        $tx = $this->tx($this->starling, '2025-06-10', -2499, 'SCREWFIX LEEDS', 'SCREWFIX');
        $lines = AllocationWriter::lines(-2499, 'business_expense', $this->cat('materials'));

        $this->assertTrue($writer->decide($tx, $lines, 'matcher', false, 'Looks like kit, cable and consumables'));
        $ids = $tx->allocations()->pluck('id')->all();
        $this->assertTrue($writer->decide($tx->fresh(), $lines, 'matcher', false, 'Looks like kit, cable and consumables'), 'The line says this: done');

        $this->assertSame(1, $this->events($tx));
        $this->assertSame($ids, $tx->allocations()->pluck('id')->all(), 'The same rows, not deleted and made again');
        $this->assertSame('suggested', $tx->fresh()->review_status);
    }

    public function test_your_identical_decision_again_records_nothing_new(): void
    {
        $writer = app(AllocationWriter::class);
        $tx = $this->tx($this->starling, '2025-06-10', -4000, 'VIRGIN MEDIA', 'VIRGIN MEDIA');
        $lines = AllocationWriter::lines(-4000, 'business_expense', $this->cat('phone_internet'), null, 50);

        $writer->decide($tx, $lines);
        $writer->decide($tx->fresh(), $lines);

        $this->assertSame(1, AuditEvent::query()->where('action', 'allocation.decided')->where('subject_id', $tx->id)->count());
        $this->assertSame(['business_expense', 'personal'], $tx->allocations()->orderBy('id')->pluck('kind')->all());
    }

    public function test_any_difference_is_written_and_recorded(): void
    {
        $writer = app(AllocationWriter::class);
        $tx = $this->tx($this->starling, '2025-06-10', -2499, 'SCREWFIX LEEDS', 'SCREWFIX');
        $materials = AllocationWriter::lines(-2499, 'business_expense', $this->cat('materials'));

        $writer->decide($tx, $materials, 'matcher', false, 'Looks like kit, cable and consumables');
        $writer->decide($tx->fresh(), $materials, 'matcher', false, 'Like the 3 times you decided SCREWFIX'); // new reason
        $writer->decide($tx->fresh(), AllocationWriter::lines(-2499, 'business_expense', $this->cat('equipment')), 'matcher', false, 'Like the 3 times you decided SCREWFIX'); // new category
        $writer->decide($tx->fresh(), AllocationWriter::lines(-2499, 'business_expense', $this->cat('equipment')), 'you'); // yours now

        $this->assertSame(4, $this->events($tx));
        $this->assertSame(['you', true], [$tx->allocations()->value('decided_by'), (bool) $tx->allocations()->value('is_manual')]);
    }

    public function test_the_categoriser_suggesting_the_same_again_leaves_no_trace(): void
    {
        $tx = $this->tx($this->starling, '2025-06-10', -650, 'PRET A MANGER', 'PRET A MANGER');
        $proposal = ['lines' => AllocationWriter::lines(-650, 'personal'), 'reason' => 'Looks like food or drink, with no job that day: personal'];

        app(Categoriser::class)->suggest($tx, $proposal);
        app(Categoriser::class)->suggest($tx->fresh(), $proposal); // the nightly pass, the next night

        $this->assertSame(1, $this->events($tx));
        $this->assertSame(Categoriser::MARKER, $tx->allocations()->value('matcher'), 'Still marked as the Categoriser’s');
    }

    // ---- the guard reads the latest decision, under lock ---------------------------------------------------------

    public function test_a_matcher_working_from_an_old_copy_never_overwrites_your_decision(): void
    {
        $tx = $this->tx($this->starling, '2025-06-10', -2499, 'SCREWFIX LEEDS', 'SCREWFIX');
        $old = BankTransaction::query()->with('allocations')->find($tx->id); // loaded by a nightly pass: nothing decided yet
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(-2499, 'personal')); // meanwhile, you decide

        $this->assertFalse(app(AllocationWriter::class)->decide($old, AllocationWriter::lines(-2499, 'business_expense', $this->cat('materials')), 'matcher', false, 'Looks like kit'));

        $this->assertSame(['personal', 'you'], [$tx->allocations()->value('kind'), $tx->allocations()->value('decided_by')]);
    }

    public function test_deciding_confirming_and_clearing_lock_the_line_and_its_allocations(): void
    {
        $writer = app(AllocationWriter::class);
        $tx = $this->tx($this->starling, '2025-06-10', -2499, 'SCREWFIX LEEDS', 'SCREWFIX');

        foreach ([
            'decide' => fn () => $writer->decide($tx, AllocationWriter::lines(-2499, 'personal'), 'matcher', false, 'Looks like kit'),
            'confirm' => fn () => $writer->confirm($tx->fresh()),
            'clear' => fn () => $writer->clear($tx->fresh(), 'Changed my mind'),
        ] as $what => $work) {
            $locked = array_values(array_filter($this->queries($work), fn ($q) => str_contains($q['sql'], 'for update')));
            $tables = array_map(fn ($q) => preg_match('/from "(\w+)"/', $q['sql'], $m) ? $m[1] : '?', $locked);
            $this->assertContains('bank_transactions', $tables, "{$what} locks the line");
            $this->assertContains('allocations', $tables, "{$what} locks its allocations");
            $this->assertTrue(collect($locked)->every('nested'), "{$what} holds the locks inside its transaction");
        }
        $this->assertSame([], $tx->allocations()->get()->all());
    }

    // ---- pairing never guesses between equal partners -----------------------------------------------------------

    public function test_two_equally_good_partners_make_no_pair(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo', 'last4' => '7310']);
        OwnAccounts::flush();
        $out = $this->tx($this->starling, '2025-06-02', -10000, 'Savings', 'A SMITH');
        $toRevolut = $this->tx($revolut, '2025-06-03', 10000, 'Payment from Alex Smith');
        $toMonzo = $this->tx($monzo, '2025-06-03', 10000, 'Payment from Alex Smith');
        $pairer = app(TransferPairer::class);

        $this->assertSame(0, $pairer->pairAll());

        $this->assertSame([null, null, null], [$out->fresh()->transfer_pair_id, $toRevolut->fresh()->transfer_pair_id, $toMonzo->fresh()->transfer_pair_id]);
        $this->assertSame(3, $pairer->ambiguous, 'All three left for you');
        $this->assertFalse(AuditEvent::query()->where('action', 'transfer.paired')->exists());
    }

    public function test_the_nightly_run_says_how_many_lines_it_left_for_you(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo', 'last4' => '7310']);
        OwnAccounts::flush();
        $this->tx($this->starling, '2025-06-02', -10000, 'Savings', 'A SMITH');
        $this->tx($revolut, '2025-06-03', 10000, 'Payment from Alex Smith');
        $this->tx($monzo, '2025-06-03', 10000, 'Payment from Alex Smith');

        $this->artisan('ledger:transfers')->expectsOutputToContain('3 lines left for you: which partner is right isn’t clear.')->assertSuccessful();
    }

    public function test_a_nearer_or_surer_partner_still_wins(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo', 'last4' => '7310']);
        OwnAccounts::flush();
        $out = $this->tx($this->starling, '2025-06-02', -10000, 'Savings', 'A SMITH');
        $near = $this->tx($revolut, '2025-06-03', 10000, 'Payment from Alex Smith');
        $later = $this->tx($monzo, '2025-06-05', 10000, 'Payment from Alex Smith');
        $pairer = app(TransferPairer::class);

        $this->assertSame(1, $pairer->pairAll());

        $this->assertSame($near->id, $out->fresh()->transfer_pair_id, 'Equally sure, but a day apart rather than three');
        $this->assertNull($later->fresh()->transfer_pair_id);
        $this->assertSame(0, $pairer->ambiguous);
        $this->assertSame('transfer', Allocation::query()->where('bank_transaction_id', $near->id)->value('kind'));
    }

    public function test_lines_that_are_all_alike_are_paired_in_order(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        OwnAccounts::flush();
        // Two £100 top-ups from Starling to Revolut on the same day: whichever pairs with whichever, the books are the same.
        $out1 = $this->tx($this->starling, '2025-06-02', -10000, 'To Revolut', 'A SMITH');
        $out2 = $this->tx($this->starling, '2025-06-02', -10000, 'To Revolut', 'A SMITH');
        $in1 = $this->tx($revolut, '2025-06-02', 10000, 'Payment from Alex Smith');
        $in2 = $this->tx($revolut, '2025-06-02', 10000, 'Payment from Alex Smith');
        $pairer = app(TransferPairer::class);

        $this->assertSame(2, $pairer->pairAll());

        $this->assertSame([$out1->id, $out2->id], [$in1->fresh()->transfer_pair_id, $in2->fresh()->transfer_pair_id], 'In the order they came');
        $this->assertSame(0, $pairer->ambiguous);
        $this->assertSame(0, $pairer->suggestOneSided(), 'Nothing left over to suggest on its own');
        $this->assertEqualsCanonicalizing([$out1->id, $out2->id, $in1->id, $in2->id], TransferPairer::confirmable()['ids'], 'All four confirmed with one tap, as before');
    }

    public function test_lines_alike_but_for_the_day_are_still_left_for_you(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        OwnAccounts::flush();
        // Each £100 in is a day from each £100 out, but the days differ: which went with which isn't the same either way.
        $this->tx($this->starling, '2025-06-02', -10000, 'To Revolut', 'A SMITH');
        $this->tx($this->starling, '2025-06-04', -10000, 'To Revolut', 'A SMITH');
        $this->tx($revolut, '2025-06-03', 10000, 'Payment from Alex Smith');
        $pairer = app(TransferPairer::class);

        $this->assertSame(0, $pairer->pairAll());
        $this->assertSame(3, $pairer->ambiguous);
    }

    public function test_a_line_whose_best_partner_is_caught_in_a_tie_is_left_too(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo', 'last4' => '7310']);
        OwnAccounts::flush();
        $x = $this->tx($this->starling, '2025-06-02', -10000, 'Savings', 'A SMITH');
        $y = $this->tx($monzo, '2025-06-02', -10000, 'Savings', 'A SMITH');
        $a = $this->tx($revolut, '2025-06-03', 10000, 'Payment from Alex Smith'); // X or Y, a day each
        $b = $this->tx($this->starling, '2025-06-03', 10000, 'Payment from Alex Smith'); // Y a day away (X is the same account)
        $z = $this->tx($revolut, '2025-06-06', -10000, 'Savings', 'A SMITH'); // B, three days away
        $pairer = app(TransferPairer::class);

        $this->assertSame(0, $pairer->pairAll(), 'Had A gone with X, B would go with Y; had it gone with Y, B would go with Z');

        foreach ([$x, $y, $a, $b, $z] as $tx) {
            $this->assertNull($tx->fresh()->transfer_pair_id);
        }
        $this->assertSame(5, $pairer->ambiguous, 'Z too: its only partner, B, is caught up in the tie');
    }

    public function test_a_tie_across_the_edge_of_an_imports_window_is_left_after_the_import_too(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        $monzo = Account::query()->create(['name' => 'Monzo', 'kind' => 'bank', 'provider' => 'monzo', 'last4' => '7310']);
        OwnAccounts::flush();
        $out = $this->tx($this->starling, '2025-05-28', -10000, 'Savings', 'A SMITH');
        $before = $this->tx($revolut, '2025-05-27', 10000, 'Payment from Alex Smith');
        $after = $this->tx($monzo, '2025-05-29', 10000, 'Payment from Alex Smith');
        $night = app(TransferPairer::class);
        $this->assertSame(0, $night->pairAll());
        $this->assertSame(3, $night->ambiguous);

        // The next Starling statement arrives: a coffee on 2 June. The run after it looks at 28 May to 7 June, so
        // the line of 27 May is outside it, but it's still a rival for the line of 28 May.
        $coffee = $this->tx($this->starling, '2025-06-02', -350, 'PRET A MANGER', 'PRET A MANGER', 'week2.csv');
        $import = app(TransferPairer::class);

        $this->assertSame(0, $import->pairAll([$coffee->import_batch_id]));

        $this->assertSame([null, null, null], [$out->fresh()->transfer_pair_id, $before->fresh()->transfer_pair_id, $after->fresh()->transfer_pair_id]);
        $this->assertSame(3, $import->ambiguous);
    }

    public function test_an_import_pairs_a_line_in_its_window_with_a_partner_just_outside_it(): void
    {
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'last4' => '4821']);
        OwnAccounts::flush();
        $in = $this->tx($revolut, '2025-05-25', 10000, 'Payment from Alex Smith');
        $out = $this->tx($this->starling, '2025-05-28', -10000, 'Savings', 'A SMITH');
        $coffee = $this->tx($this->starling, '2025-06-01', -350, 'PRET A MANGER', 'PRET A MANGER', 'week2.csv');

        // The run after the import looks at 27 May to 6 June: the line of 28 May is in it, its partner of 25 May
        // isn't. A full run would pair them, so this run does too.
        $this->assertSame(1, app(TransferPairer::class)->pairAll([$coffee->import_batch_id]));
        $this->assertSame($out->id, $in->fresh()->transfer_pair_id);
    }

    /**
     * Run $work and return its queries, with a locking read marked "for update" (SQLite has no row locks, so its
     * grammar normally leaves the clause out; MySQL's writes it) and whether each ran inside a transaction of the
     * work's own.
     *
     * @return list<array{sql:string, nested:bool}>
     */
    private function queries(callable $work): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return $value === true ? '/* for update */' : '';
                }
            });
        }
        $base = DB::transactionLevel();
        $seen = [];
        $on = true;
        DB::listen(function (QueryExecuted $q) use (&$seen, &$on, $base) {
            if ($on) {
                $seen[] = ['sql' => str_replace('`', '"', $q->sql), 'nested' => DB::transactionLevel() > $base];
            }
        });
        try {
            $work();
        } finally {
            $on = false;
            $connection->setQueryGrammar($grammar);
        }

        return $seen;
    }
}
