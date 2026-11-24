<?php

namespace App\Http\Controllers;

use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\RefResolver;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
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

        // What each item rests on, in plain words (its "Why"), and the reasons offered for hiding it.
        $refs = RefResolver::resolveMany(collect($list['items'])->flatMap(fn ($i) => $i['why'] ?? [])->unique()->values()->all());
        $list['items'] = array_map(fn (array $i) => array_diff_key($i, array_flip(['hidden_under', 'job_ids', 'range'])) + [
            'why_refs' => array_values(array_filter(array_map(fn ($r) => isset($refs[$r]) ? array_intersect_key($refs[$r], array_flip(['ref', 'label', 'href', 'state', 'check'])) : null, $i['why'] ?? []))),
            'reasons' => Dismissals::reasonsFor($i['code'] ?? null),
            'grade_basis' => Grades::forCode($i['code'] ?? null)[1],
        ], $list['items']);

        return Inertia::render('todo', $list + ['groups' => TodoList::GROUPS, 'all' => $all, 'directions' => array_filter(FindingDraft::DIRECTION_WORDS),
            'snooze_reasons' => Dismissals::reasonsFor(null, true)]);
    }

    /** Whether a to-do can only be snoozed, not marked not relevant (see TodoList::snoozeOnly()). */
    public static function snoozeOnly(string $key): bool
    {
        return TodoList::snoozeOnly($key);
    }

    /**
     * What has been found and dealt with: the history behind the list, with the money involved, each finding's
     * events and what closed it. ?scope=job:14 (or acct:3, year:2024-25) keeps to one job, account or year.
     */
    public function history(Request $request): Response
    {
        // Recorded again only when something the list is made from has changed, and never waiting for a run
        // already under way (the page shows what's recorded).
        FindingsRegistry::refresh();
        $scope = is_string($request->query('scope')) ? $request->query('scope') : null;

        // Each finding comes with the latest of its history; ?events={finding} sends all of one ("Show all").
        return Inertia::render('todo-history', FindingsRegistry::summary(null, $scope) + [
            'more_events' => Inertia::optional(fn () => ctype_digit((string) $request->query('events')) ? FindingsRegistry::eventsOf((int) $request->query('events')) : null),
        ]);
    }

    /**
     * Snooze a to-do for a while, or mark it not relevant (it stays hidden until something about it changes its key),
     * with a reason (one of Dismissals' reasons, or your own words), kept with it and with the finding. The to-do page
     * adds an Undo to the confirmation; undoing is audited too.
     *
     * Mileage to claim marked not relevant means you didn't drive to those jobs: each is left out from then on (a
     * dismissal of its own, TodoList::mileageJobKey()), so a job that finishes later is listed on its own. Undo puts
     * them back.
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => 'required|string|max:191', 'days' => 'nullable|integer|min:1|max:365', 'undo' => 'boolean',
            'reason' => 'nullable|string|max:20', 'note' => 'nullable|string|max:250']);
        if ($data['undo'] ?? false) {
            return back()->with('success', Dismissals::undo($data['key']));
        }
        $snooze = isset($data['days']);
        // Only the reasons offered for this kind of finding ("Both charges were real" only for a charge in twice).
        $code = Dismissals::codeFor($data['key']);
        if (isset($data['reason']) && ! array_key_exists($data['reason'], Dismissals::reasonsFor($code, $snooze))) {
            throw ValidationException::withMessages(['reason' => 'Choose one of the reasons offered.']);
        }
        try {
            $message = Dismissals::dismiss($data['key'], $data['days'] ?? null, Dismissals::note($data['reason'] ?? null, $data['note'] ?? null, $snooze, $code));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['key' => $e->getMessage()]);
        }

        return back()->with('success', $message);
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
