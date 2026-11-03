<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

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
            'status' => $this->status,
            'group_id' => $this->group_id,
            'group' => $this->group?->description ?: $this->group?->name,
            'permissions' => $this->group?->is_owner ? ['*'] : ($this->group?->permissions ?? []),
        ];
    }
}
