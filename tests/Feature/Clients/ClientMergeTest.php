<?php

namespace Tests\Feature\Clients;

use App\Domain\Clients\ClientIds;
use App\Domain\Clients\ClientMerge;
use App\Domain\Income\IncomeClient;
use App\Domain\Income\PayerAliases;
use App\Domain\Intel\AuditContext;
use App\Domain\Jobs\JobCreator;
use App\Domain\Ledger\AllocationWriter;
use App\Models\Account;
use App\Models\Allocation;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ChargeTerm;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Build slice S4: merging a duplicate client (ClientMerge) and reading merges (ClientIds). Yours only and audited;
 * nothing in your records is rewritten; the kept client's empty details are filled and the duplicate's arrangements
 * moved only where it has none. Refused while the duplicate has confirmed records in a locked year. After a merge,
 * JobCreator::client finds the kept client by any of the names, IncomeClient and PayerAliases count one client, and
 * unmerging restores them. Possible duplicates are suggested, never merged on their own.
 */
class ClientMergeTest extends TestCase
{
    use RefreshDatabase;

    private ?Account $account = null;

    private ?ImportBatch $batch = null;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
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

    private function merge(): ClientMerge
    {
        return app(ClientMerge::class);
    }

    private function job(Client $client, string $on = '2026-05-01'): WorkJob
    {
        return WorkJob::query()->create(['title' => 'Arena load-in '.(++$this->n), 'client_id' => $client->id, 'starts_on' => $on, 'ends_on' => $on, 'status' => 'done']);
    }

