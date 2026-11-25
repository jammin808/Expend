<?php

namespace Tests\Feature\Clients;

use App\Domain\Charges\ChargeTermsWriter;
use App\Domain\Clients\ClientFigures;
use App\Domain\Clients\ClientIds;
use App\Domain\Clients\ClientMerge;
use App\Models\AuditEvent;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\CostCharge;
use App\Models\Invoice;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Income\HttpFixtures;
use Tests\TestCase;

/**
 * Build slice S9 (the client area's HTTP): the list and a client's page with their props (a fixed number of queries
 * however many clients), details with the duplicate check, merging tied to both clients as compared (409 with the
 * sheet when either changed, refused for locked-year records), unmerging, "Not the same", and the arrangement editor
 * tied to the rows it loaded.
 */
class ClientPagesTest extends TestCase
{
    use HttpFixtures;
    use RefreshDatabase;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-10 10:00:00');
        CarbonImmutable::setTestNow('2026-06-10 10:00:00');
        Queue::fake();
        $this->signIn();
        $this->acme = $this->client('Acme Events', ['billing_email' => 'accounts@acme.example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function props(string $uri): array
    {
        $page = $this->get($uri)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component(explode('?', ltrim($uri, '/'))[0] === 'clients' ? 'clients/index' : 'clients/show', false))
            ->viewData('page');

        return json_decode(json_encode($page['props']), true);
    }

    public function test_the_list_shows_what_each_client_owes_and_has_to_invoice_and_finds_merged_and_payer_names(): void
    {
        $late = $this->job('Show A', $this->acme, '2026-04-01');
        // Sent: an invoice never sent isn't overdue (it's "Not sent", as on the job's stage).
        $this->invoice('INV-0001', 45000, $late, $this->acme, ['issue_date' => '2026-04-02', 'due_date' => '2026-05-02', 'sent_at' => '2026-04-02 10:00:00']);
        $big = $this->client('Big Prod Co');
        $this->job('Show B', $big, '2026-05-20');
        $quiet = $this->client('Quiet Ltd');
        $this->job('Show C', $quiet, '2026-06-01', ['status' => 'cancelled']);
        $dupe = $this->client('ACME EVENTS LTD');
        $dupe->forceFill(['merged_into_id' => $this->acme->id])->save();
        $this->decide($this->tx(10000, 'BIGPROD PAYMENTS', '2026-05-25'), [$this->income(10000, ['client_id' => $big->id])]);

        $props = $this->props('/clients');
        $rows = collect($props['clients'])->keyBy('name');
        $this->assertSame(['Acme Events', 'Big Prod Co', 'Quiet Ltd'], $rows->keys()->all(), 'overdue first, then to invoice, then the rest; merged clients left out');
        $this->assertSame('Owes £450.00 · 1 overdue', $rows['Acme Events']['owed_words']);
        $this->assertSame(['ACME EVENTS LTD'], $rows['Acme Events']['merged_names']);
        $this->assertSame(1, $rows['Big Prod Co']['to_invoice']);
        $this->assertSame(['owe' => 1, 'to_invoice' => 1, 'all' => 3], $props['counts']);

        $this->assertSame(['Acme Events'], array_column($this->props('/clients?q=acme events ltd')['clients'], 'name'));
        $this->assertSame(['Big Prod Co'], array_column($this->props('/clients?q=bigprod')['clients'], 'name'));
        $this->assertSame(['Acme Events'], array_column($this->props('/clients?show=owe')['clients'], 'name'));
    }

    public function test_the_list_makes_the_same_number_of_queries_for_many_clients(): void
    {
        $make = function (int $from, int $n) {
            for ($i = $from; $i < $from + $n; $i++) {
                $c = $this->client('Client '.$i);
                $job = $this->job('Job '.$i, $c, '2026-05-0'.(1 + $i % 9));
                $this->invoice('INV-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 10000 + $i, $job, $c, ['issue_date' => '2026-05-10', 'due_date' => '2026-06-09']);
            }
        };
        // Each measured after one visit, so what the layout caches (the to-do count, settings) is warm both times.
        $make(1, 3);
        $this->get('/clients')->assertOk();
        $few = $this->queries(fn () => $this->get('/clients')->assertOk());
        $make(4, 30);
        $this->get('/clients')->assertOk();
        $many = $this->queries(fn () => $this->get('/clients')->assertOk());
        $this->assertSame($few, $many, 'a fixed number of queries');
    }

    public function test_a_clients_page_has_the_figures_its_jobs_capped_at_five_and_who_pays_as_whom(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->job('Show '.$i, $this->acme, '2026-05-0'.$i);
        }
        $agency = $this->client('CrewCo');
        $this->job('Crewed show', $agency, '2026-05-09', ['end_client_id' => $this->acme->id]);
        $this->decide($this->tx(30000, 'ACME PAYROLL', '2026-05-20'), [$this->income(30000, ['client_id' => $this->acme->id])]);

        $props = $this->props('/clients/'.$this->acme->id);
        $this->assertCount(5, $props['jobs']['paying']);
        $this->assertSame(7, $props['jobs']['paying_count']);
        $this->assertSame(['Crewed show'], array_column($props['jobs']['end_client'], 'title'));
        $this->assertSame('Booked through CrewCo (1 job)', $props['client']['booked_through']);
        $this->assertSame('Pays you as ACME PAYROLL (1 payment)', $props['client']['payers'][0]['text']);
        $figures = ClientFigures::forClient($this->acme, '2026-27');
        $this->assertSame([$figures['made_pence'], $figures['profit_pence']], [$props['figures']['made_pence'], $props['figures']['profit_pence']]);
        $this->assertSame(ChargeTermsWriter::fingerprint($this->acme), $props['terms']['fp']);
        $this->assertStringNotContainsString('token', json_encode($props));

        // A merged name opens the client it went into.
        $dupe = $this->client('ACME EVENTS LTD');
        $dupe->forceFill(['merged_into_id' => $this->acme->id])->save();
        $this->get('/clients/'.$dupe->id)->assertRedirect('/clients/'.$this->acme->id)->assertSessionHas('success', 'ACME EVENTS LTD is merged into Acme Events.');
    }

    public function test_merging_is_tied_to_both_clients_as_compared_and_refused_for_a_locked_year(): void
    {
        $dupe = $this->client('ACME EVENTS LTD');
        $sheet = $this->getJson("/clients/{$dupe->id}/compare/{$this->acme->id}")->assertOk()->json();
        $this->assertTrue($sheet['can_merge']);
        $this->assertSame(ClientMerge::fingerprint($dupe, $this->acme), $sheet['fp']);

        // Either changed since: 409 with the sheet as it is now, nothing merged.
        $this->acme->forceFill(['invoice_name' => 'Acme Events Limited'])->save();
        $stale = $this->postJson("/clients/{$dupe->id}/merge", ['into_id' => $this->acme->id, 'fp' => $sheet['fp']])->assertStatus(409)->json();
        $this->assertSame('One of them changed since you compared them: look again. Nothing was merged.', $stale['message']);
        $this->assertSame(ClientMerge::fingerprint($dupe, $this->acme->fresh()), $stale['sheet']['fp']);
        $this->assertNull($dupe->fresh()->merged_into_id);

        $this->postJson("/clients/{$dupe->id}/merge", ['into_id' => $this->acme->id, 'fp' => $stale['sheet']['fp']])->assertOk()
            ->assertJsonPath('message', 'ACME EVENTS LTD is merged into Acme Events. Its jobs, invoices and payments count as Acme Events’s now. Nothing in your records was rewritten, and you can unmerge it.');
        $this->assertSame($this->acme->id, $dupe->fresh()->merged_into_id);
        $this->assertSame(1, AuditEvent::query()->where('action', 'client.merged')->count());

        $this->post("/clients/{$dupe->id}/unmerge")->assertRedirect('/clients/'.$this->acme->id)->assertSessionHas('success', 'ACME EVENTS LTD is its own client again.');
        $this->assertNull($dupe->fresh()->merged_into_id);

        // Records in a locked year: the sheet says why it can't, and the merge is refused.
        $job = $this->job('Old show', $dupe, '2025-05-01');
        $this->decide($this->tx(20000, 'ACME LTD', '2025-05-20'), [$this->income(20000, ['work_job_id' => $job->id])]);
        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();
        $sheet = $this->getJson("/clients/{$dupe->id}/compare/{$this->acme->id}")->json();
        $this->assertFalse($sheet['can_merge']);
        $this->assertSame('ACME EVENTS LTD has records in 2025-26, which is locked: unlock it with a reason first.', $sheet['why_not']);
        $this->postJson("/clients/{$dupe->id}/merge", ['into_id' => $this->acme->id, 'fp' => $sheet['fp']])->assertStatus(422)
            ->assertJsonPath('message', 'ACME EVENTS LTD has records in 2025-26, which is locked: unlock it with a reason first.');
    }

    public function test_not_the_same_is_remembered_and_details_refuse_a_name_you_have(): void
    {
        $dupe = $this->client('ACME EVENTS LTD');
        $this->assertCount(1, $this->props('/clients')['duplicates']);
        $this->post("/clients/{$dupe->id}/not-same", ['other_id' => $this->acme->id])->assertSessionHas('success');
        $this->assertTrue(TodoDismissal::query()->where('key', ClientIds::dupeKey($dupe->id, $this->acme->id))->whereNull('until')->exists());
        $this->assertSame([], $this->props('/clients')['duplicates']);

        $this->visit('PATCH', "/clients/{$dupe->id}", ['name' => 'The Acme Events'], "/clients/{$dupe->id}")
            ->assertSessionHas('error', 'You already have Acme Events: merge them instead of giving this one the same name.');
        $this->visit('PATCH', "/clients/{$this->acme->id}", ['payment_terms_days' => 90, 'invoice_route' => 'new'], "/clients/{$this->acme->id}")
            ->assertSessionHas('success', 'Saved. Over 60 days: check it’s in your agreement.');
        $this->assertSame([90, 'new', 'accounts@acme.example.test'], [$this->acme->fresh()->payment_terms_days, $this->acme->fresh()->invoice_route, $this->acme->fresh()->billing_email]);
        $this->visit('PATCH', "/clients/{$this->acme->id}", ['billing_email' => 'accounts@acme'], "/clients/{$this->acme->id}")
            ->assertSessionHasErrors(['billing_email' => 'That email address doesn’t look right. Use one address, like accounts@client.co.uk.']);
    }

    public function test_the_arrangement_editor_saves_a_preset_as_you_and_refuses_a_stale_save(): void
    {
        $fp = ChargeTermsWriter::fingerprint($this->acme);
        $this->putJson("/clients/{$this->acme->id}/terms", ['fp' => $fp, 'preset' => 'mileage', 'rate' => '0.45'])->assertOk()
            ->assertJsonPath('message', 'Saved. Expend ticks these on Acme Events’s drafts. Per-mile covers fuel.');
        $this->assertSame(['charge' => 'per_mile', 'rate_minor' => 45], ChargeTerm::query()->where('client_id', $this->acme->id)->where('class', 'mileage')->first()->only(['charge', 'rate_minor']));

        // The same fingerprint again: the rows changed since it was loaded.
        $stale = $this->putJson("/clients/{$this->acme->id}/terms", ['fp' => $fp, 'preset' => 'at_cost'])->assertStatus(409)->json();
        $this->assertSame('This changed since you opened it: look again. Nothing was saved.', $stale['message']);
        $this->assertSame(ChargeTermsWriter::fingerprint($this->acme), $stale['sheet']['fp']);

        // From the page (an Inertia visit): back with the reason.
        $this->visit('PUT', "/clients/{$this->acme->id}/terms", ['fp' => $fp, 'preset' => 'at_cost'], "/clients/{$this->acme->id}")
            ->assertSessionHas('error', 'This changed since you opened it: look again. Nothing was saved.');
        $this->assertSame(1, AuditEvent::query()->where('action', 'charge_terms.updated')->count());
    }

    public function test_a_costs_decisions_go_through_cost_charges_by_its_id(): void
    {
        // "Recharge to Acme" on a confirmed cost with no job: charged to them, and counted in their costs.
        $cost = $this->tx(-4160, 'TRAINLINE', '2026-05-20');
        $this->decide($cost, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => -4160]]);
        $this->visit('POST', "/items/bank/{$cost->id}/recharge", ['client_id' => $this->acme->id], '/transactions')
            ->assertSessionHas('success', 'Charged to Acme Events and counted in their costs. It goes on Acme Events’s next draft.');
        $this->assertSame($this->acme->id, $this->stored($cost)[0][3]);
        $ref = 'tx:'.$cost->id.'#c'.$this->acme->id;
        $this->assertSame('recharge', CostCharge::query()->where('cost_ref', $ref)->value('charge'));

