<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\FundDetail;

/**
 * Where to deploy the idle e-Cash, and how much each destination can take.
 *
 * Two halves, because "where to put it" has two answers:
 *   destinations — funds you do NOT hold, so deploying also spreads the book.
 *                  Picked by PortfolioAdvisor (e-Series legality, risk tiers,
 *                  entry timing); this class does not re-decide that, or the
 *                  two screens would drift apart.
 *   topups       — funds you already hold, with the amount each can absorb
 *                  before breaching the 30%-of-book cap, and what the sales
 *                  charge on that amount actually costs in ringgit.
 *
 * Every figure is a PMO fact: charge per PHS (cash → equity 3.75%/5%, bond
 * 0.65%/1%, gold 1%), the 30% cap, and your own captured values.
 */
class CashPlanner
{
    private const CONCENTRATION_CAP = 30.0;   // % of book

    public function __construct(private FundAnalysis $analysis) {}

    /**
     * @return array{cash: float, total: float, candidates: array<int, array<string, mixed>>, destinations: array<int, array<string, mixed>>}
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

            // The number the old table never showed: how much of YOUR cash
            // this fund can actually take, and what the load costs on it.
            // Headroom alone read as "RM150,317 of room" against RM101,370 of
            // cash — a ceiling you cannot reach is not an answer.
            $canTake = min($headroom, $cash);

            $rows[] = [
                'name'     => trim((string) preg_replace('/^PUBLIC\s+/i', '', $fund->name)),
                'code'     => $code,
                'weight'   => $weight,
                'cost_pct' => $cost,
                'headroom' => $headroom,
                'can_take' => $canTake,
                'charge_rm' => $canTake * $cost / 100,
                'over'     => $weight >= self::CONCENTRATION_CAP,
                'armed'    => $armedBuy,
                'is_bond'  => $isBond,
                'switch'   => PortfolioAdvisor::freeSwitchStatus($fund->name, $code, $fund->category),
            ];
        }

        // Rank: not-over first, then has an armed buy level, then cheaper to buy,
        // then more headroom.
        usort($rows, function ($a, $b) {
            return [$a['over'], ! $a['armed'], $a['cost_pct'], -$a['headroom']]
                <=> [$b['over'], ! $b['armed'], $b['cost_pct'], -$b['headroom']];
        });

        // ponytail: runs the full advisor plan to read one block off it
        // (~180ms). Split deploys() out of analyze() if this page gets slow.
        $destinations = $cash > 0
            ? (app(PortfolioAdvisor::class)->analyze()['deploy'][0]['options'] ?? [])
            : [];

        return [
            'cash'         => $cash,
            'total'        => $total,
            'candidates'   => $rows,
            'destinations' => $destinations,
            // The book holds no bond/sukuk sleeve at all — worth saying next to
            // a steady-tier option that would fill it.
            'no_bond'      => ! collect($rows)->contains('is_bond', true),
        ];
    }
}