    /** A payment from the payer that you decided yourself as income for the job. */
    private function paid(WorkJob $job, string $on = '2026-05-20', string $payer = 'ACME PAYROLL'): BankTransaction
    {
        $this->account ??= Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->batch ??= ImportBatch::query()->create(['account_id' => $this->account->id, 'original_filename' => 'may.csv', 'file_sha256' => str_repeat('c', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $tx = BankTransaction::query()->create(['account_id' => $this->account->id, 'import_batch_id' => $this->batch->id, 'posted_on' => $on,
            'tax_year' => UkTaxYear::fromDate($on)->label(), 'description' => $payer, 'counterparty' => $payer, 'merchant_key' => $payer, 'amount_pence' => 50000,
            'raw' => [], 'fingerprint' => 'merge-'.(++$this->n)]);
        $this->assertTrue(app(AllocationWriter::class)->decide($tx, [['kind' => 'business_income', 'category_id' => (int) Category::query()->where('code', 'turnover')->value('id'),
            'work_job_id' => $job->id, 'amount_pence' => 50000]]));

        return $tx;
    }

    public function test_a_merge_points_the_duplicate_at_the_kept_client_fills_its_empty_details_and_is_audited(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events', 'billing_email' => 'accounts@acme.example.test']);
        $dupe = Client::query()->create(['name' => 'ACME EVENTS LTD', 'billing_email' => 'old@acme.example.test', 'billing_address' => '1 Arena Way, Leeds', 'payment_terms_days' => 14, 'invoice_route' => 'new']);
        $older = Client::query()->create(['name' => 'Acme Events Limited']);
        $older->forceFill(['merged_into_id' => $dupe->id])->save(); // merged into the duplicate earlier
        ChargeTerm::query()->create(['client_id' => $acme->id, 'class' => 'rail', 'charge' => 'none']);
        ChargeTerm::query()->create(['client_id' => $dupe->id, 'class' => 'rail', 'charge' => 'at_cost']);
        ChargeTerm::query()->create(['client_id' => $dupe->id, 'class' => 'hotel', 'charge' => 'at_cost']);
        $job = $this->job($dupe);

        $kept = $this->merge()->merge($dupe, $acme, 'Same company');

        $this->assertSame($acme->id, $kept->id);
        $this->assertSame([$acme->id, $acme->id], [$dupe->fresh()->merged_into_id, $older->fresh()->merged_into_id], 'A merge is always one step');
        $this->assertSame(['accounts@acme.example.test', '1 Arena Way, Leeds', 14, 'new'],
            [$kept->billing_email, $kept->billing_address, $kept->payment_terms_days, $kept->invoice_route], 'Only empty details are filled');
        $this->assertSame([$acme->id, 'client:'.$acme->id], [ChargeTerm::query()->where('class', 'hotel')->value('client_id'), ChargeTerm::query()->where('class', 'hotel')->value('owner_key')],
            'Moved: the kept client had nothing for hotels');
        $this->assertSame('none', ChargeTerm::query()->where('owner_key', 'client:'.$acme->id)->where('class', 'rail')->value('charge'), 'The kept client’s own arrangement stays');
        $this->assertSame('client:'.$dupe->id, ChargeTerm::query()->where('class', 'rail')->where('charge', 'at_cost')->value('owner_key'), 'Not moved where the kept client has one');
        $this->assertSame($dupe->id, $job->fresh()->client_id, 'Nothing in your records is rewritten');

        $event = AuditEvent::query()->where('action', 'client.merged')->sole();
        $this->assertSame(['you', 'Client', $dupe->id, 'Same company'], [$event->actor, $event->subject_type, (int) $event->subject_id, $event->reason]);
        $this->assertSame([$acme->id, [$older->id], ['hotel']], [$event->after['merged_into_id'], $event->after['re_pointed'], $event->after['terms_moved']]);
        $this->assertSame(['billing_address', 'invoice_route', 'payment_terms_days'], array_keys(collect($event->after['filled'])->sortKeys()->all()));
        $this->assertNull($event->before['merged_into_id']);
    }

    public function test_after_a_merge_every_name_finds_the_kept_client_and_income_counts_as_one_until_unmerged(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $dupe = Client::query()->create(['name' => 'ACME EVENTS LTD']);
        $job = $this->job($dupe);
        $this->paid($job);
        $this->paid($this->job($dupe));

        // Before: each name is its own client.
        $this->assertSame($dupe->id, JobCreator::client('acme events ltd')->id);
        $this->assertSame($dupe->id, PayerAliases::soleClient('ACME PAYROLL'));

        $this->merge()->merge($dupe, $acme);

        $this->assertSame($acme->id, JobCreator::client('Acme Events Limited')->id, 'The key variant finds the kept client, not a third');
        $this->assertSame($acme->id, JobCreator::client('ACME EVENTS LTD')->id, 'The merged name finds the client it went into');
        $this->assertSame(2, Client::query()->count());
        $this->assertSame($acme->id, IncomeClient::of(Allocation::query()->where('work_job_id', $job->id)->sole()));
        $this->assertSame([$acme->id], IncomeClient::scope(Allocation::query())->get()->pluck(IncomeClient::COLUMN)->map(fn ($id) => (int) $id)->unique()->values()->all());
        $this->assertSame($acme->id, PayerAliases::soleClient('ACME PAYROLL'));
        $this->assertSame($acme->id, JobCreator::check(['title' => 'Gala', 'starts_on' => '2026-07-01', 'client_id' => $dupe->id])['client_id'], 'A form loaded before the merge');

        $this->merge()->unmerge($dupe, 'Two companies after all');

        $this->assertNull($dupe->fresh()->merged_into_id);
        $this->assertSame($dupe->id, IncomeClient::of(Allocation::query()->where('work_job_id', $job->id)->sole()));
        $this->assertSame($dupe->id, PayerAliases::soleClient('ACME PAYROLL'));
        $event = AuditEvent::query()->where('action', 'client.unmerged')->sole();
        $this->assertSame(['you', ['merged_into_id' => $acme->id], ['merged_into_id' => null], 'Two companies after all'], [$event->actor, $event->before, $event->after, $event->reason]);
    }

    public function test_a_merge_or_unmerge_that_would_move_a_locked_years_records_is_refused(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $dupe = Client::query()->create(['name' => 'ACME EVENTS LTD']);
        $this->paid($this->job($dupe, '2025-05-01'), '2025-05-20'); // 2025-26
        $this->paid($this->job($acme, '2024-05-01'), '2024-05-20'); // 2024-25: the kept client's own
        TaxYear::track('2024-25')->forceFill(['locked_at' => now()])->save();

        $this->merge()->merge($dupe, $acme); // the kept client's own locked records don't move
        $this->merge()->unmerge($dupe);

        TaxYear::track('2025-26')->forceFill(['locked_at' => now()])->save();
        foreach ([fn () => $this->merge()->merge($dupe, $acme), fn () => $this->merge()->merge($dupe->fresh(), $acme)] as $try) {
            try {
                $try();
                $this->fail('Merged a client with records in a locked year');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('ACME EVENTS LTD has records in 2025-26, which is locked: unlock it with a reason first.', $e->getMessage());
            }
        }
        $this->assertNull($dupe->fresh()->merged_into_id);

        // Merged while open, then the year was locked: unmerging would move them back, so it's refused too.
        TaxYear::query()->where('label', '2025-26')->update(['locked_at' => null]);
        $this->merge()->merge($dupe, $acme);
        TaxYear::query()->where('label', '2025-26')->update(['locked_at' => now()]);
        TaxYear::query()->where('label', '2024-25')->update(['locked_at' => now()]);
        $this->expectExceptionMessage('ACME EVENTS LTD has records in 2025-26, which is locked: unlock it with a reason first.');
        $this->merge()->unmerge($dupe);
    }

    public function test_merges_that_make_no_sense_and_merges_by_automation_are_refused(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $dupe = Client::query()->create(['name' => 'ACME EVENTS LTD']);
        $other = Client::query()->create(['name' => 'Big Prod Co']);

        $refused = function (callable $call, string $message) {
            try {
                $call();
                $this->fail('Accepted: '.$message);
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        };
        $refused(fn () => $this->merge()->merge($acme, $acme), 'A client can’t be merged into itself.');
        foreach (['policy', 'matcher', 'ai'] as $actor) {
            $refused(fn () => AuditContext::with($actor, fn () => $this->merge()->merge($dupe, $acme)), 'Only you can merge clients.');
        }
        $refused(fn () => $this->merge()->merge($dupe, $acme, null, 'stale-fingerprint'), 'This changed since: open it in Expend. Nothing was done.');

        $fp = ClientMerge::fingerprint($dupe, $acme);
        $this->merge()->merge($dupe, $acme, null, $fp);
        $this->assertSame($acme->id, $this->merge()->merge($dupe->fresh(), $acme)->id, 'Merging again into the same client is already done');
        $this->assertSame(1, AuditEvent::query()->where('action', 'client.merged')->count());
        $refused(fn () => $this->merge()->merge($other, $dupe), 'ACME EVENTS LTD is merged into Acme Events: merge into Acme Events instead.');
        $refused(fn () => $this->merge()->merge($dupe, $other), 'ACME EVENTS LTD is already merged into Acme Events: unmerge it first.');
        $refused(fn () => $this->merge()->unmerge($acme), 'Acme Events isn’t merged into another client.');
        $refused(fn () => AuditContext::with('policy', fn () => $this->merge()->unmerge($dupe)), 'Only you can unmerge a client.');
    }

    public function test_possible_duplicates_are_unmerged_clients_with_the_same_name_key_or_billing_email(): void
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $ltd = Client::query()->create(['name' => 'ACME EVENTS LTD']);
        $crew = Client::query()->create(['name' => 'Crew Co', 'billing_email' => 'Accounts@crew.example.test']);
        $crewLtd = Client::query()->create(['name' => 'CrewCo Payroll', 'billing_email' => 'accounts@crew.example.test ']);
        $merged = Client::query()->create(['name' => 'The Acme Events Limited']);
        $merged->forceFill(['merged_into_id' => $acme->id])->save();
        Client::query()->create(['name' => 'Big Prod Co']);

        $this->assertSame([['a' => $acme->id, 'b' => $ltd->id, 'why' => 'name'], ['a' => $crew->id, 'b' => $crewLtd->id, 'why' => 'email']], ClientIds::possibleDuplicates());

        // "Not the same" is remembered.
        TodoDismissal::query()->create(['key' => ClientIds::dupeKey($ltd->id, $acme->id), 'until' => null, 'note' => 'Not the same']);
        $this->assertSame('client_dupe:'.$acme->id.':'.$ltd->id, ClientIds::dupeKey($ltd->id, $acme->id));
        $this->assertSame([['a' => $crew->id, 'b' => $crewLtd->id, 'why' => 'email']], ClientIds::possibleDuplicates());
        $this->assertCount(2, ClientIds::possibleDuplicates(true));
        $this->assertSame(2, Client::query()->whereNull('merged_into_id')->where('name', 'like', '%cme%')->count(), 'Nothing is merged on its own');

        $this->assertSame([$acme->id, null], [ClientIds::resolve($merged->id), ClientIds::resolve(null)]);
        $this->assertSame([$merged->id => $acme->id, $crew->id => $crew->id], ClientIds::resolveMany([$merged->id, $crew->id, null]));
        $this->assertSame([$acme->id, $merged->id], ClientIds::withMerged($merged->id));
    }

    public function test_a_new_client_records_why_it_was_added(): void
    {
        JobCreator::client('Big Prod Co', 'you', 'Added while deciding a payment');
        JobCreator::client('Crew Co');

        $this->assertSame(['Added while deciding a payment', 'For a new job'], AuditEvent::query()->where('action', 'client.created')->orderBy('id')->pluck('reason')->all());
    }
}
