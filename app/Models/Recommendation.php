<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    protected $fillable = [
        'user_id', 'snapshot_id', 'fund_name',
        'action', 'target_weight', 'rationale', 'model',
    ];

    protected $casts = [
        'target_weight' => 'decimal:2',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
