<?php

namespace Tests\Feature;

use App\Domain\Todo\FindingsRegistry;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
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

        $this->actingAs(User::factory()->create())->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p
            ->where('resolved', 1)
            ->where('money_open', 5000 + (int) Finding::query()->whereNull('resolved_at')->where('key', '!=', $gap->key)->get()->sum(fn ($f) => abs((int) $f->amount_pence)))
            ->has('recent'));
        Carbon::setTestNow();
    }
}
