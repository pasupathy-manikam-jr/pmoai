<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FundPrice extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['code', 'name', 'price', 'price_date', 'period'];

    protected $casts = [
        'price'      => 'decimal:4',
        'price_date' => 'date',
    ];

    public function __construct(array $attributes = [])
    {
        // Daily columns d1..d31: fillable + decimal-cast.
        for ($d = 1; $d <= 31; $d++) {
            $this->fillable[] = "d{$d}";
            $this->casts["d{$d}"] = 'decimal:4';
        }

        parent::__construct($attributes);
    }
}
