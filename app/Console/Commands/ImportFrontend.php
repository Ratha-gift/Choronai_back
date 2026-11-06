<?php

namespace App\Console\Commands;

use App\Models;
use App\Support\Images;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Moves the data of the old frontend database (.data/choronai.sqlite, exported
 * as JSON) into PostgreSQL. History is copied as it was; each warehouse's
 * stock is set to the frontend's balance with one "import" ledger entry.
 *
 *   php artisan choronai:import-frontend storage/app/frontend-export.json --fresh --dry-run
 */
class ImportFrontend extends Command
{
    protected $signature = 'choronai:import-frontend
        {file : JSON export of the frontend workspace (the "data" object)}
        {--fresh : Empty the business tables first (sample data from the seeder)}
        {--dry-run : Run everything, report, then roll back}';

    protected $description = 'Import the old frontend workspace into the database';

    /** Old frontend id => new UUID, per collection. */
    private array $ids = [];

    /** Lookups by name: warehouses, branches, customers, users, products. */
    private array $byName = [];

    private array $report = [];

    /** Tables emptied by --fresh, children first. */
    private const TABLES = [
        'reward_entries', 'payments', 'sale_lines', 'sale_return_lines', 'sale_returns', 'sales', 'suspended_sales',
        'registers', 'devices', 'stock_movements', 'stock_document_lines', 'stock_documents', 'stock_levels', 'price_changes',
        'combo_items', 'product_suppliers', 'products', 'member_cards', 'customers', 'product_rewards', 'suppliers',
        'warehouses', 'branches', 'categories', 'units', 'brands', 'customer_groups', 'price_groups', 'payment_terms',
        'currencies', 'tax_rates', 'printers', 'audit_logs', 'workspace_commits', 'personal_access_tokens',
        'users', 'user_groups', 'reference_counters', 'settings',
    ];

    public function handle(): int
    {
        $data = json_decode((string) file_get_contents($this->argument('file')), true);
        if (! is_array($data) || ! isset($data['choronai-next-catalog-v1'])) {
            $this->error('The file is not a frontend workspace export.');

            return self::FAILURE;
        }
        $rows = fn (string $name) => $data["choronai-{$name}-v1"] ?? [];
        $uploaded = [];

        try {
            DB::transaction(function () use ($data, $rows, &$uploaded) {
                if ($this->option('fresh')) {
                    Schema::disableForeignKeyConstraints();
                    foreach (self::TABLES as $table) {
                        if (Schema::hasTable($table)) {
                            DB::table($table)->delete();
                        }
                    }
                    Schema::enableForeignKeyConstraints();
                }

                $this->settings($data);
                $this->groups($rows('userGroups'), $data['choronai-permissions-v1'] ?? []);
                $this->lists($rows);
                $this->branches($rows('branches'), $rows('warehouses'));
                $this->users($rows('users'));
                $this->customers($rows('customers'), $rows('memberCards'));
                $uploaded = $this->products($data['choronai-next-catalog-v1'], $data['choronai-next-price-history-v1'] ?? []);
                $this->rewards($rows('productRewards'));
                $this->registers($rows('registers'));
                $this->sales($rows('sales'), $rows('saleReturns'));
                $this->stockDocuments($rows);
                $this->stockLevels($data['choronai-next-catalog-v1']);
                $this->counters($rows);
                DB::table('workspace_revisions')->where('id', 1)->increment('revision');

                $this->table(['What', 'Imported'], collect($this->report)->map(fn ($n, $what) => [$what, $n])->values()->all());
                if ($this->option('dry-run')) {
                    throw new DryRun;
                }
            });
        } catch (DryRun) {
            // Photos uploaded during a dry run are removed again.
            foreach ($uploaded as $path) {
                Images::delete($path);
            }
            $this->warn('Dry run: nothing was saved.');

            return self::SUCCESS;
        }
        $this->info('Import finished.');

        return self::SUCCESS;
    }

    private function count(string $what, int $n = 1): void
    {
        $this->report[$what] = ($this->report[$what] ?? 0) + $n;
    }

