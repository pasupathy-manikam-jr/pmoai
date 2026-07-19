<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\Transaction;

/**
 * Personal money-weighted return (XIRR) per fund, computed from the parsed
 * Statement of Transaction rows + the current position value as the
 * terminal flow. Also totals the fees actually paid.
 *
 * Honesty guard: if the units implied by the transaction history don't
 * roughly match the units implied by the current value, the history is
 * incomplete (missing statements / reinvested distributions) and the XIRR
 * would be wrong — flagged instead of shown.
 */
class Xirr
{
    /**
     * @return array{xirr: ?float, complete: bool, fees: float, tx: int}|null
     *         null when no transactions exist for the code
     */
    public static function forFund(string $code, float $currentValue): ?array
    {
        $txs = Transaction::whereRaw('upper(fund_code) = ?', [strtoupper($code)])
            ->orderBy('trans_date')->get();
        if ($txs->isEmpty()) {
            return null;
        }

        $flows = [];
        $units = 0.0;
        $fees = 0.0;
        foreach ($txs as $t) {
            $fees += (float) ($t->charge_amt ?? 0) + (float) ($t->sst ?? 0);
            $units += (float) ($t->units ?? 0);
            $net = (float) ($t->net ?? 0);
            switch ($t->trans_type) {
                case 'II': case 'AI': case 'RII': case 'SI': case 'SWS':
                    $flows[] = ['date' => $t->trans_date, 'amount' => -abs($net)];
                    break;
                case 'SWR': case 'RP': case 'SRR': case 'DP': case 'REV': case 'COF':
                    $flows[] = ['date' => $t->trans_date, 'amount' => abs($net)];
                    break;
                case 'DR': // reinvested distribution: internal, no external cash flow
                default:
                    break;
            }
        }
        if (! $flows) {
            return null;
        }

        // Completeness: transaction units vs units implied by current value.
        // Money-market funds are absent from PMO's price list → catalog
        // unit_price is null; fall back to the holdings-fed price series.
        $price = (float) (Fund::whereRaw('upper(code) = ?', [strtoupper($code)])->value('unit_price')
            ?? \App\Models\FundPrice::whereRaw('upper(code) = ?', [strtoupper($code)])
                ->orderByDesc('period')->value('price')
            ?? 0);
        $complete = false;
        if ($price > 0 && $units > 0) {
            $implied = $currentValue / $price;
            $complete = abs($implied - $units) / max($implied, 1) <= 0.02;
        }

        $flows[] = ['date' => now(), 'amount' => $currentValue];

        // Annualizing a days-old position produces absurd rates — hold off
        // until the history spans at least ~90 days.
        $spanDays = \Illuminate\Support\Carbon::parse($txs->first()->trans_date)->diffInDays(now(), true);
        $young = $spanDays < 90;

        return [
            'xirr'     => ($complete && ! $young) ? self::compute($flows) : null,
            'complete' => $complete,
            'young'    => $young,
            'fees'     => round($fees, 2),
            'tx'       => $txs->count(),
        ];
    }

    /**
     * Bisection on the annual rate; robust enough for well-formed flows.
     *
     * @param array<int, array{date: mixed, amount: float}> $flows
     */
    public static function compute(array $flows): ?float
    {
        if (count($flows) < 2) {
            return null;
        }
        $t0 = \Illuminate\Support\Carbon::parse($flows[0]['date']);
        $npv = function (float $rate) use ($flows, $t0): float {
            $v = 0.0;
            foreach ($flows as $f) {
                $years = $t0->diffInDays(\Illuminate\Support\Carbon::parse($f['date']), true) / 365.25;
                $v += $f['amount'] / ((1 + $rate) ** $years);
            }
            return $v;
        };

        $lo = -0.95;
        $hi = 10.0;
        if ($npv($lo) * $npv($hi) > 0) {
            return null; // no sign change — no solvable rate
        }
        for ($i = 0; $i < 100; $i++) {
            $mid = ($lo + $hi) / 2;
            $v = $npv($mid);
            if (abs($v) < 0.01) {
                break;
            }
            if ($npv($lo) * $v < 0) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        return round(($lo + $hi) / 2 * 100, 2);
    }
}
