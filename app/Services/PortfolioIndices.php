<?php

namespace App\Services;

use App\Models\FundDetail;

/**
 * Derives the dashboard's market indices from where the portfolio ACTUALLY
 * invests — parsing each held fund's captured "Geographical Breakdown" (in
 * payload.allocation), weighting each country by the holding's RM value, and
 * mapping the biggest exposures to their market index. Gold funds map to the
 * gold price (their "USA" line is just US-listed gold ETFs, not US equity).
 * Always appends the macro trio: gold (if held), USD/MYR, and the home KLCI.
 */
class PortfolioIndices
{
    /** country (upper) => [yahoo symbol, tradingview symbol, label] */
    private const MAP = [
        'USA'           => ['^IXIC', 'NASDAQ:IXIC',      'NASDAQ (US)'],
        'INDONESIA'     => ['^JKSE', 'IDX:COMPOSITE',    'Jakarta'],
        'TAIWAN'        => ['^TWII', 'TWSE:TAIEX',       'Taiwan TAIEX'],
        'KOREA'         => ['^KS11', 'KRX:KOSPI',        'Korea KOSPI'],
        'INDIA'         => ['^NSEI', 'NSE:NIFTY',        'India Nifty'],
        'CHINA'         => ['^HSI',  'HSI:HSI',          'Hang Seng'],
        'JAPAN'         => ['^N225', 'TVC:NI225',        'Japan Nikkei'],
        'GREAT BRITAIN' => ['^FTSE', 'TVC:UKX',          'UK FTSE 100'],
        'NETHERLANDS'   => ['^AEX',  'EURONEXT:AEX',     'Netherlands AEX'],
        'FRANCE'        => ['^FCHI', 'TVC:CAC40',        'France CAC 40'],
        'SINGAPORE'     => ['^STI',  'TVC:STI',          'Singapore STI'],
        'AUSTRALIA'     => ['^AXJO', 'ASX:XJO',          'Australia ASX 200'],
    ];

    /** Lines in the geo block that are NOT countries. */
    private const NON_COUNTRY = [
        'TECHNOLOGY', 'COMMUNICATIONS', 'INDUSTRIAL', 'FINANCIAL', 'CONSUMER',
        'CONSUMER, CYCLICAL', 'UTILITIES', 'ENERGY', 'HEALTHCARE', 'MATERIALS',
        'ETF', 'BASIC MATERIALS', 'REAL ESTATE',
    ];

    private const MAX_COUNTRY_INDICES = 6;

    /**
     * @return array<int, array{symbol:string,label:string,tag:string,tv:string}>
     */
    public function derive(): array
    {
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get();

        $exposure = [];    // country => RM
        $contrib = [];     // country => [ [name, rm], ... ]
        $goldFunds = [];   // [ [name, rm], ... ]
        $foreignFunds = []; // name => rm  (any non-Malaysia exposure)
        $homeFunds = [];   // name => rm  (PRS / Malaysia)

        foreach ($held as $d) {
            $val = (float) $d->payload['position']['current_value'];
            $name = $this->short($d->name);

            if (preg_match('/EMAS|GOLD/i', $d->name)) {
                $goldFunds[] = ['name' => $name, 'rm' => $val];
                continue;
            }
            if (preg_match('/\bPRS\b/i', $d->name)) {
                $homeFunds[$name] = $val;   // retirement, Malaysian
                continue;
            }
            $geo = $this->parseGeo($d->payload['allocation'] ?? '') ?: $this->heuristic($d->name);
            $isForeign = false;
            foreach ($geo as $country => $pct) {
                $rm = $val * $pct / 100;
                $exposure[$country] = ($exposure[$country] ?? 0) + $rm;
                $contrib[$country][] = ['name' => $name, 'rm' => $rm];
                if ($country === 'MALAYSIA') {
                    $homeFunds[$name] = ($homeFunds[$name] ?? 0) + $rm;
                } else {
                    $isForeign = true;
                }
            }
            if ($isForeign) {
                $foreignFunds[$name] = ($foreignFunds[$name] ?? 0) + $val;
            }
        }

        arsort($exposure);

        $out = [];
        foreach ($exposure as $country => $rm) {
            if (! isset(self::MAP[$country])) {
                continue;
            }
            [$sym, $tv, $label] = self::MAP[$country];
            $out[$sym] = ['symbol' => $sym, 'tv' => $tv, 'label' => $label,
                'tag' => '~RM'.number_format($rm, 0).' of your book',
                'funds' => $this->rankFunds($contrib[$country] ?? [])];
            if (count($out) >= self::MAX_COUNTRY_INDICES) {
                break;
            }
        }

        // Macro trio — always relevant to a Malaysian portfolio.
        if ($goldFunds) {
            $out['GC=F'] = ['symbol' => 'GC=F', 'tv' => 'TVC:GOLD', 'label' => 'Gold (spot)',
                'tag' => '~RM'.number_format(array_sum(array_column($goldFunds, 'rm')), 0).' in the gold fund',
                'funds' => $this->rankFunds($goldFunds)];
        }
        $out['MYR=X'] = ['symbol' => 'MYR=X', 'tv' => 'FX_IDC:USDMYR', 'label' => 'USD / MYR',
            'tag' => 'RM value of every foreign fund',
            'funds' => $this->rankFunds(array_map(fn ($n, $v) => ['name' => $n, 'rm' => $v], array_keys($foreignFunds), $foreignFunds))];
        $out['^KLSE'] = ['symbol' => '^KLSE', 'tv' => 'FTSEMYX:FBMKLCI', 'label' => 'FBM KLCI',
            'tag' => 'Malaysia home · PRS',
            'funds' => $this->rankFunds(array_map(fn ($n, $v) => ['name' => $n, 'rm' => $v], array_keys($homeFunds), $homeFunds))];

        return array_values($out);
    }

