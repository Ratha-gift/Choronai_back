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

    public function test_posted_sales_cannot_be_deleted(): void
    {
        $this->openRegister();
        $id = $this->sell('MEAT-001', 3)->json('id');

        $this->actingAsUser('admin021');
        $this->deleteJson("/api/v1/sales/{$id}")->assertStatus(409);
        $this->assertSame(27.0, $this->stock('MEAT-001', 'CEN'));
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
