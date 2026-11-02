<?php

namespace Tests\Feature;

use App\Models\User;

class AuthTest extends ApiTestCase
{
    public function test_login_returns_a_token_and_camel_case_user(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['username' => 'Admin021', 'password' => 'admin123'])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('admin021', $response->json('user.username'));
        $this->assertSame(['*'], $response->json('user.permissions'));
        $this->assertArrayHasKey('firstName', $response->json('user'));

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('group', 'Owner');
    }

    public function test_wrong_password_and_inactive_users_are_refused(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'admin021', 'password' => 'nope'])->assertStatus(422);

        User::where('username', 'cashier01')->update(['status' => 'inactive']);
        $this->postJson('/api/v1/auth/login', ['username' => 'cashier01', 'password' => 'cashier123'])->assertForbidden();
    }

    public function test_requests_without_a_token_get_401(): void
    {
        $this->getJson('/api/v1/products')->assertUnauthorized();
    }

    public function test_permissions_come_from_the_group(): void
    {
        $this->actingAsUser('cashier01');

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->postJson('/api/v1/stock/receives', [])->assertForbidden();
        $this->putJson('/api/v1/settings/system', ['overSelling' => 'no'])->assertForbidden();
        // Cashiers do not see the cost price.
        $this->assertArrayNotHasKey('cost', $this->getJson('/api/v1/products')->json('rows.0'));
    }

    public function test_the_last_owner_cannot_be_disabled(): void
    {
        $admin = $this->actingAsUser('admin021');

        $this->patchJson("/api/v1/users/{$admin->id}", ['status' => 'inactive'])->assertStatus(422);
    }
}
