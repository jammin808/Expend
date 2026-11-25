<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\CostCharges;
use App\Domain\Charges\CostRef;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\Steps\RelateSweep;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Suggester;
use App\Domain\Verification\Verifier;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\MerchantRule;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build slice S8 for clients, jobs and invoices: automation around costs charged to a client. The client paying back a
 * recharged or claimed cost, suggested as anything but turnover, is warned about (Verifier); a payment of an invoice with
 * recharges is matched as one turnover line, only ever as a suggestion; a rule for the client's payer never confirms on
 * its own; and relate.sweep leaves the suggestions of a job cancelled with a fee or its costs to charge alone.
 */
class RechargeAutomationTest extends TestCase
{
    use ChargeFixtures;
    use RefreshDatabase;

    private const WARNING = 'Looks like Acme Events paying you: that’s turnover (box 15), not a refund or other income';

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCharges();
    }

    protected function tearDown(): void
    {
        $this->stopCharges();
        parent::tearDown();
    }

    /** A job for Acme with a £45 train you paid, and a payer known to pay for Acme. */
    private function recharged(): array
    {
        $acme = $this->client();
        $job = $this->job($acme);
        $train = $this->tx(-4500, '2026-06-03', 'TRAINLINE');
        $this->decide($train, [$this->cost(4500, 'travel_rail', $job)]);
        // Paid for an earlier job of Acme's (client-only, so it pays none of this job's invoices).
        $known = $this->tx(30000, '2026-05-02', 'ACME EVENTS LTD');
        $this->decide($known, [$this->income(30000, null, $acme->id)]);

        return [$acme, $job, $train];
    }

    /** Money in from Acme's payer, suggested by automation as these lines. */
    private function suggested(array $lines, int $pence = 4500): BankTransaction
    {
        $tx = $this->tx($pence, '2026-06-18', 'ACME EVENTS LTD');
        $lines = array_map(fn (array $l) => $l + ['business_use_pct' => 100], $lines);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines, 'matcher', false, 'Looks like a refund', null, new Provenance('categorise.hint')));

        return $tx;
    }

    private function warned(BankTransaction $tx): bool
    {
        return collect(app(Verifier::class)->transaction($tx)->checks)->contains(fn (array $c) => $c['ok'] === false && $c['note'] === self::WARNING);
    }

    public function test_the_client_paying_back_a_recharged_cost_is_warned_unless_it_is_turnover(): void
    {
        [, $job, $train] = $this->recharged();
        $this->invoice($job, [['job:'.$job->id.'#fee', 50000], [CostRef::tx($train->id, $job->id), 4500]]);

        $this->assertTrue($this->warned($this->suggested([['kind' => 'personal', 'amount_pence' => 4500]])), 'As personal');
        $this->assertTrue($this->warned($this->suggested([['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => 4500]])), 'As a refund of the train');
        $this->assertTrue($this->warned($this->suggested([['kind' => 'business_income', 'category_id' => $this->cat('other_income'), 'amount_pence' => 4500]])), 'As other business income');
        $this->assertTrue($this->warned($this->suggested([['kind' => 'loan', 'amount_pence' => 4500]])), 'As a loan');
        $turnover = $this->suggested([['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => 4500]]);
        $this->assertFalse($this->warned($turnover), 'Turnover is what it is');
        $this->assertSame('verified', app(Verifier::class)->transaction($turnover)->status);
    }

    public function test_a_cost_claimed_on_the_clients_expense_form_counts_too(): void
    {
        [, $job, $train] = $this->recharged();
        $personal = $this->suggested([['kind' => 'personal', 'amount_pence' => 4500]]);
        $this->assertFalse($this->warned($personal), 'Nothing charged to Acme yet');

        app(CostCharges::class)->claimed(CostRef::tx($train->id, $job->id));

        $this->assertTrue($this->warned($personal));
    }

    public function test_a_payer_that_pays_for_two_clients_is_not_warned_about(): void
    {
        [, $job, $train] = $this->recharged();
        $this->invoice($job, [[CostRef::tx($train->id, $job->id), 4500]]);
        $beta = $this->client('Beta Productions');
        $this->decide($this->tx(20000, '2026-05-03', 'ACME EVENTS LTD'), [$this->income(20000, null, $beta->id)]);

        $this->assertFalse($this->warned($this->suggested([['kind' => 'personal', 'amount_pence' => 4500]])));
    }

    public function test_paying_an_invoice_with_recharges_is_one_turnover_line_and_only_suggested(): void
    {
        [$acme, $job, $train] = $this->recharged();
        $invoice = $this->invoice($job, [['job:'.$job->id.'#fee', 50000], [CostRef::tx($train->id, $job->id), 4500]]);
        // Your rule for the payer confirms on its own, but the invoice match comes first and only you accept it.
        MerchantRule::query()->create(['merchant_key' => 'ACME EVENTS LTD', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'business_pct' => 100, 'auto_confirm' => true, 'direction' => 'in']);
        $paid = $this->tx(54500, '2026-06-25', 'ACME EVENTS LTD');
        $other = $this->tx(12000, '2026-06-26', 'ACME EVENTS LTD');

        app(Suggester::class)->suggestFor(collect([$paid, $other]));

        $line = Allocation::query()->where('bank_transaction_id', $paid->id)->sole();
        $this->assertSame(['business_income', $this->cat('turnover'), $invoice->id, $job->id, 54500, null, 'suggest.invoice'],
            [$line->kind, (int) $line->category_id, $line->invoice_id, $line->work_job_id, $line->amount_pence, $line->confirmed_at, $line->method]);
        $rule = Allocation::query()->where('bank_transaction_id', $other->id)->sole();
        $this->assertSame(['rule', $acme->id, null], [$rule->decided_by, $rule->client_id, $rule->confirmed_at], 'The rule fills in Acme, for you to accept');
    }

    public function test_relate_sweep_leaves_a_cancelled_job_that_still_owes_you_alone(): void
    {
        $acme = $this->client();
        $jobs = [];
        $txs = [];
        foreach (['fee', 'costs', 'none', null] as $i => $charge) {
            $jobs[$i] = $job = $this->job($acme, '2026-06-0'.($i + 1));
            $txs[$i] = $tx = $this->tx(-3000, '2026-06-0'.($i + 1), 'KIT HIRE LTD');
            app(AllocationWriter::class)->decide($tx, [$this->cost(3000, 'equipment', $job) + ['business_use_pct' => 100]], 'matcher', false,
                'A business cost on '.$job->title.'’s dates', null, new Provenance('jobs.gather', ['job:'.$job->id]));
            $job->update(['status' => 'cancelled', 'cancel_charge' => $charge, 'cancel_fee_pence' => $charge === 'fee' ? 15000 : null]);
        }

        $counts = app(RelateSweep::class)->run(new CycleContext)->counts;

        $this->assertSame(2, $counts['cancelled_job']);
        $left = fn (int $i) => Allocation::query()->where('bank_transaction_id', $txs[$i]->id)->count();
        $this->assertSame([1, 1, 0, 0], [$left(0), $left(1), $left(2), $left(3)], 'A fee or costs to charge: its suggestions stay');
        $this->assertStringContainsString($jobs[2]->title.' was cancelled', (string) AuditEvent::query()->where('action', 'allocation.cleared')->where('subject_id', $txs[2]->id)->value('reason'));
        $this->assertSame('cancelled', WorkJob::query()->find($jobs[0]->id)->status);
        $this->assertNotNull(Client::query()->find($acme->id));
    }
}
