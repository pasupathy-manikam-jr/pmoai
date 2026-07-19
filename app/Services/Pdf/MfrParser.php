<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Parses Public Mutual's monthly "Public Series of Funds" report (MFR).
 *
 * Strategy: pdftotext -layout preserves the 2-column page geometry, so
 * each fund's whole page can be sliced as text between consecutive
 * `PUBLIC … FUND (CODE)` headings and then mined by anchored regexes
 * — Fund Size, Volatility Factor, Geographical Breakdown, Top Sectors,
 * Top Holdings, Asset Allocation, Calendar Returns, Distribution History.
 *
 * Anything that fails to match is left null; the row still upserts so
 * later monthly captures can fill the gap.
 */
class MfrParser
{
    /** Lipper volatility band labels seen in the MFR. */
    private const VOL_CLASSES = '/(V\.\s*HIGH|VERY HIGH|HIGH|MODERATE|V\.\s*LOW|VERY LOW|LOW)/i';

    /** A4 fund pages render as a 2-column layout; right column starts ~col 130. */
    private const COL_SPLIT = 130;

    public function __construct(private readonly array $fxMap)
    {
    }

    /**
     * @return array{period: ?string, funds: array<int, array<string, mixed>>}
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("MFR file not found: $path");
        }

        $text = $this->pdftotext($path);
        $period = $this->detectPeriod($text);
        $funds = $this->splitFundBlocks($text);

        $out = [];
        foreach ($funds as [$code, $name, $block]) {
            $out[] = $this->parseBlock($code, $name, $block);
        }

        return ['period' => $period, 'funds' => $out];
    }

    private function pdftotext(string $path): string
    {
        // Under PHP-FPM the PATH lacks homebrew, so a bare "pdftotext"
        // silently produces nothing — resolve an absolute binary.
        $bin = config('ai.pdftotext_bin');
        if (! $bin || ! is_executable($bin)) {
            foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $cand) {
                if (is_executable($cand)) {
                    $bin = $cand;
                    break;
                }
            }
            $bin = $bin ?: 'pdftotext';
        }

        $cmd = escapeshellcmd($bin).' -layout '.escapeshellarg($path).' -';
        $out = shell_exec($cmd);
        if ($out === null || $out === '') {
            throw new RuntimeException("pdftotext produced no output for $path (bin: $bin)");
        }
        return $out;
    }

    /** "APRIL 2026" → "2026-04". */
    private function detectPeriod(string $text): ?string
    {
        $months = [
            'JANUARY' => '01', 'FEBRUARY' => '02', 'MARCH' => '03',
            'APRIL' => '04', 'MAY' => '05', 'JUNE' => '06',
            'JULY' => '07', 'AUGUST' => '08', 'SEPTEMBER' => '09',
            'OCTOBER' => '10', 'NOVEMBER' => '11', 'DECEMBER' => '12',
        ];
        if (preg_match('/\b('.implode('|', array_keys($months)).')\s+(\d{4})\b/i', $text, $m)) {
            return $m[2].'-'.$months[strtoupper($m[1])];
        }
        return null;
    }

