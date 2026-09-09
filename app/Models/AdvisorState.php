<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvisorState extends Model
{
    protected $fillable = ['board'];

    protected $casts = ['board' => 'array'];
}
