<?php

namespace Tests\Feature;

use App\Domain\Import\LineDetails;
use App\Domain\Import\RevolutImport;
use App\Domain\Ledger\Reconciler;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\StatementBalance;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Accounts checked against the bank's own figures, the findings history, and the details on each ledger line. */
class NewFixesLedgerTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function batch(Account $a, string $name = 't.csv', array $extra = []): ImportBatch
    {
        return ImportBatch::query()->create(array_merge(['account_id' => $a->id, 'original_filename' => $name, 'file_sha256' => hash('sha256', $name.$a->id), 'stored_path' => 'x', 'status' => 'committed'], $extra));
    }

    private function row(Account $a, string $date, int $pence, ?int $balance, array $extra = [], ?ImportBatch $batch = null): BankTransaction
    {
        $batch ??= ImportBatch::query()->where('account_id', $a->id)->first() ?? $this->batch($a);

        return BankTransaction::query()->create($extra + ['account_id' => $a->id, 'import_batch_id' => $batch->id, 'posted_on' => $date,
            'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($extra['transaction_on'] ?? $date))->label(), 'description' => 'Line', 'merchant_key' => 'X',
            'amount_pence' => $pence, 'balance_after_pence' => $balance, 'raw' => [], 'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1]);
    }

    private function csv(string $text): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rev').'.csv';
        file_put_contents($path, $text);

        return $path;
    }

    // ------------------------------------------------------------------ reconciling (N10, N94, N46, N52, N33/N47, N72, N106)

    public function test_n10_a_revolut_statement_whose_payments_complete_days_later_matches(): void
    {
        Storage::fake('local');
        Http::fake();
        $this->seed();
        Setting::put('own_names', ['Alex Smith']);
        $revolut = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $path = $this->csv("Type,Product,Started Date,Completed Date,Description,Amount,Fee,Currency,State,Balance\n"
            ."Topup,Current,2026-03-01 09:00:00,2026-03-01 09:00:05,Top-up,500.00,0.00,GBP,COMPLETED,500.00\n"
            ."Card Payment,Current,2026-03-02 13:40:10,2026-03-05 08:01:00,Pret A Manger,-6.50,0.00,GBP,COMPLETED,493.50\n"
            ."Transfer,Current,2026-03-04 10:00:00,2026-03-04 10:00:01,To J Bloggs,-100.00,0.00,GBP,COMPLETED,393.50\n"
            ."Card Payment,Current,2026-03-06 12:00:00,2026-03-06 12:00:01,Tesco,-10.00,0.00,GBP,COMPLETED,383.50\n");
        app(RevolutImport::class)->commit($revolut, $path, 'revolut.csv');
        $this->assertSame('committed', ImportBatch::query()->value('status'));

        $r = Reconciler::account($revolut);
        $this->assertSame('running_balance', $r['method']);
        $this->assertSame([], $r['gaps'], 'A payment completing after a later one is not money missing');
        $this->assertSame(38350, $r['balance_pence']);

        // A genuinely missing line is still found.
        $this->row($revolut, '2026-03-10', -500, 37000);
        $this->assertSame([-850], array_column(Reconciler::account($revolut)['gaps'], 'missing_pence'));
    }

    public function test_n10_lines_whose_balance_follows_the_completed_date_still_match(): void
    {
        $bank = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut']);
        $this->row($bank, '2026-03-01', 50000, 50000);
        $this->row($bank, '2026-03-05', -650, 39350, ['transaction_on' => '2026-03-02']);
        $this->row($bank, '2026-03-04', -10000, 40000);
        $this->row($bank, '2026-03-06', -1000, 38350);

        $this->assertSame([], Reconciler::account($bank)['gaps']);
    }

    public function test_n94_a_day_that_ends_where_it_began_in_a_newest_first_file_has_no_gap(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        // Stored newest first, as the file was: 2 Jun, then 1 Jun's -£100 (the later of the day) and +£100.
        $this->row($bank, '2025-06-02', -500, 99500);
        $this->row($bank, '2025-06-01', -10000, 100000);
        $this->row($bank, '2025-06-01', 10000, 110000);
        $this->assertSame([], Reconciler::account($bank)['gaps']);

        // The same kind of day straight after a real gap: one gap, with the whole amount, in the right place.
        $other = Account::query()->create(['name' => 'Other', 'kind' => 'bank']);
        $this->row($other, '2025-06-10', -500, 99500);
        $this->row($other, '2025-06-09', -10000, 100000);
        $this->row($other, '2025-06-09', 10000, 110000);
        $this->row($other, '2025-06-01', -1000, 50000);
        $gaps = Reconciler::account($other)['gaps'];
        $this->assertSame([['2025-06-02', '2025-06-08', 50000]], array_map(fn ($g) => [$g['from'], $g['to'], $g['missing_pence']], $gaps));
    }

    public function test_n46_lines_without_a_balance_fill_a_gap_and_a_line_in_twice_is_shown(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $bal = 100000;
        foreach (range(1, 20) as $d) {
            $this->row($bank, sprintf('2025-06-%02d', $d), -100, $bal -= 100);
        }
        $this->row($bank, '2025-06-22', -100, $bal -= 5100);
        $this->assertSame([['2025-06-21', -5000]], array_map(fn ($g) => [$g['from'], $g['missing_pence']], Reconciler::account($bank)['gaps']));

        // The missing payment, imported from a file with no balance column: the gap is filled.
        $this->row($bank, '2025-06-21', -5000, null);
        $r = Reconciler::account($bank);
        $this->assertSame(['running_balance', []], [$r['method'], $r['gaps']]);

        // The same day's statement imported again, this time with balances: the payment is now in twice.
        $this->row($bank, '2025-06-21', -5000, 93000);
        $this->row($bank, '2025-06-23', -100, $bal - 100);
        $gaps = Reconciler::account($bank)['gaps'];
        $this->assertCount(1, $gaps);
        $this->assertSame(['extra', 5000], [$gaps[0]['kind'], $gaps[0]['missing_pence']]);
        $this->assertStringContainsString('£50.00 of money out is in Expend but not in the bank’s figures on 21 Jun 2025', $gaps[0]['text']);
        $todo = collect(TodoList::build()['items'])->where('group', 'reconcile')->values();
        $this->assertStringContainsString('may be in twice', $todo[0]['title']);
    }

    public function test_n46_a_line_without_a_balance_between_lines_with_one_is_no_gap(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $bal = 100000;
        foreach (range(1, 20) as $d) {
            $this->row($bank, sprintf('2025-07-%02d', $d), -100, $bal -= 100);
        }
        $this->row($bank, '2025-07-21', -100, $bal -= 100);
        $this->row($bank, '2025-07-21', -700, null);
        $bal -= 700;
        $this->row($bank, '2025-07-21', -100, $bal -= 100);
        $this->row($bank, '2025-07-22', -100, $bal - 100);

        $this->assertSame([], Reconciler::account($bank)['gaps']);
    }

    public function test_n52_a_statement_balance_is_checked_on_a_running_balance_account(): void
    {
        Carbon::setTestNow('2025-07-05 10:00:00');
        $this->actingAs(User::factory()->create());
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-05', 2000, 102000);
        $this->row($bank, '2025-06-10', -3000, 99000);

        // The bank says £500 at the end of June: £490 has gone out since the last import.
        $this->post("/accounts/{$bank->id}/balances", ['as_of' => '2025-06-30', 'balance' => '500.00'])->assertSessionHas('error');
        $gaps = Reconciler::account($bank)['gaps'];
        $this->assertSame([['2025-06-11', '2025-06-30', -49000]], array_map(fn ($g) => [$g['from'], $g['to'], $g['missing_pence']], $gaps));

        // Corrected to what the bank really says: it matches, up to the statement's date.
        $this->post("/accounts/{$bank->id}/balances", ['as_of' => '2025-06-30', 'balance' => '990.00'])->assertSessionHas('success', 'Saved. Matches the bank up to 30 Jun 2025.');

        // An opening balance before the first import that doesn't agree: missing before it.
        $this->post("/accounts/{$bank->id}/balances", ['as_of' => '2025-05-31', 'balance' => '2,000.00'])->assertSessionHas('error');
        $gaps = Reconciler::account($bank)['gaps'];
        $this->assertSame([['2025-05-31', '2025-06-01', -95000]], array_map(fn ($g) => [$g['from'], $g['to'], $g['missing_pence']], $gaps));
        $this->assertStringContainsString('between 31 May 2025 and 1 Jun 2025', $gaps[0]['text']);
    }

    public function test_n33_n47_a_foreign_pockets_gaps_are_in_its_own_currency(): void
    {
        $gbp = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $eur = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $gbp->id]);
        $this->row($eur, '2026-03-03', -1000, null, ['original_amount_minor' => -1150, 'original_currency' => 'EUR', 'balance_after_minor' => 10350]);
        $this->row($eur, '2026-03-06', -2000, null, ['original_amount_minor' => -2300, 'original_currency' => 'EUR', 'balance_after_minor' => 6850]);

        $r = Reconciler::account($eur);
        $this->assertSame('€12.00 of money out isn’t in Expend from 4 Mar 2026 to 5 Mar 2026.', $r['gaps'][0]['text']);
        $this->assertStringContainsString('(€12.00 in all)', $r['text']);
        $this->assertStringNotContainsString('£', $r['text'].$r['gaps'][0]['text']);
        $todo = collect(TodoList::build()['items'])->firstWhere('group', 'reconcile');
        $this->assertSame(['Revolut EUR: €12.00 missing', 'EUR'], [$todo['title'], $todo['currency']]);

        // Checked against statement balances, a pocket adds up its own currency, not pounds.
        $card = Account::query()->create(['name' => 'Euro card', 'kind' => 'bank', 'currency' => 'EUR']);
        $this->row($card, '2026-03-10', -900, null, ['original_amount_minor' => -1000, 'original_currency' => 'EUR']);
        StatementBalance::query()->create(['account_id' => $card->id, 'as_of' => '2026-03-01', 'balance_pence' => 5000]);
        StatementBalance::query()->create(['account_id' => $card->id, 'as_of' => '2026-03-31', 'balance_pence' => 4000]);
        $this->assertSame(['statement_balances', []], [Reconciler::account($card)['method'], Reconciler::account($card)['gaps']]);
    }

    public function test_n72_an_unused_currency_pocket_isnt_waiting_for_a_statement(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        CarbonImmutable::setTestNow('2026-05-20 10:00:00');
        $gbp = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $eur = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $gbp->id]);
        $usd = Account::query()->create(['name' => 'Revolut USD', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'USD', 'parent_account_id' => $gbp->id]);
        $this->row($eur, '2026-03-06', -1000, null, ['original_amount_minor' => -1150, 'original_currency' => 'EUR', 'balance_after_minor' => 10350]);
        $eur = $eur->fresh();
        $this->assertTrue(Reconciler::account($eur)['stale'], 'Nothing imported anywhere in this Revolut account since March');

        // A statement of all its currencies was imported in May (pounds and dollars in it, nothing in euros).
        $may = $this->batch($gbp, 'may.csv', ['first_date' => '2026-05-01', 'last_date' => '2026-05-15']);
        $this->row($gbp, '2026-05-15', -500, 20000, [], $may);
        $this->row($usd, '2026-05-10', -400, null, ['original_amount_minor' => -500, 'original_currency' => 'USD', 'balance_after_minor' => 9500],
            $this->batch($usd, 'may.csv', ['first_date' => '2026-05-01', 'last_date' => '2026-05-15', 'file_sha256' => $may->file_sha256]));
        $this->assertFalse(Reconciler::account($eur)['stale']);
        $this->assertSame('2026-03-06', Reconciler::account($eur)['last_line']);
        $this->assertFalse(collect(TodoList::build()['items'])->contains(fn ($i) => str_starts_with($i['key'], 'stale:')));

        // An ordinary account is still checked on its own.
        $other = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->row($other, '2026-03-01', -500, 1000);
        $this->assertTrue(Reconciler::account($other->fresh())['stale']);
    }

    public function test_n106_lines_on_the_statement_dates_and_a_corrected_balance(): void
    {
        $this->actingAs(User::factory()->create());
        $card = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->row($card, '2025-04-01', -500, null);  // on the first statement
        $this->row($card, '2025-04-30', -1300, null); // on the second

        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-01', 'balance' => '100.00'])->assertSessionHas('success');
        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-30', 'balance' => '113.00'])->assertSessionHas('success');
        $this->assertSame([], Reconciler::account($card)['gaps'], 'The first day is the first statement’s, the last day is the second’s');

        // Corrected: the balance is replaced, and the audit log keeps what it replaced.
        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-30', 'balance' => '113.50'])->assertRedirect()->assertSessionHas('error');
        $this->assertSame([10000, 11350], StatementBalance::query()->orderBy('as_of')->pluck('balance_pence')->all());
        $event = AuditEvent::query()->where('action', 'account.balance_changed')->sole();
        $this->assertSame(['as_of' => '2025-04-30', 'balance_pence' => 11300, 'note' => null], $event->before);
        $this->assertSame(11350, $event->after['balance_pence']);
    }

    public function test_n92_n102_an_unreadable_balance_is_a_form_error(): void
    {
        $this->actingAs(User::factory()->create());
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        foreach (['12,50', 'abc', '1.234,56'] as $bad) {
            $this->post("/accounts/{$bank->id}/balances", ['as_of' => '2025-04-01', 'balance' => $bad])->assertRedirect()->assertSessionHasErrors('balance');
        }
        $this->assertSame(0, StatementBalance::query()->count());
    }

    public function test_n93_a_card_statement_in_credit_is_read_as_credit(): void
    {
        $this->actingAs(User::factory()->create());
        $card = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->row($card, '2025-04-20', 11234, null); // paid £112.34 off £100 owed
        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-01', 'balance' => '100.00']);
        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-04-30', 'balance' => '£12.34 CR'])->assertSessionHas('success');
        $this->assertSame(-1234, StatementBalance::query()->where('as_of', '2025-04-30')->value('balance_pence'));
        $this->assertSame([], Reconciler::account($card)['gaps']);

        $this->row($card, '2025-05-10', -5000, null);
        $this->post("/accounts/{$card->id}/balances", ['as_of' => '2025-05-31', 'balance' => '37.66 DR'])->assertSessionHas('success');
        $this->assertSame(3766, StatementBalance::query()->where('as_of', '2025-05-31')->value('balance_pence'));

        // A bank account's CR and DR mean what they always did.
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->post("/accounts/{$bank->id}/balances", ['as_of' => '2025-04-30', 'balance' => '50.00 DR']);
        $this->assertSame(-5000, StatementBalance::query()->where('account_id', $bank->id)->value('balance_pence'));
    }

    // ------------------------------------------------------------------ findings history (N48, N104, N49, N50, N96, N85)

    public function test_n48_n104_a_finding_past_the_lists_limits_isnt_dealt_with(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $old = WorkJob::query()->create(['title' => 'Old show', 'starts_on' => '2023-05-01', 'ends_on' => '2023-05-01', 'status' => 'done']);
        $rows = [];
        foreach (range(1, 299) as $i) {
            $day = CarbonImmutable::parse('2024-01-01')->addDays($i)->format('Y-m-d');
            $rows[] = ['title' => 'Job '.$i, 'starts_on' => $day, 'ends_on' => $day, 'status' => 'done', 'po_number' => 'PO-'.$i, 'created_at' => now(), 'updated_at' => now()];
        }
        WorkJob::query()->insert($rows);
        FindingsRegistry::sync();
        $this->assertNull(Finding::query()->where('key', 'no_po:'.$old->id)->sole()->resolved_at);

        WorkJob::query()->create(['title' => 'New show', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01', 'status' => 'done', 'po_number' => 'PO-NEW']);
        FindingsRegistry::sync();
        foreach (['no_po:', 'invoice:'] as $prefix) {
            $f = Finding::query()->where('key', $prefix.$old->id)->sole();
            $this->assertSame([null, null], [$f->resolved_at, $f->resolution], "{$prefix} is still open: nothing was done");
        }
        $this->assertSame(0, FindingsRegistry::summary('2020-01-01')['resolved']);
    }

    public function test_n49_findings_dealt_with_are_listed_however_many_are_open(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $jobs = collect(range(1, 105))->map(fn ($i) => WorkJob::query()->create(['title' => 'Show '.$i, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01', 'status' => 'booked']));
        FindingsRegistry::sync();
        Carbon::setTestNow('2026-11-02 09:00:00');
        $jobs->take(3)->each(fn (WorkJob $j) => $j->update(['po_number' => 'PO-'.$j->id]));
        FindingsRegistry::sync();
        Carbon::setTestNow('2026-11-03 09:00:00');
        FindingsRegistry::sync();
        $this->assertSame('2026-11-01 09:00:00', Finding::query()->whereNull('resolved_at')->max('updated_at'), 'Being seen again changes nothing');

        $recent = collect(FindingsRegistry::summary()['recent']);
        $this->assertSame(3, $recent->where('resolution', 'resolved')->count());
        $this->assertSame(100, $recent->whereNull('resolved')->count());
    }

    public function test_n50_a_snoozed_finding_then_dealt_with_counts_as_dealt_with(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'status' => 'booked']);
        $other = WorkJob::query()->create(['title' => 'Club night', 'starts_on' => '2025-08-02', 'ends_on' => '2025-08-02', 'status' => 'booked']);
        FindingsRegistry::sync();
        TodoDismissal::query()->create(['key' => 'no_po:'.$job->id, 'until' => '2025-07-08 09:00:00']);
        TodoDismissal::query()->create(['key' => 'no_po:'.$other->id, 'until' => '2025-07-30 09:00:00']);
        Carbon::setTestNow('2025-07-02 09:00:00');
        FindingsRegistry::sync();
        $this->assertSame('snoozed', Finding::query()->where('key', 'no_po:'.$job->id)->value('resolution'));

        // The snooze ends, it's back, and then you add the PO.
        Carbon::setTestNow('2025-07-10 09:00:00');
        FindingsRegistry::sync();
        $this->assertNull(Finding::query()->where('key', 'no_po:'.$job->id)->value('resolution'));
        Carbon::setTestNow('2025-07-11 09:00:00');
        $job->update(['po_number' => 'PO-1']);
        // The other one gets its PO while still snoozed.
        $other->update(['po_number' => 'PO-2']);
        FindingsRegistry::sync();

        $this->assertSame(['resolved', 'resolved'], Finding::query()->whereIn('key', ['no_po:'.$job->id, 'no_po:'.$other->id])->orderBy('id')->pluck('resolution')->all());
        $this->assertSame(2, FindingsRegistry::summary('2025-01-01')['resolved']);
    }

    public function test_n96_two_syncs_at_once_dont_fail_on_a_new_finding(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01', 'status' => 'booked']);
        $raced = false;
        DB::listen(function (QueryExecuted $q) use (&$raced, $job) {
            if (! $raced && preg_match('/^select .* from [`"]findings[`"] where [`"]key[`"] in /i', $q->sql)) {
                $raced = true; // another request records the same finding in between
                DB::table('findings')->insert(['key' => 'no_po:'.$job->id, 'group' => 'no_po', 'title' => 'No PO', 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        FindingsRegistry::sync();
        $this->assertTrue($raced);
        $this->assertSame(1, Finding::query()->where('key', 'no_po:'.$job->id)->count());
        $this->assertSame('No PO: Arena show', Finding::query()->where('key', 'no_po:'.$job->id)->value('title'));
    }

    public function test_n85_a_gap_filled_in_two_goes_is_one_finding_dealt_with_once(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'is_active' => true]);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        $gap = Finding::query()->where('group', 'reconcile')->where('key', 'like', 'gap:%')->sole();
        // Kept by its account and first day (M3), so what's left of it is the same finding.
        $this->assertSame([5000, 'gap:'.$bank->id.':2025-06-02'], [$gap->amount_pence, $gap->key]);

        // Part of it imported: still the same finding, with what's left.
        Carbon::setTestNow('2025-07-02 09:00:00');
        $this->row($bank, '2025-06-05', 3000, 103000);
        FindingsRegistry::sync();
        $this->assertSame(1, Finding::query()->where('key', 'like', 'gap:%')->count());
        $this->assertSame([null, 2000], [$gap->fresh()->resolved_at, $gap->fresh()->amount_pence]);

        // The rest: dealt with, once.
        Carbon::setTestNow('2025-07-03 09:00:00');
        $this->row($bank, '2025-06-07', 2000, 105000);
        FindingsRegistry::sync();
        $this->assertSame('resolved', $gap->fresh()->resolution);
        $summary = FindingsRegistry::summary('2025-01-01');
        $reconcile = collect($summary['by_group'])->firstWhere('group', 'reconcile');
        $this->assertSame([1, 1], [$reconcile['found'], $reconcile['dealt_with']]);
    }

    public function test_n85_a_gap_kept_or_snoozed_the_old_way_carries_on(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-10', -1000, 104000);
        $legacy = 'gap:'.$bank->id.':2025-06-02:5000';
        Finding::query()->create(['key' => $legacy, 'group' => 'reconcile', 'title' => 'Starling: £50.00 missing', 'amount_pence' => 5000, 'first_seen_at' => '2025-06-20', 'last_seen_at' => '2025-06-30']);
        TodoDismissal::query()->create(['key' => $legacy, 'until' => '2025-07-05 09:00:00']);

        $this->assertFalse(collect(TodoList::build()['items'])->contains(fn ($i) => str_starts_with($i['key'], 'gap:')), 'The snooze still hides it');
        FindingsRegistry::sync();
        $f = Finding::query()->where('group', 'reconcile')->sole();
        $this->assertSame(['gap:'.$bank->id.':2025-06-02', 'snoozed', '2025-06-20'], [$f->key, $f->resolution, $f->first_seen_at->format('Y-m-d')]);
        $this->assertSame(0, FindingsRegistry::summary('2025-01-01')['resolved'], 'A new key for the same gap is not the gap dealt with');
    }

    // ------------------------------------------------------------------ ledger line details (N51, N53, N95)

    public function test_n51_a_receipt_uploaded_weeks_after_the_payment_is_linked(): void
    {
        $this->actingAs(User::factory()->create());
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $hotel = $this->row($amex, '2025-04-04', -8900, null, ['description' => 'PREMIER INN']);
        $doc = fn (string $received, array $receipt, string $sha) => Document::query()->create(['source' => 'upload', 'original_name' => 'receipt.jpg', 'mime' => 'image/jpeg', 'size' => 1,
            'sha256' => str_repeat($sha, 64), 'stored_path' => 'x', 'kind' => 'receipt', 'status' => 'extracted', 'received_at' => $received, 'extraction' => ['receipt' => $receipt]]);
        $doc('2025-04-30 10:00:00', ['merchant' => 'Premier Inn', 'date' => '2025-04-04', 'amount_minor' => 8900], 'a');
        $doc('2026-01-20 10:00:00', ['merchant' => 'Premier Inn (tax time)', 'date' => '2025-04-05', 'amount_minor' => '8900'], 'b');
        $doc('2025-04-30 10:00:00', ['merchant' => 'No date on it', 'amount_minor' => 8900], 'c');
        $doc('2025-04-30 10:00:00', ['merchant' => 'Other amount', 'date' => '2025-04-04', 'amount_minor' => 8800], 'd');

        $links = collect($this->getJson("/items/bank/{$hotel->id}")->assertOk()->json('links'))->where('type', 'receipt')->pluck('label')->all();
        $this->assertSame(['Receipt: Premier Inn', 'Receipt: Premier Inn (tax time)'], $links);
    }

    public function test_n53_a_utc_time_is_only_shown_on_its_own_uk_date(): void
    {
        $late = LineDetails::from(['Date started (UTC)' => '2025-07-03 23:30:00'], null, CarbonImmutable::parse('2025-07-03'));
        $this->assertNull($late['time'], '00:30 on 4 July is not a time on 3 July');
        $this->assertSame('00:30', LineDetails::from(['Date started (UTC)' => '2025-07-03 23:30:00'], null, CarbonImmutable::parse('2025-07-04'))['time']);
        $this->assertSame('14:23', LineDetails::from(['Date started (UTC)' => '2025-07-03 13:23:10'], null, CarbonImmutable::parse('2025-07-03'))['time']);
        $this->assertSame('13:30', LineDetails::from(['Created' => '2025-07-03T12:30:00Z'], null, CarbonImmutable::parse('2025-07-03'))['time'], 'A time with a Z is in UTC');
        $this->assertSame('14:23', LineDetails::from(['Started Date' => '2025-07-03 14:23:10'])['time']);
    }

    public function test_n95_a_bank_lines_payment_kind_isnt_read_as_a_place_and_kept_ones_are_put_right(): void
    {
        foreach (['BRITISH GAS     DIRECT DEBIT    DD', 'HMRC NDDS          BUSINESS TAX       DDR', 'J SMITH             RENT               SO',
            'ACME EVENTS LTD     INVOICE            BGC', 'MY SAVINGS          TRANSFER           TFR'] as $text) {
            $this->assertNull(LineDetails::from([], $text)['place'], $text);
        }
        $this->assertSame('Leeds', LineDetails::from([], 'TESCO STORES 3021      LEEDS        GB')['place']);
        $this->assertSame('Madrid, ES', LineDetails::from([], 'CAFE CENTRAL  MADRID   ES')['place']);
        $this->assertSame('Paris, FRA', LineDetails::from([], 'LE CAFE      PARIS     FRA')['place']);

        // Lines already kept with a place made up from their text, a real one, one from the statement's columns,
        // and a UTC time a day out.
        $bank = Account::query()->create(['name' => 'Barclays', 'kind' => 'bank']);
        $made = $this->row($bank, '2025-06-01', -4500, null, ['description' => 'BRITISH GAS     DIRECT DEBIT    DD', 'place' => 'Direct Debit, DD']);
        $real = $this->row($bank, '2025-06-02', -350, null, ['description' => 'TESCO STORES 3021      LEEDS        GB', 'place' => 'Leeds']);
        $cols = $this->row($bank, '2025-06-03', -900, null, ['description' => 'X', 'raw' => ['Town/City' => 'MANCHESTER'], 'place' => 'Manchester']);
        $late = $this->row($bank, '2025-07-03', -1200, null, ['description' => 'Kebab', 'raw' => ['Date started (UTC)' => '2025-07-03 23:30:00'], 'transacted_time' => '00:30']);

        (include database_path('migrations/2026_12_01_000600_ledger_repair_line_places_and_times.php'))->up();

        $this->assertSame([null, 'Leeds', 'Manchester'], [$made->fresh()->place, $real->fresh()->place, $cols->fresh()->place]);
        $this->assertNull($late->fresh()->transacted_time);
        $event = AuditEvent::query()->where('action', 'ledger.line_details_repaired')->sole();
        $this->assertSame(['place' => 'Direct Debit, DD'], $event->before['lines'][$made->id]);
        $this->assertSame([-4500, -1200], [$made->fresh()->amount_pence, $late->fresh()->amount_pence]);
    }

    // ------------------------------------------------------------------ second review of these fixes

    /** Daily -£1 lines with balances on the days given (from £1,000), less what $drops says went out unseen before a day. */
    private function daily(Account $a, array $days, string $month = '2025-06', int $start = 100000, array $drops = []): int
    {
        $bal = $start;
        foreach ($days as $d) {
            $bal -= 100 + ($drops[$d] ?? 0);
            $this->row($a, sprintf('%s-%02d', $month, $d), -100, $bal);
        }

        return $bal;
    }

    private function gaps(Account $a): array
    {
        return array_map(fn ($g) => [$g['kind'], $g['from'], $g['to'], $g['missing_pence']], Reconciler::account($a->fresh())['gaps']);
    }

    public function test_chk_a_line_in_twice_on_the_last_day_is_shown_with_or_without_a_later_balance(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        CarbonImmutable::setTestNow('2025-07-01 09:00:00');
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        // A payment imported from a file with no balance, then the bank's own file for 1-21 Jun with it in.
        $file = $this->batch($bank, 'bank.csv');
        $bal = $this->daily($bank, range(1, 20));
        $this->row($bank, '2025-06-21', -5000, null, [], $this->batch($bank, 'hand.csv'));
        $this->row($bank, '2025-06-21', -5000, $bal - 5000, [], $file);

        $this->assertSame([['extra', '2025-06-21', '2025-06-21', 5000]], $this->gaps($bank));

        // The statement's closing balance at the end of June agrees with the bank's file: still in twice, not money in missing.
        StatementBalance::query()->create(['account_id' => $bank->id, 'as_of' => '2025-06-30', 'balance_pence' => $bal - 5000]);
        $this->assertSame([['extra', '2025-06-21', '2025-06-21', 5000]], $this->gaps($bank));
        $todo = collect(TodoList::build()['items'])->where('group', 'reconcile')->values();
        $this->assertSame(['Starling: £50.00 may be in twice'], $todo->pluck('title')->all());

        // A real payment late on the last day, after the bank's file was exported (no line of that amount that day):
        // the statement balance has it, so it's no gap; and without a statement balance it's left alone.
        $other = Account::query()->create(['name' => 'Other', 'kind' => 'bank']);
        $this->batch($other, 'bank.csv');
        $bal = $this->daily($other, range(1, 21));
        $this->row($other, '2025-06-21', -700, null, [], $this->batch($other, 'api.csv'));
        $this->assertSame([], $this->gaps($other));
        StatementBalance::query()->create(['account_id' => $other->id, 'as_of' => '2025-06-30', 'balance_pence' => $bal - 700]);
        $this->assertSame([], $this->gaps($other));
        // The same with a statement balance that doesn't have it: it isn't in the bank's figures.
        StatementBalance::query()->where('account_id', $other->id)->update(['balance_pence' => $bal]);
        $this->assertSame([['extra', '2025-06-21', '2025-06-21', 700]], $this->gaps($other));
    }

    public function test_chk_a_statement_balance_on_the_day_of_a_late_line_without_a_balance_matches(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $file = $this->batch($bank, 'bank.csv');
        $bal = $this->daily($bank, range(1, 10), '2025-06', 10000); // 9000 at the end of 10 Jun's lines
        $this->row($bank, '2025-06-10', -500, null, [], $this->batch($bank, 'hand.csv'));
        $this->row($bank, '2025-06-11', -100, $bal - 600, [], $file);
        $this->assertSame([], $this->gaps($bank));

        // The bank's balance at the end of 10 Jun has the late £5 in it.
        $sb = StatementBalance::query()->create(['account_id' => $bank->id, 'as_of' => '2025-06-10', 'balance_pence' => 8500]);
        $this->assertSame([], $this->gaps($bank));
        // Or it went through the next morning: that agrees too.
        $sb->update(['balance_pence' => 9000]);
        $this->assertSame([], $this->gaps($bank));
        // A balance that agrees with neither is a gap.
        $sb->update(['balance_pence' => 8000]);
        $this->assertSame([['missing', '2025-06-10', '2025-06-10', -500]], $this->gaps($bank));
    }

    public function test_chk_a_statement_balance_in_between_doesnt_hide_a_line_in_twice(): void
    {
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->batch($bank, 'bank.csv');
        $bal = $this->daily($bank, range(1, 9)); // nothing from 10 to 12 Jun
        $this->row($bank, '2025-06-11', -300, null, [], $this->batch($bank, 'hand.csv'));
        $this->daily($bank, range(13, 20), '2025-06', $bal);
        $this->assertSame([['extra', '2025-06-11', '2025-06-11', 300]], $this->gaps($bank));

        // The statement says the balance on 12 Jun was what it was at the end of 9 Jun: nothing went out between.
        StatementBalance::query()->create(['account_id' => $bank->id, 'as_of' => '2025-06-12', 'balance_pence' => $bal]);
        $this->assertSame([['extra', '2025-06-11', '2025-06-11', 300]], $this->gaps($bank), 'Still in twice, not money in missing');
    }

    public function test_chk_a_line_dated_days_from_its_gap_is_one_thing_to_check(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        CarbonImmutable::setTestNow('2025-07-01 09:00:00');
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $this->batch($bank, 'bank.csv');
        $this->daily($bank, range(1, 20), '2025-06', 100000, [14 => 5000]); // £50 went out between 13 and 14 Jun
        $this->assertSame([['missing', '2025-06-13', '2025-06-14', -5000]], $this->gaps($bank));

        // The payment, from a file that dates it a week before the bank's balance moved.
        $this->row($bank, '2025-06-07', -5000, null, [], $this->batch($bank, 'hand.csv'));
        $r = Reconciler::account($bank->fresh());
        $this->assertSame([['misdated', '2025-06-13', '2025-06-14', -5000]], $this->gaps($bank));
        $this->assertSame('2025-06-07', $r['gaps'][0]['line_on']);
        $this->assertStringContainsString('a line for the same amount dated 7 Jun 2025', $r['gaps'][0]['text']);

        $todo = collect(TodoList::build()['items'])->where('group', 'reconcile')->values();
        $this->assertSame(['Starling: check the date of a £50.00 line'], $todo->pluck('title')->all(), 'One to-do, not "missing" and "in twice"');
        $this->assertStringNotContainsString('undo', $todo[0]['detail']);
        $this->assertStringContainsString('min=50.00&max=50.00', $todo[0]['href']);

        // A line of another amount there is still in twice, and the advice is to check before undoing anything.
        $this->row($bank, '2025-06-07', -1234, null, [], ImportBatch::query()->where('original_filename', 'hand.csv')->first());
        $twice = collect(TodoList::build()['items'])->firstWhere('title', 'Starling: £12.34 may be in twice');
        $this->assertStringContainsString('before undoing anything', $twice['detail']);
    }

    public function test_chk_money_dealt_with_for_a_gap_filled_in_stages_is_the_whole_gap(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'is_active' => true]);
        $this->row($bank, '2025-06-01', -5000, 100000);
        $this->row($bank, '2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        Carbon::setTestNow('2025-07-02 09:00:00');
        $this->row($bank, '2025-06-05', 3000, 103000);
        FindingsRegistry::sync();
        $missing = fn (array $s, string $which) => collect($s['by_direction'])->firstWhere('direction', 'unrecorded')[$which]['GBP'] ?? 0;
        $this->assertSame(2000, $missing(FindingsRegistry::summary('2025-01-01'), 'open'), 'What’s still missing');
        Carbon::setTestNow('2025-07-03 09:00:00');
        $this->row($bank, '2025-06-07', 2000, 105000);
        FindingsRegistry::sync();

        $summary = FindingsRegistry::summary('2025-01-01');
        $this->assertSame([1, 5000, 0], [$summary['resolved'], $missing($summary, 'resolved'), $missing($summary, 'open')]);
        $this->assertSame(5000, collect($summary['recent'])->firstWhere('resolution', 'resolved')['amount_pence']);
    }

    public function test_chk_a_gap_marked_not_relevant_comes_back_when_its_amount_changes(): void
    {
        Carbon::setTestNow('2025-07-05 09:00:00');
        $amex = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card', 'provider' => 'amex']);
        $this->row($amex, '2025-06-15', -4700, null);
        StatementBalance::query()->create(['account_id' => $amex->id, 'as_of' => '2025-05-31', 'balance_pence' => 10000]);
        $june = StatementBalance::query()->create(['account_id' => $amex->id, 'as_of' => '2025-06-30', 'balance_pence' => 15000]);
        $gap = collect(TodoList::build()['items'])->firstWhere('group', 'reconcile');
        $this->assertSame('Amex: £3.00 missing', $gap['title']);
        FindingsRegistry::sync();
        // "Not relevant" is kept under the gap as you saw it (its dates and amount: dismiss_key), not its finding's key.
        TodoDismissal::query()->create(['key' => $gap['dismiss_key'], 'until' => null]);
        $this->assertNull(collect(TodoList::build()['items'])->firstWhere('group', 'reconcile'));
        Carbon::setTestNow('2025-07-06 09:00:00');
        FindingsRegistry::sync();
        $this->assertSame('dismissed', Finding::query()->where('group', 'reconcile')->sole()->resolution);

        // The statement figure was mistyped: £50 is missing, not £3. It's back, and it's the same finding, open again.
        Carbon::setTestNow('2025-07-07 09:00:00');
        $june->update(['balance_pence' => 19700]);
        $this->assertSame('Amex: £50.00 missing', collect(TodoList::build()['items'])->firstWhere('group', 'reconcile')['title']);
        FindingsRegistry::sync();
        $f = Finding::query()->where('group', 'reconcile')->sole();
        $this->assertSame([null, 1, -5000], [$f->resolved_at, $f->reopened, $f->amount_pence]);
    }

    public function test_chk_a_statement_of_one_currency_doesnt_count_for_another(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        CarbonImmutable::setTestNow('2026-05-20 10:00:00');
        $gbp = Account::query()->create(['name' => 'Revolut', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $eur = Account::query()->create(['name' => 'Revolut EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $gbp->id]);
        $this->row($gbp, '2026-02-10', -500, 20000, [], $this->batch($gbp, 'feb.csv', ['last_date' => '2026-02-10']));
        $this->row($eur, '2026-03-28', -1000, null, ['original_amount_minor' => -1150, 'original_currency' => 'EUR', 'balance_after_minor' => 10350],
            $this->batch($eur, 'eur-march.csv', ['last_date' => '2026-03-28']));

        // Pounds imported in May: the euro pocket's own statement is still waiting.
        $this->row($gbp, '2026-05-15', -500, 19500, [], $this->batch($gbp, 'gbp-may.csv', ['last_date' => '2026-05-15']));
        $r = Reconciler::account($eur->fresh());
        $this->assertSame([true, '2026-03-28', '2026-03-28'], [$r['stale'], $r['last_line'], $r['last_import']]);
        $todo = collect(TodoList::build()['items'])->firstWhere('key', 'stale:'.$eur->id);
        $this->assertSame('stale:'.$eur->id.':2026-03-28', $todo['dismiss_key'], 'Not relevant until it has new lines');
        $this->assertSame('Revolut EUR: nothing imported since 28 Mar 2026', $todo['title']);
        $this->assertStringContainsString('If you used this pocket after 28 Mar 2026, import its statement', $todo['detail']);

        // And the other way: euros imported in May don't make the pounds up to date.
        $other = Account::query()->create(['name' => 'Revolut 2', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'GBP']);
        $pocket = Account::query()->create(['name' => 'Revolut 2 EUR', 'kind' => 'bank', 'provider' => 'revolut', 'currency' => 'EUR', 'parent_account_id' => $other->id]);
        $this->row($other, '2026-02-10', -500, 20000, [], $this->batch($other, 'feb2.csv', ['last_date' => '2026-02-10']));
        $this->row($pocket, '2026-05-15', -1000, null, ['original_amount_minor' => -1150, 'original_currency' => 'EUR', 'balance_after_minor' => 10350],
            $this->batch($pocket, 'eur-may.csv', ['last_date' => '2026-05-15']));
        $r = Reconciler::account($other->fresh());
        $this->assertSame([true, '2026-02-10'], [$r['stale'], $r['last_import']]);
    }

    public function test_chk_the_history_page_only_works_the_findings_out_again_when_something_changed(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-01', 'status' => 'booked']);
        $this->assertTrue(FindingsRegistry::refresh());
        $this->assertSame(1, Finding::query()->where('key', 'no_po:'.$job->id)->count());

        // Nothing changed: the page view doesn't work every finding out again.
        Carbon::setTestNow('2026-11-01 09:05:00');
        $everything = 0;
        DB::listen(function (QueryExecuted $q) use (&$everything) {
            $everything += (int) str_contains($q->sql, 'work_jobs') && ! str_contains($q->sql, 'UNION ALL');
        });
        $this->assertFalse(FindingsRegistry::refresh());
        $this->assertSame(0, $everything);

        // Another run is recording: the view doesn't wait for it.
        $job->update(['po_number' => 'PO-1']);
        $lock = Cache::lock('findings-sync', 300);
        $this->assertTrue($lock->get());
        $started = microtime(true);
        $this->assertFalse(FindingsRegistry::refresh());
        $this->assertLessThan(2, microtime(true) - $started);
        $lock->release();

        // Something changed: recorded.
        $this->assertTrue(FindingsRegistry::refresh());
        $this->assertSame('resolved', Finding::query()->where('key', 'no_po:'.$job->id)->value('resolution'));
    }

    public function test_chk_a_us_state_is_a_place_and_vat_isnt(): void
    {
        $this->assertSame('Seattle, WA', LineDetails::from([], 'AMAZON WEB SERVICES     SEATTLE     WA')['place']);
        $this->assertSame('New York, NY', LineDetails::from([], 'HOTEL ZETTA      NEW YORK     NY')['place']);
        $this->assertSame('Dallas, TX', LineDetails::from([], 'SHELL   DALLAS   TX')['place']);
        $this->assertSame('Toronto, ON', LineDetails::from([], 'TIM HORTONS    TORONTO    ON')['place']);
        $this->assertNull(LineDetails::from([], 'VAT RETURN      HMRC VAT      VAT')['place']);
        $this->assertNull(LineDetails::from([], 'HMRC          VAT          GB')['place']);

        // Kept places: a US one stays, an unknown code stays (it may be right), a payment's kind goes; rolling back
        // puts back what was cleared.
        $bank = Account::query()->create(['name' => 'Amex', 'kind' => 'credit_card']);
        $us = $this->row($bank, '2025-06-01', -4500, null, ['description' => 'AMAZON WEB SERVICES     SEATTLE     WA', 'place' => 'Seattle, WA']);
        $odd = $this->row($bank, '2025-06-02', -900, null, ['description' => 'SURF SHACK     NOOSA     QXZ', 'place' => 'Noosa, QXZ']);
        $dd = $this->row($bank, '2025-06-03', -350, null, ['description' => 'BRITISH GAS     DIRECT DEBIT    DD', 'place' => 'Direct Debit, DD']);
        $migration = include database_path('migrations/2026_12_01_000600_ledger_repair_line_places_and_times.php');
        $migration->up();
        $this->assertSame(['Seattle, WA', 'Noosa, QXZ', null], [$us->fresh()->place, $odd->fresh()->place, $dd->fresh()->place]);
        $migration->down();
        $this->assertSame('Direct Debit, DD', $dd->fresh()->place);
        $this->assertSame(['place' => 'Direct Debit, DD'], AuditEvent::query()->where('action', 'ledger.line_details_restored')->sole()->after['lines'][$dd->id]);
    }
}
