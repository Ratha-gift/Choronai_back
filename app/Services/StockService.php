<?php

namespace App\Services;

use App\Models\ComboItem;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Support\Settings;
use Illuminate\Validation\ValidationException;

/**
 * Every stock change goes through here. Call inside DB::transaction: the
 * stock_levels rows are locked so two cashiers cannot sell the last kilogram.
 */
class StockService
{
    /**
     * @param  array<int, array{product_id: string, quantity: float, expiry?: ?string}>  $changes  quantity is + (in) or − (out)
     * @param  bool|null  $allowNegative  null = follow the "over_selling" setting
     * @return array<string, float> stock levels that went below zero
     */
    public function apply(string $documentType, string $documentId, string $reason, string $warehouseId, array $changes, ?bool $allowNegative = null): array
    {
        $allowNegative ??= Settings::get('system', 'over_selling', 'yes') === 'yes';
        $negative = [];

        foreach ($this->expandCombos($changes) as $change) {
            if (abs($change['quantity']) < 0.0005) {
                continue;
            }
            $level = $this->lockLevel($change['product_id'], $warehouseId);
            $after = round($level->quantity + $change['quantity'], 3);

            if ($after < 0 && $change['quantity'] < 0) {
                $name = Product::withTrashed()->find($change['product_id'])?->name ?? $change['product_id'];
                if (! $allowNegative) {
                    throw ValidationException::withMessages([
                        'items' => ["Not enough stock for {$name}: {$level->quantity} left."],
                    ]);
                }
                $negative[$name] = $after;
            }

            $level->update(['quantity' => $after]);
            StockMovement::create([
                'product_id' => $change['product_id'],
                'warehouse_id' => $warehouseId,
                'quantity' => $change['quantity'],
                'reason' => $reason,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'expiry' => $change['expiry'] ?? null,
                'created_by' => auth()->id(),
            ]);
        }

        return $negative;
    }

    /** Undoes every movement of a document (before an edit or a delete). */
    public function reverse(string $documentType, string $documentId): void
    {
        $movements = StockMovement::where(['document_type' => $documentType, 'document_id' => $documentId])->get();
        foreach ($movements as $movement) {
            $level = $this->lockLevel($movement->product_id, $movement->warehouse_id);
            $level->update(['quantity' => round($level->quantity - $movement->quantity, 3)]);
            $movement->delete();
        }
    }

    /** Combo products move the stock of their components instead. */
    private function expandCombos(array $changes): array
    {
        $out = [];
        foreach ($changes as $change) {
            $parts = ComboItem::where('combo_id', $change['product_id'])->get();
            if ($parts->isEmpty()) {
                $out[] = $change;

                continue;
            }
            foreach ($parts as $part) {
                $out[] = [...$change, 'product_id' => $part->product_id, 'quantity' => $change['quantity'] * $part->quantity];
            }
        }

        return $out;
    }

    private function lockLevel(string $productId, string $warehouseId): StockLevel
    {
        StockLevel::query()->insertOrIgnore(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => 0]);

        return StockLevel::where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first();
    }
}
