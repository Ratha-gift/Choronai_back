<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class SaleLine extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quantity' => 'float'];
}
