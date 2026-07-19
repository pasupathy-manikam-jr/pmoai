<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'trans_date', 'account_no', 'fund_code', 'trans_type', 'reference',
        'gross', 'charge_pct', 'charge_amt', 'sst', 'net', 'price', 'units',
        'trans_ref', 'source_pdf',
    ];

    protected $casts = ['trans_date' => 'date'];
}
