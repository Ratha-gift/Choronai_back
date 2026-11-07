<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Reads audit_logs: who created, changed or deleted what, and when. */
class AuditController extends Controller
{
    /** Table (audit entity) → permission needed to see its history. */
    private const VIEW = [
        'products' => 'products.view', 'product' => 'products.view', 'product_rewards' => 'products.view',
        'customers' => 'customers.view', 'member_cards' => 'memberCards.view', 'member_card' => 'memberCards.view',
        'suppliers' => 'suppliers.view',
        'users' => 'users.view', 'user' => 'users.view', 'user_groups' => 'users.view', 'user_group' => 'users.view',
        'sales' => 'sales.view', 'sale' => 'sales.view', 'sale_returns' => 'sales.view', 'sale_return' => 'sales.view',
        'registers' => 'sales.registers', 'register' => 'sales.registers',
        'stock_issue' => 'stock.view', 'stock_receive' => 'stock.view', 'stock_adjustment' => 'stock.view',
    ];

    /** Fields only shown with an extra permission. */
    private const PRIVATE = ['cost' => 'products.cost'];

    /** GET /audit/history/{id}: everything that happened to one record, newest first. */
    public function history(Request $request, string $id)
    {
        $rows = AuditLog::where('entity_id', $id)->latest('created_at')->orderByDesc('id')->limit(200)->get();
        $user = $request->user();
        foreach ($rows->pluck('entity')->unique() as $entity) {
            abort_unless($user->hasPermission(self::VIEW[$entity] ?? 'settings.edit'), 403, 'You do not have permission to see this history.');
        }

        return $this->present($rows, $user);
    }

    /**
     * GET /audit/activity?action=&entity=&user=&from=&to=&limit=: the latest
     * changes in the whole system (sign-ins are on /audit/logins).
     */
    public function activity(Request $request)
    {
        $query = AuditLog::whereNotIn('action', ['login', 'login_failed', 'logout'])
            ->latest('created_at')->orderByDesc('id');
        foreach (['action', 'entity'] as $field) {
            if ($value = $request->query($field)) {
                $query->where($field, $value);
            }
        }
        if ($value = $request->query('user')) {
            $query->where('user_id', $value);
        }
        if ($value = $request->query('from')) {
            $query->where('created_at', '>=', $value);
        }
        if ($value = $request->query('to')) {
            $query->where('created_at', '<', date('Y-m-d', strtotime($value.' +1 day')));
        }
        $limit = min(500, max(1, (int) $request->query('limit', 200)));

        return $this->present($query->limit($limit)->get(), $request->user());
    }

    private function present(Collection $rows, User $viewer): Collection
    {
        $users = User::whereIn('id', $rows->pluck('user_id')->filter()->unique())->get()->keyBy('id');
        $hide = array_keys(array_filter(self::PRIVATE, fn ($permission) => ! $viewer->hasPermission($permission)));

        return $rows->map(function (AuditLog $row) use ($users, $hide) {
            $user = $users[$row->user_id] ?? null;
            $clean = fn (?array $values) => $values === null ? null : array_diff_key($values, array_flip($hide));

            return [
                'id' => $row->id,
                'at' => $row->created_at,
                'action' => $row->action,
                'entity' => $row->entity,
                'entity_id' => $row->entity_id,
                'user_id' => $row->user_id,
                'username' => $user ? preg_replace('/#deleted#.*/', '', $user->username) : null,
                'user_name' => $user ? trim("{$user->first_name} {$user->last_name}") : null,
                'before' => $clean($row->before),
                'after' => $clean($row->after),
            ];
        })->values();
    }
}
