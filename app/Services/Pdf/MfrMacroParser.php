<?php

namespace App\Services\Pdf;

use RuntimeException;

/**
 * Extracts the macro commentary sections from the front of a Public Mutual
 * MFR PDF — equity outlook, bond & FX moves, GDP/CPI/OPR text. Per-fund
 * pages are skipped here (those are handled by MfrParser).
 *
 * Output rows are designed to drop into `market_events` so the existing
 * embedding + retrieval path can use them as prompt context (interest-rate
 * environment, FX backdrop, growth outlook).
 */
class MfrMacroParser
{
    /** Macro commentary lives in roughly the first 6 pages of the MFR. */
    private const MACRO_PAGES = 6;

    /**
     * Section anchors. Each entry: [headline_template, regex_anchor].
     * The body for each section is the text from its anchor to the next.
     */
    private const SECTIONS = [
        ['Most Equity Markets — Monthly Summary', '/Most Equity Markets[^\n]*\n/i'],
        ['Update on Equity Markets',              '/Update on Equity Markets\s*\n/i'],
        ['Update on Bond & Currency Markets',     '/Update on Bond & Currency Markets\s*\n/i'],
        ['Outlook',                                '/^Outlook\s*$/m'],
    ];

    public function parseFile(string $path, ?string $period = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("MFR file not found: $path");
        }

        $cmd = sprintf(
            'pdftotext -raw -l %d %s -',
            self::MACRO_PAGES,
            escapeshellarg($path)
        );
        $text = shell_exec($cmd);
        if ($text === null || $text === '') {
            throw new RuntimeException("pdftotext produced no output for $path");
        }

        $period = $period ?: $this->detectPeriod($text);

        $hits = [];
        foreach (self::SECTIONS as [$title, $re]) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $hits[] = ['title' => $title, 'start' => $m[0][1]];
            }
        }
        // Sort by start offset.
        usort($hits, fn ($a, $b) => $a['start'] <=> $b['start']);

        $events = [];
        $count = count($hits);
        for ($i = 0; $i < $count; $i++) {
            $start = $hits[$i]['start'];
            $end = $i + 1 < $count ? $hits[$i + 1]['start'] : strlen($text);
            $body = trim(substr($text, $start, $end - $start));
            if ($body === '') {
                continue;
            }
            $events[] = [
                'source'       => 'mfr',
                'headline'     => $hits[$i]['title'].($period ? " — {$period}" : ''),
                'body'         => $this->cleanBody($body),
                'published_at' => $period ? $period.'-01' : null,
            ];
        }
        return $events;
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

    private function cleanBody(string $s): string
    {
        // Drop image/footer noise: page numbers, "Source: …" lines.
        $lines = preg_split('/\n/', $s);
        $kept = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l === '' || preg_match('/^Source:/i', $l) || preg_match('/^\d+$/', $l)) {
                continue;
            }
            $kept[] = $l;
        }
        $body = implode(' ', $kept);
        return mb_substr($body, 0, 8000); // hard cap per row
    }
}
