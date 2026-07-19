<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Parses a Public Mutual Product Highlights Sheet (PHS) PDF: one fund per
 * file. Output is the static reference data — category, asset-allocation
 * rule, risks, fees, valuation, min investment — that gets merged into
 * `fund_details.payload` to back the detail view and feed prompts.
 */
class PhsParser
{
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("PHS file not found: $path");
        }
        $text = $this->pdftotext($path);

        [$code, $name] = $this->codeAndName($text);

        return [
            'code'                  => $code,
            'name'                  => $name,
            'category'              => $this->labeled($text, 'Category of fund'),
            'fund_objective'        => $this->labeled($text, 'Fund objective'),
            'asset_allocation_rule' => $this->labeled($text, 'Asset allocation'),
            'location'              => $this->labeled($text, 'Location of assets'),
            'investor_profile'      => $this->labeled($text, 'Investor profile'),
            'risk_text'             => $this->risks($text),
            'fees'                  => $this->fees($text),
            'min_initial_invest'    => $this->labeled($text, 'Minimum initial investment'),
            'min_additional_invest' => $this->labeled($text, 'Minimum additional investment'),
            'avg_annual_returns'    => $this->avgAnnualReturns($text),
            'ptr'                   => $this->ptr($text),
            'phs_distributions'     => $this->phsDistributions($text),
            'source_pdf'            => basename($path),
            'raw_text'              => $text,
        ];
    }

    private function pdftotext(string $path): string
    {
        $cmd = 'pdftotext -layout '.escapeshellarg($path).' -';
        $out = shell_exec($cmd);
        if ($out === null || $out === '') {
            throw new RuntimeException("pdftotext produced no output for $path");
        }
        return $out;
    }

    /** ["PINDOSF", "PUBLIC INDONESIA SELECT FUND"] from heading. */
    private function codeAndName(string $text): array
    {
        if (preg_match('/^\s*(PUBLIC [A-Z 0-9&\/\-]+ FUND)\s*\(([A-Z][A-Z0-9\-\. ]+)\)/m', $text, $m)) {
            return [trim($m[2]), trim($m[1])];
        }
        return [null, null];
    }

    /**
     * Pull value(s) for a left-column label that is followed by free-form
     * text in the right column. PHS layout is 2-col so paragraphs split
     * across many lines until the next labeled row.
     */
    private function labeled(string $text, string $label): ?string
    {
        $re = '/^\s*'.preg_quote($label, '/').'\b[^\n]*\n((?:(?!^\s*[A-Z][A-Za-z ]{2,40}\s{2,}|\f).*\n?){1,40})/m';
        if (! preg_match($re, $text, $m)) {
            return null;
        }
        $body = preg_replace('/\s+/', ' ', trim($m[0]));
        // Strip the label prefix.
        $body = preg_replace('/^'.preg_quote($label, '/').'\b[^A-Za-z0-9]*/i', '', $body);
        return $body !== '' ? trim($body) : null;
    }

    /** Concatenate KEY RISKS section body. */
    private function risks(string $text): ?string
    {
        if (! preg_match('/KEY RISKS\s*\n(.*?)(?=FEES & CHARGES|PERFORMANCE OF|FEES AND CHARGES)/is', $text, $m)) {
            return null;
        }
        $body = trim(preg_replace('/\s+/', ' ', $m[1]));
        return $body !== '' ? $body : null;
    }

    /** {sales_charge, redemption_charge, switching_charge, management_fee, trustee_fee, transfer_charge}. */
    private function fees(string $text): array
    {
        $keys = [
            'sales_charge'      => 'Sales charge',
            'redemption_charge' => 'Redemption charge',
            'switching_charge'  => 'Switching charge',
            'transfer_charge'   => 'Transfer charge',
            'management_fee'    => 'Management fee',
            'trustee_fee'       => 'Trustee fee',
        ];
        $out = [];
        foreach ($keys as $k => $label) {
            $v = $this->labeled($text, $label);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Average Annual Returns row: {1y, 3y, 5y, 10y, since_commencement}
     * for both fund and benchmark.
     */
    private function avgAnnualReturns(string $text): ?array
    {
        if (! preg_match('/Average Annual Returns.*?\n.*?1-Year\s+3-Year\s+5-Year\s+10-Year\s+Since Commencement.*?\n(.*?)\n\s*(?:Annual Total Return|Notes:)/is', $text, $m)) {
            return null;
        }
        $rows = preg_split('/\n/', trim($m[1]));
        $out = [];
        foreach ($rows as $row) {
            if (preg_match('/^\s*(.+?)\s+\(\%\)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)\s+([\-\d.]+)/', $row, $rm)) {
                $out[trim($rm[1])] = [
                    '1y'    => (float) $rm[2],
                    '3y'    => (float) $rm[3],
                    '5y'    => (float) $rm[4],
                    '10y'   => (float) $rm[5],
                    'since' => (float) $rm[6],
                ];
            }
        }
        return $out ?: null;
    }

    /** PTR (time) per recent FY. */
    private function ptr(string $text): ?array
    {
        if (! preg_match('/PTR \(time\)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)/i', $text, $m)) {
            return null;
        }
        return [(float) $m[1], (float) $m[2], (float) $m[3]];
    }

    /** Gross / Net distribution per unit (sen) per recent FY. */
    private function phsDistributions(string $text): ?array
    {
        $out = [];
        if (preg_match('/Gross distribution per unit \(sen\)\s+(\S+)\s+(\S+)\s+(\S+)/i', $text, $m)) {
            $out['gross_sen'] = array_map(fn ($v) => $v === '-' ? null : (float) $v, [$m[1], $m[2], $m[3]]);
        }
        if (preg_match('/Net distribution per unit \(sen\)\s+(\S+)\s+(\S+)\s+(\S+)/i', $text, $m)) {
            $out['net_sen'] = array_map(fn ($v) => $v === '-' ? null : (float) $v, [$m[1], $m[2], $m[3]]);
        }
        return $out ?: null;
    }
}
