<?php

namespace App\Models;

class CustomerGroup extends Model
{
    use Concerns\Audited;

    protected $casts = ['percentage' => 'float'];
}
