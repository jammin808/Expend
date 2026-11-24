<?php

namespace Tests\Feature;

use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** What the to-do list finds is kept with its history: when found, when dealt with, and the money involved. */
class FindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_findings_are_recorded_resolved_and_reopened(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $bank->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        foreach ([['2025-06-01', -5000, 100000], ['2025-06-10', -1000, 104000]] as $i => [$d, $a, $b]) {
            BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => $d, 'tax_year' => '2025-26', 'description' => 'X', 'merchant_key' => 'X',
                'amount_pence' => $a, 'balance_after_pence' => $b, 'raw' => [], 'fingerprint' => md5((string) $i), 'occurrence' => 1]);
        }

        $r = FindingsRegistry::sync();
        $this->assertGreaterThanOrEqual(2, $r['new']);
        $noPo = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        $gap = Finding::query()->where('group', 'reconcile')->where('key', 'like', 'gap:%')->sole();
        $this->assertSame(5000, abs($gap->amount_pence));
        $this->assertFalse(Finding::query()->where('group', 'payments')->exists(), 'Payments to decide are routine, not findings');

        // You add the PO number: the finding is dealt with. You snooze the gap.
        Carbon::setTestNow('2025-07-05 09:00:00');
        $job->update(['po_number' => 'PO-1234']);
        TodoDismissal::query()->create(['key' => $gap->key, 'until' => now()->addDays(7)]);
        FindingsRegistry::sync();
        $this->assertSame(['resolved', '2025-07-05'], [$noPo->fresh()->resolution, $noPo->fresh()->resolved_at->format('Y-m-d')]);
        $this->assertSame('snoozed', $gap->fresh()->resolution);

        // The snooze ends and the gap is still there: it's open again.
        Carbon::setTestNow('2025-07-20 09:00:00');
        FindingsRegistry::sync();
        $this->assertNull($gap->fresh()->resolved_at);
        $this->assertSame(1, $gap->fresh()->reopened);

        // Money is totalled by which way it goes, never as one figure: the gap is money missing from your records.
        $this->actingAs(User::factory()->create())->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p
            ->where('resolved', 1)
            ->missing('money_open')
            ->where('by_direction', fn ($d) => collect($d)->firstWhere('direction', 'unrecorded')['open']['GBP'] === 5000
                + (int) Finding::query()->whereNull('resolved_at')->where('direction', 'unrecorded')->where('key', '!=', $gap->key)->get()->sum(fn ($f) => abs((int) $f->amount_pence)))
            ->has('recent'));
        Carbon::setTestNow();
    }

    public function test_an_expired_snooze_on_a_finding_that_has_gone_closes_it_as_dealt_with(): void
    {
        Carbon::setTestNow('2025-07-01 09:00:00');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        TodoDismissal::query()->create(['key' => 'no_po:'.$job->id, 'until' => now()->addDays(7)]);
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        $this->assertSame('snoozed', $finding->resolution);

        // The snooze ended (its row is still there), and the PO was added since: dealt with, not snoozed.
        Carbon::setTestNow('2025-07-20 09:00:00');
        $job->update(['po_number' => 'PO-1234']);
        FindingsRegistry::sync();
        $this->assertSame(['resolved', '2025-07-20'], [$finding->fresh()->resolution, $finding->fresh()->resolved_at->format('Y-m-d')]);
        Carbon::setTestNow();
    }

    public function test_a_check_that_goes_wrong_leaves_its_findings_open(): void
    {
        Carbon::setTestNow('2026-11-24 09:00:00');
        CarbonImmutable::setTestNow('2026-11-24 09:00:00');
        // 2024-25, filed, nothing paid towards its tax: fines and interest are running.
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $bank->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $tx = BankTransaction::query()->create(['account_id' => $bank->id, 'import_batch_id' => $batch->id, 'posted_on' => '2024-09-10', 'tax_year' => '2024-25', 'description' => 'Acme Events',
            'merchant_key' => 'ACME EVENTS', 'amount_pence' => 3_000_000, 'raw' => [], 'fingerprint' => md5('acme'), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(3_000_000, 'business_income', Category::query()->where('code', 'turnover')->value('id')));
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20']);
        FindingsRegistry::sync();
        $penalty = Finding::query()->where('key', 'penalty:2024-25')->sole();

        // The estimate breaks (here, its rules can't be read): its items are missing from the list this time.
        $rules = new \ReflectionProperty(PenaltyEstimator::class, 'rules');
        $rules->setValue(null, []);
        try {
            $this->assertThrows(fn () => PenaltyEstimator::all());
            $this->assertNotContains('penalty:2024-25', array_column(TodoList::everything(), 'key'));
            $r = FindingsRegistry::sync();
            $this->assertNull($penalty->fresh()->resolved_at, 'Not dealt with: only not worked out this time');
            $this->assertContains('penalty:', $r['failed']);
            $this->assertGreaterThanOrEqual(1, $r['kept']);
        } finally {
            $rules->setValue(null, null);
        }

        // Working again, and still there: still open, never closed and reopened.
        FindingsRegistry::sync();
        $this->assertNull($penalty->fresh()->resolved_at);
        $this->assertSame(0, $penalty->fresh()->reopened);
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
