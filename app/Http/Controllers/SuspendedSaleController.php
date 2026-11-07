<?php

namespace App\Http\Controllers;

use App\Models\SuspendedSale;
use Illuminate\Http\Request;

class SuspendedSaleController extends Controller
{
    public function index(Request $request)
    {
        $rows = SuspendedSale::when($request->query('warehouse_id'), fn ($query, $id) => $query->where('warehouse_id', $id))
            ->latest()->get();

        return ['rows' => $rows, 'total' => $rows->count()];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'adjustments' => ['nullable', 'array'],
            'total' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(SuspendedSale::create($data + ['created_by' => $request->user()->id]), 201);
    }

    public function destroy(Request $request, SuspendedSale $suspendedSale)
    {
        $user = $request->user();
        abort_unless(
            $suspendedSale->created_by === $user->id || $user->hasPermission('sales.edit'),
            403,
            'This suspended sale belongs to another cashier.',
        );
        $suspendedSale->delete();

        return response()->noContent();
    }
}
