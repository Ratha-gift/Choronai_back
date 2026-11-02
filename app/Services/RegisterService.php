<?php

namespace App\Services;

use App\Models\Register;
use App\Models\SaleReturn;
use App\Support\Audit;
use App\Support\Settings;

class RegisterService
{
    public const METHODS = ['Cash', 'ABA / KHQR', 'Card', 'Bank transfer'];

    /** Same numbers as `summarize` in src/features/pos/register.ts. */
    public function summary(Register $register): array
    {
        $sales = $register->sales()->get();
        $byMethod = [];
        foreach (self::METHODS as $method) {
            $byMethod[$method] = (int) $sales->where('payment_method', $method)->sum('paid');
        }
        $refunds = (int) SaleReturn::where('created_by', $register->user_id)
            ->where('date', '>=', $register->opened_at)
            ->when($register->closed_at, fn ($query) => $query->where('date', '<=', $register->closed_at))
            ->sum('grand_total');

        return [
            'by_method' => $byMethod,
            'not_paid' => (int) $sales->sum(fn ($sale) => $sale->grand_total - $sale->paid),
            'discounts' => (int) $sales->sum('discount'),
            'total_sales' => (int) $sales->sum('grand_total'),
            'refunds' => $refunds,
            'expected_cash' => $register->cash_in_hand + $byMethod['Cash'] - $refunds,
            'invoices' => $sales->count(),
        ];
    }

    /** Saves the counted cash; the expected cash is calculated here, not by the browser. */
    public function close(Register $register, array $data): array
    {
        $summary = $this->summary($register);
        $counted = (int) $data['counted_riel'] + (int) round(($data['counted_usd'] ?? 0) * ($data['rate'] ?? Settings::usdRate()));
        $register->update([
            'status' => 'closed',
            'closed_at' => $data['closed_at'] ?? now(),
            'counted_riel' => $data['counted_riel'],
            'counted_usd' => $data['counted_usd'] ?? 0,
            'counted_total' => $counted,
            'card_slips' => $data['card_slips'] ?? 0,
            'expected_cash' => $summary['expected_cash'],
            'difference' => $counted - $summary['expected_cash'],
            'total_sales' => $summary['total_sales'],
            'invoices' => $summary['invoices'],
            'note' => $data['note'] ?? null,
        ]);
        Audit::log('close', 'register', $register->id, null, $register->only(['expected_cash', 'counted_total', 'difference']));

        return $summary;
    }
}
