<?php

namespace Tests\Feature\MailCalendar;

use App\Domain\Jobs\JobFinder;
use App\Domain\Telegram\Telegram;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Jobs\GatherForJob;
use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Mail and calendar batch 1, on the to-do list: a possible job reads as CandidateText has it (from your calendar, the
 * next 14 days first), and "Part of <job>" adds it to that job in one press (cand_merge), bound to what was shown.
 * Merging a possible job into a job searches the job again.
 */
class PossibleJobTodoTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGED = 'This changed since the page was shown, so nothing was done. Look at it again.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-24 10:00:00'));
        Setting::put('job_finder_auto', false);
        TodoList::forget();
    }

    private function entry(string $uid, string $start, string $end, string $summary, ?string $location = 'Acme Arena, Leeds LS1 1AA'): CalendarEvent
    {
        return CalendarEvent::query()->create(['uid' => $uid, 'starts_at' => $start, 'ends_at' => $end, 'all_day' => false, 'summary' => $summary, 'location' => $location]);
    }

    private function fromCalendar(CalendarEvent $e, array $extra = []): JobCandidate
    {
        return JobCandidate::query()->create($extra + ['status' => 'open', 'title' => $e->summary, 'starts_on' => $e->day(), 'ends_on' => $e->lastDay()->format('Y-m-d'),
            'sources' => [['key' => 'cal:'.$e->key(), 'type' => 'calendar', 'id' => $e->key(), 'label' => $e->summary, 'date' => $e->day(), 'to' => $e->lastDay()->format('Y-m-d'), 'verified' => true]],
            'score' => 3, 'found_by' => 'rules', 'reasons' => ['why' => []]]);
    }

    /** @return array{0: WorkJob, 1: JobCandidate, 2: CalendarEvent} a job, and a possible job from the calendar the day before it */
    private function rigDay(): array
    {
        $acme = Client::query()->create(['name' => 'Acme Events']);
        $awards = WorkJob::query()->create(['title' => 'Acme Awards', 'client_id' => $acme->id, 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-05', 'status' => 'booked']);
        $e = $this->entry('rig-1', '2026-12-03 08:00:00', '2026-12-03 18:00:00', 'Acme rig day');

        return [$awards, $this->fromCalendar($e, ['client_name' => 'Acme Events', 'po_number' => 'PO-4471', 'location' => 'Acme Arena, Leeds LS1 1AA']), $e];
    }

    private function item(string $key): ?array
    {
        TodoList::forget();

        return collect(TodoList::build()['items'])->firstWhere('key', $key);
    }

    public function test_the_to_do_item_says_it_is_from_your_calendar_opens_the_entry_and_offers_part_of(): void
    {
        [$awards, $c, $e] = $this->rigDay();
        $later = JobCandidate::query()->create(['status' => 'open', 'title' => 'Spring show', 'starts_on' => '2027-03-01', 'ends_on' => '2027-03-01', 'sources' => [], 'reasons' => ['why' => []]]);
        $earlier = JobCandidate::query()->create(['status' => 'open', 'title' => 'Autumn show', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01', 'sources' => [], 'reasons' => ['why' => []]]);

        $item = $this->item('candidate:'.$c->id);
        $this->assertSame('Possible job: Acme rig day', $item['title']);
        $this->assertSame('From your calendar: Thu 3 Dec, 08:00–18:00, Acme Arena, Leeds LS1 1AA', $item['detail']);
        $this->assertSame('/jobs/calendar?m=2026-12&entry='.$e->key(), $item['href']);
        $this->assertSame([['cand_add', (string) $c->id, 'Add as a job'], ['cand_merge', $c->id.':'.$awards->id, 'Part of Acme Awards'], ['cand_dismiss', (string) $c->id, 'Not a job']],
            array_map(fn ($a) => [$a['code'], $a['id'], $a['label']], $item['actions']));

        // One found elsewhere reads as before, and the next 14 days come first.
        $this->assertSame('1 Oct 2026 · from 0 sources', $this->item('candidate:'.$earlier->id)['detail']);
        $keys = array_values(array_filter(array_column(TodoList::build()['items'], 'key'), fn ($k) => str_starts_with($k, 'candidate:')));
        $this->assertSame(['candidate:'.$c->id, 'candidate:'.$earlier->id, 'candidate:'.$later->id], $keys);

        // On the page, each with the fingerprint of what it would act on.
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));
        $this->get('/todo')->assertOk()->assertInertia(fn ($page) => $page->where('items', fn ($items) => collect($items)->contains(fn ($i) => $i['key'] === 'candidate:'.$c->id
            && collect($i['actions'])->contains(fn ($a) => $a['code'] === 'cand_merge' && $a['fp'] === Fingerprints::of('cand_merge', $c->id.':'.$awards->id)))));
    }

    public function test_part_of_adds_it_to_the_job_and_searches_the_job_again(): void
    {
        Queue::fake();
        [$awards, $c] = $this->rigDay();
        $id = $c->id.':'.$awards->id;
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));

        $this->post('/todo/cand_merge/'.$id)->assertSessionHas('error', self::CHANGED); // a deciding press says what it was shown
        $this->post('/todo/cand_merge/'.$id, ['fp' => Fingerprints::of('cand_merge', $id)])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Added Acme rig day to Acme Awards: linked 1 source'));
        $this->assertSame(['merged', $awards->id], [$c->fresh()->status, $c->fresh()->work_job_id]);
        $this->assertSame('PO-4471', $awards->fresh()->po_number, 'Its blank PO is filled in');
        $this->assertContains('2026-12-03', array_column($awards->fresh()->dayList(), 'date'), 'Its calendar day is added');
        Queue::assertPushed(GatherForJob::class, fn (GatherForJob $j) => $j->jobId === $awards->id);

        // Pressed again: already dealt with, nothing more.
        $this->post('/todo/cand_merge/'.$id, ['fp' => Fingerprints::of('cand_merge', $id)])->assertSessionHas('error', 'That’s already been dealt with.');
    }

    public function test_part_of_is_refused_after_you_said_not_this_job(): void
    {
        Queue::fake();
        [$awards, $c, $e] = $this->rigDay();
        $id = $c->id.':'.$awards->id;
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));

        // Shown, then you said the entry isn't part of that job: the press made before is refused, and nothing changes.
        $shown = Fingerprints::of('cand_merge', $id);
        app(JobFinder::class)->rejectCalendarFor($e, $awards->id);
        $this->post('/todo/cand_merge/'.$id, ['fp' => $shown])->assertSessionHas('error', self::CHANGED);
        $this->assertSame('open', $c->fresh()->status);
        $this->assertNull($awards->fresh()->po_number);
        $this->assertNotContains('cand_merge', array_column($this->item('candidate:'.$c->id)['actions'], 'code'), 'No longer offered');
        // Even with a fresh fingerprint, the action itself refuses it.
        $this->expectExceptionMessage('You’ve said it isn’t part of Acme Awards');
        app(TodoActions::class)->run('cand_merge', $id, null, Fingerprints::of('cand_merge', $id));
    }

    public function test_part_of_is_refused_when_the_job_has_been_cancelled(): void
    {
        Queue::fake();
        [$awards, $c] = $this->rigDay();
        $id = $c->id.':'.$awards->id;
        $awards->update(['status' => 'cancelled']);

        $this->expectExceptionMessage('That job has been cancelled or deleted since');
        try {
            app(TodoActions::class)->run('cand_merge', $id, null, Fingerprints::of('cand_merge', $id));
        } finally {
            $this->assertSame('open', $c->fresh()->status);
            Queue::assertNotPushed(GatherForJob::class);
        }
    }

    public function test_part_of_in_telegram_carries_a_fingerprint_and_is_refused_once_changed(): void
    {
        Queue::fake();
        Setting::put('telegram_bot_token', '123:abc');
        Setting::put('telegram_chat_id', '42');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        [$awards, $c] = $this->rigDay();
        $button = Telegram::callbackData('cand_merge', $c->id.':'.$awards->id);
        $this->assertMatchesRegularExpression('/^t\|cand_merge\|\d+:\d+\|[0-9a-f]{6}$/', $button);

        $awards->update(['ends_on' => '2026-12-06']); // the job moved since the message
        Telegram::handleButton(['id' => 'q1', 'data' => $button, 'message' => ['message_id' => 7, 'chat' => ['id' => 42], 'date' => now()->timestamp, 'text' => 'To do']], app(TodoActions::class));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'answerCallbackQuery') && str_contains((string) ($r['text'] ?? ''), 'This changed since: open it in Expend'));
        $this->assertSame('open', $c->fresh()->status);
    }

    public function test_adding_a_possible_job_to_a_job_in_the_job_finder_searches_the_job_again(): void
    {
        // The checkers' case: a PO number and a day after the job, which the job's search goes by.
        Queue::fake();
        $job = WorkJob::query()->create(['title' => 'Acme Awards', 'starts_on' => '2026-12-04', 'ends_on' => '2026-12-04', 'status' => 'booked']);
        $c = $this->fromCalendar($this->entry('derig-1', '2026-12-05 08:00:00', '2026-12-05 12:00:00', 'Acme de-rig'), ['po_number' => 'PO-4471']);
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith']));

        $this->post("/jobs/finder/{$c->id}/merge", ['work_job_id' => $job->id])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Looking again for what belongs to the job.'));
        $this->assertSame('PO-4471', $job->fresh()->po_number);
        Queue::assertPushed(GatherForJob::class, fn (GatherForJob $j) => $j->jobId === $job->id);
    }
}
