<?php

namespace Tests\Feature\Intel;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\YearLockedDrift;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Tax\ReturnSummary;
use App\Domain\Tax\YearChecks;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Locking a year keeps a snapshot in its tax_year.locked event (section 4.6): the return's figures, the sha256 of a
 * spreadsheet exported then (kept, so it can be checked), the open findings and the checks. A failing check needs a
 * reason; the year page shows the snapshot and checks it; year.locked_drift compares it with now.
 */
class YearLockSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        // Only the spreadsheets these tests kept.
        foreach (AuditEvent::query()->where('action', 'tax_year.locked')->get() as $e) {
            $file = $e->after['snapshot']['export']['file'] ?? null;
            if ($file) {
                @unlink(storage_path('app/private/'.$file));
            }
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function decided(string $on, int $pence, string $kind, ?string $category): BankTransaction
    {
        $acc = Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank']);
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => 't.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $t = BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => $on, 'tax_year' => UkTaxYear::fromDate($on)->label(),
            'description' => 'Line '.$this->n, 'merchant_key' => 'LINE', 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5((string) ++$this->n), 'occurrence' => 1]);
        app(AllocationWriter::class)->decide($t, AllocationWriter::lines($pence, $kind, $category ? Category::query()->where('code', $category)->value('id') : null));

        return $t;
    }

    private function year(): TaxYear
    {
        $this->decided('2025-05-10', 3_000_000, 'business_income', 'turnover');
        $this->decided('2025-06-10', -40_000, 'business_expense', 'software');

        return TaxYear::track('2025-26');
    }

    private function lockEvent(): AuditEvent
    {
        return AuditEvent::query()->where('action', 'tax_year.locked')->latest('id')->firstOrFail();
    }

    public function test_the_lock_event_carries_the_figures_the_export_sha_the_findings_and_the_checks(): void
    {
        $year = $this->year();
        FindingsRegistry::sync();
        $open = FindingsRegistry::openDigest();
        $this->post('/years/2025-26/lock')->assertSessionHas('success');
        $this->assertTrue($year->fresh()->isLocked());

        $e = $this->lockEvent();
        $s = $e->after['snapshot'];
        $this->assertSame(['you', null], [$e->actor, $e->reason]);
        // Written from the web with where it came from (audit hash v2, on since M7), so stored with its keys in order.
        $this->assertSame(AuditLog::canonical(YearChecks::figures(ReturnSummary::for('2025-26'))), $s['figures']);
        $this->assertSame(AuditLog::canonical(['turnover_pence' => 3_000_000, 'other_income_pence' => 0, 'allowable_expenses_pence' => 40_000, 'disallowable_expenses_pence' => 0, 'mileage_pence' => 0,
            'net_profit_pence' => 2_960_000]), $s['figures']);
        $path = storage_path('app/private/'.$s['export']['file']);
        $this->assertFileExists($path);
        $this->assertSame(hash_file('sha256', $path), $s['export']['sha256']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $s['export']['sha256']);
        $this->assertSame([$open['open'], $open['digest']], [$s['findings']['open'], $s['findings']['digest']]);
        $checks = collect(YearChecks::checks('2025-26', ReturnSummary::for('2025-26')))->mapWithKeys(fn ($c) => [$c['key'] => $c['status']])->all();
        $this->assertSame(AuditLog::canonical($checks), $s['checks']);
        $this->assertNotContains('fail', $s['checks']);
        $this->assertSame(AuditLog::hashFor($e), $e->hash, 'Kept in the hash chain');
        $this->assertTrue(AuditLog::verify()['ok']);

        // The spreadsheet as locked downloads as it was.
        $download = $this->get('/years/2025-26/export?as_locked=1')->assertOk();
        $this->assertSame($s['export']['sha256'], hash_file('sha256', $download->getFile()->getPathname()));
    }

    public function test_locking_with_a_failing_check_needs_a_reason_and_unlocking_is_unchanged(): void
    {
        $year = $this->year();
        // A payment still to decide: "Nothing left undecided" fails.
        $acc = Account::query()->firstOrFail();
        BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => ImportBatch::query()->value('id'), 'posted_on' => '2025-07-01', 'tax_year' => '2025-26',
            'description' => 'Waiting', 'merchant_key' => 'WAITING', 'amount_pence' => -999, 'raw' => [], 'fingerprint' => md5('w'), 'occurrence' => 1]);

        $this->post('/years/2025-26/lock')->assertSessionHasErrors('reason');
        $this->assertFalse($year->fresh()->isLocked());
        $this->assertSame(0, AuditEvent::query()->where('action', 'tax_year.locked')->count());
        $this->assertSame([], glob(storage_path('app/private/'.YearChecks::LOCK_EXPORTS.'/expense-ledger-2025-26-locked-*.xlsx')) ?: [], 'Nothing exported for a lock that didn’t happen');

        $this->post('/years/2025-26/lock', ['reason' => 'Filed with the accountant’s figures'])->assertSessionHas('success');
        $e = $this->lockEvent();
        $this->assertSame(['Filed with the accountant’s figures', ['undecided']], [$e->reason, $e->after['failing']]);
        $this->assertSame('fail', $e->after['snapshot']['checks']['undecided']);
        $this->assertSame(AuditLog::canonical(['unreviewed' => 1, 'suggested' => 0, 'manual_unreviewed' => 0]), $e->after['snapshot']['pending']);

        // Unlocking: a reason, as before, and a bare event.
        $this->post('/years/2025-26/lock')->assertSessionHasErrors('reason');
        $this->post('/years/2025-26/lock', ['reason' => 'Correct a receipt'])->assertSessionHas('success');
        $unlocked = AuditEvent::query()->where('action', 'tax_year.unlocked')->sole();
        $this->assertSame(['Correct a receipt', null, null], [$unlocked->reason, $unlocked->before, $unlocked->after]);
        $this->assertFalse($year->fresh()->isLocked());
    }

    public function test_the_year_page_shows_the_snapshot_and_checks_it_and_a_change_since_is_found(): void
    {
        $this->year();
        $this->post('/years/2025-26/lock')->assertSessionHas('success');
        $page = $this->get('/years/2025-26')->assertOk();
        $page->assertInertia(fn ($p) => $p->where('lock.figures.net_profit_pence', 2_960_000)->where('lock.locked', true)->missing('lock_check'));
        $check = fn () => $this->get('/years/2025-26', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $page->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'years/show', 'X-Inertia-Partial-Data' => 'lock_check'])->assertOk()->json('props.lock_check');
        $this->assertSame(['ok' => true, 'event_ok' => true, 'chain_ok' => true, 'figures' => [], 'export' => 'same', 'findings' => ['then' => 0, 'now' => 0, 'same' => true]], $check());
        $this->assertSame([], (new YearLockedDrift)->detect(new CycleContext));

        // Software made not allowable after the year was locked: its figures move, though nothing in it changed.
        Category::query()->where('code', 'software')->update(['is_allowable' => false]);
        $result = $check();
        $this->assertFalse($result['ok']);
        $this->assertSame(['then' => 2_960_000, 'now' => 3_000_000], $result['figures']['net_profit_pence']);
        $drafts = (new YearLockedDrift)->detect(new CycleContext);
        $this->assertCount(1, $drafts);
        $d = $drafts[0];
        $this->assertSame(['year.locked_drift', 'year:2025-26', 'none', 'certain', 'tax'], [$d->code, $d->subjectRef, $d->direction, $d->grade, $d->group]);
        $this->assertSame('2025-26 has changed since you locked it: profit £400.00 higher', $d->title);
        $this->assertSame('audit:'.$this->lockEvent()->id.'@'.substr($this->lockEvent()->hash, 0, 8), $d->evidence[1]);

        // The spreadsheet kept with it, gone: said so.
        @unlink(storage_path('app/private/'.$this->lockEvent()->after['snapshot']['export']['file']));
        $this->assertSame('missing', $check()['export']);
    }
}
