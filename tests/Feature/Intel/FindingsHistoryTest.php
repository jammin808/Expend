<?php

namespace Tests\Feature\Intel;

use App\Domain\Ledger\DecisionService;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The findings history page: each finding's events, why it closed, unseen versus dealt with, and filters by job, account or year. */
class FindingsHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2025-07-01 09:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function charge(string $date, int $pence, string $name): BankTransaction
    {
        $bank = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $bank->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);

        return BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $name, 'merchant_key' => $name, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
    }

    public function test_it_shows_each_findings_events_and_why_it_closed(): void
    {
        $hmrc = $this->charge('2025-06-05', -25000, 'HMRC SELF ASSESSMENT');
        $a = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $b = WorkJob::query()->create(['title' => 'Gala dinner', 'starts_on' => '2025-06-03', 'ends_on' => '2025-06-03', 'status' => 'done']);
        FindingsRegistry::sync();

        // You record the payment as tax: dealt with, because of your decision.
        Carbon::setTestNow('2025-07-02 09:00:00');
        app(DecisionService::class)->apply(['kind' => 'tax_payment'], collect([$hmrc]));
        $decision = AuditEvent::query()->where('subject_type', 'BankTransaction')->where('subject_id', $hmrc->id)->latest('id')->firstOrFail();
        $this->post('/todo/dismiss', ['key' => 'no_po:'.$b->id, 'reason' => 'not_problem']);

        $this->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p
            ->where('recent', function ($rows) use ($hmrc, $decision, $b) {
                $rows = collect($rows)->keyBy('key');
                $closed = $rows['hmrc:'.$hmrc->id];
                $set = $rows['no_po:'.$b->id];

                return $closed['state'] === 'resolved' && $closed['cause']['ref'] === 'audit:'.$decision->id.'@'.substr($decision->hash, 0, 8)
                    && str_contains($closed['cause']['label'], 'by you')
                    && array_column($closed['events'], 'event') === ['detected', 'resolved']
                    && $set['note'] === 'Not a problem' && $set['state'] === 'dismissed'
                    && array_column($set['events'], 'event') === ['detected', 'dismissed'] && $set['events'][1]['actor'] === 'you';
            })
            ->where('scopes', fn ($scopes) => collect($scopes)->pluck('ref')->contains('job:'.$b->id) && collect($scopes)->pluck('ref')->contains('year:2025-26')));

        // Kept to one job.
        $this->get('/todo/history?scope=job:'.$a->id)->assertOk()->assertInertia(fn ($p) => $p
            ->where('scope.ref', 'job:'.$a->id)
            ->where('scope.label', 'Arena show · 1 Jun 2025')
            ->where('open', 2)
            ->where('recent', fn ($rows) => collect($rows)->pluck('key')->sort()->values()->all() === ['invoice:'.$a->id, 'no_po:'.$a->id]));
        // Not a ref: ignored.
        $this->get('/todo/history?scope=nonsense')->assertOk()->assertInertia(fn ($p) => $p->where('scope', null));
    }

    public function test_unseen_is_told_apart_from_dealt_with(): void
    {
        Finding::query()->create(['key' => 'no_po:99', 'group' => 'no_po', 'title' => 'No PO: old job', 'state' => 'unseen', 'code' => 'job.no_po',
            'first_seen_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(2)]);
        $summary = FindingsRegistry::summary('2025-01-01');
        $this->assertSame([1, 0], [$summary['unseen'], $summary['resolved']]);
        $this->assertSame('unseen', collect($summary['recent'])->firstWhere('key', 'no_po:99')['state']);
    }
}
