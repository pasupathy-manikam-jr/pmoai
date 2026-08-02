<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches live index / FX / commodity quotes from Yahoo Finance's public chart
 * endpoint (no key). One quote per symbol; failures are skipped, not fatal.
 */
class MarketQuoteService
{
    private const BASE = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    /**
     * @param  string[]  $symbols  Yahoo symbols, e.g. ['^JKSE', 'MYR=X']
     * @return array<string, array{price: float, prev_close: ?float, change_pct: ?float, currency: ?string}>
     */
    public function fetch(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $symbol) {
            try {
                $res = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->timeout(10)
                    ->get(self::BASE.rawurlencode($symbol), ['interval' => '1d', 'range' => '1d']);

                $meta = $res->json('chart.result.0.meta');
                if (! $meta || ! isset($meta['regularMarketPrice'])) {
                    continue;
                }

                $price = (float) $meta['regularMarketPrice'];
                $prev = isset($meta['chartPreviousClose']) ? (float) $meta['chartPreviousClose'] : null;
                $out[$symbol] = [
                    'price'      => $price,
                    'prev_close' => $prev,
                    'change_pct' => ($prev && $prev != 0.0) ? round(($price - $prev) / $prev * 100, 2) : null,
                    'currency'   => $meta['currency'] ?? null,
                ];
            } catch (Throwable $e) {
                // network / parse error — skip this symbol
                continue;
            }
        }

        return $out;
    }
}
