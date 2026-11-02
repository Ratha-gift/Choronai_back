<?php

namespace Database\Seeders;

use App\Models;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Support\References;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Same starting data as the frontend (src/data/seed.ts), with the decided
 * warehouse layout: one main warehouse and one warehouse for the Central branch.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = UserGroup::create(['name' => 'owner', 'description' => 'Owner', 'is_owner' => true, 'permissions' => []]);
        $sale = UserGroup::create(['name' => 'sale', 'description' => 'Sale', 'permissions' => [
            'sales.pos', 'sales.returns', 'products.view', 'customers.view', 'customers.add',
            'memberCards.view', 'memberCards.add',
        ]]);
        UserGroup::create(['name' => 'stock', 'description' => 'Stock', 'permissions' => [
            'products.view', 'stock.view', 'stock.add', 'stock.edit', 'stock.receive', 'stock.issue', 'reports.alerts',
        ]]);

        // Change these passwords after the first sign-in.
        User::create(['first_name' => 'Admin', 'last_name' => 'Choronai', 'username' => 'admin021', 'email' => 'admin@example.com', 'password' => 'admin123', 'group_id' => $owner->id]);
        User::create(['first_name' => 'Cashier', 'last_name' => 'One', 'username' => 'cashier01', 'email' => 'cashier@example.com', 'password' => 'cashier123', 'group_id' => $sale->id]);

        $branch = Models\Branch::create(['name' => 'Central', 'name_km' => 'សាខាកណ្ដាល', 'phone' => '012 000 444', 'email' => 'central@example.com', 'city' => 'Phnom Penh', 'country' => 'Cambodia']);
        $main = Warehouse::create(['code' => 'MAIN', 'name' => 'Main warehouse', 'type' => 'main', 'address' => 'Phnom Penh']);
        $central = Warehouse::create(['code' => 'CEN', 'name' => 'Central branch', 'type' => 'branch', 'branch_id' => $branch->id, 'address' => 'Phnom Penh']);

        foreach ([['Meat', 'Meat / សាច់'], ['Fish', 'Fish / ត្រី'], ['Other', 'Other / ផ្សេងៗ']] as [$code, $name]) {
            Models\Category::create(['code' => $code, 'name' => $name]);
        }
        Models\Unit::create(['code' => 'kg', 'name' => 'Kilogram']);
        Models\Unit::create(['code' => 'g', 'name' => 'Gram', 'base_unit' => 'kg', 'operator' => '/', 'value' => '1000']);
        Models\Unit::create(['code' => 'pcs', 'name' => 'Piece']);
        Models\Unit::create(['code' => 'box', 'name' => 'Box']);
        foreach ([['General', 0], ['Retail', 0], ['Wholesale', -5]] as [$name, $percentage]) {
            Models\CustomerGroup::create(['name' => $name, 'percentage' => $percentage]);
        }
        Models\PriceGroup::create(['name' => 'Default']);
        Models\PriceGroup::create(['name' => 'Wholesale']);
        foreach ([['Cash Only', 0], ['7 Days', 7], ['30 Days', 30]] as [$description, $days]) {
            Models\PaymentTerm::create(['description' => $description, 'due_days' => $days, 'discount' => '0 Percentage']);
        }
        Models\Currency::create(['code' => 'KHR', 'name' => 'Riel', 'rate' => 1]);
        Models\Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'rate' => 4100]);
        Models\TaxRate::create(['name' => 'No Tax', 'code' => 'NT', 'rate' => 0, 'type' => 'Fixed']);
        Models\TaxRate::create(['name' => 'VAT @10%', 'code' => 'VAT10', 'rate' => 10, 'type' => 'Percentage']);
        Models\Printer::create(['title' => 'Receipt printer', 'type' => 'network', 'profile' => 'simple', 'ip' => '192.168.1.88', 'port' => '9100']);
        Models\ProductReward::create(['code' => 'RW001', 'name' => 'Free 1 kg pork', 'points' => 300]);
        Models\ProductReward::create(['code' => 'RW002', 'name' => 'Free whole chicken', 'points' => 180]);
        Models\Supplier::create(['company' => 'General', 'name' => 'General', 'phone' => '-', 'country' => 'Cambodia']);
        Models\Supplier::create(['company' => 'Mekong Fresh Supply', 'name' => 'Vannak', 'phone' => '012 000 333', 'email' => 'sales@example.com', 'city' => 'Phnom Penh', 'country' => 'Cambodia']);

        Models\Customer::create(['code' => 'CUS0001', 'name' => 'Walk-in Customer', 'phone' => '-']);
        $dara = Models\Customer::create(['code' => 'CUS0002', 'name' => 'Sok Dara', 'phone' => '012 000 111', 'customer_group' => 'Retail', 'address' => 'Phnom Penh', 'award_points' => 120]);
        $sophea = Models\Customer::create(['code' => 'CUS0003', 'company' => 'Riverside Restaurant', 'name' => 'Chan Sophea', 'phone' => '012 000 222', 'email' => 'orders@example.com', 'customer_group' => 'Wholesale', 'price_group' => 'Wholesale', 'address' => 'Phnom Penh', 'award_points' => 860]);
        Models\MemberCard::create(['card_no' => '00001', 'customer_id' => $sophea->id, 'award_points' => 860, 'spent' => 2150000, 'expiry' => now()->addDays(300), 'created_by' => 'admin021']);
        Models\MemberCard::create(['card_no' => '00002', 'customer_id' => $dara->id, 'award_points' => 120, 'spent' => 310000, 'expiry' => now()->addDays(120), 'created_by' => 'admin021']);

        $products = collect([
            ['MEAT-001', '1', 'Fresh beef', 'Meat', 32000, 25000, '/products/beef.svg'],
            ['MEAT-002', '2', 'Fresh pork', 'Meat', 26000, 20000, '/products/pork.svg'],
            ['FISH-001', '3', 'Striped catfish', 'Fish', 14000, 10000, '/products/catfish.svg'],
            ['FISH-002', '4', 'Snakehead fish', 'Fish', 28000, 21000, '/products/snakehead.svg'],
            ['MEAT-003', '5', 'Whole chicken', 'Meat', 18000, 14000, '/products/chicken.svg'],
        ])->map(fn ($row) => Product::create([
            'sku' => $row[0], 'plu_code' => $row[1], 'name' => $row[2], 'category' => $row[3],
            'price' => $row[4], 'cost' => $row[5], 'image' => $row[6], 'unit' => 'kg', 'alert_quantity' => 10,
        ]));

        // Opening stock: 100 kg into the main warehouse, 30 kg of it sent to the branch.
        DB::transaction(function () use ($products, $main, $central) {
            $stock = app(StockService::class);
            $lines = $products->map(fn ($product) => ['product_id' => $product->id, 'code' => $product->sku, 'name' => $product->name, 'unit' => 'kg']);

            $receive = StockDocument::create(['kind' => 'receive', 'reference' => References::next('stock_receive_prefix'), 'date' => now(), 'warehouse_id' => $main->id, 'supplier' => 'Mekong Fresh Supply', 'note' => 'Opening stock']);
            $receive->lines()->createMany($lines->map(fn ($line, $i) => $line + ['quantity' => 100, 'price' => $products[$i]->cost])->all());
            $receive->update(['total' => $products->sum(fn ($product) => 100 * $product->cost)]);
            $stock->apply('stock_document', $receive->id, 'receive', $main->id, $products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => 100])->all());

            $issue = StockDocument::create(['kind' => 'issue', 'reference' => References::next('stock_issue_prefix'), 'date' => now(), 'warehouse_id' => $main->id, 'to_warehouse_id' => $central->id, 'note' => 'First delivery to Central']);
            $issue->lines()->createMany($lines->map(fn ($line) => $line + ['quantity' => 30])->all());
            $stock->apply('stock_document', $issue->id, 'issue_out', $main->id, $products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => -30])->all());
            $stock->apply('stock_document', $issue->id, 'issue_in', $central->id, $products->map(fn ($product) => ['product_id' => $product->id, 'quantity' => 30])->all());
        });
    }
}
