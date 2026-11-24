<?php

namespace Tests\Feature\Intel;

use App\Domain\Intel\AuditContext;
use App\Domain\Intel\Backfills\Findings;
use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\RefResolver;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BankTransaction;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\Verification;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * What the M3 review found: money never one total across directions; duplicates and gaps kept before M3 read without
 * false changes; each run of failures, each snooze's real end and each gap joined into another recorded as it was;
 * reasons limited to the kind of finding; Telegram buttons read what's hidden now; detectors only read whatever the
 * cache store; rolling back to the release before and forward again leaves nothing falsely dealt with.
 */
class FindingsReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->at('2025-07-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $when): void
    {
        $when = strlen($when) === 10 ? $when.' 09:00:00' : $when;
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }

    private function bank(string $name = 'Starling'): Account
    {
        return Account::query()->firstOrCreate(['name' => $name], ['kind' => 'bank', 'is_active' => true]);
    }

    private function row(string $date, int $pence, ?int $balance, string $description = 'Line', ?Account $account = null): BankTransaction
    {
        $account ??= $this->bank();
        $batch = ImportBatch::query()->firstOrCreate(['account_id' => $account->id, 'original_filename' => 't.csv'],
            ['file_sha256' => hash('sha256', 't'.$account->id), 'stored_path' => 'x', 'status' => 'committed']);

        return BankTransaction::query()->create(['account_id' => $account->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => UkTaxYear::fromDate($date)->label(),
            'description' => $description, 'merchant_key' => strtoupper($description), 'amount_pence' => $pence, 'balance_after_pence' => $balance, 'raw' => [],
            'fingerprint' => hash('sha256', (string) ++$this->n), 'occurrence' => 1]);
    }

    /** @return list<string> */
    private function events(Finding $f): array
    {
        return FindingEvent::query()->where('finding_id', $f->id)->orderBy('id')->pluck('event')->all();
    }

    /** A detector whose findings, and whether it works, the test sets. */
    private function fake(string $code = 'test.fake'): Detector
    {
        return new class($code) implements Detector
        {
            /** @var list<FindingDraft> */
            public array $drafts = [];

            public bool $throws = false;

            private Coverage $coverage = Coverage::Failed;

            public function __construct(private string $code) {}

            public function code(): string
            {
                return $this->code;
            }

            public function version(): int
            {
                return 1;
            }

            public function detect(CycleContext $ctx): array
            {
                $this->coverage = Coverage::Failed;
                if ($this->throws) {
                    throw new RuntimeException('The check went wrong');
                }
                $this->coverage = Coverage::Complete;

                return $this->drafts;
            }

            public function coverage(): Coverage
            {
                return $this->coverage;
            }
        };
    }

    private function gap(string $from, string $to, int $amount): FindingDraft
    {
        return new FindingDraft(key: 'gap:1:'.$from, code: 'acct.gap', group: 'reconcile', priority: 2, title: 'Gap from '.$from, subjectRef: 'acct:1', amountPence: $amount,
            direction: 'unrecorded', grade: 'certain', meta: ['range' => ['from' => $from, 'to' => $to]]);
    }

    private function telegram(): void
    {
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Setting::put('telegram_buttons_on', true);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function press(string $data, int $message = 7): ?string
    {
        Telegram::handleButton(['id' => 'q'.$message, 'data' => $data, 'message' => ['message_id' => $message, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => 'x']],
            app(TodoActions::class));

        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery'))->map(fn ($p) => $p[0]['text'] ?? null)->last();
    }

    // ---------------------------------------------------------------- money

    public function test_money_is_never_one_total_across_directions(): void
    {
        $make = fn (string $key, int $amount, string $direction) => Finding::query()->create(['key' => $key, 'group' => 'revenue', 'title' => $key, 'amount_pence' => $amount,
            'peak_amount_pence' => $amount, 'direction' => $direction, 'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $make('invoice_overdue:1', 10000, 'owed_to_you');
        $make('penalty:2024-25', -3000, 'owed_by_you');
        $make('dupe:9', -700, 'at_risk');
        $summary = FindingsRegistry::summary('2025-01-01');

        foreach (['money_open', 'money_resolved', 'money_open_other', 'money_resolved_other'] as $mixed) {
            $this->assertArrayNotHasKey($mixed, $summary, 'No figure adds money from different directions');
        }
        $this->assertSame(['owed_to_you' => ['GBP' => 10000], 'owed_by_you' => ['GBP' => 3000], 'at_risk' => ['GBP' => 700]],
            collect($summary['by_direction'])->mapWithKeys(fn ($d) => [$d['direction'] => $d['open']])->all());
        $this->actingAs(User::factory()->create())->get('/todo/history')->assertOk()->assertInertia(fn ($p) => $p->missing('money_open')->missing('money_resolved')->has('by_direction', 3));
    }

    public function test_a_duplicate_kept_before_m3_counts_only_its_extra_charges(): void
    {
        $first = $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        $second = $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        $old = $this->row('2025-05-02', -3000, null, 'TRAINLINE');
        // As kept before M3: every charge's money, and no state. One still open, one dealt with then.
        $legacy = fn (string $key, int $amount, ?string $resolved) => DB::table('findings')->insertGetId(['key' => $key, 'group' => 'duplicates', 'title' => 'Charged twice?',
            'detail' => '2 payments of £25.00', 'amount_pence' => $amount, 'peak_amount_pence' => $amount, 'first_seen_at' => '2025-06-20 05:07:00', 'last_seen_at' => '2025-06-30 05:07:00',
            'resolved_at' => $resolved, 'resolution' => $resolved ? 'resolved' : null, 'reopened' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $open = Finding::query()->findOrFail($legacy('dupe:'.$first->id, -5000, null));
        $ended = Finding::query()->findOrFail($legacy('dupe:'.$old->id, -6000, '2025-05-10 05:07:00'));

        FindingsRegistry::sync();
        $open->refresh();
        $this->assertSame([-2500, -2500], [$open->amount_pence, $open->peak_amount_pence], 'One extra charge, not both');
        $this->assertSame(['detected'], $this->events($open), 'The way it’s counted changing is no change to the money');
        $this->assertSame(-2500, FindingEvent::query()->where('finding_id', $open->id)->value('amount_pence'));

        (new Findings)->run();
        $this->assertSame([-3000, -3000], [$ended->fresh()->amount_pence, $ended->fresh()->peak_amount_pence], 'One dealt with before M3 too');

        // The second charge turns out to be one side of a transfer: dealt with, and what was at stake was one charge.
        BankTransaction::query()->whereKey($second->id)->update(['transfer_pair_id' => $first->id]);
        $this->at('2025-07-02');
        FindingsRegistry::sync();
        $atRisk = collect(FindingsRegistry::summary('2025-01-01')['by_direction'])->firstWhere('direction', 'at_risk');
        $this->assertSame(['GBP' => 5500], $atRisk['resolved']);
    }

    // ---------------------------------------------------------------- events

    public function test_a_second_run_of_failures_is_recorded(): void
    {
        $fake = $this->fake();
        $this->app->instance(Registry::class, new Registry([$fake]));
        $fake->drafts = [new FindingDraft(key: 'fake:1', code: 'test.fake', group: 'duplicates', priority: 2, title: 'X', subjectRef: 'acct:1', amountPence: -100, direction: 'at_risk', grade: 'likely')];
        FindingsRegistry::sync();
        $f = Finding::query()->sole();
        $fake->throws = true;
        FindingsRegistry::sync();
        FindingsRegistry::sync(); // the same run of failures
        $fake->throws = false;
        $this->at('2025-07-02');
        FindingsRegistry::sync(); // works, and it's still there
        $fake->throws = true;
        $this->at('2025-07-03');
        FindingsRegistry::sync(); // fails again

        $failed = FindingEvent::query()->where('finding_id', $f->id)->where('event', 'detector_failed')->pluck('created_at')->map(fn ($d) => $d->format('Y-m-d'))->all();
        $this->assertSame(['2025-07-01', '2025-07-03'], $failed);
        $this->assertSame('open', $f->fresh()->state);
    }

    public function test_snooze_ended_only_when_the_snooze_ends(): void
    {
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        $gap = Finding::query()->where('key', 'gap:'.$bank->id.':2025-06-02')->sole();
        Dismissals::dismiss($gap->key, 30, 'Waiting for the statement');

        // Partly filled the next day: back on the list (it's hidden as you saw it), though the snooze runs to 31 July.
        $this->at('2025-07-02');
        $this->row('2025-06-08', 3000, 105000);
        FindingsRegistry::sync();
        $this->assertSame(['detected', 'snoozed', 'reopened', 'changed'], $this->events($gap));
        $this->assertSame('Changed since you snoozed it', FindingEvent::query()->where('finding_id', $gap->id)->where('event', 'reopened')->value('note'));

        // A snoozed finding followed under a new key (mileage whose jobs changed) is the same: back early, not ended.
        $fake = $this->fake('travel.unclaimed');
        $this->app->instance(Registry::class, new Registry([$fake]));
        $mileage = fn (string $set) => new FindingDraft(key: 'mileage:2025-26:'.$set, code: 'travel.unclaimed', group: 'mileage', priority: 3, title: 'Mileage', subjectRef: 'year:2025-26',
            amountPence: 4500, direction: 'claimable', grade: 'likely');
        $fake->drafts = [$mileage('aaa')];
        FindingsRegistry::sync();
        $m = Finding::query()->where('key', 'mileage:2025-26:aaa')->sole();
        TodoDismissal::query()->create(['key' => $m->key, 'until' => now()->addDays(30), 'note' => 'Later']);
        FindingsRegistry::sync();
        $fake->drafts = [$mileage('bbb')];
        $this->at('2025-07-03');
        FindingsRegistry::sync();
        $this->assertSame(['detected', 'snoozed', 'rekeyed', 'reopened'], $this->events($m->fresh()));

        // One whose snooze has run out: that's snooze_ended.
        TodoDismissal::query()->create(['key' => 'mileage:2025-26:bbb', 'until' => now()->addDays(2), 'note' => 'Later']);
        FindingsRegistry::sync();
        $this->at('2025-07-06');
        FindingsRegistry::sync();
        $this->assertSame(['detected', 'snoozed', 'rekeyed', 'reopened', 'snoozed', 'snooze_ended'], $this->events($m->fresh()));
    }

    public function test_a_gap_kept_before_m3_gets_no_changed_event_when_nothing_changed(): void
    {
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        DB::table('findings')->insert(['key' => 'gap:'.$bank->id.':2025-06-02:2025-06-09:5000', 'group' => 'reconcile', 'title' => 'Starling: £50.00 missing', 'amount_pence' => 5000,
            'peak_amount_pence' => 5000, 'first_seen_at' => '2025-06-20 09:00:00', 'last_seen_at' => '2025-06-30 09:00:00', 'reopened' => 0, 'created_at' => now(), 'updated_at' => now()]);
        FindingsRegistry::sync();
        $f = Finding::query()->sole();
        $this->assertSame('gap:'.$bank->id.':2025-06-02', $f->key);
        $this->assertSame(['detected', 'rekeyed'], $this->events($f), 'Same days, same money');

        // Its days change later (the money doesn't): that is a change, worked out from the days its old key said.
        $this->at('2025-07-02');
        $this->row('2025-06-05', 0, 105000);
        FindingsRegistry::sync();
        $this->assertSame(['detected', 'rekeyed', 'changed'], $this->events($f->fresh()));
        $this->assertSame([5000, 'Days 2025-06-02 to 2025-06-04'], [$f->fresh()->amount_pence, FindingEvent::query()->where('finding_id', $f->id)->orderByDesc('id')->value('note')]);
    }

    public function test_a_gap_joined_into_a_gap_followed_in_the_same_run_cites_it(): void
    {
        $fake = $this->fake('acct.gap');
        $this->app->instance(Registry::class, new Registry([$fake]));
        $fake->drafts = [$this->gap('2025-06-02', '2025-06-05', 3000), $this->gap('2025-06-10', '2025-06-15', 2000)];
        FindingsRegistry::sync();
        $a = Finding::query()->where('key', 'gap:1:2025-06-02')->sole();
        $b = Finding::query()->where('key', 'gap:1:2025-06-10')->sole();

        $this->at('2025-07-02');
        $fake->drafts = [$this->gap('2025-06-04', '2025-06-15', 4000)];
        FindingsRegistry::sync();
        $this->assertSame('gap:1:2025-06-04', $a->fresh()->key);
        $this->assertSame(['superseded', 'fnd:'.$a->id], [$b->fresh()->resolution, $b->fresh()->resolution_ref]);
        $this->assertSame('fnd:'.$a->id, FindingEvent::query()->where('finding_id', $b->id)->orderByDesc('id')->value('cause_ref'));
    }

    public function test_a_finding_back_while_its_snooze_still_runs_is_hidden_not_dealt_with(): void
    {
        $fake = $this->fake('acct.gap');
        $this->app->instance(Registry::class, new Registry([$fake]));
        $fake->drafts = [$this->gap('2025-06-02', '2025-06-05', 3000)];
        FindingsRegistry::sync();
        $f = Finding::query()->sole();
        TodoDismissal::query()->create(['key' => $f->key, 'until' => now()->addDays(30), 'note' => 'Later']);
        FindingsRegistry::sync();
        $this->at('2025-07-02');
        $fake->drafts = [];
        FindingsRegistry::sync();
        $this->assertSame('resolved', $f->fresh()->state, 'Gone while snoozed: dealt with');

        $this->at('2025-07-03');
        $fake->drafts = [$this->gap('2025-06-02', '2025-06-05', 3000)];
        FindingsRegistry::sync();
        $f->refresh();
        $this->assertSame(['snoozed', 'snoozed', 1, 'Later'], [$f->state, $f->resolution, $f->reopened, $f->note]);
        $this->assertSame(['detected', 'snoozed', 'resolved', 'reopened', 'snoozed'], $this->events($f));
        $this->assertSame(0, FindingsRegistry::summary('2025-01-01')['resolved'], 'Not counted as dealt with');
    }

    public function test_a_new_finding_and_its_detected_event_are_written_together(): void
    {
        WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingEvent::creating(fn (FindingEvent $e) => $e->event === 'detected' ? throw new RuntimeException('Stopped part way') : null);
        $this->assertThrows(fn () => FindingsRegistry::sync(), RuntimeException::class);
        $this->assertSame(0, Finding::query()->count(), 'No finding left without its detected event');
    }

    public function test_fines_and_interest_note_a_change_once_it_has_moved_ten_pounds(): void
    {
        $fake = $this->fake('tax.penalty');
        $this->app->instance(Registry::class, new Registry([$fake]));
        $penalty = fn (int $amount) => new FindingDraft(key: 'penalty:2024-25', code: 'tax.penalty', group: 'tax', priority: 1, title: 'Fines and interest', subjectRef: 'year:2024-25',
            amountPence: $amount, direction: 'owed_by_you', grade: 'likely');
        foreach ([-75431, -75540, -75650, -75760, -76500, -76610] as $day => $amount) {
            $this->at('2025-07-'.str_pad((string) ($day + 1), 2, '0', STR_PAD_LEFT));
            $fake->drafts = [$penalty($amount)];
            FindingsRegistry::sync();
        }
        $f = Finding::query()->sole();
        $this->assertSame(-76610, $f->amount_pence, 'The finding always has today’s figure');
        $this->assertSame([['detected', -75431], ['changed', -76500]], FindingEvent::query()->where('finding_id', $f->id)->orderBy('id')->get()
            ->map(fn ($e) => [$e->event, $e->amount_pence])->all(), 'Daily interest isn’t a change each day');
    }

    public function test_the_history_page_sends_the_latest_events_and_the_rest_when_asked(): void
    {
        $f = Finding::query()->create(['key' => 'no_po:1', 'group' => 'no_po', 'title' => 'No PO: Arena show', 'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        foreach (range(1, 30) as $i) {
            FindingsRegistry::event($f, $i === 1 ? 'detected' : 'changed', note: 'Step '.$i);
        }
        $row = collect(FindingsRegistry::summary('2025-01-01')['recent'])->firstWhere('key', 'no_po:1');
        $this->assertSame([FindingsRegistry::EVENTS_SHOWN, 30], [count($row['events']), $row['events_total']]);
        $this->assertSame(['Step 11', 'Step 30'], [$row['events'][0]['note'], $row['events'][19]['note']], 'The latest, oldest first');

        config(['inertia.testing.ensure_pages_exist' => false]); // the reload checks the page's file by a path of its own
        $this->actingAs(User::factory()->create())->get('/todo/history?events='.$f->id)->assertOk()
            ->assertInertia(fn ($p) => $p->missing('more_events')->reloadOnly('more_events', fn ($r) => $r->where('more_events.id', $f->id)
                ->has('more_events.events', FindingEvent::query()->where('finding_id', $f->id)->count())->where('more_events.events.0.note', 'Step 1')));
    }

    // ---------------------------------------------------------------- reasons and dismissals

    public function test_only_the_reasons_offered_for_the_kind_are_taken(): void
    {
        $this->actingAs(User::factory()->create());
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $tx = $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        FindingsRegistry::sync();

        $this->post('/todo/dismiss', ['key' => 'no_po:'.$job->id, 'reason' => 'real'])->assertSessionHasErrors('reason');
        $this->post('/todo/dismiss', ['key' => 'no_po:'.$job->id, 'reason' => 'refunded'])->assertSessionHasErrors('reason');
        $this->assertSame(0, TodoDismissal::query()->count());
        $this->assertThrows(fn () => Dismissals::note('real', null, false, 'job.no_po'), \InvalidArgumentException::class);

        $this->post('/todo/dismiss', ['key' => 'dupe:'.$tx->id, 'reason' => 'real'])->assertSessionHas('success');
        $this->assertSame('Both charges were real', TodoDismissal::query()->where('key', 'dupe:'.$tx->id)->value('note'));
    }

    public function test_not_relevant_on_a_gap_filled_since_it_was_shown_is_refused(): void
    {
        $this->actingAs(User::factory()->create());
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        $gap = collect(TodoList::build()['items'])->firstWhere('group', 'reconcile');
        $this->assertSame('gap:'.$bank->id.':2025-06-02', $gap['key']);

        // Filled while the page was open: kept under its first day alone, it would hide every later gap from that day.
        $this->row('2025-06-05', 5000, 105000);
        $this->post('/todo/dismiss', ['key' => $gap['key'], 'reason' => 'not_problem'])->assertSessionHasErrors('key');
        $this->post('/todo/dismiss', ['key' => 'stale:'.$bank->id, 'days' => 7])->assertSessionHasErrors('key');
        $this->assertSame(0, TodoDismissal::query()->count());
    }

    // ---------------------------------------------------------------- Telegram

    public function test_telegram_buttons_follow_what_is_hidden_now_not_the_last_recorded_state(): void
    {
        $this->telegram();
        // Monday 07:00: snoozed for a week on the web. The 05:07 run a week later still records it as snoozed.
        $this->at('2025-06-30 07:00:00');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        Dismissals::dismiss('no_po:'.$job->id, 7, Dismissals::note('later', null, true));
        $this->at('2025-07-07 05:07:00');
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        $this->assertSame('snoozed', $finding->currentState());

        // 08:47: the snooze ended at 07:00, so it's listed with its buttons, and pressing Snooze a week snoozes it.
        $this->at('2025-07-07 08:47:00');
        Telegram::sendTodo('42');
        $this->assertNotSame('Already dealt with.', $this->press('t|fnd_snooze|'.$finding->id.':later'));
        $this->assertSame('snoozed', $finding->fresh()->state);
        $this->assertSame('2025-07-14 00:00:00', TodoDismissal::query()->where('key', 'no_po:'.$job->id)->value('until')?->format('Y-m-d H:i:s'),
            'A week’s snooze from Telegram is back before the next Monday digest');

        // A gap you marked not relevant that changes the same day is back on the list: Snooze a week takes it, as it
        // is now (its new dates and amount), before the next run has recorded it as back.
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        $gap = Finding::query()->where('key', 'gap:'.$bank->id.':2025-06-02')->sole();
        Dismissals::dismiss($gap->key, null, 'Not a problem');
        $this->row('2025-06-08', 3000, 105000);
        $this->assertContains($gap->key, array_column(TodoList::build(100)['items'], 'key'));
        $this->assertSame('dismissed', $gap->fresh()->getAttributes()['state'], 'Not recorded as back yet');
        $this->assertNotSame('Already dealt with.', $this->press('t|fnd_snooze|'.$gap->id.':later', 9));
        $this->assertNotContains($gap->key, array_column(TodoList::build(100)['items'], 'key'));
        $this->assertSame(1, TodoDismissal::query()->where('key', 'like', 'gap:'.$bank->id.':2025-06-02:%')->where('until', '>', now())->count());

        // Still hidden by a not-relevant: refused, whatever the recorded state says.
        $finding->forceFill(['state' => 'open'])->save();
        TodoDismissal::query()->where('key', 'no_po:'.$job->id)->update(['until' => null]);
        $this->assertSame('Already dealt with.', $this->press('t|fnd_dismiss|'.$finding->id, 10));
    }

    public function test_finding_buttons_on_the_web_are_audited_from_the_web(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        $finding = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        $channels = [];
        AuditEvent::creating(function () use (&$channels) {
            $channels[] = AuditContext::current()['channel'] ?? null;
        });
        $this->actingAs(User::factory()->create())->post('/todo/fnd_snooze/'.$finding->id.':later')->assertSessionHas('success');
        $this->assertSame(['web'], $channels);

        $this->telegram();
        $channels = [];
        $finding->forceFill(['state' => 'open'])->save();
        TodoDismissal::query()->delete();
        $this->press('t|fnd_dismiss|'.$finding->id.':sorted');
        $this->assertSame(['telegram'], $channels);
    }

    // ---------------------------------------------------------------- detectors and refs

    public function test_detectors_never_write_with_the_database_cache(): void
    {
        Cache::setDefaultDriver('database');
        Setting::put('home_postcode', 'SW1A 1AA');
        WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done', 'postcode' => 'RG1 1AA']);
        $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        TodoList::build();
        // The next day, what was kept is out of date.
        $this->at('2025-07-02');
        $writes = [];
        DB::listen(function (QueryExecuted $q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        foreach (app(Registry::class)->all() as $detector) {
            iterator_to_array((function () use ($detector) {
                yield from $detector->detect(new CycleContext);
            })(), false);
        }
        $this->assertSame([], $writes);
    }

    public function test_refs_to_records_that_are_gone_read_in_plain_words_one_query_per_kind(): void
    {
        $labels = collect(RefResolver::resolveMany(['inv:999', 'acct:999', 'cand:999', 'sbal:9', 'jday:9', 'fnd:9', 'check:document:9', 'exp:9']))->pluck('label', 'ref');
        $this->assertSame('An invoice that’s no longer there', $labels['inv:999']);
        $this->assertSame('A possible job that’s no longer there', $labels['cand:999']);
        foreach ($labels as $ref => $label) {
            $this->assertDoesNotMatchRegularExpression('/\b(Inv|Acct|Cand|Sbal|Jday|Fnd|Check|Exp)\b/', $label, $ref);
        }

        foreach ([1, 2, 3] as $id) {
            Verification::query()->create(['subject_type' => 'document', 'subject_id' => $id, 'status' => 'verified', 'method' => 'source_text', 'checks' => [], 'summary' => 'Matches', 'checked_at' => now()]);
        }
        Setting::put('chk_one', 1);
        Setting::put('chk_two', 2);
        $queries = [];
        DB::listen(function (QueryExecuted $q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $out = RefResolver::resolveMany(['check:document:1', 'check:document:2', 'check:document:3', 'check:transaction:4', 'set:chk_one', 'set:chk_two', 'set:chk_three']);
        $this->assertTrue($out['check:document:2']['exists']);
        $this->assertFalse($out['check:transaction:4']['exists']);
        $this->assertSame([true, true, false], [$out['set:chk_one']['exists'], $out['set:chk_two']['exists'], $out['set:chk_three']['exists']]);
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, '"verifications"') || str_contains($sql, '`verifications`')));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, '"settings"') || str_contains($sql, '`settings`')));
    }

    public function test_a_filed_year_waiting_on_payments_has_no_money_either_way(): void
    {
        $this->assertSame('none', TodoGroupsDetector::classify('drift:2024-25:abc', null)['direction']);
        $this->assertSame('owed_by_you', TodoGroupsDetector::classify('drift:2024-25:abc', -64000)['direction']);
    }

    // ---------------------------------------------------------------- rolling back and forward

    public function test_rolling_back_to_the_release_before_and_forward_again_falsely_resolves_nothing(): void
    {
        // Found by this release: a gap, an account with nothing imported lately, a job with no PO.
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        $amex = $this->bank('Amex');
        $batch = ImportBatch::query()->create(['account_id' => $amex->id, 'original_filename' => 'amex.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'status' => 'committed']);
        $batch->forceFill(['created_at' => '2025-03-28 09:00:00'])->save();
        foreach ([['2025-03-20', -700, 5000], ['2025-03-28', -300, 4700]] as [$date, $pence, $balance]) {
            BankTransaction::query()->create(['account_id' => $amex->id, 'import_batch_id' => $batch->id, 'posted_on' => $date, 'tax_year' => '2024-25', 'description' => 'X', 'merchant_key' => 'X',
                'amount_pence' => $pence, 'balance_after_pence' => $balance, 'raw' => [], 'fingerprint' => md5('amex'.$date), 'occurrence' => 1]);
        }
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        $gap = Finding::query()->where('key', 'gap:'.$bank->id.':2025-06-02')->sole();
        $stale = Finding::query()->where('key', 'stale:'.$amex->id)->sole();
        $noPo = Finding::query()->where('key', 'no_po:'.$job->id)->sole();

        // The release before, for a day: it doesn't know these keys or states. It calls the gap and the account dealt
        // with (leaving their state), records them again under its own keys, and closes the job once its PO is added.
        $this->at('2025-07-02');
        $job->update(['po_number' => 'PO-1']);
        Finding::query()->whereIn('id', [$gap->id, $stale->id, $noPo->id])->update(['resolved_at' => now(), 'resolution' => 'resolved']);
        foreach (['gap:'.$bank->id.':2025-06-02:2025-06-09:5000' => 5000, 'stale:'.$amex->id.':2025-03-28' => null] as $key => $amount) {
            DB::table('findings')->insert(['key' => $key, 'group' => 'reconcile', 'title' => $key, 'amount_pence' => $amount, 'peak_amount_pence' => $amount,
                'first_seen_at' => now(), 'last_seen_at' => now(), 'reopened' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        // This release again.
        $this->at('2025-07-03');
        FindingsRegistry::sync();
        $this->assertSame(['open', null], [$gap->fresh()->state, $gap->fresh()->resolved_at]);
        $this->assertSame(['open', null], [$stale->fresh()->state, $stale->fresh()->resolved_at]);
        $this->assertSame(['resolved', 'resolved'], [$noPo->fresh()->state, $noPo->fresh()->currentState()], 'Its state follows how it ended');
        $copies = Finding::query()->whereIn('key', ['gap:'.$bank->id.':2025-06-02:2025-06-09:5000', 'stale:'.$amex->id.':2025-03-28'])->get()->keyBy('key');
        $this->assertSame(['superseded', 'fnd:'.$gap->id], [$copies['gap:'.$bank->id.':2025-06-02:2025-06-09:5000']->resolution, $copies['gap:'.$bank->id.':2025-06-02:2025-06-09:5000']->resolution_ref]);
        $this->assertSame(['superseded', 'fnd:'.$stale->id], [$copies['stale:'.$amex->id.':2025-03-28']->resolution, $copies['stale:'.$amex->id.':2025-03-28']->resolution_ref]);
        $this->assertSame(1, FindingsRegistry::summary('2025-01-01')['resolved'], 'Only the job dealt with');
        $this->assertContains('resolved', $this->events($noPo));
    }

    // ---------------------------------------------------------------- the migration

    public function test_the_migration_finishes_a_run_stopped_part_way(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Changes the schema inside the test’s transaction: SQLite only (MariaDB was checked by hand).');
        }
        $migration = require database_path('migrations/2026_12_10_000001_findings_lifecycle.php');
        $migration->down();
        // Stopped after its first few columns were added.
        Schema::table('findings', function ($table) {
            $table->string('code', 40)->nullable();
            $table->unsignedSmallInteger('detector_version')->nullable();
        });
        $migration->up();
        foreach (['code', 'detector_version', 'subject_ref', 'scope_ref', 'evidence', 'direction', 'grade', 'severity', 'due_on', 'state', 'note', 'resolution_ref', 'last_run_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('findings', $column), $column);
        }
        foreach (['code', 'subject_ref', 'scope_ref'] as $column) {
            $this->assertTrue(Schema::hasIndex('findings', 'findings_'.$column.'_index'), $column.' index');
        }
        $this->assertTrue(Schema::hasTable('finding_events'));
        $migration->up(); // again: nothing left to do
        $this->assertTrue(Schema::hasColumn('findings', 'last_run_id'));
    }
}
