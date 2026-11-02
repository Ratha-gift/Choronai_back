<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberCard extends Model
{
    protected $casts = ['expiry' => 'date:Y-m-d'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
