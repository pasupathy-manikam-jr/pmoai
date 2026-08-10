<?php

namespace App\Services;

use App\Models\FundDetail;
use App\Models\FundPrice;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

/**
 * "Does the data still add up?" checks for the Overview.
 *
 * Two plain worries this answers:
 *   1. Did my total quietly change since the last capture in a way that
 *      doesn't match an actual buy/sell? (This is what the month-end
 *      statement overwrite — RM546k → older figure — would have tripped.)
 *   2. Is any of this stale — old prices, an old holdings capture — so I'd
 *      be acting on yesterday's numbers?
 *
 * All grounded in captured data: held-fund positions, the portfolio_snapshots
 * history, real transactions, and the fund_prices day grid. No forecasts.
 */
class ReconciliationService
{
    /** A held-fund value is "stale" once its newest price is older than this. */
    private const PRICE_STALE_DAYS = 7;

    /** The holdings capture is "stale" once older than this. */
    private const HOLDINGS_STALE_DAYS = 10;

    /** A total drop bigger than this (and not explained by redemptions) is flagged. */
    private const DRIFT_PCT = 3.0;

    public function check(): array
    {
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get()
            ->map(fn ($d) => [
                'name'  => $d->name,
                'code'  => $d->code ? strtoupper($d->code) : null,
                'value' => (float) ($d->payload['position']['current_value'] ?? 0),
                'seen'  => $d->captured_at,          // when this fund's page was last captured
            ])
            ->filter(fn ($h) => $h['value'] > 0)
            ->values();

        $currentTotal = $held->sum('value');

        // ---- 1. Total drift vs the last recorded total -------------------
        // The newest stored point is usually today's (written on ingest); the
        // one before it is the last independent total to compare against.
        $points = PortfolioSnapshot::orderByDesc('snap_date')->limit(2)->get();
        $prev = $points->firstWhere('snap_date', '<', now()->startOfDay()) ?? $points->get(1) ?? $points->first();

        $prevTotal = $prev ? (float) $prev->value : null;
        $prevDate  = $prev?->snap_date;
        $delta     = $prevTotal !== null ? $currentTotal - $prevTotal : null;
        $deltaPct  = $prevTotal ? $delta / $prevTotal * 100 : null;

        // Real money leaving (redemptions/switches OUT) since that point
        // legitimately lowers the total — so it isn't drift.
        $redeemed = 0.0;
        if ($prevDate) {
            $redeemed = (float) Transaction::whereDate('trans_date', '>=', $prevDate)
                ->where('units', '<', 0)
                ->sum('net');
            $redeemed = abs($redeemed);
        }

        // Flag only an unexplained DROP (the overwrite failure mode).
        $unexplained = $delta !== null ? $delta + $redeemed : null; // add back money that legitimately left
        $driftFlag = $deltaPct !== null
            && $deltaPct < -self::DRIFT_PCT
            && $unexplained < -($prevTotal * self::DRIFT_PCT / 100);

        // ---- 2. Freshness ------------------------------------------------
        $latest = $this->latestPriceDates($held->pluck('code')->filter()->unique()->all());

        $stalePrices = $held->filter(function ($h) use ($latest) {
            $code = $h['code'] ? \App\Models\Fund::canonicalCode($h['code']) : null;
            $d = $code ? ($latest[$code] ?? null) : null;
            return $d === null || $d->lt(now()->subDays(self::PRICE_STALE_DAYS));
        })->map(fn ($h) => [
            'name' => $h['name'],
            'last' => (function () use ($h, $latest) {
                $code = $h['code'] ? \App\Models\Fund::canonicalCode($h['code']) : null;
                return $code ? ($latest[$code] ?? null) : null;
            })(),
        ])->values();

        // "Holdings freshness" = when your VALUES were last recorded (the daily
        // value-history point, written on every capture) — NOT when the fund
        // detail pages were last scraped. The latter can be weeks old while
        // your money figures are current, which read as a false alarm.
        $holdingsSeen = optional(PortfolioSnapshot::orderByDesc('snap_date')->first())->snap_date;
        $holdingsAge  = $holdingsSeen ? (int) $holdingsSeen->diffInDays(now()) : null;
        // Separate, softer signal: age of the captured fund reference data.
        $refSeen = $held->max('seen');
        $refAge  = $refSeen ? (int) $refSeen->diffInDays(now()) : null;

        // ---- overall tone -------------------------------------------------
        $tone = 'open'; // ok
        if ($stalePrices->isNotEmpty() || ($holdingsAge !== null && $holdingsAge > self::HOLDINGS_STALE_DAYS)) {
            $tone = 'warn';
        }
        if ($driftFlag) {
            $tone = 'off'; // most serious
        }

        return [
            'current_total'  => $currentTotal,
            'prev_total'     => $prevTotal,
            'prev_date'      => $prevDate,
            'delta'          => $delta,
            'delta_pct'      => $deltaPct,
            'redeemed'       => $redeemed,
            'drift_flag'     => $driftFlag,
            'holdings_seen'  => $holdingsSeen,
            'holdings_age'   => $holdingsAge,
            'holdings_stale' => $holdingsAge !== null && $holdingsAge > self::HOLDINGS_STALE_DAYS,
            'ref_age'        => $refAge,
            'stale_prices'   => $stalePrices,
            'held_count'     => $held->count(),
            'tone'           => $tone,
            'price_stale_days'    => self::PRICE_STALE_DAYS,
            'holdings_stale_days' => self::HOLDINGS_STALE_DAYS,
        ];
    }

    /**
     * Newest captured date for each fund code, read from the fund_prices day
     * grid (one row per code+month; d1..d31 hold that month's daily NAVs).
     *
     * @param  array<string>  $codes
     * @return array<string, Carbon>  canonical code => newest date
     */
    private function latestPriceDates(array $codes): array
    {
        if (empty($codes)) {
            return [];
        }
        $canon = array_unique(array_map(fn ($c) => \App\Models\Fund::canonicalCode($c), $codes));

        $out = [];
        foreach (FundPrice::orderBy('period')->get() as $row) {
            $code = \App\Models\Fund::canonicalCode(strtoupper($row->code));
            if (! in_array($code, $canon, true)) {
                continue;
            }
            for ($d = 1; $d <= 31; $d++) {
                if ($row->{"d{$d}"} === null) {
                    continue;
                }
                try {
                    $date = Carbon::createFromFormat('Y-m-d', $row->period.'-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT));
                } catch (\Throwable) {
                    continue;
                }
                if (! isset($out[$code]) || $date->gt($out[$code])) {
                    $out[$code] = $date;
                }
            }
        }

        return $out;
    }
}
