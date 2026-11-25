<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Http\Presenters\Present;
use App\Models\Allocation;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Veto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Build slice S9 (income v3's pages): the drawer and the Inbox carry the chips, links and suggestion text for money
 * in (and keep "Jobs around then" for money out), the lookups the pickers search, the invoice page's payments and
 * state with close, reopen and replaced-by, Transactions' client filters, the job page's fee search by payer, and the
 * year page's group label.
 */
class IncomePagesTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        $this->signIn();
        $this->acme = $this->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function suggest(BankTransaction $tx, array $lines, string $method = 'suggest.invoice'): void
    {
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines, 'matcher', false, 'Suggested', null, new Provenance($method)));
    }

    /** One earlier payment from ACME PAYROLL you confirmed for Acme Events: the payer's sole client. */
    private function knownPayer(): void
    {
        $this->decide($this->tx(10000, 'ACME PAYROLL', '2026-04-20'), [$this->income(10000, ['client_id' => $this->acme->id])]);
    }

    public function test_the_drawer_carries_chips_and_links_for_money_in_and_keeps_jobs_around_then_for_money_out(): void
    {
        $this->knownPayer();
        $show = $this->job('Show A', $this->acme, '2026-05-27');
        $invoice = $this->invoice('INV-0042', 45000, $show, $this->acme);
        $in = $this->tx(45000);
        $this->suggest($in, [$this->income(45000, ['invoice_id' => $invoice->id])]);

        $json = $this->getJson("/items/bank/{$in->id}")->assertOk()->json();
        $line = $json['item']['allocations'][0];
        $this->assertSame([$invoice->id, 'INV-0042', null, 'Acme Events'], [$line['invoice_id'], $line['invoice_number'], $line['client_id'], $line['client']]);
        $this->assertSame([], $json['jobs_near']);
        $this->assertSame('invoice', $json['targets'][0]['kind']);
        $this->assertSame('INV-0042 · Show A · £450', $json['targets'][0]['label']);
        $this->assertSame(['ACME PAYROLL · usually Acme Events (1 payment)', $this->acme->id, 'Acme Events'],
            [$json['income']['payer'], $json['income']['client_id'], $json['income']['client']]);

        $out = $this->tx(-2340, 'UBER', '2026-05-27');
        $json = $this->getJson("/items/bank/{$out->id}")->assertOk()->json();
        $this->assertSame([['id' => $show->id, 'title' => 'Show A', 'client' => 'Acme Events']], $json['jobs_near']);
        $this->assertNull($json['income']);
    }

    public function test_editing_a_split_of_an_invoice_linked_payment_keeps_every_link_and_says_no_to_nothing(): void
    {
        $a = $this->invoice('INV-0041', 60000, null, $this->acme);
        $c = $this->invoice('INV-0043', 75000, null, $this->acme);
        $tx = $this->tx(135000);
        $this->decide($tx, [$this->income(60000, ['invoice_id' => $a->id]), $this->income(75000, ['invoice_id' => $c->id])]);

        // The split editor sends back what it loaded (Present::allocation), with a note changed.
        $loaded = $this->getJson("/items/bank/{$tx->id}")->json('item.allocations');
        $lines = array_map(fn (array $l) => ['kind' => $l['kind'], 'category_id' => $l['category_id'], 'work_job_id' => $l['work_job_id'], 'amount_pence' => $l['amount_pence'],
            'business_use_pct' => $l['business_use_pct'], 'note' => 'Checked', 'invoice_id' => $l['invoice_id'], 'client_id' => $l['client_id']], $loaded);
        $this->visit('POST', "/items/bank/{$tx->id}/decide", ['lines' => $lines, 'shown' => array_column($loaded, 'id')])->assertSessionHas('success');

        $this->assertSame([['business_income', null, $a->id, null, 60000, true], ['business_income', null, $c->id, null, 75000, true]], $this->stored($tx));
        $this->assertSame(0, Veto::query()->count());
    }

    public function test_inbox_money_in_has_chips_and_suggestion_text_and_no_jobs_around_then(): void
    {
        $this->knownPayer();
        $show = $this->job('Show A', $this->acme, '2026-05-27');
        $invoice = $this->invoice('INV-0042', 45000, $show, $this->acme);
        $in = $this->tx(45000);
        $this->suggest($in, [$this->income(45000, ['invoice_id' => $invoice->id])]);
        $out = $this->tx(-2340, 'UBER', '2026-05-27');

        $this->get('/inbox')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('inbox', false)
            ->where('items', function ($items) use ($in, $out, $show) {
                $byId = collect($items)->keyBy('id');
                $this->assertSame([], $byId[$in->id]['jobs_near']);
                $this->assertSame('INV-0042 £450 · Acme Events', $byId[$in->id]['suggestion_text']);
                $this->assertSame('invoice', $byId[$in->id]['targets'][0]['kind']);
                $this->assertSame('ACME PAYROLL · usually Acme Events (1 payment)', $byId[$in->id]['payer']);
                $this->assertSame($show->id, $byId[$out->id]['jobs_near'][0]['id']);
                $this->assertNull($byId[$out->id]['payer']);

                return true;
            })->has('lookups.clients', 1));
    }

    public function test_deciding_with_a_chips_payload_over_a_categoriser_guess_saves_its_invoice_and_client(): void
    {
        $this->knownPayer();
        $show = $this->job('Show A', $this->acme, '2026-05-27');
        $invoice = $this->invoice('INV-0042', 45000, $show, $this->acme);
        $in = $this->tx(45000);
        $this->suggest($in, [$this->income(45000)], 'categorise.hint');
        $chip = collect($this->getJson("/items/bank/{$in->id}")->json('targets'))->firstWhere('kind', 'invoice');

        $this->visit('POST', "/items/bank/{$in->id}/decide", ['lines' => $chip['lines']])->assertSessionHas('success');
        $this->assertSame([['business_income', $show->id, $invoice->id, null, 45000, true]], $this->stored($in));
    }

    public function test_lookups_find_old_jobs_whats_owed_and_open_invoices(): void
    {
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $day = CarbonImmutable::parse('2026-05-01')->subDays($i)->format('Y-m-d');
            $rows[] = ['title' => $i === 599 ? 'Old Pier gala' : 'Show '.$i, 'starts_on' => $day, 'ends_on' => $day, 'status' => 'done', 'po_not_required' => false,
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('work_jobs')->insert($chunk);
        }
        $found = $this->getJson('/lookups/jobs?q=pier')->assertOk()->json('jobs');
        $this->assertSame(['Old Pier gala'], array_column($found, 'title'));
        $this->assertCount(30, $this->getJson('/lookups/jobs?on=2026-04-01')->json('jobs'));

        // Owed: the client's open invoice, an issued invoice with no client billed to their name, and a job not billed.
        $show = $this->job('Show B', $this->acme, '2026-05-20');
        $mine = $this->invoice('INV-0050', 30000, null, $this->acme);
        $byName = $this->invoice('INV-0051', 20000, null, null, ['bill_to_name' => 'ACME EVENTS LIMITED']);
        $this->invoice('INV-0052', 20000, null, null, ['bill_to_name' => 'Someone Else']);
        $owed = $this->getJson('/lookups/owed?client='.$this->acme->id)->assertOk()->json();
        $this->assertSame([$mine->id, $byName->id], array_column($owed['invoices'], 'id'));
        $this->assertContains($show->id, array_column($owed['jobs'], 'id'));
        $this->assertSame('Still owed by Acme Events', $owed['label']);
        $this->getJson('/lookups/owed?client=99999')->assertStatus(422)->assertJsonValidationErrors(['client' => 'That client no longer exists: pick another.']);

        // Open invoices by number, or by amount (total, or what's still to come).
        $this->assertSame(['INV-0050'], array_column($this->getJson('/lookups/invoices?q=0050')->json('invoices'), 'number'));
        $this->assertSame(['INV-0050'], array_column($this->getJson('/lookups/invoices?q=300.00')->json('invoices'), 'number'));
        $this->decide($this->tx(5000, 'ACME PAYROLL'), [$this->income(5000, ['invoice_id' => $byName->id])]);
        $this->assertSame(['INV-0051'], array_column($this->getJson('/lookups/invoices?q=150')->json('invoices'), 'number'));
    }

    public function test_the_invoice_page_shows_payments_and_state_and_closes_reopens_and_replaces(): void
    {
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0042', 45000, $show, $this->acme);
        $this->decide($this->tx(30000), [$this->income(30000, ['invoice_id' => $invoice->id])]);
        $waiting = $this->tx(5000);
        $this->suggest($waiting, [$this->income(5000, ['invoice_id' => $invoice->id])]);

        $this->get("/invoices/{$invoice->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('invoices/show', false)
            ->where('issued.state', 'part_paid')->where('issued.state_words', 'Part paid £300 of £450')->where('issued.outstanding_minor', 15000)
            ->has('issued.payments', 2)->where('issued.payments.0.linked', true)->where('issued.payments.1.waiting', true)
            ->where('invoice.state', 'part_paid'));

        // Close: refused while a payment that may be for it waits; then allowed, and reopened.
        $this->visit('POST', "/invoices/{$invoice->id}/close", ['reason' => 'short_paid'], "/invoices/{$invoice->id}")
            ->assertSessionHas('error', 'Decide the payment waiting in the Inbox first: it may be for INV-0042.');
        app(AllocationWriter::class)->clear($waiting);
        $this->visit('POST', "/invoices/{$invoice->id}/close", ['reason' => 'nope'], "/invoices/{$invoice->id}")->assertSessionHasErrors(['reason' => 'Say why it’s being closed.']);
        $this->visit('POST', "/invoices/{$invoice->id}/close", ['reason' => 'short_paid', 'note' => 'Agreed £300'], "/invoices/{$invoice->id}")
            ->assertSessionHas('success', 'Closed INV-0042: £150 isn’t coming. You’re only taxed on what came in, so there’s nothing to claim for it.');
        $this->assertSame(15000, $invoice->fresh()->closed_minor);
        $this->visit('POST', "/invoices/{$invoice->id}/reopen", [], "/invoices/{$invoice->id}")->assertSessionHas('success', 'Reopened: INV-0042 is owed again.');
        $this->assertNull($invoice->fresh()->closed_minor);

        // A void invoice with a payment: what replaced it.
        $old = $this->invoice('INV-0040', 20000, null, $this->acme);
        $this->decide($this->tx(20000), [$this->income(20000, ['invoice_id' => $old->id])]);
        $old->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong rate']);
        $this->get("/invoices/{$old->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('issued.replace_with', fn ($rows) => in_array($invoice->id, array_column($rows->all(), 'id'), true)));
        $this->visit('POST', "/invoices/{$old->id}/replaced-by", ['invoice_id' => $invoice->id], "/invoices/{$old->id}")
            ->assertSessionHas('success', 'Noted: INV-0042 replaced INV-0040. What was paid against it counts there.');
        $this->assertSame($invoice->id, $old->fresh()->replaced_by_id);
    }

    public function test_transactions_filter_by_client_and_by_income_not_assigned(): void
    {
        $show = $this->job('Show A', $this->acme);
        $onJob = $this->tx(30000);
        $this->decide($onJob, [$this->income(30000, ['work_job_id' => $show->id])]);
        $clientOnly = $this->tx(20000, 'ACME LTD');
        $this->decide($clientOnly, [$this->income(20000, ['client_id' => $this->acme->id])]);
        $loose = $this->tx(10000, 'SOMEONE');
        $this->decide($loose, [$this->income(10000)]);
        $other = Client::query()->create(['name' => 'Other Ltd']);
        $theirs = $this->tx(5000, 'OTHER');
        $this->decide($theirs, [$this->income(5000, ['client_id' => $other->id])]);

        $ids = fn (string $query) => collect($this->get('/transactions?'.$query)->assertOk()->viewData('page')['props']['page']['data'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$onJob->id, $clientOnly->id], $ids('client='.$this->acme->id));
        $this->assertSame([$loose->id], $ids('unassigned=1'));
    }

    public function test_the_job_page_fee_search_finds_a_known_payer_and_the_year_page_names_the_group(): void
    {
        $this->knownPayer();
        $show = $this->job('Show A', $this->acme, '2026-05-20');
        $fee = $this->tx(77700, 'ACME PAYROLL', '2026-06-01');
        $this->get("/jobs/{$show->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('near', fn ($near) => collect($near)->contains('id', $fee->id)));

        // The year page: a money-in group whose suggestions are income, named with the client and its invoice matches;
        // "Decide all" starts from the payer's sole client.
        $invoice = $this->invoice('INV-0042', 45000, $show, $this->acme);
        $fee->delete();
        $a = $this->tx(45000, 'ACME PAYROLL', '2026-05-28');
        $this->suggest($a, [$this->income(45000, ['invoice_id' => $invoice->id])]);
        $b = $this->tx(20000, 'ACME PAYROLL', '2026-05-29');
        $this->suggest($b, [$this->income(20000, ['client_id' => $this->acme->id])], 'categorise.hint');
        $this->get('/years/2026-27')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('groups', function ($groups) {
            $g = collect($groups)->firstWhere('merchant_key', 'ACME PAYROLL');
            $this->assertSame('Business income · Acme Events · 1 invoice match', $g['suggestion']);
            $this->assertSame([$this->acme->id, 'Acme Events'], [$g['client_id'], $g['client']]);

            return true;
        })->where('unlock_note', 'Changing a client, job or invoice doesn’t change your return.'));
    }

    public function test_a_line_shows_its_worked_out_client_through_a_merge(): void
    {
        $dupe = Client::query()->create(['name' => 'ACME EVENTS LTD']);
        $tx = $this->tx(20000);
        $this->decide($tx, [$this->income(20000, ['client_id' => $dupe->id])]);
        $dupe->forceFill(['merged_into_id' => $this->acme->id])->save();
        $line = Allocation::query()->with(['invoice.client.mergedInto', 'job.client.mergedInto', 'client.mergedInto'])->where('bank_transaction_id', $tx->id)->first();

        $this->assertSame('Acme Events', Present::allocation($line)['client']);
        $this->assertNull(Present::allocation(Allocation::query()->where('bank_transaction_id', $tx->id)->first())['client'], 'nothing loaded: no extra query');
    }

    public function test_the_inbox_and_transactions_make_the_same_number_of_queries_however_many_money_in_suggestions(): void
    {
        $show = $this->job('Show A', $this->acme, '2026-05-20');
        $this->invoice('INV-0042', 45000, $show, $this->acme);
        $k = 0;
        // Each from a payer of its own (client names, merges and a payer's usual fee read for the page, not per payment).
        $grow = function (int $n) use ($show, &$k) {
            for ($i = 0; $i < $n; $i++) {
                $k++;
                $this->suggest($this->tx(700 + $k, 'PAYER X'.$k, '2026-05-21'), [$this->income(700 + $k, ['work_job_id' => $show->id])]);
            }
        };
        $grow(2);
        foreach (['/inbox', '/transactions?year=2026-27'] as $uri) {
            $this->get($uri)->assertOk();
            $few = $this->queries(fn () => $this->get($uri)->assertOk());
            $grow(12);
            $this->get($uri)->assertOk();
            $many = $this->queries(fn () => $this->get($uri)->assertOk());
            $this->assertSame($few, $many, $uri);
        }
    }
}