        // By its id ('#' as ':'): not charged, claimed, then forgotten.
        $id = 'tx:'.$cost->id.':c'.$this->acme->id;
        $this->visit('POST', "/costs/{$id}/charge", ['charge' => 'no_charge'], '/clients/'.$this->acme->id)->assertSessionHas('success', 'Not charged: it stays a cost of yours.');
        $this->visit('POST', "/costs/{$id}/claimed", [], '/clients/'.$this->acme->id)->assertSessionHas('success', 'Noted: claimed on their expense form.');
        $this->visit('POST', "/costs/{$id}/outcome", ['outcome' => 'paid_back'], '/clients/'.$this->acme->id)->assertSessionHas('success', 'Noted: paid back.');
        $this->visit('POST', "/costs/{$id}/clear", [], '/clients/'.$this->acme->id)->assertSessionHas('success', 'Back to the usual arrangement.');
        $this->assertNull(CostCharge::query()->where('cost_ref', $ref)->value('charge'));
        $this->visit('POST', '/costs/nonsense/charge', ['charge' => 'no_charge'], '/clients/'.$this->acme->id)->assertSessionHas('error', 'That isn’t a cost Expend knows: open it again.');
        $this->postJson("/costs/{$id}/charge", ['charge' => 'invoiced'])->assertStatus(422)->assertJsonPath('message', 'Pick the issued invoice it was on.');

