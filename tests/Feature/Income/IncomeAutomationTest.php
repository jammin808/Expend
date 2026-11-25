<?php

namespace Tests\Feature\Income;

use App\Domain\Ai\AiClient;
use App\Domain\Ai\TransactionAdvisor;
use App\Domain\Import\Importer;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Steps\RelateSweep;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\RuleLearner;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\Suggester;
use App\Domain\Verification\Verifier;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\CycleRunStep;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\MerchantRule;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use InvalidArgumentException;
use Tests\Support\FakeAiClient;
use Tests\TestCase;

/**
 * Build slice S8 (income design v3's automation): the Suggester matches money in through Income\InvoiceMatcher (what's
 * still to come, a set of the payer's client's invoices, or with a fee taken off; never a tie); a rule's and a guess's
 * turnover gets the payer's sole client (PayerAliases::enrich), and RuleLearner's rules too, always as a suggestion for
 * you to accept (owner answer 3: nothing confirms an income link on its own); learning compares a rule's suggestion
 * with the client filled in, so an identical one is left alone; relate.sweep takes a match back once the invoice has
 * nothing, or less than the match, still to come; the Verifier passes a fee taken off and an invoice paid late; and the
 * AI is sent no jobs for money in.
 */
class IncomeAutomationTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private int $n = 0;

    private Client $acme;

    private Client $beta;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->acme = Client::query()->create(['name' => 'Acme Events']);
        $this->beta = Client::query()->create(['name' => 'Beta Productions']);
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

    private function batch(): ImportBatch
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);

        return ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'b'.(++$this->n).'.csv', 'file_sha256' => str_repeat('e', 64), 'stored_path' => 'x', 'status' => 'committed']);
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-28', ?ImportBatch $batch = null): BankTransaction
    {
        $batch ??= $this->batch();
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $batch->account_id, 'import_batch_id' => $batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'automation-'.$this->n]);
    }

    private function invoice(string $number, int $total, ?Client $client = null, ?WorkJob $job = null, string $issued = '2026-05-15'): Invoice
    {
        $client ??= $this->acme;

        return Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $client->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => $client->name, 'issue_date' => $issued, 'due_date' => CarbonImmutable::parse($issued)->addDays(30)->format('Y-m-d')]);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence] + $links;
    }

    /** A payment from the payer you decided as the client's turnover, so the payer is known for it. */
    private function known(Client $client, string $key = 'ACME PAYROLL', string $on = '2026-03-02', int $pence = 10000): BankTransaction
    {
        $tx = $this->tx($pence, $key, $on);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [$this->income($pence, ['client_id' => $client->id])]));

        return $tx;
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:bool}> kind, job, invoice, client, amount, confirmed */
    private function stored(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->invoice_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null])->all();
    }

    private function first(BankTransaction $tx): ?Allocation
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->first();
    }

    public function test_an_import_matches_exact_set_and_net_as_suggestions_and_never_throws(): void
    {
        $show = WorkJob::query()->create(['title' => 'Show A', 'client_id' => $this->acme->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
        $inv42 = $this->invoice('INV-0042', 45000, job: $show, issued: '2026-05-10');
        $inv41 = $this->invoice('INV-0041', 60000, issued: '2026-05-11');
        $inv43 = $this->invoice('INV-0043', 75000, issued: '2026-05-12');
        $inv50 = $this->invoice('INV-0050', 100000, issued: '2026-05-13');
        // ACME PAYROLL pays for Acme (you decided two of its receipts, each with a £30 fee taken off).
        foreach (['2026-03-02' => 100000, '2026-04-02' => 50000] as $on => $gross) {
            $r = $this->tx($gross - 3000, 'ACME PAYROLL', $on);
            app(AllocationWriter::class)->decide($r, [$this->income($gross, ['client_id' => $this->acme->id]),
                ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000, 'client_id' => $this->acme->id]]);
        }

        $batch = $this->batch();
        $exact = $this->tx(45000, 'ACME PAYROLL', '2026-05-28', $batch);
        $set = $this->tx(135000, 'ACME PAYROLL', '2026-05-29', $batch);
        $net = $this->tx(97000, 'ACME PAYROLL', '2026-05-30', $batch);
        app(Importer::class)->afterImport($batch);

        $this->assertSame([['business_income', $show->id, $inv42->id, null, 45000, false]], $this->stored($exact));
        $this->assertSame(['suggest.invoice', 'Matches what’s still to come on INV-0042 (£450)'], [$this->first($exact)->method, $this->first($exact)->reason]);
        $this->assertSame([['business_income', null, $inv41->id, null, 60000, false], ['business_income', null, $inv43->id, null, 75000, false]], $this->stored($set));
        $this->assertSame(['suggest.invoice_set', 'INV-0041 + INV-0043 add up to £1,350'], [$this->first($set)->method, $this->first($set)->reason]);
        $this->assertSame([['business_income', null, $inv50->id, null, 100000, false], ['business_expense', null, null, $this->acme->id, -3000, false]], $this->stored($net));
        $this->assertSame('suggest.invoice_net', $this->first($net)->method);
        $this->assertStringContainsString('£30 taken off as a fee, like the last 2 payments from ACME PAYROLL', (string) $this->first($net)->reason);
        $this->assertSame(['suggested', 'suggested', 'suggested'], [$exact->fresh()->review_status, $set->fresh()->review_status, $net->fresh()->review_status]);
    }

    public function test_a_tie_is_never_guessed_and_your_rule_has_its_turn(): void
    {
        $this->invoice('INV-0101', 50000);
        $this->invoice('INV-0102', 50000, $this->beta);
        MerchantRule::query()->create(['merchant_key' => 'NEW CREW LTD', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => false]);
        $pay = $this->tx(50000, 'NEW CREW LTD');

        app(Suggester::class)->suggestFor(collect([$pay]));

        $this->assertSame([['business_income', null, null, null, 50000, false]], $this->stored($pay), 'Two £500 invoices and a payer not known: left for the chips');
        $this->assertSame(['rule', 'rule.owner'], [$this->first($pay)->decided_by, $this->first($pay)->method]);
    }

    public function test_a_rules_and_a_guesss_turnover_get_the_payers_client_and_are_only_suggested(): void
    {
        $known = $this->known($this->acme);
        MerchantRule::query()->create(['merchant_key' => 'ACME PAYROLL', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => true, 'direction' => 'in']);
        $ruled = $this->tx(32100);
        // A payer nobody has told Expend about: its rule still confirms a plain turnover line on its own.
        MerchantRule::query()->create(['merchant_key' => 'SOLO GIG', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => true, 'direction' => 'in']);
        $plain = $this->tx(20000, 'SOLO GIG');

        app(Suggester::class)->suggestFor(collect([$ruled, $plain]));

        $this->assertSame([['business_income', null, null, $this->acme->id, 32100, false]], $this->stored($ruled), 'Your auto-confirm rule, enriched: a suggestion for you to accept');
        $this->assertSame('Your rule for ACME PAYROLL · for Acme Events (from your payments)', $this->first($ruled)->reason);
        $this->assertContains('tx:'.$known->id, $this->first($ruled)->evidence);
        $this->assertSame('suggested', $ruled->fresh()->review_status);
        $this->assertSame([['business_income', null, null, null, 20000, true]], $this->stored($plain), 'A plain turnover rule still confirms');

        // Automation can't accept it either.
        try {
            AuditContext::with('matcher', fn () => app(AllocationWriter::class)->confirm($ruled->fresh()));
            $this->fail('Only you accept an income link');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Only you can accept an income link.', $e->getMessage());
        }

        // A guess (the categoriser's, learned from your decision) gets the client the same way, marked as its own.
        $beta = $this->known($this->beta, 'BETA PAY', '2026-03-03');
        $guessed = $this->tx(15000, 'BETA PAY');
        app(Suggester::class)->suggestFor(collect([$guessed]));
        $this->assertSame([['business_income', null, null, $this->beta->id, 15000, false]], $this->stored($guessed));
        $g = $this->first($guessed);
        $this->assertSame(['matcher', Categoriser::MARKER], [$g->decided_by, $g->matcher]);
        $this->assertStringStartsWith('categorise.', (string) $g->method);
        $this->assertStringEndsWith(' · for Beta Productions (from your payments)', (string) $g->reason);
        $this->assertContains('tx:'.$beta->id, $g->evidence);

        // A payer that has paid for two clients is shared: nothing is filled in.
        $this->known($this->acme, 'BETA PAY', '2026-03-04');
        $shared = $this->tx(15000, 'BETA PAY');
        app(Suggester::class)->suggestFor(collect([$shared]));
        $this->assertNull($this->first($shared)?->client_id);
    }

    public function test_accepting_a_turnover_rule_fills_in_the_client_for_you_to_accept(): void
    {
        $this->known($this->acme, 'ACME PAYROLL', '2026-03-02');
        $this->known($this->acme, 'ACME PAYROLL', '2026-04-02');
        $waiting = $this->tx(25000);

        $offer = app(RuleLearner::class)->offer('ACME PAYROLL');
        $this->assertSame(['id' => $this->acme->id, 'name' => 'Acme Events'], $offer['client']);
        $this->assertStringContainsString('From now on payments from ACME PAYROLL are filled in as Acme Events’ turnover, for you to accept.', $offer['text']);
        $this->assertSame($offer['text'], app(RuleLearner::class)->offers()[0]['text'], 'The to-do list words it the same');

        $r = app(RuleLearner::class)->accept('ACME PAYROLL');

        $this->assertSame([1, 'Acme Events'], [$r['applied'], $r['client']]);
        $this->assertStringContainsString('for you to accept', $r['message']);
        $this->assertSame([['business_income', null, null, $this->acme->id, 25000, false]], $this->stored($waiting));
        $this->assertSame('rule', $this->first($waiting)->decided_by);
        $this->assertSame('Your rule for ACME PAYROLL · for Acme Events (from your payments)', $this->first($waiting)->reason);
    }

    public function test_learning_leaves_an_identical_enriched_rule_suggestion_alone_and_redoes_one_whose_client_changed(): void
    {
        $this->known($this->acme);
        MerchantRule::query()->create(['merchant_key' => 'ACME PAYROLL', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => true, 'direction' => 'in']);
        $waiting = $this->tx(20000, 'ACME PAYROLL', '2026-05-20');
        app(Suggester::class)->suggestFor(collect([$waiting]));
        $ids = Allocation::query()->where('bank_transaction_id', $waiting->id)->pluck('id')->all();
        $this->assertSame($this->acme->id, $this->first($waiting)->client_id);

        // You decide another ACME payment as Acme's: the waiting suggestion already says so.
        $decided = $this->tx(30000, 'ACME PAYROLL', '2026-05-21');
        app(AllocationWriter::class)->decide($decided, [$this->income(30000, ['client_id' => $this->acme->id])]);
        $events = AuditEvent::query()->count();
        $this->assertSame(0, app(Categoriser::class)->learnFrom(collect([$decided])));
        $this->assertSame($ids, Allocation::query()->where('bank_transaction_id', $waiting->id)->pluck('id')->all(), 'Same lines');
        $this->assertSame($events, AuditEvent::query()->count(), 'Nothing written, nothing recorded');

        // Then one for Beta: the payer is shared now, so the client comes off the waiting suggestion.
        $other = $this->tx(30000, 'ACME PAYROLL', '2026-05-22');
        app(AllocationWriter::class)->decide($other, [$this->income(30000, ['client_id' => $this->beta->id])]);
        $this->assertSame(1, app(Categoriser::class)->learnFrom(collect([$other])));
        $this->assertSame([['business_income', null, null, null, 20000, true]], $this->stored($waiting), 'Your plain turnover rule, confirmed as it always was');
    }

    public function test_learning_picks_up_an_invoice_once_the_payer_is_known(): void
    {
        $acmes = $this->invoice('INV-0201', 50000);
        $this->invoice('INV-0202', 50000, $this->beta);
        $waiting = $this->tx(50000, 'ACME PAYROLL', '2026-05-20');
        app(Suggester::class)->suggestFor(collect([$waiting]));
        $this->assertNull($this->first($waiting)?->invoice_id, 'Tied, and the payer isn’t known');

        $decided = $this->known($this->acme, 'ACME PAYROLL', '2026-05-21');
        app(Categoriser::class)->learnFrom(collect([$decided]));

        $this->assertSame([['business_income', null, $acmes->id, null, 50000, false]], $this->stored($waiting));
        $this->assertSame('suggest.invoice', $this->first($waiting)->method);
    }

    public function test_relate_sweep_takes_a_match_back_once_the_invoice_is_closed_paid_or_has_less_to_come(): void
    {
        $writer = app(AllocationWriter::class);
        $match = function (BankTransaction $tx, Invoice $invoice, int $pence) use ($writer) {
            $this->assertTrue($writer->decide($tx, [$this->income($pence, ['invoice_id' => $invoice->id])], 'matcher', false,
                'Matches what’s still to come on '.$invoice->number, null, new Provenance('suggest.invoice', ['inv:'.$invoice->id])));
        };
        $closed = $this->invoice('INV-0301', 45000);
        $paid = $this->invoice('INV-0302', 45000);
        $part = $this->invoice('INV-0303', 100000);
        $fits = $this->invoice('INV-0304', 100000);
        $match($a = $this->tx(45000, 'PAYER A'), $closed, 45000);
        $match($b = $this->tx(45000, 'PAYER B'), $paid, 45000);
        $match($c = $this->tx(100000, 'PAYER C'), $part, 100000);
        $match($d = $this->tx(60000, 'PAYER D'), $fits, 60000);
        $this->assertSame(0, app(RelateSweep::class)->run(new CycleContext)->counts['swept'], 'Every match still fits');

        // Closed short (as InvoiceClosing records it), paid by a payment you confirmed, and £400 of £1,000 paid.
        $closed->forceFill(['closed_minor' => 45000, 'closed_reason' => 'wont_chase', 'closed_at' => now()])->save();
        $writer->decide($this->tx(45000, 'PAYER E'), [$this->income(45000, ['invoice_id' => $paid->id])]);
        $writer->decide($this->tx(40000, 'PAYER F'), [$this->income(40000, ['invoice_id' => $part->id])]);
        $writer->decide($this->tx(40000, 'PAYER G'), [$this->income(40000, ['invoice_id' => $fits->id])]);
        $counts = app(RelateSweep::class)->run(new CycleContext)->counts;

        $this->assertSame(3, $counts['paid_invoice']);
        $this->assertSame([[], [], []], [$this->stored($a), $this->stored($b), $this->stored($c)]);
        $this->assertCount(1, $this->stored($d), '£600 waiting for the £600 still to come still fits');
        $reason = fn (BankTransaction $t) => (string) AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $t->id)->value('reason');
        $this->assertSame('Invoice INV-0301 has been closed, so this payment isn’t for it: it’s back for you to decide', $reason($a));
        $this->assertStringContainsString('INV-0302 has been paid by other payments you confirmed', $reason($b));
        $this->assertSame('INV-0303 has only £600 still to come now, so this match no longer fits: it’s back for you to decide', $reason($c));
        $this->assertSame('matcher', AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $c->id)->value('actor'));
    }

    public function test_a_trial_night_takes_nothing_back(): void
    {
        $invoice = $this->invoice('INV-0401', 45000);
        $pay = $this->tx(45000, 'PAYER A');
        app(AllocationWriter::class)->decide($pay, [$this->income(45000, ['invoice_id' => $invoice->id])], 'matcher', false, 'Matches', null, new Provenance('suggest.invoice', ['inv:'.$invoice->id]));
        app(AllocationWriter::class)->decide($this->tx(45000, 'PAYER B'), [$this->income(45000, ['invoice_id' => $invoice->id])]);
        Setting::put('intel_cycle', 'shadow');
        Bus::fake();

        $runner = app(CycleRunner::class);
        $night = $runner->start('nightly', deadline: CarbonImmutable::now()->addHour());
        for ($i = 0; $i < 60 && ! $night->fresh()->isFinished(); $i++) {
            $runner->work($night->id);
        }

        $this->assertSame(['skipped', 'shadow'], [CycleRunStep::query()->where('cycle_run_id', $night->id)->where('step', RelateSweep::KEY)->value('status'),
            CycleRunStep::query()->where('cycle_run_id', $night->id)->where('step', RelateSweep::KEY)->first()?->counts['skipped']]);
        $this->assertCount(1, $this->stored($pay), 'Only recorded: the match stays for the real run');
    }

    public function test_the_verifier_passes_a_fee_taken_off_and_an_invoice_paid_long_after_the_job(): void
    {
        $job = WorkJob::query()->create(['title' => 'Winter show', 'client_id' => $this->acme->id, 'starts_on' => '2025-11-10', 'ends_on' => '2025-11-10', 'status' => 'done']);
        $invoice = $this->invoice('INV-0501', 100000, job: $job, issued: '2025-11-20');
        $late = $this->tx(100000, 'ACME PAYROLL', '2026-05-28'); // over 120 days after the job, but after the invoice
        app(AllocationWriter::class)->decide($late, [$this->income(100000, ['invoice_id' => $invoice->id])], 'matcher', false, 'Matches', null, new Provenance('suggest.invoice', ['inv:'.$invoice->id]));
        $netted = $this->tx(97000, 'ACME PAYROLL', '2025-12-01');
        app(AllocationWriter::class)->decide($netted, [$this->income(100000, ['work_job_id' => $job->id]),
            ['kind' => 'business_expense', 'category_id' => $this->cat('finance'), 'amount_pence' => -3000, 'work_job_id' => $job->id]], 'matcher', false, 'Fee', null, new Provenance('suggest.invoice_net'));

        $checked = app(Verifier::class)->transaction($late);
        $this->assertSame('verified', $checked->status, json_encode($checked->checks));
        $this->assertContains(['what' => 'Invoice: INV-0501', 'ok' => true, 'note' => 'The payment date fits the invoice'], $checked->checks);
        $fee = app(Verifier::class)->transaction($netted);
        $this->assertSame('verified', $fee->status, json_encode($fee->checks));

        // An invoice voided since, or a payment long before the invoice, is flagged.
        $early = $this->invoice('INV-0502', 20000, issued: '2026-05-15');
        $tooEarly = $this->tx(20000, 'ACME PAYROLL', '2026-02-01');
        app(AllocationWriter::class)->decide($tooEarly, [$this->income(20000, ['invoice_id' => $early->id])], 'matcher', false, 'Matches', null, new Provenance('suggest.invoice', ['inv:'.$early->id]));
        $this->assertContains(['what' => 'Invoice: INV-0502', 'ok' => false, 'note' => 'The payment came long before the invoice'], app(Verifier::class)->transaction($tooEarly)->checks);
    }

    public function test_the_ai_is_sent_no_jobs_for_money_in(): void
    {
        Setting::put('ai_suggestions', true);
        $ai = new FakeAiClient;
        $this->app->instance(AiClient::class, $ai);
        $job = WorkJob::query()->create(['title' => 'Arena load-in', 'client_id' => $this->acme->id, 'starts_on' => '2026-05-28', 'ends_on' => '2026-05-28', 'status' => 'done']);
        $in = $this->tx(45000, 'ACME PAYROLL', '2026-05-28');
        $out = $this->tx(-2500, 'KIT HIRE LTD', '2026-05-28');
        $ai->responses = [FakeAiClient::json(['suggestions' => [
            ['id' => $in->id, 'kind' => 'business_income', 'category_code' => 'turnover', 'job_id' => $job->id, 'business_pct' => 100, 'reason' => 'Fee'],
            ['id' => $out->id, 'kind' => 'business_expense', 'category_code' => 'equipment', 'job_id' => $job->id, 'business_pct' => 100, 'reason' => 'Kit'],
        ]])];

        app(TransactionAdvisor::class)->suggest();

        $sent = json_decode(substr($ai->requests[0]['messages'][0]['content'], strpos($ai->requests[0]['messages'][0]['content'], "Transactions:\n") + 14), true);
        $byId = collect($sent)->keyBy('id');
        $this->assertSame([], $byId[$in->id]['jobs_around_date'], 'No jobs for money in');
        $this->assertSame([$job->id], array_column($byId[$out->id]['jobs_around_date'], 'id'));
        $this->assertSame([['business_income', null, null, null, 45000, false]], $this->stored($in), 'Kind and category only');
        $this->assertSame($job->id, $this->first($out)->work_job_id);
    }

    public function test_relate_sweep_keeps_a_match_to_a_still_issued_invoice_of_a_job_cancelled_with_nothing_owed(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme arena', 'client_id' => $this->acme->id, 'starts_on' => '2026-05-10', 'ends_on' => '2026-05-10', 'status' => 'done']);
        $invoice = $this->invoice('INV-0601', 45000, job: $job);
        // Cancelled with nothing owed, but its issued invoice stays (JobStatus::cancel keeps it: void it only if wrong).
        $job->update(['status' => 'cancelled', 'cancel_charge' => 'none']);
        $pay = $this->tx(45000, 'ACME EVENTS LTD');
        $guess = $this->tx(30000, 'SOMEONE');
        app(AllocationWriter::class)->decide($guess, [$this->income(30000, ['work_job_id' => $job->id])], 'matcher', false, 'Looks like the fee', null,
            new Provenance('jobs.gather', ['job:'.$job->id]));

        for ($night = 1; $night <= 3; $night++) {
            app(Suggester::class)->suggestFor(collect([$pay->fresh()]));
            app(RelateSweep::class)->run(new CycleContext);
        }

        $this->assertSame([['business_income', $job->id, $invoice->id, null, 45000, false]], $this->stored($pay), 'The match to an invoice still owed stays');
        $this->assertSame(1, AuditEvent::query()->where('subject_id', $pay->id)->whereIn('action', ['allocation.suggested', 'allocation.cleared'])->count(), 'No nightly suggest and clear');
        $this->assertSame([], $this->stored($guess), 'A guess on the job itself is still taken back');

        // Once the invoice is voided, the match goes as before.
        $invoice->update(['status' => 'void']);
        app(RelateSweep::class)->run(new CycleContext);
        $this->assertSame([], $this->stored($pay));
    }

    public function test_the_verifier_passes_a_job_cancelled_with_a_fee_or_costs_still_owed(): void
    {
        $job = fn (string $title, ?string $charge) => WorkJob::query()->create(['title' => $title, 'client_id' => $this->acme->id, 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-20',
            'status' => 'cancelled', 'cancel_charge' => $charge, 'cancel_fee_pence' => $charge === 'fee' ? 15000 : null]);
        $check = function (WorkJob $j, int $pence) {
            $pay = $this->tx($pence, 'ACME EVENTS LTD');
            $this->assertTrue(app(AllocationWriter::class)->decide($pay, [$this->income($pence, ['work_job_id' => $j->id])], 'ai', false,
                'For '.$j->title, null, new Provenance('ai.assistant', ['job:'.$j->id])));

            return collect(app(Verifier::class)->transaction($pay)->checks)->firstWhere('what', 'Job: '.$j->title);
        };

        $this->assertSame(['what' => 'Job: Fee owed', 'ok' => true, 'note' => 'The job was cancelled with a fee to charge'], $check($job('Fee owed', 'fee'), 15000));
        $this->assertSame(['what' => 'Job: Costs owed', 'ok' => true, 'note' => 'The job was cancelled with its costs to charge'], $check($job('Costs owed', 'costs'), 8000));
        $this->assertSame(['what' => 'Job: Nothing owed', 'ok' => false, 'note' => 'The job is cancelled'], $check($job('Nothing owed', 'none'), 9000));
    }
}
