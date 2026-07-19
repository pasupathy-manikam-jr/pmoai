<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Snapshot extends Model
{
    protected $fillable = ['user_id', 'raw_text', 'status'];

    // Funds are a global catalog (one row per code), not snapshot-scoped.
    // Read via App\Models\Fund directly.

    public function feedback(): HasMany
    {
        return $this->hasMany(UserFeedback::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(Recommendation::class);
    }
}
