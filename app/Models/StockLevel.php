<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class StockLevel extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quantity' => 'float'];
}
