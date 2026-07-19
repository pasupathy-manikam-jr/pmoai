<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageCapture extends Model
{
    protected $fillable = ['url', 'title', 'hash', 'text', 'tables', 'links', 'captured_at'];

    protected $casts = [
        'tables'      => 'array',
        'links'       => 'array',
        'captured_at' => 'datetime',
    ];
}
