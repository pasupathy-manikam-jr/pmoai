<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\Vector;
use Pgvector\Laravel\HasNeighbors;

class MarketEvent extends Model
{
    use HasNeighbors;

    protected $fillable = ['source', 'headline', 'body', 'published_at', 'embedding'];

    protected $casts = [
        'published_at' => 'datetime',
        'embedding'    => Vector::class,
    ];
}
