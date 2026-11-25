<?php

namespace Tests\Feature\Clients;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Provenance;
use App\Domain\Invoices\InvoicePayments;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\ManualExpenses;
use App\Domain\Ledger\SourceState;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S3 (the client area's owner decisions): DecisionService::setExpenseClient says which client a cost with
 * no job was for, and ::shareCost shares a cost with another job (half each by default, the odd penny to the first).
 * Both are yours only, in open years only, keep every other line as it is, and are audited as your decision.
 */
class ExpenseClientAndShareTest extends TestCase
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

    private function service(): DecisionService
    {
        return app(DecisionService::class);
    }

    private function cat(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function job(string $title, ?Client $client = null): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $client?->id, 'starts_on' => '2026-05-01', 'ends_on' => '2026-05-01', 'status' => 'done']);
    }

    private function tx(int $pence, string $key = 'TRAINLINE', string $on = '2026-05-02'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('9', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'share-'.$this->n]);
    }

    private function cost(int $pence, ?WorkJob $job = null, string $category = 'travel_rail'): array
    {
        return ['kind' => 'business_expense', 'category_id' => $this->cat($category), 'amount_pence' => $pence, 'work_job_id' => $job?->id, 'business_use_pct' => 100];
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:int, 4:bool, 5:string}> kind, job, client, amount, confirmed, by */
    private function stored(BankTransaction|ManualExpense $source): array
    {
        return Allocation::query()->where($source instanceof BankTransaction ? 'bank_transaction_id' : 'manual_expense_id', $source->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null, $a->decided_by])->all();
    }

    private function refusal(callable $do): ?string
    {
        try {
            $do();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_set_expense_client_puts_the_client_on_costs_with_no_job_only(): void
    {
        $show = $this->job('Show A', $this->acme);
        $tx = $this->tx(-10000, 'TOOLSTATION');
        app(AllocationWriter::class)->decide($tx, [$this->cost(-4000, null, 'other'), $this->cost(-3000, $show, 'other'), ['kind' => 'personal', 'amount_pence' => -3000]]);

        $this->assertTrue($this->service()->setExpenseClient($tx, $this->acme->id));
        $this->assertSame([
            ['business_expense', null, $this->acme->id, -4000, true, 'you'],
            ['business_expense', $show->id, null, -3000, true, 'you'],
            ['personal', null, null, -3000, true, 'you'],
        ], $this->stored($tx));
        $event = AuditEvent::query()->where('action', 'allocation.decided')->where('subject_id', $tx->id)->latest('id')->first();
        $this->assertSame($this->acme->id, $event->after[0]['client_id']);
        $this->assertArrayNotHasKey('client_id', $event->after[1], 'lines without a client keep the keys they always had');

        $this->assertFalse($this->service()->setExpenseClient($tx, $this->acme->id), 'nothing to change');
        $this->assertTrue($this->service()->setExpenseClient($tx, null));
        $this->assertNull($this->stored($tx)[0][2]);
        $this->assertTrue(AuditLog::verify()['ok']);

        // A manual expense, the same way.
        $cash = ManualExpense::query()->create(['spent_on' => '2026-05-03', 'tax_year' => '2026-27', 'description' => 'Gaffer tape', 'merchant_key' => 'GAFFER TAPE',
            'paid_with' => 'cash', 'amount_pence' => -1299]);
        app(AllocationWriter::class)->decide($cash, [$this->cost(-1299, null, 'other')]);
        $this->assertTrue($this->service()->setExpenseClient($cash, $this->acme->id));
        $this->assertSame($this->acme->id, $this->stored($cash)[0][2]);
    }

    /**
     * Stage 1 check: correcting a manual expense ("It was £15.00", another date) writes its lines again with everything
     * else they said: the client you set on the cost, its business and personal parts, and a cash payment's invoice.
     */
    public function test_correcting_a_manual_expense_keeps_its_client_its_parts_and_its_invoice(): void
    {
        $expenses = app(ManualExpenses::class);
        $none = ['original_amount_minor' => null, 'original_currency' => null, 'fx_units_per_gbp' => null, 'fx_rate_date' => null, 'fx_source' => null];
        $add = fn (string $what, int $pence, array $data = []) => $expenses->create($data + ['spent_on' => '2026-05-03', 'description' => $what, 'paid_with' => 'cash',
            'kind' => 'business_expense', 'category_id' => $this->cat('other'), 'business_pct' => 100], ['pence' => $pence, 'fx' => $none]);

        // One line: the amount, then the date into the year before.
        $tape = $add('Gaffer tape', -1299);
        $this->assertTrue($this->service()->setExpenseClient($tape, $this->acme->id));
        $expenses->correct($tape, ['amount_minor' => 1500]);
        $this->assertSame([['business_expense', null, $this->acme->id, -1500, true, 'you']], $this->stored($tape));
        $expenses->correct($tape->fresh(), ['spent_on' => '2026-03-28']);
        $this->assertSame([['business_expense', null, $this->acme->id, -1500, true, 'you']], $this->stored($tape));
        $this->assertSame(['2025-26'], Allocation::query()->where('manual_expense_id', $tape->id)->pluck('tax_year')->all());

        // Business and personal parts: the business part keeps the client, both take the new amount.
        $phone = $add('Phone case', -1000, ['business_pct' => 60]);
        $this->assertTrue($this->service()->setExpenseClient($phone, $this->acme->id));
        $expenses->correct($phone, ['amount_minor' => 2000]);
        $this->assertSame([['business_expense', null, $this->acme->id, -1200, true, 'you'], ['personal', null, null, -800, true, 'you']], $this->stored($phone));
        $this->assertSame([60, null], Allocation::query()->where('manual_expense_id', $phone->id)->orderBy('id')->pluck('business_use_pct')->all());

        // Cash paid in for an invoice keeps the invoice, and the note.
        $show = $this->job('Show A', $this->acme);
        $invoice = Invoice::query()->create(['status' => 'issued', 'number' => 'INV-0001', 'client_id' => $this->acme->id, 'work_job_id' => $show->id, 'currency' => 'GBP',
            'total_minor' => 45000, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-01', 'due_date' => '2026-05-31']);
        $cash = $add('Cash from Acme Events', 40000, ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'invoice_id' => $invoice->id, 'note' => 'Paid at the venue']);
        $expenses->correct($cash, ['amount_minor' => 45000]);
        $line = Allocation::query()->where('manual_expense_id', $cash->id)->sole();
        $this->assertSame([45000, $invoice->id, $show->id, 'Paid at the venue'], [$line->amount_pence, $line->invoice_id, $line->work_job_id, $line->note]);
        $this->assertSame('paid', InvoicePayments::received([$invoice])[$invoice->id]['state']);
    }

    public function test_set_expense_client_is_refused_unconfirmed_in_a_locked_year_for_a_gone_client_or_for_automation(): void
    {
        $suggested = $this->tx(-8450);
        app(AllocationWriter::class)->decide($suggested, [$this->cost(-8450)], 'matcher', false, 'Train ticket', null, new Provenance('suggest.travel'));
        $this->assertSame('Confirm the payment first, then say which client it was for.', $this->refusal(fn () => $this->service()->setExpenseClient($suggested, $this->acme->id)));
        $this->assertSame('Confirm the payment first, then say which client it was for.', $this->refusal(fn () => $this->service()->setExpenseClient($this->tx(-500), $this->acme->id)));

        $old = $this->tx(-8450, 'TRAINLINE', '2025-05-02');
        app(AllocationWriter::class)->decide($old, [$this->cost(-8450)]);
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();
        $this->assertSame('Tax year 2025-26 is locked. Unlock it (with a reason) before changing anything in it.', $this->refusal(fn () => $this->service()->setExpenseClient($old, $this->acme->id)));

        $mine = $this->tx(-8450);
        app(AllocationWriter::class)->decide($mine, [$this->cost(-8450)]);
        $this->assertSame('That client no longer exists: pick another.', $this->refusal(fn () => $this->service()->setExpenseClient($mine, 999999)));
        foreach (['policy', 'matcher', 'ai'] as $actor) {
            $this->assertSame('Only you can set which client a cost was for.', $this->refusal(fn () => AuditContext::with($actor, fn () => $this->service()->setExpenseClient($mine, $this->acme->id))));
        }
        $this->assertNull($this->stored($mine)[0][2]);
    }

    public function test_share_cost_splits_a_ticket_half_each_between_two_jobs(): void
    {
        // The £84.50 ticket for two jobs: £42.25 on each.
        $leeds = $this->job('Leeds Arena', $this->acme);
        $york = $this->job('York Hall', Client::query()->create(['name' => 'Other Ltd']));
        $ticket = $this->tx(-8450);
        app(AllocationWriter::class)->decide($ticket, [$this->cost(-8450, $leeds)]);

        $this->service()->shareCost($ticket, $leeds->id, $york->id);
        $this->assertSame([
            ['business_expense', $leeds->id, null, -4225, true, 'you'],
            ['business_expense', $york->id, null, -4225, true, 'you'],
        ], $this->stored($ticket));
        $this->assertSame((int) $ticket->amount_pence, (int) Allocation::query()->where('bank_transaction_id', $ticket->id)->sum('amount_pence'));

        // The odd penny stays with the first job; a third job takes an amount you give; every other line stays.
        $odd = $this->tx(-8451);
        app(AllocationWriter::class)->decide($odd, [$this->cost(-6451, $leeds), ['kind' => 'personal', 'amount_pence' => -2000]]);
        $this->service()->shareCost($odd, $leeds->id, $york->id);
        $this->assertSame([
            ['business_expense', $leeds->id, null, -3226, true, 'you'],
            ['business_expense', $york->id, null, -3225, true, 'you'],
            ['personal', null, null, -2000, true, 'you'],
        ], $this->stored($odd));
        $this->service()->shareCost($odd, $leeds->id, $york->id, 1000);
        $this->assertSame([-2226, -4225, -2000], array_column($this->stored($odd), 3), 'added to the line already on York Hall');
    }

    public function test_share_cost_on_a_suggestion_you_were_shown_and_its_refusals(): void
    {
        $leeds = $this->job('Leeds Arena', $this->acme);
        $york = $this->job('York Hall', $this->acme);
        $ticket = $this->tx(-8450);
        app(AllocationWriter::class)->decide($ticket, [$this->cost(-8450, $leeds)], 'matcher', false, 'Train ticket', null, new Provenance('suggest.travel'));
        $shown = SourceState::fingerprint($ticket);

        $this->assertSame('Pick another job to share it with.', $this->refusal(fn () => $this->service()->shareCost($ticket, $leeds->id, $leeds->id)));
        $this->assertSame('That job no longer exists: pick another.', $this->refusal(fn () => $this->service()->shareCost($ticket, $leeds->id, 999999)));
        $this->assertSame('This payment has no cost on that job to share.', $this->refusal(fn () => $this->service()->shareCost($ticket, $york->id, $leeds->id)));
        $this->assertSame('Share less than the whole £84.50, and more than nothing.', $this->refusal(fn () => $this->service()->shareCost($ticket, $leeds->id, $york->id, 8450)));
        $this->assertSame('This payment changed since you looked: check it again.', $this->refusal(fn () => $this->service()->shareCost($ticket, $leeds->id, $york->id, null, 'abc123')));
        $this->assertSame('Only you can share a cost with another job.', $this->refusal(fn () => AuditContext::with('ai', fn () => $this->service()->shareCost($ticket, $leeds->id, $york->id))));

        // As shown: split as your decision.
        $this->service()->shareCost($ticket, $leeds->id, $york->id, null, $shown);
        $this->assertSame([[$leeds->id, -4225, true, 'you'], [$york->id, -4225, true, 'you']], array_map(fn ($l) => [$l[1], $l[3], $l[4], $l[5]], $this->stored($ticket)));

        // A locked year.
        $old = $this->tx(-8450, 'TRAINLINE', '2025-05-02');
        app(AllocationWriter::class)->decide($old, [$this->cost(-8450, $leeds)]);
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();
        $this->assertSame('Tax year 2025-26 is locked. Unlock it (with a reason) before changing anything in it.', $this->refusal(fn () => $this->service()->shareCost($old, $leeds->id, $york->id)));
        $this->assertSame([['business_expense', $leeds->id, null, -8450, true, 'you']], $this->stored($old));
    }
}
