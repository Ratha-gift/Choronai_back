<?php

namespace App\Models;

class SuspendedSale extends Model
{
    protected $casts = ['items' => 'array', 'adjustments' => 'array'];
}
