<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActionItem extends Model
{
    protected $fillable = ['label', 'sort', 'done', 'done_at'];

    protected $casts = ['done' => 'boolean', 'done_at' => 'datetime'];
}
