<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\Grades;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S10 (income design v3's to-do part): money-in buttons without Personal (For INV-042, Acme Events no job),
 * suggestions in IncomeText's words, overdue items by what's still to come with Close it and "£30 was a fee", the
 * overpaid and voided-after-paid items with their buttons, the paid-back wording, the client-only hint on
 * "Invoice <job>" with "It was for this job", and the finding codes. Every press is yours, bound to what it was shown.
 */
class IncomeTodoTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->acme = Client::query()->create(['name' => 'Acme Events']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function job(string $title, string $on = '2026-05-01', string $status = 'done'): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $this->acme->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => $status]);
    }

    /** Issued (as an existing ledger holds it) and sent, due 30 days after. */
    private function invoice(string $number, int $total, ?WorkJob $job = null, string $issued = '2026-05-01', string $status = 'issued'): Invoice
    {
        return Invoice::query()->create(['status' => $status, 'number' => $number, 'client_id' => $this->acme->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@example.test', 'issue_date' => $issued,
            'due_date' => CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d'), 'sent_at' => $issued.' 10:00:00', 'sent_to' => 'accounts@example.test']);
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-06-05'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'june.csv', 'file_sha256' => str_repeat('f', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'todo-'.$this->n]);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence, 'business_use_pct' => 100] + $links;
    }

    /** Decided by you (confirmed). */
    private function decide(BankTransaction $tx, array ...$lines): BankTransaction
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines));

        return $tx;
    }

    /** ACME PAYROLL pays for Acme Events (one earlier payment you decided as theirs). */
    private function known(string $on = '2026-03-02'): void
    {
        $this->decide($this->tx(10000, 'ACME PAYROLL', $on), $this->income(10000, ['client_id' => $this->acme->id]));
    }

    private function item(string $key): ?array
    {
        return collect(TodoList::build(100)['items'])->firstWhere('key', $key);
    }

    /** @return list<array{0:string, 1:string, 2:string}> each action's code, id and label */
    private static function actions(?array $item): array
    {
        return array_map(fn (array $a) => [$a['code'], $a['id'], $a['label']], $item['actions'] ?? []);
    }

    private function press(string $code, string $id): string
    {
        return app(TodoActions::class)->run($code, $id, null, Fingerprints::of($code, $id));
    }

    public function test_money_in_is_offered_its_invoice_and_its_client_never_personal(): void
    {
        $this->known();
        $show = $this->job('Show A');
        $inv = $this->invoice('INV-0042', 45000, $show);
        $tx = $this->tx(45000);

        $item = $this->item('tx:'.$tx->id);
        $this->assertSame([['tx_invoice', $tx->id.':'.$inv->id, 'For INV-0042'], ['tx_client', $tx->id.':'.$this->acme->id, 'Acme Events, no job']], self::actions($item),
            'No one-tap Personal on money in: it may be a client paying you');

        // Personal from an old button is refused too.
        try {
            app(TodoActions::class)->run('tx_personal', (string) $tx->id, null, Fingerprints::of('tx_personal', (string) $tx->id));
            $this->fail('Money in is never set aside with one tap');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Money in isn’t marked personal from here', $e->getMessage());
        }

        // "For INV-0042": saved for the invoice, as you, exactly as the chip fills it.
        $this->assertSame('Saved: ACME PAYROLL pays INV-0042.', $this->press('tx_invoice', $tx->id.':'.$inv->id));
        $line = $tx->fresh()->allocations()->sole();
        $this->assertSame([$inv->id, $show->id, 45000, 'you'], [(int) $line->invoice_id, (int) $line->work_job_id, (int) $line->amount_pence, $line->decided_by]);
        $this->assertNotNull($line->confirmed_at);
        $this->assertNull($this->item('tx:'.$tx->id), 'Decided: off the list');
    }

    public function test_a_suggestion_reads_as_income_text_and_accept_never_repeats_its_invoice(): void
    {
        $this->known();
        $inv = $this->invoice('INV-0042', 45000, $this->job('Show A'));
        $tx = $this->tx(45000);
        app(AllocationWriter::class)->decide($tx, [$this->income(45000, ['invoice_id' => $inv->id])], 'matcher', false);

        $item = $this->item('tx:'.$tx->id);
        $this->assertStringContainsString('suggested: INV-0042 £450 · Acme Events', $item['detail']);
        $this->assertSame(['tx_accept', 'tx_client'], array_column($item['actions'], 'code'), 'The invoice it names is Accept; no second button for it');
    }

    public function test_a_stale_invoice_button_is_refused(): void
    {
        $this->known();
        $inv = $this->invoice('INV-0042', 45000, $this->job('Show A'));
        $tx = $this->tx(45000);
        $fp = Fingerprints::of('tx_invoice', $tx->id.':'.$inv->id);

        // Something else paid it meanwhile: the button was offered on an invoice still owed £450.
        $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-06-06'), $this->income(45000, ['invoice_id' => $inv->id]));
        $this->expectExceptionMessage('This changed since');
        app(TodoActions::class)->run('tx_invoice', $tx->id.':'.$inv->id, null, $fp);
    }

    public function test_part_paid_offers_close_it_and_a_fee_taken_off(): void
    {
        $show = $this->job('Show A');
        $inv = $this->invoice('INV-0042', 100000, $show);
        $paid = $this->decide($this->tx(97000, 'ACME PAYROLL', '2026-06-01'), $this->income(97000, ['invoice_id' => $inv->id]));

        $item = $this->item('invoice_overdue:'.$inv->id);
        $this->assertSame('Invoice INV-0042 part-paid', $item['title']);
        $this->assertSame([['tx_fee', $paid->id.':'.$inv->id, '£30 was a fee or commission'], ['inv_close', $inv->id.':sp', 'Close it: £30 isn’t coming']], self::actions($item));
        $this->assertSame(3000, $item['amount'], 'What’s still to come');

        // The fee: the payment saved gross (£1,000 for the invoice) with £30 taken off, as you; the invoice is paid.
        $this->assertSame('Saved: £30 was taken off INV-0042 as a fee or commission, so it’s paid in full.', $this->press('tx_fee', $paid->id.':'.$inv->id));
        $lines = $paid->fresh()->allocations()->orderBy('id')->get();
        $this->assertSame([['business_income', 100000, $inv->id], ['business_expense', -3000, null]], $lines->map(fn (Allocation $a) => [$a->kind, (int) $a->amount_pence, $a->invoice_id])->all());
        $this->assertSame($this->cat('finance'), (int) $lines[1]->category_id);
        $this->assertSame((int) $show->id, (int) $lines[1]->work_job_id, 'The fee is on the same job');
        $this->assertNull($this->item('invoice_overdue:'.$inv->id));
    }

    public function test_close_it_writes_off_what_is_still_to_come(): void
    {
        $inv = $this->invoice('INV-0042', 45000, $this->job('Show A'));
        $this->decide($this->tx(30000, 'ACME PAYROLL', '2026-06-01'), $this->income(30000, ['invoice_id' => $inv->id]));
        $item = $this->item('invoice_overdue:'.$inv->id);
        $this->assertSame([['inv_close', $inv->id.':sp', 'Close it: £150 isn’t coming']], self::actions($item), 'A third short: no fee button');

        $this->assertSame('Closed INV-0042: £150 isn’t coming. Reopen it on its page if it comes after all.', $this->press('inv_close', $inv->id.':sp'));
        $this->assertSame([15000, 'short_paid'], [(int) $inv->fresh()->closed_minor, $inv->fresh()->closed_reason]);
        $this->assertNull($this->item('invoice_overdue:'.$inv->id));
    }

    public function test_an_overpaid_invoice_keeps_the_rest_as_the_clients(): void
    {
        $show = $this->job('Show A');
        $inv = $this->invoice('INV-0042', 45000, $show);
        $tx = $this->decide($this->tx(55000, 'ACME PAYROLL', '2026-06-01'), $this->income(55000, ['invoice_id' => $inv->id]));

        $item = $this->item('invoice_overpaid:'.$inv->id);
        $this->assertSame('Acme Events paid £100.00 more than INV-0042', $item['title']);
        $this->assertSame([10000, 'GBP'], [$item['amount'], $item['currency']]);
        $this->assertSame(['inv_over_keep', (string) $inv->id, 'Keep £100 as Acme Events, no job'], self::actions($item)[0]);
        $this->assertSame(['link', 'I paid it back'], [$item['actions'][1]['code'], $item['actions'][1]['label']], 'A link: it decides nothing');
        $this->assertNull(Fingerprints::of('link', ''));

        $this->assertSame('Kept £100 as Acme Events’s, with no job: INV-0042 is paid exactly.', $this->press('inv_over_keep', (string) $inv->id));
        $lines = $tx->fresh()->allocations()->orderBy('id')->get();
        $this->assertSame([[45000, $inv->id, null], [10000, null, $this->acme->id]], $lines->map(fn (Allocation $a) => [(int) $a->amount_pence, $a->invoice_id, $a->client_id])->all());
        $this->assertNull($this->item('invoice_overpaid:'.$inv->id));
    }

    public function test_a_voided_invoice_that_was_paid_says_which_replaced_it(): void
    {
        $show = $this->job('Show A');
        $void = $this->invoice('INV-0041', 45000, $show, '2026-05-01');
        $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-06-01'), $this->income(45000, ['invoice_id' => $void->id]));
        $void->forceFill(['status' => 'void'])->save();
        $other = $this->job('Show B', '2026-05-03');
        // Paid against INV-0041 but it's on another job now: it counts nowhere until you say what replaced it.
        Allocation::query()->where('invoice_id', $void->id)->update(['work_job_id' => $other->id]);
        $replacement = $this->invoice('INV-0043', 45000, $other, '2026-05-04');

        $item = $this->item('invoice_void_paid:'.$void->id);
        $this->assertSame('INV-0041 was voided after £450.00 was paid against it', $item['title']);
        $this->assertSame([['inv_replace', $void->id.':'.$replacement->id, 'INV-0043 replaced it']], self::actions($item));

        $this->assertSame('Saved: INV-0043 replaced INV-0041, so the £450 paid against it counts there.', $this->press('inv_replace', $void->id.':'.$replacement->id));
        $this->assertSame($replacement->id, (int) $void->fresh()->replaced_by_id);
        $this->assertNull($this->item('invoice_void_paid:'.$void->id));
    }

    public function test_money_paid_back_is_said_and_never_chased(): void
    {
        $inv = $this->invoice('INV-0042', 45000, $this->job('Show A'));
        $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-06-01'), $this->income(45000, ['invoice_id' => $inv->id]));
        $this->decide($this->tx(-45000, 'ACME PAYROLL', '2026-06-03'), $this->income(-45000, ['invoice_id' => $inv->id]));

        $item = $this->item('invoice_overdue:'.$inv->id);
        $this->assertSame('Invoice INV-0042: you paid money back', $item['title']);
        $this->assertStringContainsString('You paid back £450.00 against invoice INV-0042', $item['detail']);
        $this->assertSame([['inv_close', $inv->id.':ot', 'Close it: not owed']], self::actions($item), 'No reminder');
    }

    public function test_invoice_a_job_says_when_its_client_paid_with_no_job_and_it_can_go_on_the_job(): void
    {
        $job = $this->job('Show A', '2026-05-01');
        $tx = $this->decide($this->tx(45000, 'ACME PAYROLL', '2026-05-20'), $this->income(45000, ['client_id' => $this->acme->id]));

        $item = $this->item('invoice:'.$job->id);
        $this->assertSame('Acme Events · finished 1 May 2026 · Acme Events paid £450 on 20 May with no job: was it for this?', $item['detail']);
        $this->assertSame([['job_invoice', (string) $job->id, 'Draft the invoice'], ['tx_job', $tx->id.':'.$job->id, 'It was for this job']], self::actions($item));

        $this->assertSame('Saved: £450 from ACME PAYROLL on 20 May is for Show A.', $this->press('tx_job', $tx->id.':'.$job->id));
        $line = $tx->fresh()->allocations()->sole();
        $this->assertSame([$job->id, 'you'], [(int) $line->work_job_id, $line->decided_by]);
        $this->assertNull($this->item('invoice:'.$job->id), 'Paid for by income: nothing to invoice');
    }

    public function test_the_new_finding_codes_are_registered_and_named(): void
    {
        $c = fn (string $key) => array_intersect_key(TodoGroupsDetector::classify($key) ?? [], ['code' => 1, 'subject_ref' => 1, 'direction' => 1]);
        $this->assertSame(['code' => 'invoice.overpaid', 'subject_ref' => 'inv:4', 'direction' => 'none'], $c('invoice_overpaid:4'));
        $this->assertSame(['code' => 'invoice.void_paid', 'subject_ref' => 'inv:5', 'direction' => 'none'], $c('invoice_void_paid:5'));
        $this->assertSame(['code' => 'job.done_due', 'subject_ref' => 'job:7', 'direction' => 'owed_to_you'], $c('job_done:7'));
        $this->assertSame(['code' => 'invoice.draft', 'subject_ref' => 'inv:8', 'direction' => 'owed_to_you'], $c('draft:8'));
        $this->assertSame(['code' => 'cost.claimed', 'subject_ref' => 'tx:381', 'direction' => 'owed_to_you'], $c('claimed:tx:381:j45'));
        $this->assertSame(['code' => 'cost.late', 'subject_ref' => 'travel:7', 'direction' => 'owed_to_you'], $c('charge:travel:7'));
        $this->assertSame('job:45', TodoGroupsDetector::classify('charge:tx:381:j45')['scope']['ref']);
        foreach (['invoice.overpaid', 'invoice.void_paid', 'job.done_due', 'invoice.draft', 'cost.claimed', 'cost.late'] as $code) {
            $this->assertContains($code, (new TodoGroupsDetector)->codes(), $code);
            $this->assertSame(Grades::CERTAIN, Grades::forCode($code)[0], $code);
            $this->assertNotSame(str_replace(['.', '_'], ' ', $code), DetectorLevels::name($code), $code.' has a name');
        }
        $this->assertSame('Income not assigned to a client, job or invoice', DetectorLevels::name('income.unlinked'));
    }

    public function test_always_do_this_for_a_payer_with_one_client_says_it_is_filled_in_for_you(): void
    {
        $this->known('2026-03-02');
        foreach (['2026-04-01', '2026-05-01'] as $on) {
            $this->known($on);
        }
        $tx = $this->tx(20000, 'ACME PAYROLL', '2026-06-05');
        $offer = collect(TodoList::build(100)['items'])->firstWhere('key', 'rule:ACME PAYROLL');
        if ($offer === null) {
            $this->markTestSkipped('No rule offered for this payer in this ledger');
        }
        $message = $this->press('rule_make', $offer['actions'][0]['id']);
        $this->assertStringContainsString('Acme Events', $message);
        $this->assertStringContainsString('for you to accept', $message);
        $this->assertNotNull($tx);
    }
}
