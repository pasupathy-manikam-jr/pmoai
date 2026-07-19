<?php

namespace App\Console\Commands;

use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Parses "Summary of PRS Contributions" yearly PDFs into the transactions
 * store (type RII, synthetic refs — dedupe-safe). Feeds PRS start dates,
 * returns, and the yearly tax-relief tracker.
 */
class IngestPrsStatements extends Command
{
    protected $signature = 'pmoai:ingest-prs-stmt {path : A PRS summary PDF, or a directory of them}';

    protected $description = 'Parse Summary of PRS Contributions PDF(s) into the transactions table.';

    public function handle(): int
    {
        $path = $this->argument('path');
        $files = is_dir($path)
            ? (glob(rtrim($path, '/').'/*.[pP][dD][fF]') ?: [])
            : [$path];
        if (! $files || ! is_file($files[0])) {
            $this->error("Nothing at: $path");
            return self::FAILURE;
        }

        $bin = config('ai.pdftotext_bin') ?: '/opt/homebrew/bin/pdftotext';

        // fund-name → PRS code map from the catalog
        $codeByNorm = Fund::where('category', 'PRS')->get()
            ->mapWithKeys(fn ($f) => [FundDetail::normalizeName($f->name) => $f->code]);

        $total = 0;
        foreach ($files as $pdf) {
            $text = shell_exec(escapeshellcmd($bin).' -layout '.escapeshellarg($pdf).' -') ?? '';
            if (! str_contains($text, 'SUMMARY OF PRS CONTRIBUTIONS')) {
                $this->warn(basename($pdf).': not a PRS contributions summary — skipped');
                continue;
            }

            $new = 0;
            $lastName = null;
            $lastAcct = null;
            foreach (preg_split('/\R/', $text) as $line) {
                // Full row: PUBLIC MUTUAL PRS ISLAMIC GROWTH FUND  04222321  26/12/2019  3,000.00
                if (preg_match('/^\s*(PUBLIC MUTUAL PRS [A-Z ]+FUND)\s+(\d{7,10})\s+(\d{2}\/\d{2}\/\d{4})\s+([\d,]+\.\d{2})\s*$/', $line, $m)) {
                    [, $name, $acct, $date, $amt] = $m;
                    $lastName = $name;
                    $lastAcct = $acct;
                }
                // Continuation row (same fund, extra contribution): 16/08/2022  3,000.00
                elseif ($lastName && preg_match('/^\s*(\d{2}\/\d{2}\/\d{4})\s+([\d,]+\.\d{2})\s*$/', $line, $m2)) {
                    $name = $lastName;
                    $acct = $lastAcct;
                    [, $date, $amt] = $m2;
                } else {
                    continue;
                }
                $code = $codeByNorm[FundDetail::normalizeName($name)] ?? null;
                $carbon = \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $date);
                $ref = 'PRSC-'.$acct.'-'.$carbon->format('Ymd');

                $t = Transaction::firstOrCreate(
                    ['trans_ref' => $ref],
                    [
                        'trans_date' => $carbon,
                        'account_no' => $acct,
                        'fund_code'  => $code ?? 'PRS-?',
                        'trans_type' => 'RII',
                        'reference'  => 'PRS contribution (yearly summary)',
                        'gross'      => (float) str_replace(',', '', $amt),
                        'net'        => (float) str_replace(',', '', $amt),
                        'units'      => null,
                        'source_pdf' => basename($pdf),
                    ],
                );
                if ($t->wasRecentlyCreated) {
                    $new++;
                }
            }
            $this->info(basename($pdf).": $new new contributions");
            $total += $new;
        }

        $this->info("Total new: $total");
        return self::SUCCESS;
    }
}
