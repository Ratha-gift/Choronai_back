<?php

namespace Tests\Feature;

use App\Models\AuditLog;

/** Who created, changed and deleted each record (App\Models\Concerns\Audited). */
class AuditTest extends ApiTestCase
{
    public function test_seeded_data_has_no_log(): void
    {
        $this->assertSame(0, AuditLog::count());
    }

    public function test_create_update_delete_are_logged_with_the_user(): void
    {
        $admin = $this->actingAsUser('admin021');
        $id = $this->postJson('/api/v1/brands', ['name' => 'Kampot'])->assertCreated()->json('id');
        $this->patchJson("/api/v1/brands/{$id}", ['name' => 'Kampot Farm'])->assertOk();
        $this->deleteJson("/api/v1/brands/{$id}")->assertNoContent();

        $rows = AuditLog::where('entity_id', $id)->orderBy('id')->get();
        $this->assertSame(['create', 'update', 'delete'], $rows->pluck('action')->all());
        $this->assertSame([$admin->id], $rows->pluck('user_id')->unique()->values()->all());
        $this->assertSame(['name' => 'Kampot'], $rows[1]->before);
        $this->assertSame(['name' => 'Kampot Farm'], $rows[1]->after);
    }

    public function test_product_history_names_the_user_and_hides_cost(): void
    {
        $this->actingAsUser('admin021');
        $product = $this->product('MEAT-001');
        $this->patchJson("/api/v1/products/{$product->id}", ['cost' => 12345, 'name' => 'Beef'])->assertOk();

        $history = $this->getJson("/api/v1/audit/history/{$product->id}")->assertOk()->json();
        $this->assertSame('update', $history[0]['action']);
        $this->assertSame('admin021', $history[0]['username']);
        $this->assertSame('Beef', $history[0]['after']['name']);
        $this->assertArrayHasKey('cost', $history[0]['after']);

        $this->actingAsUser('cashier01');
        $cashier = $this->getJson("/api/v1/audit/history/{$product->id}");
        if ($cashier->status() === 200) {
            $this->assertArrayNotHasKey('cost', $cashier->json('0.after'));
        } else {
            $cashier->assertForbidden();
        }
    }

    public function test_password_change_is_logged_without_the_password(): void
    {
        $admin = $this->actingAsUser('admin021');
        $cashier = \App\Models\User::where('username', 'cashier01')->first();
        $this->patchJson("/api/v1/users/{$cashier->id}", ['password' => 'newpass123'])->assertOk();

        $row = AuditLog::where('entity_id', $cashier->id)->latest('id')->first();
        $this->assertSame('(changed)', $row->after['password']);
        $this->assertStringNotContainsString('newpass123', json_encode($row->after));
        $this->assertStringNotContainsString('$2y$', json_encode([$row->before, $row->after]));
        $this->assertSame($admin->id, $row->user_id);
    }

    public function test_activity_list_needs_permission(): void
    {
        $this->actingAsUser('admin021');
        $this->postJson('/api/v1/brands', ['name' => 'Kampot'])->assertCreated();
        $this->getJson('/api/v1/audit/activity')->assertOk()->assertJsonPath('0.action', 'create');

        $this->actingAsUser('cashier01');
        $this->getJson('/api/v1/audit/activity')->assertForbidden();
    }
}