    /**
     * @return array<int, array{0:string,1:string,2:string}>
     */
    private function splitFundBlocks(string $text): array
    {
        // Heading shapes across booklets: "PUBLIC ... FUND (CODE)",
        // "PB ... FUND (CODE)", lowercase e-Series ("PUBLIC e-... (PeAGFF)"),
        // dotted names ("U.S."), share classes ("... FUND - CLASS A (PMMF-A)"),
        // FUND-less names ("PUBLIC ISLAMIC SELECT BOND (PISBF)"), and an
        // optional trailing "EPF Qualified Fund" label on the same line.
        $heading = '/^\s+((?:PUBLIC|PB)[A-Za-z0-9 .&\/\-]+?)\s*\(([A-Za-z][A-Za-z0-9\-\. ]*)\)[ \t]*(?:EPF[ A-Za-z]*)?$/m';
        if (! preg_match_all($heading, $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $blocks = [];
        $n = count($m[0]);
        for ($i = 0; $i < $n; $i++) {
            $start = $m[0][$i][1];
            $end = $i + 1 < $n ? $m[0][$i + 1][1] : strlen($text);
            $name = preg_replace('/\s+/', ' ', trim($m[1][$i][0]));
            $code = trim($m[2][$i][0]);
            $blocks[] = [$code, $name, substr($text, $start, $end - $start)];
        }
        return $blocks;
    }

    private function parseBlock(string $code, string $name, string $block): array
    {
        [$left, $right] = $this->splitColumns($block);

        $row = [
            'code' => $code,
            'name' => $name,
            'fund_size_nav_myr'  => null,
            'fund_size_units'    => null,
            'benchmark_name'     => $this->benchmark($left),
            'benchmark_returns'  => null,
            'volatility_factor'  => null,
            'volatility_class'   => null,
            'asset_allocation'   => $this->assetAllocation($left),
            'geo_foreign'        => null,
            'fx_exposure'        => null,
            'fx_foreign_total_pct' => null,
            'top_sectors'        => null,
            'top_holdings'       => $this->topHoldings($right),
            'distributions'      => $this->distributions($right),
            'calendar_returns'   => $this->calendarReturns($left),
            'duration_yrs'       => null,
        ];

        [$nav, $units] = $this->fundSize($left);
        $row['fund_size_nav_myr'] = $nav;
        $row['fund_size_units']   = $units;

        [$vf, $vc] = $this->volatility($block);
        $row['volatility_factor'] = $vf;
        $row['volatility_class']  = $vc;

        $row['benchmark_returns'] = $this->returnsTable($left);

        $geo = $this->geoForeign($right);
        $row['geo_foreign'] = $geo;
        [$fx, $fxTotal] = $this->fxFromGeo($geo);
        $row['fx_exposure']          = $fx;
        $row['fx_foreign_total_pct'] = $fxTotal;

        $row['top_sectors'] = $this->topSectors($right);

        return $row;
    }

    /**
     * MFR fund pages are 2-column. pdftotext -layout concatenates the right
     * column onto each line of the left, so left-only and right-only text
     * are recovered by slicing each line at a fixed column.
     *
     * @return array{0:string,1:string}
     */
    private function splitColumns(string $block): array
    {
        $left = $right = '';
        foreach (preg_split('/\n/', $block) as $line) {
            // mb_substr keeps utf-8 chars (•, smart quotes) intact.
            $l = rtrim(mb_substr($line, 0, self::COL_SPLIT));
            $r = rtrim(mb_substr($line, self::COL_SPLIT));
            $left  .= "{$l}\n";
            $right .= ltrim($r)."\n";
        }
        return [$left, $right];
    }

    // ---- per-section extractors -----------------------------------------

    /** [nav_myr_in_RM, units_in_units] both in raw RM / units (Million factor applied). */
    private function fundSize(string $b): array
    {
        $nav = null;
        $units = null;
        if (preg_match('/NAV\s*:\s*RM\s*([\d.,]+)\s*Million/i', $b, $m)) {
            $nav = (float) str_replace(',', '', $m[1]) * 1_000_000;
        }
        if (preg_match('/UNITS\s*:\s*([\d.,]+)\s*Million/i', $b, $m)) {
            $units = (float) str_replace(',', '', $m[1]) * 1_000_000;
        }
        return [$nav, $units];
    }

    /** ["6.7", "Low"] from "Volatility Factor (VF) for this fund is 6.7 and is classified as \"Low\"" */
    private function volatility(string $b): array
    {
        if (preg_match(
            '/Volatility Factor.*?is\s+([\d.]+)\s+and is classified as\s+"?([A-Za-z. ]+?)"?\s*\(/is',
            $b,
            $m
        )) {
            $cls = trim($m[2]);
            // Normalize: "V. High" / "Very High" → "v.high"
            $norm = strtolower(preg_replace('/\s+/', '', $cls));
            return [(float) $m[1], $norm];
        }
        return [null, null];
    }

    /** First line under "Benchmark:" or implicit from "Performance of X vs its Benchmark Index". */
    private function benchmark(string $b): ?string
    {
        if (preg_match('/Benchmark:\s*\n\s*([^\n]+)/', $b, $m)) {
            $line = trim($m[1]);
            // Skip placeholder "Index data are sourced from Lipper." style lines.
            if ($line && ! preg_match('/^Index data/i', $line)) {
                return $line;
            }
        }
        if (preg_match('/vs (its )?Benchmark Index.*?\n(.*?)(?=\n\s*Performance|\n\s*Following)/is', $b, $m)) {
            return null; // table follows; benchmark name not always inline
        }
        return null;
    }

    /**
     * {ytd, 1y, 3y, 5y, 10y, 20y, 30y, since_commencement}
     * each value: {fund_total, bench_total, fund_ann, bench_ann}
     */
    private function returnsTable(string $b): ?array
    {
        // Anchor on labels at line start.
        $labels = [
            'ytd'   => 'Year-to-Date',
            '1y'    => '1-year',
            '3y'    => '3-year',
            '5y'    => '5-year',
            '10y'   => '10-year',
            '20y'   => '20-year',
            '30y'   => '30-year',
            'since' => 'Since Commencement',
        ];
        $out = [];
        foreach ($labels as $key => $lab) {
            $re = '/^\s*'.preg_quote($lab, '/').'\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+|-)\s+([\-\d.]+|-)/m';
            if (preg_match($re, $b, $m)) {
                $out[$key] = [
                    'fund_total'  => $this->num($m[1]),
                    'bench_total' => $this->num($m[2]),
                    'fund_ann'    => $this->num($m[3]),
                    'bench_ann'   => $this->num($m[4]),
                ];
            }
        }
        return $out ?: null;
    }

    /** [{Asset Type}, pct] rows under "Asset Allocation as at …". */
    private function assetAllocation(string $b): ?array
    {
        if (! preg_match('/Asset Allocation as at[^\n]*\n(.*?)(?=\n\s*Annual Returns|\n\s*Distribution History|\Z)/is', $b, $m)) {
            return null;
        }
        $body = $m[1];
        $out = [];
        foreach (preg_split('/\n/', $body) as $line) {
            if (preg_match('/^\s*(.+?)\s+([\d.]+)\s*%\s*$/', $line, $r)) {
                $label = trim($r[1]);
                if (stripos($label, 'Asset Type') === 0 || stripos($label, 'Total') === 0) {
                    continue;
                }
                $out[$label] = (float) $r[2];
            }
        }
        return $out ?: null;
    }

    /** {country: pct} from "Geographical Breakdown - Foreign". */
    private function geoForeign(string $b): ?array
    {
        if (! preg_match(
            '/Geographical Breakdown - Foreign\s*\n(?:\s*Breakdown\s*\n)?(.*?)(?=\n\s*Top 5 Sectors|\n\s*Top 5 Holdings|\n\s*Performance of)/is',
            $b,
            $m
        )) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\n/', $m[1]) as $line) {
            if (preg_match('/^\s*(.+?)\s+([\d.]+)\s*%\s*$/', $line, $r)) {
                $country = trim($r[1]);
                if ($country === '' || $country === 'Breakdown') {
                    continue;
                }
                $out[$country] = (float) $r[2];
            }
        }
        return $out ?: null;
    }

    /**
     * fx_exposure {USD: 13.7, ...} + total foreign pct.
     * Falls back to (null, null) if no geo rows.
     */
    private function fxFromGeo(?array $geo): array
    {
        if (! $geo) {
            return [null, null];
        }
        $fx = [];
        $total = 0.0;
        foreach ($geo as $country => $pct) {
            $ccy = $this->fxMap[$country] ?? null;
            if ($ccy === null) {
                continue;
            }
            $fx[$ccy] = ($fx[$ccy] ?? 0) + $pct;
            $total += $pct;
        }
        return [$fx ?: null, $total > 0 ? round($total, 2) : null];
    }

    /** {sector: pct} from "Top 5 Sectors" block. */
    private function topSectors(string $b): ?array
    {
        if (! preg_match(
            '/Top 5 Sectors\s*\n(?:\s*Sectors\s*\n)?(.*?)(?=\n\s*Top 5 Holdings|\n\s*Asset Allocation|\n\s*Performance of)/is',
            $b,
            $m
        )) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\n/', $m[1]) as $line) {
            if (preg_match('/^\s*(.+?)\s+([\d.]+)\s*%\s*$/', $line, $r)) {
                $sector = trim($r[1]);
                if ($sector === '' || stripos($sector, 'Sectors') === 0) {
                    continue;
                }
                $out[$sector] = (float) $r[2];
            }
        }
        return $out ?: null;
    }

