<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The Next.js frontend reads every collection with GET /frontend/workspace
 * and sends whole changed collections back with POST /frontend/workspace.
 * These tests send rows shaped like the frontend forms create them.
 */
class WorkspaceTest extends ApiTestCase
{
    private function snapshot(): array
    {
        return $this->getJson('/api/v1/frontend/workspace')->assertOk()->json();
    }

    /** Applies $change to one collection and commits it like the browser does. */
    private function commit(string $key, callable $change, int $status = 200): array
    {
        $snapshot = $this->snapshot();
        $rows = $change($snapshot['data'][$key]);
        $response = $this->postJson('/api/v1/frontend/workspace', [
            'id' => (string) Str::uuid(), 'revision' => $snapshot['revision'], 'changes' => [$key => $rows],
        ]);
        $response->assertStatus($status);

        return $response->json() ?? [];
    }

    public function test_snapshot_has_the_frontend_collections(): void
    {
        $this->actingAsUser('admin021');
        $data = $this->snapshot()['data'];
        foreach (['choronai-next-catalog-v1', 'choronai-customers-v1', 'choronai-branches-v1', 'choronai-warehouses-v1',
            'choronai-sales-v1', 'choronai-stockReceives-v1', 'choronai-adjustments-v1', 'choronai-users-v1',
            'choronai-system-settings-v1', 'choronai-pos-settings-v1', 'choronai-permissions-v1'] as $key) {
            $this->assertArrayHasKey($key, $data, $key);
        }
        $beef = collect($data['choronai-next-catalog-v1'])->firstWhere('sku', 'MEAT-001');
        $this->assertNotEmpty($beef['stockByLocation']);
    }

    public function test_poll_with_current_revision_is_unchanged(): void
    {
        $this->actingAsUser('admin021');
        $revision = $this->snapshot()['revision'];
        $this->getJson("/api/v1/frontend/workspace?revision={$revision}")->assertOk()
            ->assertJson(['unchanged' => true, 'revision' => $revision])->assertJsonMissingPath('data');

        $this->commit('choronai-customers-v1', fn ($rows) => [...$rows, [
            'id' => (string) Str::uuid(), 'name' => 'Sokha', 'phone' => '011 222 333', 'company' => '', 'email' => '',
            'customerGroup' => 'General', 'priceGroup' => '', 'address' => '', 'code' => '', 'awardPoints' => 0,
        ]]);
        $fresh = $this->getJson("/api/v1/frontend/workspace?revision={$revision}")->assertOk()->json();
        $this->assertArrayNotHasKey('unchanged', $fresh);
        $this->assertSame($revision + 1, $fresh['revision']);
        $this->assertContains('Sokha', array_column($fresh['data']['choronai-customers-v1'], 'name'));
    }

    public function test_snapshot_does_not_query_once_per_document(): void
    {
        $this->actingAsUser('admin021');
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();
        try {
            $this->snapshot();
        } finally {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
        }
    }

    public function test_customer_added_in_the_browser_is_saved(): void
    {
        $this->actingAsUser('admin021');
        $id = (string) Str::uuid();
        $this->commit('choronai-customers-v1', fn ($rows) => [...$rows, [
            'id' => $id, 'name' => 'Dara', 'phone' => '012 345 678', 'company' => '', 'email' => '',
            'customerGroup' => 'General', 'priceGroup' => '', 'address' => '', 'code' => '', 'awardPoints' => 0,
        ]]);
        $this->assertDatabaseHas('customers', ['id' => $id, 'name' => 'Dara']);
    }

    public function test_branch_keeps_what_it_prints_on_invoices(): void
    {
        $this->actingAsUser('admin021');
        $this->commit('choronai-branches-v1', fn ($rows) => array_map(fn ($row) => [
            ...$row, 'address' => 'St 271', 'prefix' => 'INV8', 'invoiceFooter' => 'Thank you', 'logo' => '/api/backend/images/x.png',
        ], $rows));
        $branch = collect($this->snapshot()['data']['choronai-branches-v1'])->first();
        $this->assertSame('St 271', $branch['address']);
        $this->assertSame('INV8', $branch['prefix']);
        $this->assertSame('Thank you', $branch['invoiceFooter']);
        $this->assertSame('/api/backend/images/x.png', $branch['logo']);
    }

