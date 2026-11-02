<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserGroup;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserGroupController extends Controller
{
    public function index()
    {
        $rows = UserGroup::orderBy('name')->get();

        return ['rows' => $rows, 'total' => $rows->count()];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('user_groups')],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json(UserGroup::create($data + ['permissions' => []]), 201);
    }

    public function update(Request $request, UserGroup $group)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:60', Rule::unique('user_groups')->ignore($group->id)],
            'description' => ['nullable', 'string'],
        ]);
        $group->update($data);

        return $group;
    }

    public function destroy(UserGroup $group)
    {
        abort_if($group->is_owner, 422, 'The owner group cannot be deleted.');
        abort_if($group->hasMany(User::class, 'group_id')->exists(), 409, 'Move the users out of this group first.');
        $group->delete();

        return response()->noContent();
    }

    public function permissions(UserGroup $group)
    {
        return ['is_owner' => $group->is_owner, 'permissions' => $group->permissions ?? []];
    }

    /** Body: { permissions: ["products.view", "sales.pos", ...] } */
    public function updatePermissions(Request $request, UserGroup $group)
    {
        abort_if($group->is_owner, 422, 'The owner group always has every permission.');
        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', 'regex:/^[A-Za-z]+\.[A-Za-z]+$/'],
        ]);
        $before = $group->permissions;
        $group->update(['permissions' => array_values(array_unique($data['permissions']))]);
        Audit::log('update_permissions', 'user_group', $group->id, ['permissions' => $before], ['permissions' => $group->permissions]);

        return $this->permissions($group);
    }
}
