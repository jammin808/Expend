<?php

namespace App\Http\Controllers;

use App\Domain\Intel\DetectorLevels;
use App\Domain\Intel\FindingDraft;
use App\Domain\Intel\Grades;
use App\Domain\Intel\RefResolver;
use App\Domain\Todo\ChangedSince;
use App\Domain\Todo\Dismissals;
use App\Domain\Todo\FindingsRegistry;
use App\Domain\Todo\Fingerprints;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\Setting;
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
            foreach (['items', 'below'] as $part) {
                $seen = [];
                $list[$part] = array_values(array_filter($list[$part], function (array $i) use (&$seen, $all) {
                    $seen[$i['group']] = ($seen[$i['group']] ?? 0) + 1;

                    return in_array($i['group'], $all, true) || $seen[$i['group']] <= self::PER_GROUP;
                }));
            }
        }

        $list['items'] = self::shown($list['items']);
        $list['below'] = self::shown($list['below']);
        // The kinds that can be raised or lowered, how each is shown now and why (the page counts what each found).
        $kinds = Setting::remembering(fn () => collect(DetectorLevels::all())->filter(fn ($l, $code) => in_array($code, DetectorLevels::DEMOTABLE, true))
            ->map(fn ($l, $code) => ['code' => $code, 'name' => DetectorLevels::name($code), 'level' => $l['level'], 'reason' => $l['reason'],
                'fp_raise' => Fingerprints::of('det_raise', str_replace('.', ':', $code)), 'fp_lower' => Fingerprints::of('det_lower', str_replace('.', ':', $code))])->values()->all());

        return Inertia::render('todo', $list + ['groups' => TodoList::GROUPS, 'all' => $all, 'directions' => array_filter(FindingDraft::DIRECTION_WORDS),
            'snooze_reasons' => Dismissals::reasonsFor(null, true), 'detector_levels' => $kinds,
            // What the new checks found, below the fold: loaded once the list is on the page, so the list never waits.
            'more_below' => Inertia::defer(function () use ($all) {
                $below = TodoList::belowFold($all === [] ? self::PER_GROUP : self::ALL_OF_A_KIND);

                return ['items' => self::shown($below['items']), 'counts' => $below['counts']];
            }),
        ]);
    }

    /**
     * Items as the page shows them: what each rests on, in plain words (its "Why", one query per kind of record), the
     * reasons offered for hiding it, its grade's basis, and each action's fingerprint of what it would act on now (a
     * press on something changed since is refused).
     *
     * @param  list<array>  $items
     * @return list<array>
     */
    private static function shown(array $items): array
    {
        $refs = RefResolver::resolveMany(collect($items)->flatMap(fn ($i) => $i['why'] ?? [])->unique()->values()->all());

        return array_map(fn (array $i) => ['actions' => Fingerprints::attach($i['actions'] ?? [])] + array_diff_key($i, array_flip(['hidden_under', 'job_ids', 'range', 'subsumes'])) + [
            'why_refs' => array_values(array_filter(array_map(fn ($r) => isset($refs[$r]) ? array_intersect_key($refs[$r], array_flip(['ref', 'label', 'href', 'state', 'check'])) : null, $i['why'] ?? []))),
            'reasons' => Dismissals::reasonsFor($i['code'] ?? null),
            // The snooze reasons for this kind ("Not finished yet" for "Is it done?").
            'snooze_reasons' => Dismissals::reasonsFor($i['code'] ?? null, true),
            'grade_basis' => Grades::forCode($i['code'] ?? null)[1],
        ], $items);
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
        // already under way (the page shows what's recorded); queued instead when it takes a while.
        FindingsRegistry::refresh(orQueue: true);
        $scope = is_string($request->query('scope')) ? $request->query('scope') : null;

        // Each finding comes with the latest of its history; ?events={finding} sends all of one ("Show all").
        return Inertia::render('todo-history', FindingsRegistry::summary(null, $scope) + [
            'updating' => FindingsRegistry::queued(),
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

    /**
     * A to-do button. It carries the fingerprint of what it was shown on (fp: Fingerprints): pressed on something that
     * has changed since, it's refused with a message and nothing is done. A deciding press without one is refused the
     * same way, so a page that doesn't send it can't act on something it never showed. Every page that offers these
     * sends one: the to-do list, the why-panel's Lift and the connections map.
     *
     * After a press that did something, what to offer next goes with the message (TodoActions::followUp): the flash
     * 'undo' (its code, id, label and fingerprint, for the toast's Undo) and 'follow_up' (every button, links included).
     */
    public function act(Request $request, string $code, string $id, TodoActions $actions): RedirectResponse
    {
        abort_unless(in_array($code, TodoActions::CODES, true), 404);
        $fp = $request->validate(['fp' => 'nullable|string|regex:/^[0-9a-f]{6}$/'])['fp'] ?? null;
        if ($fp === null && Fingerprints::decides($code, $id)) {
            return back()->with('error', (new ChangedSince)->getMessage());
        }
        try {
            $message = $actions->run($code, $id, null, $fp);
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

        [$undo, $next] = self::followUp($code, $id);

        return back()->with('success', $message)->with('undo', $undo)->with('follow_up', $next);
    }

    /**
     * What to offer after a press: its Undo, and every follow-up button with its fingerprint (links as {label, href}).
     *
     * @return array{0:?array, 1:list<array>}
     */
    private static function followUp(string $code, string $id): array
    {
        try {
            $buttons = TodoActions::followUp($code, $id);
        } catch (Throwable $e) {
            report($e);

            return [null, []];
        }
        $next = array_map(fn (array $b) => isset($b['url'])
            ? ['label' => $b['text'], 'href' => $b['url']]
            : ['code' => $b[1], 'id' => (string) $b[2], 'label' => $b[0], 'fp' => Fingerprints::of($b[1], (string) $b[2])], $buttons);

        return [collect($next)->first(fn (array $b) => ($b['label'] ?? null) === 'Undo' && isset($b['code'])), $next];
    }
}
