<?php

namespace App\Console\Commands;

use App\Models\Fund;
use App\Models\FundDetail;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Pulls the latest PRS fund reports straight from publicmutual.com.my's own
 * report API (the dropdown on /funds-report-prs), extracts average total
 * returns + a performance excerpt, and upserts PRS funds into the catalog so
 * PRS detail pages can run AI analysis.
 */
class FetchPrsReports extends Command
{
    protected $signature = 'pmoai:fetch-prs';

    protected $description = 'Download latest PRS fund reports from publicmutual.com.my and ingest returns into the catalog.';

    private const BASE = 'https://www.publicmutual.com.my';

    public function handle(): int
    {
        $jar = new CookieJar();

        // 1. Page load → anti-forgery token (paired with the cookie jar).
        $page = Http::withOptions(['cookies' => $jar])->get(self::BASE.'/funds-report-prs')->body();
        if (! preg_match('/name="__RequestVerificationToken"[^>]*value="([^"]+)"/', $page, $m)) {
            $this->error('Could not extract request token.');
            return self::FAILURE;
        }
        $token = $m[1];
        $api = fn () => Http::withOptions(['cookies' => $jar])->withHeaders([
            'RequestVerificationToken' => $token,
            'Content-Type'             => 'application/json',
        ]);

        // 2. Fund list.
        $funds = $api()->get(self::BASE.'/ECommunicationPRS/GetFilteredFundList')->json('ResultData') ?? [];
        if (! $funds) {
            $this->error('Empty PRS fund list.');
            return self::FAILURE;
        }

        $dir = storage_path('app/prs');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $details = FundDetail::all(['id', 'code', 'name']);
        $ok = 0;

        foreach ($funds as $f) {
            $desc = $f['FundDescAbbr'];                     // "... FUND (PRS-SEQF)"
            $code = preg_match('/\(([A-Z0-9\-]+)\)/', $desc, $cm) ? $cm[1] : null;
            $name = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $desc));
            if (! $code) {
                continue;
            }

            // 3. Latest report row (Annual preferred, else first).
            $rows = $api()->get(self::BASE.'/ECommunicationPRS/GetFundReportBySchemeCode?schemeCode='.$f['SchemeCode'])
                ->json('ResultData') ?? [];
            $row = collect($rows)->firstWhere('Type', 'Annual') ?? ($rows[0] ?? null);
            if (! $row || empty($row['E_DocId'])) {
                $this->warn("$code: no report");
                continue;
            }

            // 4. Download (base64 in JSON) → PDF on disk.
            $doc = $api()->get(self::BASE.'/ECommunicationPRS/DownloadDocByDocId?docId='.$row['E_DocId'])
                ->json('ResultData');
            if (empty($doc['FileBytes'])) {
                $this->warn("$code: empty document");
                continue;
            }
            $pdf = $dir.'/'.$code.' '.$row['Type'].' '.str_replace('/', '-', $row['Date']).'.pdf';
            file_put_contents($pdf, base64_decode($doc['FileBytes']));

            // 5. Extract average total returns + a performance excerpt.
            $text = shell_exec(escapeshellcmd($this->pdftotext()).' -layout '.escapeshellarg($pdf).' -') ?? '';
            $ret = fn ($label) => preg_match('/^\s*'.$label.'\s+(-?\d+\.\d+)/m', $text, $rm) ? (float) $rm[1] : null;
            $r1 = $ret('1 Year');
            $r3 = $ret('3 Years');
            $r5 = $ret('5 Years');

            $excerpt = '';
            if (($pos = stripos($text, 'Average Total Return')) !== false) {
                $excerpt = 'PRS '.$row['Type'].' report ('.$row['Date'].") — average total returns:\n"
                    ."1Y {$r1}% | 3Y {$r3}% | 5Y {$r5}%\n\n"
                    .mb_substr($text, $pos, 2200);
            }

            // 6. Upsert catalog row so detail pages resolve + analyze.
            Fund::updateOrCreate(
                ['code' => $code],
                [
                    'name'      => $name,
                    'fund_type' => 'PRS',
                    'category'  => 'PRS',
                    'shariah'   => str_contains(strtoupper($name), 'ISLAMIC'),
                    'return_1y' => $r1,
                    'return_3y' => $r3,
                    'return_5y' => $r5,
                ],
            );

            // 7. Link any held PRS detail (name-contained match) + stash excerpt.
            $norm = FundDetail::normalizeName($name);
            foreach ($details as $d) {
                $dn = FundDetail::normalizeName($d->name);
                if (strlen($dn) >= 8 && str_contains($norm, $dn)) {
                    $detail = FundDetail::find($d->id);
                    $payload = $detail->payload ?? [];
                    if ($excerpt) {
                        $payload['performance'] = $excerpt;
                    }
                    $detail->update(['code' => $detail->code ?: $code, 'payload' => $payload]);
                }
            }

            $this->info("$code ({$row['Type']} {$row['Date']}): 1Y {$r1} 3Y {$r3} 5Y {$r5}");
            $ok++;
        }

        $this->info("$ok PRS funds ingested.");
        return self::SUCCESS;
    }

    private function pdftotext(): string
    {
        $bin = config('ai.pdftotext_bin');
        if ($bin && is_executable($bin)) {
            return $bin;
        }
        foreach (['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'] as $cand) {
            if (is_executable($cand)) {
                return $cand;
            }
        }
        return 'pdftotext';
    }
}
