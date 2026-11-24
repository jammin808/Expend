<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\TodoDismissal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

class TodoController extends Controller
{
    /** The first few of each kind are sent; a kind you ask to see all of ("Show more") is sent in full, up to this many. */
    private const PER_GROUP = 15;

    private const ALL_OF_A_KIND = 500;

    public function index(Request $request): Response
    {
        // ?all=no_po,jobs: the kinds you asked to see all of. Payments are gone through in the Inbox instead.
        $asked = is_string($request->query('all')) ? explode(',', $request->query('all')) : [];
        $all = array_values(array_intersect(array_keys(TodoList::GROUPS), array_diff($asked, ['payments'])));

        $list = TodoList::build($all === [] ? self::PER_GROUP : self::ALL_OF_A_KIND);
        if ($all !== []) {
            $seen = [];
            $list['items'] = array_values(array_filter($list['items'], function (array $i) use (&$seen, $all) {
                $seen[$i['group']] = ($seen[$i['group']] ?? 0) + 1;

                return in_array($i['group'], $all, true) || $seen[$i['group']] <= self::PER_GROUP;
            }));
        }

        return Inertia::render('todo', $list + ['groups' => TodoList::GROUPS, 'all' => $all]);
    }

    /** Whether a to-do can only be snoozed, not marked not relevant (see TodoList::snoozeOnly()). */
    public static function snoozeOnly(string $key): bool
    {
        return TodoList::snoozeOnly($key);
    }

    /** What has been found and dealt with: the history behind the list, with the money involved. */
    public function history(): Response
    {
        // Recorded again only when something the list is made from has changed, and never waiting for a run
        // already under way (the page shows what's recorded).
        FindingsRegistry::refresh();

        return Inertia::render('todo-history', FindingsRegistry::summary());
    }

    /**
     * Snooze a to-do for a while, or mark it not relevant (it stays hidden until something about it changes its key).
     * The to-do page adds an Undo to the confirmation.
     *
     * Mileage to claim marked not relevant means you didn't drive to those jobs: each is left out from then on (a
     * dismissal of its own, TodoList::mileageJobKey()), so a job that finishes later is listed on its own. Undo puts
     * them back.
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => 'required|string|max:191', 'days' => 'nullable|integer|min:1|max:365', 'undo' => 'boolean']);
        if ($data['undo'] ?? false) {
            TodoDismissal::query()->where('key', $data['key'])->delete();
            if (preg_match('/^mileage:\d{4}-\d{2}:([0-9a-f]+)$/', $data['key'], $m)) {
                TodoDismissal::query()->where('key', 'like', TodoList::MILEAGE_JOB.'%:'.$m[1])->delete();
            }
            TodoList::forget();

            return back()->with('success', 'It’s back on the list.');
        }
        if (! isset($data['days']) && self::snoozeOnly($data['key'])) {
            throw ValidationException::withMessages(['key' => 'This one can only be snoozed. It comes back whenever it applies again, and “Not relevant” would hide it for good.']);
        }
        $jobs = isset($data['days']) ? null : TodoList::mileageJobs($data['key']);
        TodoDismissal::query()->updateOrCreate(['key' => $data['key']], ['until' => isset($data['days']) ? now()->addDays($data['days']) : null]);
        foreach ($jobs ?? [] as $job) {
            TodoDismissal::query()->updateOrCreate(['key' => TodoList::mileageJobKey($job, $data['key'])], ['until' => null]);
        }
        AuditLog::record('todo.dismissed', 'Todo', null, $data + ($jobs ? ['no_mileage_for_jobs' => $jobs] : []));
        TodoList::forget();

        return back()->with('success', isset($data['days']) ? "Snoozed for {$data['days']} days."
            : ($jobs ? 'Marked not relevant: '.(count($jobs) === 1 ? 'that job won’t' : 'those '.count($jobs).' jobs won’t').' be listed for mileage again.' : 'Marked not relevant.'));
    }

    public function act(string $code, string $id, TodoActions $actions): RedirectResponse
    {
        abort_unless(in_array($code, TodoActions::CODES, true), 404);
        try {
            $message = $actions->run($code, $id);
        } catch (Throwable $e) {
            if ($e instanceof ModelNotFoundException) {
                return back()->with('error', 'That’s already been dealt with.');
            }
            if (! $e instanceof InvalidArgumentException) {
                report($e); // an InvalidArgumentException is an action saying why it can't be done ("This one needs a look")
            }

            return back()->with('error', $e->getMessage());
        } finally {
            TodoList::forget();
        }

        return back()->with('success', $message);
    }
}
