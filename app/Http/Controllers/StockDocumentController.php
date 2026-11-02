<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockDocument;
use App\Services\StockService;
use App\Support\Audit;
use App\Support\References;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock Receive, Stock Issue (a transfer between warehouses) and Stock
 * Adjustment. The route passes `kind` as a default, after the URL parameters.
 */
class StockDocumentController extends Controller
{
    private const PREFIX = [
        'receive' => 'stock_receive_prefix',
        'issue' => 'stock_issue_prefix',
        'adjustment' => 'adjustment_prefix',
    ];

    public function __construct(private StockService $stock) {}

    public function index(Request $request, string $kind)
    {
        $query = StockDocument::with('lines', 'warehouse', 'toWarehouse', 'creator')->where('kind', $kind)
            ->when($request->query('warehouse_id'), fn ($query, $id) => $query->where(fn ($where) => $where->where('warehouse_id', $id)->orWhere('to_warehouse_id', $id)))
            ->when($request->query('from'), fn ($query, $from) => $query->where('date', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->query('to'), fn ($query, $to) => $query->where('date', '<=', Carbon::parse($to)->endOfDay()));

        return CrudController::paginate($request, $query, ['reference', 'note'], fn (StockDocument $document) => $document->toApi());
    }

    public function show(string $id, string $kind)
    {
        return $this->find($kind, $id)->load('lines', 'warehouse', 'toWarehouse', 'creator')->toApi();
    }

    public function store(Request $request, string $kind)
    {
        $data = $this->validated($request, $kind);
        $document = DB::transaction(function () use ($kind, $data, $request) {
            $date = Carbon::parse($data['date'] ?? now());
            $document = StockDocument::create([
                ...$this->header($data, $kind),
                'kind' => $kind,
                'reference' => $data['reference'] ?? References::next(self::PREFIX[$kind], $date),
                'created_by' => $request->user()->id,
            ]);
            $this->saveLines($document, $data['items']);

            return $document;
        });

        return response()->json($document->load('lines', 'warehouse', 'toWarehouse', 'creator')->toApi(), 201);
    }

    /** Editing = put back the old stock, then apply the new lines. */
    public function update(Request $request, string $id, string $kind)
    {
        $document = $this->find($kind, $id);
        $data = $this->validated($request, $kind, $document->id);
        DB::transaction(function () use ($document, $data, $kind) {
            $before = $document->load('lines')->toApi();
            $this->stock->reverse('stock_document', $document->id);
            $document->lines()->delete();
            $document->update([
                ...$this->header($data, $kind),
                ...(isset($data['reference']) ? ['reference' => $data['reference']] : []),
            ]);
            $this->saveLines($document, $data['items']);
            Audit::log('update', "stock_{$kind}", $document->id, $before);
        });

        return $document->refresh()->load('lines', 'warehouse', 'toWarehouse', 'creator')->toApi();
    }

    public function destroy(string $id, string $kind)
    {
        $document = $this->find($kind, $id);
        DB::transaction(function () use ($document, $kind) {
            $this->stock->reverse('stock_document', $document->id);
            Audit::log('delete', "stock_{$kind}", $document->id, $document->load('lines')->toApi());
            $document->delete();
        });

        return response()->noContent();
    }

    private function find(string $kind, string $id): StockDocument
    {
        return StockDocument::where('kind', $kind)->findOrFail($id);
    }

    private function validated(Request $request, string $kind, ?string $id = null): array
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:60', 'unique:stock_documents,reference'.($id ? ",{$id}" : '')],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'to_warehouse_id' => [$kind === 'issue' ? 'required' : 'prohibited', 'uuid', 'exists:warehouses,id', 'different:warehouse_id'],
            'supplier' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'attachment' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', $kind === 'adjustment' ? 'min:0' : 'gt:0'],
            'items.*.price' => ['sometimes', 'integer', 'min:0'],
            'items.*.type' => [$kind === 'adjustment' ? 'required' : 'prohibited', 'in:addition,subtraction'],
            'items.*.description' => ['nullable', 'string'],
            'items.*.expiry' => ['nullable', 'date'],
        ]);

        return $data;
    }

    private function header(array $data, string $kind): array
    {
        $total = collect($data['items'])->sum(fn ($item) => round($item['quantity'] * ($item['price'] ?? 0)));

        return [
            'date' => Carbon::parse($data['date'] ?? now()),
            'warehouse_id' => $data['warehouse_id'],
            'to_warehouse_id' => $kind === 'issue' ? $data['to_warehouse_id'] : null,
            'supplier' => $data['supplier'] ?? '',
            'status' => $data['status'] ?? 'completed',
            'note' => $data['note'] ?? null,
            'attachment' => $data['attachment'] ?? '',
            'total' => (int) $total,
        ];
    }

    private function saveLines(StockDocument $document, array $items): void
    {
        $products = Product::whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $lines = array_map(fn ($item) => [
            'product_id' => $item['product_id'],
            'code' => $products[$item['product_id']]->sku,
            'name' => $products[$item['product_id']]->name,
            'unit' => $products[$item['product_id']]->unit,
            'quantity' => round((float) $item['quantity'], 3),
            'price' => (int) ($item['price'] ?? 0),
            'type' => $item['type'] ?? null,
            'description' => $item['description'] ?? '',
            'expiry' => $item['expiry'] ?? null,
        ], $items);
        $document->lines()->createMany($lines);

        $change = fn ($line, $sign = 1) => [
            'product_id' => $line['product_id'],
            'quantity' => $sign * ($line['type'] === 'subtraction' ? -1 : 1) * $line['quantity'],
            'expiry' => $line['expiry'],
        ];

        match ($document->kind) {
            'receive' => $this->stock->apply('stock_document', $document->id, 'receive', $document->warehouse_id, array_map($change, $lines)),
            'adjustment' => $this->stock->apply('stock_document', $document->id, 'adjustment', $document->warehouse_id, array_map($change, $lines)),
            'issue' => [
                $this->stock->apply('stock_document', $document->id, 'issue_out', $document->warehouse_id, array_map(fn ($line) => $change($line, -1), $lines)),
                $this->stock->apply('stock_document', $document->id, 'issue_in', $document->to_warehouse_id, array_map($change, $lines)),
            ],
        };
    }
}
