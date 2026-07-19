<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioSnapshot extends Model
{
    protected $fillable = ['snap_date', 'invested', 'value'];

    protected $casts = ['snap_date' => 'date'];
}