        // Its client taken off again (a ledger change, as you).
        $this->visit('POST', "/items/bank/{$cost->id}/expense-client", ['client_id' => null], '/transactions')->assertSessionHas('success', 'Not counted for any client now.');
        $this->assertNull($this->stored($cost)[0][3]);
    }

    public function test_a_draft_for_a_clients_costs_opens_on_its_own(): void
    {
        $this->post("/clients/{$this->acme->id}/invoice")->assertRedirect();
        $draft = Invoice::query()->where('client_id', $this->acme->id)->whereNull('work_job_id')->sole();
        $this->assertSame('draft', $draft->status);
        $this->post("/clients/{$this->acme->id}/invoice");
        $this->assertSame(1, Invoice::query()->count(), 'their open draft is reused');
    }

    public function test_a_clients_page_makes_the_same_number_of_queries_however_many_jobs_are_still_to_invoice(): void
    {
        $make = function (int $from, int $n) {
            for ($i = $from; $i < $from + $n; $i++) {
                $end = $this->client('End '.$i);
                $this->job('Show '.$i, $this->acme, '2026-05-'.str_pad((string) (1 + $i), 2, '0', STR_PAD_LEFT), ['end_client_id' => $end->id, 'fee_pence' => 10000 + $i]);
            }
        };
        $uri = '/clients/'.$this->acme->id;
        $make(1, 6);
        $this->get($uri)->assertOk();
        $few = $this->queries(fn () => $this->get($uri)->assertOk());
        $make(7, 14);
        $this->get($uri)->assertOk();
        $many = $this->queries(fn () => $this->get($uri)->assertOk());
        $this->assertSame($few, $many, 'a fixed number of queries');
        $this->assertCount(20, $this->props($uri)['owed']['jobs']);
    }

    public function test_new_client_named_as_a_merged_client_is_the_client_you_have_and_keeps_its_details(): void
    {
        $this->acme->forceFill(['invoice_name' => 'Acme Events Ltd'])->save();
        $old = $this->client('Acme Productions');
        $old->forceFill(['merged_into_id' => $this->acme->id])->save();

        $this->postJson('/clients', ['name' => 'Acme Productions', 'billing_email' => 'someone@else.example.test', 'invoice_name' => 'Somebody Else'])->assertOk()
            ->assertJson(['id' => $this->acme->id, 'name' => 'Acme Events', 'existing' => true, 'message' => 'You already have Acme Events.']);
        $this->assertSame(['accounts@acme.example.test', 'Acme Events Ltd'], [$this->acme->fresh()->billing_email, $this->acme->fresh()->invoice_name]);

        // "It's a different client": one of its own, with what was typed.
        $new = $this->postJson('/clients', ['name' => 'Acme Productions', 'billing_email' => 'someone@else.example.test', 'different' => true])->assertCreated()->json();
        $this->assertNotSame($this->acme->id, $new['id']);
        $this->assertSame('someone@else.example.test', Client::query()->find($new['id'])->billing_email);
        $this->assertSame('accounts@acme.example.test', $this->acme->fresh()->billing_email);
    }

    public function test_a_decision_for_a_cost_that_doesnt_exist_is_refused(): void
    {
        $cost = $this->tx(-4160, 'TRAINLINE', '2026-05-20');
        $job = $this->job('Show A', $this->acme, '2026-05-20');
        $this->decide($cost, [['kind' => 'business_expense', 'category_id' => $this->cat('travel_rail'), 'amount_pence' => -4160, 'work_job_id' => $job->id]]);

        foreach (['tx:999999:j'.$job->id, 'tx:'.$cost->id.':j'.($job->id + 50), 'trip:999999', 'job:999999:fee'] as $id) {
            $this->postJson("/costs/{$id}/charge", ['charge' => 'no_charge'])->assertStatus(422)->assertJsonPath('message', 'That isn’t a cost Expend knows: open it again.');
        }
        $this->postJson('/costs/tx:'.$cost->id.':j'.$job->id.'/pair', ['other' => 'tx:999999:j'.$job->id, 'pair' => 'same'])
            ->assertStatus(422)->assertJsonPath('message', 'That other cost isn’t one Expend knows: open it again.');
        $this->assertSame(0, CostCharge::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'cost.%')->count());

        // The cost as it is now is decided.
        $this->postJson('/costs/tx:'.$cost->id.':j'.$job->id.'/charge', ['charge' => 'no_charge'])->assertOk();
        $this->assertSame('no_charge', CostCharge::query()->where('cost_ref', 'tx:'.$cost->id.'#j'.$job->id)->value('charge'));
    }
}