    /** Symbols only — for the quote fetcher. @return string[] */
    public function symbols(): array
    {
        return array_column($this->derive(), 'symbol');
    }

    /**
     * Per held fund: name, RM value, its captured geography {country: pct}, and
     * a gold flag. The raw material for the stress test. Built from the same
     * captured PMO factsheet geography the dashboard uses.
     *
     * @return array<int, array{name:string, value:float, geo:array<string,float>, gold:bool}>
     */
    public function fundGeo(): array
    {
        $out = [];
        foreach (FundDetail::whereRaw("payload->'position'->>'current_value' is not null")->get() as $d) {
            $val = (float) $d->payload['position']['current_value'];
            $name = trim((string) preg_replace('/^PUBLIC\s+/i', '', $d->name));
            if (preg_match('/EMAS|GOLD/i', $d->name)) {
                $out[] = ['name' => $name, 'value' => $val, 'geo' => [], 'gold' => true];

                continue;
            }
            $geo = $this->parseGeo($d->payload['allocation'] ?? '') ?: $this->heuristic($d->name);
            $out[] = ['name' => $name, 'value' => $val, 'geo' => $geo, 'gold' => false];
        }

        return $out;
    }

    /** Short fund label — drop the "PUBLIC " prefix. */
    private function short(string $name): string
    {
        return trim((string) preg_replace('/^PUBLIC\s+/i', '', $name));
    }

    /**
     * Merge same-named contributors, sort by RM desc, keep the top 3.
     *
     * @param  array<int, array{name:string,rm:float}>  $rows
     * @return array<int, array{name:string,rm:float}>
     */
    private function rankFunds(array $rows): array
    {
        $merged = [];
        foreach ($rows as $r) {
            $merged[$r['name']] = ($merged[$r['name']] ?? 0) + $r['rm'];
        }
        arsort($merged);

        return array_map(fn ($n, $v) => ['name' => $n, 'rm' => $v],
            array_slice(array_keys($merged), 0, 3),
            array_slice(array_values($merged), 0, 3));
    }

    /** {country: pct} from a captured allocation blob; [] if none. */
    private function parseGeo(string $text): array
    {
        if (! preg_match('/Geographical Breakdown[^\n]*\n(.*?)(?:Top 5 Sectors|Top 5 Holdings|$)/s', $text, $m)) {
            return [];
        }
        $geo = [];
        foreach (preg_split('/\n/', trim($m[1])) as $line) {
            if (preg_match('/^(.+?)\s+([\d.]+)%/', trim($line), $c)) {
                $country = strtoupper(trim($c[1]));
                if (in_array($country, self::NON_COUNTRY, true)) {
                    continue;
                }
                $geo[$country] = (float) $c[2];
            }
        }

        return $geo;
    }

    /** Fallback geography from the fund name when nothing was captured. */
    private function heuristic(string $name): array
    {
        $n = strtoupper($name);
        foreach (['INDONESIA', 'INDIA', 'CHINA', 'JAPAN', 'KOREA', 'TAIWAN', 'SINGAPORE', 'AUSTRALIA'] as $c) {
            if (str_contains($n, $c)) {
                return [$c => 100.0];
            }
        }
        if (preg_match('/\bUS\b|U\.S\.|AMERICA|ARTIFICIAL|TECHNOLOGY/', $n)) {
            return ['USA' => 100.0];
        }

        return [];
    }
}
