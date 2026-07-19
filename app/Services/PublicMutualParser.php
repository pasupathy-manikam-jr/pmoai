<?php

namespace App\Services;

/**
 * Parses pasted Public Mutual data from up to 3 tabs and merges by fund code.
 *
 *  Prices       : Fund | Date | Price RM | Change RM | Change %
 *  Performance  : Fund | Date | Factor | Class | YTD | 1Y | 3Y | 5Y | 10Y
 *  Info         : Fund | Shariah | Category | Risk | Since Inception | Size
 *
 * Input may be a single blob (treated as Prices, back-compat) or the three
 * segments joined with sentinels by the controller:
 *
 *   [[PMOAI:PRICES]] ... [[PMOAI:PERFORMANCE]] ... [[PMOAI:INFO]] ...
 *
 * The site's "Action" column pastes as junk tokens (Add Favourite, Chart,
 * Add to Compare, Buy) — these are stripped.
 */
class PublicMutualParser
{
    private const SHARIAH = ['ISLAMIC', 'SHARIAH', 'ITTIKAL', 'SUKUK', 'AL-', 'AMANAH'];

    private const TYPES = [
        'SUKUK' => 'sukuk', 'BOND' => 'bond', 'BALANCED' => 'balanced',
        'MIXED' => 'mixed', 'MONEY' => 'money market', 'INCOME' => 'income',
        'EQUITY' => 'equity', 'GROWTH' => 'equity', 'SMALLCAP' => 'equity',
        'DIVIDEND' => 'equity',
    ];

    private const JUNK = [
        'ADD FAVOURITE', 'FAVOURITE', 'CHART', 'ADD TO COMPARE', 'COMPARE',
        'BUY', 'DOCUMENTS', 'ACTION',
    ];

    public function parse(string $raw): array
    {
        [$prices, $perf, $info] = $this->split($raw);

        $funds = [];
        $segments = [
            'prices'      => $prices,
            'performance' => $perf,
            'info'        => $info,
        ];

        foreach ($segments as $kind => $seg) {
            if (trim($seg) === '') {
                continue;
            }
            // Preferred: header-delimited TSV from the console snippet.
            $rows = $this->parseHeaderTsv($seg);
            // Fallback: legacy manual/CSV/token layouts.
            if (! $rows) {
                $rows = match ($kind) {
                    'performance' => $this->parsePerformance($seg, $funds),
                    'info'        => $this->parseInfo($seg),
                    default       => $this->parsePrices($seg),
                };
            }
            foreach ($rows as $r) {
                $k = $this->key($r);
                $base = $funds[$k] ?? $this->blank($r['name'], $r['extra']['code'] ?? null);
                $funds[$k] = $this->overlay($base, $r);
            }
        }

        return array_values($funds);
    }

    /** Overlay non-empty fields of $r onto $base (merge across tabs). */
    private function overlay(array $base, array $r): array
    {
        foreach ($r as $key => $val) {
            if ($key === 'extra') {
                foreach ((array) $val as $ek => $ev) {
                    if ($ev !== null && $ev !== '') {
                        $base['extra'][$ek] = $ev;
                    }
                }
                continue;
            }
            if ($key === 'shariah') {
                $base['shariah'] = $base['shariah'] || $val;
                continue;
            }
            if ($val !== null && $val !== '') {
                $base[$key] = $val;
            }
        }
        return $base;
    }

