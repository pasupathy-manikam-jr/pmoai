<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\Vector;
use Pgvector\Laravel\HasNeighbors;

class UserFeedback extends Model
{
    use HasNeighbors;

    protected $table = 'user_feedback';

    protected $fillable = ['user_id', 'snapshot_id', 'text', 'embedding'];

    protected $casts = [
        'embedding' => Vector::class,
    ];
}
