<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    use Concerns\Audited;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
