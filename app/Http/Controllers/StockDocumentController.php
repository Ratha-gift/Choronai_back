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
                'workflow' => 'controlled',
                'kind' => $kind,
                'reference' => $data['reference'] ?? References::next(self::PREFIX[$kind], $date),
                'created_by' => $request->user()->id,
            ]);
            $this->saveLines($document, $data['items']);
            Audit::log('create', "stock_{$kind}", $document->id, null, $document->load('lines')->toApi());

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
            $document = StockDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($document);
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
            $document = StockDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($document);
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

    private function assertEditable(StockDocument $document): void
    {
        // Posted documents remain auditable. Correct them with a new document.
        abort_unless($document->workflow === 'controlled' && $document->kind === 'adjustment'
            && $document->status === 'pending', 409, 'Only pending adjustments can be edited or deleted.');
    }

    public function approve(Request $request, string $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $document = StockDocument::where('kind', 'adjustment')->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($document->workflow === 'controlled', 409, 'Legacy adjustments already affected stock.');
            if ($document->status === 'approved') {
                return $document->toApi();
            }
            abort_unless($document->status === 'pending', 409, 'Adjustment is not pending.');
            $changes = $document->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'quantity' => ($line->type === 'subtraction' ? -1 : 1) * $line->quantity,
                'expiry' => $line->expiry,
            ])->all();
            $this->stock->apply('stock_document', $document->id, 'adjustment', $document->warehouse_id, $changes, false);
            $document->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            Audit::log('approve', 'stock_adjustment', $document->id);

            return $document->toApi();
        });
    }

    /** Each receipt has a client UUID: retries with that UUID cannot move stock twice. */
    public function receiveTransfer(Request $request, string $id)
    {
        $data = $request->validate([
            'id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
            'note' => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $issue = StockDocument::where('kind', 'issue')->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($issue->workflow === 'controlled', 409, 'Legacy transfers already credited the destination.');
            if ($existing = StockDocument::withTrashed()->find($data['id'])) {
                abort_unless($existing->transfer_id === $issue->id, 409, 'Receipt ID already belongs to another document.');
                $old = $existing->lines->mapWithKeys(fn ($line) => [$line->product_id => (float) $line->quantity])->all();
                $incoming = collect($data['items'])->mapWithKeys(fn ($line) => [$line['product_id'] => (float) $line['quantity']])->all();
                abort_unless($old == $incoming, 409, 'Receipt ID was already used with different quantities.');

                return $existing->toApi();
            }
            abort_unless(in_array($issue->status, ['dispatched', 'partially_received'], true), 409, 'Transfer is not awaiting receipt.');
            $sent = $issue->lines->keyBy('product_id');
            $received = DB::table('stock_document_lines as lines')
                ->join('stock_documents as documents', 'documents.id', '=', 'lines.document_id')
                ->where('documents.transfer_id', $issue->id)
                ->selectRaw('lines.product_id, SUM(lines.quantity) as quantity')->groupBy('lines.product_id')
                ->pluck('quantity', 'product_id');
            $items = [];
            foreach ($data['items'] as $item) {
                $line = $sent->get($item['product_id']);
                abort_unless($line && round(($received[$line->product_id] ?? 0) + $item['quantity'], 3) <= (float) $line->quantity,
                    422, 'Receipt exceeds the outstanding dispatched quantity.');
                $items[] = [...$item, 'price' => $line->price, 'expiry' => $line->expiry];
                $received[$line->product_id] = ($received[$line->product_id] ?? 0) + $item['quantity'];
            }
            $receipt = StockDocument::create([
                ...$this->header(['warehouse_id' => $issue->to_warehouse_id, 'items' => $items, 'note' => $data['note'] ?? null], 'receive'),
                'id' => $data['id'], 'kind' => 'receive', 'workflow' => 'controlled', 'transfer_id' => $issue->id,
                'reference' => References::next(self::PREFIX['receive'], now()), 'created_by' => $request->user()->id,
            ]);
            $this->saveLines($receipt, $items);
            $complete = $sent->every(fn ($line) => round($received[$line->product_id] ?? 0, 3) >= (float) $line->quantity);
            $issue->update(['status' => $complete ? 'received' : 'partially_received']);
            Audit::log('receive_transfer', 'stock_receive', $receipt->id, null, ['transfer_id' => $issue->id]);

            return response()->json($receipt->toApi(), 201);
        });
    }

    private function validated(Request $request, string $kind, ?string $id = null): array
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:60', 'unique:stock_documents,reference'.($id ? ",{$id}" : '')],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'to_warehouse_id' => [$kind === 'issue' ? 'required' : 'prohibited', 'uuid', 'exists:warehouses,id', 'different:warehouse_id'],
            'supplier' => ['nullable', 'string'],
            'status' => ['nullable', $kind === 'adjustment' ? 'in:pending' : ($kind === 'issue' ? 'in:dispatched' : 'in:completed')],
            'note' => ['nullable', 'string'],
            'attachment' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'decimal:0,3'],
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
            'status' => $kind === 'adjustment' ? 'pending' : ($kind === 'issue' ? 'dispatched' : 'completed'),
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
            'adjustment' => null,
            'issue' => $this->stock->apply('stock_document', $document->id, 'issue_out', $document->warehouse_id, array_map(fn ($line) => $change($line, -1), $lines), false),
        };
    }
}
