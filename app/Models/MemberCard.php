<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberCard extends Model
{
    use Concerns\Audited;

    protected $casts = ['expiry' => 'date:Y-m-d'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
