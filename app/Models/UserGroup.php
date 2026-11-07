<?php

namespace App\Models;

class UserGroup extends Model
{
    use Concerns\Audited;

    protected $casts = ['permissions' => 'array', 'is_owner' => 'boolean'];

    public function allows(string $permission): bool
    {
        return $this->is_owner || in_array($permission, $this->permissions ?? [], true);
    }
}
