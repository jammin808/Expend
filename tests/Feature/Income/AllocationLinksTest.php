<?php

namespace Tests\Feature\Income;

use App\Domain\Audit\AuditLog;
use App\Domain\Income\IncomeClient;
use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
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
 * Build slice S2 (income v3's ledger core): what AllocationWriter does with links.
 *  - An invoice stays only on income, and only one that can be paid (or one the payment already named); the invoice
 *    fills an empty job; a line on another job than its invoice is kept.
 *  - A client is stored only where neither the invoice nor the line's own job names one.
 *  - On money in, a negative line is allowed only as a deduction beside larger income.
 *  - Automation never confirms an income link, and confirm() checks the invoice and who is accepting.
 *  - A change of client alone is written and audited; the snapshot carries client_id only when it's set.
 */
class AllocationLinksTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function writer(): AllocationWriter
    {
        return app(AllocationWriter::class);
    }

    private function category(string $code): int
    {
        return (int) Category::query()->where('code', $code)->value('id');
    }

    private function client(string $name = 'Acme Events'): Client
    {
        return Client::query()->firstOrCreate(['name' => $name]);
    }

    private function job(string $title, ?Client $client = null): WorkJob
    {
        return WorkJob::query()->create(['title' => $title, 'client_id' => $client?->id, 'starts_on' => '2026-05-20', 'ends_on' => '2026-05-20', 'status' => 'done']);
    }

    private function invoice(?WorkJob $job, ?string $number, int $total, string $status = 'issued', ?Client $client = null): Invoice
    {
        return Invoice::query()->create(['status' => $status, 'number' => $number, 'work_job_id' => $job?->id, 'client_id' => $client?->id ?? $job?->client_id,
            'currency' => 'GBP', 'total_minor' => $total, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-21', 'due_date' => '2026-06-20']);
    }

    private function tx(int $pence, string $name = 'Acme Events Ltd', string $on = '2026-05-28', ?Account $account = null): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => ($account ?? $this->account)->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $name, 'counterparty' => $name, 'merchant_key' => strtoupper($name), 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'links-'.$this->n]);
    }

    /** One income line, with whatever links are given. */
    private function income(int $pence, array $links = []): array
    {
        return [['kind' => 'business_income', 'category_id' => $this->category('turnover'), 'amount_pence' => $pence] + $links];
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:bool}> kind, job, invoice, client, amount, confirmed */
    private function stored(BankTransaction|ManualExpense $source): array
    {
        return Allocation::query()->where($source instanceof BankTransaction ? 'bank_transaction_id' : 'manual_expense_id', $source->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->invoice_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null])->all();
    }

    private function refusal(callable $write): ?string
    {
        try {
            $write();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function matcher(string $method = 'suggest.invoice'): Provenance
    {
        return new Provenance($method, [], 1);
    }

    public function test_an_invoice_stays_only_on_income_and_only_one_that_can_be_paid(): void
    {
        $acme = $this->client();
        $arena = $this->job('Arena load-in', $acme);
        $gala = $this->job('Gala dinner', $acme);
        $issued = $this->invoice($arena, 'INV-0001', 60000);
        $w = $this->writer();

        // (a) Only income carries an invoice: a cost naming one is saved without it.
        $cost = $this->tx(-4500, 'Adobe');
        $this->assertTrue($w->decide($cost, [['kind' => 'business_expense', 'category_id' => $this->category('software'), 'amount_pence' => -4500, 'invoice_id' => $issued->id]]));
        $this->assertSame([['business_expense', null, null, null, -4500, true]], $this->stored($cost));
        $gift = $this->tx(2500, 'Birthday money');
        $this->assertTrue($w->decide($gift, [['kind' => 'personal', 'amount_pence' => 2500, 'invoice_id' => $issued->id]]));
        $this->assertSame([['personal', null, null, null, 2500, true]], $this->stored($gift));

        // (c) The invoice fills an empty job; a line on another job than its invoice keeps its own job.
        $paid = $this->tx(60000);
        $this->assertTrue($w->decide($paid, $this->income(60000, ['invoice_id' => $issued->id])));
        $this->assertSame([['business_income', $arena->id, $issued->id, null, 60000, true]], $this->stored($paid));
        $shows = $this->tx(30000);
        $this->assertTrue($w->decide($shows, [...$this->income(20000, ['invoice_id' => $issued->id, 'work_job_id' => $gala->id]), ...$this->income(10000, ['invoice_id' => $issued->id])]));
        $this->assertSame([['business_income', $gala->id, $issued->id, null, 20000, true], ['business_income', $arena->id, $issued->id, null, 10000, true]], $this->stored($shows));

        // (b) A draft or a void invoice is refused for you, with what to do.
        $draft = $this->invoice($gala, null, 40000, 'draft');
        $numbered = $this->invoice($gala, 'INV-0002', 40000, 'draft');
        $void = $this->invoice($gala, 'INV-0003', 40000);
        $void->update(['status' => 'void', 'void_reason' => 'Wrong day rate']);
        $legacy = $this->invoice($gala, 'INV-0004', 40000);
        $legacy->update(['status' => 'paid']);
        $tx = $this->tx(40000);
        $events = AuditEvent::query()->count();
        $this->assertSame([
            'That invoice is a draft: issue it first.',
            'INV-0002 is a draft: issue it first.',
            'INV-0003 is void: pick the invoice that replaced it.',
            'That invoice no longer exists: pick another.',
        ], [
            $this->refusal(fn () => $w->decide($tx, $this->income(40000, ['invoice_id' => $draft->id]))),
            $this->refusal(fn () => $w->decide($tx, $this->income(40000, ['invoice_id' => $numbered->id]))),
            $this->refusal(fn () => $w->decide($tx, $this->income(40000, ['invoice_id' => $void->id]))),
            $this->refusal(fn () => $w->decide($tx, $this->income(40000, ['invoice_id' => 999999]))),
        ]);
        $this->assertSame([], $this->stored($tx));
        $this->assertSame($events, AuditEvent::query()->count());
        // An invoice marked paid by an earlier release can still be paid.
        $this->assertTrue($w->decide($tx, $this->income(40000, ['invoice_id' => $legacy->id])));
        $this->assertSame([['business_income', $gala->id, $legacy->id, null, 40000, true]], $this->stored($tx));

        // For automation the same is refused without throwing: 'link_closed', and nothing written.
        $waiting = $this->tx(40000);
        foreach ([$draft, $void] as $closed) {
            $this->assertFalse($w->decide($waiting, $this->income(40000, ['invoice_id' => $closed->id]), 'matcher', false, 'Matches an invoice', null, $this->matcher()));
            $this->assertSame('link_closed', $w->refused);
        }
        $this->assertSame('the invoice it would be linked to is a draft or void, or the client no longer exists', AllocationWriter::REFUSED_WORDS['link_closed']);
        $this->assertSame([], $this->stored($waiting));
        $this->assertSame('unreviewed', $waiting->fresh()->review_status);

        // A payment linked while its invoice was issued keeps it when saved again after the invoice was voided: unchanged
        // writes nothing, and a change (a note) is saved with the link kept.
        $later = $this->invoice($gala, 'INV-0005', 25000);
        $kept = $this->tx(25000);
        $lines = $this->income(25000, ['invoice_id' => $later->id]);
        $this->assertTrue($w->decide($kept, $lines));
        $ids = Allocation::query()->where('bank_transaction_id', $kept->id)->pluck('id')->all();
        $later->update(['status' => 'void', 'void_reason' => 'Issued twice']);
        $events = AuditEvent::query()->count();
        $this->assertTrue($w->decide($kept->fresh(), $lines));
        $this->assertSame($events, AuditEvent::query()->count());
        $this->assertSame($ids, Allocation::query()->where('bank_transaction_id', $kept->id)->pluck('id')->all());
        $lines[0]['note'] = 'Paid before it was voided';
        $this->assertTrue($w->decide($kept->fresh(), $lines));
        $this->assertSame([['business_income', $gala->id, $later->id, null, 25000, true]], $this->stored($kept));
        // Automation never suggests it on another payment.
        $this->assertFalse($w->decide($this->tx(25000), $this->income(25000, ['invoice_id' => $later->id]), 'matcher', false, 'Matches an invoice', null, $this->matcher()));
        $this->assertSame('link_closed', $w->refused);

        // Only lines you made or confirmed count as naming it already (stage 1 check): a suggestion naming an invoice
        // voided since can't be confirmed by deciding the same lines as you (Accept refuses it too), nor suggested again.
        $gone = $this->invoice($gala, 'INV-0006', 15000);
        $open = $this->tx(15000);
        $this->assertTrue($w->decide($open, $this->income(15000, ['invoice_id' => $gone->id]), 'matcher', false, 'Matches an invoice', null, $this->matcher()));
        $gone->update(['status' => 'void', 'void_reason' => 'Wrong day rate']);
        $this->assertSame('INV-0006 is void: pick the invoice that replaced it.', $this->refusal(fn () => $w->decide($open->fresh(), $this->income(15000, ['invoice_id' => $gone->id]))));
        $this->assertFalse($w->decide($open->fresh(), $this->income(15000, ['invoice_id' => $gone->id, 'note' => 'Again']), 'matcher', false, 'Matches an invoice', null, $this->matcher()));
        $this->assertSame('link_closed', $w->refused);
        $this->assertSame([['business_income', $gala->id, $gone->id, null, 15000, false]], $this->stored($open));
    }

    public function test_a_client_is_stored_only_where_no_invoice_or_job_names_one(): void
    {
        $acme = $this->client();
        $other = $this->client('Other Ltd');
        $arena = $this->job('Arena load-in', $acme);
        $nobodys = $this->job('Open day');
        $w = $this->writer();

        // Cleared where the invoice or the line's own job says who it's for.
        $onInvoice = $this->invoice($arena, 'INV-0001', 60000);
        $a = $this->tx(60000);
        $w->decide($a, $this->income(60000, ['invoice_id' => $onInvoice->id, 'client_id' => $other->id]));
        $this->assertSame([['business_income', $arena->id, $onInvoice->id, null, 60000, true]], $this->stored($a));
        $b = $this->tx(30000);
        $w->decide($b, $this->income(30000, ['work_job_id' => $arena->id, 'client_id' => $other->id]));
        $this->assertSame([['business_income', $arena->id, null, null, 30000, true]], $this->stored($b));
        // An invoice with a client of its own on a job with none: the invoice names it.
        $billed = $this->invoice($nobodys, 'INV-0002', 15000, client: $acme);
        $c = $this->tx(15000);
        $w->decide($c, $this->income(15000, ['invoice_id' => $billed->id, 'client_id' => $other->id]));
        $this->assertSame([['business_income', $nobodys->id, $billed->id, null, 15000, true]], $this->stored($c));

        // Kept on income with no job (a refund paid back too), on a job with no client, and for a client-less job-less invoice.
        $d = $this->tx(50000);
        $w->decide($d, $this->income(50000, ['client_id' => $acme->id]));
        $this->assertSame([['business_income', null, null, $acme->id, 50000, true]], $this->stored($d));
        $refund = $this->tx(-5000, 'Refund Acme Events');
        $w->decide($refund, $this->income(-5000, ['client_id' => $acme->id]));
        $this->assertSame([['business_income', null, null, $acme->id, -5000, true]], $this->stored($refund));
        $e = $this->tx(20000);
        $w->decide($e, $this->income(20000, ['work_job_id' => $nobodys->id, 'client_id' => $acme->id]));
        $this->assertSame([['business_income', $nobodys->id, null, $acme->id, 20000, true]], $this->stored($e));
        $loose = $this->invoice(null, 'INV-0003', 12000);
        $f = $this->tx(12000);
        $w->decide($f, $this->income(12000, ['invoice_id' => $loose->id, 'client_id' => $acme->id]));
        $this->assertSame([['business_income', null, $loose->id, $acme->id, 12000, true]], $this->stored($f));

        // A cost with no job keeps its client; one on a client's job doesn't; other kinds never carry one.
        $cost = $this->tx(-2390, 'Trainline');
        $w->decide($cost, [['kind' => 'business_expense', 'category_id' => $this->category('travel_rail'), 'amount_pence' => -2390, 'client_id' => $acme->id]]);
        $this->assertSame([['business_expense', null, null, $acme->id, -2390, true]], $this->stored($cost));
        $onJob = $this->tx(-1850, 'GWR');
        $w->decide($onJob, [['kind' => 'business_expense', 'category_id' => $this->category('travel_rail'), 'work_job_id' => $arena->id, 'amount_pence' => -1850, 'client_id' => $other->id]]);
        $this->assertSame([['business_expense', $arena->id, null, null, -1850, true]], $this->stored($onJob));
        $personal = $this->tx(2500, 'Birthday money');
        $w->decide($personal, [['kind' => 'personal', 'amount_pence' => 2500, 'client_id' => $acme->id]]);
        $this->assertSame([['personal', null, null, null, 2500, true]], $this->stored($personal));

        // Cash income you added by hand works the same.
        $cash = ManualExpense::query()->create(['spent_on' => '2026-05-30', 'tax_year' => '2026-27', 'description' => 'Cash from Acme Events', 'merchant_key' => 'ACME EVENTS',
            'paid_with' => 'cash', 'amount_pence' => 8000]);
        $w->decide($cash, $this->income(8000, ['client_id' => $acme->id]));
        $this->assertSame([['business_income', null, null, $acme->id, 8000, true]], $this->stored($cash));

        // A client that no longer exists: refused for you, 'link_closed' for automation.
        $g = $this->tx(9000);
        $this->assertSame('That client no longer exists: pick another.', $this->refusal(fn () => $w->decide($g, $this->income(9000, ['client_id' => 999999]))));
        $this->assertFalse($w->decide($g, $this->income(9000, ['client_id' => 999999]), 'matcher', false, 'A guess', null, $this->matcher('categorise.merchant')));
        $this->assertSame('link_closed', $w->refused);
        $this->assertSame([], $this->stored($g));

        // The worked-out client: the invoice's, else the line's own job's, else the line's own; merges resolved.
        $worked = fn (BankTransaction|ManualExpense $s) => Allocation::query()->where($s instanceof BankTransaction ? 'bank_transaction_id' : 'manual_expense_id', $s->id)
            ->orderBy('id')->get()->map(fn (Allocation $x) => IncomeClient::of($x))->all();
        $this->assertSame([[$acme->id], [$acme->id], [$acme->id], [$acme->id], [$acme->id], [$acme->id], [$acme->id], [$acme->id], [null]],
            [$worked($a), $worked($b), $worked($c), $worked($d), $worked($refund), $worked($e), $worked($f), $worked($cost), $worked($personal)]);
        $other->forceFill(['merged_into_id' => $acme->id])->save();
        $dup = $this->client('ACME EVENTS LTD');
        $h = $this->tx(7000);
        $w->decide($h, $this->income(7000, ['client_id' => $dup->id]));
        $this->assertSame([$dup->id], $worked($h));
        $dup->forceFill(['merged_into_id' => $acme->id])->save();
        $this->assertSame([$acme->id], $worked($h));
        $this->assertSame([$acme->id, $acme->id, null], [IncomeClient::ofInvoice($onInvoice), IncomeClient::ofInvoice($billed), IncomeClient::ofInvoice($loose)]);

        // The same, for many lines at once in SQL: filter and group by it.
        $byClient = IncomeClient::scope(Allocation::query())->where('kind', 'business_income')
            ->whereRaw(IncomeClient::expression().' = ?', [$acme->id])->orderBy('allocations.id')->get();
        $this->assertSame([$a->id, $b->id, $c->id, $d->id, $refund->id, $e->id, $f->id, $h->id], $byClient->pluck('bank_transaction_id')->filter()->map(fn ($id) => (int) $id)->values()->all());
        $this->assertTrue($byClient->every(fn (Allocation $x) => (int) $x->{IncomeClient::COLUMN} === $acme->id));
        $totals = IncomeClient::scope(Allocation::query()->select([]))->where('kind', 'business_income')
            ->selectRaw('SUM(allocations.amount_pence) as pence')->groupByRaw(IncomeClient::expression())
            ->get()->mapWithKeys(fn ($row) => [(int) $row->{IncomeClient::COLUMN} => (int) $row->pence])->all();
        $this->assertSame([$acme->id => 60000 + 30000 + 15000 + 50000 - 5000 + 20000 + 12000 + 8000 + 7000], $totals);
    }

    public function test_deductions_and_the_narrowed_sign_rule(): void
    {
        $acme = $this->client();
        $arena = $this->job('Arena load-in', $acme);
        $invoice = $this->invoice($arena, 'INV-0042', 100000);
        $finance = $this->category('finance');
        $w = $this->writer();

        // £970 received: £1,000 turnover for INV-0042 and the £30 fee the payer took off.
        $net = $this->tx(97000);
        $this->assertTrue($w->decide($net, [
            ...$this->income(100000, ['invoice_id' => $invoice->id]),
            ['kind' => 'business_expense', 'category_id' => $finance, 'work_job_id' => $arena->id, 'amount_pence' => -3000, 'note' => 'Bank fee'],
        ]));
        $this->assertSame([['business_income', $arena->id, $invoice->id, null, 100000, true], ['business_expense', $arena->id, null, null, -3000, true]], $this->stored($net));
        // Two invoices and two deductions with no job but a client.
        $two = $this->tx(45000);
        $this->assertTrue($w->decide($two, [
            ...$this->income(30000, ['client_id' => $acme->id]), ...$this->income(20000, ['client_id' => $acme->id]),
            ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => -1500, 'client_id' => $acme->id],
            ['kind' => 'business_expense', 'category_id' => $this->category('other'), 'amount_pence' => -3500, 'client_id' => $acme->id],
        ]));
        $this->assertSame([-1500, -3500], array_values(array_map(fn ($l) => $l[4], array_filter($this->stored($two), fn ($l) => $l[0] === 'business_expense'))));
        // Automation is held to the same rule (it may suggest a deduction, never confirm the income link).
        $suggested = $this->tx(48500);
        $this->assertTrue($w->decide($suggested, [...$this->income(50000, ['invoice_id' => $invoice->id]), ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => -1500]],
            'matcher', false, 'Less the bank fee', null, $this->matcher()));

        $tx = $this->tx(10000);
        $out = $this->tx(-5000, 'Refund Acme Events');
        $refused = [
            'on money out' => $this->refusal(fn () => $w->decide($out, [...$this->income(-6000, ['client_id' => $acme->id]), ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => 1000]])),
            'without income' => $this->refusal(fn () => $w->decide($tx, [['kind' => 'personal', 'amount_pence' => 13000], ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => -3000]])),
            'income only from an expense' => $this->refusal(fn () => $w->decide($tx, [['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => 13000], ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => -3000]])),
            'as large as the gross' => $this->refusal(fn () => $w->decide($tx, [...$this->income(3000), ['kind' => 'personal', 'amount_pence' => 10000], ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => -3000]])),
            'negative income' => $this->refusal(fn () => $w->decide($tx, [...$this->income(15000), ...$this->income(-5000)])),
            'negative personal beside income' => $this->refusal(fn () => $w->decide($tx, [...$this->income(12000), ['kind' => 'personal', 'amount_pence' => -2000]])),
            'income category on the deduction' => $this->refusal(fn () => $w->decide($tx, [...$this->income(13000), ['kind' => 'business_expense', 'category_id' => $this->category('turnover'), 'amount_pence' => -3000]])),
            'the sum' => $this->refusal(fn () => $w->decide($tx, [...$this->income(13000), ['kind' => 'business_expense', 'category_id' => $finance, 'amount_pence' => -2000]])),
        ];
        $this->assertSame([
            'on money out' => 'Line 2 must be money out, like the transaction itself.',
            'without income' => 'Line 2 must be money in, like the transaction itself.',
            'income only from an expense' => 'Line 2 must be money in, like the transaction itself.',
            'as large as the gross' => 'Line 3 must be money in, like the transaction itself.',
            'negative income' => 'Line 2 must be money in, like the transaction itself.',
            'negative personal beside income' => 'Line 2 must be money in, like the transaction itself.',
            'income category on the deduction' => 'Line 2: "Turnover (sales and fees)" isn\'t an expense category.',
            'the sum' => 'The lines add up to £110.00 but the amount is £100.00. They must match exactly.',
        ], $refused);
        $this->assertSame([], $this->stored($tx));
        $this->assertSame([], $this->stored($out));
    }

    public function test_a_change_of_client_alone_is_written_and_audited(): void
    {
        $acme = $this->client();
        $other = $this->client('Other Ltd');
        $w = $this->writer();
        $keys = ['amount_pence', 'business_use_pct', 'category_id', 'confirmed', 'decided_by', 'invoice_id', 'is_manual', 'kind', 'merchant_rule_id', 'method', 'note', 'reason', 'work_job_id'];
        $sorted = function (array $snapshot): array {
            $k = array_keys($snapshot);
            sort($k);

            return $k;
        };

        $tx = $this->tx(50000);
        $w->decide($tx, $this->income(50000, ['client_id' => $acme->id]));
        $first = AuditEvent::query()->where('action', 'allocation.decided')->latest('id')->firstOrFail();
        $this->assertSame([...$keys, 'client_id'], array_values(array_unique([...$keys, ...$sorted($first->after[0])])));
        $this->assertSame($acme->id, $first->after[0]['client_id']);
        $ids = Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->all();

        // Saving the same again: nothing written, nothing recorded.
        $events = AuditEvent::query()->count();
        $this->assertTrue($w->decide($tx->fresh(), $this->income(50000, ['client_id' => $acme->id])));
        $this->assertSame($events, AuditEvent::query()->count());
        $this->assertSame($ids, Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->all());

        // Only the client changes: written and recorded, with before and after.
        $this->assertTrue($w->decide($tx->fresh(), $this->income(50000, ['client_id' => $other->id]), reason: 'Paid by their agency'));
        $changed = AuditEvent::query()->where('action', 'allocation.decided')->latest('id')->firstOrFail();
        $this->assertSame($events + 1, AuditEvent::query()->count());
        $this->assertSame([$acme->id, $other->id], [$changed->before[0]['client_id'], $changed->after[0]['client_id']]);
        $this->assertSame([['business_income', null, null, $other->id, 50000, true]], $this->stored($tx));

        // And taken off: the snapshot without a client has exactly the keys it always had.
        $this->assertTrue($w->decide($tx->fresh(), $this->income(50000)));
        $cleared = AuditEvent::query()->where('action', 'allocation.decided')->latest('id')->firstOrFail();
        $this->assertSame($keys, $sorted($cleared->after[0]));
        $this->assertSame($other->id, $cleared->before[0]['client_id']);
    }

    public function test_automation_never_confirms_an_income_link(): void
    {
        $acme = $this->client();
        $arena = $this->job('Arena load-in', $acme);
        $invoice = $this->invoice($arena, 'INV-0001', 60000);
        $w = $this->writer();
        $rule = new Provenance('rule.owner', [], null);

        // A rule that confirms on its own: with a job, an invoice or a client the income is only suggested.
        foreach (['job' => ['work_job_id' => $arena->id], 'invoice' => ['invoice_id' => $invoice->id], 'client' => ['client_id' => $acme->id]] as $label => $links) {
            $tx = $this->tx(60000);
            $this->assertTrue($w->decide($tx, $this->income(60000, $links), 'rule', true, 'Your rule for ACME EVENTS LTD', null, $rule), $label);
            $this->assertSame([false], array_map(fn ($l) => $l[5], $this->stored($tx)), $label);
            $this->assertSame('suggested', $tx->fresh()->review_status, $label);
        }
        // A matcher asking to confirm is held the same way.
        $tx = $this->tx(60000);
        $this->assertTrue($w->decide($tx, $this->income(60000, ['invoice_id' => $invoice->id]), 'matcher', true, 'Matches invoice INV-0001', null, $this->matcher()));
        $this->assertSame([['business_income', $arena->id, $invoice->id, null, 60000, false]], $this->stored($tx));

        // Plain turnover with nothing linked still confirms, as a rule's always has; so does money out, and a cost.
        $plain = $this->tx(25000);
        $this->assertTrue($w->decide($plain, $this->income(25000), 'rule', true, 'Your rule for ACME EVENTS LTD', null, $rule));
        $this->assertSame([['business_income', null, null, null, 25000, true]], $this->stored($plain));
        $back = $this->tx(-5000, 'Refund Acme Events');
        $this->assertTrue($w->decide($back, $this->income(-5000, ['work_job_id' => $arena->id]), 'rule', true, 'Your rule', null, $rule));
        $this->assertSame([['business_income', $arena->id, null, null, -5000, true]], $this->stored($back));
        $refund = $this->tx(1500, 'Adobe refund');
        $this->assertTrue($w->decide($refund, [['kind' => 'business_expense', 'category_id' => $this->category('software'), 'work_job_id' => $arena->id, 'amount_pence' => 1500]], 'rule', true, 'Your rule', null, $rule));
        $this->assertSame([['business_expense', $arena->id, null, null, 1500, true]], $this->stored($refund));

        // You confirm it in one go, as always.
        $yours = $this->tx(60000);
        $this->assertTrue($w->decide($yours, $this->income(60000, ['invoice_id' => $invoice->id])));
        $this->assertSame([['business_income', $arena->id, $invoice->id, null, 60000, true]], $this->stored($yours));
    }

    /**
     * Stage 1 check: automation never sets a client on a cost (only you do: DecisionService::setExpenseClient). A rule's
     * or a matcher's cost line is saved without the client it named; a deduction (the fee taken off money in) keeps its
     * client but, like an income link, is only ever suggested by automation.
     */
    public function test_automation_never_sets_a_client_on_a_cost(): void
    {
        $acme = $this->client();
        $w = $this->writer();
        $rule = new Provenance('rule.owner', [], null);
        $cost = fn (int $pence, array $links = []) => [['kind' => 'business_expense', 'category_id' => $this->category('other'), 'amount_pence' => $pence, 'business_use_pct' => 100] + $links];

        $ticket = $this->tx(-8450, 'Trainline');
        $this->assertTrue($w->decide($ticket, $cost(-8450, ['client_id' => $acme->id]), 'rule', true, 'Your rule for TRAINLINE', null, $rule));
        $this->assertSame([['business_expense', null, null, null, -8450, true]], $this->stored($ticket));
        $tape = $this->tx(-1299, 'Toolstation');
        $this->assertTrue($w->decide($tape, $cost(-1299, ['client_id' => $acme->id]), 'matcher', false, 'Like your other payments', null, $this->matcher('categorise.merchant')));
        $this->assertSame([['business_expense', null, null, null, -1299, false]], $this->stored($tape));
        $cash = ManualExpense::query()->create(['spent_on' => '2026-05-03', 'tax_year' => '2026-27', 'description' => 'Gaffer tape', 'merchant_key' => 'GAFFER TAPE',
            'paid_with' => 'cash', 'amount_pence' => -1299]);
        $this->assertTrue($w->decide($cash, $cost(-1299, ['client_id' => $acme->id]), 'ai', false, 'Looks like tape for a job', null, $this->matcher('ai.advisor')));
        $this->assertSame([['business_expense', null, null, null, -1299, false]], $this->stored($cash));

        // A deduction keeps its client, and a rule only suggests it, even beside plain turnover.
        $net = $this->tx(97000);
        $this->assertTrue($w->decide($net, [...$this->income(100000), ...$cost(-3000, ['client_id' => $acme->id])], 'rule', true, 'Your rule for ACME EVENTS LTD', null, $rule));
        $this->assertSame([['business_income', null, null, null, 100000, false], ['business_expense', null, null, $acme->id, -3000, false]], $this->stored($net));
        $this->assertSame('Only you can accept an income link.', $this->refusal(fn () => AuditContext::with('matcher', fn () => $w->confirm($net->fresh()))));

        // You set it.
        $this->assertTrue($w->decide($ticket->fresh(), $cost(-8450, ['client_id' => $acme->id])));
        $this->assertSame([['business_expense', null, null, $acme->id, -8450, true]], $this->stored($ticket));
        $w->confirm($net->fresh());
        $this->assertSame([true, true], array_column($this->stored($net), 5));
    }

    public function test_confirm_checks_the_invoice_and_who_accepts_an_income_link(): void
    {
        $acme = $this->client();
        $arena = $this->job('Arena load-in', $acme);
        $invoice = $this->invoice($arena, 'INV-0001', 60000);
        $w = $this->writer();

        // A match whose invoice was voided since can't be accepted: nothing confirmed, nothing recorded.
        $tx = $this->tx(60000);
        $w->decide($tx, $this->income(60000, ['invoice_id' => $invoice->id]), 'matcher', false, 'Matches invoice INV-0001', null, $this->matcher());
        $invoice->update(['status' => 'void', 'void_reason' => 'Wrong day rate']);
        $events = AuditEvent::query()->count();
        $this->assertSame('INV-0001 is void: pick the invoice that replaced it.', $this->refusal(fn () => $w->confirm($tx->fresh())));
        $this->assertSame([['business_income', $arena->id, $invoice->id, null, 60000, false]], $this->stored($tx));
        $this->assertSame([$events, 'suggested'], [AuditEvent::query()->count(), $tx->fresh()->review_status]);

        // Only you accept an income link: a matcher, a policy or the AI can't, whatever it's linked to.
        $open = $this->invoice($arena, 'INV-0002', 30000);
        $linked = $this->tx(30000);
        $w->decide($linked, $this->income(30000, ['invoice_id' => $open->id]), 'matcher', false, 'Matches invoice INV-0002', null, $this->matcher());
        $client = $this->tx(20000);
        $w->decide($client, $this->income(20000, ['client_id' => $acme->id]), 'matcher', false, 'Acme Events pays from here', null, $this->matcher('categorise.merchant'));
        foreach (['matcher', 'policy', 'ai'] as $actor) {
            foreach ([$linked, $client] as $source) {
                $this->assertSame('Only you can accept an income link.', $this->refusal(fn () => AuditContext::with($actor, fn () => $w->confirm($source->fresh()))), $actor);
            }
        }
        $this->assertSame([false, false], [$this->stored($linked)[0][5], $this->stored($client)[0][5]]);
        // A plain income guess may still be settled by a matcher; and you accept the links.
        $plain = $this->tx(10000);
        $w->decide($plain, $this->income(10000), 'matcher', false, 'Looks like turnover', null, $this->matcher('categorise.merchant'));
        AuditContext::with('matcher', fn () => $w->confirm($plain->fresh()));
        $this->assertSame([['business_income', null, null, null, 10000, true]], $this->stored($plain));
        $w->confirm($linked->fresh());
        $w->confirm($client->fresh());
        $this->assertSame([[true], [true]], [array_map(fn ($l) => $l[5], $this->stored($linked)), array_map(fn ($l) => $l[5], $this->stored($client))]);
        $this->assertSame('you', Allocation::query()->where('bank_transaction_id', $linked->id)->value('confirmed_by'));

        // The other side of a transfer you decided is still settled by the matcher.
        $savings = Account::query()->create(['name' => 'Savings', 'kind' => 'bank', 'provider' => 'starling']);
        $out = $this->tx(-20000, 'To savings');
        $in = $this->tx(20000, 'From current account', account: $savings);
        $in->forceFill(['transfer_pair_id' => $out->id])->save();
        $out->forceFill(['transfer_pair_id' => $in->id])->save();
        foreach ([$out, $in] as $side) {
            $w->decide($side->fresh(), AllocationWriter::lines($side->amount_pence, 'transfer'), 'matcher', false, 'Money moving between your accounts', null, $this->matcher('transfer.pair'));
        }
        $w->decide($out->fresh(), AllocationWriter::lines(-20000, 'transfer'));
        $this->assertSame([['transfer', null, null, null, 20000, true]], $this->stored($in));
        $this->assertSame('matcher', Allocation::query()->where('bank_transaction_id', $in->id)->value('confirmed_by'));
    }

    public function test_a_locked_year_changes_nothing_and_the_chain_verifies(): void
    {
        $acme = $this->client();
        $arena = $this->job('Arena load-in', $acme);
        $invoice = $this->invoice($arena, 'INV-0001', 60000);
        $w = $this->writer();
        $old = $this->tx(60000, 'Acme Events Ltd', '2025-05-10');
        $w->decide($old, $this->income(60000, ['work_job_id' => $arena->id]), 'matcher', false, 'Paid for Arena load-in', null, $this->matcher('jobs.gather'));
        TaxYear::track('2025-26', 'filed')->update(['locked_at' => now()]);
        $events = AuditEvent::query()->count();
        $locked = 'Tax year 2025-26 is locked. Unlock it (with a reason) before changing anything in it.';
        $invoice->update(['status' => 'void', 'void_reason' => 'Wrong day rate']);

        $this->assertSame([$locked, $locked, $locked, $locked], [
            $this->refusal(fn () => $w->decide($old, $this->income(60000, ['client_id' => $acme->id]))),
            $this->refusal(fn () => $w->decide($old, $this->income(60000, ['invoice_id' => $invoice->id]))),
            $this->refusal(fn () => $w->decide($old, $this->income(60000, ['invoice_id' => $invoice->id]), 'matcher', false, 'Matches', null, $this->matcher())),
            $this->refusal(fn () => $w->confirm($old)),
        ]);
        $this->assertSame([['business_income', $arena->id, null, null, 60000, false]], $this->stored($old));
        $this->assertSame($events, AuditEvent::query()->count());

        // Everything recorded here, and above, still verifies as one chain.
        $this->assertTrue(AuditLog::verify()['ok']);
    }
}
