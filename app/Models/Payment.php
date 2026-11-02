<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Payment extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['tendered_usd' => 'float', 'rate' => 'float', 'paid_at' => 'datetime'];
}
