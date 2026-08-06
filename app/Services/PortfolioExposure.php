<?php

namespace App\Services;

use App\Models\FundDetail;

/**
 * "Look-through" analytics from each held fund's captured Top-5 Sectors and
 * Top-5 Holdings (payload.allocation). Surfaces exposure the fund-level weights
 * hide: which sectors the whole book leans on, and which single stocks you hold
 * through more than one fund (real concentration).
 */
class PortfolioExposure
{
    /** @return \Illuminate\Support\Collection<int, FundDetail> */
    private function held(): \Illuminate\Support\Collection
    {
        return FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get();
    }

    private function short(string $name): string
    {
        return trim((string) preg_replace('/^PUBLIC\s+/i', '', $name));
    }

    /**
     * Portfolio sector mix, RM-weighted (Top-5 per fund — approximate, the
     * captured sectors don't sum to 100%).
     *
     * @return array<int, array{sector:string, rm:float, pct:float}>
     */
    public function sectors(): array
    {
        $rm = [];
        $total = 0.0;
        foreach ($this->held() as $d) {
            $val = (float) $d->payload['position']['current_value'];
            $total += $val;
            if (! preg_match('/Top 5 Sectors[^\n]*\n(.*?)(?:Top 5 Holdings|$)/s', $d->payload['allocation'] ?? '', $m)) {
                continue;
            }
            foreach (preg_split('/\n/', trim($m[1])) as $line) {
                if (preg_match('/^(.+?)\s+([\d.]+)%/', trim($line), $c)) {
                    $sector = ucwords(strtolower(trim($c[1])));
                    $rm[$sector] = ($rm[$sector] ?? 0) + $val * (float) $c[2] / 100;
                }
            }
        }
        arsort($rm);

        $out = [];
        foreach ($rm as $sector => $v) {
            $out[] = ['sector' => $sector, 'rm' => $v, 'pct' => $total > 0 ? $v / $total * 100 : 0];
        }

        return $out;
    }

    /**
     * Stock look-through: each Top-5 holding, the funds it appears in, and the
     * combined value of those funds (an exposure indicator — the captured
     * holdings carry no per-stock weight). Ranked by fund-count then value;
     * stocks in >1 fund are the hidden concentration.
     *
     * @return array<int, array{stock:string, funds:string[], fund_count:int, combined_value:float}>
     */
    public function stocks(): array
    {
        $map = [];   // normkey => [stock, funds[], value]
        foreach ($this->held() as $d) {
            $val = (float) $d->payload['position']['current_value'];
            $fund = $this->short($d->name);
            if (! preg_match('/Top 5 Holdings[^\n]*\n(.*)$/s', $d->payload['allocation'] ?? '', $m)) {
                continue;
            }
            foreach (preg_split('/\n/', trim($m[1])) as $line) {
                $line = trim($line);
                if ($line === '' || ! preg_match('/[A-Za-z]{3,}/', $line)) {
                    continue;
                }
                $key = $this->normStock($line);
                if (! isset($map[$key])) {
                    $map[$key] = ['stock' => $this->cleanStock($line), 'funds' => [], 'value' => 0.0];
                }
                if (! in_array($fund, $map[$key]['funds'], true)) {
                    $map[$key]['funds'][] = $fund;
                    $map[$key]['value'] += $val;
                }
            }
        }

        $out = [];
        foreach ($map as $row) {
            $out[] = [
                'stock' => $row['stock'],
                'funds' => $row['funds'],
                'fund_count' => count($row['funds']),
                'combined_value' => $row['value'],
            ];
        }
        usort($out, fn ($a, $b) => [$b['fund_count'], $b['combined_value']] <=> [$a['fund_count'], $a['combined_value']]);

        return $out;
    }

