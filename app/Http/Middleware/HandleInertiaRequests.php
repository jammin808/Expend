<?php

namespace App\Http\Middleware;

use App\Domain\Todo\TodoList;
use App\Models\BankTransaction;
use App\Models\TaxYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
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
                'todo' => Cache::remember('todo_total', 120, fn () => TodoList::build(1)['total']),
                'years' => TaxYear::query()->orderBy('label')->get(['label', 'status'])->toArray(),
            ] : null,
        ];
    }
}
