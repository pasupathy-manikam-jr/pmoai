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
            if (! $meta || ! isset($meta['regularMarketPrice'])) {
                continue;   // both hosts failed / no price — skip, not fatal
            }

            $price = (float) $meta['regularMarketPrice'];
            $prev = isset($meta['chartPreviousClose']) ? (float) $meta['chartPreviousClose'] : null;
            $out[$symbol] = [
                'price'      => $price,
                'prev_close' => $prev,
                'change_pct' => ($prev && $prev != 0.0) ? round(($price - $prev) / $prev * 100, 2) : null,
                'currency'   => $meta['currency'] ?? null,
            ];
        }

        return $out;
    }
}