    public function test_stock_receive_and_adjustment_approval_move_stock(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $line = fn ($qty, $extra = []) => ['productId' => $beef->id, 'code' => 'MEAT-001', 'name' => 'Fresh beef',
            'unit' => 'kg', 'quantity' => $qty, 'price' => 30000, ...$extra];
        $receive = (string) Str::uuid();
        $this->commit('choronai-stockReceives-v1', fn ($rows) => [...$rows, [
            'id' => $receive, 'date' => now()->toIso8601String(), 'reference' => 'SRC/TEST/1', 'branch' => 'Central',
            'warehouse' => 'Central branch', 'items' => [$line(5)], 'status' => 'completed', 'createdBy' => 'admin021',
        ]]);
        $this->assertSame(35.0, $this->stock('MEAT-001', 'CEN'));

        $adjustment = (string) Str::uuid();
        $row = ['id' => $adjustment, 'date' => now()->toIso8601String(), 'reference' => 'QA/TEST/1', 'branch' => 'Central',
            'warehouse' => 'Central branch', 'status' => 'pending',
            'items' => [$line(2, ['type' => 'subtraction', 'reason' => 'spoiled'])]];
        $this->commit('choronai-adjustments-v1', fn ($rows) => [...$rows, $row]);
        $this->assertSame(35.0, $this->stock('MEAT-001', 'CEN'), 'pending adjustments do not move stock');

        $this->commit('choronai-adjustments-v1', fn ($rows) => collect($rows)
            ->map(fn ($r) => $r['id'] === $adjustment ? [...$r, 'status' => 'approved'] : $r)->all());
        $this->assertSame(33.0, $this->stock('MEAT-001', 'CEN'));
    }

    public function test_stale_revision_is_a_conflict(): void
    {
        $this->actingAsUser('admin021');
        $snapshot = $this->snapshot();
        $this->postJson('/api/v1/frontend/workspace', [
            'id' => (string) Str::uuid(), 'revision' => $snapshot['revision'] + 5,
            'changes' => ['choronai-customers-v1' => $snapshot['data']['choronai-customers-v1']],
        ])->assertStatus(409);
    }

    /** Like a browser commit, but several collections at once (one POS action). */
    private function commitMany(array $changes, int $status = 200): array
    {
        $snapshot = $this->snapshot();
        $payload = [];
        foreach ($changes as $key => $change) {
            $payload[$key] = $change($snapshot['data'][$key]);
        }
        $response = $this->postJson('/api/v1/frontend/workspace', [
            'id' => (string) Str::uuid(), 'revision' => $snapshot['revision'], 'changes' => $payload,
        ]);
        $response->assertStatus($status);

        return $response->json() ?? [];
    }

    public function test_pos_shift_sale_with_split_payment_and_close(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $register = (string) Str::uuid();
        $this->commit('choronai-registers-v1', fn ($rows) => [...$rows, [
            'id' => $register, 'username' => 'admin021', 'openedAt' => now()->toIso8601String(), 'status' => 'open',
            'warehouse' => 'Central branch', 'branch' => 'Central', 'cashInHand' => 40000, 'openNote' => '',
        ]]);
        $this->assertDatabaseHas('registers', ['id' => $register, 'status' => 'open']);

        $sale = (string) Str::uuid();
        $result = $this->commitMany([
            'choronai-sales-v1' => fn ($rows) => [...$rows, [
                'id' => $sale, 'date' => now()->toIso8601String(), 'reference' => 'SALE/TEST/1', 'branch' => 'Central',
                'warehouse' => 'Central branch', 'customer' => 'Walk-in Customer',
                'items' => [['productId' => $beef->id, 'code' => 'MEAT-001', 'name' => 'Fresh beef', 'unit' => 'kg',
                    'quantity' => 2, 'price' => 32000, 'productDiscount' => 0]],
                'total' => 64000, 'orderTax' => 0, 'discount' => 0, 'shipping' => 0, 'grandTotal' => 64000,
                'paid' => 64000, 'returned' => 0, 'balance' => 0, 'paymentStatus' => 'paid', 'status' => 'completed',
                'note' => '', 'createdBy' => 'admin021', 'paymentMethod' => 'Mixed', 'registerId' => $register,
                'payments' => [
                    ['id' => 'SALE/TEST/1-1', 'method' => 'ABA / KHQR', 'amount' => 40000],
                    ['id' => 'SALE/TEST/1-2', 'method' => 'Cash', 'amount' => 24000],
                ],
            ]],
            // The browser also lowers its own copy of the stock; Laravel ignores that.
            'choronai-next-catalog-v1' => fn ($rows) => collect($rows)->map(fn ($p) => $p['id'] === $beef->id
                ? [...$p, 'quantity' => ($p['quantity'] ?? 0) - 2] : $p)->all(),
        ]);
        $this->assertSame(28.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertDatabaseHas('payments', ['sale_id' => $sale, 'method' => 'ABA / KHQR', 'amount' => 40000]);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale, 'method' => 'Cash', 'amount' => 24000]);
        $saved = collect($result['data']['choronai-sales-v1'])->firstWhere('id', $sale);
        $this->assertSame(64000, $saved['grandTotal']);

