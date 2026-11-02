<?php

namespace App\Models;

class PriceChange extends Model
{
    public $timestamps = false;

    protected $casts = ['at' => 'datetime'];
}
