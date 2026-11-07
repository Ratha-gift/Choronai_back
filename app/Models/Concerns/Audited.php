<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes who created, changed or deleted a row to audit_logs, whatever the
 * route (API, POS sync, frontend workspace). Updates keep only the changed
 * fields, with their old and new values. Hidden fields (passwords) and
 * timestamps are never written.
 */
trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(fn (Model $row) => Audit::row('create', $row, null, self::auditValues($row, $row->attributesToArray())));

        static::updated(function (Model $row) {
            $changes = $row->getChanges();
            $after = self::auditValues($row, array_intersect_key($row->attributesToArray(), $changes));
            $before = [];
            foreach (array_keys($after) as $field) {
                $before[$field] = $row->getOriginal($field);
            }
            // Say that the password changed, never what it is.
            if (array_key_exists('password', $changes)) {
                $after['password'] = '(changed)';
                $before['password'] = null;
            }
            if (! $after) {
                return;
            }
            Audit::row('update', $row, $before, $after);
        });

        static::deleted(fn (Model $row) => Audit::row('delete', $row, self::auditValues($row, $row->attributesToArray())));
    }

    private static function auditValues(Model $row, array $values): array
    {
        $skip = array_merge($row->getHidden(), ['created_at', 'updated_at', 'pin_code', 'remember_token', 'password', 'legacy_password_hash']);

        return array_diff_key($values, array_flip($skip));
    }
}
