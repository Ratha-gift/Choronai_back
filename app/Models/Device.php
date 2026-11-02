<?php

namespace App\Models;

class Device extends Model
{
    protected $casts = ['last_sync_at' => 'datetime'];
}
