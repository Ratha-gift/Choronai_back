<?php

use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\ConvertCase;
use App\Http\Middleware\RequirePermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ConvertCase::class]);
        $middleware->alias([
            'perm' => RequirePermission::class,
            'active' => ActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API always answers with JSON, also for 404 and 500.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
