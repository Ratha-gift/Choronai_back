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
            'sale_id' => ['required', 'uuid', 'exists:sales,id'],
            'refund_method' => ['sometimes', 'in:Cash,ABA / KHQR,Card,Bank transfer'],
            'restock' => ['sometimes', 'boolean'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            'items.*.price' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($this->sales->createReturn($data, $data['warehouse_id'])->toApi(), 201);
    }

    public function destroy(SaleReturn $saleReturn)
    {
        abort(409, 'Posted refunds are retained for reconciliation.');
    }
}
