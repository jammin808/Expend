<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Audit\AuditLog;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return to_route('login');
        }

        return Inertia::render('auth/two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'nullable|string', 'recovery_code' => 'nullable|string']);
        $user = User::query()->find($request->session()->get('login.id'));
        if (! $user || ! $user->hasTwoFactor()) {
            return to_route('login');
        }

        $key = 'two-factor:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $ok = false;
        if ($request->filled('recovery_code')) {
            $ok = TwoFactor::useRecoveryCode($user, $request->string('recovery_code'));
        } elseif ($request->filled('code')) {
            $ok = TwoFactor::verify($user->two_factor_secret, (string) $request->string('code'));
        }

        if (! $ok) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['code' => 'That code didn’t work.']);
        }

        RateLimiter::clear($key);
        Auth::login($user, (bool) $request->session()->pull('login.remember'));
        $request->session()->forget('login.id');
        $request->session()->regenerate();
        AuditLog::record('auth.login', $user, null, ['ip' => $request->ip(), 'method' => $request->filled('recovery_code') ? 'recovery_code' : 'totp']);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