    /** New UUID for an old frontend id (stable within one import). */
    private function id(string $collection, mixed $old): string
    {
        $key = (string) $old;

        return $this->ids[$collection][$key] ??= (string) Str::uuid();
    }

    /** camelCase frontend row => existing snake_case columns of $table. */
    private function columns(string $table, array $row, array $extra = []): array
    {
        $columns = array_flip(Schema::getColumnListing($table));
        $out = [];
        foreach ($row as $key => $value) {
            $column = Str::snake($key);
            if (isset($columns[$column]) && $column !== 'id' && ! is_array($value)) {
                $out[$column] = $value ?? '';
            }
        }

        return [...$out, ...$extra];
    }

    private function settings(array $data): void
    {
        foreach (['system', 'pos'] as $scope) {
            $values = collect($data["choronai-{$scope}-settings-v1"] ?? [])
                ->mapWithKeys(fn ($value, $key) => [Str::snake($key) => $value])->all();
            if ($values) {
                Settings::put($scope, $values); // pin_code is hashed by Settings
                $this->count("settings ({$scope})");
            }
        }
        if (! empty($data['choronai-logo-v1'])) {
            Settings::put('system', ['logo' => $data['choronai-logo-v1']]);
        }
    }

    private function groups(array $groups, array $permissions): void
    {
        foreach ($groups as $group) {
            $id = $this->id('userGroups', $group['id']);
            $owner = strtolower((string) $group['name']) === 'owner';
            Models\UserGroup::create([
                'id' => $id, 'name' => $group['name'], 'description' => $group['description'] ?? '',
                'is_owner' => $owner,
                'permissions' => array_keys(array_filter($permissions[$group['id']] ?? [])),
            ]);
            $this->byName['group'][strtolower((string) $group['name'])] = $id;
            $this->byName['group'][strtolower((string) ($group['description'] ?? ''))] = $id;
            $this->count('user groups');
        }
    }

