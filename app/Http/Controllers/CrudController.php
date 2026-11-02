<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\Audit;
use App\Support\Resources;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** List, create, update and delete for the simple lists in App\Support\Resources. */
class CrudController extends Controller
{
    public function index(Request $request, string $resource)
    {
        [$model, , $search] = Resources::find($resource);

        return $this->paginate($request, $model::query(), $search);
    }

    public function show(string $resource, string $id)
    {
        [$model] = Resources::find($resource);

        return $model::findOrFail($id);
    }

    public function store(Request $request, string $resource)
    {
        [$model, $permission, , $rules] = Resources::find($resource);
        $this->authorizePermission($request, $permission, 'add');
        $data = $request->validate($this->rules($rules, $model));

        if ($model === Customer::class && empty($data['code'])) {
            $data['code'] = $this->nextCustomerCode();
        }

        return response()->json($model::create($data), 201);
    }

    public function update(Request $request, string $resource, string $id)
    {
        [$model, $permission, , $rules] = Resources::find($resource);
        $this->authorizePermission($request, $permission, 'edit');
        $row = $model::findOrFail($id);
        $data = $request->validate($this->rules($rules, $model, $id, partial: true));
        $before = $row->toArray();
        $row->update($data);
        Audit::log('update', $resource, $id, $before, $row->toArray());

        return $row;
    }

    public function destroy(Request $request, string $resource, string $id)
    {
        [$model, $permission] = Resources::find($resource);
        $this->authorizePermission($request, $permission, 'delete');
        $row = $model::findOrFail($id);
        try {
            $row->delete();
        } catch (QueryException) {
            abort(409, 'This record is used by other records and cannot be deleted.');
        }
        Audit::log('delete', $resource, $id, $row->toArray());

        return response()->noContent();
    }

    /** "customers.edit" also accepts customers.add / customers.delete for those actions. */
    private function authorizePermission(Request $request, string $permission, string $action): void
    {
        $user = $request->user();
        $module = explode('.', $permission)[0];
        if (! $user->hasPermission($permission) && ! $user->hasPermission("{$module}.{$action}")) {
            abort(403, 'You do not have permission to do this.');
        }
    }

    /** Unique columns get a unique rule that ignores the row being edited. */
    private function rules(array $rules, string $model, ?string $id = null, bool $partial = false): array
    {
        $table = (new $model)->getTable();
        $unique = ['code', 'name', 'card_no', 'description'];
        foreach ($rules as $field => $rule) {
            $parts = explode('|', $rule);
            if (in_array($field, $unique, true) && $this->isUnique($table, $field)) {
                $parts[] = Rule::unique($table, $field)->ignore($id);
            }
            if ($partial) {
                $parts = array_map(fn ($part) => $part === 'required' ? 'sometimes' : $part, $parts);
            }
            $rules[$field] = $parts;
        }

        return $rules;
    }

    private function isUnique(string $table, string $field): bool
    {
        static $unique = [
            'branches' => ['name'], 'warehouses' => ['code'], 'categories' => ['code'], 'units' => ['code'],
            'brands' => ['name'], 'customer_groups' => ['name'], 'price_groups' => ['name'], 'payment_terms' => ['description'],
            'currencies' => ['code'], 'tax_rates' => ['name'], 'product_rewards' => ['code'], 'customers' => ['code'], 'member_cards' => ['card_no'],
        ];

        return in_array($field, $unique[$table] ?? [], true);
    }

    private function nextCustomerCode(): string
    {
        $prefix = (string) Settings::get('system', 'customer_prefix', 'CUS');
        $last = Customer::where('code', 'like', "{$prefix}%")->orderByDesc('code')->value('code');
        $number = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * ?search=&sort=name&direction=asc&page=1&pageSize=50 → { rows, total }.
     * Without page, all rows are returned (what the frontend does today).
     */
    public static function paginate(Request $request, Builder $query, array $search = [], ?callable $map = null)
    {
        if ($term = $request->query('search')) {
            $query->where(function (Builder $where) use ($search, $term) {
                foreach ($search as $column) {
                    $where->orWhereRaw('LOWER(CAST('.$column.' AS TEXT)) LIKE ?', ['%'.mb_strtolower($term).'%']);
                }
            });
        }
        $sort = Str::snake((string) $request->query('sort'));
        $columns = $query->getModel()->getConnection()->getSchemaBuilder()->getColumnListing($query->getModel()->getTable());
        $query->orderBy(in_array($sort, $columns, true) ? $sort : 'created_at', $request->query('direction') === 'asc' ? 'asc' : 'desc');

        $total = (clone $query)->count();
        if ($page = (int) $request->query('page')) {
            $size = min(500, max(1, (int) $request->query('page_size', 50)));
            $query->forPage($page, $size);
        }
        $rows = $query->get();

        return ['rows' => $map ? $rows->map($map)->values() : $rows, 'total' => $total];
    }
}