    /**
     * Return-per-unit-of-risk for each held fund: the fund's longest available
     * annualised return divided by its captured volatility factor. Higher =
     * more return for the price swings you stomach. Held funds only, sorted.
     *
     * @return array<int, array{name:string, return:float, vol:float, ratio:float}>
     */
    public function riskAdjusted(): array
    {
        $analysis = app(FundAnalysis::class);
        $out = [];
        foreach ($this->held() as $d) {
            [$code, $hist, $fund] = $analysis->resolve($d);
            if (! $fund || preg_match('/CASH|MONEY MARKET|DEPOSIT/i', $fund->name)) {
                continue;   // money-market's tiny volatility distorts the ratio
            }
            $ret = $fund->return_5y ?? $fund->return_3y ?? $fund->return_1y;
            $vol = optional($analysis->factsheetFor($code))->volatility_factor;
            if ($ret === null || ! $vol || (float) $vol <= 0) {
                continue;
            }
            $out[] = [
                'name'   => $this->short($fund->name),
                'return' => (float) $ret,
                'vol'    => (float) $vol,
                'ratio'  => round((float) $ret / (float) $vol, 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);

        return $out;
    }

    /**
     * Each held fund vs its OWN Public Mutual benchmark, straight from the
     * captured factsheet (PMO publishes fund_ann vs bench_ann per period). Picks
     * the longest period available. Positive diff = the fund beat its benchmark.
     *
     * @return array<int, array{name:string, period:string, fund:float, bench:float, diff:float, beat:bool}>
     */
    public function benchmarks(): array
    {
        $analysis = app(FundAnalysis::class);
        $out = [];
        foreach ($this->held() as $d) {
            [$code, $hist, $fund] = $analysis->resolve($d);
            if (! $fund || preg_match('/CASH|MONEY MARKET|\bPRS\b/i', $fund->name)) {
                continue;
            }
            $br = optional($analysis->factsheetFor($code))->benchmark_returns;
            if (! is_array($br)) {
                continue;
            }
            foreach (['5y', '3y', '1y', 'since'] as $p) {
                $f = $br[$p]['fund_ann'] ?? null;
                $b = $br[$p]['bench_ann'] ?? null;
                if ($f !== null && $b !== null) {
                    $out[] = [
                        'name'   => $this->short($fund->name),
                        'period' => $p,
                        'fund'   => (float) $f,
                        'bench'  => (float) $b,
                        'diff'   => round((float) $f - (float) $b, 2),
                        'beat'   => (float) $f >= (float) $b,
                    ];
                    break;
                }
            }
        }
        usort($out, fn ($a, $b) => $b['diff'] <=> $a['diff']);

        return $out;
    }

    /** Captured country (from the geo breakdown) → its currency. */
    private const CCY = [
        'USA' => 'USD', 'UNITED STATES' => 'USD',
        'INDONESIA' => 'IDR', 'INDIA' => 'INR',
        'CHINA' => 'CNY', 'GREATER CHINA' => 'CNY', 'HONG KONG' => 'HKD',
        'TAIWAN' => 'TWD', 'KOREA' => 'KRW', 'SOUTH KOREA' => 'KRW',
        'JAPAN' => 'JPY', 'GREAT BRITAIN' => 'GBP', 'UNITED KINGDOM' => 'GBP',
        'NETHERLANDS' => 'EUR', 'FRANCE' => 'EUR', 'GERMANY' => 'EUR', 'IRELAND' => 'EUR',
        'SINGAPORE' => 'SGD', 'AUSTRALIA' => 'AUD', 'THAILAND' => 'THB',
        'VIETNAM' => 'VND', 'PHILIPPINES' => 'PHP', 'MALAYSIA' => 'MYR',
    ];

    /**
     * REAL currency exposure — built from each fund's captured Geographical
     * Breakdown (the same data the dashboard indices use), weighting each
     * country's currency by (fund value × that country's %). A gold fund is
     * USD-priced. Any part of a fund with no listed country falls back to MYR
     * (fund-held cash). This replaces the old name-guess estimate.
     *
     * @return array{rows: array<int, array{ccy:string, rm:float, pct:float}>, total:float, foreign_pct:float}
     */
    public function currencies(): array
    {
        $byCcy = [];   // ccy => RM
        foreach (app(PortfolioIndices::class)->fundGeo() as $f) {
            $val = (float) $f['value'];
            if ($val <= 0) {
                continue;
            }
            if (! empty($f['gold'])) {
                $byCcy['USD'] = ($byCcy['USD'] ?? 0) + $val;   // gold trades in USD
                continue;
            }
            $placed = 0.0;
            foreach ($f['geo'] as $country => $pct) {
                $ccy = self::CCY[strtoupper($country)] ?? null;
                if (! $ccy) {
                    continue;   // unknown country → treated as MYR remainder below
                }
                $rm = $val * (float) $pct / 100;
                $byCcy[$ccy] = ($byCcy[$ccy] ?? 0) + $rm;
                $placed += $rm;
            }
            // Whatever geography didn't account for → home currency (MYR).
            $rest = max(0, $val - $placed);
            if ($rest > 0) {
                $byCcy['MYR'] = ($byCcy['MYR'] ?? 0) + $rest;
            }
        }

        arsort($byCcy);
        $total = array_sum($byCcy) ?: 1;
        $rows = array_map(
            fn ($ccy, $rm) => ['ccy' => $ccy, 'rm' => $rm, 'pct' => $rm / $total * 100],
            array_keys($byCcy), array_values($byCcy)
        );

        return [
            'rows' => $rows,
            'total' => array_sum($byCcy),
            'foreign_pct' => 100 - (($byCcy['MYR'] ?? 0) / $total * 100),
        ];
    }

    /** Match key — strip corporate suffixes/share-class so "NVIDIA Corporation" == "NVIDIA". */
    private function normStock(string $s): string
    {
        $s = strtoupper($s);
        $s = preg_replace('/\s*-\s*CLASS.*$/', '', $s);
        $s = preg_replace('/\b(INCORPORATED|CORPORATION|COMPANY|LIMITED|LTD|TBK|PLC|TRUST|ETF|SHARES|PERSERO|CO)\b/', '', $s);
        $s = preg_replace('/[^A-Z0-9]+/', ' ', $s);

        return trim($s);
    }

    /** Display name — drop the trailing share-class only. */
    private function cleanStock(string $s): string
    {
        return trim((string) preg_replace('/\s*-\s*Class.*$/i', '', $s));
    }
}
