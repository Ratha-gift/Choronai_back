<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
