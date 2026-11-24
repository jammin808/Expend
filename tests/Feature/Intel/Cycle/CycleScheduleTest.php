<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\Steps\DetectSync;
use App\Domain\Intel\Cycle\Steps\RelateCategorise;
use App\Domain\Intel\Cycle\Steps\RelateJobs;
use App\Domain\Intel\Cycle\Steps\RelateTransfers;
use App\Domain\Intel\Cycle\Steps\VerifyAuditChain;
use App\Domain\Intel\Cycle\Steps\VerifyBacklog;
use App\Jobs\ReadDocument;
use App\Jobs\RunCycleStep;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Document;
use App\Models\Finding;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\User;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The three-state flag intel_cycle and the schedule (docs/intelligence-cycle.md 6.1, M5's "nothing runs twice"): for
 * each value, each piece of nightly work is done exactly once, by the old schedule entry or by the cycle's step, and
 * never by both; nothing is dropped. The tick is quick, runs before the worker, and is skipped while the cycle is off.
 */
class CycleScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** Each old nightly entry and the cycle step that does the same work. */
    private const WORK = [
        'ledger:verify-audit' => VerifyAuditChain::KEY,
        'ledger:transfers' => RelateTransfers::KEY,
        'ledger:categorise' => RelateCategorise::KEY,
        'ledger:find-jobs' => RelateJobs::KEY,
        'ledger:recheck' => VerifyBacklog::KEY,
        'ledger:findings' => DetectSync::KEY,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return Collection<int, Event> */
    private function events()
    {
        return collect(app(Schedule::class)->events());
    }

    private function event(string $command): Event
    {
        $event = $this->events()->first(fn (Event $e) => str_contains((string) $e->command, $command) || $e->description === $command);
        $this->assertNotNull($event, $command.' is scheduled');

        return $event;
    }

    /** Whether the entry would run (its skip() and when() say so); the time is checked apart. */
    private function runs(string $command): bool
    {
        return $this->event($command)->filtersPass(app());
    }

    /** Whether the cycle would do the work of this step, changing records (not a trial run, which skips writers). */
    private function cycleDoes(string $step, string $mode): bool
    {
        if ($mode === 'off') {
            return false;
        }
        $runner = app(CycleRunner::class);
        $nightly = array_keys($runner->plan('nightly'));
        if (! in_array($step, $nightly, true)) {
            return false;
        }

        return $mode === 'on' || ! $runner->step($step)->writes();
    }

    /** London time. */
    private function at(string $london): void
    {
        $utc = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    public function test_each_piece_of_nightly_work_is_done_once_in_every_state(): void
    {
        $this->at('2026-11-24 04:00:00');
        foreach (['off', 'shadow', 'on'] as $mode) {
            Setting::put('intel_cycle', $mode);
            // Tonight's run, started at 03:05 in this mode (none while the cycle is off).
            CycleRunStep::query()->delete();
            CycleRun::query()->delete();
            if ($mode !== 'off') {
                app(CycleRunner::class)->start('nightly', mode: $mode);
            }
            $this->assertSame($mode !== 'off', $this->runs('ledger:cycle tick'), "The tick runs unless the cycle is off ({$mode})");
            foreach (self::WORK as $command => $step) {
                $legacy = $this->runs($command);
                $writes = app(CycleRunner::class)->step($step)->writes();
                if ($mode === 'on') {
                    $this->assertFalse($legacy, "{$command} is skipped once the cycle is on");
                    $this->assertTrue($this->cycleDoes($step, $mode), "{$step} does its work in the cycle");
                } else {
                    $this->assertTrue($legacy, "{$command} still runs ({$mode})");
                    // In a trial, a step that writes is skipped, and one that doesn't only looks (detect.sync works it out and keeps nothing).
                    $this->assertSame($mode === 'shadow' && ! $writes, $this->cycleDoes($step, $mode), "{$step} ({$mode})");
                }
                // Never twice: when both run, the cycle's is a trial that changes nothing (checked in CycleShadowTest).
                $this->assertFalse($legacy && $mode === 'on', "{$command} doesn't run beside the cycle");
            }
        }
    }

    private function transferPair(): void
    {
        foreach ([['Starling', -12000, 'TO SAVINGS'], ['Savings', 12000, 'FROM CURRENT']] as $i => [$account, $pence, $text]) {
            $acc = Account::query()->firstOrCreate(['name' => $account], ['kind' => 'bank']);
            $batch = ImportBatch::query()->firstOrCreate(['account_id' => $acc->id, 'original_filename' => $account.'.csv'], ['file_sha256' => str_repeat('a', 64), 'stored_path' => 'x']);
            BankTransaction::query()->create(['account_id' => $acc->id, 'import_batch_id' => $batch->id, 'posted_on' => '2026-11-20', 'tax_year' => UkTaxYear::fromDate('2026-11-20')->label(),
                'description' => $text, 'counterparty' => null, 'merchant_key' => $text, 'amount_pence' => $pence, 'raw' => [], 'fingerprint' => md5($text), 'occurrence' => 1]);
        }
    }

    private function workThrough(CycleRun $run): CycleRun
    {
        for ($i = 0; $i < 60 && ! $run->fresh()->isFinished(); $i++) {
            app(CycleRunner::class)->work($run->id);
        }

        return $run->fresh();
    }

    /** Switched on in the night after a trial run started: the old entries still do tonight's work, once. */
    public function test_switching_on_during_a_trial_night_leaves_tonights_work_to_the_old_entries(): void
    {
        Bus::fake([RunCycleStep::class]);
        Http::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->transferPair();
        Setting::put('intel_cycle', 'shadow');
        $this->at('2026-11-24 03:05:00');
        $this->artisan('ledger:cycle tick');
        $run = CycleRun::query()->sole();
        $this->assertSame('shadow', $run->mode);

        $this->at('2026-11-24 03:40:00');
        $this->artisan('ledger:cycle mode on')->expectsOutputToContain('Tonight’s run was a trial')->assertSuccessful();
        $this->artisan('ledger:cycle tick');
        $run = $this->workThrough($run);
        $this->assertSame(1, CycleRun::query()->where('kind', 'nightly')->count(), 'No second nightly run');
        $this->assertSame(['skipped', 'shadow'], [CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', RelateTransfers::KEY)->value('status'),
            CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', RelateTransfers::KEY)->first()->counts['skipped']]);

        $this->at('2026-11-24 04:07:00');
        foreach (array_keys(self::WORK) as $command) {
            $this->assertTrue($this->runs($command), "{$command} still does tonight’s work");
        }

        // From tomorrow night the cycle does it, and the old entries are skipped.
        $this->at('2026-11-25 03:05:00');
        $this->artisan('ledger:cycle tick');
        $this->assertSame('on', CycleRun::query()->where('run_key', 'nightly:2026-11-25')->value('mode'));
        $this->at('2026-11-25 04:07:00');
        $this->assertFalse($this->runs('ledger:transfers'));
    }

    /** Switched back to a trial in the night: the real run stops changing records, and the old entries take over. */
    public function test_switching_to_a_trial_during_a_real_night_stops_the_runs_writing(): void
    {
        Bus::fake([RunCycleStep::class]);
        Http::fake();
        Setting::put('job_finder_auto', false);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->transferPair();
        Setting::put('intel_cycle', 'on');
        $this->at('2026-11-24 03:05:00');
        $this->artisan('ledger:cycle tick');
        $run = CycleRun::query()->sole();
        $this->assertSame('on', $run->mode);

        $this->artisan('ledger:cycle mode shadow')->assertSuccessful();
        $this->at('2026-11-24 03:06:00');
        $run = $this->workThrough($run);
        foreach ([RelateTransfers::KEY, RelateCategorise::KEY, RelateJobs::KEY, VerifyBacklog::KEY] as $step) {
            $row = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', $step)->sole();
            $this->assertSame(['skipped', 'mode'], [$row->status, $row->counts['skipped']], $step);
        }
        $this->assertNull(BankTransaction::query()->whereNotNull('transfer_pair_id')->first(), 'The run paired nothing after the switch');
        $sync = CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'detect.sync')->sole();
        $this->assertTrue($sync->counts['shadow'], 'It recorded findings as a trial does: worked out, nothing kept (ledger:findings records them tonight)');
        $this->assertSame(0, Finding::query()->count());
        $this->at('2026-11-24 04:07:00');
        foreach (array_keys(self::WORK) as $command) {
            $this->assertTrue($this->runs($command), "{$command} runs again");
        }
    }

    /** Switched off: the runs still going are ended and sealed, and documents waiting for AI budget can be read again by hand. */
    public function test_switching_off_ends_the_runs_going(): void
    {
        Bus::fake([RunCycleStep::class]);
        Http::fake();
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        Setting::put('intel_cycle', 'on');
        $this->at('2026-11-24 03:05:00');
        $this->artisan('ledger:cycle tick');
        $doc = Document::query()->create(['source' => 'upload', 'original_name' => 'po.pdf', 'mime' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('d', 64),
            'stored_path' => 'documents/po.pdf', 'status' => ReadDocument::WAITING, 'error' => 'This month’s AI spending cap has no room left for reading documents']);

        $this->artisan('ledger:cycle mode off')->expectsOutputToContain('Ended 1 run')->assertSuccessful();
        $run = CycleRun::query()->sole();
        $this->assertSame('partial', $run->status);
        $this->assertNotNull($run->seal_audit_id, 'Sealed');
        $this->assertSame(['skipped'], CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', '!=', 'preserve.seal')->pluck('status')->unique()->values()->all());
        $this->assertSame('failed', $doc->fresh()->status, 'Nothing reads it again on its own while the cycle is off: Read with AI offers it');
        $this->assertStringContainsString('spending cap', $doc->fresh()->error);
    }

    public function test_the_entries_the_cycle_does_not_do_keep_running_whatever_the_flag(): void
    {
        foreach (['off', 'shadow', 'on'] as $mode) {
            Setting::put('intel_cycle', $mode);
            foreach (['ledger:sync-calendar', 'ledger:fetch-email', 'starling-import', 'ledger:telegram-digest', 'ledger:invoice-nudges', 'ledger:opportunities',
                'gmail-rescan', 'gmail-labels', 'queue:work', 'heartbeat', 'queue:prune-failed'] as $name) {
                $this->assertTrue($this->runs($name), "{$name} runs ({$mode})");
            }
        }
    }

    public function test_the_flag_is_off_unless_set_and_anything_else_counts_as_off(): void
    {
        $this->assertSame('off', CycleRunner::mode());
        $this->assertFalse($this->runs('ledger:cycle tick'));
        Setting::put('intel_cycle', 'maybe');
        $this->assertSame('off', CycleRunner::mode());
        $this->assertTrue($this->runs('ledger:verify-audit'));
    }

    public function test_the_tick_runs_every_minute_before_the_worker(): void
    {
        $events = $this->events()->values();
        $tick = $events->search(fn (Event $e) => str_contains((string) $e->command, 'ledger:cycle tick'));
        $worker = $events->search(fn (Event $e) => str_contains((string) $e->command, 'queue:work'));
        $this->assertNotFalse($tick);
        $this->assertLessThan($worker, $tick, 'Defined before queue:work, so a step queued by it runs in the same minute');
        $this->assertSame('* * * * *', $events[$tick]->expression);
        $this->assertFalse($events[$tick]->runInBackground);
    }

    public function test_failed_work_is_pruned_weekly_keeping_ninety_days(): void
    {
        $prune = $this->event('queue:prune-failed');
        $this->assertStringContainsString('--hours=2160', (string) $prune->command, '90 days');
        $this->assertSame('Europe/London', (string) $prune->timezone);
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-29 05:43:00', 'Europe/London')->utc());
        $this->assertTrue($prune->isDue(app()), 'Sunday morning');
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-30 05:43:00', 'Europe/London')->utc());
        $this->assertFalse($prune->isDue(app()));
    }

    public function test_the_nightly_entries_still_run_on_london_time(): void
    {
        foreach (array_keys(self::WORK) as $command) {
            $this->assertSame('Europe/London', (string) $this->event($command)->timezone, $command);
        }
    }
}
