<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\SaleService;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(private SaleService $sales) {}

    public function index(Request $request)
    {
        $query = Sale::with('lines', 'warehouse', 'customer', 'creator', 'memberCard')
            ->when($request->query('warehouse_id'), fn ($query, $id) => $query->where('warehouse_id', $id))
            ->when($request->query('register_id'), fn ($query, $id) => $query->where('register_id', $id))
            ->when($request->boolean('needs_review'), fn ($query) => $query->where('needs_review', true))
            ->when($request->query('from'), fn ($query, $from) => $query->where('date', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->query('to'), fn ($query, $to) => $query->where('date', '<=', Carbon::parse($to)->endOfDay()));

        return CrudController::paginate($request, $query, ['reference', 'note'], fn (Sale $sale) => $sale->toApi());
    }

    public function show(Sale $sale)
    {
        return $sale->load('lines', 'payments', 'warehouse', 'customer', 'creator', 'memberCard')->toApi();
    }

    /** A POS sale. Needs an open register; stock comes from the register's warehouse. */
    public function store(Request $request)
    {
        $data = $request->validate(self::rules());
        $register = RegisterController::openFor($request->user()->id);
        abort_unless($register, 409, 'Open the register before selling.');

        return response()->json($this->sales->create($data, $register)->toApi(), 201);
    }

    public function destroy(Sale $sale)
    {
        $this->sales->delete($sale);

        return response()->noContent();
    }

    /** A later payment on a credit / partly paid sale (Sales → Add Payment). */
    public function addPayment(Request $request, Sale $sale)
    {
        $data = $request->validate([
            'method' => ['required', 'in:Cash,ABA / KHQR,Card,Bank transfer,Delivery,Other'],
            'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['nullable', 'date'],
        ]);

        return DB::transaction(function () use ($request, $sale, $data) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $owed = $sale->grand_total - $sale->returned - $sale->paid;
            abort_if($owed <= 0, 409, 'This sale is already paid.');
            $amount = min((int) $data['amount'], $owed);
            $sale->payments()->create([
                'method' => $data['method'], 'amount' => $amount, 'tendered_riel' => $amount, 'tendered_usd' => 0,
                'rate' => Settings::usdRate(), 'paid_at' => Carbon::parse($data['paid_at'] ?? now()),
                'created_by' => $request->user()->id,
            ]);
            $paid = $sale->paid + $amount;
            $sale->update(['paid' => $paid, 'payment_status' => $paid >= $sale->grand_total - $sale->returned ? 'paid' : 'partial']);
            Audit::log('add_payment', 'sale', $sale->id, null, ['method' => $data['method'], 'amount' => $amount]);

            return $sale->load('lines', 'payments', 'warehouse', 'customer', 'creator', 'memberCard')->toApi();
        });
    }

    /** Manager marks an offline sale as checked. */
    public function review(Sale $sale)
    {
        $sale->update(['needs_review' => false]);

        return $sale->load('lines', 'warehouse', 'customer', 'creator', 'memberCard')->toApi();
    }

    public static function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:60'],
            'date' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'member_card_no' => ['nullable', 'string', 'exists:member_cards,card_no'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'integer', 'min:0'],
            'items.*.name' => ['nullable', 'string'],
            'items.*.unit' => ['nullable', 'string'],
            'order_tax' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'shipping' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
            'payment' => ['required', 'array'],
            // The POS methods; "Mixed" = split over several methods (see `payments`),
            // "Not yet paid" = a credit sale.
            'payment.method' => ['required', 'in:Cash,ABA / KHQR,Card,Bank transfer,Delivery,Other,Mixed,Not yet paid'],
            'payment.tendered_riel' => ['nullable', 'integer', 'min:0'],
            'payment.tendered_usd' => ['nullable', 'numeric', 'min:0'],
            'payment.rate' => ['nullable', 'numeric', 'gt:0'],
            // Split payment: one entry per method, amounts in riel.
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required', 'in:Cash,ABA / KHQR,Card,Bank transfer,Delivery,Other'],
            'payments.*.amount' => ['required', 'integer', 'min:0'],
        ];
    }
}
