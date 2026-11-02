<?php

namespace App\Http\Controllers;

use App\Models\StockLevel;
use Illuminate\Http\Request;

class StockLevelController extends Controller
{
    /** ?warehouseId= → stock of each product in that warehouse (all warehouses without it). */
    public function index(Request $request)
    {
        $rows = StockLevel::query()
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_levels.warehouse_id')
            ->whereNull('products.deleted_at')
            ->when($request->query('warehouse_id'), fn ($query, $id) => $query->where('stock_levels.warehouse_id', $id))
            ->orderBy('products.name')
            ->get(['stock_levels.product_id', 'products.name', 'products.sku', 'products.unit', 'products.alert_quantity', 'stock_levels.warehouse_id', 'warehouses.name as warehouse', 'stock_levels.quantity']);

        return ['rows' => $rows, 'total' => $rows->count()];
    }
}
