<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, Concerns\Audited;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'legacy_password_hash'];

    protected $casts = ['password' => 'hashed'];

    protected $with = ['group'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class);
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function hasPermission(string $permission): bool
    {
        return (bool) $this->group?->allows($permission);
    }

    /**
     * Staff with a branch and without "See all branches" (branches.all) work
     * only with the warehouses of their own branch.
     */
    public function mayUseWarehouse(?string $warehouseId): bool
    {
        if (! $warehouseId || (string) $this->branch === '' || $this->hasPermission('branches.all')) {
            return true;
        }
        $branchId = Branch::where('name', $this->branch)->value('id');
        if (! $branchId) {
            return true; // branch renamed or removed: do not lock the user out
        }

        return Warehouse::whereKey($warehouseId)->value('branch_id') === $branchId;
    }

    public function isOwner(): bool
    {
        return (bool) $this->group?->is_owner;
    }

    /**
     * A user may only hand out rights they have themselves: the owner manages
     * every group; others only groups that are not the owner group and whose
     * permissions they all have. This stops "Admin makes itself Owner".
     */
    public function mayManageGroup(?UserGroup $group): bool
    {
        if ($this->isOwner()) {
            return true;
        }
        if (! $group || $group->is_owner) {
            return false;
        }

        return $this->mayGrant($group->permissions ?? []);
    }

    /** Every permission in $permissions is one this user has. */
    public function mayGrant(array $permissions): bool
    {
        return $this->isOwner()
            || collect($permissions)->every(fn ($permission) => $this->hasPermission((string) $permission));
    }

    /** Editing, resetting the password of or deleting $user. */
    public function mayManageUser(User $user): bool
    {
        return $this->isOwner() || $user->id === $this->id || $this->mayManageGroup($user->group);
    }

    public function assertWarehouse(?string $warehouseId): void
    {
        abort_unless($this->mayUseWarehouse($warehouseId), 403, 'This warehouse belongs to another branch.');
    }

    /** Shape used by the frontend session and the Users page. */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => trim("{$this->first_name} {$this->last_name}"),
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'branch' => $this->branch,
            'status' => $this->status,
            'group_id' => $this->group_id,
            'group' => $this->group?->description ?: $this->group?->name,
            'permissions' => $this->group?->is_owner ? ['*'] : ($this->group?->permissions ?? []),
        ];
    }
}
