<?php

namespace App\Http\Middleware;

use App\Domain\Todo\TodoList;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** The dashboard shows the kept to-do list; a request that changes what it's made from makes it build the list afresh. */
    public function handle(Request $request, Closure $next)
    {
        TodoList::watch();
        $response = parent::handle($request, $next);
        TodoList::settle();

        return $response;
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? $user->only(['id', 'name', 'email']) + ['two_factor' => $user->hasTwoFactor()] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'rule_offer' => fn () => $request->session()->get('rule_offer'),
                'rule_undo' => fn () => $request->session()->get('rule_undo'),
            ],
            'nav' => fn () => $user ? [
                'inbox' => BankTransaction::query()->whereIn('review_status', ['unreviewed', 'suggested'])->count(),
                // The last list's total (the one the dashboard or the to-do page just built, so they agree), for a
                // couple of minutes after a change: the Inbox asks for this after every decision.
                'todo' => TodoList::badge(),
                'years' => TaxYear::query()->orderBy('label')->get(['label', 'status'])->toArray(),
            ] : null,
        ];
    }
}
