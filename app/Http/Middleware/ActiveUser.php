<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Users switched to inactive lose access straight away, even with a token. */
class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === 'inactive') {
            $request->user()->currentAccessToken()?->delete();
            abort(401, 'This account is inactive.');
        }

        return $next($request);
    }
}
