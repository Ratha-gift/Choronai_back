<?php

namespace App\Http\Controllers;

use App\Models;
use App\Services\FrontendWorkspace;
use App\Support\Resources;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** Atomic adapter from existing browser collections to permission-checked API commands. */
class FrontendWorkspaceController extends Controller
{
    public function show(Request $request, FrontendWorkspace $workspace)
    {
        // Browsers poll with the revision they already have; when nothing was
        // saved since, answer at once instead of rebuilding every collection.
        if ($request->filled('revision')) {
            $revision = (int) DB::table('workspace_revisions')->where('id', 1)->value('revision');
            if ($revision === (int) $request->query('revision')) {
                return ['initialized' => true, 'backend' => 'laravel', 'revision' => $revision, 'unchanged' => true];
            }
        }

        return DB::transaction(function () use ($request, $workspace) {
            // One consistent view of the data and its revision without
            // blocking other devices: writers bump the revision in the same
            // transaction as their change.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $workspace->snapshot($request);
        });
    }

    public function commit(Request $request, FrontendWorkspace $workspace)
    {
        $input = $request->validate(['id' => 'required|uuid', 'revision' => 'required|integer|min:0', 'changes' => 'sometimes|array', 'ops' => 'sometimes|array']);
        abort_if(! isset($input['changes']) && ! isset($input['ops']), 422, 'A commit needs changes or ops.');

        return DB::transaction(function () use ($request, $workspace, $input) {
            $revision = DB::table('workspace_revisions')->where('id', 1)->lockForUpdate()->value('revision');
            $hash = hash('sha256', json_encode($input));
            if ($prior = DB::table('workspace_commits')->where('id', $input['id'])->first()) {
                abort_unless($prior->user_id === $request->user()->id && hash_equals($prior->payload_hash, $hash), 409, 'Commit ID has already been used.');

                return $workspace->snapshot($request);
            }
            abort_unless((int) $revision === $input['revision'], 409, 'Another device changed the data. Reload and reconcile pending work.');
            $before = $workspace->snapshot($request)['data'];
            // A delta commit ships only the rows it touched (ops); rebuild the
            // whole collection from the server's current data so every command
            // below runs unchanged. Old clients still send the full collection
            // in `changes`, which is kept as-is.
            $changes = $input['changes'] ?? [];
            foreach ($input['ops'] ?? [] as $key => $ops) {
                abort_unless(array_key_exists($key, $before), 422, 'Unknown workspace collection.');
                $changes[$key] = $this->replay($before[$key], is_array($ops) ? $ops : []);
            }
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
            // after branches and warehouses are saved; only for users who may edit them
            if ($request->user()->hasPermission('settings.edit')) {
                $this->linkBranches($changes);
            }
            $this->rows($request, $before, $changes, FrontendWorkspace::key('userGroups'), 'user-groups', ['name', 'description']);
            $this->rows($request, $before, $changes, FrontendWorkspace::key('users'), 'users', ['first_name', 'last_name', 'username', 'email', 'phone', 'gender', 'branch', 'group_id', 'status', 'password']);
            $productFields = ['sku', 'name', 'type', 'category', 'sub_category', 'brand', 'unit', 'sale_unit', 'purchase_unit', 'price', 'cost', 'additional', 'alert_quantity', 'promotion', 'image', 'gallery', 'custom_fields', 'details', 'invoice_details', 'combo_items', 'suppliers'];
            $this->rows($request, $before, $changes, 'choronai-next-catalog-v1', 'products', $productFields);
            $registerKey = FrontendWorkspace::key('registers');
            foreach ($this->delta($before, $changes, $registerKey) as [$old, $row]) {
                abort_unless($row, 409, 'Registers cannot be deleted.');
                if (! $old) {
                    $this->call($request, 'POST', 'registers/open', [
                        'id' => $row['id'], 'warehouse_id' => $this->warehouse($row),
                        'cash_in_hand' => (int) round($row['cash_in_hand'] ?? 0), 'open_note' => $row['open_note'] ?? null,
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
                    // "Report short" on a transfer: the rest goes back to the sender.
                    if (! $old && ! empty($row['short_of'])) {
                        $this->call($request, 'POST', 'stock/issues/'.$row['short_of'].'/short', $row);

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
                        // Issues start pending; Accept below moves the stock.
                        $body['status'] = 'pending';
                    }
                    if ($path === 'receives') {
                        // Line types belong to adjustments only.
                        $body['items'] = array_map(fn ($item) => collect($item)->except('type')->all(), $body['items'] ?? []);
                    }
                    $status = $row['status'] ?? '';
                    $oldStatus = $old['status'] ?? 'pending';
                    // Only pending documents can change; the browser also rewrites
                    // statuses after a transfer receipt, which Laravel already did.
                    $editable = ! $old || $oldStatus === 'pending' || $path === 'receives';
                    if ($editable && (! $old || ! $this->same($old, $row, ['date', 'reference', 'supplier', 'note', 'attachment', 'items', 'warehouse', 'to_warehouse']))) {
                        $this->call($request, $old ? 'PUT' : 'POST', 'stock/'.$path.($old ? '/'.$row['id'] : ''), $body);
                    }
                    if ($path !== 'receives' && $oldStatus === 'pending' && $status !== 'pending') {
                        $action = $status === 'rejected' ? 'reject' : ($path === 'adjustments' ? 'approve' : 'accept');
                        // "received" here means the receiving branch accepted its
                        // own transfer; accept() then dispatches and receives it.
                        if ($status === 'rejected' || in_array($status, ['approved', 'accepted', 'dispatched', 'completed', 'received'], true)) {
                            // Pass the intended status so accept() knows whether
                            // to receive the transfer in one step or only dispatch.
                            $this->call($request, 'POST', "stock/{$path}/{$row['id']}/{$action}", ['status' => $status]);
                        }
                    }
                }
            }
            foreach ($this->delta($before, $changes, FrontendWorkspace::key('sales')) as [$old, $row]) {
                if (! $row) {
                    // A removed sale: admins/owners may delete it (route permission
                    // sales.delete / sales.cancel). The service undoes its stock,
                    // points, payments and any linked returns.
                    abort_unless($old, 409, 'Unknown sale.');
                    $this->call($request, 'DELETE', "sales/{$old['id']}");

                    continue;
                }
                if ($old) {
                    if (! $this->same($old, $row, ['items', 'warehouse', 'customer', 'discount', 'shipping', 'order_tax'])) {
                        // The owner may re-cut a posted sale (items, customer,
                        // discount …); everyone else must use Return Sale instead.
                        abort_unless($request->user()->isOwner(), 409, 'A posted sale cannot be changed. Use Return Sale instead.');
                        $this->call($request, 'PUT', "sales/{$row['id']}", [
                            ...collect($row)->only(['items', 'note'])->all(),
                            // Money is whole riel; a percentage discount/tax can
                            // arrive fractional, so round before the integer rules.
                            'order_tax' => (int) round($row['order_tax'] ?? 0),
                            'discount' => (int) round($row['discount'] ?? 0),
                            'shipping' => (int) round($row['shipping'] ?? 0),
                            'customer_id' => $row['customer_id'] ?? Models\Customer::where('name', $row['customer'] ?? '')->value('id'),
                            'member_card_no' => $row['member_card'] ?? null,
                            'payment' => ['method' => $row['payment_method'] ?? 'Cash', 'tendered_riel' => (int) ($row['paid'] ?? 0)],
                            'payments' => collect($row['payments'] ?? [])->map(fn ($part) => [
                                'method' => $part['method'], 'amount' => (int) $part['amount'],
                            ])->all(),
                        ]);

                        continue;
                    }
                    // Payments added later (Sales → Add Payment).
                    $known = collect($old['payments'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
                    $oldMethods = collect($old['payments'] ?? [])->keyBy(fn ($p) => (string) $p['id']);
                    foreach ($row['payments'] ?? [] as $payment) {
                        if (! in_array((string) $payment['id'], $known, true) && (int) ($payment['amount'] ?? 0) > 0) {
                            $this->call($request, 'POST', "sales/{$row['id']}/payments", [
                                'method' => $payment['method'], 'amount' => (int) $payment['amount'], 'paid_at' => $payment['date'] ?? null,
                            ]);

                            continue;
                        }
                        // The method of an existing payment was corrected (Sales →
                        // View Payments): Cash → QR, … The amount is untouched.
                        $prior = $oldMethods->get((string) $payment['id']);
                        if ($prior && ($prior['method'] ?? null) !== ($payment['method'] ?? null)) {
                            $this->call($request, 'PATCH', "sales/{$row['id']}/payments/{$payment['id']}", [
                                'method' => $payment['method'],
                            ]);
                        }
                    }

                    continue;
                }
                $this->call($request, 'POST', 'sales', [
                    ...collect($row)->only(['id', 'reference', 'date', 'items', 'note'])->all(),
                    // Money is whole riel; a percentage discount/tax can arrive
                    // fractional, so round before the integer validation rules.
                    'order_tax' => (int) round($row['order_tax'] ?? 0),
                    'discount' => (int) round($row['discount'] ?? 0),
                    'shipping' => (int) round($row['shipping'] ?? 0),
                    'customer_id' => $row['customer_id'] ?? Models\Customer::where('name', $row['customer'] ?? '')->value('id'),
                    'member_card_no' => $row['member_card'] ?? null,
                    'payment' => ['method' => $row['payment_method'] ?? 'Cash', 'tendered_riel' => (int) ($row['paid'] ?? 0)],
                    'payments' => collect($row['payments'] ?? [])->map(fn ($part) => [
                        'method' => $part['method'], 'amount' => (int) $part['amount'],
                    ])->all(),
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
                    // The browser counts riel and dollars together in counted_total (riel).
                    $this->call($request, 'POST', 'registers/'.$row['id'].'/close', [
                        'counted_riel' => (int) round($row['counted_total'] ?? 0), 'counted_usd' => 0,
                        'card_slips' => (int) ($row['card_slips'] ?? 0), 'note' => $row['note'] ?? null,
                        'closed_at' => $row['closed_at'] ?? null,
                    ]);
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

    /** A branch names its warehouse: point that warehouse at the branch. */
    private function linkBranches(array $changes): void
    {
        foreach ($changes[FrontendWorkspace::key('branches')] ?? [] as $branch) {
            $warehouse = Models\Warehouse::where('name', $branch['warehouse'] ?? '')->first();
            if ($warehouse && $warehouse->branch_id !== $branch['id'] && Models\Branch::whereKey($branch['id'])->exists()) {
                $warehouse->update(['branch_id' => $branch['id'], 'type' => 'branch']);
            }
        }
    }

    /**
     * Rebuilds a collection from the server's current value and a delta commit's
     * ops — the mirror of the browser's replay() in sync.ts. Rows are upserted
     * and removed by id; a settings object merges changed fields; a plain value
     * is replaced.
     */
    private function replay($current, array $ops)
    {
        if (array_key_exists('rows', $ops)) {
            $remove = array_map('strval', $ops['rows']['remove'] ?? []);
            $rows = array_values(array_filter(
                is_array($current) ? $current : [],
                fn ($row) => ! in_array((string) ($row['id'] ?? ''), $remove, true)
            ));
            foreach ($ops['rows']['upsert'] ?? [] as $row) {
                $at = null;
                foreach ($rows as $index => $existing) {
                    if ((string) ($existing['id'] ?? '') === (string) ($row['id'] ?? '')) {
                        $at = $index;
                        break;
                    }
                }
                if ($at === null) {
                    $rows[] = $row;
                } else {
                    $rows[$at] = $row;
                }
            }

            return $rows;
        }
        if (array_key_exists('fields', $ops) || array_key_exists('removed', $ops)) {
            $merged = array_merge(is_array($current) ? $current : [], $ops['fields'] ?? []);
            foreach ($ops['removed'] ?? [] as $field) {
                unset($merged[$field]);
            }

            return $merged;
        }

        return array_key_exists('value', $ops) ? $ops['value'] : $current;
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
            if (! $this->equal($old->get($id), $next->get($id))) {
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
            if (! $this->equal($old[$field] ?? null, $new[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compares a stored value with what the browser sent back. Requests turn
     * "" into null and JSON turns 2.000 into 2, so both sides are normalised.
     */
    private function equal(mixed $a, mixed $b): bool
    {
        // Stored rows can hold models/collections: compare their JSON form.
        $plain = fn ($value) => json_decode(json_encode($value), true);

        return json_encode(self::normalize($plain($a))) === json_encode(self::normalize($plain($b)));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = array_map(self::normalize(...), $value);
            if (! array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }
        if ($value === '') {
            return null;
        }
        if (is_numeric($value) && ! is_string($value)) {
            return (float) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return (float) $value;
        }

        return $value;
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
