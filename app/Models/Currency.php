<?php

namespace App\Models;

class Currency extends Model
{
    protected $casts = ['rate' => 'float'];
}