        $this->commit('choronai-registers-v1', fn ($rows) => collect($rows)->map(fn ($r) => $r['id'] === $register
            ? [...$r, 'status' => 'closed', 'closedAt' => now()->toIso8601String(), 'countedTotal' => 64000,
                'countedUsd' => 0, 'cardSlips' => 0, 'note' => ''] : $r)->all());
        $this->assertDatabaseHas('registers', ['id' => $register, 'status' => 'closed']);
    }

    public function test_credit_sale_is_not_yet_paid(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $this->commit('choronai-registers-v1', fn ($rows) => [...$rows, ['id' => (string) Str::uuid(),
            'status' => 'open', 'warehouse' => 'Central branch', 'cashInHand' => 0, 'openedAt' => now()->toIso8601String()]]);
        $sale = (string) Str::uuid();
        $this->commit('choronai-sales-v1', fn ($rows) => [...$rows, [
            'id' => $sale, 'reference' => 'SALE/TEST/2', 'date' => now()->toIso8601String(), 'warehouse' => 'Central branch',
            'items' => [['productId' => $beef->id, 'quantity' => 1, 'price' => 32000]],
            'paid' => 0, 'paymentMethod' => 'Not yet paid', 'payments' => [],
        ]]);
        $this->assertDatabaseHas('sales', ['id' => $sale, 'paid' => 0, 'payment_status' => 'due']);
        $this->assertDatabaseMissing('payments', ['sale_id' => $sale]);
    }

    public function test_an_admin_can_remove_a_paid_or_unpaid_sale_from_the_browser(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $this->commit('choronai-registers-v1', fn ($rows) => [...$rows, ['id' => (string) Str::uuid(),
            'status' => 'open', 'warehouse' => 'Central branch', 'cashInHand' => 0, 'openedAt' => now()->toIso8601String()]]);

        // A paid sale can now be removed by an admin; its stock goes back.
        $paid = (string) Str::uuid();
        $this->commit('choronai-sales-v1', fn ($rows) => [...$rows, [
            'id' => $paid, 'reference' => 'SALE/TEST/PAID', 'date' => now()->toIso8601String(), 'warehouse' => 'Central branch',
            'items' => [['productId' => $beef->id, 'quantity' => 1, 'price' => 32000]],
            'paid' => 32000, 'paymentMethod' => 'Cash', 'payments' => [['id' => 'P1', 'method' => 'Cash', 'amount' => 32000]],
        ]]);
        $this->assertSame(29.0, $this->stock('MEAT-001', 'CEN'));
        $this->commit('choronai-sales-v1', fn ($rows) => collect($rows)->reject(fn ($r) => $r['id'] === $paid)->values()->all());
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertNull(\App\Models\Sale::find($paid));

        // An unpaid sale can be removed too; its stock goes back.
        $unpaid = (string) Str::uuid();
        $this->commit('choronai-sales-v1', fn ($rows) => [...$rows, [
            'id' => $unpaid, 'reference' => 'SALE/TEST/CREDIT', 'date' => now()->toIso8601String(), 'warehouse' => 'Central branch',
            'items' => [['productId' => $beef->id, 'quantity' => 2, 'price' => 32000]],
            'paid' => 0, 'paymentMethod' => 'Not yet paid', 'payments' => [],
        ]]);
        $this->assertSame(28.0, $this->stock('MEAT-001', 'CEN'));
        $this->commit('choronai-sales-v1', fn ($rows) => collect($rows)->reject(fn ($r) => $r['id'] === $unpaid)->values()->all());
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertNull(\App\Models\Sale::find($unpaid));
    }

    public function test_owner_accepting_a_pending_transfer_completes_it_in_one_step(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $issue = (string) Str::uuid();
        $this->commit('choronai-stockIssues-v1', fn ($rows) => [...$rows, [
            'id' => $issue, 'date' => now()->toIso8601String(), 'reference' => 'SIS/TEST/1', 'branch' => 'Central',
            'warehouse' => 'Main warehouse', 'toBranch' => 'Central', 'toWarehouse' => 'Central branch', 'status' => 'pending',
            'items' => [['productId' => $beef->id, 'code' => 'MEAT-001', 'name' => 'Fresh beef', 'quantity' => 10, 'price' => 25000]],
        ]]);
        $this->assertSame(70.0, $this->stock('MEAT-001', 'MAIN'), 'pending issues do not move stock');

        // The owner (all branches) can receive at the destination, so accepting
        // deducts the sending warehouse and takes it into the destination at once.
        $this->commit('choronai-stockIssues-v1', fn ($rows) => collect($rows)
            ->map(fn ($r) => $r['id'] === $issue ? [...$r, 'status' => 'received', 'stockApplied' => true] : $r)->all());
        $this->assertSame(60.0, $this->stock('MEAT-001', 'MAIN'));
        $this->assertSame(40.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertDatabaseHas('stock_documents', ['id' => $issue, 'status' => 'received']);
        $this->assertDatabaseHas('stock_documents', ['transfer_id' => $issue, 'kind' => 'receive']);
    }

    public function test_receiving_branch_accepts_a_transfer_in_one_step_from_the_browser(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $issue = (string) Str::uuid();
        $this->commit('choronai-stockIssues-v1', fn ($rows) => [...$rows, [
            'id' => $issue, 'date' => now()->toIso8601String(), 'reference' => 'SIS/TEST/ONE', 'branch' => 'Central',
            'warehouse' => 'Main warehouse', 'toBranch' => 'Central', 'toWarehouse' => 'Central branch', 'status' => 'pending',
            'items' => [['productId' => $beef->id, 'code' => 'MEAT-001', 'name' => 'Fresh beef', 'quantity' => 10, 'price' => 25000]],
        ]]);

        // A Central-branch stock manager who cannot use the Main warehouse
        // accepts the incoming transfer: the browser marks it "received".
        $group = \App\Models\UserGroup::create(['name' => 'branch-stock', 'description' => 'Branch Stock',
            'permissions' => ['stock.view', 'stock.approve', 'stock.receive']]);
        \App\Models\User::create(['first_name' => 'Branch', 'last_name' => 'Manager', 'username' => 'branchmgr',
            'email' => 'branch@example.com', 'password' => 'secret123', 'group_id' => $group->id]);
        \App\Models\User::where('username', 'branchmgr')->update(['branch' => 'Central']);
        $this->actingAsUser('branchmgr');

        $this->commit('choronai-stockIssues-v1', fn ($rows) => collect($rows)
            ->map(fn ($r) => $r['id'] === $issue ? [...$r, 'status' => 'received', 'stockApplied' => true] : $r)->all());

        $this->assertSame(60.0, $this->stock('MEAT-001', 'MAIN'));
        $this->assertSame(40.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertDatabaseHas('stock_documents', ['id' => $issue, 'status' => 'received']);
        $this->assertDatabaseHas('stock_documents', ['transfer_id' => $issue, 'kind' => 'receive']);
    }

    public function test_short_transfer_reported_from_the_browser(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $issue = (string) Str::uuid();
        $this->commit('choronai-stockIssues-v1', fn ($rows) => [...$rows, [
            'id' => $issue, 'date' => now()->toIso8601String(), 'reference' => 'SIS/TEST/3', 'branch' => 'Central',
            'warehouse' => 'Main warehouse', 'toBranch' => 'Central', 'toWarehouse' => 'Central branch', 'status' => 'accepted',
            'items' => [['productId' => $beef->id, 'quantity' => 6, 'price' => 25000]],
        ]]);
        $this->assertSame(64.0, $this->stock('MEAT-001', 'MAIN'));

        $this->commitMany([
            'choronai-stockReceives-v1' => fn ($rows) => [...$rows, [
                'id' => (string) Str::uuid(), 'shortOf' => $issue, 'date' => now()->toIso8601String(),
                'branch' => 'Central', 'warehouse' => 'Main warehouse', 'note' => 'Arrived empty',
                'items' => [['productId' => $beef->id, 'quantity' => 6, 'price' => 25000]],
            ]],
            'choronai-stockIssues-v1' => fn ($rows) => collect($rows)
                ->map(fn ($r) => $r['id'] === $issue ? [...$r, 'status' => 'short_received'] : $r)->all(),
        ]);
        $this->assertSame(70.0, $this->stock('MEAT-001', 'MAIN'));
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertDatabaseHas('stock_documents', ['id' => $issue, 'status' => 'short_received']);
        $this->assertDatabaseHas('stock_documents', ['short_of' => $issue, 'kind' => 'receive', 'note' => 'Arrived empty']);
    }

    public function test_rejected_issue_and_cashier_limits(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $issue = (string) Str::uuid();
        $row = ['id' => $issue, 'reference' => 'SIS/TEST/2', 'warehouse' => 'Central branch', 'status' => 'pending',
            'items' => [['productId' => $beef->id, 'quantity' => 3, 'price' => 0]]];
        $this->commit('choronai-stockIssues-v1', fn ($rows) => [...$rows, $row]);
        $this->commit('choronai-stockIssues-v1', fn ($rows) => collect($rows)
            ->map(fn ($r) => $r['id'] === $issue ? [...$r, 'status' => 'rejected'] : $r)->all());
        $this->assertSame(30.0, $this->stock('MEAT-001', 'CEN'));
        $this->assertDatabaseHas('stock_documents', ['id' => $issue, 'status' => 'rejected']);

        // A cashier cannot change products through the workspace either.
        $this->actingAsUser('cashier01');
        $this->commit('choronai-next-catalog-v1', fn ($rows) => collect($rows)
            ->map(fn ($p) => $p['id'] === $beef->id ? [...$p, 'price' => 1] : $p)->all(), 403);
        $this->assertSame(32000, (int) $this->product('MEAT-001')->price);
    }

    public function test_product_and_settings_changes_are_saved(): void
    {
        $this->actingAsUser('admin021');
        $id = (string) Str::uuid();
        $this->commit('choronai-next-catalog-v1', fn ($rows) => [...$rows, [
            'id' => $id, 'sku' => 'MEAT-009', 'name' => 'Salmon set', 'type' => 'standard', 'category' => 'fish',
            'unit' => 'kg', 'price' => 66000, 'cost' => 50000, 'alertQuantity' => 2,
            'image' => '/api/backend/images/products/95715441-e220-4bfd-a759-084d8fac8c54.jpg', 'gallery' => [],
            'quantity' => 0, 'stockByLocation' => [],
        ]]);
        $this->assertDatabaseHas('products', ['id' => $id, 'sku' => 'MEAT-009', 'price' => 66000]);

        $this->commit('choronai-next-catalog-v1', fn ($rows) => collect($rows)
            ->map(fn ($p) => $p['id'] === $id ? [...$p, 'price' => 70000] : $p)->all());
        $this->assertDatabaseHas('products', ['id' => $id, 'price' => 70000]);
        $this->assertDatabaseHas('price_changes', ['product_id' => $id, 'new_price' => 70000]);

        $this->commit('choronai-system-settings-v1', fn ($settings) => [...$settings, 'siteName' => 'Choronai Test']);
        $this->assertSame('Choronai Test', $this->snapshot()['data']['choronai-system-settings-v1']['siteName']);
    }

    public function test_user_created_in_the_browser_can_sign_in(): void
    {
        $this->actingAsUser('admin021');
        $id = (string) Str::uuid();
        $result = $this->commit('choronai-users-v1', fn ($rows) => [...$rows, [
            'id' => $id, 'firstName' => 'Srey', 'lastName' => 'Mom', 'username' => 'sreymom', 'email' => '',
            'phone' => '', 'gender' => 'Female', 'group' => 'Sale', 'branch' => 'Central', 'status' => 'active',
            'password' => 'secret123',
        ]]);
        $user = collect($result['data']['choronai-users-v1'])->firstWhere('id', $id);
        $this->assertSame('Central', $user['branch']);
        $this->assertArrayNotHasKey('password', $user, 'the password never comes back');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['username' => 'sreymom', 'password' => 'secret123'])->assertOk();
    }

    public function test_later_payment_on_a_credit_sale(): void
    {
        $this->actingAsUser('admin021');
        $beef = $this->product('MEAT-001');
        $this->commit('choronai-registers-v1', fn ($rows) => [...$rows, ['id' => (string) Str::uuid(),
            'status' => 'open', 'warehouse' => 'Central branch', 'cashInHand' => 0, 'openedAt' => now()->toIso8601String()]]);
        $sale = (string) Str::uuid();
        $this->commit('choronai-sales-v1', fn ($rows) => [...$rows, [
            'id' => $sale, 'reference' => 'SALE/TEST/3', 'date' => now()->toIso8601String(), 'warehouse' => 'Central branch',
            'items' => [['productId' => $beef->id, 'quantity' => 1, 'price' => 32000]],
            'paid' => 0, 'paymentMethod' => 'Not yet paid', 'payments' => [],
        ]]);

        // Sales → Add Payment: the browser appends a payment and raises `paid`.
        $result = $this->commit('choronai-sales-v1', fn ($rows) => collect($rows)->map(fn ($r) => $r['id'] === $sale
            ? [...$r, 'paid' => 20000, 'paymentStatus' => 'partial',
                'payments' => [...$r['payments'], ['id' => (string) Str::uuid(), 'method' => 'ABA / KHQR', 'amount' => 20000]]]
            : $r)->all());
        $this->assertDatabaseHas('sales', ['id' => $sale, 'paid' => 20000, 'payment_status' => 'partial']);
        $saved = collect($result['data']['choronai-sales-v1'])->firstWhere('id', $sale);
        $this->assertCount(1, $saved['payments']);

        // Changing the items of a posted sale is refused (use Return Sale).
        $this->commit('choronai-sales-v1', fn ($rows) => collect($rows)->map(fn ($r) => $r['id'] === $sale
            ? [...$r, 'items' => [['productId' => $beef->id, 'quantity' => 5, 'price' => 32000]]] : $r)->all(), 409);
    }

    public function test_group_permissions_are_enforced_by_the_server(): void
    {
        // Sale group (seeded): no Payments, no Product Price.
        $this->actingAsUser('admin021');
        $this->openRegister();
        $sale = $this->sell('MEAT-001', 1, ['payment' => ['method' => 'Not yet paid', 'tenderedRiel' => 0]])->assertCreated()->json('id');
        $this->actingAsUser('cashier01');
        $this->postJson("/api/v1/sales/{$sale}/payments", ['method' => 'Cash', 'amount' => 1])->assertForbidden();

        $this->actingAsUser('admin021');
        $group = UserGroup::where('name', 'admin')->first() ?? UserGroup::where('is_owner', false)->first();
        $group->update(['permissions' => ['products.view', 'products.edit']]);
        $user = User::where('username', 'cashier01')->first();
        $user->update(['group_id' => $group->id]);
        $this->actingAsUser('cashier01');
        $beef = $this->product('MEAT-001');
        $this->patchJson("/api/v1/products/{$beef->id}", ['name' => 'Beef'])->assertOk();
        $this->patchJson("/api/v1/products/{$beef->id}", ['price' => 1])->assertForbidden();
        $this->assertSame(32000, (int) $beef->refresh()->price);
    }

    public function test_user_edit_and_delete_from_the_browser(): void
    {
        $this->actingAsUser('admin021');
        $cashier = User::where('username', 'cashier01')->first();

        // Edit: new branch, no password typed (the password stays).
        $this->commit('choronai-users-v1', fn ($rows) => collect($rows)
            ->map(fn ($r) => $r['id'] === $cashier->id ? [...$r, 'branch' => 'Riverside', 'lastName' => 'Two'] : $r)->all());
        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'branch' => 'Riverside', 'last_name' => 'Two']);
        $this->assertTrue(Hash::check('cashier123', $cashier->refresh()->password));

        // Delete: the row is kept for old sales but leaves the list.
        $result = $this->commit('choronai-users-v1', fn ($rows) => collect($rows)
            ->reject(fn ($r) => $r['id'] === $cashier->id)->values()->all());
        $this->assertNull(collect($result['data']['choronai-users-v1'])->firstWhere('id', $cashier->id));
        $this->assertDatabaseHas('users', ['id' => $cashier->id, 'status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['username' => 'cashier01', 'password' => 'cashier123'])->assertStatus(422);
    }
}
