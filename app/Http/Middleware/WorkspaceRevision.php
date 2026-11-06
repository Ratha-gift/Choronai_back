<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All API writers share a revision lock so browser snapshots detect external changes. */
class WorkspaceRevision
{
    public function handle(Request $request, Closure $next)
    {
        // Until `php artisan migrate` has created the table, do nothing.
        static $ready = null;
        $ready ??= Schema::hasTable('workspace_revisions');
        if (! $ready || $request->isMethod('GET') || $request->isMethod('HEAD') || $request->is('api/v1/auth/*')
            || $request->is('api/v1/frontend/workspace') || $request->attributes->get('workspace_internal')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            DB::table('workspace_revisions')->where('id', 1)->lockForUpdate()->first();
            $response = $next($request);
            if ($response->getStatusCode() < 400) {
                DB::table('workspace_revisions')->where('id', 1)->increment('revision');
            }

            return $response;
        });
    }
}
