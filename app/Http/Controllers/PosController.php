<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Device;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\TaxRate;
use App\Models\Warehouse;
use App\Services\RegisterService;
use App\Services\SaleService;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(private SaleService $sales, private RegisterService $registers) {}

    /** Removing a cart line or cancelling a sale. Every attempt is logged. */
    public function verifyPin(Request $request)
    {
        $data = $request->validate(['pin' => 'required|string', 'reason' => 'nullable|string']);
        $key = 'pin:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many wrong PINs. Wait a minute.');

        $ok = Settings::checkPin($data['pin']);
        $ok ? RateLimiter::clear($key) : RateLimiter::hit($key, 60);
        Audit::log($ok ? 'pin_ok' : 'pin_wrong', 'pos', null, null, ['reason' => $data['reason'] ?? '']);

        return ['ok' => $ok];
    }

    /**
     * Everything one POS computer keeps for offline selling. Includes the
     * salted PIN hash (never the PIN) so the PIN can be checked offline.
     */
    public function bootstrap(Request $request)
    {
        $warehouseId = $request->validate(['warehouse_id' => 'required|uuid|exists:warehouses,id'])['warehouse_id'];
        $pos = Settings::all('pos');
        $canSeeCost = $request->user()->hasPermission('products.cost');

        return [
            'synced_at' => now()->toIso8601String(),
            'warehouse' => Warehouse::find($warehouseId),
            'products' => Product::with('stockLevels', 'comboItems')->get()->map(function (Product $product) use ($warehouseId, $canSeeCost) {
                $row = collect($product->toApi($warehouseId))->except(['stock', 'suppliers', 'gallery', 'details'])->all();

                return $canSeeCost ? $row : collect($row)->except('cost')->all();
            }),
            'customers' => Customer::orderBy('name')->get(),
            'member_cards' => MemberCard::all(),
            'currencies' => Currency::all(),
            'tax_rates' => TaxRate::all(),
            'settings' => [
                'system' => Settings::public('system'),
                'pos' => [...Settings::public('pos'), 'pin_salt' => $pos['pin_salt'] ?? null, 'pin_hash' => $pos['pin_hash'] ?? null],
            ],
            'register' => RegisterController::openFor($request->user()->id)?->toApi(),
        ];
    }

    /** Registers one POS computer; its prefix goes into offline references. */
    public function registerDevice(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'reference_prefix' => ['required', 'string', 'max:6', 'regex:/^[A-Z0-9]+$/', 'unique:devices,reference_prefix'],
        ]);

        return response()->json(Device::create($data), 201);
    }

    public function devices()
    {
        $rows = Device::orderBy('name')->get();

        return ['rows' => $rows, 'total' => $rows->count()];
    }

    /**
     * Uploads what a POS computer did while offline: registers it opened or
     * closed, and its sales. Sending the same batch twice is safe: rows are
     * matched by the id the device gave them.
     */
    public function sync(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['required', 'uuid', 'exists:devices,id'],
            'registers' => ['sometimes', 'array'],
            'registers.*.id' => ['required', 'uuid'],
            'registers.*.cash_in_hand' => ['required', 'integer', 'min:0'],
            'registers.*.opened_at' => ['required', 'date'],
            'registers.*.open_note' => ['nullable', 'string'],
            'registers.*.closed_at' => ['nullable', 'date'],
            'registers.*.counted_riel' => ['nullable', 'integer', 'min:0'],
            'registers.*.counted_usd' => ['nullable', 'numeric', 'min:0'],
            'registers.*.card_slips' => ['nullable', 'integer', 'min:0'],
            'registers.*.note' => ['nullable', 'string'],
            'sales' => ['sometimes', 'array'],
            'sales.*.register_id' => ['required', 'uuid'],
            ...collect(SaleController::rules())->mapWithKeys(fn ($rule, $key) => ["sales.*.{$key}" => $rule])->all(),
            'sales.*.id' => ['required', 'uuid'],
            'sales.*.date' => ['required', 'date'],
        ]);
        return DB::transaction(function () use ($request, $data) {
        // Serialize one cashier's sync and register openings, including UUID retries.
        \App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        $device = Device::findOrFail($data['device_id']);
        $user = $request->user();
        $result = ['registers' => [], 'sales' => []];

        foreach ($data['registers'] ?? [] as $row) {
            $register = Register::find($row['id']);
            abort_if($register && ($register->user_id !== $user->id || $register->warehouse_id !== $device->warehouse_id), 403, 'Register belongs to another cashier or warehouse.');
            if (! $register) {
                abort_if(RegisterController::openFor($user->id), 409, 'Close or synchronize the existing register before importing another shift.');
                $register = Register::create([
                    'id' => $row['id'],
                    'user_id' => $user->id,
                    'warehouse_id' => $device->warehouse_id,
                    'status' => 'open',
                    'opened_at' => $row['opened_at'],
                    'cash_in_hand' => $row['cash_in_hand'],
                    'open_note' => $row['open_note'] ?? null,
                ]);
            }
            $result['registers'][] = ['id' => $register->id, 'status' => 'saved'];
        }

        foreach ($data['sales'] ?? [] as $row) {
            if ($existing = Sale::withTrashed()->find($row['id'])) {
                abort_unless($existing->created_by === $user->id && $existing->register_id === $row['register_id'], 403, 'Sale belongs to another cashier or register.');
                $result['sales'][] = ['id' => $existing->id, 'status' => 'duplicate', 'reference' => $existing->reference];

                continue;
            }
            $register = Register::find($row['register_id']);
            if (! $register || $register->user_id !== $user->id || $register->warehouse_id !== $device->warehouse_id) {
                $result['sales'][] = ['id' => $row['id'], 'status' => 'error', 'message' => 'Unknown register.'];

                continue;
            }
            try {
                $sale = $this->sales->create([...$row, 'device_id' => $device->id], $register, offline: true);
                $result['sales'][] = ['id' => $sale->id, 'status' => 'saved', 'reference' => $sale->reference, 'needs_review' => $sale->needs_review];
            } catch (ValidationException $error) {
                $result['sales'][] = ['id' => $row['id'], 'status' => 'error', 'message' => collect($error->errors())->flatten()->first()];
            }
        }

        // Close registers after their sales are in, so the summary is complete.
        foreach ($data['registers'] ?? [] as $row) {
            $register = Register::find($row['id']);
            $failedSale = collect($result['sales'])->contains(fn ($entry) => $entry['status'] === 'error');
            if (! $failedSale && ! empty($row['closed_at']) && $register->status === 'open') {
                $this->registers->close($register, [...$row, 'counted_riel' => $row['counted_riel'] ?? 0]);
            }
        }

        DB::table('devices')->where('id', $device->id)->update(['last_sync_at' => now()]);

        return $result;
        });
    }
}
