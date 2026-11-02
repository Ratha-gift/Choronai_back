<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Seeded shop: MAIN has 70 kg and CEN 30 kg of every product. */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function actingAsUser(string $username): User
    {
        $user = User::where('username', $username)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    protected function warehouse(string $code): Warehouse
    {
        return Warehouse::where('code', $code)->firstOrFail();
    }

    protected function product(string $sku): Product
    {
        return Product::where('sku', $sku)->firstOrFail();
    }

    protected function stock(string $sku, string $code): float
    {
        return (float) $this->product($sku)->stockLevels()->where('warehouse_id', $this->warehouse($code)->id)->value('quantity');
    }

    protected function openRegister(string $code = 'CEN', int $cash = 50000): string
    {
        return $this->postJson('/api/v1/registers/open', ['warehouseId' => $this->warehouse($code)->id, 'cashInHand' => $cash])
            ->assertCreated()->json('id');
    }

    protected function sell(string $sku, float $quantity, array $extra = [])
    {
        $product = $this->product($sku);

        return $this->postJson('/api/v1/sales', [
            'items' => [['productId' => $product->id, 'quantity' => $quantity, 'price' => $product->price]],
            'payment' => ['method' => 'Cash', 'tenderedRiel' => 1000000],
            ...$extra,
        ]);
    }
}
