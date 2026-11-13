<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\MemberCard;
use Illuminate\Support\Str;

class PosTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser('cashier01');
    }

    public function test_selling_needs_an_open_register(): void
    {
        $this->sell('MEAT-001', 1)->assertStatus(409);
    }

    public function test_only_one_open_register_per_cashier(): void
    {
        $this->openRegister();
        $this->postJson('/api/v1/registers/open', ['warehouseId' => $this->warehouse('CEN')->id, 'cashInHand' => 0])->assertStatus(409);
    }

    public function test_sale_takes_branch_stock_and_awards_points(): void
    {
        $this->openRegister();
        $sale = $this->sell('MEAT-001', 2.5, ['memberCardNo' => '00001'])->assertCreated();

        $sale->assertJsonPath('reference', 'SALE/'.now()->format('Y/m').'/0001')
            ->assertJsonPath('grandTotal', 80000)->assertJsonPath('paid', 80000)
            ->assertJsonPath('paymentStatus', 'paid')->assertJsonPath('customer', 'Chan Sophea');
        $this->assertSame(27.5, $this->stock('MEAT-001', 'CEN'));
        $this->assertSame(70.0, $this->stock('MEAT-001', 'MAIN'));

        // 80,000 riel / 10,000 per point = 8 points.
        $card = MemberCard::where('card_no', '00001')->first();
        $this->assertSame(868, $card->award_points);
        $this->assertSame(2230000, $card->spent);
    }

    public function test_overselling_follows_the_setting(): void
    {
        $this->openRegister();
        $this->sell('FISH-001', 31)->assertCreated();

        $this->actingAsUser('admin021');
        $this->putJson('/api/v1/settings/system', ['overSelling' => 'no'])->assertOk();
        $this->actingAsUser('cashier01');
        $this->sell('FISH-001', 1)->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_closing_calculates_expected_cash_on_the_server(): void
    {
        $register = $this->openRegister(cash: 50000);
        $this->sell('MEAT-001', 2.5)->assertCreated(); // 80,000 cash
        $this->sell('MEAT-002', 1, ['payment' => ['method' => 'ABA / KHQR', 'tenderedRiel' => 26000]])->assertCreated();

        $this->postJson("/api/v1/registers/{$register}/close", ['countedRiel' => 120000])->assertOk()
            ->assertJsonPath('summary.expectedCash', 130000)
            ->assertJsonPath('summary.byMethod.ABA / KHQR', 26000)
            ->assertJsonPath('register.difference', -10000)
            ->assertJsonPath('register.invoices', 2)
            ->assertJsonPath('register.status', 'closed');
    }

    public function test_pin_is_hashed_and_checked(): void
    {
        $this->actingAsUser('admin021');
        $this->putJson('/api/v1/settings/pos', ['pinCode' => '4321'])->assertOk()
            ->assertJsonPath('hasPin', true)->assertJsonMissingPath('pinCode')->assertJsonMissingPath('pinHash');

        $this->actingAsUser('cashier01');
        $this->postJson('/api/v1/pos/verify-pin', ['pin' => '0000'])->assertJsonPath('ok', false);
        $this->postJson('/api/v1/pos/verify-pin', ['pin' => '4321'])->assertJsonPath('ok', true);
    }

    public function test_saving_pos_settings_with_an_empty_pin_keeps_the_pin(): void
    {
        $this->actingAsUser('admin021');
        $this->putJson('/api/v1/settings/pos', ['pinCode' => '4321'])->assertOk();
        // What the settings page sends: every field, the PIN field empty.
        $this->putJson('/api/v1/settings/pos', ['pinCode' => '', 'displayProducts' => 40])
            ->assertOk()->assertJsonPath('hasPin', true);
        $this->putJson('/api/v1/settings/pos', ['pinCode' => null])->assertJsonPath('hasPin', true);
        $this->postJson('/api/v1/pos/verify-pin', ['pin' => '4321'])->assertJsonPath('ok', true);

        $this->putJson('/api/v1/settings/pos', ['removePin' => true])->assertJsonPath('hasPin', false);
        $this->assertArrayNotHasKey('remove_pin', \App\Support\Settings::all('pos'));
    }

    public function test_a_paid_sale_can_be_deleted_and_restores_stock_and_points(): void
    {
        $card = MemberCard::where('card_no', '00001')->first();
        $points = $card->award_points;
        $spent = $card->spent;

        $this->openRegister();
        $id = $this->sell('MEAT-001', 2.5, ['memberCardNo' => '00001'])->json('id');
        $this->assertSame(27.5, $this->stock('MEAT-001', 'CEN'));

        $this->actingAsUser('admin021');
        $this->deleteJson("/api/v1/sales/{$id}")->assertNoContent();

        // Stock, points and the sale itself are back to how they started.
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertNull(\App\Models\Sale::find($id));
        $card->refresh();
        $this->assertSame($points, $card->award_points);
        $this->assertSame($spent, $card->spent);
    }

    public function test_deleting_a_returned_sale_cascades_and_rebalances_stock(): void
    {
        $card = MemberCard::where('card_no', '00001')->first();
        $points = $card->award_points;

        $this->openRegister();
        $beef = $this->product('MEAT-001');
        $id = $this->sell('MEAT-001', 3, ['memberCardNo' => '00001'])->json('id');

        // Return 1 kg against the sale (restock): 30 - 3 + 1 = 28.
        $this->postJson('/api/v1/sale-returns', [
            'sale_id' => $id, 'warehouse_id' => $this->warehouse('CEN')->id,
            'items' => [['product_id' => $beef->id, 'quantity' => 1, 'price' => $beef->price]],
        ])->assertCreated();
        $this->assertSame(28.0, $this->stock('MEAT-001', 'CEN'));

        $this->actingAsUser('admin021');
        $this->deleteJson("/api/v1/sales/{$id}")->assertNoContent();

        // The sale and its return are gone and the stock is rebalanced to 30.
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertNull(\App\Models\Sale::find($id));
        $this->assertSame(0, \App\Models\SaleReturn::where('sale_id', $id)->count());
        // Earn and the return's reversal both undone: points back to the start.
        $this->assertSame($points, $card->refresh()->award_points);
    }

    public function test_an_unpaid_sale_can_be_deleted_and_puts_stock_back(): void
    {
        $this->openRegister();
        // A credit sale: nothing tendered, so paid is 0.
        $id = $this->sell('MEAT-001', 3, ['payment' => ['method' => 'Cash', 'tenderedRiel' => 0]])->json('id');
        $this->assertSame(27.0, $this->stock('MEAT-001', 'CEN'));

        $this->actingAsUser('admin021');
        $this->deleteJson("/api/v1/sales/{$id}")->assertNoContent();
        // The stock is back and the sale is gone.
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertNull(\App\Models\Sale::find($id));
    }

    public function test_offline_sync_is_idempotent_and_flags_negative_stock(): void
    {
        $device = Device::create(['name' => 'Counter 1', 'warehouse_id' => $this->warehouse('CEN')->id, 'reference_prefix' => 'A1']);
        $register = (string) Str::uuid();
        $sale = (string) Str::uuid();
        $catfish = $this->product('FISH-001');
        $body = [
            'deviceId' => $device->id,
            'registers' => [['id' => $register, 'cashInHand' => 20000, 'openedAt' => now()->subHours(8)->toIso8601String(), 'closedAt' => now()->toIso8601String(), 'countedRiel' => 20000]],
            'sales' => [[
                'id' => $sale, 'registerId' => $register, 'reference' => 'SALE/A1/2026/0001', 'date' => now()->subHours(2)->toIso8601String(),
                'items' => [['productId' => $catfish->id, 'quantity' => 35, 'price' => 14000]],
                'payment' => ['method' => 'Cash', 'tenderedRiel' => 490000],
            ]],
        ];

        $this->postJson('/api/v1/pos/sync', $body)->assertOk()
            ->assertJsonPath('sales.0.status', 'saved')
            ->assertJsonPath('sales.0.reference', 'SALE/A1/2026/0001')
            ->assertJsonPath('sales.0.needsReview', true);
        $this->postJson('/api/v1/pos/sync', $body)->assertOk()->assertJsonPath('sales.0.status', 'duplicate');

        $this->assertSame(-5.0, $this->stock('FISH-001', 'CEN'));
        $this->assertDatabaseHas('registers', ['id' => $register, 'status' => 'closed', 'expected_cash' => 510000, 'difference' => -490000]);
    }
}
