<?php

namespace Tests\Feature;

class StockTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser('admin021');
    }

    public function test_posted_receive_adds_stock_and_is_immutable(): void
    {
        $beef = $this->product('MEAT-001');
        $body = fn ($quantity) => ['warehouseId' => $this->warehouse('MAIN')->id, 'items' => [['productId' => $beef->id, 'quantity' => $quantity, 'price' => 25000]]];

        $id = $this->postJson('/api/v1/stock/receives', $body(20))->assertCreated()
            ->assertJsonPath('items.0.code', 'MEAT-001')->assertJsonPath('total', 500000)->json('id');
        $this->assertSame(90.0, $this->stock('MEAT-001', 'MAIN'));

        $this->putJson("/api/v1/stock/receives/{$id}", $body(25))->assertStatus(409);
        $this->assertSame(90.0, $this->stock('MEAT-001', 'MAIN'));

        $this->deleteJson("/api/v1/stock/receives/{$id}")->assertStatus(409);
        $this->assertSame(90.0, $this->stock('MEAT-001', 'MAIN'));
    }

    public function test_issue_moves_stock_between_warehouses(): void
    {
        $pork = $this->product('MEAT-002');
        $id = $this->postJson('/api/v1/stock/issues', [
            'warehouseId' => $this->warehouse('MAIN')->id,
            'toWarehouseId' => $this->warehouse('CEN')->id,
            'items' => [['productId' => $pork->id, 'quantity' => 12.5]],
        ])->assertCreated()->assertJsonPath('toWarehouse', 'Central branch')->json('id');

        $this->assertSame(57.5, $this->stock('MEAT-002', 'MAIN'));
        $this->assertSame(30.0, $this->stock('MEAT-002', 'CEN'));
        $receipt = ['id' => (string) \Illuminate\Support\Str::uuid(), 'items' => [['productId' => $pork->id, 'quantity' => 5]]];
        $this->postJson("/api/v1/stock/issues/{$id}/receive", $receipt)->assertCreated();
        $this->postJson("/api/v1/stock/issues/{$id}/receive", $receipt)->assertOk();
        $this->assertSame(35.0, $this->stock('MEAT-002', 'CEN'));
        $this->getJson("/api/v1/stock/issues/{$id}")->assertJsonPath('status', 'partially_received');
        $receipt['id'] = (string) \Illuminate\Support\Str::uuid();
        $receipt['items'][0]['quantity'] = 8;
        $this->postJson("/api/v1/stock/issues/{$id}/receive", $receipt)->assertStatus(422);
        $receipt['items'][0]['quantity'] = 7.5;
        $this->postJson("/api/v1/stock/issues/{$id}/receive", $receipt)->assertCreated();
        $this->assertSame(42.5, $this->stock('MEAT-002', 'CEN'));
        $this->getJson("/api/v1/stock/issues/{$id}")->assertJsonPath('status', 'received');
    }

    public function test_short_transfer_sends_the_rest_back(): void
    {
        $pork = $this->product('MEAT-002');
        $id = $this->postJson('/api/v1/stock/issues', [
            'warehouseId' => $this->warehouse('MAIN')->id,
            'toWarehouseId' => $this->warehouse('CEN')->id,
            'items' => [['productId' => $pork->id, 'quantity' => 6, 'price' => 20000]],
        ])->assertCreated()->json('id');
        $this->assertSame(64.0, $this->stock('MEAT-002', 'MAIN'));
        $this->postJson("/api/v1/stock/issues/{$id}/receive", [
            'id' => (string) \Illuminate\Support\Str::uuid(), 'items' => [['productId' => $pork->id, 'quantity' => 4]],
        ])->assertCreated();

        $short = ['id' => (string) \Illuminate\Support\Str::uuid()];
        $this->postJson("/api/v1/stock/issues/{$id}/short", $short)->assertStatus(422);
        $short['note'] = 'Only 4 kg arrived';
        $this->postJson("/api/v1/stock/issues/{$id}/short", $short)->assertCreated()
            ->assertJsonPath('shortOf', $id)->assertJsonPath('warehouse', 'Main warehouse')
            ->assertJsonPath('items.0.quantity', 2)->assertJsonPath('total', 40000);
        // A retry does not move stock twice.
        $this->postJson("/api/v1/stock/issues/{$id}/short", $short)->assertOk();
        $this->assertSame(66.0, $this->stock('MEAT-002', 'MAIN'));
        $this->assertSame(34.0, $this->stock('MEAT-002', 'CEN'));
        $this->getJson("/api/v1/stock/issues/{$id}")->assertJsonPath('status', 'short_received');

        // Closed: no more receipts and no second report.
        $this->postJson("/api/v1/stock/issues/{$id}/receive", [
            'id' => (string) \Illuminate\Support\Str::uuid(), 'items' => [['productId' => $pork->id, 'quantity' => 1]],
        ])->assertStatus(409);
        $this->postJson("/api/v1/stock/issues/{$id}/short", ['id' => (string) \Illuminate\Support\Str::uuid(), 'note' => 'again'])
            ->assertStatus(409);
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
        $id = $this->postJson('/api/v1/stock/adjustments', [
            'warehouseId' => $this->warehouse('CEN')->id,
            'items' => [['productId' => $catfish->id, 'quantity' => 4, 'type' => 'subtraction', 'description' => 'Spoiled']],
        ])->assertCreated()->assertJsonPath('items.0.description', 'Spoiled')->json('id');

        $this->assertSame(30.0, $this->stock('FISH-001', 'CEN'));
        $this->actingAsUser('cashier01');
        $this->postJson("/api/v1/stock/adjustments/{$id}/approve")->assertForbidden();
        $this->actingAsUser('admin021');
        $this->postJson("/api/v1/stock/adjustments/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/stock/adjustments/{$id}/approve")->assertOk();
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
