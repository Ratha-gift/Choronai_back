<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Unique document numbers across all devices, e.g. SALE/2026/11/0001. */
class References
{
    /** Call inside a DB transaction. `$prefixKey` is a system setting such as "sale_prefix". */
    public static function next(string $prefixKey, ?Carbon $at = null): string
    {
        $at ??= now();
        $prefix = (string) Settings::get('system', $prefixKey, '');
        $format = Settings::get('system', 'reference_format', 'year-month');
        $period = match (Settings::get('system', 'reference_reset', 'year')) {
            'month' => $at->format('Y-m'),
            'never' => 'all',
            default => $at->format('Y'),
        };

        DB::table('reference_counters')->insertOrIgnore(['prefix' => $prefix, 'period' => $period, 'last_number' => 0]);
        $counter = DB::table('reference_counters')->where(['prefix' => $prefix, 'period' => $period])->lockForUpdate()->first();
        $number = $counter->last_number + 1;
        DB::table('reference_counters')->where('id', $counter->id)->update(['last_number' => $number]);

        $parts = $format === 'sequence'
            ? [$prefix, str_pad($number, 4, '0', STR_PAD_LEFT)]
            : [$prefix, $at->format('Y'), $at->format('m'), str_pad($number, 4, '0', STR_PAD_LEFT)];

        return implode('/', array_filter($parts, fn ($part) => $part !== ''));
    }
}
