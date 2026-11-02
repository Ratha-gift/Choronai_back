<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as Eloquent;

/** Base for tables with a UUID primary key. Validation happens in controllers. */
abstract class Model extends Eloquent
{
    use HasUuids;

    protected $guarded = [];
}
