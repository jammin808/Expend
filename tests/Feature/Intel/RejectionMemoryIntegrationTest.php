<?php

namespace Tests\Feature\Intel;

use App\Domain\Ai\AssistantTools;
use App\Domain\Intel\Detectors\TravelUnclaimed;
use App\Domain\Intel\Methods;
use App\Domain\Intel\Vetoes;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Matching\Categoriser;
use App\Domain\Matching\MerchantKey;
use App\Domain\Todo\Fingerprints;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * M7, as integrated: the connections map's possible-job buttons are bound to what it showed, the assistant says why a
 * proposal was refused, Methods::outranks is the writer's order, and a job you didn't drive to is left out of the
 * unclaimed mileage.
 */
class RejectionMemoryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Account $bank;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2025-07-01 09:00:00');
        $this->bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank', 'provider' => 'starling']);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(string $date, int $pence, string $description, ?string $counterparty = null): BankTransaction
    {
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $this->bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create([
            'account_id' => $this->bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate(new \DateTimeImmutable($date))->label(),
            'description' => $description, 'counterparty' => $counterparty, 'merchant_key' => MerchantKey::from($description, $counterparty), 'amount_pence' => $pence, 'raw' => [],
            'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1,
        ]);
    }

    public function test_the_connections_map_sends_what_it_showed_with_a_possible_jobs_buttons(): void
    {
        $c = JobCandidate::query()->create(['status' => 'open', 'title' => 'Acme rig', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);
        $this->get('/connections?year=2025-26')->assertOk()->assertInertia(fn ($p) => $p->where('nodes', fn ($nodes) => collect($nodes)->contains(fn ($n) => $n['id'] === 'candidate:'.$c->id
            && $n['data']['fp_add'] === Fingerprints::of('cand_add', (string) $c->id) && $n['data']['fp_dismiss'] === Fingerprints::of('cand_dismiss', (string) $c->id))));

        // Its dates moved after the map was shown: "Not a job" from it is refused, and the possible job stays.
        $shown = Fingerprints::of('cand_dismiss', (string) $c->id);
        $c->update(['starts_on' => '2025-08-02', 'ends_on' => '2025-08-02']);
        $this->post("/todo/cand_dismiss/{$c->id}", ['fp' => $shown])->assertSessionHas('error', fn ($m) => str_starts_with($m, 'This changed since'));
        $this->assertSame('open', $c->fresh()->status);
    }

    public function test_the_assistant_is_told_why_a_proposal_was_refused(): void
    {
        $tools = app(AssistantTools::class);
        $writer = app(AllocationWriter::class);

        $mine = $this->tx('2025-06-12', -650, 'PRET A MANGER LONDON');
        $writer->decide($mine, AllocationWriter::lines(-650, 'personal'));
        $out = $tools->run('propose_decision', ['transaction_id' => $mine->id, 'kind' => 'loan', 'reason' => 'You said it was a loan']);
        $this->assertFalse($out['ok']);
        $this->assertStringContainsString('you have already decided or confirmed this transaction', $out['note']);

        $vetoed = $this->tx('2025-06-13', -720, 'PRET A MANGER LEEDS');
        Vetoes::record('allocation_shape', 'tx:'.$vetoed->id, null, Vetoes::shape([['kind' => 'personal']]));
        $out = $tools->run('propose_decision', ['transaction_id' => $vetoed->id, 'kind' => 'personal', 'reason' => 'Looks personal']);
        $this->assertStringContainsString('you said no to that for this transaction before', $out['note']);

        $acme = Client::query()->create(['name' => 'Acme Events']);
        Invoice::query()->forceCreate(['client_id' => $acme->id, 'number' => 'INV-101', 'status' => 'issued', 'currency' => 'GBP', 'total_minor' => 45000, 'issue_date' => '2025-06-01']);
        $paid = $this->tx('2025-06-20', 45000, 'ACME EVENTS LTD', 'Acme Events Ltd');
        app(Categoriser::class)->suggestFor(collect([$paid]));
        $out = $tools->run('propose_decision', ['transaction_id' => $paid->id, 'kind' => 'loan', 'reason' => 'You said it was a loan']);
        $this->assertStringContainsString('a stronger suggestion is waiting for it', $out['note']);
        $this->assertStringNotContainsString('already decided', $out['note']);
    }

    public function test_methods_outranks_is_the_writers_order(): void
    {
        $this->assertTrue(Methods::outranks('suggest.invoice', 'matcher', 'categorise.hint', 'matcher'));
        $this->assertTrue(Methods::outranks('ai.assistant', 'ai', 'ai.advisor', 'ai'));
        $this->assertFalse(Methods::outranks('ai.assistant', 'ai', 'categorise.hint', 'matcher'), 'The same rank: replaced as before');
        $this->assertTrue(Methods::outranks(null, 'you', 'rule.learned', 'rule'));
        $this->assertSame(AllocationWriter::outranks('transfer.pair', 'matcher', 'suggest.hmrc', 'matcher'), Methods::outranks('transfer.pair', 'matcher', 'suggest.hmrc', 'matcher'));
    }

    public function test_a_job_you_didnt_drive_to_is_left_out_of_the_unclaimed_mileage(): void
    {
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done', 'postcode' => 'LS1 4AP']);
        $this->assertArrayNotHasKey($job->id, TravelUnclaimed::excluded([]));

        $veto = Vetoes::record('mileage_day', 'job:'.$job->id, null, reasonCode: 'no_drive');
        $this->assertArrayHasKey($job->id, TravelUnclaimed::excluded([]));

        Vetoes::lift($veto, 'Lifted by you');
        $this->assertArrayNotHasKey($job->id, TravelUnclaimed::excluded([]));
    }
}
