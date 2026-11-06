<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;

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

    public function test_email_address_works_as_username(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'ADMIN@example.com', 'password' => 'admin123'])
            ->assertOk()->assertJsonPath('user.username', 'admin021');
    }

    public function test_sign_in_length_depends_on_the_group(): void
    {
        $this->travelTo(now()->startOfMinute());
        $at = fn (string $user, string $pass, bool $remember) => Carbon::parse(
            $this->postJson('/api/v1/auth/login', ['username' => $user, 'password' => $pass, 'remember' => $remember])
                ->assertOk()->json('expiresAt'))->diffInMinutes(now(), absolute: true);

        // Defaults: managers 7 days, cashiers 8 hours; at most 12 h without "Keep me signed in".
        $this->assertEqualsWithDelta(168 * 60, $at('admin021', 'admin123', true), 1);
        $this->assertEqualsWithDelta(12 * 60, $at('admin021', 'admin123', false), 1);
        $this->assertEqualsWithDelta(8 * 60, $at('cashier01', 'cashier123', true), 1);

        // Changed in System Settings.
        Settings::put('system', ['staff_session_hours' => 4]);
        $this->assertEqualsWithDelta(4 * 60, $at('cashier01', 'cashier123', true), 1);
    }

    public function test_sign_in_history_records_success_failure_and_address(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => 'cashier01', 'password' => 'nope'], ['X-Client-IP' => '203.0.113.7'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['username' => 'cashier01', 'password' => 'cashier123'], ['X-Client-IP' => '203.0.113.7'])->assertOk();

        $this->actingAsUser('cashier01');
        $this->getJson('/api/v1/audit/logins')->assertForbidden(); // a cashier cannot read it

        $this->actingAsUser('admin021');
        $rows = $this->getJson('/api/v1/audit/logins')->assertOk()->json();
        $this->assertSame(['login', 'login_failed'], array_column(array_slice($rows, 0, 2), 'action'));
        $this->assertSame('203.0.113.7', $rows[0]['ip']);
        $this->assertSame('cashier01', $rows[1]['username']);
    }
}
