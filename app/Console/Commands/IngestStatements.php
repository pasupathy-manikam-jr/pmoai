<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * Parses "Statement of Transaction" PDFs (PMO Statements → Transactions →
 * Statement of Transaction) into the transactions table. Dedupe on the
 * TR… transaction reference, so re-ingesting the same statement is safe.
 */
class IngestStatements extends Command
{
    protected $signature = 'pmoai:ingest-stmt {path : A statement PDF, or a directory of them}';

    protected $description = 'Parse Statement of Transaction PDF(s) into the transactions table.';

    public function handle(): int
    {
        $path = $this->argument('path');

        $files = is_dir($path)
            ? (glob(rtrim($path, '/').'/*.[pP][dD][fF]') ?: [])
            : [$path];
        if (! $files || ! is_file($files[0])) {
            $this->error("Nothing to ingest at: $path");
            return self::FAILURE;
        }

        $bin = config('ai.pdftotext_bin') ?: '/opt/homebrew/bin/pdftotext';
        $totalNew = 0;

        foreach ($files as $pdf) {
            $text = shell_exec(escapeshellcmd($bin).' -layout '.escapeshellarg($pdf).' -') ?? '';
            if (! str_contains($text, 'STATEMENT OF TRANSACTION')) {
                $this->warn(basename($pdf).': not a Statement of Transaction — skipped');
                continue;
            }

            $new = 0;
            foreach (preg_split('/\R/', $text) as $line) {
                // 13/07/2026 074114785 PINDOSF SWR PMO 137974826 -52,920.00 0.00 0.00 0.00 -52,920.00 0.1764 -300,000.00 TR2600006350406
                if (! preg_match('/^\s*(\d{2}\/\d{2}\/\d{4})\s+(\d{6,12})\s+(\S+)\s+([A-Z]{2,4})\s+(.+?)\s+(TR\w+)\s*$/', $line, $m)) {
                    continue;
                }
                [, $date, $acct, $code, $type, $middle, $ref] = $m;

                // middle = reference words + 7 numeric columns:
                // gross, charge%, charge, SST, net, price, units
                $tok = preg_split('/\s+/', trim($middle));
                if (count($tok) < 7) {
                    continue;
                }
                $nums = array_slice($tok, -7);
                $reference = implode(' ', array_slice($tok, 0, count($tok) - 7)) ?: null;
                $num = fn ($s) => is_numeric(str_replace(',', '', $s)) ? (float) str_replace(',', '', $s) : null;
                [$gross, $chargePct, $chargeAmt, $sst, $net, $price, $units] = array_map($num, $nums);

                $created = Transaction::firstOrCreate(
                    ['trans_ref' => $ref],
                    [
                        'trans_date' => \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $date),
                        'account_no' => $acct,
                        'fund_code'  => strtoupper($code),
                        'trans_type' => $type,
                        'reference'  => $reference,
                        'gross'      => $gross,
                        'charge_pct' => $chargePct,
                        'charge_amt' => $chargeAmt,
                        'sst'        => $sst,
                        'net'        => $net,
                        'price'      => $price,
                        'units'      => $units,
                        'source_pdf' => basename($pdf),
                    ],
                );
                if ($created->wasRecentlyCreated) {
                    $new++;
                }
            }

            $this->info(basename($pdf).": $new new transactions");
            $totalNew += $new;
        }

        $this->info("Total new: $totalNew (".Transaction::count().' in store)');

        $reconciled = \App\Models\PendingTransaction::reconcile();
        if ($reconciled) {
            $this->info("Reconciled $reconciled pending (float) → settled.");
        }

        return self::SUCCESS;
    }
}
