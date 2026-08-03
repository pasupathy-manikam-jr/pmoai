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
