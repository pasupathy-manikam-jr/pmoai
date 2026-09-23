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

    /**
     * When this account was first invested, Y-m-d or null. Exact date from a
     * captured PMO account page ("Initial Investment on dd/mm/yyyy") first,
     * else the account's earliest ingested transaction. Never guesses "today":
     * a re-capture used to stamp every fund with the capture date.
     */
    public static function firstInvested(?string $code, ?string $accountNo): ?string
    {
        if ($accountNo) {
            // Several pages mention an account number (portfolio lists etc.);
            // only its own account page has the line right after it.
            $texts = \Illuminate\Support\Facades\DB::table('page_captures')
                ->where('text', 'like', '%'.$accountNo.'%Initial Investment on%')
                ->orderByDesc('captured_at')->pluck('text');
            foreach ($texts as $text) {
                // PMO separates words with non-breaking spaces (U+00A0).
                if (preg_match('/'.preg_quote($accountNo, '/').'\b[^0-9]{0,200}?Initial[\s\x{00A0}]+Investment[\s\x{00A0}]+on[\s\x{00A0}]+(\d{2})\/(\d{2})\/(\d{4})/su', $text, $m)) {
                    return "{$m[3]}-{$m[2]}-{$m[1]}";
                }
            }
        }
        if (! $code) {
            return null;
        }
        $q = Transaction::whereRaw('upper(fund_code) = ?', [strtoupper($code)]);
        $first = (clone $q)->when($accountNo, fn ($x) => $x->where('account_no', $accountNo))->min('trans_date')
            ?? ($accountNo ? null : $q->min('trans_date'));

        return $first ? substr((string) $first, 0, 10) : null;
    }
}
