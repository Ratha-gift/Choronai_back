<?php

namespace App\Models;

class RewardEntry extends Model
{
    protected $casts = ['details' => 'array', 'points' => 'integer', 'spent' => 'integer'];
}
