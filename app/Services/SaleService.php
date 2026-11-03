<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MemberCard;
use App\Models\Product;
use App\Models\Register;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Support\Audit;
use App\Support\References;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(private StockService $stock) {}

    /**
     * Saves a POS sale with its lines, payment, stock and points in one
     * transaction. `$offline` sales (synced later) are never refused for low
     * stock: they are saved and flagged for review instead.
     */
    public function create(array $data, Register $register, bool $offline = false): Sale
    {
        return DB::transaction(function () use ($data, $register, $offline) {
            $register = Register::whereKey($register->id)->lockForUpdate()->firstOrFail();
            abort_unless($register->user_id === auth()->id(), 403, 'This register belongs to another cashier.');
            if (isset($data['id']) && ($existing = Sale::withTrashed()->find($data['id']))) {
                abort_unless($existing->register_id === $register->id, 409, 'Sale ID is already in use.');

                return $existing->load('lines', 'payments', 'warehouse', 'customer', 'creator', 'memberCard');
            }
            abort_unless($register->status === 'open', 409, 'Register is closed.');
            $date = isset($data['date']) ? Carbon::parse($data['date']) : now();
            $lines = $this->lines($data['items']);
            $total = (int) round(collect($lines)->sum(fn ($line) => $line['quantity'] * $line['price']));
            $orderTax = (int) ($data['order_tax'] ?? 0);
            $discount = (int) ($data['discount'] ?? 0);
            $shipping = (int) ($data['shipping'] ?? 0);
            $grandTotal = max(0, $total + $orderTax - $discount + $shipping);

            $payment = $data['payment'] ?? [];
            $rate = (float) ($payment['rate'] ?? Settings::usdRate());
            $tendered = (int) round(($payment['tendered_riel'] ?? 0) + ($payment['tendered_usd'] ?? 0) * $rate);
            $paid = min($tendered, $grandTotal);

            $card = isset($data['member_card_no'])
                ? MemberCard::where('card_no', $data['member_card_no'])->lockForUpdate()->first()
                : null;
            abort_if($card?->expiry && $card->expiry->endOfDay()->isPast(), 422, 'Member card has expired.');
            abort_if($card && isset($data['customer_id']) && $data['customer_id'] !== $card->customer_id, 422, 'Card belongs to a different customer.');
            $customerId = $data['customer_id'] ?? $card?->customer_id;

            $reference = $data['reference'] ?? null;
            if (! $reference || Sale::withTrashed()->where('reference', $reference)->exists()) {
                $reference = References::next('sale_prefix', $date);
            }

            $sale = Sale::create([
                ...(isset($data['id']) ? ['id' => $data['id']] : []),
                'reference' => $reference,
                'date' => $date,
                'warehouse_id' => $register->warehouse_id,
                'customer_id' => $customerId,
                'member_card_id' => $card?->id,
                'register_id' => $register->id,
                'device_id' => $data['device_id'] ?? null,
                'total' => $total,
                'order_tax' => $orderTax,
                'discount' => $discount,
                'shipping' => $shipping,
                'grand_total' => $grandTotal,
                'paid' => $paid,
                'payment_status' => $paid >= $grandTotal ? 'paid' : ($paid > 0 ? 'partial' : 'due'),
                'payment_method' => $payment['method'] ?? 'Cash',
                'note' => $data['note'] ?? null,
                'created_by' => $register->user_id,
            ]);
            $sale->lines()->createMany($lines);
            $sale->payments()->create([
                'method' => $payment['method'] ?? 'Cash',
                'amount' => $paid,
                'tendered_riel' => (int) ($payment['tendered_riel'] ?? 0),
                'tendered_usd' => (float) ($payment['tendered_usd'] ?? 0),
                'rate' => $rate,
                'paid_at' => $date,
                'created_by' => $register->user_id,
            ]);

            $negative = $this->stock->apply('sale', $sale->id, 'sale', $register->warehouse_id, array_map(
                fn ($line) => ['product_id' => $line['product_id'], 'quantity' => -$line['quantity']],
                $lines,
            ), $offline ? true : null);

            $reasons = [];
            if ($negative) {
                $reasons[] = 'Stock below zero: '.collect($negative)->map(fn ($qty, $name) => "{$name} ({$qty})")->join(', ');
            }
            foreach ($lines as $line) {
                $current = Product::withTrashed()->find($line['product_id'])?->price;
                if ($current !== null && $line['price'] !== (int) $current) {
                    $reasons[] = "{$line['name']} sold at {$line['price']} (price {$current})";
                }
            }
            if ($reasons && $offline) {
                $sale->update(['needs_review' => true, 'review_reason' => implode('; ', $reasons)]);
            }
            if ($reasons) {
                Audit::log('price_or_stock_warning', 'sale', $sale->id, null, ['reasons' => $reasons]);
            }

            app(RewardService::class)->earn($sale);

            return $sale->load('lines', 'payments', 'warehouse', 'customer', 'creator', 'memberCard');
        });
    }

    public function createReturn(array $data, string $warehouseId): SaleReturn
    {
        return DB::transaction(function () use ($data, $warehouseId) {
            $register = Register::where('user_id', auth()->id())->where('status', 'open')->lockForUpdate()->first();
            abort_unless($register, 409, 'Open a register before issuing a refund.');
            abort_unless($register->warehouse_id === $warehouseId, 422, 'Refund warehouse must match the open register.');
            $date = isset($data['date']) ? Carbon::parse($data['date']) : now();
            $lines = $this->lines($data['items']);
            $total = (int) round(collect($lines)->sum(fn ($line) => $line['quantity'] * $line['price']));
            $sale = Sale::whereKey($data['sale_id'])->lockForUpdate()->firstOrFail();
            $sold = $sale->lines->groupBy('product_id');
            foreach ($lines as $line) {
                $original = $sold->get($line['product_id']);
                $returned = DB::table('sale_return_lines as lines')
                    ->join('sale_returns as returns', 'returns.id', '=', 'lines.document_id')
                    ->where('returns.sale_id', $sale->id)->where('lines.product_id', $line['product_id'])->sum('lines.quantity');
                abort_unless($original && round($returned + $line['quantity'], 3) <= round($original->sum('quantity'), 3), 422, 'Returned quantity exceeds the original sale.');
                abort_if($line['price'] > $original->min('price'), 422, 'Refund unit price exceeds the original sale price.');
            }
            abort_if($total > max(0, $sale->paid - $sale->returned), 422, 'Refund exceeds the remaining amount paid.');

            $return = SaleReturn::create([
                'reference' => References::next('return_sale_prefix', $date),
                'date' => $date,
                'warehouse_id' => $warehouseId,
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'register_id' => $register->id,
                'refund_method' => $data['refund_method'] ?? 'Cash',
                'restock' => $data['restock'] ?? true,
                'total' => $total,
                'grand_total' => $total,
                'paid' => $total,
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $return->lines()->createMany($lines);
            $sale?->increment('returned', $total);
            app(RewardService::class)->reverseReturn($sale->refresh(), $return->id, $total);

            if ($data['restock'] ?? true) {
                $this->stock->apply('sale_return', $return->id, 'sale_return', $warehouseId, array_map(
                fn ($line) => ['product_id' => $line['product_id'], 'quantity' => $line['quantity']],
                $lines,
                ));
            }
            Audit::log('refund', 'sale_return', $return->id, null, ['sale_id' => $sale->id, 'register_id' => $register->id, 'amount' => $total]);

            return $return->load('lines', 'warehouse', 'customer', 'creator');
        });
    }

    /** Deleting a sale puts its stock back and takes back its points. */
    public function delete(Sale $sale): void
    {
        abort(409, 'Posted sales are retained. Use a linked sale return to correct a sale.');
    }

    /** Copies name, code and unit from the product so old invoices never change. */
    private function lines(array $items): array
    {
        $products = Product::withTrashed()->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');

        return array_map(function ($item) use ($products) {
            $product = $products[$item['product_id']] ?? throw ValidationException::withMessages(['items' => ["Unknown product {$item['product_id']}."]]);

            return [
                'product_id' => $product->id,
                'code' => $product->sku,
                'name' => $item['name'] ?? $product->name,
                'unit' => $item['unit'] ?? $product->unit,
                'quantity' => round((float) $item['quantity'], 3),
                'price' => (int) round($item['price'] ?? $product->price),
            ];
        }, $items);
    }

    /** Every `customer_spent` riel earns `customer_points` (negative amount takes them back). */
    private function awardPoints(int $amount, ?string $customerId, ?MemberCard $card): void
    {
        $spent = (int) Settings::get('system', 'customer_spent', 0);
        $points = $spent > 0 ? intdiv(abs($amount), $spent) * (int) Settings::get('system', 'customer_points', 0) : 0;
        $points *= $amount < 0 ? -1 : 1;

        if ($points && $customerId) {
            Customer::whereKey($customerId)->increment('award_points', $points);
        }
        if ($card) {
            $card->increment('award_points', $points);
            $card->increment('spent', $amount);
        }
    }
}
