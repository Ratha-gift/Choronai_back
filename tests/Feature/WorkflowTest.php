<?php

namespace Tests\Feature;

use App\Models\MemberCard;
use App\Models\ProductReward;
use App\Models\RewardEntry;
use Illuminate\Support\Str;

class WorkflowTest extends ApiTestCase
{
    public function test_redemption_is_atomic_and_retry_safe(): void
    {
        $this->actingAsUser('admin021');
        $card = MemberCard::where('card_no', '00001')->firstOrFail();
        $reward = ProductReward::create(['code' => 'BEEF', 'name' => 'Beef reward', 'points' => 10,
            'product_id' => $this->product('MEAT-001')->id, 'quantity' => 0.5]);
        $body = ['id' => (string) Str::uuid(), 'rewardId' => $reward->id, 'warehouseId' => $this->warehouse('CEN')->id];
        $url = "/api/v1/member-cards/{$card->id}/redeem";
        $this->postJson($url, $body)->assertCreated();
        $this->postJson($url, $body)->assertOk();
        $this->assertSame(850, $card->refresh()->award_points);
        $this->assertSame(29.5, $this->stock('MEAT-001', 'CEN'));
        $this->assertSame(1, RewardEntry::count());
        $reward->update(['quantity' => 100]);
        $body['id'] = (string) Str::uuid();
        $this->postJson($url, $body)->assertStatus(422);
        $this->assertSame(850, $card->refresh()->award_points);
        $this->assertSame(1, RewardEntry::count());
        $this->actingAsUser('cashier01');
        $this->postJson($url, $body)->assertForbidden();
    }

    public function test_refunds_reverse_points_and_separate_cash_from_card(): void
    {
        $this->actingAsUser('admin021');
        $register = $this->openRegister(cash: 50000);
        $sale = $this->sell('MEAT-001', 2.5, ['memberCardNo' => '00001'])->assertCreated()->json('id');
        $body = ['saleId' => $sale, 'warehouseId' => $this->warehouse('CEN')->id, 'refundMethod' => 'Card',
            'restock' => false, 'items' => [['productId' => $this->product('MEAT-001')->id, 'quantity' => 1, 'price' => 32000]]];
        $this->postJson('/api/v1/sale-returns', $body)->assertCreated();
        $this->assertSame(27.5, $this->stock('MEAT-001', 'CEN'));
        $this->assertSame(865, MemberCard::where('card_no', '00001')->value('award_points'));
        $this->getJson("/api/v1/registers/{$register}/summary")->assertJsonPath('expectedCash', 130000)->assertJsonPath('cashRefunds', 0);
        $body['items'][0]['quantity'] = 2;
        $this->postJson('/api/v1/sale-returns', $body)->assertStatus(422);
        $body['items'][0]['quantity'] = 1.5;
        $body['refundMethod'] = 'Cash';
        $body['restock'] = true;
        $this->postJson('/api/v1/sale-returns', $body)->assertCreated();
        $this->assertSame(860, MemberCard::where('card_no', '00001')->value('award_points'));
        $this->getJson("/api/v1/registers/{$register}/summary")->assertJsonPath('expectedCash', 82000);
        $this->postJson("/api/v1/registers/{$register}/close", ['countedRiel' => 82000])->assertOk();
        $this->getJson("/api/v1/registers/{$register}/summary")->assertJsonPath('expectedCash', 82000);
        $this->postJson('/api/v1/sale-returns', $body)->assertStatus(409);
    }

    public function test_expired_card_cannot_earn(): void
    {
        $this->actingAsUser('admin021');
        $this->openRegister();
        MemberCard::where('card_no', '00001')->update(['expiry' => now()->subDay()]);
        $this->sell('MEAT-001', 1, ['memberCardNo' => '00001'])->assertStatus(422);
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
    }
}
