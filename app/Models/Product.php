<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes, Concerns\Audited;

    protected $casts = [
        'alert_quantity' => 'float',
        'gallery' => 'array',
        'custom_fields' => 'array',
        'promo_start' => 'date:Y-m-d',
        'promo_end' => 'date:Y-m-d',
    ];

    public function comboItems(): HasMany
    {
        return $this->hasMany(ComboItem::class, 'combo_id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function priceChanges(): HasMany
    {
        return $this->hasMany(PriceChange::class)->orderByDesc('at');
    }

    /** Frontend `Product` shape; `quantity` is the stock in one warehouse or in all. */
    public function toApi(?string $warehouseId = null): array
    {
        $levels = $this->relationLoaded('stockLevels') ? $this->stockLevels : $this->stockLevels()->get();
        $quantity = $warehouseId
            ? (float) ($levels->firstWhere('warehouse_id', $warehouseId)?->quantity ?? 0)
            : (float) $levels->sum('quantity');

        return [
            ...collect($this->attributesToArray())->except(['promo_price', 'promo_start', 'promo_end', 'stock_levels', 'combo_items', 'suppliers', 'deleted_at'])->all(),
            'quantity' => round($quantity, 3),
            'stock' => $levels->mapWithKeys(fn ($level) => [$level->warehouse_id => (float) $level->quantity]),
            'promotion' => $this->promo_price === null ? null : [
                'price' => $this->promo_price,
                'start' => $this->promo_start?->format('Y-m-d'),
                'end' => $this->promo_end?->format('Y-m-d'),
            ],
            'combo_items' => $this->comboItems->map->only(['product_id', 'quantity', 'price'])->values(),
            'suppliers' => $this->suppliers->map->only(['supplier', 'part_number', 'price'])->values(),
        ];
    }
}
