<?php

namespace App\Http\Controllers;

use App\Models\SaleReturn;
use App\Services\SaleService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleReturnController extends Controller
{
    public function __construct(private SaleService $sales, private StockService $stock) {}

    public function index(Request $request)
    {
        $query = SaleReturn::with('lines', 'warehouse', 'customer', 'creator');

        return CrudController::paginate($request, $query, ['reference', 'note'], fn (SaleReturn $return) => $return->toApi());
    }

    public function show(SaleReturn $saleReturn)
    {
        return $saleReturn->load('lines', 'warehouse', 'customer', 'creator')->toApi();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => ['nullable', 'uuid', 'exists:sales,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($this->sales->createReturn($data, $data['warehouse_id'])->toApi(), 201);
    }

    public function destroy(SaleReturn $saleReturn)
    {
        DB::transaction(function () use ($saleReturn) {
            $this->stock->reverse('sale_return', $saleReturn->id);
            if ($saleReturn->sale_id) {
                DB::table('sales')->where('id', $saleReturn->sale_id)->decrement('returned', $saleReturn->grand_total);
            }
            $saleReturn->delete();
        });

        return response()->noContent();
    }
}
