<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fund extends Model
{
    // Catalog: one row per Public Mutual code, upserted each snapshot.
    protected $fillable = [
        'name', 'code', 'fund_type', 'shariah',
        'unit_price', 'selling_price',
        'return_ytd', 'return_1y', 'return_3y', 'return_5y', 'return_10y',
        'perf_factor', 'perf_class', 'perf_date',
        'category', 'risk', 'since_inception', 'fund_size',
        'currency', 'extra',
    ];

    protected $casts = [
        'shariah'       => 'boolean',
        'unit_price'    => 'decimal:4',
        'selling_price' => 'decimal:4',
        'return_ytd'    => 'decimal:2',
        'return_1y'     => 'decimal:2',
        'return_3y'     => 'decimal:2',
        'return_5y'     => 'decimal:2',
        'return_10y'    => 'decimal:2',
        'perf_factor'   => 'decimal:2',
        'perf_date'     => 'date',
        'extra'         => 'array',
    ];

    /** Permanent price history, keyed by the shared Public Mutual code. */
    public function prices(): HasMany
    {
        return $this->hasMany(FundPrice::class, 'code', 'code');
    }

    /** Monthly MFR factsheet history, keyed by code. */
    public function factsheets(): HasMany
    {
        return $this->hasMany(FundFactsheet::class, 'code', 'code');
    }

    public function latestFactsheet(): ?FundFactsheet
    {
        return $this->factsheets()->orderByDesc('period')->first();
    }

    /**
     * Canonical catalog casing for a code. Price writers must funnel every
     * code through this before upserting: fund_prices' (code, period) unique
     * index is CASE-SENSITIVE, so 'PeEMAS' and 'PEEMAS' would split one fund's
     * month into two rows (e-Series codes are mixed case). Returns the input
     * unchanged when the code is not in the catalog. Memoized per process.
     */
    public static function canonicalCode(string $code): string
    {
        static $map = null;
        if ($map === null) {
            $map = static::whereNotNull('code')->pluck('code')
                ->mapWithKeys(fn ($c) => [strtoupper($c) => $c])->all();
        }

        return $map[strtoupper($code)] ?? $code;
    }
}
