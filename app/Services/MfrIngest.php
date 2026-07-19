<?php

namespace App\Services;

use App\Models\FundFactsheet;
use App\Services\Pdf\MfrParser;
use RuntimeException;

/**
 * Parses one MFR / fund-review booklet PDF and upserts a fund_factsheets
 * row per fund per report month. Shared by the artisan command and the
 * token-protected /ingest-mfr endpoint (Tampermonkey).
 */
class MfrIngest
{
    /**
     * @return array{period: string, written: int}
     *
     * @throws RuntimeException when the report period cannot be determined
     */
    public function ingestFile(string $path, ?string $periodOverride = null): array
    {
        $parser = new MfrParser(config('fx_map'));
        $parsed = $parser->parseFile($path);

        $period = (string) ($periodOverride ?: $parsed['period']);
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Could not determine period (YYYY-MM) from the PDF.');
        }

        $now = now();
        $written = 0;
        foreach ($parsed['funds'] as $row) {
            // MFR prints some codes with spaces ("P ITTIKAL"); the catalog
            // has none (PITTIKAL) and detail pages join on catalog code.
            $row['code']        = str_replace(' ', '', $row['code']);
            $row['period']      = $period;
            $row['source_pdf']  = basename($path);
            $row['captured_at'] = $now;

            FundFactsheet::updateOrCreate(
                ['code' => $row['code'], 'period' => $period],
                $row,
            );
            $written++;
        }

        return ['period' => $period, 'written' => $written];
    }
}