    /** The simple lists (Settings and People pages). */
    private function lists(callable $rows): void
    {
        $lists = [
            'categories' => 'categories', 'units' => 'units', 'brands' => 'brands', 'customerGroups' => 'customer_groups',
            'priceGroups' => 'price_groups', 'paymentTerms' => 'payment_terms', 'currencies' => 'currencies',
            'taxRates' => 'tax_rates', 'printers' => 'printers', 'suppliers' => 'suppliers',
        ];
        foreach ($lists as $collection => $table) {
            foreach ($rows($collection) as $row) {
                DB::table($table)->insert($this->columns($table, $row, [
                    'id' => $this->id($collection, $row['id']), 'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->count($table);
            }
        }
    }

    /** One branch = one warehouse: the frontend keeps the link on the branch. */
    private function branches(array $branches, array $warehouses): void
    {
        $branchOf = [];
        foreach ($branches as $branch) {
            $id = $this->id('branches', $branch['id']);
            DB::table('branches')->insert($this->columns('branches', $branch, ['id' => $id, 'created_at' => now(), 'updated_at' => now()]));
            $this->byName['branch'][$branch['name']] = $id;
            if (! empty($branch['warehouse'])) {
                $branchOf[$branch['warehouse']] = $id;
            }
            $this->count('branches');
        }
        foreach ($warehouses as $warehouse) {
            $id = $this->id('warehouses', $warehouse['id']);
            DB::table('warehouses')->insert($this->columns('warehouses', $warehouse, [
                'id' => $id, 'type' => isset($branchOf[$warehouse['name']]) ? 'branch' : 'main',
                'branch_id' => $branchOf[$warehouse['name']] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->byName['warehouse'][$warehouse['name']] = $id;
            $this->count('warehouses');
        }
    }

    private function warehouse(array $row): string
    {
        $id = $this->byName['warehouse'][$row['warehouse'] ?? ''] ?? null;
        abort_unless($id, 422, "Unknown warehouse \"{$row['warehouse']}\" in {$row['reference']}.");

        return $id;
    }

    private function users(array $users): void
    {
        foreach ($users as $user) {
            $id = $this->id('users', $user['id']);
            $group = $this->byName['group'][strtolower((string) ($user['group'] ?? ''))]
                ?? array_values($this->byName['group'])[0];
            Models\User::forceCreate([
                'id' => $id, 'first_name' => $user['firstName'] ?? $user['username'], 'last_name' => $user['lastName'] ?? '',
                'username' => $user['username'], 'email' => ($user['email'] ?? '') ?: null, 'phone' => $user['phone'] ?? '',
                'gender' => $user['gender'] ?? '', 'branch' => $user['branch'] ?? '', 'group_id' => $group,
                'status' => ($user['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
                // Unusable until the first sign-in replaces it (see AuthController).
                'password' => Hash::make(Str::random(40)),
                'legacy_password_hash' => $user['passwordHash'] ?? null,
            ]);
            $this->byName['user'][$user['username']] = $id;
            $this->count('users');
        }
    }

    private function user(?string $username): ?string
    {
        return $this->byName['user'][$username ?? ''] ?? null;
    }

    private function customers(array $customers, array $cards): void
    {
        $byPhone = [];
        foreach ($customers as $customer) {
            $id = $this->id('customers', $customer['id']);
            DB::table('customers')->insert($this->columns('customers', $customer, [
                'id' => $id, 'code' => ($customer['code'] ?? '') ?: 'CUS'.Str::upper(Str::random(5)),
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->byName['customer'][$customer['name']] = $id;
            $byPhone[(string) ($customer['phone'] ?? '')] = $id;
            $this->count('customers');
        }
        foreach ($cards as $card) {
            $id = $this->id('memberCards', $card['id']);
            DB::table('member_cards')->insert([
                'id' => $id, 'card_no' => $card['cardNo'], 'customer_id' => $byPhone[(string) ($card['customer'] ?? '')] ?? null,
                'award_points' => (int) ($card['awardPoints'] ?? 0), 'spent' => (int) ($card['spent'] ?? 0),
                'expiry' => ($card['expiry'] ?? '') ?: null, 'created_by' => $this->user($card['createdBy'] ?? null),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->byName['card'][$card['cardNo']] = $id;
            $this->count('member cards');
        }
    }

    /** Products; photos saved as text (data URLs) move to the image bucket. */
    private function products(array $products, array $history): array
    {
        $uploaded = [];
        $image = function (?string $value) use (&$uploaded): string {
            if (! $value || ! preg_match('#^data:image/(jpeg|png|webp|gif);base64,(.+)$#', $value, $match)) {
                return (string) $value;
            }
            $path = 'products/'.Str::uuid().'.'.str_replace('jpeg', 'jpg', $match[1]);
            Images::disk()->put($path, base64_decode($match[2]), ['ContentType' => "image/{$match[1]}"]);
            $uploaded[] = $path;
            $this->count('photos moved to '.Images::diskName());
            $url = Images::url($path);

            return str_starts_with($url, 'http') ? $url : '/api/backend/'.$url;
        };
        foreach ($products as $product) {
            $id = $this->id('products', $product['id']);
            $this->byName['product'][$product['id']] = $id;
            $promotion = $product['promotion'] ?? [];
            DB::table('products')->insert([
                ...$this->columns('products', $product),
                'id' => $id, 'image' => $image($product['image'] ?? ''),
                'gallery' => json_encode(array_map($image, $product['gallery'] ?? [])),
                'custom_fields' => json_encode($product['customFields'] ?? []),
                'promo_price' => $promotion['price'] ?? null, 'promo_start' => ($promotion['start'] ?? '') ?: null,
                'promo_end' => ($promotion['end'] ?? '') ?: null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->count('products');
        }
        foreach ($products as $product) {
            foreach ($product['comboItems'] ?? [] as $item) {
                DB::table('combo_items')->insert([
                    'combo_id' => $this->byName['product'][$product['id']],
                    'product_id' => $this->byName['product'][$item['productId']] ?? null,
                    'quantity' => $item['quantity'], 'price' => (int) ($item['price'] ?? 0),
                ]);
            }
        }
        foreach ($history as $change) {
            if ($productId = $this->byName['product'][$change['productId'] ?? ''] ?? null) {
                DB::table('price_changes')->insert([
                    'id' => (string) Str::uuid(), 'product_id' => $productId, 'name' => $change['name'] ?? '',
                    'old_price' => (int) ($change['oldPrice'] ?? 0), 'new_price' => (int) ($change['newPrice'] ?? 0),
                    'actor' => $change['actor'] ?? '', 'at' => Carbon::parse($change['at'] ?? now()),
                ]);
                $this->count('price changes');
            }
        }

        return $uploaded;
    }

    private function product(array $line): string
    {
        $id = $this->byName['product'][$line['productId'] ?? ''] ?? null;
        abort_unless($id, 422, "Unknown product {$line['code']} in an old document.");

        return $id;
    }

    /** Product rewards point at products, so they come after them. */
    private function rewards(array $rewards): void
    {
        foreach ($rewards as $reward) {
            DB::table('product_rewards')->insert($this->columns('product_rewards', collect($reward)->except('productId')->all(), [
                'id' => $this->id('productRewards', $reward['id']),
                'product_id' => $this->byName['product'][$reward['productId'] ?? ''] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            $this->count('product_rewards');
        }
    }

    private function registers(array $registers): void
    {
        foreach ($registers as $register) {
            DB::table('registers')->insert([
                'id' => $this->id('registers', $register['id']),
                'user_id' => $this->user($register['username'] ?? null) ?? array_values($this->byName['user'])[0],
                'warehouse_id' => $this->warehouse($register + ['reference' => 'a register']),
                'status' => $register['status'] === 'closed' ? 'closed' : 'open',
                'opened_at' => Carbon::parse($register['openedAt']),
                'closed_at' => isset($register['closedAt']) ? Carbon::parse($register['closedAt']) : null,
                'cash_in_hand' => (int) ($register['cashInHand'] ?? 0), 'open_note' => $register['openNote'] ?? '',
                'counted_usd' => (float) ($register['countedUsd'] ?? 0), 'counted_total' => (int) ($register['countedTotal'] ?? 0),
                'card_slips' => (int) ($register['cardSlips'] ?? 0), 'expected_cash' => (int) ($register['expectedCash'] ?? 0),
                'difference' => (int) ($register['difference'] ?? 0), 'total_sales' => (int) ($register['totalSales'] ?? 0),
                'invoices' => (int) ($register['invoices'] ?? 0), 'note' => $register['note'] ?? '',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->count('registers');
        }
    }

    private function lines(array $items): array
    {
        return array_map(fn ($line) => [
            'product_id' => $this->product($line), 'code' => $line['code'] ?? '', 'name' => $line['name'] ?? '',
            'unit' => $line['unit'] ?? 'kg', 'quantity' => round((float) $line['quantity'], 3), 'price' => (int) ($line['price'] ?? 0),
        ], $items);
    }

    private function sales(array $sales, array $returns): void
    {
        foreach ($sales as $sale) {
            $id = $this->id('sales', $sale['id']);
            DB::table('sales')->insert([
                'id' => $id, 'reference' => $sale['reference'], 'date' => Carbon::parse($sale['date']),
                'warehouse_id' => $this->warehouse($sale), 'customer_id' => $this->byName['customer'][$sale['customer'] ?? ''] ?? null,
                'member_card_id' => $this->byName['card'][$sale['memberCard'] ?? ''] ?? null,
                'register_id' => isset($sale['registerId'], $this->ids['registers'][$sale['registerId']]) ? $this->ids['registers'][$sale['registerId']] : null,
                'total' => (int) $sale['total'], 'order_tax' => (int) ($sale['orderTax'] ?? 0), 'discount' => (int) ($sale['discount'] ?? 0),
                'shipping' => (int) ($sale['shipping'] ?? 0), 'grand_total' => (int) $sale['grandTotal'], 'paid' => (int) ($sale['paid'] ?? 0),
                'returned' => (int) ($sale['returned'] ?? 0), 'payment_status' => $sale['paymentStatus'] ?? 'paid',
                'status' => $sale['status'] ?? 'completed', 'payment_method' => $sale['paymentMethod'] ?? 'Cash',
                'note' => $sale['note'] ?? '', 'created_by' => $this->user($sale['createdBy'] ?? null),
                'created_at' => Carbon::parse($sale['date']), 'updated_at' => now(),
            ]);
            foreach ($this->lines($sale['items']) as $line) {
                DB::table('sale_lines')->insert([...$line, 'document_id' => $id]);
            }
            // Older sales only have `paid` + `paymentMethod`.
            $payments = $sale['payments'] ?? [['method' => $sale['paymentMethod'] ?? 'Cash', 'amount' => (int) ($sale['paid'] ?? 0)]];
            foreach ($payments as $payment) {
                if ((int) $payment['amount'] <= 0 || $payment['method'] === 'Not yet paid') {
                    continue;
                }
                DB::table('payments')->insert([
                    'sale_id' => $id, 'method' => $payment['method'], 'amount' => (int) $payment['amount'],
                    'tendered_riel' => (int) $payment['amount'], 'tendered_usd' => 0, 'rate' => Settings::usdRate(),
                    'paid_at' => Carbon::parse($payment['date'] ?? $sale['date']), 'created_by' => $this->user($sale['createdBy'] ?? null),
                ]);
            }
            $this->byName['sale'][$sale['reference']] = $id;
            $this->count('sales');
        }
        foreach ($returns as $return) {
            $id = $this->id('saleReturns', $return['id']);
            DB::table('sale_returns')->insert([
                'id' => $id, 'reference' => $return['reference'], 'date' => Carbon::parse($return['date']),
                'warehouse_id' => $this->warehouse($return), 'customer_id' => $this->byName['customer'][$return['customer'] ?? ''] ?? null,
                'sale_id' => $this->byName['sale'][$return['referenceTo'] ?? ''] ?? null,
                'total' => (int) $return['total'], 'grand_total' => (int) $return['grandTotal'], 'paid' => (int) ($return['paid'] ?? 0),
                'payment_status' => $return['paymentStatus'] ?? 'paid', 'status' => $return['status'] ?? 'completed',
                'note' => $return['note'] ?? '', 'created_by' => $this->user($return['createdBy'] ?? null),
                'refund_method' => $return['refundMethod'] ?? 'Cash', 'restock' => $return['restock'] ?? true,
                'created_at' => Carbon::parse($return['date']), 'updated_at' => now(),
            ]);
            foreach ($this->lines($return['items']) as $line) {
                DB::table('sale_return_lines')->insert([...$line, 'document_id' => $id]);
            }
            $this->count('sale returns');
        }
    }

    /** Receives, issues and adjustments as history ("legacy": they already moved stock). */
    private function stockDocuments(callable $rows): void
    {
        foreach (['stockReceives' => 'receive', 'stockIssues' => 'issue', 'adjustments' => 'adjustment'] as $collection => $kind) {
            foreach ($rows($collection) as $document) {
                $id = $this->id($collection, $document['id']);
                $applied = $document['stockApplied'] ?? ($kind !== 'adjustment' || in_array($document['status'] ?? '', ['approved', 'pending'], true));
                DB::table('stock_documents')->insert([
                    'id' => $id, 'kind' => $kind, 'reference' => $document['reference'], 'date' => Carbon::parse($document['date']),
                    'warehouse_id' => $this->warehouse($document),
                    'to_warehouse_id' => $this->byName['warehouse'][$document['toWarehouse'] ?? ''] ?? null,
                    'supplier' => $document['supplier'] ?? '', 'status' => $document['status'] ?? 'completed',
                    'total' => (int) ($document['grandTotal'] ?? $document['total'] ?? 0), 'note' => $document['note'] ?? '',
                    'attachment' => $document['document'] ?? '', 'created_by' => $this->user($document['createdBy'] ?? null),
                    // Pending ones still wait for approval; the rest already moved stock.
                    'workflow' => $applied ? 'legacy' : 'controlled',
                    'transfer_id' => isset($document['transferId']) ? $this->id('stockIssues', $document['transferId']) : null,
                    'approved_by' => $this->user($document['approvedBy'] ?? null),
                    'approved_at' => isset($document['approvedAt']) ? Carbon::parse($document['approvedAt']) : null,
                    'created_at' => Carbon::parse($document['date']), 'updated_at' => now(),
                ]);
                foreach ($document['items'] as $line) {
                    DB::table('stock_document_lines')->insert([
                        'document_id' => $id, 'product_id' => $this->product($line), 'code' => $line['code'] ?? '',
                        'name' => $line['name'] ?? '', 'unit' => $line['unit'] ?? 'kg', 'quantity' => round((float) $line['quantity'], 3),
                        'price' => (int) ($line['price'] ?? 0), 'type' => $kind === 'adjustment' ? ($line['type'] ?? 'addition') : null,
                        'description' => trim(($line['description'] ?? '').(isset($line['reason']) ? " [{$line['reason']}]" : '')),
                        'expiry' => ($line['expiry'] ?? '') ?: null,
                    ]);
                }
                $this->count("stock {$kind}s");
            }
        }
    }

    /** Each warehouse gets the frontend's balance as one opening ledger entry. */
    private function stockLevels(array $products): void
    {
        $byId = [];
        foreach ($this->ids['warehouses'] ?? [] as $old => $new) {
            $byId[$old] = $new;
        }
        $import = (string) Str::uuid();
        $default = $this->byName['warehouse'][Settings::get('system', 'default_warehouse', '')] ?? null;
        foreach ($products as $product) {
            // Very old rows only have a total: it belongs to the default warehouse.
            $balances = $product['stockByLocation']
                ?? ($default && isset($product['quantity']) ? [json_encode([null, $default]) => $product['quantity']] : []);
            foreach ($balances as $key => $quantity) {
                [, $warehouse] = json_decode($key, true) + [null, null];
                $warehouseId = $byId[$warehouse] ?? $this->byName['warehouse'][$warehouse] ?? null;
                if (! $warehouseId || (float) $quantity == 0.0) {
                    continue;
                }
                $productId = $this->byName['product'][$product['id']];
                DB::table('stock_levels')->insert(['product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => round($quantity, 3)]);
                DB::table('stock_movements')->insert([
                    'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => round($quantity, 3),
                    'reason' => 'import', 'document_type' => 'import', 'document_id' => $import, 'created_at' => now(),
                ]);
                $this->count('stock balances');
            }
        }
    }

    /** New document numbers continue after the imported ones. */
    private function counters(callable $rows): void
    {
        $reset = Settings::get('system', 'reference_reset', 'year');
        $max = [];
        foreach (['sales', 'saleReturns', 'stockReceives', 'stockIssues', 'adjustments'] as $collection) {
            foreach ($rows($collection) as $row) {
                $parts = explode('/', (string) $row['reference']);
                if (count($parts) < 3 || ! ctype_digit(end($parts))) {
                    continue;
                }
                $year = $parts[1];
                $period = match ($reset) {
                    'month' => count($parts) === 4 ? "{$year}-{$parts[2]}" : null,
                    'never' => 'all',
                    default => $year,
                };
                if ($period) {
                    $key = $parts[0].'|'.$period;
                    $max[$key] = max($max[$key] ?? 0, (int) end($parts));
                }
            }
        }
        foreach ($max as $key => $number) {
            [$prefix, $period] = explode('|', $key);
            DB::table('reference_counters')->insert(['prefix' => $prefix, 'period' => $period, 'last_number' => $number]);
        }
    }
}

/** Thrown to roll a --dry-run back. */
class DryRun extends \RuntimeException {}
