<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Ledger\SourceState;
use App\Domain\Todo\Fingerprints;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\ManualExpense;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Models\Veto;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Build slice S3 (income v3's owner decision service): DecisionService maps invoice_id and client_id only when a line
 * or decision names them, keeps what a payment had when your decision says the same (keepInvoice, keepClient), never
 * carries links over or touches invoice matches in bulk, and sets who money in was from (setClient) without flattening
 * your splits. SourceState is a payment's decision exactly as to-do buttons have always fingerprinted it.
 */
class DecisionServiceTest extends TestCase
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

    private function invoice(string $number, int $total, ?WorkJob $job = null, ?Client $client = null): Invoice
    {
        return Invoice::query()->create(['status' => 'issued', 'number' => $number, 'client_id' => $client?->id, 'work_job_id' => $job?->id, 'currency' => 'GBP',
            'total_minor' => $total, 'bill_to_name' => 'Acme Events', 'issue_date' => '2026-05-15', 'due_date' => '2026-06-14']);
    }

    private function tx(int $pence, string $key = 'ACME PAYROLL', string $on = '2026-05-28'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('f', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $this->n++;

        return BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $key, 'counterparty' => $key, 'merchant_key' => $key, 'amount_pence' => $pence,
            'raw' => [], 'fingerprint' => 'decisions-'.$this->n]);
    }

    private function income(int $pence, array $links = []): array
    {
        return ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'amount_pence' => $pence] + $links;
    }

    private function suggest(BankTransaction $tx, array $lines, string $method = 'suggest.invoice'): void
    {
        $by = ['ai.assistant' => 'ai', 'rule.owner' => 'rule'][$method] ?? 'matcher';
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines, $by, false, 'Suggested', null, new Provenance($method)));
    }

    /** @return list<array{0:string, 1:?int, 2:?int, 3:?int, 4:int, 5:bool}> kind, job, invoice, client, amount, confirmed */
    private function stored(BankTransaction $tx): array
    {
        return Allocation::query()->where('bank_transaction_id', $tx->id)->orderBy('id')->get()
            ->map(fn (Allocation $a) => [$a->kind, $a->work_job_id, $a->invoice_id, $a->client_id, $a->amount_pence, $a->confirmed_at !== null])->all();
    }

    private function vetoes(BankTransaction $tx, string $relation = 'invoice_payment'): array
    {
        return Veto::query()->active()->where('subject_ref', 'tx:'.$tx->id)->where('relation', $relation)->pluck('object_ref')->all();
    }

    public function test_a_decision_without_the_keys_keeps_the_invoice_and_the_client_when_it_says_the_same(): void
    {
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0001', 60000, $show, $this->acme);
        $matched = $this->tx(60000);
        $this->suggest($matched, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $clientOnly = $this->tx(30000, 'ACME EVENTS LTD');
        $this->suggest($clientOnly, [$this->income(30000, ['client_id' => $this->acme->id])], 'categorise.hint');

        // The page's old payload: kind, category, job; no invoice_id or client_id.
        $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $show->id, 'note' => 'Paid at last'], collect([$matched]));
        $r = $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover')], collect([$clientOnly]));

        $this->assertSame([['business_income', $show->id, $invoice->id, null, 60000, true]], $this->stored($matched));
        $this->assertSame([['business_income', null, null, $this->acme->id, 30000, true]], $this->stored($clientOnly));
        $this->assertSame([], $this->vetoes($matched));
        $this->assertSame(['saved', 'skipped', 'errors', 'learned', 'left'], array_keys($r));
        $this->assertSame(DecisionService::NONE_LEFT, $r['left']);
    }

    /**
     * Stage 1 check: a suggestion whose invoice was voided since isn't confirmed as paying it by Save (Accept refuses it):
     * Save carries no void invoice over, and naming it on lines that only the suggestion had is refused as Accept is. A
     * line you confirmed keeps its invoice when you save it again.
     */
    public function test_saving_a_suggestion_whose_invoice_was_voided_confirms_no_void_link(): void
    {
        $show = $this->job('Gala dinner', $this->acme);
        $void = $this->invoice('INV-0201', 45000, $show, $this->acme);
        $tx = $this->tx(45000, 'ACME EVENTS LTD');
        $this->suggest($tx, [$this->income(45000, ['invoice_id' => $void->id])]);
        $void->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong day rate']);
        $refused = function (callable $do): ?string {
            try {
                $do();
            } catch (InvalidArgumentException $e) {
                return $e->getMessage();
            }

            return null;
        };
        $this->assertSame('INV-0201 is void: pick the invoice that replaced it.', $refused(fn () => app(AllocationWriter::class)->confirm($tx)));

        // Your lines naming it, as the suggestion did: refused like Accept.
        $r = $this->service()->apply(['lines' => [$this->income(45000, ['work_job_id' => $show->id, 'invoice_id' => $void->id])]], collect([$tx]));
        $this->assertSame([0, ['INV-0201 is void: pick the invoice that replaced it.']], [$r['saved'], $r['errors']]);
        $this->assertSame([['business_income', $show->id, $void->id, null, 45000, false]], $this->stored($tx), 'still the open suggestion');

        // Save with the form as loaded: the job's income, no void invoice.
        $r = $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $show->id], collect([$tx]));
        $this->assertSame([1, []], [$r['saved'], $r['errors']]);
        $this->assertSame([['business_income', $show->id, null, null, 45000, true]], $this->stored($tx));

        // Confirmed for an invoice that's voided afterwards: saving it again keeps the invoice, as before.
        $paid = $this->invoice('INV-0202', 30000, $show, $this->acme);
        $mine = $this->tx(30000, 'ACME EVENTS LTD');
        app(AllocationWriter::class)->decide($mine, [$this->income(30000, ['invoice_id' => $paid->id])]);
        $paid->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong client']);
        $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $show->id, 'note' => 'Checked'], collect([$mine]));
        $this->assertSame([['business_income', $show->id, $paid->id, null, 30000, true]], $this->stored($mine));
    }

    public function test_a_named_link_is_yours_and_null_takes_it_off(): void
    {
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0001', 60000, $show, $this->acme);
        $other = $this->invoice('INV-0002', 60000, null, $this->acme);

        // invoice_id null: the link goes, and the suggestion's invoice is remembered as not this payment's.
        $tx = $this->tx(60000);
        $this->suggest($tx, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $show->id, 'invoice_id' => null], collect([$tx]));
        $this->assertSame([['business_income', $show->id, null, null, 60000, true]], $this->stored($tx));
        $this->assertSame(['inv:'.$invoice->id], $this->vetoes($tx));

        // A named invoice and client on the business line; the personal part never carries one.
        $named = $this->tx(60000);
        $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'invoice_id' => $other->id], collect([$named]));
        $this->assertSame([['business_income', null, $other->id, null, 60000, true]], $this->stored($named));
        $cost = $this->tx(-10000, 'ADOBE');
        $this->service()->apply(['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'business_pct' => 60, 'client_id' => $this->acme->id], collect([$cost]));
        $this->assertSame([['business_expense', null, null, $this->acme->id, -6000, true], ['personal', null, null, null, -4000, true]], $this->stored($cost));

        // A client named as null takes the client off.
        $loose = $this->tx(20000);
        $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'client_id' => $this->acme->id], collect([$loose]));
        $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'client_id' => null], collect([$loose]));
        $this->assertSame([['business_income', null, null, null, 20000, true]], $this->stored($loose));
    }

    public function test_a_split_keeps_each_lines_invoice_and_client_and_shown_still_binds_what_you_said_no_to(): void
    {
        $a = $this->invoice('INV-0041', 60000, null, $this->acme);
        $c = $this->invoice('INV-0043', 75000, null, $this->acme);
        $tx = $this->tx(132000);
        $this->service()->apply(['lines' => [
            $this->income(60000, ['invoice_id' => $a->id]),
            $this->income(75000, ['invoice_id' => $c->id]),
            ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000, 'client_id' => $this->acme->id],
        ]], collect([$tx]));
        $this->assertSame([
            ['business_income', null, $a->id, null, 60000, true],
            ['business_income', null, $c->id, null, 75000, true],
            ['business_expense', null, null, $this->acme->id, -3000, true],
        ], $this->stored($tx));

        // What the page showed was replaced since: deciding differently doesn't say no to the new suggestion.
        $shown = $this->tx(60000);
        $this->suggest($shown, [$this->income(60000, ['invoice_id' => $a->id])]);
        $was = Allocation::query()->where('bank_transaction_id', $shown->id)->pluck('id')->all();
        $this->suggest($shown, [$this->income(60000, ['invoice_id' => $c->id])], 'rule.owner');
        $this->service()->apply(['kind' => 'personal', 'shown' => $was], collect([$shown]));
        $this->assertSame([], $this->vetoes($shown));
    }

    public function test_bulk_never_carries_links_over_and_leaves_invoice_matches_and_splits(): void
    {
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0001', 60000, $show, $this->acme);
        $matched = $this->tx(60000);
        $this->suggest($matched, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $clientOnly = $this->tx(30000);
        $this->suggest($clientOnly, [$this->income(30000, ['client_id' => $this->acme->id])], 'categorise.hint');
        $split = $this->tx(50000);
        app(AllocationWriter::class)->decide($split, [$this->income(20000, ['work_job_id' => $show->id]), $this->income(30000)]);
        $plain = $this->tx(40000);

        $r = $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover')], collect([$matched, $clientOnly, $split, $plain]), true, bulk: true, keepSplits: true);

        $this->assertSame(['invoice' => 1, 'split' => 1, 'locked' => 0, 'suggestion' => 0, 'other' => 0], $r['left']);
        $this->assertSame(2, $r['saved']);
        $this->assertSame([['business_income', $show->id, $invoice->id, null, 60000, false]], $this->stored($matched), 'the invoice match is left for you');
        $this->assertSame([], $this->vetoes($matched), 'and nothing is said no to');
        $this->assertSame([['business_income', null, null, null, 30000, true]], $this->stored($clientOnly), 'keepClient doesn’t run in bulk');
        $this->assertCount(2, $this->stored($split));

        // Bulk with a client gives client-only lines.
        $more = [$this->tx(11000), $this->tx(12000)];
        $r = $this->service()->apply(['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'client_id' => $this->acme->id], collect($more), true, bulk: true);
        $this->assertSame(2, $r['saved']);
        foreach ($more as $tx) {
            $this->assertSame($this->acme->id, $this->stored($tx)[0][3]);
        }
    }

    public function test_set_client_turns_undecided_money_in_and_guesses_into_the_clients_turnover(): void
    {
        $undecided = $this->tx(45000);
        $guessed = $this->tx(30000);
        $this->suggest($guessed, [['kind' => 'personal', 'amount_pence' => 30000]], 'categorise.hint');

        $r = $this->service()->setClient(collect([$undecided, $guessed]), $this->acme->id);

        $this->assertSame(2, $r['saved']);
        $this->assertSame([['business_income', null, null, $this->acme->id, 45000, true]], $this->stored($undecided));
        $this->assertSame([['business_income', null, null, $this->acme->id, 30000, true]], $this->stored($guessed));
        $this->assertSame($this->cat('turnover'), (int) Allocation::query()->where('bank_transaction_id', $undecided->id)->value('category_id'));
        $this->assertCount(1, $this->vetoes($guessed, 'allocation_shape'), 'the guess you decided differently from is remembered');

        // Another payment waiting from the same payer learns from it.
        $waiting = $this->tx(25000, 'ACME PAYROLL', '2026-05-29');
        $r = $this->service()->setClient(collect([$this->tx(26000, 'ACME PAYROLL', '2026-05-27')]), $this->acme->id);
        $this->assertSame(1, $r['saved']);
        $this->assertNotSame([], $this->stored($waiting));
    }

    public function test_set_client_rebuilds_your_lines_without_flattening_and_leaves_what_it_shouldnt_change(): void
    {
        $other = Client::query()->create(['name' => 'Other Ltd']);
        $theirs = $this->job('Their show', $other);
        $loose = $this->job('Loose show');
        $w = app(AllocationWriter::class);

        // Your split: the part on a job with a client stays that client's; the rest becomes Acme's.
        $split = $this->tx(50000);
        $w->decide($split, [$this->income(20000, ['work_job_id' => $theirs->id]), $this->income(25000, ['work_job_id' => $loose->id]),
            $this->income(8000), ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000]]);
        // Already Acme's.
        $done = $this->tx(10000);
        $w->decide($done, [$this->income(10000, ['client_id' => $this->acme->id])]);
        // A suggestion naming a job; one naming an invoice; your decision of another kind; money out; a transfer; a locked year.
        $jobSuggestion = $this->tx(20000);
        $this->suggest($jobSuggestion, [$this->income(20000, ['work_job_id' => $loose->id])], 'jobs.gather');
        $invoiceSuggestion = $this->tx(20000);
        $this->suggest($invoiceSuggestion, [$this->income(20000, ['invoice_id' => $this->invoice('INV-0001', 20000, null, $this->acme)->id])]);
        $personal = $this->tx(15000);
        $w->decide($personal, [['kind' => 'personal', 'amount_pence' => 15000]]);
        $out = $this->tx(-5000);
        $transfer = $this->tx(7000);
        $transfer->forceFill(['transfer_pair_id' => $out->id])->saveQuietly();
        $old = $this->tx(9000, 'ACME PAYROLL', '2025-05-20');
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();

        $r = $this->service()->setClient(collect([$split, $done, $jobSuggestion, $invoiceSuggestion, $personal, $out, $transfer, $old]), $this->acme->id);

        $this->assertSame(['invoice' => 0, 'split' => 0, 'locked' => 1, 'suggestion' => 2, 'other' => 3], $r['left']);
        $this->assertSame([1, 1], [$r['saved'], $r['already']]);
        $this->assertSame([
            ['business_income', $theirs->id, null, null, 20000, true],
            ['business_income', $loose->id, null, $this->acme->id, 25000, true],
            ['business_income', null, null, $this->acme->id, 8000, true],
            ['business_expense', null, null, $this->acme->id, -3000, true],
        ], $this->stored($split));
        $this->assertSame([['business_income', $loose->id, null, null, 20000, false]], $this->stored($jobSuggestion));
        $this->assertSame([], $this->stored($old));

        $this->expectException(InvalidArgumentException::class);
        AuditContext::with('policy', fn () => $this->service()->setClient(collect([$this->tx(1000)]), $this->acme->id));
    }

    public function test_source_state_is_the_decision_fingerprints_have_always_used(): void
    {
        $fp = new ReflectionMethod(Fingerprints::class, 'tx');
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0001', 60000, $show, $this->acme);
        $undecided = $this->tx(10000);
        $suggested = $this->tx(60000);
        $this->suggest($suggested, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $mine = $this->tx(-10000, 'ADOBE');
        app(AllocationWriter::class)->decide($mine, AllocationWriter::lines(-10000, 'business_expense', $this->cat('other'), $show->id, 60));
        $paired = $this->tx(-5000, 'SAVINGS');
        $paired->forceFill(['transfer_pair_id' => $undecided->id])->saveQuietly();

        foreach ([$undecided, $suggested, $mine, $paired] as $tx) {
            $this->assertSame($fp->invoke(null, $tx->id), SourceState::of($tx));
            $this->assertSame(Fingerprints::of('tx_accept', (string) $tx->id), SourceState::fingerprint($tx));
            $this->assertTrue(SourceState::matches($tx, Fingerprints::of('tx_accept', (string) $tx->id)));
        }
        $this->assertNull(SourceState::ofTransaction(999999));

        // A change of the decision changes it, so a request made on what was shown is refused.
        $before = SourceState::fingerprint($suggested);
        app(AllocationWriter::class)->confirm($suggested);
        $this->assertFalse(SourceState::matches($suggested, $before));
        $this->assertFalse(SourceState::matches($suggested, null));

        // A manual expense, in the same shape.
        $cash = ManualExpense::query()->create(['spent_on' => '2026-05-20', 'tax_year' => '2026-27', 'description' => 'Cable ties', 'merchant_key' => 'CABLE TIES',
            'paid_with' => 'cash', 'amount_pence' => -1299]);
        app(AllocationWriter::class)->decide($cash, AllocationWriter::lines(-1299, 'business_expense', $this->cat('other'), $show->id));
        $state = SourceState::of($cash);
        $this->assertSame(['confirmed', null], [$state['status'], $state['pair']]);
        $this->assertSame([['business_expense', $this->cat('other'), $show->id, null, -1299, 100, 'you', true]], $state['lines']);
        $this->assertTrue(SourceState::matches($cash, SourceState::fingerprint($cash)));
    }

    /**
     * Stage 1 check: a line's own client is part of the state only when it has one. A line without one keeps exactly
     * the state and hash to-do buttons were given before, and a suggestion whose only change is its client is a change,
     * so a button shown for "Turnover · Acme Events" doesn't accept "Turnover · Beta Crew".
     */
    public function test_source_state_has_a_lines_own_client_only_when_it_has_one(): void
    {
        // The hash a to-do button was given before clients were kept on lines.
        $before = function (BankTransaction $tx): string {
            $lines = Allocation::query()->where('bank_transaction_id', $tx->id)->get()
                ->map(fn (Allocation $a) => [$a->kind, $a->category_id === null ? null : (int) $a->category_id, $a->work_job_id === null ? null : (int) $a->work_job_id,
                    $a->invoice_id === null ? null : (int) $a->invoice_id, (int) $a->amount_pence, $a->business_use_pct === null ? null : (int) $a->business_use_pct,
                    $a->decided_by, $a->confirmed_at !== null])
                ->sortBy(fn ($l) => json_encode($l))->values()->all();
            $tx->refresh();

            return substr(hash('sha256', json_encode(['tx_accept', (string) $tx->id, ['status' => $tx->review_status, 'pair' => $tx->transfer_pair_id, 'lines' => $lines]])), 0, 6);
        };
        $show = $this->job('Show A', $this->acme);
        $plain = $this->tx(45000);
        $this->suggest($plain, [$this->income(45000, ['work_job_id' => $show->id])], 'categorise.merchant');
        $this->assertSame($before($plain), SourceState::fingerprint($plain));
        $this->assertSame($before($plain), Fingerprints::of('tx_accept', (string) $plain->id));

        $beta = Client::query()->create(['name' => 'Beta Crew']);
        $tx = $this->tx(45000, 'ACME EVENTS LTD');
        $this->suggest($tx, [$this->income(45000, ['client_id' => $this->acme->id])], 'categorise.merchant');
        $this->assertSame([['business_income', $this->cat('turnover'), null, null, 45000, null, 'matcher', false, $this->acme->id]], SourceState::of($tx)['lines']);
        $shown = SourceState::fingerprint($tx);
        $this->assertSame($shown, Fingerprints::of('tx_accept', (string) $tx->id));

        $this->suggest($tx, [$this->income(45000, ['client_id' => $beta->id])], 'categorise.merchant');
        $this->assertFalse(SourceState::matches($tx, $shown), 'a button shown for Acme Events doesn\'t accept Beta Crew');
        $this->assertNotSame($shown, Fingerprints::of('tx_accept', (string) $tx->id));
    }
}
