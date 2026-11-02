<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The frontend speaks camelCase (grandTotal), the database snake_case
 * (grand_total). Requests are converted on the way in, JSON on the way out.
 */
class ConvertCase
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->query->replace(self::keys($request->query->all(), Str::snake(...)));
        $body = $request->isJson() ? $request->json() : $request->request;
        $body->replace(self::keys($body->all(), Str::snake(...)));

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->setData(self::keys($response->getData(true), Str::camel(...)));
        }

        return $response;
    }

    /**
     * Renames the keys of nested arrays. Lists keep their numeric keys, and
     * keys that are data rather than names (ids such as UUIDs) stay as they are.
     */
    public static function keys(mixed $value, callable $rename): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $key) ? $rename($key) : $key] = self::keys($item, $rename);
        }

        return $out;
    }
}
