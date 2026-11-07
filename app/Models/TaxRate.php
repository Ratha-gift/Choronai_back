<?php

namespace App\Models;

class TaxRate extends Model
{
    use Concerns\Audited;

    protected $casts = ['rate' => 'float'];
}
