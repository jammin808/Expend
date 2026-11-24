<?php

namespace App\Http\Controllers;

use App\Domain\Intel\Cycle\CycleRunner;
use App\Models\CycleRun;
use App\Models\CycleRunStep;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What Expend found and did overnight (docs/intelligence-cycle.md 7.1 and 7.5): the latest morning report, "Check now",
 * and the run log at /intel/runs. Only reads, except "Check now", which asks for a run (CycleRunner::request()).
 */
class IntelController extends Controller
{
    public function index(): Response
    {
        $run = CycleRun::query()->whereNotNull('report')->orderByDesc('id')->first();

        return Inertia::render('intel/index', [
            'mode' => CycleRunner::mode(),
            'report' => $run?->report,
            'reportRun' => $run ? self::runRow($run) : null,
            'runs' => CycleRun::query()->orderByDesc('id')->limit(5)->get()->map(fn (CycleRun $r) => self::runRow($r))->all(),
            'checking' => CycleRun::query()->whereIn('kind', ['manual', 'import'])->where('status', 'running')->exists(),
        ]);
    }

    public function runs(): Response
    {
        $runs = CycleRun::query()->orderByDesc('id')->paginate(20);
        $steps = CycleRunStep::query()->whereIn('cycle_run_id', $runs->pluck('id'))->orderBy('seq')->orderBy('id')->get()->groupBy('cycle_run_id');

        return Inertia::render('intel/runs', [
            'mode' => CycleRunner::mode(),
            'page' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'links' => ['prev' => $runs->previousPageUrl(), 'next' => $runs->nextPageUrl()]],
            'runs' => $runs->getCollection()->map(fn (CycleRun $r) => self::runRow($r) + [
                'steps' => ($steps[$r->id] ?? collect())->map(fn (CycleRunStep $s) => [
                    'step' => $s->step, 'words' => CycleRunner::stepWords($s->step), 'stage' => $s->stage, 'status' => $s->status,
                    'status_words' => CycleRunStep::STATUS_WORDS[$s->status] ?? $s->status, 'attempts' => $s->attempts, 'coverage' => $s->coverage,
                    'detector_version' => $s->detector_version, 'counts' => $s->counts, 'error' => $s->error,
                    'started_at' => $s->started_at?->toIso8601String(), 'finished_at' => $s->finished_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    /** "Check now": run the detectors and record what they find, in the worker, within the minute. */
    public function check(CycleRunner $runner): RedirectResponse
    {
        if (CycleRunner::mode() === 'off') {
            return back()->with('error', 'The nightly check isn’t switched on yet, so there’s nothing to run. The to-do list is still up to date.');
        }
        $run = $runner->request('manual');
        if (! $run) {
            return back()->with('error', 'Couldn’t start a check just now. Try again in a minute.');
        }
        if (! $run->wasRecentlyCreated && in_array($run->kind, ['nightly', 'morning'], true)) {
            // A run going now records the findings as they are when it gets there: no second one.
            return back()->with('success', 'The '.($run->kind === 'nightly' ? 'nightly' : 'morning').' check is running now: it records what it finds when it gets there.');
        }

        return back()->with('success', 'Checking now: the findings are updated within a minute or two.');
    }

    /** @return array<string, mixed> */
    private static function runRow(CycleRun $r): array
    {
        return [
            'id' => $r->id, 'run_key' => $r->run_key, 'kind' => $r->kind, 'kind_words' => CycleRun::KIND_WORDS[$r->kind] ?? $r->kind, 'mode' => $r->mode,
            'status' => $r->status, 'status_words' => CycleRun::STATUS_WORDS[$r->status] ?? $r->status,
            'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(), 'reported_at' => $r->reported_at?->toIso8601String(),
            'ai_spent_usd' => round($r->ai_spent_micro / 1_000_000, 2), 'ai_budget_usd' => round($r->ai_budget_micro / 1_000_000, 2),
            'summary' => ($r->counts ?? [])['summary'] ?? null, 'sealed' => $r->seal_audit_id !== null, 'error' => $r->error,
        ];
    }
}
