<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(Request $request): Response
    {
        // Single-user app: registration only exists until the owner's account is created,
        // and on a live site only with the one-time setup code from the server's .env.
        abort_if(User::query()->exists() || ! self::codeOk($request->query('code')), 404);

        return Inertia::render('auth/register', ['code' => $request->query('code')]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists() || ! self::codeOk($request->input('code')), 404);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return to_route('setup');
    }

    private static function codeOk(mixed $code): bool
    {
        $expected = (string) config('app.setup_code');

        return $expected === '' || (is_string($code) && hash_equals($expected, $code));
    }
}
