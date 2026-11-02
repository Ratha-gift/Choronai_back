<?php

namespace App\Support;

use App\Models\AuditLog;

class Audit
{
    public static function log(string $action, string $entity, ?string $entityId = null, ?array $before = null, ?array $after = null): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
