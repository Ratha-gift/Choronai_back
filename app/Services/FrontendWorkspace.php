<?php

namespace App\Services;

use App\Models;
use App\Support\Resources;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Presents relational API records in the existing frontend's collection shapes. */
class FrontendWorkspace
{
    public static function key(string $name): string
    {
        return 'choronai-'.$name.'-v1';
    }

    public function snapshot(Request $request): array
    {
        $data = [];
        $user = $request->user();
        $branches = Models\Branch::all()->keyBy('id');
        $warehouses = Models\Warehouse::all()->keyBy('id');
        $customers = Models\Customer::all()->keyBy('id');
        $groups = Models\UserGroup::all();
        foreach (Resources::all() as $path => [$model]) {
            $name = Str::camel(str_replace('-', '_', $path));
            $data[self::key($name)] = $model::all()->map(function ($row) use ($path, $branches, $customers, $warehouses) {
                $out = $row->toArray();
                if ($path === 'warehouses') {
                    $out['branch'] = $branches->get($row->branch_id)?->name ?? $branches->first()?->name ?? '';
                }
                if ($path === 'branches') {
                    // One branch = one warehouse; the frontend keeps the link here.
                    $out['warehouse'] = $warehouses->firstWhere('branch_id', $row->id)?->name ?? '';
                }
                if ($path === 'member-cards') {
                    $out['customer'] = $customers->get($row->customer_id)?->phone ?? '';
                }

                return $out;
            })->all();
        }
        $data[self::key('userGroups')] = $groups->map->toArray()->all();
        // Deleted users stay in the table for old documents, but not in the list.
        $data[self::key('users')] = Models\User::where('username', 'not like', '%#deleted#%')
            ->when(! $user->hasPermission('users.view'), fn ($q) => $q->whereKey($user->id))
            ->get()->map->toApi()->all();
        $data[self::key('permissions')] = $groups->mapWithKeys(fn ($group) => [$group->id => array_fill_keys($group->permissions ?? [], true)])->all();
        $data['choronai-next-catalog-v1'] = Models\Product::with('stockLevels', 'comboItems', 'suppliers')->get()->map(function ($product) use ($warehouses, $branches, $user) {
            $out = $product->toApi();
            $out['stock_by_location'] = [];
            foreach ($product->stockLevels as $level) {
                $warehouse = $warehouses->get($level->warehouse_id);
                $branch = $warehouse?->branch_id ?? $branches->first()?->id;
                $out['stock_by_location'][json_encode([$branch, $level->warehouse_id])] = (float) $level->quantity;
            }
            if (! $user->hasPermission('products.cost')) {
                unset($out['cost']);
            }

            return $out;
        })->all();
        $data['choronai-next-price-history-v1'] = $user->hasPermission('products.view') ? Models\PriceChange::all()->toArray() : [];
        foreach (['sales' => Models\Sale::class, 'saleReturns' => Models\SaleReturn::class,
            'stockReceives' => 'receive', 'stockIssues' => 'issue', 'adjustments' => 'adjustment'] as $name => $type) {
            $stock = in_array($type, ['receive', 'issue', 'adjustment']);
            $query = $stock ? Models\StockDocument::where('kind', $type) : $type::query();
            if ($stock && ! $user->hasPermission('stock.view')) {
                $data[self::key($name)] = [];

                continue;
            }
            if (! $stock && ! $user->hasPermission('sales.view')) {
                $query->where('created_by', $user->id);
            }
            $with = $stock ? ['lines', 'warehouse', 'creator'] : ['lines', 'warehouse', 'creator', ...($type === Models\Sale::class ? ['payments'] : [])];
            $data[self::key($name)] = $query->with($with)->get()->map(function ($document) use ($branches, $warehouses, $stock) {
                $out = $document->toApi();
                if ($document instanceof Models\Sale) {
                    // The frontend's payment list (Sales → View / Add Payment).
                    $out['payments'] = $document->payments->map(fn ($payment) => [
                        'id' => (string) $payment->id, 'date' => $payment->paid_at, 'method' => $payment->method,
                        'amount' => $payment->amount, 'reference' => $document->reference,
                    ])->values()->all();
                }
                $warehouse = $warehouses->get($document->warehouse_id);
                $out['branch'] = $branches->get($warehouse?->branch_id)?->name ?? $branches->first()?->name ?? '';
                if ($stock) {
                    $destination = $warehouses->get($document->to_warehouse_id);
                    $out['to_branch'] = $branches->get($destination?->branch_id)?->name ?? '';
                    $out['stock_applied'] = $document->workflow === 'legacy'
                        || ! in_array($document->status, ['pending', 'rejected'], true);
                    // The frontend calls a half-received transfer "partial".
                    if ($document->status === 'partially_received') {
                        $out['status'] = 'partial';
                    }
                    $out['to_warehouse'] = $destination?->name;
                }
                if ($document instanceof Models\SaleReturn) {
                    $out['reference_to'] = Models\Sale::withTrashed()->find($document->sale_id)?->reference;
                }

                return $out;
            })->all();
        }
        $data[self::key('registers')] = Models\Register::with('user', 'warehouse')
            ->when(! $user->hasPermission('sales.registers') && ! $user->hasPermission('users.view'), fn ($q) => $q->where('user_id', $user->id))
            ->get()->map->toApi()->all();
        $data[self::key('suspendedSales')] = Models\SuspendedSale::where('created_by', $user->id)->get()->map(fn ($row) => [
            ...$row->toArray(), 'warehouse' => $warehouses->get($row->warehouse_id)?->name,
            'customer' => $customers->get($row->customer_id)?->name, 'created_by' => $user->username,
        ])->all();
        $data[self::key('rewardLedger')] = ($user->hasPermission('memberCards.view') || $user->hasPermission('sales.pos'))
            ? Models\RewardEntry::all()->map(fn ($entry) => [
                ...$entry->toArray(), 'event_id' => $entry->event_key, 'card_id' => $entry->member_card_id,
                'date' => $entry->created_at, 'reference' => $entry->sale_id ?? $entry->reward_id,
                'kind' => $entry->kind === 'reversal' ? 'return' : $entry->kind,
                'created_by' => Models\User::find($entry->created_by)?->username,
                ...($entry->details ?? []),
            ])->all() : [];
        foreach (['system', 'pos'] as $scope) {
            $data['choronai-'.$scope.'-settings-v1'] = Settings::public($scope);
        }
        $data['choronai-logo-v1'] = Settings::get('system', 'logo', null);

        return ['initialized' => true, 'backend' => 'laravel', 'revision' => (int) DB::table('workspace_revisions')->where('id', 1)->value('revision'), 'data' => $data];
    }
}