    /**
     * Parse the canonical TSV the console snippet emits:
     *   Code <tab> Name <tab> <site headers...>
     * Columns mapped by header label, so all 3 tabs share one path.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseHeaderTsv(string $seg): array
    {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $seg)),
            fn ($l) => $l !== ''
        ));

        $hi = null;
        foreach ($lines as $i => $l) {
            if (stripos($l, "code\tname") === 0 || preg_match('/^code\tname\t/i', $l)) {
                $hi = $i;
                break;
            }
        }
        if ($hi === null) {
            return [];
        }

        $headers = array_map(fn ($h) => $this->classify($h), explode("\t", $lines[$hi]));
        $funds = [];

        for ($i = $hi + 1; $i < count($lines); $i++) {
            $cols = explode("\t", $lines[$i]);
            if (count($cols) < 2) {
                continue;
            }
            $row = [];
            foreach ($headers as $idx => $canon) {
                if ($canon !== null && isset($cols[$idx])) {
                    $row[$canon] = trim($cols[$idx]);
                }
            }
            // Fund cell line order varies per tab; decide by shape, not
            // position. Code = spaceless short token; name = the longer /
            // spaced one.
            $a = trim((string) ($row['code'] ?? ''));
            $b = trim((string) ($row['name'] ?? ''));
            $isCode = fn ($s) => $s !== '' && ! str_contains($s, ' ')
                && (bool) preg_match('/^[A-Za-z][A-Za-z0-9.\-]{1,13}$/', $s);

            if ($isCode($a) && ! $isCode($b)) {
                $code = $a;
                $name = $b;
            } elseif ($isCode($b) && ! $isCode($a)) {
                $code = $b;
                $name = $a;
            } else {
                $name = $b !== '' ? $b : $a;
                $code = $a !== '' && $a !== $name ? $a : ($b !== $name ? $b : null);
            }
            if ($name === '') {
                continue;
            }

            $f = $this->blank($name, $code ?: null);
            $this->set($f, 'unit_price', $this->num($row['unit_price'] ?? null));
            $this->set($f, 'return_ytd', $this->num($row['return_ytd'] ?? null));
            $this->set($f, 'return_1y', $this->num($row['return_1y'] ?? null));
            $this->set($f, 'return_3y', $this->num($row['return_3y'] ?? null));
            $this->set($f, 'return_5y', $this->num($row['return_5y'] ?? null));
            $this->set($f, 'return_10y', $this->num($row['return_10y'] ?? null));
            $this->set($f, 'perf_factor', $this->num($row['perf_factor'] ?? null));
            $this->set($f, 'perf_class', $row['perf_class'] ?? null);
            $this->set($f, 'category', $row['category'] ?? null);
            $this->set($f, 'risk', $row['risk'] ?? null);
            $this->set($f, 'since_inception', $row['since_inception'] ?? null);
            $this->set($f, 'fund_size', $row['fund_size'] ?? null);

            if (isset($row['date']) && $row['date'] !== '') {
                $f['perf_date'] = $row['date'];
                $f['extra']['price_date'] = $row['date'];
            }
            if (isset($row['change_pct'])) {
                $f['extra']['change_pct'] = $this->num($row['change_pct']);
            }
            if (isset($row['change_rm'])) {
                $f['extra']['change_rm'] = $this->num($row['change_rm']);
            }
            if (! empty($row['shariah'])) {
                $s = strtoupper($row['shariah']);
                if (! in_array($s, ['-', 'NO', 'N', 'NON'], true)) {
                    $f['shariah'] = true;
                }
            }

            $funds[] = $f;
        }
        return $funds;
    }

    private function set(array &$f, string $key, $val): void
    {
        if ($val !== null && $val !== '') {
            $f[$key] = $val;
        }
    }

    /** Map a site header label to a canonical field key (or null = ignore). */
    private function classify(string $label): ?string
    {
        $u = strtoupper(preg_replace('/[^A-Z0-9%]/i', '', $label));

        return match (true) {
            $u === 'CODE'                       => 'code',
            $u === 'NAME'                       => 'name',
            str_contains($u, 'YTD')             => 'return_ytd',
            (bool) preg_match('/(^|[^0-9])10(YR|Y|YEAR)/', $u) => 'return_10y',
            (bool) preg_match('/(^|[^0-9])5(YR|Y|YEAR)/', $u)  => 'return_5y',
            (bool) preg_match('/(^|[^0-9])3(YR|Y|YEAR)/', $u)  => 'return_3y',
            (bool) preg_match('/(^|[^0-9])1(YR|Y|YEAR)/', $u)  => 'return_1y',
            str_contains($u, 'FACTOR')          => 'perf_factor',
            str_contains($u, 'CLASS')           => 'perf_class',
            str_contains($u, 'PRICE')           => 'unit_price',
            str_contains($u, 'CHANGE') && str_contains($u, '%') => 'change_pct',
            str_contains($u, 'CHANGE')          => 'change_rm',
            str_contains($u, 'DATE')            => 'date',
            str_contains($u, 'SHARIAH')         => 'shariah',
            str_contains($u, 'CATEG')           => 'category',
            str_contains($u, 'RISK')            => 'risk',
            str_contains($u, 'SINCE') || str_contains($u, 'INCEPT') => 'since_inception',
            str_contains($u, 'SIZE')            => 'fund_size',
            default                             => null,
        };
    }

