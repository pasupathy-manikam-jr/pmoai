<?php

namespace App\Console\Commands;

use App\Services\MfrIngest;
use Illuminate\Console\Command;

class IngestMfr extends Command
{
    protected $signature = 'pmoai:ingest-mfr
        {path : Absolute path to a monthly MFR PDF, or a directory of PDFs}
        {--period= : Override YYYY-MM (defaults to month detected in PDF header)}';

    protected $description = 'Parse Public Mutual MFR PDF(s) and upsert one fund_factsheets row per fund per report month.';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (is_dir($path)) {
            $pdfs = glob(rtrim($path, '/').'/*.[pP][dD][fF]') ?: [];
            if (! $pdfs) {
                $this->error("No PDFs found in directory: $path");
                return self::FAILURE;
            }
            $fail = 0;
            foreach ($pdfs as $pdf) {
                // --period only makes sense for a single file; per-file
                // detection applies in directory mode.
                if ($this->call('pmoai:ingest-mfr', ['path' => $pdf]) !== self::SUCCESS) {
                    $fail++;
                }
            }
            $this->info(count($pdfs).' PDFs processed, '.$fail.' failed.');
            return $fail ? self::FAILURE : self::SUCCESS;
        }

        if (! is_file($path)) {
            $this->error("Not a file: $path");
            return self::FAILURE;
        }

        try {
            $res = app(MfrIngest::class)->ingestFile($path, $this->option('period') ?: null);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage().' Pass --period.');
            return self::FAILURE;
        }

        $this->info("Ingested {$res['written']} factsheets for period {$res['period']} from ".basename($path));
        return self::SUCCESS;
    }
}
