<?php

namespace App\Http\Controllers;

use App\Domain\Todo\TodoActions;
use App\Domain\Todo\TodoList;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
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
