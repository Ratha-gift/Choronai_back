<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserGroup;

/**
 * A user who may manage users (Admin) must not give anyone, including
 * themselves, more rights than they have, nor take over an Owner account.
 */
class EscalationTest extends ApiTestCase
{
    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $group = UserGroup::create(['name' => 'admin', 'description' => 'Admin', 'permissions' => [
            'users.view', 'users.add', 'users.edit', 'users.delete', 'products.view', 'sales.pos',
        ]]);
        $this->admin = User::create(['first_name' => 'Ad', 'username' => 'admin2', 'password' => 'secret123', 'group_id' => $group->id]);
        $this->owner = User::where('username', 'admin021')->firstOrFail();
        $this->actingAsUser('admin2');
    }

    private function ownerGroup(): UserGroup
    {
        return UserGroup::where('is_owner', true)->firstOrFail();
    }

    public function test_admin_cannot_create_an_owner(): void
    {
        $this->postJson('/api/v1/users', [
            'firstName' => 'X', 'username' => 'sneaky', 'password' => 'secret123', 'groupId' => $this->ownerGroup()->id,
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);
    }

    public function test_admin_cannot_make_itself_owner(): void
    {
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['groupId' => $this->ownerGroup()->id])->assertForbidden();
        $this->assertFalse($this->admin->fresh()->isOwner());
    }

    public function test_admin_cannot_reset_or_delete_the_owner(): void
    {
        $this->patchJson("/api/v1/users/{$this->owner->id}", ['password' => 'hacked123'])->assertForbidden();
        $this->patchJson("/api/v1/users/{$this->owner->id}", ['status' => 'inactive'])->assertForbidden();
        $this->deleteJson("/api/v1/users/{$this->owner->id}")->assertForbidden();
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_admin_cannot_grant_rights_it_does_not_have(): void
    {
        $own = $this->admin->group;
        $this->putJson("/api/v1/user-groups/{$own->id}/permissions", [
            'permissions' => [...$own->permissions, 'settings.system'],
        ])->assertForbidden();
        $this->assertNotContains('settings.system', $own->fresh()->permissions);

        $this->patchJson("/api/v1/user-groups/{$this->ownerGroup()->id}", ['name' => 'nobody'])->assertForbidden();
    }

    public function test_admin_can_still_manage_weaker_users(): void
    {
        $sale = UserGroup::create(['name' => 'helper', 'permissions' => ['sales.pos']]);
        $id = $this->postJson('/api/v1/users', [
            'firstName' => 'Helper', 'username' => 'helper1', 'password' => 'secret123', 'groupId' => $sale->id,
        ])->assertCreated()->json('id');
        $this->patchJson("/api/v1/users/{$id}", ['password' => 'newpass123'])->assertOk();
        $this->putJson("/api/v1/user-groups/{$sale->id}/permissions", ['permissions' => ['sales.pos', 'products.view']])
            ->assertOk();
    }

    public function test_owner_can_do_everything(): void
    {
        $this->actingAsUser('admin021');
        $this->patchJson("/api/v1/users/{$this->admin->id}", ['groupId' => $this->ownerGroup()->id])->assertOk();
    }
}
