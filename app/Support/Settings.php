<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Setting;

/**
 * System and POS settings. Keys follow the frontend form
 * (src/features/settings/definitions.ts) in snake_case. Only the values the
 * server needs have defaults here; the frontend fills the rest.
 */
class Settings
{
    public const DEFAULTS = [
        'system' => [
            'over_selling' => 'yes',
            'reference_format' => 'year-month',
            'reference_reset' => 'year',
            'sale_prefix' => 'SALE',
            'return_sale_prefix' => 'SR',
            'stock_receive_prefix' => 'SRC',
            'stock_issue_prefix' => 'SIS',
            'adjustment_prefix' => 'ADJ',
            'customer_prefix' => 'CUS',
            'customer_spent' => 10000,
            'customer_points' => 1,
        ],
        'pos' => [],
    ];

    /** Keys that are stored but never sent back to the browser. */
    public const SECRET = ['pin_code'];

    public static function all(string $scope): array
    {
        $saved = Setting::find($scope)?->values ?? [];

        return [...self::DEFAULTS[$scope] ?? [], ...$saved];
    }

    public static function get(string $scope, string $key, mixed $default = null): mixed
    {
        return self::all($scope)[$key] ?? $default;
    }

    public static function put(string $scope, array $values): array
    {
        $current = Setting::find($scope)?->values ?? [];
        $merged = [...$current, ...$values];

        // The POS PIN is kept only as a salted SHA-256, which the POS can also
        // check offline (section 7 of docs/backend-plan.md).
        if (array_key_exists('pin_code', $values)) {
            unset($merged['pin_code']);
            $pin = (string) $values['pin_code'];
            if ($pin === '') {
                unset($merged['pin_hash'], $merged['pin_salt']);
            } else {
                $merged['pin_salt'] = bin2hex(random_bytes(8));
                $merged['pin_hash'] = self::pinHash($merged['pin_salt'], $pin);
            }
        }

        Setting::updateOrCreate(['scope' => $scope], ['values' => $merged]);

        return self::public($scope);
    }

    /** What the browser may see: no PIN, only whether one is set. */
    public static function public(string $scope): array
    {
        $values = self::all($scope);
        if ($scope === 'pos') {
            $values['has_pin'] = isset($values['pin_hash']);
            unset($values['pin_hash'], $values['pin_salt']);
        }

        return collect($values)->except(self::SECRET)->all();
    }

    public static function pinHash(string $salt, string $pin): string
    {
        return hash('sha256', "choronai-pin:{$salt}:{$pin}");
    }

    public static function checkPin(string $pin): bool
    {
        $pos = self::all('pos');
        if (! isset($pos['pin_hash'])) {
            return true;
        }

        return hash_equals($pos['pin_hash'], self::pinHash($pos['pin_salt'], $pin));
    }

    /** Riel per US dollar, from Settings → Currencies. */
    public static function usdRate(): float
    {
        return (float) (Currency::where('code', 'USD')->value('rate') ?: 4100);
    }
}
