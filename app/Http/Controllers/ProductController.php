<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Stock is never edited here: it only changes through stock documents and sales. */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        $warehouseId = $request->query('warehouse_id');
        $query = Product::with('stockLevels', 'comboItems', 'suppliers')
            ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category));

        return CrudController::paginate($request, $query, ['name', 'sku', 'plu_code'], fn (Product $product) => $this->present($request, $product, $warehouseId));
    }

    public function show(Request $request, Product $product)
    {
        return $this->present($request, $product->load('stockLevels', 'comboItems', 'suppliers'), $request->query('warehouse_id'));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        $product = DB::transaction(function () use ($data) {
            $product = Product::create($this->columns($data));
            $this->saveChildren($product, $data);

            return $product;
        });

        return response()->json($product->load('stockLevels', 'comboItems', 'suppliers')->toApi(), 201);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate($this->rules($product->id));
        if (array_key_exists('cost', $data) && ! $request->user()->hasPermission('products.cost')) {
            unset($data['cost']);
        }

        DB::transaction(function () use ($request, $product, $data) {
            $oldPrice = $product->price;
            $product->update($this->columns($data));
            $this->saveChildren($product, $data);
            if (isset($data['price']) && (int) $data['price'] !== (int) $oldPrice) {
                $product->priceChanges()->create([
                    'name' => $product->name,
                    'old_price' => $oldPrice,
                    'new_price' => $data['price'],
                    'actor' => $request->user()->username,
                    'at' => now(),
                ]);
            }
        });

        return $product->refresh()->load('stockLevels', 'comboItems', 'suppliers')->toApi();
    }

    public function destroy(Product $product)
    {
        // Soft delete: old invoices and the stock ledger still point at it.
        $product->delete();
        Audit::log('delete', 'product', $product->id, ['name' => $product->name, 'sku' => $product->sku]);

        return response()->noContent();
    }

    public function priceHistory(Product $product)
    {
        $rows = $product->priceChanges()->get();

        return ['rows' => $rows, 'total' => $rows->count()];
    }

    /** The stock ledger of one product, newest first. */
    public function movements(Request $request, Product $product)
    {
        $query = StockMovement::where('product_id', $product->id)
            ->when($request->query('warehouse_id'), fn ($query, $id) => $query->where('warehouse_id', $id));

        return CrudController::paginate($request, $query);
    }

    /** Users without "products.cost" do not see the cost price. */
    private function present(Request $request, Product $product, ?string $warehouseId): array
    {
        $row = $product->toApi($warehouseId);
        if (! $request->user()->hasPermission('products.cost')) {
            unset($row['cost']);
        }

        return $row;
    }

    private function rules(?string $id = null): array
    {
        $required = $id ? 'sometimes' : 'required';

        return [
            'sku' => [$required, 'string', 'max:60', Rule::unique('products')->ignore($id)],
            'plu_code' => ['nullable', 'string', 'max:10', 'regex:/^\d+$/', Rule::unique('products')->ignore($id)],
            'name' => [$required, 'string', 'max:200'],
            'type' => ['sometimes', 'in:standard,service,combo'],
            'category' => ['sometimes', 'string'],
            'sub_category' => ['nullable', 'string'],
            'brand' => ['nullable', 'string'],
            'unit' => ['sometimes', 'string'],
            'sale_unit' => ['nullable', 'string'],
            'purchase_unit' => ['nullable', 'string'],
            'price' => [$required, 'integer', 'min:0'],
            'cost' => ['sometimes', 'integer', 'min:0'],
            'additional' => ['sometimes', 'integer', 'min:0'],
            'alert_quantity' => ['sometimes', 'numeric', 'min:0'],
            'promotion' => ['nullable', 'array'],
            'promotion.price' => ['required_with:promotion', 'integer', 'min:0'],
            'promotion.start' => ['nullable', 'date'],
            'promotion.end' => ['nullable', 'date'],
            'image' => ['nullable', 'string'],
            'gallery' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],
            'details' => ['nullable', 'string'],
            'invoice_details' => ['nullable', 'string'],
            'combo_items' => ['sometimes', 'array'],
            'combo_items.*.product_id' => ['required', 'uuid', 'exists:products,id', Rule::notIn([$id])],
            'combo_items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'combo_items.*.price' => ['sometimes', 'integer', 'min:0'],
            'suppliers' => ['sometimes', 'array'],
            'suppliers.*.supplier' => ['required', 'string'],
            'suppliers.*.part_number' => ['nullable', 'string'],
            'suppliers.*.price' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    private function columns(array $data): array
    {
        $columns = collect($data)->except(['promotion', 'combo_items', 'suppliers'])->all();
        if (array_key_exists('promotion', $data)) {
            $columns['promo_price'] = $data['promotion']['price'] ?? null;
            $columns['promo_start'] = $data['promotion']['start'] ?? null;
            $columns['promo_end'] = $data['promotion']['end'] ?? null;
        }

        return $columns;
    }

    private function saveChildren(Product $product, array $data): void
    {
        if (array_key_exists('combo_items', $data)) {
            $product->comboItems()->delete();
            $product->comboItems()->createMany(array_map(fn ($item) => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'] ?? 0,
            ], $data['combo_items']));
        }
        if (array_key_exists('suppliers', $data)) {
            $product->suppliers()->delete();
            $product->suppliers()->createMany(array_map(fn ($item) => [
                'supplier' => $item['supplier'],
                'part_number' => $item['part_number'] ?? '',
                'price' => $item['price'] ?? 0,
            ], $data['suppliers']));
        }
    }
}
