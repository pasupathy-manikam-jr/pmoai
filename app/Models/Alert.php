<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    protected $fillable = [
        'fund_code', 'condition', 'level', 'label', 'active', 'fired_at', 'fired_price', 'explanation',
    ];

    protected $casts = [
        'active'   => 'boolean',
        'fired_at' => 'datetime',
    ];
}
