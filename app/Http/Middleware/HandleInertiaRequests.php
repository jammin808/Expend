<?php

namespace App\Http\Middleware;

use App\Domain\Todo\TodoList;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * The dashboard shows the kept to-do list; a request that changes what it's made from makes it build the list afresh.
     * The quick add screen's copy for the service worker (quick.shell) is kept on the phone and shown offline, so it
     * carries nothing particular to this request: no to-do work, and its address is /quick whatever was asked.
     */
    public function handle(Request $request, Closure $next)
    {
        $shell = self::isShell($request);
        Inertia::resolveUrlUsing($shell ? fn () => '/quick' : null);
        if ($shell) {
            return parent::handle($request, $next);
        }
        TodoList::watch();
        $response = parent::handle($request, $next);
        TodoList::settle();

        return $response;
    }

    /** The quick add screen as the service worker keeps it (GET /quick/shell). */
    public static function isShell(Request $request): bool
    {
        return $request->routeIs('quick.shell');
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();
        if (self::isShell($request)) {
            // Only what the cached screen may show offline: never a flash message, a badge or anything else of this request.
            return [
                'errors' => (object) [],
                'name' => config('app.name'),
                'auth' => ['user' => $user ? $user->only(['name', 'email']) : null],
                'flash' => ['success' => null, 'error' => null, 'rule_offer' => null, 'rule_undo' => null],
                'nav' => null,
            ];
        }

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
