<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketQuoteDay extends Model
{
    protected $fillable = ['symbol', 'quote_date', 'price', 'change_pct'];

    protected $casts = [
        'quote_date' => 'date',
        'price'      => 'decimal:4',
        'change_pct' => 'decimal:2',
    ];
}
