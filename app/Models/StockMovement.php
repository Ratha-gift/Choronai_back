<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class StockMovement extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quantity' => 'float', 'expiry' => 'date:Y-m-d'];
}
