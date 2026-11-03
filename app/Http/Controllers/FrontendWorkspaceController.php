<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services\FrontendWorkspace;
use App\Support\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Atomic adapter from existing browser collections to permission-checked API commands. */
class FrontendWorkspaceController extends Controller
{
    public function show(Request $request, FrontendWorkspace $workspace)
    {
        return DB::transaction(function () use ($request, $workspace) {
            DB::table('workspace_revisions')->where('id', 1)->lockForUpdate()->first();

            return $workspace->snapshot($request);
        });
    }

    public function commit(Request $request, FrontendWorkspace $workspace)
    {
        $input = $request->validate(['id' => 'required|uuid', 'revision' => 'required|integer|min:0', 'changes' => 'required|array']);

        return DB::transaction(function () use ($request, $workspace, $input) {
            $revision = DB::table('workspace_revisions')->where('id', 1)->lockForUpdate()->value('revision');
            $hash = hash('sha256', json_encode($input));
            if ($prior = DB::table('workspace_commits')->where('id', $input['id'])->first()) {
                abort_unless($prior->user_id === $request->user()->id && hash_equals($prior->payload_hash, $hash), 409, 'Commit ID has already been used.');

                return $workspace->snapshot($request);
            }
            abort_unless((int) $revision === $input['revision'], 409, 'Another device changed the data. Reload and reconcile pending work.');
            $before = $workspace->snapshot($request)['data'];
            $changes = $input['changes'];
            foreach ($changes as $key => $value) {
                abort_unless(array_key_exists($key, $before), 422, 'Unknown workspace collection.');
                if (is_array($before[$key]) && array_is_list($before[$key]) && str_ends_with($key, '-v1')
                    && ! str_contains($key, 'settings') && ! str_contains($key, 'permissions')) {
                    abort_unless(is_array($value) && array_is_list($value), 422, 'Expected a collection of rows.');
                    $ids = array_column($value, 'id');
                    abort_unless(count($ids) === count($value) && count(array_unique($ids)) === count($ids), 422, 'Every row needs a unique ID.');
                }
            }
            // Referenced lists are saved before business commands. Derived balances are
            // never accepted: the stock, sale and reward services calculate those.
            foreach (Resources::all() as $path => [, , , $rules]) {
                $name = Str::camel(str_replace('-', '_', $path));
                $this->rows($request, $before, $changes, FrontendWorkspace::key($name), $path, array_keys($rules));
            }
            $this->rows($request, $before, $changes, FrontendWorkspace::key('userGroups'), 'user-groups', ['name', 'description']);
            $this->rows($request, $before, $changes, FrontendWorkspace::key('users'), 'users', ['first_name', 'last_name', 'username', 'email', 'phone', 'gender', 'group_id', 'status', 'password_hash']);
            $productFields = ['sku', 'name', 'type', 'category', 'sub_category', 'brand', 'unit', 'sale_unit', 'purchase_unit', 'price', 'cost', 'additional', 'alert_quantity', 'promotion', 'image', 'gallery', 'custom_fields', 'details', 'invoice_details', 'combo_items', 'suppliers'];
            $this->rows($request, $before, $changes, 'choronai-next-catalog-v1', 'products', $productFields);
            $registerKey = FrontendWorkspace::key('registers');
            foreach ($this->delta($before, $changes, $registerKey) as [$old, $row]) {
                abort_unless($row, 409, 'Registers cannot be deleted.');
                if (! $old) {
                    $this->call($request, 'POST', 'registers/open', [
                        'id' => $row['id'], 'warehouse_id' => $this->warehouse($row),
                        'cash_in_hand' => $row['cash_in_hand'], 'open_note' => $row['open_note'] ?? null,
                        'opened_at' => $row['opened_at'],
                    ]);
                }
            }
            foreach (['stockReceives' => 'receives', 'stockIssues' => 'issues', 'adjustments' => 'adjustments'] as $name => $path) {
                foreach ($this->delta($before, $changes, FrontendWorkspace::key($name)) as [$old, $row]) {
                    if (! $row) {
                        $this->call($request, 'DELETE', "stock/{$path}/{$old['id']}");
                        continue;
                    }
                    if (! $old && ! empty($row['transfer_id'])) {
                        $this->call($request, 'POST', 'stock/issues/'.$row['transfer_id'].'/receive', $row);
                        continue;
                    }
                    $body = collect($row)->only(['id', 'date', 'reference', 'supplier', 'note', 'attachment', 'items'])->all();
                    $body['warehouse_id'] = $this->warehouse($row);
                    if ($path === 'issues') {
                        $body['to_warehouse_id'] = Models\Warehouse::where('name', $row['to_warehouse'] ?? '')->value('id');
                    }
                    // The browser changes source status after receipt; Laravel already did that.
                    if ($old && $path === 'issues' && $this->same($old, $row, array_keys($body))) {
                        continue;
                    }
                    if (! $old || ! $this->same($old, $row, ['date', 'reference', 'supplier', 'note', 'attachment', 'items', 'warehouse'])) {
                        $this->call($request, $old ? 'PUT' : 'POST', 'stock/'.$path.($old ? '/'.$row['id'] : ''), $body);
                    }
                    if ($path === 'adjustments' && ($row['status'] ?? '') === 'approved' && ($old['status'] ?? '') !== 'approved') {
                        $this->call($request, 'POST', 'stock/adjustments/'.$row['id'].'/approve');
                    }
                }
            }
            foreach ($this->delta($before, $changes, FrontendWorkspace::key('sales')) as [$old, $row]) {
                abort_unless($row, 409, 'Posted sales cannot be deleted.');
                if ($old) {
                    abort_unless($this->same($old, $row, ['items', 'paid', 'warehouse', 'customer', 'discount', 'shipping', 'order_tax', 'payment_method']), 409, 'Posted sales cannot be edited.');
                    continue;
                }
                $this->call($request, 'POST', 'sales', [
                    ...collect($row)->only(['id', 'reference', 'date', 'items', 'order_tax', 'discount', 'shipping', 'note'])->all(),
                    'customer_id' => $row['customer_id'] ?? Models\Customer::where('name', $row['customer'] ?? '')->value('id'),
                    'member_card_no' => $row['member_card'] ?? null,
                    'payment' => ['method' => $row['payment_method'] ?? 'Cash', 'tendered_riel' => $row['paid'] ?? 0],
                ]);
            }
            foreach ($this->delta($before, $changes, FrontendWorkspace::key('saleReturns')) as [$old, $row]) {
                abort_unless($row && ! $old, 409, 'Posted returns cannot be edited or deleted.');
                $this->call($request, 'POST', 'sale-returns', [
                    ...collect($row)->only(['id', 'date', 'items', 'note', 'restock', 'refund_method'])->all(),
                    'sale_id' => Models\Sale::where('reference', $row['reference_to'] ?? '')->value('id'),
                    'warehouse_id' => $this->warehouse($row),
                ]);
            }
            foreach ($this->delta($before, $changes, FrontendWorkspace::key('rewardLedger')) as [$old, $row]) {
                if ($old || ! $row || ($row['kind'] ?? '') !== 'redeem') {
                    continue; // Earn and return entries are produced by the services.
                }
                $rewardId = $row['reward_id'] ?? Models\ProductReward::where('code', $row['reference'] ?? '')->value('id');
                $this->call($request, 'POST', 'member-cards/'.$row['card_id'].'/redeem', [
                    'id' => $row['id'], 'reward_id' => $rewardId, 'warehouse_id' => $this->warehouse($row),
                ]);
            }
            $this->rows($request, $before, $changes, FrontendWorkspace::key('suspendedSales'), 'suspended-sales', ['items', 'adjustments', 'total', 'note', 'warehouse_id', 'customer_id']);
            foreach ($this->delta($before, $changes, $registerKey) as [$old, $row]) {
                if ($row && ($row['status'] ?? '') === 'closed' && ($old['status'] ?? '') !== 'closed') {
                    $this->call($request, 'POST', 'registers/'.$row['id'].'/close', $row);
                }
            }
            foreach (['system', 'pos'] as $scope) {
                $key = 'choronai-'.$scope.'-settings-v1';
                if (isset($changes[$key])) {
                    $this->call($request, 'PUT', 'settings/'.$scope, $changes[$key]);
                }
            }
            if (array_key_exists('choronai-logo-v1', $changes)) {
                $this->call($request, 'PUT', 'settings/system', ['logo' => $changes['choronai-logo-v1']]);
            }
            foreach ($changes[FrontendWorkspace::key('permissions')] ?? [] as $id => $permissions) {
                if (($before[FrontendWorkspace::key('permissions')][$id] ?? []) != $permissions) {
                    $this->call($request, 'PUT', 'user-groups/'.$id.'/permissions', ['permissions' => array_keys(array_filter($permissions))]);
                }
            }
            DB::table('workspace_revisions')->where('id', 1)->increment('revision');
            DB::table('workspace_commits')->insert(['id' => $input['id'], 'user_id' => $request->user()->id,
                'payload_hash' => $hash, 'revision' => $revision + 1, 'created_at' => now()]);

            return $workspace->snapshot($request);
        });
    }

