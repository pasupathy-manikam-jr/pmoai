<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\FundDetail;

/**
 * Where to deploy the idle e-Cash — ranked on real Public Mutual mechanics:
 * the sales charge to buy each fund (cash → equity 3.75%/5%, bond 0.65%/1%),
 * whether the fund has an armed buy-below trigger you set, and how much room it
 * has before it breaches a 30%-of-book concentration cap. No generic scoring —
 * every column is a PMO fact.
 */
class CashPlanner
{
    private const CONCENTRATION_CAP = 30.0;   // % of book

    public function __construct(private FundAnalysis $analysis) {}

    /**
     * @return array{cash: float, total: float, candidates: array<int, array<string, mixed>>}
     */
    public function plan(): array
    {
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get();
        $total = 0.0;
        $cash = 0.0;
        $rows = [];

        foreach ($held as $d) {
            $val = (float) $d->payload['position']['current_value'];
            $total += $val;
            if (preg_match('/CASH|MONEY MARKET/i', $d->name)) {
                $cash += $val;
            }
        }

        foreach ($held as $d) {
            $val = (float) $d->payload['position']['current_value'];
            [$code, $hist, $fund] = $this->analysis->resolve($d);
            if (! $fund || preg_match('/CASH|MONEY MARKET|\bPRS\b/i', $fund->name)) {
                continue;   // not a deploy destination
            }

            $isE = FundAnalysis::isESeries($fund);
            $isBond = (bool) preg_match('/BOND|SUKUK|FIXED|ENHANCED BOND/i', $fund->name);
            $isGold = (bool) preg_match('/EMAS|GOLD/i', $fund->name);
            // PMO sales charge on a fresh cash purchase, per each fund's PHS.
            $cost = $isGold ? 1.0 : ($isBond ? ($isE ? 0.65 : 1.0) : ($isE ? 3.75 : 5.0));

            $weight = $total > 0 ? $val / $total * 100 : 0;
            $headroom = max(0.0, self::CONCENTRATION_CAP / 100 * $total - $val);
            $armedBuy = Alert::whereRaw('upper(fund_code) = ?', [strtoupper((string) $code)])
                ->where('condition', 'below')->where('active', true)->whereNull('fired_at')->exists();

            $rows[] = [
                'name'     => trim((string) preg_replace('/^PUBLIC\s+/i', '', $fund->name)),
                'code'     => $code,
                'weight'   => $weight,
                'cost_pct' => $cost,
                'headroom' => $headroom,
                'over'     => $weight >= self::CONCENTRATION_CAP,
                'armed'    => $armedBuy,
                'is_bond'  => $isBond,
            ];
        }

        // Rank: not-over first, then has an armed buy level, then cheaper to buy,
        // then more headroom.
        usort($rows, function ($a, $b) {
            return [$a['over'], ! $a['armed'], $a['cost_pct'], -$a['headroom']]
                <=> [$b['over'], ! $b['armed'], $b['cost_pct'], -$b['headroom']];
        });

        return ['cash' => $cash, 'total' => $total, 'candidates' => $rows];
    }
}