    /** @return array{0:string,1:string,2:string} prices, performance, info */
    private function split(string $raw): array
    {
        if (! str_contains($raw, '[[PMOAI:')) {
            return [$raw, '', ''];
        }
        $get = function (string $tag) use ($raw): string {
            if (preg_match('/\[\[PMOAI:'.$tag.'\]\](.*?)(?=\[\[PMOAI:|$)/s', $raw, $m)) {
                return trim($m[1]);
            }
            return '';
        };
        return [$get('PRICES'), $get('PERFORMANCE'), $get('INFO')];
    }

    /** Non-empty, trimmed, junk-stripped tokens (split on newlines + tabs). */
    private function tokens(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n|\t/', $raw) as $l) {
            $l = trim($l);
            if ($l === '') {
                continue;
            }
            if (in_array(strtoupper($l), self::JUNK, true)) {
                continue;
            }
            $out[] = $l;
        }
        return $out;
    }

    // ---- Prices -----------------------------------------------------------

    private function parsePrices(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        // CSV export (col0 = "NAME\nCODE", date, price, chg, chg%).
        if ($this->looksLikeCsv($raw)) {
            return $this->parsePricesCsv($raw);
        }

        $t = $this->tokens($raw);
        $n = count($t);
        $funds = [];

        for ($i = 0; $i < $n; $i++) {
            $line = $t[$i];
            $priceDate = $price = $chg = $pct = null;

            if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $line)) {
                $priceDate = $line;
                $price = $t[$i + 1] ?? null;
                $chg   = $t[$i + 2] ?? null;
                $pct   = $t[$i + 3] ?? null;
            } elseif (preg_match('#^(\d{2}/\d{2}/\d{4})\s*(-?\d+\.\d+)\s*(-?\d+\.\d+)\s*(-?\d+\.\d+)\s*%?$#', $line, $m)) {
                $priceDate = $m[1];
                $price = $m[2];
                $chg   = $m[3];
                $pct   = $m[4];
            } else {
                continue;
            }

            $name = $t[$i - 2] ?? null;
            $code = $t[$i - 1] ?? null;
            if (! $name || ! is_numeric($price)) {
                continue;
            }

            $funds[] = $this->row($name, $code, (float) $price, [
                'price_date' => $priceDate,
                'change_rm'  => is_numeric($chg) ? (float) $chg : null,
                'change_pct' => $this->num($pct),
            ]);
        }
        return $funds;
    }

    private function parsePricesCsv(string $raw): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);

        $funds = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < 5) {
                continue;
            }
            [$nameCode, $date, $price, $chg, $pct] = $r;
            if (! preg_match('#^\d{2}/\d{2}/\d{4}$#', trim($date)) || ! is_numeric(trim($price))) {
                continue;
            }
            $parts = preg_split('/\r\n|\r|\n/', trim($nameCode));
            $name = trim($parts[0] ?? '');
            $code = isset($parts[1]) ? trim($parts[1]) : null;
            if ($name === '') {
                continue;
            }
            $funds[] = $this->row($name, $code, (float) trim($price), [
                'price_date' => trim($date),
                'change_rm'  => is_numeric(trim($chg)) ? (float) trim($chg) : null,
                'change_pct' => $this->num($pct),
            ]);
        }
        fclose($fh);
        return $funds;
    }

    // ---- Performance ------------------------------------------------------

    /**
     * @param array<string, array<string, mixed>> $known Funds parsed from
     *        earlier tabs (price rows) — used to split a glued Code+Name
     *        when the paste lost tab delimiters.
     */
    private function parsePerformance(string $raw, array $known = []): array
    {
        if (trim($raw) === '') {
            return [];
        }

        // Tab-delimited paste lost? Each fund record then arrives as one
        // glued line (Code+Name+Date+Factor+Class+returns, no separators).
        // The legacy token walk below needs bare date tokens, so it yields
        // nothing here — route to the flat parser instead.
        if (! str_contains($raw, "\t") && $this->looksFlatPerformance($raw)) {
            return $this->parsePerformanceFlat($raw, $known);
        }

        $t = $this->tokens($raw);
        $n = count($t);
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            if (! preg_match('#^\d{2}/\d{2}/\d{4}$#', $t[$i])) {
                continue;
            }
            $name = $t[$i - 2] ?? null;
            $code = $t[$i - 1] ?? null;
            if (! $name) {
                continue;
            }

            // After date: factor (num), class (text), then numeric returns
            // YTD,1Y,3Y,5Y,10Y in order (10Y may be absent).
            $factor = $this->num($t[$i + 1] ?? null);
            $class  = $t[$i + 2] ?? null;
            $nums = [];
            for ($j = $i + 3; $j < $n && count($nums) < 5; $j++) {
                if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $t[$j])) {
                    break; // hit next record's date
                }
                if (is_numeric(str_replace(['%', ','], '', trim($t[$j])))) {
                    $nums[] = $this->num($t[$j]);
                } elseif ($nums) {
                    break; // numbers ended
                }
            }

            $out[] = [
                'name'  => $name,
                'extra' => ['code' => $code],
                'perf_factor' => $factor,
                'perf_class'  => $class,
                'perf_date'   => $t[$i],
                'return_ytd'  => $nums[0] ?? null,
                'return_1y'   => $nums[1] ?? null,
                'return_3y'   => $nums[2] ?? null,
                'return_5y'   => $nums[3] ?? null,
                'return_10y'  => $nums[4] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Heuristic: a tabless performance paste has data lines that embed a
     * dd/mm/yyyy NOT at the line start (Code+Name precede it on the same
     * line). The legacy path expects the date to stand alone as a token.
     */
    private function looksFlatPerformance(string $raw): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
            if (preg_match('#\S\d{2}/\d{2}/\d{4}#', trim($l))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse the glued performance layout one record per line:
     *   {Code}{Name}{dd/mm/yyyy}{Factor}{Class}{YTD}{1Y}{3Y}{5Y}{10Y}
     *
     * Code/Name have no separator, so the Code is recovered by longest
     * case-insensitive prefix match against codes parsed from earlier tabs
     * (the price rows). Returns are PM's fixed 2-decimal tokens; "--" = null.
     *
     * @param array<string, array<string, mixed>> $known
     * @return array<int, array<string, mixed>>
     */
    private function parsePerformanceFlat(string $raw, array $known): array
    {
        // Code list, longest first so "PBIASSF" wins over a shorter "PB".
        $codes = [];
        foreach ($known as $f) {
            $c = $f['extra']['code'] ?? null;
            if (is_string($c) && $c !== '') {
                $codes[strtoupper($c)] = $f['name'] ?? null;
            }
        }
        uksort($codes, fn ($a, $b) => strlen($b) <=> strlen($a));

        $classRe = 'V\. HIGH|VERY HIGH|HIGH|MODERATE|V\. LOW|VERY LOW|LOW';
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('#(\d{2}/\d{2}/\d{4})#', $line, $dm, PREG_OFFSET_CAPTURE)) {
                continue; // header / blank
            }
            $date    = $dm[1][0];
            $dateAt  = $dm[1][1];
            $pre     = substr($line, 0, $dateAt);          // Code+Name
            $post    = substr($line, $dateAt + strlen($date));

            // Resolve code by longest prefix of the glued Code+Name.
            $code = null;
            $name = null;
            $preU = strtoupper($pre);
            foreach ($codes as $c => $cname) {
                if (str_starts_with($preU, $c)) {
                    $code = $c;
                    $name = trim(substr($pre, strlen($c))) ?: $cname;
                    break;
                }
            }
            if ($code === null) {
                // Unknown code: fall back to the site naming convention —
                // the Name starts at the first PUBLIC/PB/PRS token.
                if (preg_match('/\b(PUBLIC|PB|PRS|PCASH)\b/i', $pre, $nm, PREG_OFFSET_CAPTURE)) {
                    $at   = $nm[0][1];
                    $code = trim(substr($pre, 0, $at)) ?: null;
                    $name = trim(substr($pre, $at));
                } else {
                    continue; // can't split safely — skip, don't corrupt
                }
            }
            if (! $name) {
                continue;
            }

            // After the date: {Factor}{Class}{returns…}
            $factor = $class = null;
            if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*('.$classRe.')?/i', $post, $pm)) {
                $factor = $this->num($pm[1] ?? null);
                $class  = ($pm[2] ?? '') !== '' ? trim($pm[2]) : null;
                $post   = substr($post, strlen($pm[0]));
            }
            // PM returns are fixed 2-decimal; "--" marks a missing horizon.
            preg_match_all('/-?\d+\.\d{2}|--/', $post, $rm);
            $nums = array_map(
                fn ($v) => $v === '--' ? null : $this->num($v),
                $rm[0]
            );

            $out[] = [
                'name'        => $name,
                'extra'       => ['code' => $code],
                'perf_factor' => $factor,
                'perf_class'  => $class,
                'perf_date'   => $date,
                'return_ytd'  => $nums[0] ?? null,
                'return_1y'   => $nums[1] ?? null,
                'return_3y'   => $nums[2] ?? null,
                'return_5y'   => $nums[3] ?? null,
                'return_10y'  => $nums[4] ?? null,
            ];
        }
        return $out;
    }

    // ---- Info -------------------------------------------------------------

    private function parseInfo(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $t = $this->tokens($raw);
        $n = count($t);
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            // Anchor on "<n> Yr(s)" (Since Inception).
            if (! preg_match('#^\d+\s*Yrs?$#i', $t[$i])) {
                continue;
            }
            // Walk back to the fund code (short all-caps token).
            $codeIdx = null;
            for ($b = $i - 1; $b >= 0 && $b >= $i - 4; $b--) {
                if (preg_match('#^[A-Z][A-Z0-9]{2,9}$#', $t[$b])) {
                    $codeIdx = $b;
                    break;
                }
            }
            if ($codeIdx === null) {
                continue;
            }
            $code = $t[$codeIdx];
            $name = $t[$codeIdx - 1] ?? null;
            if (! $name) {
                continue;
            }

            // Between code and "Yrs": category (short) then maybe risk.
            $mid = array_slice($t, $codeIdx + 1, $i - $codeIdx - 1);
            $category = $mid[0] ?? null;
            $risk = $mid[1] ?? null;

            // Fund size = first "<num> mil/bil" after the Yrs token.
            $size = null;
            for ($f = $i + 1; $f < $n && $f <= $i + 3; $f++) {
                if (preg_match('#^[\d,]+(\.\d+)?\s*(mil|bil|m|b)$#i', $t[$f])) {
                    $size = $t[$f];
                    break;
                }
            }

            $out[] = [
                'name'            => $name,
                'extra'           => ['code' => $code],
                'category'        => $category,
                'risk'            => $risk,
                'since_inception' => $t[$i],
                'fund_size'       => $size,
                'shariah'         => $this->isShariah(strtoupper($name)),
            ];
        }
        return $out;
    }

    // ---- helpers ----------------------------------------------------------

    private function key(array $f): string
    {
        $code = $f['extra']['code'] ?? null;
        return $code ? strtoupper(trim($code)) : strtoupper(trim($f['name']));
    }

    private function row(string $name, ?string $code, float $price, array $extra): array
    {
        $f = $this->blank($name, $code);
        $f['unit_price'] = $price;
        $f['extra'] = array_merge($f['extra'], $extra);
        return $f;
    }

    private function blank(string $name, ?string $code): array
    {
        $upper = strtoupper($name);
        return [
            'name' => $name,
            'fund_type' => $this->type($upper),
            'shariah' => $this->isShariah($upper),
            'unit_price' => null,
            'selling_price' => null,
            'return_ytd' => null,
            'return_1y' => null,
            'return_3y' => null,
            'return_5y' => null,
            'return_10y' => null,
            'perf_factor' => null,
            'perf_class' => null,
            'perf_date' => null,
            'category' => null,
            'risk' => null,
            'since_inception' => null,
            'fund_size' => null,
            'currency' => 'MYR',
            'extra' => ['code' => $code],
        ];
    }

    private function isShariah(string $upper): bool
    {
        foreach (self::SHARIAH as $kw) {
            if (str_contains($upper, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function type(string $upper): ?string
    {
        foreach (self::TYPES as $kw => $type) {
            if (str_contains($upper, $kw)) {
                return $type;
            }
        }
        return null;
    }

    private function num(?string $s): ?float
    {
        if ($s === null) {
            return null;
        }
        $s = trim(str_replace(['%', ','], '', $s));
        return is_numeric($s) ? (float) $s : null;
    }

    private function looksLikeCsv(string $raw): bool
    {
        $head = substr(ltrim($raw), 0, 200);
        return str_contains($head, '","') || str_contains($head, "\",\n")
            || (str_contains($head, ',') && str_contains($head, '"'));
    }
}
