<?php

namespace Tests\Feature;

use App\Domain\Todo\TodoList;
use App\Http\Controllers\TodoController;
use App\Models\AuditEvent;
use App\Models\JobCandidate;
use App\Models\Setting;
use App\Models\TaxYear;
use App\Models\TodoDismissal;
use App\Models\User;
use App\Models\WorkJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Review fixes for the to-do page's server side: every item of a kind can be reached, items that come back under the
 * same key can only be snoozed, and the dismissal response. (The page, toaster, search box and "?" dialog fixes are
 * in the browser and were checked there.)
 */
class ReviewFixesFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::put('job_finder_auto', false);
        Setting::put('business_name', 'Test Freelancer');
        $this->actingAs(User::factory()->create());
    }

    /** Booked jobs ahead with no PO number and nothing marking a PO as not needed. */
    private function jobsWithoutPo(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $day = now()->addDays(30 + $i)->toDateString();
            WorkJob::query()->create(['title' => "Show {$i}", 'starts_on' => $day, 'ends_on' => $day]);
        }
    }

    private function candidates(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $day = now()->addDays(10 + $i)->toDateString();
            JobCandidate::query()->create(['title' => "Maybe {$i}", 'starts_on' => $day, 'ends_on' => $day, 'sources' => [], 'reasons' => ['why' => []]]);
        }
    }

    private function gmailDisconnected(): void
    {
        Setting::put('google_client_id', 'abc.apps.googleusercontent.com');
        Setting::put('google_client_secret', 'secret');
        Setting::put('gmail_refresh_token', null);
        Setting::put('gmail_last_result', ['ok' => false, 'at' => now()->toIso8601String(), 'message' => 'invalid_grant']);
    }

    /** @return list<array> the to-do page's items */
    private function pageItems(string $url = '/todo'): array
    {
        $items = [];
        $this->get($url)->assertOk()->assertInertia(function (AssertableInertia $p) use (&$items) {
            $items = $p->toArray()['props']['items'];
        });

        return $items;
    }

    private function inGroup(array $items, string $group): int
    {
        return count(array_filter($items, fn ($i) => $i['group'] === $group));
    }

    // R46: the chip said "Jobs with no PO (40)" but only 15 were ever sent, and nothing reached the other 25.
    public function test_every_item_of_a_kind_can_be_listed_on_request(): void
    {
        $this->jobsWithoutPo(40);
        $this->candidates(20);

        $this->get('/todo')->assertInertia(fn (AssertableInertia $p) => $p->where('counts.no_po', 40)->where('counts.jobs', 20)->where('all', []));
        $first = $this->pageItems();
        $this->assertSame(15, $this->inGroup($first, 'no_po'), 'the first few of each kind come with the page');

        // "Show 35 more: jobs with no PO" asks for the rest of that kind; the others stay at the first few.
        $this->get('/todo?all=no_po')->assertInertia(fn (AssertableInertia $p) => $p->where('all', ['no_po'])->where('counts.no_po', 40));
        $all = $this->pageItems('/todo?all=no_po');
        $this->assertSame(40, $this->inGroup($all, 'no_po'));
        $this->assertSame(15, $this->inGroup($all, 'jobs'));

        // More than one kind, and nonsense ignored (payments are gone through in the Inbox).
        $both = $this->pageItems('/todo?all=jobs,no_po,payments,nope');
        $this->assertSame(40, $this->inGroup($both, 'no_po'));
        $this->assertSame(20, $this->inGroup($both, 'jobs'));
        $this->get('/todo?all=payments')->assertInertia(fn (AssertableInertia $p) => $p->where('all', []));
        $this->get('/todo?all[]=no_po')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('all', []));
    }

    // R47: "Not relevant" stored the fixed key 'gmail' for good, so every later disconnection was hidden too.
    public function test_gmail_and_tax_return_items_can_only_be_snoozed(): void
    {
        $this->gmailDisconnected();
        TaxYear::track('2024-25');
        $this->jobsWithoutPo(1);

        $items = collect($this->pageItems())->keyBy('key');
        $this->assertTrue($items['gmail']['snooze_only']);
        $this->assertTrue($items['deadline:2024-25']['snooze_only'], 'overdue since 31 January 2026');
        $noPo = $items->first(fn ($i) => $i['group'] === 'no_po');
        $this->assertFalse($noPo['snooze_only'], 'a job’s own item changes key with the job, so it can be marked not relevant');
        $this->assertTrue(TodoController::snoozeOnly('transfers'));
        $this->assertTrue(TodoController::snoozeOnly('deadline:2025-26:amend'));
        $this->assertFalse(TodoController::snoozeOnly('rule:costa'));

        // Marking them not relevant is refused, with a reason; nothing is hidden.
        foreach (['gmail', 'deadline:2024-25', 'transfers'] as $key) {
            $this->post('/todo/dismiss', ['key' => $key])->assertSessionHasErrors('key')->assertSessionMissing('success');
        }
        $this->assertSame(0, TodoDismissal::query()->count());

        // Snoozing works, and a later disconnection shows again once the snooze ends.
        $this->post('/todo/dismiss', ['key' => 'gmail', 'days' => 7])->assertSessionHasNoErrors()->assertSessionHas('success', 'Snoozed for 7 days.');
        $this->assertArrayNotHasKey('gmail', collect(TodoList::build()['items'])->keyBy('key')->all());
        Setting::put('gmail_refresh_token', 'reconnected');
        Setting::put('gmail_last_result', ['ok' => true]);
        $this->travel(30)->days();
        $this->gmailDisconnected();
        $this->assertContains('gmail', array_column(TodoList::build()['items'], 'key'));

        // Other items can still be marked not relevant.
        $this->post('/todo/dismiss', ['key' => $noPo['key']])->assertSessionHasNoErrors()->assertSessionHas('success', 'Marked not relevant.');
        $this->assertNull(TodoDismissal::query()->where('key', $noPo['key'])->value('until'));
    }

    // R41: the page gives the server's confirmation its Undo; the old 'todo_undo' flash was never shared, so it's gone.
    public function test_dismissing_confirms_once(): void
    {
        $this->post('/todo/dismiss', ['key' => 'no_po:1'])->assertSessionHas('success', 'Marked not relevant.')->assertSessionMissing('todo_undo');
        $this->post('/todo/dismiss', ['key' => 'no_po:1', 'undo' => true])->assertSessionHas('success', 'It’s back on the list.');
        $this->assertSame(0, TodoDismissal::query()->count());
    }

    // R47: ones already marked not relevant before the fix become a week's snooze; every other dismissal is untouched.
    public function test_repair_turns_old_permanent_dismissals_of_fixed_keys_into_a_snooze(): void
    {
        $later = now()->addDays(3);
        foreach (['gmail', 'transfers', 'deadline:2024-25', 'deadline:2025-26:amend', 'no_po:7', 'rule:costa'] as $key) {
            TodoDismissal::query()->create(['key' => $key, 'until' => null]);
        }
        TodoDismissal::query()->create(['key' => 'tx:9', 'until' => $later]);

        (require database_path('migrations/2026_11_29_000801_frontend_snooze_fixed_key_todos.php'))->up();

        $until = TodoDismissal::query()->pluck('until', 'key');
        foreach (['gmail', 'transfers', 'deadline:2024-25', 'deadline:2025-26:amend'] as $key) {
            $this->assertNotNull($until[$key], $key);
            $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), $until[$key]->getTimestamp(), 5, $key);
        }
        $this->assertNull($until['no_po:7']);
        $this->assertNull($until['rule:costa']);
        $this->assertSame($later->toDateTimeString(), $until['tx:9']->toDateTimeString());

        $event = AuditEvent::query()->where('action', 'todo.dismissal_changed')->sole();
        $this->assertSame(['deadline:2024-25', 'deadline:2025-26:amend', 'gmail', 'transfers'], $event->after['keys']);
        $this->assertSame('system', $event->actor);

        // Running it again changes nothing.
        (require database_path('migrations/2026_11_29_000801_frontend_snooze_fixed_key_todos.php'))->up();
        $this->assertSame(1, AuditEvent::query()->where('action', 'todo.dismissal_changed')->count());
        $this->assertSame(7, DB::table('todo_dismissals')->count());
    }
}
