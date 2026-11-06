<?php

namespace App\Http\Controllers;

use App\Models\Register;
use App\Services\RegisterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __construct(private RegisterService $registers) {}

    public function index(Request $request)
    {
        $query = Register::with('user', 'warehouse');
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,closed'],
            'user_id' => ['nullable', 'uuid'],
            'warehouse_id' => ['nullable', 'uuid'],
        ]);
        foreach (array_filter($filters) as $column => $value) {
            $query->where($column, $value);
        }
        // Cashiers without the Registers page only see their own shifts.
        if (! $request->user()->hasPermission('sales.registers')) {
            $query->where('user_id', $request->user()->id);
        }

        return CrudController::paginate($request, $query, ['status'], fn (Register $register) => $register->toApi());
    }

    public function current(Request $request)
    {
        $register = $this->openFor($request->user()->id);

        return ['register' => $register?->toApi(), 'summary' => $register ? $this->registers->summary($register) : null];
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'id' => ['nullable', 'uuid', 'unique:registers,id'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'cash_in_hand' => ['required', 'integer', 'min:0'],
            'open_note' => ['nullable', 'string'],
            'opened_at' => ['nullable', 'date'],
        ]);

        $register = DB::transaction(function () use ($request, $data) {
            // Lock a row that exists even when this user has no shifts yet.
            \App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            // One open register per cashier.
            abort_if($this->openFor($request->user()->id, lock: true), 409, 'You already have an open register.');

            return Register::create([
                ...$data,
                'user_id' => $request->user()->id,
                'status' => 'open',
                'opened_at' => $data['opened_at'] ?? now(),
            ]);
        });

        return response()->json($register->load('user', 'warehouse')->toApi(), 201);
    }

    public function summary(Request $request, Register $register)
    {
        $this->authorizeRegister($request, $register);

        return $this->registers->summary($register);
    }

    /** The server calculates the expected cash; the cashier only sends what they counted. */
    public function close(Request $request, Register $register)
    {
        $this->authorizeRegister($request, $register);
        abort_if($register->status === 'closed', 409, 'This register is already closed.');
        $data = $request->validate([
            'counted_riel' => ['required', 'integer', 'min:0'],
            'counted_usd' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'gt:0'],
            'card_slips' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
            'closed_at' => ['nullable', 'date'],
        ]);

        $summary = $this->registers->close($register, $data);

        return ['register' => $register->refresh()->load('user', 'warehouse')->toApi(), 'summary' => $summary];
    }

    public static function openFor(string $userId, bool $lock = false): ?Register
    {
        return Register::with('user', 'warehouse')->where(['user_id' => $userId, 'status' => 'open'])
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
    }

    private function authorizeRegister(Request $request, Register $register): void
    {
        abort_unless($register->user_id === $request->user()->id || $request->user()->hasPermission('sales.registers'), 403);
    }
}
