<?php

namespace App\Http\Middleware;

use App\Models\BankTransaction;
use App\Models\TaxYear;
use Illuminate\Http\Request;
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
            ],
            'nav' => fn () => $user ? [
                'inbox' => BankTransaction::query()->whereIn('review_status', ['unreviewed', 'suggested'])->count(),
                'years' => TaxYear::query()->orderBy('label')->get(['label', 'status'])->toArray(),
            ] : null,
        ];
    }
}
