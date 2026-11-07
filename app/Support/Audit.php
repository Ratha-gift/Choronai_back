<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    /** Off while importing or seeding: those rows have no user who made them. */
    public static bool $enabled = true;

    public static function log(string $action, string $entity, ?string $entityId = null, ?array $before = null, ?array $after = null): void
    {
        if (! self::$enabled) {
            return;
        }
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
        ]);
    }

    /** create / update / delete of a row (App\Models\Concerns\Audited). */
    public static function row(string $action, Model $row, ?array $before = null, ?array $after = null): void
    {
        self::log($action, $row->getTable(), (string) $row->getKey(), $before, $after);
    }

    /** Runs $work without writing audit rows. */
    public static function quietly(callable $work): mixed
    {
        $was = self::$enabled;
        self::$enabled = false;
        try {
            return $work();
        } finally {
            self::$enabled = $was;
        }
    }
}
