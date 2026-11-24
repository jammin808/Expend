<?php

namespace Tests\Feature\Intel;

use App\Domain\Intel\Coverage;
use App\Domain\Intel\Cycle\CycleContext;
use App\Domain\Intel\Detectors\CycleStepFailing;
use App\Domain\Intel\Detectors\HmrcUnallocated;
use App\Domain\Intel\Detectors\Registry;
use App\Domain\Intel\Detectors\TodoGroupsDetector;
use App\Domain\Intel\Detectors\TravelUnclaimed;
use App\Domain\Intel\Detectors\YearFiledDrift;
use App\Domain\Intel\Grades;
use App\Domain\Intel\Ranker;
use App\Domain\Intel\Ref;
use App\Domain\Mileage\JobTrips;
use App\Models\Setting;
use App\Models\TravelItem;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** The shared vocabulary findings use: refs, grades, the ranking, the run context and the detector registry. */
class FindingsVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_refs_are_made_parsed_and_checked(): void
    {
        $this->assertSame('doc:55#f=rate@ab12cd34', Ref::make('doc', 55, 'f=rate', 'AB12CD34EF'));
        $this->assertSame(['kind' => 'check', 'id' => 'document:12', 'locator' => null, 'pin' => null], Ref::parse('check:document:12'));
        $this->assertSame(['kind' => 'fx', 'id' => 'EUR:2025-04:hmrc_monthly', 'locator' => null, 'pin' => null], Ref::parse('fx:EUR:2025-04:hmrc_monthly'));
        $this->assertTrue(Ref::valid('tx:381#row41'));
        $this->assertFalse(Ref::valid('nope:1'), 'Unknown kind');
        $this->assertFalse(Ref::valid('tx:'), 'No id');
        $this->assertFalse(Ref::valid('tx:'.str_repeat('1', 200)), 'Longer than 191');
        $this->assertFalse(Ref::valid('set:telegram_bot_token'), 'A secret is never cited');
        $this->assertTrue(Ref::valid('set:home_postcode'));
        $this->assertNull(Ref::maybe('tx', null));
        $this->assertSame(['tx:1', 'job:2'], Ref::list(['tx:1', null, 'bad', 'job:2', 'tx:1']));
        $this->assertCount(Ref::LIST_MAX, Ref::list(array_map(fn ($i) => 'tx:'.$i, range(1, 30))));
        $this->expectException(InvalidArgumentException::class);
        Ref::make('tx', 'has space');
    }

    public function test_grades_take_the_weakest_and_weigh_as_documented(): void
    {
        $this->assertSame('possible', Grades::weakest(['certain', 'possible', 'strong']));
        $this->assertSame('weak', Grades::weakest(['weak', 'certain']));
        $this->assertNull(Grades::weakest([null]));
        $this->assertSame([1.0, 0.9, 0.7, 0.4, 0.1, 0.5], array_values(Grades::WEIGHTS));
        $this->assertSame(0.5, Grades::weight(null));
        $this->assertSame(['certain', 'Your bank line and how it’s recorded'], Grades::forCode('tax.hmrc_unallocated'));
        $this->assertSame([null, null], Grades::forCode('health.calendar'));
        foreach (Grades::FOR_CODE as [$grade]) {
            $this->assertTrue(Grades::valid($grade));
        }
    }

    public function test_the_ranker_scores_and_says_how_serious(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        CarbonImmutable::setTestNow('2026-01-01 09:00:00');
        $this->assertSame(0.0, Ranker::score(-5000, 'none', 'certain', null), 'No direction: nothing at stake');
        $this->assertSame(0.0, Ranker::score(null, 'owed_to_you', 'certain', null));
        $this->assertSame(3500.0, Ranker::score(-5000, 'at_risk', 'likely', null));
        $this->assertSame(15000.0, Ranker::score(5000, 'owed_to_you', 'certain', '2026-01-05'), 'Due within 7 days: × 3');
        $this->assertSame(10000.0, Ranker::score(5000, 'owed_to_you', 'certain', '2026-01-20'), 'Within 30: × 2');
        $this->assertSame(7500.0, Ranker::score(5000, 'owed_to_you', 'certain', '2026-02-20'), 'Within 60: × 1.5');
        $this->assertSame('critical', Ranker::severity(['code' => 'health.audit_chain']));
        $this->assertSame('critical', Ranker::severity(['code' => 'tax.deadline', 'due_on' => '2026-01-06']));
        $this->assertSame('critical', Ranker::severity(['code' => 'invoice.overdue', 'amount' => 60000, 'direction' => 'owed_to_you', 'grade' => 'certain']));
        $this->assertSame('important', Ranker::severity(['code' => 'invoice.overdue', 'amount' => 6000, 'direction' => 'owed_to_you', 'grade' => 'certain']));
        $this->assertSame('important', Ranker::severity(['code' => 'job.no_po', 'due_on' => '2026-02-10']));
        $this->assertSame('important', Ranker::severity(['code' => 'check.failed']));
        $this->assertSame('info', Ranker::severity(['code' => 'job.candidate']));

        $order = ['deadline' => 0, 'duplicates' => 1, 'payments' => 2];
        $items = array_map(fn ($i) => Ranker::annotate($i), [
            ['key' => 'p', 'group' => 'payments', 'priority' => 2, 'amount' => -90000],
            ['key' => 'small', 'group' => 'duplicates', 'priority' => 2, 'amount' => -2000, 'direction' => 'at_risk', 'grade' => 'likely', 'code' => 'tx.duplicate'],
            ['key' => 'big', 'group' => 'duplicates', 'priority' => 2, 'amount' => -20000, 'direction' => 'at_risk', 'grade' => 'likely', 'code' => 'tx.duplicate'],
            ['key' => 'first', 'group' => 'deadline', 'priority' => 1, 'code' => 'tax.deadline', 'due_on' => '2026-01-31'],
        ]);
        $this->assertSame(['first', 'big', 'small', 'p'], array_column(Ranker::sort($items, $order), 'key'), 'Priorities stay floors; within one, most at stake first');
    }

    public function test_the_run_context_has_a_deadline_and_defaults_for_everything(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        CarbonImmutable::setTestNow('2026-01-01 09:00:00');
        $none = new CycleContext;
        $this->assertSame([null, null, false, true], [$none->runId, $none->deadline, $none->shadow, $none->hasTime(9999)]);
        $ctx = new CycleContext(44, now()->addSeconds(30), true);
        $this->assertTrue($ctx->hasTime(10));
        $this->assertFalse($ctx->hasTime(40));
        $this->assertSame([44, true], [$ctx->runId, $ctx->shadow]);
    }

    public function test_the_registry_holds_the_todo_groups_m1s_three_health_and_m4s(): void
    {
        $registry = app(Registry::class);
        $this->assertSame(['todo', 'tax.hmrc_unallocated', 'travel.unclaimed', 'year.filed_drift', 'health', 'job.closeout', 'travel.double_claim', 'income.unlinked',
            'receipt.unmatched', 'cost.no_receipt', 'dupe.cross_source', 'rule.drift', 'tax.saving', 'acct.unchecked', 'check.unverifiable', 'year.locked_drift',
            'health.cycle_step'],
            array_map(fn ($d) => $d->code(), $registry->all()));
        $this->assertInstanceOf(CycleStepFailing::class, $registry->forCode('health.cycle_step'), 'M5’s, registered by the integrator (contract 3)');
        $this->assertInstanceOf(TodoGroupsDetector::class, $registry->forCode('acct.gap'));
        $this->assertInstanceOf(HmrcUnallocated::class, $registry->forCode('tax.hmrc_unallocated'));
        $this->assertInstanceOf(TravelUnclaimed::class, $registry->forCode('travel.unclaimed'));
        $this->assertInstanceOf(YearFiledDrift::class, $registry->forCode('year.filed_drift'));
        $this->assertNull($registry->forCode('nothing.here'));
        $this->assertSame(Coverage::Failed, (new HmrcUnallocated)->coverage(), 'Not run yet');
        $this->assertSame(Coverage::Capped, Coverage::Complete->and(Coverage::Capped));
        $this->assertSame(Coverage::Failed, Coverage::Capped->and(Coverage::Failed));
    }

    public function test_the_section_4_4_table_reads_each_key(): void
    {
        $c = fn (string $key, ?int $amount = null) => array_intersect_key(TodoGroupsDetector::classify($key, $amount) ?? [], ['code' => 1, 'subject_ref' => 1, 'direction' => 1]);
        $this->assertSame(['code' => 'health.gmail', 'subject_ref' => 'set:gmail_last_result', 'direction' => 'none'], $c('gmail'));
        $this->assertSame(['code' => 'import.held', 'subject_ref' => 'batch:7', 'direction' => 'unrecorded'], $c('import:7'));
        $this->assertSame(['code' => 'invoice.overdue', 'subject_ref' => 'inv:3', 'direction' => 'owed_to_you'], $c('invoice_overdue:3'));
        $this->assertSame(['code' => 'travel.recharge', 'subject_ref' => 'travel:4', 'direction' => 'owed_to_you'], $c('recharge:4'));
        $this->assertSame(['code' => 'check.failed', 'subject_ref' => 'doc:12', 'direction' => 'none'], $c('check:document:12'));
        $this->assertSame(['code' => 'check.failed', 'subject_ref' => 'check:calendar_link:5', 'direction' => 'none'], $c('check:calendar_link:5'));
        $this->assertSame(['code' => 'tax.deadline', 'subject_ref' => 'year:2024-25', 'direction' => 'none'], $c('deadline:2024-25:amend'));
        $this->assertSame(['code' => 'tax.penalty', 'subject_ref' => 'year:2024-25', 'direction' => 'owed_by_you'], $c('penalty:2024-25'));
        $this->assertSame(['code' => 'job.uninvoiced', 'subject_ref' => 'job:9', 'direction' => 'owed_to_you'], $c('invoice:9'));
        $this->assertSame(['code' => 'invoice.unsent', 'subject_ref' => 'inv:9', 'direction' => 'owed_to_you'], $c('unsent:9'));
        $this->assertSame(['code' => 'job.candidate', 'subject_ref' => 'cand:2', 'direction' => 'none'], $c('candidate:2'));
        $this->assertSame(['code' => 'tx.duplicate', 'subject_ref' => 'tx:8', 'direction' => 'at_risk'], $c('dupe:8'));
        $this->assertSame(['code' => 'job.no_po', 'subject_ref' => 'job:9', 'direction' => 'none'], $c('no_po:9'));
        $this->assertSame(['code' => 'acct.gap', 'subject_ref' => 'acct:3', 'direction' => 'unrecorded'], $c('gap:3:2025-06-02'));
        $this->assertSame(['code' => 'acct.gap', 'subject_ref' => 'acct:3', 'direction' => 'unrecorded'], $c('gap:3:2025-06-02:2025-06-09:5000'), 'The way it was kept before');
        $this->assertSame(['code' => 'acct.stale', 'subject_ref' => 'acct:3', 'direction' => 'none'], $c('stale:3'));
        $this->assertSame(['code' => 'travel.payment_missing', 'subject_ref' => 'travel:6', 'direction' => 'unrecorded'], $c('missing:6'));
        $this->assertSame(['code' => 'doc.held', 'subject_ref' => 'doc:5', 'direction' => 'none'], $c('doc:5'));
        $this->assertSame(['code' => 'tax.hmrc_unallocated', 'subject_ref' => 'tx:1', 'direction' => 'none'], $c('hmrc:1'));
        $this->assertSame(['code' => 'travel.unclaimed', 'subject_ref' => 'year:2026-27', 'direction' => 'claimable'], $c('mileage:2026-27:abc'));
        $this->assertSame(['code' => 'year.filed_drift', 'subject_ref' => 'year:2024-25', 'direction' => 'owed_by_you'], $c('drift:2024-25:abc', -64000));
        $this->assertSame(['code' => 'year.filed_drift', 'subject_ref' => 'year:2024-25', 'direction' => 'claimable'], $c('drift:2024-25:abc', 64000));
        $this->assertNull(TodoGroupsDetector::classify('tx:1'), 'A payment to decide is a proposal, not a finding');
        $this->assertNull(TodoGroupsDetector::classify('rule:PRET'));
    }

    public function test_jobtrips_and_the_mileage_detector_share_one_journey_rule(): void
    {
        Carbon::setTestNow('2026-11-24 10:00:00');
        CarbonImmutable::setTestNow('2026-11-24 10:00:00');
        Setting::put('home_postcode', 'SW1A 1AA');
        DB::table('places')->insert([['postcode' => 'SW1A 1AA', 'latitude' => 51.501, 'longitude' => -0.1416, 'created_at' => now(), 'updated_at' => now()],
            ['postcode' => 'LS1 1AA', 'latitude' => 53.7974, 'longitude' => -1.5438, 'created_at' => now(), 'updated_at' => now()]]);
        $train = WorkJob::query()->create(['title' => 'Leeds conference', 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-07', 'postcode' => 'LS1 1AA']);
        $other = WorkJob::query()->create(['title' => 'Leeds awards', 'starts_on' => '2026-10-14', 'ends_on' => '2026-10-14', 'postcode' => 'LS1 1AA']);
        $drove = WorkJob::query()->create(['title' => 'Leeds gala', 'starts_on' => '2026-10-21', 'ends_on' => '2026-10-21', 'postcode' => 'LS1 1AA']);
        // A train the day before one job, not linked to any job; one linked to another job; one you marked personal.
        $item = fn (array $a) => TravelItem::query()->create($a + ['kind' => 'rail', 'provider' => 'LNER', 'status' => 'booked', 'source' => 'manual', 'evidence' => []]);
        $item(['starts_on' => '2026-10-06', 'ends_on' => '2026-10-06']);
        $item(['starts_on' => '2026-10-13', 'ends_on' => '2026-10-13', 'work_job_id' => $train->id]);
        $item(['starts_on' => '2026-10-20', 'ends_on' => '2026-10-20', 'decision' => 'personal']);

        $jobs = WorkJob::query()->with('days')->get()->keyBy('id');
        $this->assertSame([$train->id => ['rail']], JobTrips::journeys($jobs));
        $offered = array_column(JobTrips::proposals('2026-27'), 'job_id');
        sort($offered);
        $this->assertSame([$other->id, $drove->id], $offered, 'The Mileage page leaves out the same job');
    }
}
