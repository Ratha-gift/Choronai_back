<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    /**
     * How long a sign-in lasts (System Settings): managers (Owner, or Users ›
     * Edit) default 7 days, staff 8 hours, e.g. one cashier shift. Without
     * "Keep me signed in" it lasts at most 12 hours.
     */
    public static function sessionMinutes(User $user, bool $remember): int
    {
        $manager = $user->group?->is_owner || $user->hasPermission('users.edit');
        $hours = (float) ($manager
            ? Settings::get('system', 'admin_session_hours', 168)
            : Settings::get('system', 'staff_session_hours', 8));
        $hours = max(0.25, $hours ?: ($manager ? 168 : 8));

        return (int) round(60 * ($remember ? $hours : min($hours, 12)));
    }

    /** Returns a bearer token; POS computers keep it for offline sync too. */
    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);
        // The visitor's address: Laravel only sees the frontend server, which
        // passes it on (X-Client-IP). Laravel is not public in production.
        $ip = $request->header('X-Client-IP') ?: $request->ip();
        $key = 'login:'.mb_strtolower($data['username']).'|'.$ip;
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
            Audit::log('login_failed', 'user', $user?->id, null, ['ip' => $ip, 'username' => $data['username']]);
            abort(422, 'Wrong username or password.');
        }
        if ($user->status === 'inactive') {
            Audit::log('login_failed', 'user', $user->id, null, ['ip' => $ip, 'username' => $user->username, 'reason' => 'inactive']);
            abort(403, 'This account is inactive.');
        }
        RateLimiter::clear($key);
        // Sign-in history (Server Status → Sign-in history).
        Audit::log('login', 'user', $user->id, null, ['ip' => $ip, 'username' => $user->username]);

        $expires = now()->addMinutes(self::sessionMinutes($user, (bool) ($data['remember'] ?? false)));
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
        Audit::log('logout', 'user', $request->user()->id, null, [
            'ip' => $request->header('X-Client-IP') ?: $request->ip(), 'username' => $request->user()->username,
        ]);

        return response()->noContent();
    }

    /** GET /audit/logins: the latest sign-ins, failed sign-ins and sign-outs. */
    public function loginHistory(Request $request)
    {
        $limit = min(500, max(1, (int) $request->query('limit', 100)));

        return AuditLog::whereIn('action', ['login', 'login_failed', 'logout'])
            ->latest('created_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'at' => $row->created_at,
                'action' => $row->action,
                'username' => $row->after['username'] ?? null,
                'ip' => $row->after['ip'] ?? null,
                'reason' => $row->after['reason'] ?? null,
            ]);
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
