<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /** Returns a bearer token; POS computers keep it for offline sync too. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);
        $key = 'login:'.mb_strtolower($data['username']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        // Username, or the email address browsers often fill in.
        $login = mb_strtolower(trim($data['username']));
        $user = User::whereRaw('LOWER(username) = ?', [$login])->first()
            ?? (str_contains($login, '@') ? User::whereRaw('LOWER(email) = ?', [$login])->first() : null);
        // Users imported from the old frontend: check its SHA-256 hash once,
        // then store the password the normal way (bcrypt).
        if ($user && $user->legacy_password_hash && hash_equals(
            $user->legacy_password_hash,
            hash('sha256', 'choronai:'.mb_strtolower($user->username).':'.$data['password']),
        )) {
            $user->update(['password' => $data['password'], 'legacy_password_hash' => null]);
        }
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            abort(422, 'Wrong username or password.');
        }
        if ($user->status === 'inactive') {
            abort(403, 'This account is inactive.');
        }
        RateLimiter::clear($key);

        $expires = ($data['remember'] ?? false) ? now()->addDays(30) : now()->addHours(12);
        $token = $user->createToken('web', ['*'], $expires);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expires->toIso8601String(),
            'user' => $user->toApi(),
        ];
    }

    public function me(Request $request)
    {
        return $request->user()->toApi();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            abort(422, 'The current password is wrong.');
        }
        $user->update(['password' => $data['password']]);
        // Sign out every other device.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->noContent();
    }
}
