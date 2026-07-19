<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundDetail extends Model
{
    protected $fillable = ['code', 'name', 'payload', 'raw_text', 'source_url', 'captured_at'];

    protected $casts = [
        'payload'     => 'array',
        'captured_at' => 'datetime',
    ];

    /**
     * Canonical key for matching list-table fund names (e.g.
     * "PUBLIC INDONESIA SELECT") against detail-page names
     * (e.g. "PUBLIC INDONESIA SELECT FUND").
     */
    public static function normalizeName(?string $name): string
    {
        $n = strtoupper((string) $name);
        $n = preg_replace('/[^A-Z0-9 ]/', ' ', $n);
        $n = preg_replace('/\bFUND\b/', ' ', $n);
        $n = preg_replace('/\s+/', ' ', $n);

        return trim($n);
    }
}
