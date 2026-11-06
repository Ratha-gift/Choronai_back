<?php

namespace Tests\Feature;

use App\Models\Sale;
use Illuminate\Support\Facades\Storage;

class ImportFrontendTest extends ApiTestCase
{
    public function test_old_frontend_data_is_imported_and_old_passwords_work(): void
    {
        Storage::fake('public');
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
        $export = [
            'choronai-userGroups-v1' => [['id' => 'user-group-1', 'name' => 'owner', 'description' => 'Owner'],
                ['id' => 'user-group-3', 'name' => 'sale', 'description' => 'Sale']],
            'choronai-permissions-v1' => ['user-group-3' => ['sales.pos' => true, 'products.view' => true, 'sales.view' => false]],
            'choronai-users-v1' => [['id' => 'user-1', 'firstName' => 'Mony', 'username' => 'mony', 'group' => 'Sale',
                'branch' => 'Central', 'passwordHash' => hash('sha256', 'choronai:mony:mony-pass')]],
            'choronai-branches-v1' => [['id' => 'branch-1', 'name' => 'Central', 'warehouse' => 'Main warehouse']],
            'choronai-warehouses-v1' => [['id' => 'warehouse-1', 'code' => 'MAIN', 'name' => 'Main warehouse']],
            'choronai-customers-v1' => [['id' => 'customer-1', 'code' => 'CUS0001', 'name' => 'Walk-in Customer', 'phone' => '-']],
            'choronai-next-catalog-v1' => [['id' => 'sample-1', 'sku' => 'MEAT-001', 'name' => 'Fresh beef', 'category' => 'Meat',
                'unit' => 'kg', 'price' => 32000, 'image' => $png, 'stockByLocation' => ['["branch-1","warehouse-1"]' => 11]]],
            'choronai-sales-v1' => [['id' => 'mvv7t2hf-v5i152', 'reference' => 'SALE/2026/10/0023', 'date' => '2026-10-30T16:57:00.000Z',
                'warehouse' => 'Main warehouse', 'customer' => 'Walk-in Customer', 'createdBy' => 'mony', 'paymentMethod' => 'Cash',
                'items' => [['productId' => 'sample-1', 'code' => 'MEAT-001', 'name' => 'Fresh beef', 'quantity' => 2, 'price' => 32000]],
                'total' => 64000, 'grandTotal' => 64000, 'paid' => 64000]],
            'choronai-system-settings-v1' => ['siteName' => 'Imported shop', 'referenceReset' => 'year'],
        ];
        $file = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($file, json_encode($export));

        $this->artisan('choronai:import-frontend', ['file' => $file, '--fresh' => true])->assertSuccessful();

        $this->assertDatabaseCount('products', 1);
        $this->assertSame(11.0, $this->stock('MEAT-001', 'MAIN'));
        $this->assertSame(1, Sale::count());
        $this->assertDatabaseHas('payments', ['method' => 'Cash', 'amount' => 64000]);
        $this->assertDatabaseHas('warehouses', ['code' => 'MAIN', 'type' => 'branch']);
        $image = $this->product('MEAT-001')->image;
        $this->assertStringStartsWith('/api/backend/images/products/', $image);
        Storage::disk('public')->assertExists(substr($image, strlen('/api/backend/images/')));

        // The old frontend password still works, once, then it is bcrypt.
        $this->postJson('/api/v1/auth/login', ['username' => 'mony', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['username' => 'mony', 'password' => 'mony-pass'])->assertOk()
            ->assertJsonPath('user.branch', 'Central');
        $this->assertDatabaseHas('users', ['username' => 'mony', 'legacy_password_hash' => null]);

        // The next document number follows the imported ones.
        $this->assertDatabaseHas('reference_counters', ['prefix' => 'SALE', 'period' => '2026', 'last_number' => 23]);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'export');
        file_put_contents($file, json_encode(['choronai-next-catalog-v1' => [], 'choronai-userGroups-v1' => [['id' => 'g', 'name' => 'owner']]]));
        $this->artisan('choronai:import-frontend', ['file' => $file, '--fresh' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('products', ['sku' => 'MEAT-001']); // the seeded data is still there
    }
}
