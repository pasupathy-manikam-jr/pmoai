<?php

namespace App\Services;

use App\Models\MarketQuote;
use Illuminate\Support\Carbon;

/**
 * The T+1 edge. Public Mutual prices a fund at the NEXT valuation point, which
 * reflects overseas market moves that have ALREADY happened. So today's index
 * moves tell you roughly where each held fund's next NAV lands — before the
 * 4 PM cut-off decides which price you get.
 *
 * expected % ≈ Σ (country weight × that market's move today)
 *            + (foreign share × USD/MYR move)          [ringgit effect on RM NAV]
 * Gold fund ≈ gold's move. All from captured geo + live quotes. An estimate,
 * not a forecast: it ignores stock selection, hedging and intraday drift.
 */
class ExpectedNav
{
    /** A quote older than this is flagged stale and excluded from the estimate. */
    private const STALE_HOURS = 36;

    public function forHeld(): array
    {
        $quotes = MarketQuote::all()->keyBy('symbol');
        $now = Carbon::now();
        $fresh = fn ($q) => $q && $q->fetched_at && $q->fetched_at->gt($now->copy()->subHours(self::STALE_HOURS));

        $usdQ = $quotes['MYR=X'] ?? null;
        $usd = $fresh($usdQ) ? (float) $usdQ->change_pct : 0.0;

        $rows = [];
        foreach (app(PortfolioIndices::class)->fundGeo() as $f) {
            $val = (float) $f['value'];
            $drivers = [];
            $exp = 0.0;
            $covered = 0.0;
            $staleSyms = [];

            if (! empty($f['gold'])) {
                $g = $quotes['GC=F'] ?? null;
                if ($fresh($g)) {
                    $exp = (float) $g->change_pct;
                    $covered = 100;
                    $drivers[] = ['label' => 'Gold', 'w' => 100, 'chg' => (float) $g->change_pct, 'contrib' => $exp];
                } else {
                    $staleSyms[] = 'GC=F';
                }
            } else {
                $malaysia = 0.0;
                foreach ($f['geo'] as $country => $pct) {
                    $sym = PortfolioIndices::symbolFor($country);
                    if (! $sym) {
                        continue;
                    }
                    $q = $quotes[$sym] ?? null;
                    if (! $fresh($q)) {
                        $staleSyms[] = $sym;
                        continue;
                    }
                    $contrib = (float) $pct / 100 * (float) $q->change_pct;
                    $exp += $contrib;
                    $covered += (float) $pct;
                    if (strtoupper($country) === 'MALAYSIA') {
                        $malaysia += (float) $pct;
                    }
                    $drivers[] = ['label' => PortfolioIndices::labelFor($sym), 'w' => (float) $pct, 'chg' => (float) $q->change_pct, 'contrib' => $contrib];
                }
                // Ringgit effect on the foreign share (approximated with USD/MYR).
                $foreign = max(0, $covered - $malaysia);
                if ($foreign > 0 && $usd != 0.0) {
                    $fx = $foreign / 100 * $usd;
                    $exp += $fx;
                    $drivers[] = ['label' => 'USD/MYR', 'w' => $foreign, 'chg' => $usd, 'contrib' => $fx];
                }
            }

            usort($drivers, fn ($a, $b) => abs($b['contrib']) <=> abs($a['contrib']));

            $rows[] = [
                'name'         => $f['name'],
                'value'        => $val,
                'expected_pct' => round($exp, 2),
                'expected_rm'  => round($val * $exp / 100, 0),
                'covered'      => round($covered),         // % of the fund we could map to a live index
                'drivers'      => $drivers,
                'stale'        => array_values(array_unique($staleSyms)),
                'usable'       => $covered >= 40,          // enough mapped exposure to mean anything
            ];
        }

        usort($rows, fn ($a, $b) => abs($b['expected_rm']) <=> abs($a['expected_rm']));
        $usable = array_filter($rows, fn ($r) => $r['usable']);
        $asOf = $quotes->max('fetched_at');

        return [
            'rows'     => $rows,
            'total_rm' => round(array_sum(array_column($usable, 'expected_rm')), 0),
            'as_of'    => $asOf,
            'usd_chg'  => $usd,
        ];
    }
}