    /** [security_name, ...] under "Top 5 Holdings" / "Security Name". */
    private function topHoldings(string $b): ?array
    {
        if (! preg_match(
            '/Top 5 Holdings\s*\n\s*Security Name\s*\n(.*?)(?=\n\s*Distribution History|\n\s*Asset Allocation|\n\s*Annual Returns|\Z)/is',
            $b,
            $m
        )) {
            return null;
        }
        $out = [];
        $count = 0;
        foreach (preg_split('/\n/', $m[1]) as $line) {
            $line = trim($line);
            if ($line === '' || $count >= 5) {
                if ($line === '' && $out) {
                    break;
                }
                continue;
            }
            // Stop at next labeled section header.
            if (preg_match('/^(Distribution|Asset|Annual|Notes|Benchmark|Performance|Total|Year|Source)/i', $line)) {
                break;
            }
            $out[] = $line;
            $count++;
        }
        return $out ?: null;
    }

    /**
     * Distribution History rows: [{year, type, sen, date, yield_pct}].
     * Schema varies (some funds Final/Interim, some only Financial Year),
     * so be permissive: capture year-ish first token, last %ish token,
     * middle date token.
     */
    private function distributions(string $b): ?array
    {
        if (! preg_match(
            '/Distribution History\s*\n(.*?)(?=\nNote:|\nAsset Allocation|\nAnnual Returns|\Z)/is',
            $b,
            $m
        )) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\n/', $m[1]) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // "2017 1.75 31.3.17 2.8" or "Final 0.25 31.5.16 1.0"
            if (preg_match(
                '/^(\S+)\s+([\d.]+)\s+(\d{1,2}\.\d{1,2}\.\d{2,4})\s+([\d.]+)$/',
                $line,
                $r
            )) {
                $out[] = [
                    'key'       => $r[1],
                    'sen'       => (float) $r[2],
                    'date'      => $r[3],
                    'yield_pct' => (float) $r[4],
                ];
            }
        }
        return $out ?: null;
    }

    /**
     * {years: [...], fund: [...], bench: [...]} from the 10-year
     * calendar-year strip.
     */
    private function calendarReturns(string $b): ?array
    {
        if (! preg_match('/Calendar Year\s+((?:\d{4}\s+)+\d{4})/i', $b, $hm)) {
            return null;
        }
        $years = preg_split('/\s+/', trim($hm[1]));

        $fund = $bench = null;
        if (preg_match('/Fund Return.*?%\)\s+((?:\-?[\d.]+\s+)+\-?[\d.]+)/is', $b, $fm)) {
            $fund = array_map([$this, 'num'], preg_split('/\s+/', trim($fm[1])));
        }
        if (preg_match('/Benchmark\s*\(%\)\s+((?:\-?[\d.]+\s+)+\-?[\d.]+)/i', $b, $bm)) {
            $bench = array_map([$this, 'num'], preg_split('/\s+/', trim($bm[1])));
        }
        if (! $fund && ! $bench) {
            return null;
        }
        return [
            'years'     => array_map('intval', $years),
            'fund_pct'  => $fund,
            'bench_pct' => $bench,
        ];
    }

    private function num(?string $s): ?float
    {
        if ($s === null || $s === '-' || $s === '--') {
            return null;
        }
        $s = trim(str_replace(['%', ','], '', $s));
        return is_numeric($s) ? (float) $s : null;
    }
}
