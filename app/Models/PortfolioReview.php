<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioReview extends Model
{
    protected $fillable = ['status', 'text', 'error', 'provider'];
}
