<?php

namespace Tests\Feature;

use App\Models\User;

/** Staff kept to their branch cannot use another branch's warehouse. */
class BranchLockTest extends ApiTestCase
{
    private function cashierOfCentral(): User
    {
        User::where('username', 'cashier01')->update(['branch' => 'Central']);

        return $this->actingAsUser('cashier01');
    }

    public function test_register_opens_only_in_the_own_branch(): void
    {
        $this->cashierOfCentral();
        $this->postJson('/api/v1/registers/open', ['warehouseId' => $this->warehouse('MAIN')->id, 'cashInHand' => 0])
            ->assertForbidden();
        $this->postJson('/api/v1/registers/open', ['warehouseId' => $this->warehouse('CEN')->id, 'cashInHand' => 0])
            ->assertCreated();
    }

    public function test_user_without_branch_may_use_any_warehouse(): void
    {
        $user = $this->actingAsUser('cashier01');
        $this->assertTrue($user->mayUseWarehouse($this->warehouse('MAIN')->id));
        $this->assertTrue($user->mayUseWarehouse($this->warehouse('CEN')->id));
    }

    public function test_owner_with_a_branch_still_sees_all(): void
    {
        User::where('username', 'admin021')->update(['branch' => 'Central']);
        $admin = $this->actingAsUser('admin021');
        $this->assertTrue($admin->mayUseWarehouse($this->warehouse('MAIN')->id));
    }
}
