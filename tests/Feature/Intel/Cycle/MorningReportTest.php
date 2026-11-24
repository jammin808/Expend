<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Audit\AuditLog;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\CycleRunner;
use App\Domain\Intel\Cycle\MorningReport;
use App\Domain\Intel\Cycle\Steps\RecommendAlerts;
use App\Domain\Intel\DetectorLevels;
use App\Domain\Invoices\InvoiceService;
use App\Domain\Telegram\Telegram;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "What Expend found and did overnight" (docs/intelligence-cycle.md 7.1 and 7.6, M5): one line on a quiet night and no
 * message; sections left out when empty; money by direction, never mixed; nothing that looks like an account number,
 * card number, sort code or postcode; sent at its time; and critical alerts once each, at most three a day.
 */
class MorningReportTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Setting::put('intel_cycle', 'on');
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->at('2026-11-24 07:06:00');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $london): void
    {
        $utc = CarbonImmutable::parse($london, 'Europe/London')->utc();
        Carbon::setTestNow($utc);
        CarbonImmutable::setTestNow($utc);
    }

    /** @return list<string> the Telegram messages sent */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($pair) => (string) $pair[0]['text'])->values()->all();
    }

    private function finding(array $fields): Finding
    {
        $n = ++$this->n;

        return Finding::unguarded(fn () => Finding::query()->create($fields + ['key' => 'test:'.$n, 'group' => 'revenue', 'title' => 'Finding '.$n, 'first_seen_at' => now(),
            'last_seen_at' => now(), 'state' => 'open', 'reopened' => 0]));
    }

    private function overdueInvoice(): Invoice
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14', 'po_number' => 'PO-1']);
        $invoice = Invoice::query()->create(['work_job_id' => $job->id, 'status' => 'draft', 'total_minor' => 0, 'bill_to_name' => 'Acme Events', 'bill_to_email' => 'accounts@acme.example']);
        app(InvoiceService::class)->updateDraft($invoice, [], [['description' => 'Show day', 'quantity' => '1', 'unit_price' => '450.00']]);

        return app(InvoiceService::class)->issue($invoice->fresh(), '2026-09-15')->fresh();
    }

    private function workThrough(CycleRun $run): CycleRun
    {
        for ($i = 0; $i < 40 && ! $run->fresh()->isFinished(); $i++) {
            app(CycleRunner::class)->work($run->id);
        }

        return $run->fresh();
    }

    public function test_a_quiet_night_is_one_line_and_no_message(): void
    {
        $run = app(CycleRunner::class)->start('morning');
        $this->at('2026-11-24 07:31:00');
        $run = $this->workThrough($run);

        $this->assertSame('ok', $run->status);
        $this->assertSame('A quiet night: nothing new found, and nothing needs you.', $run->report['line']);
        $this->assertTrue($run->report['quiet']);
        foreach (['found', 'did', 'spot_check', 'dealt', 'needs', 'health'] as $section) {
            $this->assertArrayNotHasKey($section, $run->report, "{$section} is left out when empty");
        }
        $this->assertSame([], $this->sent());
        $this->assertNull($run->reported_at);
    }

    public function test_the_report_waits_for_its_time_then_is_sent_and_kept(): void
    {
        $invoice = $this->overdueInvoice();
        $runner = app(CycleRunner::class);
        $run = $runner->start('morning');
        for ($i = 0; $i < 10; $i++) {
            $runner->work($run->id);
        }
        $step = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'recommend.report')->sole();
        $this->assertSame('more', $step()->status, 'Waits for 07:30');
        $this->assertNotNull($step()->cursor['not_before']);
        $this->assertNull($runner->next($run->fresh()), 'The tick has nothing to queue until then');
        $this->assertSame([], $this->sent());

        $this->at('2026-11-24 07:30:00');
        $run = $this->workThrough($run);
        $this->assertSame('ok', $step()->status);
        $this->assertNotNull($run->reported_at);
        $this->assertContains('invoice_overdue:'.$invoice->id, array_column($run->report['found']['items'], 'key'));
        $sent = $this->sent();
        $reports = array_values(array_filter($sent, fn ($t) => str_starts_with($t, 'What Expend found and did overnight')));
        $this->assertCount(1, $reports);
        $this->assertStringContainsString('Invoice '.$invoice->number, $reports[0]);
        // Unpaid and not yet sent are two things to do about one invoice: its money is counted once.
        $this->assertStringContainsString('You may be owed £450.00', $reports[0]);
        $this->assertStringContainsString(url('/intel'), $reports[0]);
        // Long overdue is critical, but not alerted on its own: the weekday nudge carries it, with its reminder button.
        $this->assertCount(0, array_filter($sent, fn ($t) => str_starts_with($t, '⚠ Invoice '.$invoice->number)));
    }

    public function test_the_report_reads_by_section_and_never_carries_account_numbers(): void
    {
        $run = app(CycleRunner::class)->start('morning');
        $this->finding(['title' => 'Possible duplicate: ACME EVENTS ref 12345678, sort code 12-34-56, card 4929 1234 5678 9012, at LS11 8BU',
            'code' => 'tx.duplicate', 'amount_pence' => -4500, 'currency' => 'GBP', 'direction' => 'at_risk', 'severity' => 'important']);
        $this->finding(['title' => 'Invoice to Acme Events unpaid', 'code' => 'invoice.overdue', 'amount_pence' => 45000, 'currency' => 'GBP', 'direction' => 'owed_to_you', 'severity' => 'critical']);
        $this->finding(['title' => 'Rechargeable hotel', 'code' => 'travel.recharge', 'amount_pence' => 12000, 'currency' => 'EUR', 'direction' => 'owed_to_you']);
        $gone = $this->finding(['title' => 'Gap in the Starling statement', 'code' => 'acct.gap', 'first_seen_at' => now()->subDays(9), 'resolved_at' => now()->subHours(3),
            'resolution' => 'resolved', 'state' => 'resolved']);
        FindingEvent::query()->create(['finding_id' => $gone->id, 'event' => 'resolved', 'cause_ref' => 'batch:5', 'actor' => 'system', 'created_at' => now()->subHours(3)]);
        $old = $this->finding(['title' => 'Seen last week', 'first_seen_at' => now()->subDays(8)]);
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-14']);
        foreach (range(1, 3) as $i) {
            AuditLog::record('allocation.suggested', $job, null, ['n' => $i], null, 'matcher');
        }
        AuditLog::record('transfer.paired', $job, null, null, null, 'matcher');
        AuditLog::record('transfer.paired', $job, null, null, null, 'matcher');
        AuditLog::record('job.updated', $job, null, ['title' => 'Arena show'], null, 'you');

        $report = MorningReport::build($run);
        $this->assertSame(3, $report['found']['count'], 'Only what’s new since the last report');
        $this->assertSame('Invoice to Acme Events unpaid', $report['found']['items'][0]['title'], 'Most at stake first');
        $money = collect($report['found']['money'])->map(fn ($m) => $m['direction'].':'.$m['currency'].':'.$m['pence'])->sort()->values()->all();
        $this->assertSame(['at_risk:GBP:4500', 'owed_to_you:EUR:12000', 'owed_to_you:GBP:45000'], $money, 'By direction and currency, never one total');
        $this->assertSame(['suggested what 3 payments are', 'paired 1 transfer between your accounts'], array_column($report['did']['items'], 'text'), 'Not what you did');
        $this->assertSame(['count' => 1, 'by' => ['import' => 1]], array_intersect_key($report['dealt'], ['count' => 1, 'by' => 1]));
        $this->assertNotContains($old->key, array_column($report['found']['items'], 'key'));
        $this->assertFalse($report['quiet']);

        $text = MorningReport::telegram($report);
        $this->assertStringContainsString('You may be owed £450.00 · You may be owed €120.00 · May be lost £45.00', $text);
        $this->assertStringContainsString('Did on its own: suggested what 3 payments are, paired 1 transfer between your accounts.', $text);
        $this->assertStringContainsString('Dealt with: 1 (1 by an import).', $text);
        foreach (['12345678', '12-34-56', '4929', '9012', 'LS11 8BU'] as $secret) {
            $this->assertStringNotContainsString($secret, $text, $secret);
        }
        $this->assertStringContainsString('ACME EVENTS', $text, 'Merchant names stay, as in the Monday digest');

        Setting::put('intel_report_detail', 'counts');
        $short = MorningReport::telegram($report, 'counts');
        $this->assertSame("What Expend found and did overnight\n".$report['line']."\nMore: ".url('/intel'), $short);
        $this->assertStringNotContainsString('ACME', $short);
    }

    public function test_cleaning_keeps_amounts_and_dates(): void
    {
        $this->assertSame('£1,250.00 on 14 Dec 2026, 2026-12-14, job 41', MorningReport::clean('£1,250.00 on 14 Dec 2026, 2026-12-14, job 41'));
        $this->assertSame('Account [number], IBAN [account]', MorningReport::clean('Account 87654321, IBAN GB29 NWBK 6016 1331 9268 19'));
    }

    public function test_critical_findings_are_alerted_once_and_at_most_three_a_day(): void
    {
        $critical = collect(range(1, 5))->map(fn ($i) => $this->finding(['title' => "Penalty step in {$i} days", 'code' => 'tax.penalty', 'severity' => 'critical',
            'amount_pence' => 10000, 'direction' => 'owed_by_you']));
        $this->finding(['title' => 'The audit log has been altered', 'code' => 'health.audit_chain', 'severity' => 'critical', 'group' => 'health']);
        $this->finding(['title' => 'Only important', 'code' => 'invoice.overdue', 'severity' => 'important']);
        $alerts = fn () => app(RecommendAlerts::class)->run(new CycleContext(runId: null));

        $this->assertSame(['sent' => 3, 'held_back' => 2, 'critical' => 5], $alerts()->counts);
        $this->assertCount(3, $this->sent());
        $this->assertStringStartsWith('⚠ Penalty step in 1 days (£100.00)', $this->sent()[0]);
        $buttons = Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->first()[0]['reply_markup']['inline_keyboard'];
        $this->assertSame(Telegram::callbackData('fnd_snooze', $critical[0]->id.':later'), $buttons[0][0]['callback_data'], 'Snooze a week and Not relevant');

        $this->assertSame(0, $alerts()->counts['sent'], 'Three a day');
        $this->assertCount(3, $this->sent());

        $this->at('2026-11-25 07:40:00');
        $this->assertSame(['sent' => 2, 'held_back' => 0, 'critical' => 2], $alerts()->counts, 'The next day, the two held back; never the same one twice');
        $this->assertSame(0, $alerts()->counts['sent']);

        // Dealt with, and back again: a new alert.
        $critical[0]->forceFill(['reopened' => 1])->save();
        $this->assertSame(1, $alerts()->counts['sent']);
        $this->assertCount(6, $this->sent());
    }

    public function test_checks_below_the_fold_are_kept_off_telegram_and_out_of_the_totals(): void
    {
        // cost.no_receipt is one of M4's new checks: below the fold, recorded at info, kept off Telegram until raised.
        $this->finding(['key' => 'noreceipt:tx:9', 'group' => 'receipts', 'code' => 'cost.no_receipt', 'title' => 'No receipt: Stage hire Ltd', 'amount_pence' => 90000,
            'currency' => 'GBP', 'direction' => 'claimable', 'grade' => 'likely', 'severity' => 'info']);
        // Failing two nights running: recorded critical (its meta), though its amount alone would say otherwise.
        $this->finding(['key' => 'health:step:relate.jobs', 'group' => 'health', 'code' => 'health.cycle_step', 'title' => 'The job finder has failed 2 nights running',
            'direction' => 'none', 'severity' => 'critical']);
        $report = MorningReport::build(app(CycleRunner::class)->start('morning'));

        $this->assertSame(1, $report['found']['count']);
        $this->assertSame([['health:step:relate.jobs', 'critical']], array_map(fn ($i) => [$i['key'], $i['severity']], $report['found']['items']), 'As recorded');
        $this->assertSame([], $report['found']['money'], 'Not counted in the totals');
        $this->assertSame(1, $report['found']['below_fold']);
        $this->assertSame([['noreceipt:tx:9', 'info']], array_map(fn ($i) => [$i['key'], $i['severity']], $report['found']['below_items']), 'Listed apart on /intel');
        $text = MorningReport::telegram($report);
        $this->assertStringNotContainsString('No receipt', $text);
        $this->assertStringNotContainsString('£900.00', $text);
        $this->assertStringContainsString('• The job finder has failed 2 nights running', $text);
    }

    public function test_a_kind_turned_down_by_its_false_alarms_is_named_once_in_the_next_report(): void
    {
        // Demoted by the nightly findings run (DetectorLevels::settle(), M4), as it keeps it.
        Setting::put(DetectorLevels::SETTING, ['job.closeout' => ['level' => 'demoted', 'at' => CarbonImmutable::parse('2026-11-24 05:07:00', 'Europe/London')->toIso8601String(),
            'reason' => '2 of 10 findings since 26 Aug 2026 were marked not relevant as wrong.']]);
        $run = app(CycleRunner::class)->start('morning');
        $this->at('2026-11-24 07:31:00');
        $run = $this->workThrough($run);

        $line = collect($run->report['health']['lines'])->firstWhere('code', 'job.closeout');
        $this->assertSame(['info', 'Jobs to close out: turned down. 2 of 10 findings since 26 Aug 2026 were marked not relevant as wrong. Shown below the fold until you raise it again.'],
            [$line['level'], $line['text']]);
        $this->assertTrue($run->report['quiet'], 'A note, not news: no message for it alone');
        $this->assertSame([], $this->sent());

        // Named once: the next morning's report doesn't repeat it.
        $this->at('2026-11-25 07:06:00');
        $next = app(CycleRunner::class)->start('morning');
        $this->at('2026-11-25 07:31:00');
        $next = $this->workThrough($next);
        $this->assertNull(collect($next->report['health']['lines'] ?? [])->firstWhere('code', 'job.closeout'));
        $this->assertSame('demoted', DetectorLevels::levelOf('job.closeout'), 'Still demoted: only the report moved on');
    }

    public function test_a_night_with_only_notes_is_quiet_and_sends_nothing(): void
    {
        $this->at('2026-11-24 03:05:00');
        $night = app(CycleRunner::class)->start('nightly');
        $night->forceFill(['status' => 'ok', 'finished_at' => now(), 'ai_spent_micro' => 40_000])->save();
        AuditLog::record('verification.checked', 'Document', null, ['status' => 'verified'], null, 'checker', 1);

        $this->at('2026-11-24 07:06:00');
        $run = app(CycleRunner::class)->start('morning');
        $this->at('2026-11-24 07:31:00');
        $run = $this->workThrough($run);

        $this->assertTrue($run->report['quiet'], 'The AI spent and a routine double-check are notes, not news');
        $this->assertSame('verification', $run->report['did']['items'][0]['action'], 'Still on /intel');
        $this->assertStringStartsWith('A quiet night', $run->report['line']);
        $this->assertSame(['spent_usd' => 0.04, 'allowance_usd' => round($night->ai_budget_micro / 1_000_000, 2)], $run->report['health']['ai'], 'Still on /intel');
        $this->assertSame([], $this->sent());
        $this->assertNull($run->reported_at);
    }

    public function test_a_report_telegram_doesnt_take_is_tried_again(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => false, 'description' => 'Too Many Requests: retry after 5'], 429)
            ->whenEmpty(Http::response(['ok' => true, 'result' => []]))]);
        $this->finding(['title' => 'Charged twice by Stage hire Ltd', 'code' => 'tx.duplicate', 'amount_pence' => -4500, 'currency' => 'GBP', 'direction' => 'at_risk', 'severity' => 'important']);
        $runner = app(CycleRunner::class);
        $run = $runner->start('morning');
        $this->at('2026-11-24 07:31:00');
        $run = $this->workThrough($run);
        $step = fn () => CycleRunStep::query()->where('cycle_run_id', $run->id)->where('step', 'recommend.report')->sole();
        $this->assertSame(['more', 1], [$step()->status, $step()->cursor['tries']], 'Telegram was busy: again in a couple of minutes');
        $this->assertFalse($run->isFinished());
        $this->assertNull($run->reported_at);
        $this->assertNotNull($run->report, 'Kept on /intel meanwhile');

        $this->at('2026-11-24 07:34:00');
        $run = $this->workThrough($run);
        $this->assertSame('ok', $step()->status);
        $this->assertNotNull($run->reported_at);
        $this->assertCount(2, array_filter($this->sent(), fn ($t) => str_starts_with($t, 'What Expend found and did overnight')), 'Refused once, then taken');
    }

    public function test_needs_you_reads_as_labels_and_counts(): void
    {
        $this->overdueInvoice();
        $this->finding(['title' => 'Charged twice by Stage hire Ltd', 'code' => 'tx.duplicate', 'amount_pence' => -4500, 'currency' => 'GBP', 'direction' => 'at_risk', 'severity' => 'important']);
        $run = app(CycleRunner::class)->start('morning');
        $report = MorningReport::build($run);
        $this->assertNotEmpty($report['needs']['groups']);
        $text = MorningReport::telegram($report);
        $this->assertStringContainsString('Unpaid invoices: 1', $text);
        $this->assertDoesNotMatchRegularExpression('/\d+ unpaid invoices/', $text);
    }

    public function test_a_trial_run_sends_no_alert(): void
    {
        $this->finding(['title' => 'Penalty step soon', 'code' => 'tax.penalty', 'severity' => 'critical']);
        $out = app(RecommendAlerts::class)->run(new CycleContext(runId: null, shadow: true));
        $this->assertSame(1, $out->counts['would_send']);
        $this->assertSame([], $this->sent());
        $this->assertNull(Setting::get(RecommendAlerts::SENT));
    }
}
