<?php

namespace Tests\Feature\Intel\Detectors;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoList;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\Document;
use App\Models\Finding;
use App\Models\MileageTrip;
use App\Models\Setting;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * New detectors start below the fold (info, off the list's total and Telegram); you raise one from the to-do page;
 * one is demoted when more than one in ten of its findings is marked not relevant as wrong. And the mileage item's
 * "Add the trips" (trips_add), bound to the jobs shown.
 */
class LevelsAndActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('job_finder_auto', false);
        $this->at('2026-06-10');
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function at(string $date): void
    {
        Carbon::setTestNow($date.' 10:00:00');
        CarbonImmutable::setTestNow($date.' 10:00:00');
    }

    /** A finished job with a PO document and no invoice: a close-out of its own (new, so below the fold). */
    private function jobWithPo(string $title, int $total = 50000): WorkJob
    {
        $client = Client::query()->firstOrCreate(['name' => 'Acme Events']);
        $job = WorkJob::query()->create(['title' => $title, 'client_id' => $client->id, 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'status' => 'done']);
        Document::query()->create(['source' => 'email', 'original_name' => 'po.pdf', 'sha256' => hash('sha256', $title), 'stored_path' => 'x', 'kind' => 'po', 'status' => 'extracted',
            'work_job_id' => $job->id, 'job_linked_by' => 'you', 'extraction' => ['po' => ['stated_total_minor' => $total, 'currency' => 'GBP', 'total_verified' => true, 'lines' => []]]]);

        return $job;
    }

    public function test_a_new_detector_starts_below_the_fold_and_you_raise_it(): void
    {
        Http::fake();
        $job = $this->jobWithPo('Arena load-in');
        $list = TodoList::build();
        $this->assertNotContains('closeout:'.$job->id, array_column($list['items'], 'key'));
        $this->assertSame(['closeout:'.$job->id], array_column($list['below'], 'key'));
        $total = $list['total'];

        // The page: the list, then what the new checks found once it's loaded, and the kinds that can be raised.
        $page = $this->get('/todo')->assertOk();
        $page->assertInertia(fn ($p) => $p->where('below.0.key', 'closeout:'.$job->id)->where('below.0.severity', 'info')
            ->where('detector_levels', fn ($l) => collect($l)->firstWhere('code', 'job.closeout')['level'] === 'info')
            ->missing('more_below'));
        $this->assertSame(['default' => ['more_below']], $page->viewData('page')['deferredProps']);
        $more = $this->get('/todo', ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $page->viewData('page')['version'], 'X-Inertia-Partial-Component' => 'todo',
            'X-Inertia-Partial-Data' => 'more_below'])->assertOk()->json('props.more_below');
        $this->assertSame(['items' => [], 'counts' => []], $more, 'Nothing else found by the new checks here');

        // Raised from the page: with the rest of the list, at its own severity, and audited as yours.
        $this->post('/todo/det_raise/job:closeout')->assertSessionHas('success');
        $this->assertSame('raised', DetectorLevels::levelOf('job.closeout'));
        $list = TodoList::build();
        $item = collect($list['items'])->firstWhere('key', 'closeout:'.$job->id);
        $this->assertNotNull($item);
        $this->assertSame([false, 'important'], [$item['below_fold'], $item['severity']], '£500 owed, strong: important');
        $this->assertSame($total + 1, $list['total']);
        $event = AuditEvent::query()->where('action', 'detector.raised')->sole();
        $this->assertSame(['you', ['code' => 'job.closeout', 'level' => 'raised']], [$event->actor, $event->after]);

        // And lowered again.
        $this->post('/todo/det_lower/job:closeout')->assertSessionHas('success');
        $this->assertTrue(DetectorLevels::belowFold('job.closeout'));
        $this->post('/todo/det_raise/todo:nothing')->assertSessionHas('error');
    }

    public function test_more_than_one_in_ten_dismissed_as_wrong_demotes_a_detector(): void
    {
        DetectorLevels::raise('job.closeout');
        $jobs = collect(range(1, 10))->map(fn ($i) => $this->jobWithPo('Job '.$i, 10000 * $i));
        FindingsRegistry::sync();
        $this->assertSame(10, Finding::query()->where('code', 'job.closeout')->count());
        $this->assertSame('important', Finding::query()->where('key', 'closeout:'.$jobs[4]->id)->value('severity'), '£500, strong: important once raised');
        Carbon::setTestNow('2026-06-10 11:00:00');
        CarbonImmutable::setTestNow('2026-06-10 11:00:00');

        // One in ten wrong: still raised. "Already sorted" isn't a false positive.
        $this->post('/todo/dismiss', ['key' => 'closeout:'.$jobs[0]->id, 'reason' => 'not_problem'])->assertSessionHas('success');
        $this->post('/todo/dismiss', ['key' => 'closeout:'.$jobs[1]->id, 'reason' => 'sorted'])->assertSessionHas('success');
        FindingsRegistry::sync();
        $this->assertSame('raised', DetectorLevels::levelOf('job.closeout'));
        $since = now()->toImmutable();
        $this->at('2026-06-11');

        // Two in ten: demoted by the next findings run, on its own, kept, audited as Expend's, and said so.
        $this->post('/todo/dismiss', ['key' => 'closeout:'.$jobs[2]->id, 'reason' => 'not_mine'])->assertSessionHas('success');
        $this->assertSame('raised', DetectorLevels::levelOf('job.closeout'), 'Until the findings run');
        FindingsRegistry::sync();
        $this->assertSame('demoted', DetectorLevels::levelOf('job.closeout'));
        $event = AuditEvent::query()->where('action', 'detector.demoted')->sole();
        $this->assertSame(['system', ['code' => 'job.closeout', 'level' => 'demoted']], [$event->actor, $event->after]);
        $demoted = DetectorLevels::demotedSince($since);
        $this->assertSame(['job.closeout'], array_keys($demoted));
        $this->assertStringStartsWith('2 of 10 findings since 10 Jun 2026 were marked not relevant as wrong.', $demoted['job.closeout']);
        $this->assertNotContains('closeout:'.$jobs[9]->id, array_column(TodoList::build()['items'], 'key'));
        $this->assertSame('info', Finding::query()->where('key', 'closeout:'.$jobs[4]->id)->value('severity'));
        $this->at('2026-06-12');
        $this->assertSame([], DetectorLevels::demotedSince(now()), 'Demoted before then, not since');

        // It stays demoted when the dismissals age out: Expend never raises a kind on its own.
        $this->at('2026-10-01');
        FindingsRegistry::sync();
        $this->assertSame('demoted', DetectorLevels::levelOf('job.closeout'));
        $this->assertSame(1, AuditEvent::query()->where('action', 'detector.demoted')->count());

        // Raising it again starts afresh: what you'd seen when you raised it doesn't count.
        $this->post('/todo/det_raise/job:closeout')->assertSessionHas('success');
        $this->assertSame('raised', DetectorLevels::levelOf('job.closeout'));
        FindingsRegistry::sync();
        $this->assertSame('raised', DetectorLevels::levelOf('job.closeout'));
    }

    public function test_add_the_trips_for_one_vehicle_bound_to_the_jobs_shown(): void
    {
        Http::fake(); // postcodes are known: nothing is looked up
        Setting::put('home_postcode', 'RG1 1AA');
        foreach ([['RG1 1AA', 51.4545, -0.9781], ['B40 1NT', 52.4531, -1.7166]] as [$pc, $lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
        $job = WorkJob::query()->create(['title' => 'NEC show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'postcode' => 'B40 1NT', 'status' => 'done']);

        // No vehicle yet: no button, the Mileage page is the way.
        $item = collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
        $this->assertSame([], $item['actions']);

        $van = Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $item = collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
        $set = substr($item['key'], strrpos($item['key'], ':') + 1);
        $this->assertSame(['trips_add', 'Add the trips to Van'], [$item['actions'][0]['code'], $item['actions'][0]['label']]);
        $this->assertMatchesRegularExpression('/^2026:'.$set.':'.$van->id.':[0-9a-f]{8}$/', $item['actions'][0]['id'], 'Bound to the jobs, the vehicle and the miles shown');

        // A job finished since it was shown: refused, nothing added.
        WorkJob::query()->create(['title' => 'NEC again', 'starts_on' => '2026-06-05', 'ends_on' => '2026-06-05', 'postcode' => 'B40 1NT', 'status' => 'done']);
        $this->post('/todo/trips_add/2026:'.$set)->assertSessionHas('error');
        $this->assertSame(0, MileageTrip::query()->count());

        $item = collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
        $this->post('/todo/'.$item['actions'][0]['code'].'/'.$item['actions'][0]['id'])->assertSessionHas('success');
        $this->assertSame(2, MileageTrip::query()->where('vehicle_id', $van->id)->count());
        $this->assertSame(['mileage.from_jobs', 'you'], [AuditEvent::query()->latest('id')->value('action'), AuditEvent::query()->latest('id')->value('actor')]);
        $this->assertNull(collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:')), 'Claimed');
        // Pressed again (a Telegram button stays): already dealt with.
        $this->post('/todo/'.$item['actions'][0]['code'].'/'.$item['actions'][0]['id'])->assertSessionHas('error', 'That’s already been dealt with.');
        $this->assertSame(2, MileageTrip::query()->count());

        // Two vehicles on simplified mileage: which one is yours to say, on the Mileage page.
        Vehicle::query()->create(['name' => 'Car', 'type' => 'car_van', 'method' => 'simplified']);
        WorkJob::query()->create(['title' => 'NEC third', 'starts_on' => '2026-06-07', 'ends_on' => '2026-06-07', 'postcode' => 'B40 1NT', 'status' => 'done']);
        $item = collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
        $this->assertSame([], $item['actions']);
        $this->assertSame(0, TodoDismissal::query()->count());
        Http::assertNothingSent();
    }

    /** Home, a venue, and another home: postcodes Expend already knows (nothing is looked up). */
    private function places(): void
    {
        Http::fake();
        foreach ([['RG1 1AA', 51.4545, -0.9781], ['B40 1NT', 52.4531, -1.7166]] as [$pc, $lat, $lng]) {
            DB::table('places')->insert(['postcode' => $pc, 'latitude' => $lat, 'longitude' => $lng, 'created_at' => now(), 'updated_at' => now()]);
        }
        Setting::put('home_postcode', 'RG1 1AA');
    }

    /** @return array the year's mileage item */
    private function mileageItem(): ?array
    {
        return collect(TodoList::build()['items'])->first(fn ($i) => str_starts_with($i['key'], 'mileage:2026-27:'));
    }

    public function test_add_the_trips_is_bound_to_the_vehicle_shown(): void
    {
        $this->places();
        WorkJob::query()->create(['title' => 'NEC show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'postcode' => 'B40 1NT', 'status' => 'done']);
        $van = Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $shown = $this->mileageItem()['actions'][0];
        $this->assertSame('Add the trips to Van', $shown['label']);

        // The van moves to actual costs and a motorbike is added before the old button is pressed.
        $van->update(['method' => 'actual']);
        $bike = Vehicle::query()->create(['name' => 'Motorbike', 'type' => 'motorcycle', 'method' => 'simplified']);
        $this->post('/todo/trips_add/'.$shown['id'])->assertSessionHas('error');
        $this->assertSame(0, MileageTrip::query()->count(), 'Never added to a vehicle you weren’t shown');

        // The button the list offers now names the motorbike, and adds to it.
        $now = $this->mileageItem()['actions'][0];
        $this->assertSame('Add the trips to Motorbike', $now['label']);
        $this->post('/todo/trips_add/'.$now['id'])->assertSessionHas('success');
        $this->assertSame([0, 1], [MileageTrip::query()->where('vehicle_id', $van->id)->count(), MileageTrip::query()->where('vehicle_id', $bike->id)->count()]);
    }

    public function test_add_the_trips_after_moving_house_adds_nothing_and_looks_nothing_up(): void
    {
        $this->places();
        WorkJob::query()->create(['title' => 'NEC show', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-01', 'postcode' => 'B40 1NT', 'status' => 'done']);
        Vehicle::query()->create(['name' => 'Van', 'type' => 'car_van', 'method' => 'simplified']);
        $shown = $this->mileageItem()['actions'][0];

        // A new home postcode not known yet: the list says "may have mileage" (same jobs, no button) without looking it up.
        Setting::put('home_postcode', 'M1 1AE');
        $this->assertSame([], $this->mileageItem()['actions']);
        $this->post('/todo/trips_add/'.$shown['id'])->assertSessionHas('error');
        $this->assertSame(0, MileageTrip::query()->count(), 'The miles shown were from the old home');
        Http::assertNothingSent();
    }
}
