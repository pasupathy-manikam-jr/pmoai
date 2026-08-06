<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches live index / FX / commodity quotes from Yahoo Finance's public chart
 * endpoint (no key). One quote per symbol; failures are skipped, not fatal.
 *
 * Resilience: Yahoo serves the same chart API from two hosts (query1/query2).
 * We try both before giving up, so a single host rate-limiting or hiccupping
 * doesn't blank the dashboard. (Both are the same Yahoo backend, so this is
 * host failover, not an independent second source — a truly independent
 * provider would need an API key; see FALLBACK note in fetch().)
 */
class MarketQuoteService
{
    private const HOSTS = ['query1', 'query2'];

    /**
     * Yahoo symbol → Twelve Data symbol (independent fallback). Only FX and
     * commodities are on Twelve Data's free tier — its index symbols differ and
     * indices require a paid plan (verified 2026-08-03), so those stay on Yahoo
     * host-failover. USD/MYR (drives every foreign fund) and gold are the two
     * most important quotes and are covered here.
     */
    private const TD_MAP = [
        'MYR=X' => 'USD/MYR',
        'GC=F'  => 'XAU/USD',
    ];

    /**
     * GET the chart endpoint, trying each Yahoo host until one returns usable
     * JSON. Returns the decoded `chart.result.0` array, or null.
     */
    private function chart(string $symbol, array $params): ?array
    {
        foreach (self::HOSTS as $host) {
            try {
                $res = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->timeout(12)
                    ->get("https://{$host}.finance.yahoo.com/v8/finance/chart/".rawurlencode($symbol), $params);

                $result = $res->json('chart.result.0');
                if ($res->ok() && is_array($result) && isset($result['meta'])) {
                    return $result;
                }
            } catch (Throwable $e) {
                // try the next host
            }
        }

        return null;
    }

    /**
     * Daily close history for one symbol (for backfilling our own store).
     *
     * @return array<int, array{date: string, close: float}>  oldest → newest
     */
    public function history(string $symbol, string $range = '3mo'): array
    {
        $result = $this->chart($symbol, ['interval' => '1d', 'range' => $range]);
        if ($result) {
            $stamps = $result['timestamp'] ?? null;
            $closes = $result['indicators']['quote'][0]['close'] ?? null;
            if (! is_array($stamps) || ! is_array($closes)) {
                return [];
            }

            $out = [];
            foreach ($stamps as $i => $ts) {
                $c = $closes[$i] ?? null;
                if ($c === null) {
                    continue;   // market holiday / missing point
                }
                $out[] = ['date' => gmdate('Y-m-d', (int) $ts), 'close' => (float) $c];
            }

            return $out;
        }

        return [];
    }

    /**
     * FALLBACK NOTE: this tries Yahoo's two hosts (via chart()). For a truly
     * INDEPENDENT second source (to survive a full Yahoo outage or cross-check
     * values), add a keyed provider here — e.g. Twelve Data — behind a config
     * flag, and merge its result for any symbol Yahoo missed. Stooq is no
     * longer usable (it now gates the CSV behind a JS proof-of-work).
     *
     * @param  string[]  $symbols  Yahoo symbols, e.g. ['^JKSE', 'MYR=X']
     * @return array<string, array{price: float, prev_close: ?float, change_pct: ?float, currency: ?string}>
     */
    public function fetch(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $symbol) {
            $meta = $this->chart($symbol, ['interval' => '1d', 'range' => '1d'])['meta'] ?? null;
            if ($meta && isset($meta['regularMarketPrice'])) {
                $price = (float) $meta['regularMarketPrice'];
                $prev = isset($meta['chartPreviousClose']) ? (float) $meta['chartPreviousClose'] : null;
                $out[$symbol] = [
                    'price'      => $price,
                    'prev_close' => $prev,
                    'change_pct' => ($prev && $prev != 0.0) ? round(($price - $prev) / $prev * 100, 2) : null,
                    'currency'   => $meta['currency'] ?? null,
                ];

                continue;
            }

            // Yahoo missed this symbol on both hosts → independent fallback.
            if ($td = $this->twelveData($symbol)) {
                $out[$symbol] = $td;
            }
        }

        return $out;
    }

    /**
     * Cross-check the two independent sources for the symbols they BOTH cover
     * (only TD_MAP symbols — USD/MYR + gold). Flags any price that disagrees by
     * more than $tolPct. Dormant (empty) with no Twelve Data key configured, so
     * it never raises a false alarm on a single-source setup.
     *
     * @param  string[]  $symbols
     * @return array<int, array{symbol:string, yahoo:float, td:float, diff_pct:float, agree:bool}>
     */
    public function crossCheck(array $symbols, float $tolPct = 1.0): array
    {
        if (! config('services.twelvedata.key')) {
            return [];
        }
        $out = [];
        foreach ($symbols as $symbol) {
            if (! isset(self::TD_MAP[$symbol])) {
                continue;   // no second source covers this one
            }
            $meta = $this->chart($symbol, ['interval' => '1d', 'range' => '1d'])['meta'] ?? null;
            $yahoo = isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : null;
            $td = $this->twelveData($symbol);
            if ($yahoo === null || $td === null || $yahoo == 0.0) {
                continue;   // need both to compare
            }
            $diff = abs($yahoo - $td['price']) / $yahoo * 100;
            $out[] = [
                'symbol'   => $symbol,
                'yahoo'    => $yahoo,
                'td'       => $td['price'],
                'diff_pct' => round($diff, 2),
                'agree'    => $diff <= $tolPct,
            ];
        }

        return $out;
    }

    /**
     * Twelve Data quote — independent second source. Dormant unless a key is
     * configured and the symbol is mapped. Returns the same shape as fetch(),
     * or null on any failure (rate limit, unmapped, no coverage).
     *
     * @return array{price: float, prev_close: ?float, change_pct: ?float, currency: ?string}|null
     */
    private function twelveData(string $symbol): ?array
    {
        $key = config('services.twelvedata.key');
        $tdSymbol = self::TD_MAP[$symbol] ?? null;
        if (! $key || ! $tdSymbol) {
            return null;
        }

        try {
            $res = Http::timeout(12)->get('https://api.twelvedata.com/quote', [
                'symbol' => $tdSymbol,
                'apikey' => $key,
            ]);
            $j = $res->json();
            // Twelve Data signals errors with status=error / a code field.
            if (! is_array($j) || ($j['status'] ?? null) === 'error' || ! isset($j['close'])) {
                return null;
            }

            $price = (float) $j['close'];
            $prev = isset($j['previous_close']) ? (float) $j['previous_close'] : null;

            return [
                'price'      => $price,
                'prev_close' => $prev,
                'change_pct' => isset($j['percent_change']) ? round((float) $j['percent_change'], 2)
                    : (($prev && $prev != 0.0) ? round(($price - $prev) / $prev * 100, 2) : null),
                'currency'   => $j['currency'] ?? null,
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}
