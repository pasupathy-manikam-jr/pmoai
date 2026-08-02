<?php

namespace App\Console\Commands;

use App\Models\Fund;
use App\Models\PendingTransaction;
use Illuminate\Console\Command;

/**
 * Parses PMO "Float Transactions" (Urus Niaga Apungan) PDFs — requests
 * SUBMITTED but not yet processed — into the pending_transactions table.
 *
 * Two layouts, auto-detected:
 *   - Unit trust (scheme "ut"): SWR/SWS/RP/II/AI rows, optional switch target.
 *   - PRS (scheme "prs"): AC/IC contribution rows with a contribution type.
 *
 * Each float PDF is a FULL snapshot of what is pending for its scheme, so
 * ingesting one replaces every pending row of that scheme (a settled item
 * simply drops off the next statement). Dedupe is therefore replace-by-scheme,
 * not per-row.
 */
class IngestFloat extends Command
{
    protected $signature = 'pmoai:ingest-float {path : A Float Transactions PDF, or a directory of them}';

    protected $description = 'Parse Float Transactions PDF(s) into the pending_transactions table (replace-snapshot per scheme).';

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

        foreach ($files as $pdf) {
            $text = shell_exec(escapeshellcmd($bin).' -layout '.escapeshellarg($pdf).' -') ?? '';
            if (! preg_match('/Float Transactions|Urus Niaga Apungan/i', $text)) {
                $this->warn(basename($pdf).': not a Float Transactions statement — skipped');
                continue;
            }

            // PRS float carries a contribution-type column and PRS wording.
            $scheme = preg_match('/Jenis Caruman|Contribution Type|PRIVATE RETIREMENT|CARUMAN/i', $text) ? 'prs' : 'ut';
            $rows = $scheme === 'prs' ? $this->parsePrs($text) : $this->parseUt($text);

            if (! $rows) {
                $this->warn(basename($pdf).": recognised as {$scheme} float but no rows parsed.");
                continue;
            }

            // Replace-snapshot: this PDF is the complete pending set for its scheme.
            PendingTransaction::where('scheme', $scheme)->delete();
            foreach ($rows as $r) {
                PendingTransaction::create($r + [
                    'scheme'      => $scheme,
                    'source_pdf'  => basename($pdf),
                    'captured_at' => now(),
                ]);
            }

            $this->info(basename($pdf).": {$scheme} float — ".count($rows).' pending row(s) recorded.');
        }

        // Drop any just-recorded request that has already settled.
        $reconciled = PendingTransaction::reconcile();
        if ($reconciled) {
            $this->info("Reconciled $reconciled pending (float) → already settled.");
        }

        return self::SUCCESS;
    }

    /** amount/units "1,234.56" or "(1,234.56)" → float. */
    private function num(string $s): ?float
    {
        $s = trim($s);
        $neg = str_starts_with($s, '(') && str_ends_with($s, ')');
        $s = str_replace([',', '(', ')'], '', $s);

        return is_numeric($s) ? ($neg ? -(float) $s : (float) $s) : null;
    }

    /**
     * Unit-trust float row (columns via pdftotext -layout):
     *   29/07/2026  SWR  128960916  <FUND>  0.00  100,000.00  077221901  <SWITCH FUND>
     * Switch account + fund are optional (only for SWS/SWR).
     */
    private function parseUt(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (! preg_match(
                '/^\s*(\d{2}\/\d{2}\/\d{4})\s+([A-Z]{2,4})\s+(\d{6,12})\s+(.+?)\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})(?:\s+(\d{6,12})\s+(.+?))?\s*$/',
                $line, $m
            )) {
                continue;
            }
            [, $date, $type, $acct, $fund, $amount, $units, $swAcct, $swFund] = array_pad($m, 9, null);
            $out[] = [
                'submitted_at'      => $this->date($date),
                'trans_type'        => $type,
                'account_no'        => $acct,
                'fund_name'         => trim($fund),
                'fund_code'         => $this->codeFor(trim($fund)),
                'contribution_type' => null,
                'amount'            => $this->num($amount),
                'units'             => $this->num($units),
                'switch_to_account' => $swAcct ?: null,
                'switch_to_fund'    => $swFund ? trim($swFund) : null,
            ];
        }

        return $out;
    }

    /**
     * PRS float row:
     *   29/07/2026  AC  06244382  PRS STRATEGIC EQUITY  IND  3,000.00  0.00
     */
    private function parsePrs(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            if (! preg_match(
                '/^\s*(\d{2}\/\d{2}\/\d{4})\s+([A-Z]{2,4})\s+(\d{6,12})\s+(.+?)\s+([A-Z]{2,4})\s+([\d,]+\.\d{2})\s+([\d,]+\.\d{2})\s*$/',
                $line, $m
            )) {
                continue;
            }
            [, $date, $type, $acct, $fund, $contrib, $amount, $units] = $m;
            $out[] = [
                'submitted_at'      => $this->date($date),
                'trans_type'        => $type,
                'account_no'        => $acct,
                'fund_name'         => trim($fund),
                'fund_code'         => $this->codeFor(trim($fund)),
                'contribution_type' => $contrib,
                'amount'            => $this->num($amount),
                'units'             => $this->num($units),
                'switch_to_account' => null,
                'switch_to_fund'    => null,
            ];
        }

        return $out;
    }

    private function date(string $d): string
    {
        [$dd, $mm, $yyyy] = explode('/', $d);

        return "$yyyy-$mm-$dd 00:00:00";
    }

    /** Best-effort catalog code for a fund name (case-insensitive contains). */
    private function codeFor(string $name): ?string
    {
        static $catalog = null;
        if ($catalog === null) {
            $catalog = Fund::query()->whereNotNull('code')->get(['code', 'name']);
        }
        $n = strtoupper($name);
        $hit = $catalog->first(function ($f) use ($n) {
            $fn = strtoupper($f->name);

            return str_starts_with($fn, $n) || str_starts_with($n, $fn) || str_contains($fn, $n);
        });

        return $hit?->code;
    }
}
