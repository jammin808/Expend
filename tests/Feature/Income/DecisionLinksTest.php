<?php

namespace Tests\Feature\Income;

use App\Domain\Intel\Provenance;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\TaxYear;
use App\Models\Veto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Build slice S9 (income v3's owner HTTP): the decide, bulk, bulk-client and confirm endpoints carry invoice and
 * client links as DecisionService takes them, refuse an invoice for many at once, leave invoice matches and splits in
 * bulk (and say so), and "New client" reuses the client you have.
 */
class DecisionLinksTest extends TestCase
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

    private function suggest($tx, array $lines, string $method = 'suggest.invoice'): void
    {
        $by = ['categorise.hint' => 'matcher', 'jobs.gather' => 'matcher'][$method] ?? 'matcher';
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, $lines, $by, false, 'Suggested', null, new Provenance($method)));
    }

    private function vetoes($tx): array
    {
        return Veto::query()->active()->where('subject_ref', 'tx:'.$tx->id)->where('relation', 'invoice_payment')->pluck('object_ref')->all();
    }

    public function test_a_legacy_payload_keeps_the_invoice_and_the_client_and_null_takes_the_link_off(): void
    {
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0001', 60000, $show, $this->acme);
        $kept = $this->tx(60000);
        $this->suggest($kept, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $clientOnly = $this->tx(30000, 'ACME EVENTS LTD');
        $this->suggest($clientOnly, [$this->income(30000, ['client_id' => $this->acme->id])], 'categorise.hint');

        // The page's old payload: no invoice_id or client_id keys.
        $this->visit('POST', "/items/bank/{$kept->id}/decide", ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $show->id, 'note' => 'Paid'])
            ->assertSessionHas('success');
        $this->visit('POST', "/items/bank/{$clientOnly->id}/decide", ['kind' => 'business_income', 'category_id' => $this->cat('turnover')])->assertSessionHas('success');
        $this->assertSame([['business_income', $show->id, $invoice->id, null, 60000, true]], $this->stored($kept));
        $this->assertSame([['business_income', null, null, $this->acme->id, 30000, true]], $this->stored($clientOnly));

        // invoice_id named as null: the link goes, and the suggestion's invoice is remembered as not this payment's.
        $dropped = $this->tx(60000);
        $this->suggest($dropped, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $this->visit('POST', "/items/bank/{$dropped->id}/decide", ['kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'work_job_id' => $show->id, 'invoice_id' => null])
            ->assertSessionHas('success');
        $this->assertSame([['business_income', $show->id, null, null, 60000, true]], $this->stored($dropped));
        $this->assertSame(['inv:'.$invoice->id], $this->vetoes($dropped));
    }

    public function test_a_split_keeps_each_lines_invoice_and_client_and_a_gone_invoice_says_so_plainly(): void
    {
        $a = $this->invoice('INV-0041', 60000, null, $this->acme);
        $c = $this->invoice('INV-0043', 75000, null, $this->acme);
        $tx = $this->tx(132000);
        $this->visit('POST', "/items/bank/{$tx->id}/decide", ['lines' => [
            $this->income(60000, ['invoice_id' => $a->id]),
            $this->income(75000, ['invoice_id' => $c->id]),
            ['kind' => 'business_expense', 'category_id' => $this->cat('other'), 'amount_pence' => -3000, 'client_id' => $this->acme->id],
        ]])->assertSessionHas('success');
        $this->assertSame([
            ['business_income', null, $a->id, null, 60000, true],
            ['business_income', null, $c->id, null, 75000, true],
            ['business_expense', null, null, $this->acme->id, -3000, true],
        ], $this->stored($tx));

        $other = $this->tx(10000);
        $this->visit('POST', "/items/bank/{$other->id}/decide", ['lines' => [$this->income(10000, ['invoice_id' => 99999])]])
            ->assertSessionHasErrors(['lines.0.invoice_id' => 'The invoice on line 1 no longer exists: pick another.']);
        $this->visit('POST', "/items/bank/{$other->id}/decide", ['kind' => 'business_income', 'client_id' => 99999])
            ->assertSessionHasErrors(['client_id' => 'That client no longer exists: pick another.']);
        $this->assertSame([], $this->stored($other));
    }

    public function test_send_back_still_refuses_when_what_was_shown_changed(): void
    {
        $tx = $this->tx(60000);
        $this->suggest($tx, [$this->income(60000, ['client_id' => $this->acme->id])], 'categorise.hint');
        $was = Allocation::query()->where('bank_transaction_id', $tx->id)->pluck('id')->all();
        $this->suggest($tx, [$this->income(60000)], 'categorise.hint');

        $this->visit('POST', "/items/bank/{$tx->id}/clear", ['reason_code' => 'wrong_category', 'shown' => $was])
            ->assertSessionHas('error', 'This changed since the page was shown, so nothing was done. Look at it again.');
        $this->assertCount(1, $this->stored($tx));
    }

    public function test_bulk_refuses_an_invoice_and_leaves_invoice_matches_and_splits_saying_so(): void
    {
        $show = $this->job('Show A', $this->acme);
        $invoice = $this->invoice('INV-0001', 60000, $show, $this->acme);
        $matched = $this->tx(60000);
        $this->suggest($matched, [$this->income(60000, ['invoice_id' => $invoice->id])]);
        $plain = $this->tx(40000);

        // An invoice for many at once: refused, nothing changes.
        $this->visit('POST', '/decisions/bulk', ['merchant_key' => 'ACME PAYROLL', 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'invoice_id' => $invoice->id])
            ->assertSessionHas('error', 'Pick the invoice for each payment on its own (open it), not for all of them.');
        $this->assertSame([], $this->stored($plain));

        // By merchant: the invoice match is left for you, and nothing is said no to.
        $this->visit('POST', '/decisions/bulk', ['merchant_key' => 'ACME PAYROLL', 'kind' => 'business_income', 'category_id' => $this->cat('turnover')])
            ->assertSessionHas('success', 'Saved. Left 1 invoice match for you to check.');
        $this->assertSame([['business_income', $show->id, $invoice->id, null, 60000, false]], $this->stored($matched));
        $this->assertSame([], $this->vetoes($matched));
        $this->assertSame([['business_income', null, null, null, 40000, true]], $this->stored($plain));

        // With a client: client-only lines. By ids: a split is left as it is.
        $more = $this->tx(11000, 'ACME LTD');
        $split = $this->tx(50000, 'ACME LTD');
        app(AllocationWriter::class)->decide($split, [$this->income(20000, ['work_job_id' => $show->id]), $this->income(30000)]);
        $this->visit('POST', '/decisions/bulk', ['ids' => [$more->id, $split->id], 'kind' => 'business_income', 'category_id' => $this->cat('turnover'), 'client_id' => $this->acme->id])
            ->assertSessionHas('success', 'Saved. Left 1 split payment as it is.');
        $this->assertSame([['business_income', null, null, $this->acme->id, 11000, true]], $this->stored($more));
        $this->assertCount(2, $this->stored($split));
    }

    public function test_bulk_client_sets_who_money_in_was_from_and_says_what_was_left_and_why(): void
    {
        $undecided = $this->tx(45000);
        $guessed = $this->tx(30000);
        $this->suggest($guessed, [['kind' => 'personal', 'amount_pence' => 30000]], 'categorise.hint');
        $show = $this->job('Show A', $this->acme);
        $jobSuggestion = $this->tx(20000);
        $this->suggest($jobSuggestion, [$this->income(20000, ['work_job_id' => $show->id])], 'jobs.gather');
        $old = $this->tx(9000, 'ACME PAYROLL', '2025-05-20');
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();

        $this->visit('POST', '/decisions/bulk-client', ['merchant_key' => 'ACME PAYROLL', 'client_id' => $this->acme->id])
            ->assertSessionHas('success', 'Set 2 as Acme Events’s. Left 1 in a locked year: unlock it (with a reason) first. Left 1 with a suggestion for a job or invoice: accept or change it first.');
        $this->assertSame([['business_income', null, null, $this->acme->id, 45000, true]], $this->stored($undecided));
        $this->assertSame([['business_income', null, null, $this->acme->id, 30000, true]], $this->stored($guessed));
        $this->assertSame([], $this->stored($old));

        $this->visit('POST', '/decisions/bulk-client', ['ids' => [$undecided->id], 'client_id' => $this->acme->id])
            ->assertSessionHas('success', 'Nothing changed. 1 was Acme Events’s already.');
        $this->visit('POST', '/decisions/bulk-client', ['ids' => [$undecided->id], 'client_id' => 99999])
            ->assertSessionHasErrors(['client_id' => 'That client no longer exists: pick another.']);
    }

    public function test_confirm_many_counts_the_suggestions_whose_invoice_was_voided(): void
    {
        $good = $this->invoice('INV-0001', 60000, null, $this->acme);
        $void = $this->invoice('INV-0002', 30000, null, $this->acme);
        $a = $this->tx(60000);
        $this->suggest($a, [$this->income(60000, ['invoice_id' => $good->id])]);
        $b = $this->tx(30000);
        $this->suggest($b, [$this->income(30000, ['invoice_id' => $void->id])]);
        $void->fresh()->update(['status' => 'void', 'void_reason' => 'Wrong client']);

        $this->visit('POST', '/decisions/confirm', ['ids' => [$a->id, $b->id]])
            ->assertSessionHas('success', 'Confirmed 1. Left 1 whose invoice was voided or isn’t issued yet.');
        $this->assertTrue($this->stored($a)[0][5]);
        $this->assertFalse($this->stored($b)[0][5]);
    }

    public function test_new_client_reuses_the_client_you_have_and_adds_one_with_its_reason(): void
    {
        $this->postJson('/clients', ['name' => 'acme events ltd'])->assertOk()
            ->assertExactJson(['id' => $this->acme->id, 'name' => 'Acme Events', 'existing' => true, 'message' => 'You already have Acme Events.']);
        $this->assertSame(1, Client::query()->count());

        $made = $this->postJson('/clients', ['name' => 'Big Prod Co', 'billing_email' => 'accounts@bigprod.example.test'])->assertCreated()->json();
        $this->assertSame(['name' => 'Big Prod Co', 'existing' => false], ['name' => $made['name'], 'existing' => $made['existing']]);
        $event = AuditEvent::query()->where('action', 'client.created')->where('subject_id', $made['id'])->sole();
        $this->assertSame('Added while deciding a payment', $event->reason);
        $this->assertSame('accounts@bigprod.example.test', Client::query()->find($made['id'])->billing_email);

        // "It's a different client": its own, whatever its name.
        $other = $this->postJson('/clients', ['name' => 'Acme Events', 'different' => true])->assertCreated()->json();
        $this->assertNotSame($this->acme->id, $other['id']);
        $this->postJson('/clients', ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name' => 'Give the client a name.']);
    }
}