    private function delta(array $before, array $changes, string $key): array
    {
        if (! array_key_exists($key, $changes)) {
            return [];
        }
        $old = collect($before[$key])->keyBy('id');
        $next = collect($changes[$key])->keyBy('id');
        $out = [];
        foreach ($old->keys()->merge($next->keys())->unique() as $id) {
            if ($old->get($id) != $next->get($id)) {
                $out[] = [$old->get($id), $next->get($id)];
            }
        }

        return $out;
    }

    private function rows(Request $request, array $before, array $changes, string $key, string $path, array $fields): void
    {
        foreach ($this->delta($before, $changes, $key) as [$old, $row]) {
            if (! $row) {
                $this->call($request, 'DELETE', $path.'/'.$old['id']);
                continue;
            }
            $normalized = $row;
            if ($path === 'warehouses') {
                $normalized['branch_id'] = Models\Branch::where('name', $row['branch'] ?? '')->value('id');
                $normalized['type'] = $row['type'] ?? 'branch';
            }
            if ($path === 'users') {
                $normalized['group_id'] = $row['group_id'] ?? Models\UserGroup::where('description', $row['group'] ?? '')->orWhere('name', $row['group'] ?? '')->value('id');
            }
            if ($path === 'suspended-sales') {
                $normalized['warehouse_id'] = $this->warehouse($row);
                $normalized['customer_id'] = Models\Customer::where('name', $row['customer'] ?? '')->value('id');
            }
            if ($old && $this->same($old, $normalized, $fields)) {
                continue;
            }
            $body = collect($normalized)->only($fields)->all();
            if (! $old) {
                $body['id'] = $row['id'];
            }
            $this->call($request, $old ? 'PATCH' : 'POST', $path.($old ? '/'.$row['id'] : ''), $body);
        }
    }

    private function same(array $old, array $new, array $fields): bool
    {
        foreach ($fields as $field) {
            if (($old[$field] ?? null) != ($new[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function warehouse(array $row): ?string
    {
        return $row['warehouse_id'] ?? Models\Warehouse::where('name', $row['warehouse'] ?? '')->value('id');
    }

    private function call(Request $parent, string $method, string $path, array $body = []): void
    {
        $request = Request::create('/api/v1/'.$path, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $parent->header('Authorization'),
        ], json_encode($body));
        $request->attributes->set('workspace_internal', true);
        $request->setUserResolver($parent->getUserResolver());
        $originalRoute = Route::getCurrentRoute();
        try {
            app()->instance('request', $request);
            $response = Route::dispatch($request);
            if ($response->getStatusCode() >= 400) {
                throw new HttpResponseException($response);
            }
        } finally {
            app()->instance('request', $parent);
            if ($originalRoute) {
                $parent->setRouteResolver(fn () => $originalRoute);
            }
        }
    }
}
