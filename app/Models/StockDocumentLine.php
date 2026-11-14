<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class StockDocumentLine extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quantity' => 'float', 'current_stock' => 'float', 'expiry' => 'date:Y-m-d'];
}
