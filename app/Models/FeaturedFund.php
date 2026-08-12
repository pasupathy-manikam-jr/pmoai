<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeaturedFund extends Model
{
    protected $fillable = ['name', 'code', 'metric', 'value', 'as_at', 'rank'];

    protected $casts = ['value' => 'decimal:2'];
}
