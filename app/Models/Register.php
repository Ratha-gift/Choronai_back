<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Register extends Model
{
    use Concerns\Audited;

    protected $casts = ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'counted_usd' => 'float', 'closing_summary' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function toApi(): array
    {
        return [
            ...$this->toArray(),
            'username' => $this->user?->username,
            'warehouse' => $this->warehouse?->name,
        ];
    }
}
