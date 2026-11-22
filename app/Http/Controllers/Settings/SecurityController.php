<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Audit\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $pending = $user->two_factor_secret && ! $user->two_factor_confirmed_at;

        return Inertia::render('settings/security', [
            'enabled' => $user->hasTwoFactor(),
            'pending' => $pending ? [
                'qr' => TwoFactor::qrSvg($user, $user->two_factor_secret),
                'secret' => $user->two_factor_secret,
            ] : null,
            'recoveryCodes' => $request->session()->get('recovery_codes'),
            'recoveryRemaining' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['two_factor_secret' => TwoFactor::newSecret(), 'two_factor_confirmed_at' => null])->save();

        return back();
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();
        if (! TwoFactor::verify($user->two_factor_secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'That code didn’t match. Check the time on your phone and try the newest code.']);
        }

        $codes = TwoFactor::newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes['hashed']])->save();
        AuditLog::record('auth.two_factor_enabled', $user);

        return back()->with('recovery_codes', $codes['plain'])->with('success', 'Two-factor sign-in is on.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|current_password']);
        $codes = TwoFactor::newRecoveryCodes();
        $request->user()->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();
        AuditLog::record('auth.recovery_codes_regenerated', $request->user());

        return back()->with('recovery_codes', $codes['plain']);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|current_password']);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        AuditLog::record('auth.two_factor_disabled', $request->user());

        return back()->with('success', 'Two-factor sign-in is off.');
    }
}
