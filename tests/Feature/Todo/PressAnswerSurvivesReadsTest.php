<?php

namespace Tests\Feature\Todo;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * A to-do press's answer (its message, Undo and what to do next) is kept in the session for the page that comes back.
 * Sessions aren't locked, so a read still running when the press is posted (the to-do list's own second load,
 * "more_below") used to write its older copy of the session back over that answer, and the press came back with no
 * words and no Undo. A read that doesn't show the message now leaves the session alone (LeaveSessionOnReads).
 */
class PressAnswerSurvivesReadsTest extends TestCase
{
    use RefreshDatabase;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'session.encrypt' => false]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->actingAs(User::factory()->create(['name' => 'Alex Smith', 'email' => 'alex@example.test']));

        // The session as the to-do page left it.
        $this->id = Str::random(40);
        $this->store(fn (Store $s) => $s->put('_token', Str::random(40)));

        // A slow read: while it runs, a press is posted and finishes (it puts its answer in the session).
        $press = fn () => $this->store(fn (Store $s) => $s->flash('success', 'Marked done. Its invoice is ready to check.'));
        Route::middleware(['web', 'auth'])->get('/_slow/page', function () use ($press) {
            $press();

            return Inertia::render('todo', ['more_below' => ['items' => [], 'counts' => []], 'items' => []]);
        });
        Route::middleware(['web', 'auth'])->get('/_slow/search', function () use ($press) {
            $press();

            return response()->json(['jobs' => []]);
        });
    }

    /** Opens the stored session the way another request would, changes it, and saves it. */
    private function store(callable $change): void
    {
        $s = new Store(config('session.cookie'), new DatabaseSessionHandler(DB::connection(), 'sessions', 30, $this->app), $this->id);
        $s->start();
        $change($s);
        $s->save();
    }

    private function stored(): array
    {
        $payload = DB::table('sessions')->where('id', $this->id)->value('payload');

        return unserialize(base64_decode($payload));
    }

    private function inertia(array $headers = []): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml'] + $headers;
    }

    public function test_the_lists_second_load_running_during_a_press_keeps_the_press_answer(): void
    {
        $this->withCookie(config('session.cookie'), $this->id)
            ->get('/_slow/page', $this->inertia(['X-Inertia-Partial-Component' => 'todo', 'X-Inertia-Partial-Data' => 'more_below']))
            ->assertOk()->assertJsonPath('props.more_below.items', []);

        // The press's answer is still there for the page it comes back to.
        $this->assertSame('Marked done. Its invoice is ready to check.', $this->stored()['success'] ?? null);
    }

    public function test_a_pickers_search_running_during_a_press_keeps_the_press_answer(): void
    {
        $this->withCookie(config('session.cookie'), $this->id)
            ->withCredentials()->getJson('/_slow/search')->assertOk();

        $this->assertSame('Marked done. Its invoice is ready to check.', $this->stored()['success'] ?? null);
    }

    public function test_a_read_that_shows_the_message_still_uses_it_up(): void
    {
        $this->store(fn (Store $s) => $s->flash('success', 'Back to booked.')); // a press

        // A whole page shows it, and it's gone after.
        $this->withCookie(config('session.cookie'), $this->id)
            ->get('/todo', $this->inertia())->assertOk()->assertJsonPath('props.flash.success', 'Back to booked.');
        $this->assertArrayNotHasKey('success', $this->stored());

        // The Inbox asks for the message with its counts: that partial reload uses it up too.
        $this->store(fn (Store $s) => $s->flash('success', 'Saved.'));
        $this->withCookie(config('session.cookie'), $this->id)
            ->get('/todo', $this->inertia(['X-Inertia-Partial-Component' => 'todo', 'X-Inertia-Partial-Data' => 'nav,flash']))
            ->assertOk()->assertJsonPath('props.flash.success', 'Saved.');
        $this->assertArrayNotHasKey('success', $this->stored());
    }
}
