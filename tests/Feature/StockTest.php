<?php

namespace Tests\Feature;

class StockTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser('admin021');
    }

    public function test_receive_adds_stock_and_editing_replaces_it(): void
    {
        $beef = $this->product('MEAT-001');
        $body = fn ($quantity) => ['warehouseId' => $this->warehouse('MAIN')->id, 'items' => [['productId' => $beef->id, 'quantity' => $quantity, 'price' => 25000]]];

        $id = $this->postJson('/api/v1/stock/receives', $body(20))->assertCreated()
            ->assertJsonPath('items.0.code', 'MEAT-001')->assertJsonPath('total', 500000)->json('id');
        $this->assertSame(90.0, $this->stock('MEAT-001', 'MAIN'));

        $this->putJson("/api/v1/stock/receives/{$id}", $body(25))->assertOk();
        $this->assertSame(95.0, $this->stock('MEAT-001', 'MAIN'));

        $this->deleteJson("/api/v1/stock/receives/{$id}")->assertNoContent();
        $this->assertSame(70.0, $this->stock('MEAT-001', 'MAIN'));
    }

    public function test_issue_moves_stock_between_warehouses(): void
    {
        $pork = $this->product('MEAT-002');
        $this->postJson('/api/v1/stock/issues', [
            'warehouseId' => $this->warehouse('MAIN')->id,
            'toWarehouseId' => $this->warehouse('CEN')->id,
            'items' => [['productId' => $pork->id, 'quantity' => 12.5]],
        ])->assertCreated()->assertJsonPath('toWarehouse', 'Central branch');

        $this->assertSame(57.5, $this->stock('MEAT-002', 'MAIN'));
        $this->assertSame(42.5, $this->stock('MEAT-002', 'CEN'));
    }

    public function test_issue_needs_a_different_destination(): void
    {
        $main = $this->warehouse('MAIN')->id;
        $this->postJson('/api/v1/stock/issues', [
            'warehouseId' => $main, 'toWarehouseId' => $main,
            'items' => [['productId' => $this->product('MEAT-002')->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('toWarehouseId');
    }

    public function test_adjustment_adds_and_subtracts(): void
    {
        $catfish = $this->product('FISH-001');
        $this->postJson('/api/v1/stock/adjustments', [
            'warehouseId' => $this->warehouse('CEN')->id,
            'items' => [['productId' => $catfish->id, 'quantity' => 4, 'type' => 'subtraction', 'description' => 'Spoiled']],
        ])->assertCreated()->assertJsonPath('items.0.description', 'Spoiled');

        $this->assertSame(26.0, $this->stock('FISH-001', 'CEN'));
    }

    public function test_combo_products_move_their_components(): void
    {
        $beef = $this->product('MEAT-001');
        $pork = $this->product('MEAT-002');
        $combo = $this->postJson('/api/v1/products', [
            'sku' => 'COMBO-1', 'name' => 'BBQ pack', 'type' => 'combo', 'price' => 50000,
            'comboItems' => [['productId' => $beef->id, 'quantity' => 1], ['productId' => $pork->id, 'quantity' => 0.5]],
        ])->assertCreated()->json('id');

        $this->openRegister();
        $this->postJson('/api/v1/sales', [
            'items' => [['productId' => $combo, 'quantity' => 2, 'price' => 50000]],
            'payment' => ['method' => 'Cash', 'tenderedRiel' => 100000],
        ])->assertCreated();

        $this->assertSame(28.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertSame(29.0, $this->stock('MEAT-002', 'CEN'));
    }

    public function test_price_changes_are_recorded(): void
    {
        $beef = $this->product('MEAT-001');
        $this->patchJson("/api/v1/products/{$beef->id}", ['price' => 34000])->assertOk()->assertJsonPath('price', 34000);

        $this->getJson("/api/v1/products/{$beef->id}/price-history")
            ->assertJsonPath('rows.0.oldPrice', 32000)->assertJsonPath('rows.0.newPrice', 34000);
    }

    public function test_product_list_shows_stock_of_one_warehouse(): void
    {
        $central = $this->warehouse('CEN')->id;
        $row = $this->getJson("/api/v1/products?warehouseId={$central}&search=MEAT-001")->json('rows.0');

        $this->assertEquals(30, $row['quantity']);
        // Per-warehouse map keeps the warehouse ids unchanged.
        $this->assertEquals(30, $row['stock'][$central]);
    }
}
