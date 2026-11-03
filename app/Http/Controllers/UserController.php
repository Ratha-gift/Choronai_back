<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Register;
use App\Models\UserGroup;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['register_status' => ['nullable', 'in:open,closed,none']]);
        $query = User::query()->withExists([
            'registers as has_open_register' => fn ($query) => $query->where('status', 'open'),
            'registers as has_register',
        ]);
        match ($request->query('register_status')) {
            'open' => $query->whereHas('registers', fn ($query) => $query->where('status', 'open')),
            'closed' => $query->whereHas('registers')->whereDoesntHave('registers', fn ($query) => $query->where('status', 'open')),
            'none' => $query->whereDoesntHave('registers'),
            default => null,
        };

        return CrudController::paginate($request, $query, ['username', 'first_name', 'last_name', 'email'], fn (User $user) => [
            ...$user->toApi(),
            'register_status' => $user->has_open_register ? 'open' : ($user->has_register ? 'closed' : 'none'),
            'has_open_register' => (bool) $user->has_open_register,
        ]);
    }

    public function registers(Request $request, User $user)
    {
        return CrudController::paginate($request, Register::with('user', 'warehouse')->where('user_id', $user->id),
            ['status'], fn (Register $register) => $register->toApi());
    }

    public function show(User $user)
    {
        return $user->toApi();
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules() + ['password' => 'required|string|min:6']);
        $user = User::create($data);

        return response()->json($user->refresh()->toApi(), 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate($this->rules($user->id) + ['password' => 'nullable|string|min:6']);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $this->keepAnOwner($user, $data);
        $user->update($data);
        if (($data['status'] ?? null) === 'inactive' || isset($data['password'])) {
            $user->tokens()->delete();
        }
        Audit::log('update', 'user', $user->id, null, collect($data)->except('password')->all());

        return $user->refresh()->toApi();
    }

    public function destroy(Request $request, User $user)
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');
        $this->keepAnOwner($user, ['status' => 'deleted']);
        $user->tokens()->delete();
        // Keep the row for old documents; the account can no longer sign in.
        $user->update(['status' => 'inactive', 'username' => $user->username.'#deleted#'.now()->timestamp]);
        Audit::log('delete', 'user', $user->id);

        return response()->noContent();
    }

    private function rules(?string $id = null): array
    {
        return [
            'first_name' => [$id ? 'sometimes' : 'required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'username' => [$id ? 'sometimes' : 'required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users')->ignore($id)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'gender' => ['nullable', 'string'],
            'group_id' => [$id ? 'sometimes' : 'required', 'uuid', 'exists:user_groups,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    /** The shop must always keep at least one active owner. */
    private function keepAnOwner(User $user, array $changes): void
    {
        if (! $user->group?->is_owner) {
            return;
        }
        $losesOwner = ($changes['status'] ?? 'active') !== 'active'
            || (isset($changes['group_id']) && ! UserGroup::find($changes['group_id'])?->is_owner);
        $others = User::where('id', '!=', $user->id)->where('status', 'active')
            ->whereHas('group', fn ($query) => $query->where('is_owner', true))->exists();
        abort_if($losesOwner && ! $others, 422, 'At least one active owner account is required.');
    }
}
