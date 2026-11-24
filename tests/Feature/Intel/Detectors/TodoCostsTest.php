<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What the newer checks cost a page: what's below the fold is kept until something it's made from changes, the levels
 * are one settings read, and the history page queues a findings run that takes a while (the first after an upgrade
 * records every new kind of finding) rather than making the page wait for it on shared hosting.
 */
class TodoCostsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-07-01 10:00:00');
        CarbonImmutable::setTestNow('2026-07-01 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A £250 business cost with no receipt: cost.no_receipt, below the fold. */
    private function cost(): BankTransaction
    {
        $acc = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $acc->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $t = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-06-10', 'tax_year' => UkTaxYear::fromDate('2026-06-10')->label(),
            'description' => 'THOMANN', 'counterparty' => 'THOMANN', 'merchant_key' => 'THOMANN', 'amount_pence' => -25000, 'raw' => [], 'fingerprint' => md5('t'), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($t, AllocationWriter::lines(-25000, 'business_expense', Category::query()->where('code', 'equipment')->value('id')));

        return $t;
    }

    private function queries(callable $work): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $work();

        return $n;
    }

    public function test_what_is_found_below_the_fold_is_kept_until_something_changes(): void
    {
        $t = $this->cost();
        $keys = fn () => array_column(TodoList::belowFold()['items'], 'key');
        $n = $this->queries(fn () => $this->assertContains('noreceipt:'.$t->id, $keys()));
        $again = $this->queries(fn () => $this->assertContains('noreceipt:'.$t->id, $keys()));
        $this->assertLessThan(10, $again, 'Kept: only the fingerprint is read ('.$again.' queries, against '.$n.')');

        // The receipt arrives: worked out afresh.
        Document::query()->create(['source' => 'email', 'original_name' => 'receipt.pdf', 'sha256' => hash('sha256', 'r'), 'stored_path' => 'x', 'kind' => 'receipt',
            'status' => 'extracted', 'received_at' => '2026-06-10 12:00:00', 'extraction' => ['receipt' => ['merchant' => 'Thomann', 'date' => '2026-06-10', 'amount_minor' => 25000, 'currency' => 'GBP']]]);
        $this->assertNotContains('noreceipt:'.$t->id, $keys());
    }

    public function test_the_levels_are_one_settings_read(): void
    {
        $this->cost();
        FindingsRegistry::sync();
        $this->assertSame(1, $this->queries(fn () => DetectorLevels::all()));
    }

    public function test_the_history_page_queues_a_findings_run_that_takes_a_while(): void
    {
        Queue::fake();
        $t = $this->cost();

        // None timed yet (the first after an upgrade): queued once, and the page says it's coming.
        $this->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p->where('updating', true));
        $this->get('/todo/history')->assertOk();
        Queue::assertPushed(CallQueuedClosure::class, 1);
        $this->assertSame(0, Finding::query()->count(), 'Not recorded inside the page view');

        // The queued run records them, and its time.
        Cache::forget(FindingsRegistry::QUEUED_KEY);
        FindingsRegistry::refresh();
        $this->assertSame(1, Finding::query()->where('key', 'noreceipt:'.$t->id)->count());
        $this->assertIsNumeric(Cache::get(FindingsRegistry::SECONDS_KEY));

        // A quick run is recorded while the page waits, as before; a slow one is queued again.
        Cache::put(FindingsRegistry::SECONDS_KEY, 0.4);
        Setting::put('home_postcode', 'RG1 1AA'); // something the list is made from changed
        $this->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p->where('updating', false));
        Queue::assertPushed(CallQueuedClosure::class, 1);
        $this->assertSame(TodoList::fingerprint(), Cache::get(FindingsRegistry::SYNCED_KEY), 'Recorded in the page view');
        Cache::put(FindingsRegistry::SECONDS_KEY, 12.5);
        Carbon::setTestNow('2026-07-01 10:05:00');
        Setting::put('home_postcode', 'RG1 1AB');
        $this->get('/todo/history')->assertOk();
        Queue::assertPushed(CallQueuedClosure::class, 2);
    }
}
