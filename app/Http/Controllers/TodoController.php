<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use App\Models\TodoDismissal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TodoController extends Controller
{
    public function index(): Response
    {
        $list = TodoList::build();
        Cache::put('todo_total', $list['total'], 120);

        return Inertia::render('todo', $list + ['groups' => TodoList::GROUPS]);
    }

    /** Snooze a to-do for a while, or mark it not relevant (it stays hidden until something about it changes its key). */
    public function dismiss(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => 'required|string|max:191', 'days' => 'nullable|integer|min:1|max:365', 'undo' => 'boolean']);
        if ($data['undo'] ?? false) {
            TodoDismissal::query()->where('key', $data['key'])->delete();
            Cache::forget('todo_total');

            return back()->with('success', 'It’s back on the list.');
        }
        TodoDismissal::query()->updateOrCreate(['key' => $data['key']], ['until' => isset($data['days']) ? now()->addDays($data['days']) : null]);
        AuditLog::record('todo.dismissed', 'Todo', null, $data);
        Cache::forget('todo_total');

        return back()->with('success', isset($data['days']) ? "Snoozed for {$data['days']} days." : 'Marked not relevant.')->with('todo_undo', $data['key']);
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
            report($e);

            return back()->with('error', $e->getMessage());
        } finally {
            Cache::forget('todo_total');
        }

        return back()->with('success', $message);
    }
}
