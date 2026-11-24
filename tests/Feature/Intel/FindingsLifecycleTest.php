<?php

namespace Tests\Feature\Intel;

use App\Domain\Intel\Contracts\Detector;
use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\RefResolver;
use App\Domain\Ledger\AllocationWriter;
use App\Domain\Ledger\DecisionService;
use App\Domain\Tax\PenaltyEstimator;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Document;
use App\Models\Finding;
use App\Models\FindingEvent;
use App\Models\ImportBatch;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\TravelItem;
use App\Models\WorkJob;
use App\Support\UkTaxYear;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * M3, findings that remember: every change to a finding is an event; a finding is only called dealt with when what
 * finds it looked at everything; gaps keep one finding as they're filled; money is kept by direction and currency;
 * detectors only read, and every ref they cite resolves.
 */
class FindingsLifecycleTest extends TestCase
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

    private function at(string $date): void
    {
        Carbon::setTestNow($date.' 09:00:00');
        CarbonImmutable::setTestNow($date.' 09:00:00');
    }

    private function bank(): Account
    {
        return Account::query()->firstOrCreate(['name' => 'Starling'], ['kind' => 'bank', 'is_active' => true]);
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

    /** A detector whose findings and coverage the test sets. */
    private function fake(): Detector
    {
        return new class implements Detector
        {
            /** @var list<FindingDraft> */
            public array $drafts = [];

            public Coverage $next = Coverage::Complete;

            public bool $throws = false;

            private Coverage $coverage = Coverage::Failed;

            public function code(): string
            {
                return 'test.fake';
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
                $this->coverage = $this->next;

                return $this->drafts;
            }

            public function coverage(): Coverage
            {
                return $this->coverage;
            }
        };
    }

    private function draft(int $amount = -2000): FindingDraft
    {
        return new FindingDraft(key: 'fake:1', code: 'test.fake', group: 'duplicates', priority: 2, title: 'Something found', subjectRef: 'acct:1', amountPence: $amount,
            direction: 'at_risk', grade: 'likely');
    }

    public function test_a_capped_detector_leaves_what_it_missed_unseen_never_resolved(): void
    {
        $fake = $this->fake();
        $this->app->instance(Registry::class, new Registry([$fake]));
        $fake->drafts = [$this->draft()];
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', 'fake:1')->sole();
        $this->assertSame(['open', 'test.fake', 'at_risk', 'likely'], [$f->state, $f->code, $f->direction, $f->grade]);

        // A limit left it out: not looked for in full, so not dealt with.
        $fake->drafts = [];
        $fake->next = Coverage::Capped;
        $this->at('2025-07-02');
        $r = FindingsRegistry::sync();
        $f->refresh();
        $this->assertSame(['unseen', null, null], [$f->state, $f->resolved_at, $f->resolution]);
        $this->assertSame(1, $r['unseen']);
        $this->assertSame(1, FindingsRegistry::summary('2025-01-01')['unseen']);
        FindingsRegistry::sync();
        $this->assertSame(['detected', 'unseen'], $this->events($f), 'Unseen is noted once');

        // Seen again: open, not "back again".
        $fake->drafts = [$this->draft()];
        $fake->next = Coverage::Complete;
        FindingsRegistry::sync();
        $this->assertSame(['open', 0], [$f->fresh()->state, $f->fresh()->reopened]);

        // Gone, with everything looked at: dealt with.
        $fake->drafts = [];
        $this->at('2025-07-03');
        FindingsRegistry::sync();
        $this->assertSame(['resolved', 'resolved', '2025-07-03'], [$f->fresh()->state, $f->fresh()->resolution, $f->fresh()->resolved_at->format('Y-m-d')]);
        $this->assertSame(['detected', 'unseen', 'resolved'], $this->events($f));
    }

    public function test_a_detector_that_fails_keeps_its_findings_open_with_detector_failed(): void
    {
        $fake = $this->fake();
        $this->app->instance(Registry::class, new Registry([$fake]));
        $fake->drafts = [$this->draft()];
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', 'fake:1')->sole();

        $fake->throws = true;
        FindingsRegistry::sync();
        FindingsRegistry::sync();
        $this->assertSame(['open', null], [$f->fresh()->state, $f->fresh()->resolved_at]);
        $this->assertSame(['detected', 'detector_failed'], $this->events($f), 'Once while it keeps failing');
        $this->assertContains('test.fake', FindingsRegistry::sync()['failed']);
    }

    public function test_a_penalty_estimate_that_throws_gives_detector_failed_and_the_finding_stays_open(): void
    {
        $this->at('2026-11-24');
        $tx = $this->row('2024-09-10', 3_000_000, null, 'Acme Events');
        app(AllocationWriter::class)->decide($tx, AllocationWriter::lines(3_000_000, 'business_income', Category::query()->where('code', 'turnover')->value('id')));
        TaxYear::track('2024-25')->update(['status' => 'filed', 'filed_on' => '2026-01-20']);
        FindingsRegistry::sync();
        $penalty = Finding::query()->where('key', 'penalty:2024-25')->sole();
        $this->assertSame(['tax.penalty', 'year:2024-25', 'year:2024-25', 'owed_by_you'], [$penalty->code, $penalty->subject_ref, $penalty->scope_ref, $penalty->direction]);

        $rules = new \ReflectionProperty(PenaltyEstimator::class, 'rules');
        $rules->setValue(null, []);
        try {
            $r = FindingsRegistry::sync();
            $this->assertContains('penalty:', $r['failed']);
            $this->assertSame(['open', null], [$penalty->fresh()->state, $penalty->fresh()->resolved_at]);
            $this->assertSame('detector_failed', FindingEvent::query()->where('finding_id', $penalty->id)->orderByDesc('id')->value('event'));
        } finally {
            $rules->setValue(null, null);
        }
    }

    public function test_an_expired_snooze_produces_snooze_ended(): void
    {
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        TodoDismissal::query()->create(['key' => $f->key, 'until' => now()->addDays(7), 'note' => 'Waiting to hear back']);
        FindingsRegistry::sync();
        $this->assertSame(['snoozed', 'Waiting to hear back'], [$f->fresh()->state, $f->fresh()->note]);

        $this->at('2025-07-09');
        FindingsRegistry::sync();
        $this->assertSame(['open', null, 1], [$f->fresh()->state, $f->fresh()->resolved_at, $f->fresh()->reopened]);
        $this->assertSame(['detected', 'snoozed', 'snooze_ended'], $this->events($f));
        $snoozed = FindingEvent::query()->where('finding_id', $f->id)->where('event', 'snoozed')->sole();
        $this->assertSame(['you', 'Waiting to hear back'], [$snoozed->actor, $snoozed->note]);
    }

    public function test_a_partly_filled_gap_is_a_changed_event_on_the_same_finding(): void
    {
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        $gap = Finding::query()->where('key', 'gap:'.$bank->id.':2025-06-02')->sole();
        $this->assertSame([5000, 'acct.gap', 'acct:'.$bank->id, 'unrecorded'], [$gap->amount_pence, $gap->code, $gap->scope_ref, $gap->direction]);

        // £30 of the £50 imported, dated near the end: the gap keeps its first day, with £20 left.
        $this->at('2025-07-02');
        $this->row('2025-06-08', 3000, 105000);
        FindingsRegistry::sync();
        $this->assertSame(1, Finding::query()->where('key', 'like', 'gap:%')->count());
        $this->assertSame([2000, 5000, null], [$gap->fresh()->amount_pence, $gap->fresh()->peak_amount_pence, $gap->fresh()->resolved_at]);
        $history = FindingEvent::query()->where('finding_id', $gap->id)->orderBy('id')->get();
        $this->assertSame(['detected', 'changed'], $history->pluck('event')->all());
        $this->assertSame([5000, 2000], $history->pluck('amount_pence')->all(), 'The money it involved, each time');
        $this->assertSame(['Days 2025-06-02 to 2025-06-09', 'Days 2025-06-02 to 2025-06-07'], $history->pluck('note')->all());
    }

    public function test_a_gap_whose_first_day_moves_is_rekeyed_and_keeps_when_it_was_first_seen(): void
    {
        $bank = $this->bank();
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        FindingsRegistry::sync();
        $gap = Finding::query()->where('key', 'gap:'.$bank->id.':2025-06-02')->sole();

        // £30 imported, dated near the start: what's left starts on 4 June.
        $this->at('2025-07-05');
        $this->row('2025-06-03', 3000, 103000);
        FindingsRegistry::sync();
        $gap->refresh();
        $this->assertSame(['gap:'.$bank->id.':2025-06-04', '2025-07-01', 2000], [$gap->key, $gap->first_seen_at->format('Y-m-d'), $gap->amount_pence]);
        $this->assertSame(['detected', 'rekeyed', 'changed'], $this->events($gap));
        $this->assertSame('Was gap:'.$bank->id.':2025-06-02', FindingEvent::query()->where('finding_id', $gap->id)->where('event', 'rekeyed')->value('note'));
        $this->assertSame(1, Finding::query()->count());
    }

    public function test_a_duplicate_counts_only_the_extra_charges(): void
    {
        foreach (range(1, 3) as $i) {
            $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        }
        $item = collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'dupe:'));
        $this->assertSame(-5000, $item['amount'], 'Three alike: two extra charges of £25');
        $this->assertSame(['tx.duplicate', 'at_risk'], [$item['code'], $item['direction']]);
        FindingsRegistry::sync();
        $this->assertSame(-5000, Finding::query()->where('key', $item['key'])->value('amount_pence'));
    }

    public function test_the_ranker_orders_items_within_each_priority_by_what_is_at_stake(): void
    {
        $small = $this->row('2025-06-10', -2500, null, 'PRET A MANGER');
        $this->row('2025-06-10', -2500, null, 'PRET A MANGER');
        $big = $this->row('2025-06-12', -30000, null, 'HOTEL CHOCOLAT');
        $this->row('2025-06-12', -30000, null, 'HOTEL CHOCOLAT');
        $keys = collect(TodoList::build()['items'])->where('group', 'duplicates')->pluck('key')->values()->all();
        $this->assertSame(['dupe:'.$big->id, 'dupe:'.$small->id], $keys, 'Most at stake first');
        $items = collect(TodoList::build()['items'])->keyBy('key');
        $this->assertGreaterThan($items['dupe:'.$small->id]['score'], $items['dupe:'.$big->id]['score']);
        $this->assertSame(['important', 'info'], [$items['dupe:'.$big->id]['severity'], $items['dupe:'.$small->id]['severity']]);
    }

    public function test_direction_grade_severity_and_scope_are_recorded(): void
    {
        // A job still to come: a finished job's recharge is part of its close-out instead (M4, JobCloseoutTest).
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-07-10', 'ends_on' => '2025-07-10', 'status' => 'booked']);
        $stay = TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-07-09', 'ends_on' => '2025-07-10',
            'amount_minor' => 8900, 'currency' => 'EUR', 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'decision' => 'recharge', 'decided_at' => now()]);
        FindingsRegistry::sync();
        $f = Finding::query()->where('key', 'recharge:'.$stay->id)->sole();
        // €89 at stake, certain: £50 or more (as compared) is important.
        $this->assertSame(['travel.recharge', 'travel:'.$stay->id, 'job:'.$job->id, 'owed_to_you', 'certain', 'important', 'EUR', 1],
            [$f->code, $f->subject_ref, $f->scope_ref, $f->direction, $f->grade, $f->severity, $f->currency, $f->detector_version]);
        $this->assertSame(['travel:'.$stay->id], $f->evidence);
        $noPo = Finding::query()->where('key', 'no_po:'.$job->id)->sole();
        $this->assertSame(['job.no_po', 'job:'.$job->id, 'none'], [$noPo->code, $noPo->scope_ref, $noPo->direction]);
    }

    public function test_totals_stay_separate_by_direction_and_currency(): void
    {
        $make = fn (string $key, int $amount, ?string $currency, string $direction) => Finding::query()->create(['key' => $key, 'group' => 'revenue', 'title' => $key,
            'amount_pence' => $amount, 'peak_amount_pence' => $amount, 'currency' => $currency, 'direction' => $direction, 'state' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()]);
        $make('a', 10000, null, 'owed_to_you');
        $make('b', 5000, 'EUR', 'owed_to_you');
        $make('c', -3000, null, 'owed_by_you');
        $make('d', -700, null, 'at_risk');
        $totals = collect(FindingsRegistry::summary('2025-01-01')['by_direction'])->keyBy('direction');
        $this->assertSame(['EUR' => 5000, 'GBP' => 10000], $totals['owed_to_you']['open']);
        $this->assertSame(['GBP' => 3000], $totals['owed_by_you']['open']);
        $this->assertSame(['GBP' => 700], $totals['at_risk']['open']);
        $this->assertSame('You may be owed', $totals['owed_to_you']['label']);
    }

    /** A bit of everything the list finds, for the read-only and refs checks. */
    private function everything(): void
    {
        Setting::put('home_postcode', 'SW1A 1AA');
        $job = WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done', 'postcode' => 'RG1 1AA']);
        TravelItem::query()->create(['kind' => 'hotel', 'provider' => 'Premier Inn', 'status' => 'booked', 'starts_on' => '2025-05-31', 'ends_on' => '2025-06-01',
            'amount_minor' => 8900, 'source' => 'manual', 'evidence' => [], 'work_job_id' => $job->id, 'decision' => 'recharge', 'decided_at' => now()]);
        $hmrc = $this->row('2025-06-05', -25000, null, 'HMRC SELF ASSESSMENT');
        app(DecisionService::class)->apply(['kind' => 'personal'], collect([$hmrc]));
        $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        $this->row('2025-06-12', -2500, null, 'PRET A MANGER');
        $this->row('2025-06-01', -5000, 100000);
        $this->row('2025-06-10', -1000, 104000);
        Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', 'held'), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'held']);
        JobCandidate::query()->create(['title' => 'Maybe a job', 'starts_on' => '2025-08-01', 'ends_on' => '2025-08-01', 'sources' => [], 'reasons' => ['why' => []]]);
        ImportBatch::query()->create(['account_id' => $this->bank()->id, 'original_filename' => 'held.csv', 'file_sha256' => str_repeat('b', 64), 'stored_path' => 'x', 'status' => 'quarantined']);
    }

    public function test_detectors_never_write(): void
    {
        $this->everything();
        $writes = [];
        DB::listen(function (QueryExecuted $q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $found = 0;
        foreach (app(Registry::class)->all() as $detector) {
            $found += count(iterator_to_array((function () use ($detector) {
                yield from $detector->detect(new CycleContext);
            })(), false));
            $this->assertNotSame(Coverage::Failed, $detector->coverage(), $detector->code().' ran');
        }
        $this->assertGreaterThan(5, $found);
        $this->assertSame([], $writes);
    }

    public function test_every_evidence_ref_resolves(): void
    {
        $this->everything();
        FindingsRegistry::sync();
        // One dealt with, by your decision: what closed it is cited, and resolves too.
        $hmrc = BankTransaction::query()->where('description', 'HMRC SELF ASSESSMENT')->sole();
        $this->at('2025-07-02');
        app(DecisionService::class)->apply(['kind' => 'tax_payment'], collect([$hmrc]));
        FindingsRegistry::sync();
        $closed = Finding::query()->where('key', 'hmrc:'.$hmrc->id)->sole();
        $this->assertSame('resolved', $closed->state);
        $this->assertStringStartsWith('audit:', (string) $closed->resolution_ref);

        $refs = Finding::query()->get()->flatMap(fn (Finding $f) => [$f->subject_ref, $f->scope_ref, $f->resolution_ref, ...($f->evidence ?? [])])
            ->merge(FindingEvent::query()->pluck('cause_ref'))->filter()->unique()->values();
        $this->assertGreaterThan(8, $refs->count());
        $resolved = RefResolver::resolveMany($refs->all());
        foreach ($refs as $ref) {
            $this->assertTrue($resolved[$ref]['exists'], $ref.' resolves');
            $this->assertSame('ok', $resolved[$ref]['state'], $ref.' is as it was');
        }
    }

    public function test_a_shadow_sync_keeps_nothing(): void
    {
        WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        $r = FindingsRegistry::sync(new CycleContext(shadow: true));
        $this->assertGreaterThan(0, $r['new']);
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, FindingEvent::query()->count());
    }

    public function test_finding_events_are_append_only(): void
    {
        WorkJob::query()->create(['title' => 'Arena show', 'starts_on' => '2025-06-01', 'ends_on' => '2025-06-01', 'status' => 'done']);
        FindingsRegistry::sync();
        $event = FindingEvent::query()->firstOrFail();
        $this->assertThrows(fn () => $event->update(['note' => 'changed']), LogicException::class);
        $this->assertThrows(fn () => $event->delete(), LogicException::class);
    }
}
