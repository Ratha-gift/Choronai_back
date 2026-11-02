<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class AuditLog extends Eloquent
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
}
