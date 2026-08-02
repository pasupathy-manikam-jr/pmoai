<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketQuote extends Model
{
    protected $fillable = [
        'symbol', 'label', 'price', 'prev_close', 'change_pct', 'currency', 'fetched_at',
    ];

    protected $casts = [
        'price'      => 'decimal:4',
        'prev_close' => 'decimal:4',
        'change_pct' => 'decimal:2',
        'fetched_at' => 'datetime',
    ];
}
