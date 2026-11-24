<?php

namespace Tests\Feature\Intel\Cycle;

use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Cycle\Steps\RecommendAlerts;
use App\Domain\Todo\Dismissals;
use App\Models\Finding;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Critical alerts on Telegram (docs/intelligence-cycle.md 7.6, M5): the most urgent first, not the long known; once per
 * finding, also after a snooze is undone; only the buttons that work for it; and nothing in them that mustn't leave
 * (email addresses, references), with a link that still works.
 */
class AlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        Setting::put('intel_cycle', 'on');
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->at('2026-11-24 07:31:00');
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

    private function finding(array $fields): Finding
    {
        return Finding::unguarded(fn () => Finding::query()->create($fields + ['group' => 'revenue', 'severity' => 'critical', 'first_seen_at' => now(), 'last_seen_at' => now(),
            'state' => 'open', 'reopened' => 0]));
    }

    private function alerts(): array
    {
        return (new RecommendAlerts)->run(new CycleContext(runId: 1, deadline: now()->addMinute()))->counts;
    }

    /** @return list<array<string, mixed>> the sendMessage payloads */
    private function sends(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage'))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    /** @return list<string> the callback data of a message's buttons */
    private function callbacks(array $send): array
    {
        return array_column(array_merge(...($send['reply_markup']['inline_keyboard'] ?? [[]])), 'callback_data');
    }

    public function test_what_can_only_be_snoozed_has_no_not_relevant_button(): void
    {
        $deadline = $this->finding(['key' => 'deadline:2025-26', 'group' => 'deadline', 'code' => 'tax.deadline', 'title' => 'Tax return 2025-26 due in 5 days',
            'due_on' => '2026-11-29']);
        $dupe = $this->finding(['key' => 'dupe:tx:9', 'group' => 'duplicates', 'code' => 'tx.duplicate', 'title' => 'Charged twice by Stage hire Ltd', 'amount_pence' => -90000,
            'currency' => 'GBP', 'direction' => 'at_risk']);
        $this->assertSame(2, $this->alerts()['sent']);

        [$first, $second] = $this->sends();
        $this->assertStringStartsWith('⚠ Tax return 2025-26', $first['text'], 'Due within a week: first');
        $this->assertSame(['t|fnd_snooze|'.$deadline->id.':later'], $this->callbacks($first), 'Snooze only: “Not relevant” would hide it for good');
        $this->assertSame(['t|fnd_snooze|'.$dupe->id.':later', 't|fnd_dismiss|'.$dupe->id], $this->callbacks($second));
    }

    public function test_the_urgent_and_the_new_come_first_and_the_long_known_isnt_sent_when_alerts_start(): void
    {
        // Known for months from the to-do list and the Monday digest.
        foreach (range(1, 6) as $i) {
            $this->finding(['key' => 'invoice_unsent:'.$i, 'group' => 'invoice', 'code' => 'invoice.unsent', 'title' => "Send invoice INV-000{$i}", 'amount_pence' => 60000,
                'currency' => 'GBP', 'direction' => 'owed_to_you', 'first_seen_at' => now()->subMonths(3)->addDays($i)]);
        }
        // New last night: a penalty step in five days.
        $this->finding(['key' => 'penalty:2025-26', 'group' => 'penalties', 'code' => 'tax.penalty', 'title' => 'Fines and interest for 2025-26: £100 on 29 Nov',
            'amount_pence' => 10000, 'currency' => 'GBP', 'direction' => 'owed_by_you', 'due_on' => '2026-11-29', 'first_seen_at' => now()->subHours(4)]);
        // Open a while, and critical only from today (a step failing two nights running).
        $health = $this->finding(['key' => 'health:step:relate.jobs', 'group' => 'health', 'code' => 'health.cycle_step', 'title' => 'The job finder has failed 2 nights running',
            'severity' => 'important', 'first_seen_at' => now()->subDays(2)]);

        $counts = $this->alerts();
        $this->assertSame(['sent' => 1, 'known' => 6], array_intersect_key($counts, ['sent' => 1, 'known' => 1]));
        $this->assertStringStartsWith('⚠ Fines and interest for 2025-26', $this->sends()[0]['text']);

        // The next morning: something new, and something that has become critical; the long known still isn't sent.
        $this->at('2026-11-25 07:31:00');
        $health->forceFill(['severity' => 'critical'])->save();
        $this->finding(['key' => 'dupe:tx:12', 'group' => 'duplicates', 'code' => 'tx.duplicate', 'title' => 'Charged twice by Acme Events', 'amount_pence' => -70000,
            'currency' => 'GBP', 'direction' => 'at_risk', 'first_seen_at' => now()->subHours(3)]);
        $this->assertSame(2, $this->alerts()['sent']);
        $texts = array_column($this->sends(), 'text');
        $this->assertStringStartsWith('⚠ Charged twice by Acme Events', $texts[1], 'Newest first');
        $this->assertStringStartsWith('⚠ The job finder has failed 2 nights running', $texts[2]);
        $this->assertCount(0, array_filter($texts, fn ($t) => str_contains($t, 'Send invoice')));
    }

    public function test_overdue_invoices_are_left_to_the_weekday_nudge(): void
    {
        $this->finding(['key' => 'invoice_overdue:1', 'group' => 'income', 'code' => 'invoice.overdue', 'title' => 'Invoice INV-0001 unpaid', 'amount_pence' => 45000,
            'currency' => 'GBP', 'direction' => 'owed_to_you']);
        $this->assertSame(0, $this->alerts()['sent'], 'The nudge at 09:13 carries it, with its Send a reminder button');
        $this->assertSame([], $this->sends());
    }

    public function test_putting_a_snoozed_alert_back_on_the_list_doesnt_alert_it_again(): void
    {
        $f = $this->finding(['key' => 'dupe:tx:77', 'group' => 'duplicates', 'code' => 'tx.duplicate', 'title' => 'Charged twice by Stage hire Ltd', 'amount_pence' => -90000,
            'currency' => 'GBP', 'direction' => 'at_risk']);
        $this->alerts();
        $this->assertCount(1, $this->sends());
        // Snoozed from the alert; the next morning's alerts run; then you put it back on the list.
        Dismissals::dismiss($f->key, 7, 'Later', 'telegram', true);
        $this->at('2026-11-25 07:31:00');
        $this->alerts();
        $this->at('2026-11-25 12:00:00');
        Dismissals::undo($f->key);
        $this->at('2026-11-26 07:31:00');
        $this->assertSame(0, $this->alerts()['sent']);
        $this->assertCount(1, $this->sends(), 'Nothing about it changed: not alerted again');

        // Dealt with, and back again: a new alert.
        $f->fresh()->forceFill(['reopened' => 1])->save();
        $this->assertSame(1, $this->alerts()['sent']);
    }

    public function test_an_alert_carries_no_email_address_or_reference_and_its_link_works(): void
    {
        $this->finding(['key' => 'invoice_unsent:1', 'group' => 'invoice', 'code' => 'invoice.unsent', 'title' => 'Send invoice INV-0001', 'amount_pence' => 65000,
            'currency' => 'GBP', 'direction' => 'owed_to_you', 'detail' => '£650.00 to Acme Events at alex.smith@acme.example', 'href' => '/invoices/1']);
        $this->finding(['key' => 'dupe:tx:5', 'group' => 'duplicates', 'code' => 'tx.duplicate', 'title' => 'Charged twice? TFL TRAVEL REF:AB12345678', 'amount_pence' => -2000,
            'currency' => 'GBP', 'direction' => 'at_risk', 'href' => '/transactions?q=PAYPAL+%2AACME+4029357733']);
        $this->alerts();
        $texts = array_column($this->sends(), 'text');
        $all = implode("\n", $texts);
        foreach (['alex.smith', 'acme.example', 'AB12345678', '4029357733'] as $secret) {
            $this->assertStringNotContainsString($secret, $all, $secret);
        }
        $this->assertContains("⚠ Send invoice INV-0001 (£650.00)\n".url('/invoices/1'), $texts, 'Title, amount and its own link');
        $this->assertContains("⚠ Charged twice? TFL TRAVEL REF:[ref] (£20.00)\n".url('/todo'), $texts, 'A link carrying a reference goes to the to-do list instead');
    }
}
