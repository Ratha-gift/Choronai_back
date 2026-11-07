<?php

namespace App\Models;

class Currency extends Model
{
    use Concerns\Audited;

    protected $casts = ['rate' => 'float'];
}
