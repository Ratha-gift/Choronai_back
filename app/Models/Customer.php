<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    public function memberCards(): HasMany
    {
        return $this->hasMany(MemberCard::class);
    }
}
