<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use Concerns\Audited;

    public function memberCards(): HasMany
    {
        return $this->hasMany(MemberCard::class);
    }
}
