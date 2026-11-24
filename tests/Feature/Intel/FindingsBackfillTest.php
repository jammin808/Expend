<?php

namespace Tests\Feature\Intel;

use App\Console\Commands\IntelBackfill;
use App\Domain\Intel\Backfills\Backfill;
use App\Domain\Intel\Backfills\Findings;
use App\Domain\Todo\FindingsRegistry;
use App\Models\Account;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\WorkJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The findings backfill reads findings kept before M3 into the vocabulary, from their keys, with the start of their
 * history; it's idempotent, chunked, run by ledger:intel-backfill and scheduled hourly until it's done.
 */
class FindingsBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Carbon::setTestNow('2025-07-01 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Findings as they were kept before M3: no state, code or events. */
    private function legacy(): array
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 45000, 'currency' => 'GBP', 'bill_to_name' => 'Acme Events']);
        $bank = Account::query()->create(['name' => 'Starling', 'kind' => 'bank']);
        $batch = ImportBatch::query()->create(['account_id' => $bank->id, 'original_filename' => 't.csv', 'file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
        $row = fn (string $key, string $group, array $extra = []) => Finding::query()->create($extra + ['key' => $key, 'group' => $group, 'title' => $key,
            'first_seen_at' => '2025-03-01 10:00:00', 'last_seen_at' => '2025-06-01 10:00:00']);

        return [
            'overdue' => $row('invoice_overdue:'.$invoice->id, 'income', ['amount_pence' => 30000, 'peak_amount_pence' => 45000]),
            'gap' => $row('gap:'.$bank->id.':2025-02-01:2025-02-10:5000', 'reconcile', ['amount_pence' => 5000, 'resolved_at' => '2025-04-01 10:00:00', 'resolution' => 'resolved']),
            'snoozed' => $row('import:'.$batch->id, 'imports', ['resolved_at' => '2025-05-01 10:00:00', 'resolution' => 'snoozed']),
            'joined' => $row('gap:'.$bank->id.':2025-01-03:2000', 'reconcile', ['resolved_at' => '2025-04-02 10:00:00', 'resolution' => 'superseded']),
            'drift' => $row('drift:2024-25:abc', 'tax', ['amount_pence' => -64000]),
            'odd' => $row('something-unknown:7', 'checks'),
            'job' => $job, 'invoice' => $invoice, 'bank' => $bank, 'batch' => $batch,
        ];
    }

    public function test_it_reads_old_findings_from_their_keys_with_the_start_of_their_history(): void
    {
        $l = $this->legacy();
        $r = (new Findings)->run();
        $this->assertSame(['changed' => 6, 'done' => true], $r);

        $overdue = $l['overdue']->fresh();
        $this->assertSame(['open', 'invoice.overdue', 'inv:'.$l['invoice']->id, 'job:'.$l['job']->id, 'owed_to_you', 'certain'],
            [$overdue->state, $overdue->code, $overdue->subject_ref, $overdue->scope_ref, $overdue->direction, $overdue->grade]);
        $detected = FindingEvent::query()->where('finding_id', $overdue->id)->sole();
        $this->assertSame(['detected', '2025-03-01', 45000, 'system'], [$detected->event, $detected->created_at->format('Y-m-d'), $detected->amount_pence, $detected->actor]);

        $gap = $l['gap']->fresh();
        $this->assertSame(['resolved', 'acct.gap', 'acct:'.$l['bank']->id, 'unrecorded'], [$gap->state, $gap->code, $gap->scope_ref, $gap->direction]);
        $this->assertSame([['detected', '2025-03-01'], ['resolved', '2025-04-01']],
            FindingEvent::query()->where('finding_id', $gap->id)->orderBy('id')->get()->map(fn ($e) => [$e->event, $e->created_at->format('Y-m-d')])->all());

        $snoozed = $l['snoozed']->fresh();
        $this->assertSame(['snoozed', 'import.held', 'batch:'.$l['batch']->id, 'acct:'.$l['bank']->id], [$snoozed->state, $snoozed->code, $snoozed->subject_ref, $snoozed->scope_ref]);
        $this->assertSame(['detected', 'snoozed'], FindingEvent::query()->where('finding_id', $snoozed->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame('you', FindingEvent::query()->where('finding_id', $snoozed->id)->where('event', 'snoozed')->value('actor'));

        $this->assertSame(['resolved', 'Joined to another gap'], [$l['joined']->fresh()->state, FindingEvent::query()->where('finding_id', $l['joined']->id)->where('event', 'resolved')->value('note')]);
        $this->assertSame(['year.filed_drift', 'year:2024-25', 'owed_by_you'], [$l['drift']->fresh()->code, $l['drift']->fresh()->scope_ref, $l['drift']->fresh()->direction]);

        // A key it can't read: left null, and still done.
        $odd = $l['odd']->fresh();
        $this->assertSame(['open', null, null, null], [$odd->state, $odd->code, $odd->subject_ref, $odd->direction]);
    }

    public function test_it_is_idempotent_and_works_in_chunks(): void
    {
        $l = $this->legacy();
        $first = (new Findings)->run(4);
        $this->assertSame(['changed' => 4, 'done' => false], $first);
        $this->assertSame(['changed' => 2, 'done' => true], (new Findings)->run(4));
        $events = FindingEvent::query()->count();
        $rows = Finding::query()->orderBy('id')->get()->map->only(['state', 'code', 'subject_ref', 'scope_ref', 'direction'])->all();

        $this->assertSame(['changed' => 0, 'done' => true], (new Findings)->run());
        $this->assertSame($events, FindingEvent::query()->count());
        $this->assertSame($rows, Finding::query()->orderBy('id')->get()->map->only(['state', 'code', 'subject_ref', 'scope_ref', 'direction'])->all());
        // What you or a later run already set is never replaced.
        $l['odd']->forceFill(['state' => null, 'direction' => 'at_risk'])->save();
        (new Findings)->run();
        $this->assertSame('at_risk', $l['odd']->fresh()->direction);
        $this->assertSame(1, FindingEvent::query()->where('finding_id', $l['odd']->id)->count(), 'Its history already started');
    }

    public function test_the_findings_history_adopts_an_old_finding_it_touches(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $old = Finding::query()->create(['key' => 'no_po:'.$job->id, 'group' => 'no_po', 'title' => 'No PO: Arena show', 'first_seen_at' => '2025-05-01 10:00:00', 'last_seen_at' => '2025-06-01 10:00:00']);
        FindingsRegistry::sync();
        $this->assertSame(['open', 'job.no_po'], [$old->fresh()->state, $old->fresh()->code]);
        $this->assertSame(['detected'], FindingEvent::query()->where('finding_id', $old->id)->pluck('event')->all());
        $this->assertSame('2025-05-01', FindingEvent::query()->where('finding_id', $old->id)->value('created_at')->format('Y-m-d'));

        // Gone since: dealt with, after the history it already had.
        $job->update(['po_number' => 'PO-1']);
        Carbon::setTestNow('2025-07-02 09:00:00');
        FindingsRegistry::sync();
        $this->assertSame(['detected', 'resolved'], FindingEvent::query()->where('finding_id', $old->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame(['changed' => 0, 'done' => true], (new Findings)->run());
    }

    public function test_the_command_runs_it_records_it_done_and_the_schedule_stops(): void
    {
        $this->legacy();
        $this->assertArrayHasKey('findings', IntelBackfill::backfills());
        $this->assertInstanceOf(Backfill::class, IntelBackfill::backfills()['findings']);
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ledger:intel-backfill findings'));
        $this->assertNotNull($event, 'Scheduled');
        $this->assertSame('37 * * * *', $event->expression);
        $this->assertTrue($event->filtersPass($this->app), 'Runs while not done');

        $this->artisan('ledger:intel-backfill findings')->expectsOutputToContain('findings: 6 changed; done.')->assertSuccessful();
        $this->assertSame(['findings'], Setting::get(IntelBackfill::DONE));
        $this->assertFalse($event->filtersPass($this->app), 'Skipped once done');
        $this->artisan('ledger:intel-backfill findings')->expectsOutputToContain('already done')->assertSuccessful();
        $this->artisan('ledger:intel-backfill nonsense')->assertFailed();
        $this->assertSame(0, Finding::query()->whereNull('state')->count());
    }
}
