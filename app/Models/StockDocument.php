<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockDocument extends Model
{
    use SoftDeletes;

    protected $casts = ['date' => 'datetime'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class, 'document_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Frontend `Transaction` shape. */
    public function toApi(): array
    {
        return [
            ...collect($this->toArray())->except(['lines', 'warehouse', 'to_warehouse', 'creator', 'deleted_at'])->all(),
            'warehouse' => $this->warehouse?->name,
            'to_warehouse' => $this->toWarehouse?->name,
            'grand_total' => $this->total,
            'created_by' => $this->creator?->username,
            'items' => $this->lines->map(fn ($line) => collect($line->toArray())->except(['id', 'document_id'])->all())->values(),
        ];
    }
}
