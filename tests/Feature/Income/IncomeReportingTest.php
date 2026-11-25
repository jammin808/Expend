<?php

namespace Tests\Feature\Income;

use App\Domain\Ai\AssistantTools;
use App\Domain\Export\YearExporter;
use App\Domain\Income\Lines;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\IncomeUnlinked;
use App\Domain\Intel\FindingDraft;
use App\Domain\Invoices\InvoiceClosing;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\Explainer;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\TaxPot;
use App\Domain\Tax\TaxSavings;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Build slice S11 (income design v3's reporting part): income not assigned to a client, job or invoice (IncomeUnlinked
 * v2: client-only income is done once its client is set); the top client's share by worked-out client; the tax pot's
 * message net of a fee, naming the client; the year spreadsheet's Client and Invoice columns, appended; the map's
 * per-line amounts and its flags by invoice state and JobBilling; the assistant's invoice states, client filter and
 * lines_total, and propose_decision's lines, saved unconfirmed; explain_payment naming the invoice and client; and the
 * cash-basis note. None of it changes a tax figure.
 */
class IncomeReportingTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 10:00:00');
        CarbonImmutable::setTestNow('2026-11-01 10:00:00');
        $this->signIn();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, FindingDraft> */
    private function unlinked(): array
    {
        return collect((new IncomeUnlinked)->detect(new CycleContext))->keyBy('key')->all();
    }

    // ---------------------------------------------------------------- IncomeUnlinked v2

    public function test_client_only_income_is_assigned_a_splits_loose_part_is_flagged_and_a_finding_resolves_once_the_client_is_set(): void
    {
        $acme = $this->client();
        $show = $this->job('Show A', $acme);
        $forClient = $this->tx(40000);
        $this->decide($forClient, [$this->income(40000, ['client_id' => $acme->id])]);
        $split = $this->tx(100000, 'STAGE CO');
        $this->decide($split, [$this->income(70000, ['work_job_id' => $show->id]), $this->income(30000)]);
        $loose = $this->tx(25000, 'ACME EVENTS LTD');
        $this->decide($loose, [$this->income(25000)]);

        $found = $this->unlinked();
        $this->assertSame(['unlinked:'.$split->id, 'unlinked:'.$loose->id], array_keys($found), 'Client-only income is done once the client is set');
        $this->assertSame(30000, $found['unlinked:'.$split->id]->amountPence, 'Only the unassigned part of the split');
        $this->assertSame(IncomeUnlinked::VERSION, $found['unlinked:'.$loose->id]->detectorVersion);

        // Recorded, then the client is set: the same key resolves.
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'unlinked:'.$loose->id)->sole();
        $this->assertSame('open', $finding->state);
        $this->decide($loose, [$this->income(25000, ['client_id' => $acme->id])]);
        FindingsRegistry::sync();
        $this->assertSame('resolved', $finding->fresh()->state);
        $this->assertSame(['unlinked:'.$split->id], array_keys($this->unlinked()));
    }

    // ---------------------------------------------------------------- TaxSavings and TaxPot

    public function test_the_top_clients_share_counts_client_only_income_and_merged_duplicates(): void
    {
        $acme = $this->client();
        $old = $this->client('Acme Events Ltd');
        $old->forceFill(['merged_into_id' => $acme->id])->save();
        $other = $this->client('Stage Co');
        $this->decide($this->tx(300000, 'ACME'), [$this->income(300000, ['client_id' => $acme->id])]);
        $this->decide($this->tx(300000, 'ACME LTD'), [$this->income(300000, ['client_id' => $old->id])]);
        $this->decide($this->tx(200000, 'ACME', '2026-06-01'), [$this->income(200000, ['work_job_id' => $this->job('Gala', $acme)->id])]);
        $this->decide($this->tx(100000, 'STAGE CO'), [$this->income(100000, ['client_id' => $other->id])]);
        $before = ReturnSummary::for('2026-27');

        $review = TaxSavings::review('2026-27');
        $item = collect([...$review['items'], ...$review['more']])->firstWhere('id', 'employment-status-evidence');
        // £8,000 of £9,000 is Acme's, counted as one client: 89%. With jobs only, it would have been 22%, under the 75% line.
        $this->assertStringStartsWith('Acme Events paid 89% of your income this year.', $item['finding']);
        $this->assertSame($before['turnover_pence'], ReturnSummary::for('2026-27')['turnover_pence'], 'No tax figure changes');
        $this->assertSame(900000, $before['turnover_pence']);
    }

    public function test_the_tax_pot_message_says_what_came_in_net_of_a_fee_and_names_the_worked_out_client(): void
    {
        $acme = $this->client();
        $old = $this->client('Acme Events Ltd');
        $old->forceFill(['merged_into_id' => $acme->id])->save();
        $tx = $this->tx(97000, 'ACME PAYROLL 1234567');
        $this->decide($tx, [$this->income(100000, ['client_id' => $old->id]), Lines::deduction(3000, $this->cat('finance'), null, $old->id)]);

        $pot = new TaxPot(fn (string $label) => ['turnover_pence' => 4_000_000, 'net_profit_pence' => 3_600_000],
            fn (string $label) => ['income_pence' => 0, 'costs_pence' => 0, 'undecided_in_pence' => 0]);
        $aside = $pot->forPayment(97000, $tx->posted_on)['pence'];
        $message = $pot->importMessage([$tx->import_batch_id]);

        $this->assertStringStartsWith('Acme Events paid £970: put about '.TaxPot::pounds($aside).' aside (estimate).', $message);
        $this->assertStringNotContainsString('1234567', $message);
    }

    // ---------------------------------------------------------------- the year spreadsheet

    public function test_the_year_spreadsheet_appends_client_and_invoice_and_names_a_deduction(): void
    {
        $acme = $this->client();
        $show = $this->job('Show A', $acme);
        $invoice = $this->invoice('INV-0041', 100000, $show, $acme);
        $tx = $this->tx(97000, 'ACME EVENTS LTD');
        $this->decide($tx, [$this->income(100000, ['invoice_id' => $invoice->id]), Lines::deduction(3000, $this->cat('finance'), null, $acme->id)]);
        $other = $this->tx(-1500, 'TRAINLINE');
        $this->decide($other, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => -1500]]);

        $path = tempnam(sys_get_temp_dir(), 'yx').'.xlsx';
        YearExporter::write('2026-27', $path);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() === 'Transactions') {
                foreach ($sheet->getRowIterator() as $r) {
                    $rows[] = $r->toArray();
                }
            }
        }
        $reader->close();
        @unlink($path);

        // Every earlier column keeps its place; Client and Invoice come after Note.
        $this->assertSame(['Date', 'Time', 'Account', 'Card', 'Payment type', 'Place', 'Description', 'Amount', 'Original amount', 'Currency',
            'Rate (per £1)', 'Rate used', 'Paid amount', 'Paid currency', 'Decision', 'Category', 'Job', 'Line amount', 'Business %', 'Status', 'Decided by', 'Note',
            'Client', 'Invoice'], $rows[0]);
        $head = array_flip($rows[0]);
        $lines = collect($rows)->filter(fn ($r) => ($r[$head['Description']] ?? null) === 'ACME EVENTS LTD')->values();
        $this->assertSame([['Business income', 1000, 'Acme Events', 'INV-0041'], ['Business expense (taken off the payment)', -30, 'Acme Events', '']],
            $lines->map(fn ($r) => [$r[$head['Decision']], $r[$head['Line amount']], $r[$head['Client']], $r[$head['Invoice']]])->all());
        $train = collect($rows)->first(fn ($r) => ($r[$head['Description']] ?? null) === 'TRAINLINE');
        $this->assertSame(['Business expense', '', ''], [$train[$head['Decision']], $train[$head['Client']], $train[$head['Invoice']]]);
    }

    // ---------------------------------------------------------------- the connections map

    public function test_the_map_shows_each_payments_lines_on_the_jobs_shown_and_flags_by_invoice_state_and_billing(): void
    {
        $acme = $this->client();
        $shown = $this->job('Show A', $acme);
        $earlier = $this->job('Last year', $acme, '2025-06-01');
        // One payment for two jobs, one of them on the map: it shows that job's line. A fee taken off on the job comes off.
        $both = $this->tx(100000);
        $this->decide($both, [$this->income(60000, ['work_job_id' => $shown->id]), $this->income(40000, ['work_job_id' => $earlier->id])]);
        $net = $this->tx(97000, 'ACME NET');
        $this->decide($net, [$this->income(100000, ['work_job_id' => $shown->id]), Lines::deduction(3000, $this->cat('finance'), $shown->id)]);

        // Overdue invoices: nothing paid (flagged), closed by you (not), only a waiting suggestion (flagged: not received yet).
        $unpaid = $this->invoice('INV-0101', 50000, $this->job('Unpaid', $acme, '2026-05-02'), $acme);
        $closedJob = $this->job('Closed', $acme, '2026-05-03');
        $closed = $this->invoice('INV-0102', 50000, $closedJob, $acme, ['sent_at' => '2026-05-15 10:00:00']);
        app(InvoiceClosing::class)->close($closed, 'wont_chase');
        $waitingJob = $this->job('Waiting', $acme, '2026-05-04');
        $waiting = $this->invoice('INV-0103', 50000, $waitingJob, $acme);
        $this->decide($this->tx(50000, 'ACME WAIT'), [$this->income(50000, ['work_job_id' => $waitingJob->id])], 'matcher', 'jobs.gather');
        // Paid without an Expend invoice: billed, so not "not invoiced"; a job with nothing is.
        $paidJob = $this->job('Paid direct', $acme, '2026-05-05');
        $this->decide($this->tx(30000, 'ACME DIRECT'), [$this->income(30000, ['work_job_id' => $paidJob->id])]);
        $nothing = $this->job('Nothing yet', $acme, '2026-05-06');

        $page = $this->get('/connections?year=2026-27')->assertOk()->viewData('page')['props'];
        $nodes = collect($page['nodes'])->keyBy('id');
        $this->assertSame(60000, $nodes['tx:'.$both->id]['amount']);
        $this->assertSame(97000, $nodes['tx:'.$net->id]['amount']);
        $edges = collect($page['edges'])->keyBy(fn ($e) => $e['from'].'|'.$e['to']);
        $this->assertSame(60000, $edges['tx:'.$both->id.'|job:'.$shown->id]['amount']);
        $this->assertSame(97000, $edges['tx:'.$net->id.'|job:'.$shown->id]['amount']);
        $this->assertArrayNotHasKey('tx:'.$both->id.'|job:'.$earlier->id, $edges->all());

        $flagged = fn (string $code) => collect($page['attention'])->firstWhere('code', $code)['ids'] ?? [];
        $this->assertEqualsCanonicalizing(['invoice:'.$unpaid->id, 'job:'.$unpaid->work_job_id, 'invoice:'.$waiting->id, 'job:'.$waitingJob->id], $flagged('income_missing'));
        $notInvoiced = $flagged('not_invoiced');
        $this->assertContains('job:'.$nothing->id, $notInvoiced);
        $this->assertNotContains('job:'.$paidJob->id, $notInvoiced, 'Paid without an Expend invoice');
        $this->assertNotContains('job:'.$shown->id, $notInvoiced, 'Paid by income');
        $this->assertNotContains('job:'.$closedJob->id, $notInvoiced);
    }

    // ---------------------------------------------------------------- the assistant

    public function test_the_assistant_lists_invoices_by_state_so_paid_means_paid_in_full(): void
    {
        $acme = $this->client();
        $paid = $this->invoice('INV-0201', 60000, $this->job('Paid show', $acme), $acme);
        $part = $this->invoice('INV-0202', 75000, $this->job('Part show', $acme, '2026-05-02'), $acme);
        $this->decide($this->tx(60000), [$this->income(60000, ['invoice_id' => $paid->id])]);
        $this->decide($this->tx(25000), [$this->income(25000, ['invoice_id' => $part->id])]);
        $tools = app(AssistantTools::class);

        $rows = collect($tools->run('invoices', ['status' => 'paid'])['invoices']);
        $this->assertSame([[$paid->id, 'paid', 'paid', 0]], $rows->map(fn ($r) => [$r['invoice_id'], $r['status'], $r['state'], $r['outstanding']['pence']])->all());
        $rows = collect($tools->run('invoices', ['status' => 'issued'])['invoices']);
        $this->assertSame([[$part->id, 'issued', 'part_paid', 50000, true]], $rows->map(fn ($r) => [$r['invoice_id'], $r['status'], $r['state'], $r['outstanding']['pence'], $r['overdue']])->all());
        $this->assertCount(2, $tools->run('invoices', [])['invoices']);
    }

    public function test_search_transactions_filters_by_worked_out_client_and_totals_just_the_matching_lines(): void
    {
        $acme = $this->client();
        $old = $this->client('Acme Events Ltd');
        $old->forceFill(['merged_into_id' => $acme->id])->save();
        $stage = $this->client('Stage Co');
        $acmeShow = $this->job('Acme show', $acme);
        $stageShow = $this->job('Stage show', $stage);
        $split = $this->tx(100000, 'AGENCY');
        $this->decide($split, [$this->income(60000, ['work_job_id' => $acmeShow->id]), $this->income(40000, ['work_job_id' => $stageShow->id])]);
        $this->decide($this->tx(20000, 'ACME LTD'), [$this->income(20000, ['client_id' => $old->id])]);
        $tools = app(AssistantTools::class);

        $out = $tools->run('search_transactions', ['client_id' => $old->id]);
        $this->assertSame([2, 120000, 80000], [$out['count'], $out['total_in']['pence'], $out['lines_total']['pence']], 'A merged duplicate means the client it went into');
        $out = $tools->run('search_transactions', ['job_id' => $stageShow->id]);
        $this->assertSame([1, 100000, 40000], [$out['count'], $out['total_in']['pence'], $out['lines_total']['pence']]);
        $this->assertSame([['Acme show', 'Acme Events'], ['Stage show', 'Stage Co']], collect($out['items'][0]['decision'])->map(fn ($d) => [$d['job'], $d['client']])->all());
        $this->assertArrayNotHasKey('lines_total', $tools->run('search_transactions', ['query' => 'AGENCY']));
    }

    public function test_propose_decision_saves_invoices_a_split_and_a_deduction_as_one_unconfirmed_suggestion(): void
    {
        $acme = $this->client();
        $a = $this->job('Show A', $acme);
        $b = $this->job('Show B', $acme, '2026-05-02');
        $first = $this->invoice('INV-0301', 60000, $a, $acme);
        $second = $this->invoice('INV-0302', 75000, $b, $acme);
        $tx = $this->tx(132000, 'ACME EVENTS LTD');
        $tools = app(AssistantTools::class);

        $out = $tools->run('propose_decision', ['transaction_id' => $tx->id, 'reason' => 'The remittance names both invoices, less a £30 fee', 'lines' => [
            ['kind' => 'business_income', 'invoice_number' => 'INV-0301', 'amount_pence' => 60000],
            ['kind' => 'business_income', 'invoice_number' => 'INV-0302', 'amount_pence' => 75000],
            ['deduction' => true, 'amount_pence' => 3000],
        ]]);
        $this->assertTrue($out['ok']);
        $this->assertSame([
            ['business_income', $a->id, $first->id, null, 60000, false],
            ['business_income', $b->id, $second->id, null, 75000, false],
            ['business_expense', null, null, null, -3000, false],
        ], $this->stored($tx));
        $line = Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->first();
        $this->assertSame(['ai', 'ai.assistant', ['inv:'.$first->id, 'job:'.$a->id, 'inv:'.$second->id, 'job:'.$b->id]], [$line->decided_by, $line->method, $line->evidence]);
        $this->assertSame('suggested', $tx->fresh()->review_status);
        $this->assertSame($this->cat('finance'), (int) Allocation::query()->where('bank_transaction_id', $tx->id)->where('kind', 'business_expense')->value('category_id'));

        // One kind of line for a client: the client is kept, and it's still only a suggestion.
        $forClient = $this->tx(40000, 'ACME EVENTS LTD');
        $this->assertTrue($tools->run('propose_decision', ['transaction_id' => $forClient->id, 'kind' => 'business_income', 'category_code' => 'turnover',
            'client_id' => $acme->id, 'reason' => 'You said Acme paid a deposit'])['ok']);
        $this->assertSame([['business_income', null, null, $acme->id, 40000, false]], $this->stored($forClient));

        // Refused: a void invoice (the writer's words), an invoice that doesn't exist, lines that don't add up.
        $void = $this->invoice('INV-0303', 40000, $b, $acme, ['status' => 'void']);
        $again = $this->tx(40000, 'ACME EVENTS LTD');
        $out = $tools->run('propose_decision', ['transaction_id' => $again->id, 'reason' => 'x', 'lines' => [['kind' => 'business_income', 'invoice_number' => $void->number, 'amount_pence' => 40000]]]);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString(AllocationWriter::REFUSED_WORDS['link_closed'], $out['note']);
        $this->assertSame(['error' => 'Line 1: no invoice numbered INV-9999.'],
            $tools->run('propose_decision', ['transaction_id' => $again->id, 'reason' => 'x', 'lines' => [['kind' => 'business_income', 'invoice_number' => 'INV-9999', 'amount_pence' => 40000]]]));
        $this->assertStringStartsWith('The lines add up to', $tools->run('propose_decision', ['transaction_id' => $again->id, 'reason' => 'x',
            'lines' => [['kind' => 'business_income', 'amount_pence' => 30000]]])['error']);
        $this->assertSame([], $this->stored($again));
    }

    public function test_explain_payment_names_each_income_lines_invoice_and_client(): void
    {
        $acme = $this->client();
        $show = $this->job('Show A', $acme, '2026-05-28');
        $invoice = $this->invoice('INV-0401', 100000, $show, $acme);
        $tx = $this->tx(100000);
        $this->decide($tx, [$this->income(100000, ['invoice_id' => $invoice->id])]);
        $this->assertSame('Decided: Business income · Turnover (sales and fees) · Show A · INV-0401 · Acme Events.', Explainer::payment($tx->fresh())['status']);

        // For a client with no job, on a day with work: done once the client is set, so nothing asks which job it was for.
        $forClient = $this->tx(40000);
        $this->decide($forClient, [$this->income(40000, ['client_id' => $acme->id])]);
        $out = Explainer::payment($forClient->fresh());
        $this->assertStringEndsWith('· Acme Events.', $out['status']);
        $this->assertStringContainsString('You had work then: Show A.', implode(' ', $out['notes']));
        $this->assertStringNotContainsString('link it to the job', implode(' ', $out['notes']));
        $loose = $this->tx(25000);
        $this->decide($loose, [$this->income(25000)]);
        $this->assertStringContainsString('link it to the job', implode(' ', Explainer::payment($loose->fresh())['notes']));
        $this->assertSame(Explainer::payment($tx->fresh()), app(AssistantTools::class)->run('explain_payment', ['transaction_id' => $tx->id]));
    }

    // ---------------------------------------------------------------- the year page

    public function test_the_cash_basis_note_shows_for_2023_24_and_not_for_2024_25(): void
    {
        $this->get('/years/2023-24')->assertOk()->assertInertia(fn ($p) => $p->where('cash_basis_note', fn ($note) => str_contains((string) $note, 'cash basis for 2023-24')));
        $this->get('/years/2024-25')->assertOk()->assertInertia(fn ($p) => $p->where('cash_basis_note', null));
    }

    public function test_nothing_here_writes_a_ledger_line(): void
    {
        $acme = $this->client();
        $this->decide($this->tx(40000), [$this->income(40000, ['client_id' => $acme->id])]);
        $lines = fn () => Allocation::query()->orderBy('id')->get()->map(fn (Allocation $a) => [$a->id, $a->kind, $a->amount_pence, $a->client_id, (string) $a->confirmed_at])->all();
        $before = $lines();
        $this->unlinked();
        TaxSavings::review('2026-27');
        $this->get('/connections?year=2026-27')->assertOk();
        app(AssistantTools::class)->run('invoices', ['status' => 'paid']);
        app(AssistantTools::class)->run('search_transactions', ['client_id' => $acme->id]);
        Explainer::payment(BankTransaction::query()->sole());
        $this->assertSame($before, $lines());
    }
}
