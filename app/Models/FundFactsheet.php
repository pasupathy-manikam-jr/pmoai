<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FundFactsheet extends Model
{
    protected $fillable = [
        'code', 'period', 'name',
        'fund_size_nav_myr', 'fund_size_units',
        'benchmark_name', 'benchmark_returns',
        'volatility_factor', 'volatility_class',
        'asset_allocation', 'geo_foreign', 'fx_exposure', 'fx_foreign_total_pct',
        'top_sectors', 'top_holdings',
        'distributions', 'calendar_returns',
        'duration_yrs', 'source_pdf', 'captured_at',
    ];

    protected $casts = [
        'benchmark_returns'    => 'array',
        'asset_allocation'     => 'array',
        'geo_foreign'          => 'array',
        'fx_exposure'          => 'array',
        'top_sectors'          => 'array',
        'top_holdings'         => 'array',
        'distributions'        => 'array',
        'calendar_returns'     => 'array',
        'fund_size_nav_myr'    => 'decimal:2',
        'fund_size_units'      => 'decimal:2',
        'volatility_factor'    => 'decimal:2',
        'fx_foreign_total_pct' => 'decimal:2',
        'duration_yrs'         => 'decimal:2',
        'captured_at'          => 'datetime',
    ];

    public function scopeLatestFor(Builder $q, string $code): Builder
    {
        // e-Series codes are mixed case (PeEMAS) while captured detail codes
        // arrive uppercase — compare case-insensitively.
        return $q->whereRaw('upper(code) = ?', [strtoupper($code)])->orderByDesc('period');
    }
}
