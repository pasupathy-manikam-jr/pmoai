<?php

namespace App\Jobs;

use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\Snapshot;
use App\Services\PublicMutualParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ExtractFundsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public int $snapshotId, public bool $skipAi = false) {}

    public function handle(PublicMutualParser $parser): void
    {
        $snapshot = Snapshot::findOrFail($this->snapshotId);

        $rows = $parser->parse($snapshot->raw_text);

        // Guard against capturing the wrong table (e.g. the holdings page):
        // risk labels or category codes masquerading as fund names/codes.
        $junkNames = ['VERY HIGH', 'HIGH', 'MODERATE', 'LOW', 'VERY LOW', 'CORE (CONSERVATIVE)'];
        $rows = array_values(array_filter($rows, function ($r) use ($junkNames) {
            $name = strtoupper(trim($r['name'] ?? ''));
            $code = strtoupper(trim($r['extra']['code'] ?? ''));
            return $name !== ''
                && ! in_array($name, $junkNames, true)
                && ! in_array($code, ['EQ', 'OA', 'MM', 'MA', 'EQUITY', 'LOW', 'HIGH'], true)
                && strlen($name) >= 6;
        }));

        $this->writeCsv($snapshot->id, $rows);

        // Catalog upsert: one row per code, latest capture wins. Code is the
        // identity, so rows without one are skipped (parser keys on code).
        $now = now();
        $batch = [];
        foreach ($rows as $r) {
            $code = $r['extra']['code'] ?? null;
            if (empty($r['name']) || empty($code)) {
                continue;
            }
            $batch[] = [
                'code'            => $code,
                'name'            => $r['name'],
                'fund_type'       => $r['fund_type']       ?? null,
                'shariah'         => (bool) ($r['shariah'] ?? false),
                'unit_price'      => $r['unit_price']      ?? null,
                'selling_price'   => $r['selling_price']   ?? null,
                'return_ytd'      => $r['return_ytd']      ?? null,
                'return_1y'       => $r['return_1y']       ?? null,
                'return_3y'       => $r['return_3y']       ?? null,
                'return_5y'       => $r['return_5y']       ?? null,
                'return_10y'      => $r['return_10y']      ?? null,
                'perf_factor'     => $r['perf_factor']     ?? null,
                'perf_class'      => $r['perf_class']      ?? null,
                'perf_date'       => $this->date($r['perf_date'] ?? null),
                'category'        => $r['category']        ?? null,
                'risk'            => $r['risk']            ?? null,
                'since_inception' => $r['since_inception'] ?? null,
                'fund_size'       => $r['fund_size']       ?? null,
                'currency'        => $r['currency']        ?? 'MYR',
                // json column via query builder (upsert) — encode manually.
                'extra'           => isset($r['extra']) ? json_encode($r['extra']) : null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }
        if ($batch) {
            // Key on code; refresh every mutable column on re-capture.
            Fund::upsert($batch, ['code'], [
                'name', 'fund_type', 'shariah', 'unit_price', 'selling_price',
                'return_ytd', 'return_1y', 'return_3y', 'return_5y', 'return_10y',
                'perf_factor', 'perf_class', 'perf_date',
                'category', 'risk', 'since_inception', 'fund_size',
                'currency', 'extra', 'updated_at',
            ]);
        }

        $this->accumulatePrices($rows);
        $this->ensureDetailStubs();
        app(\App\Services\AlertCheck::class)->run();

        // Snapshot ingest is data-only for now: parse + DB upsert, NO
        // Claude/embedding rec pipeline. AI analysis moves to the fund
        // DETAIL page later. 'stored' is terminal so index/show stop
        // auto-refreshing. (skipAi param kept for when AI is reinstated.)
        $snapshot->update(['status' => 'stored']);
    }

    /**
     * Every catalog fund gets a FundDetail row (empty stub if never
     * captured), so the snapshot table always shows a "view →" link and
     * the detail page / AI analysis is reachable for all funds.
     */
    private function ensureDetailStubs(): void
    {
        $existingCodes = FundDetail::whereNotNull('code')->pluck('code')
            ->map(fn ($c) => strtoupper($c))->flip();
        $existingNames = FundDetail::pluck('name')
            ->map(fn ($n) => FundDetail::normalizeName($n))->flip();

        Fund::all(['code', 'name'])->each(function ($f) use ($existingCodes, $existingNames) {
            $code = $f->code ? strtoupper($f->code) : null;
            if (($code && isset($existingCodes[$code]))
                || isset($existingNames[FundDetail::normalizeName($f->name)])) {
                return;
            }
            // name has a unique constraint — never crash the capture over a
            // stub collision
            FundDetail::firstOrCreate(
                ['name' => $f->name],
                ['code' => $f->code, 'raw_text' => ''],
            );
        });
    }

    /**
     * Accrue captured unit prices into the permanent MONTHLY series.
     *
     * Bucketed on the capture MONTH (price_date = month start). Re-capture
     * within the same month overwrites that month's row in place ("one date
     * field"); when the month rolls over a new row is added, so the series
     * grows one point per month (the detail chart grows horizontally).
     */
    private function accumulatePrices(array $rows): void
    {
        $now = now();
        $captureDate = $now->toDateString();   // real day-of-month
        $period = $now->format('Y-m');         // monthly bucket key
        $dayCol = 'd' . (int) $now->day;       // daily column: d1..d31
        $batch = [];
        foreach ($rows as $r) {
            $code = $r['extra']['code'] ?? null;
            $price = $r['unit_price'] ?? null;
            if (! $code || ! is_numeric($price)) {
                continue;
            }
            $batch[] = [
                // canonical catalog casing so a fund never splits into
                // case-variant rows across writers (see Fund::canonicalCode)
                'code'       => \App\Models\Fund::canonicalCode($code),
                'name'       => $r['name'],
                'price'      => (float) $price,
                'price_date' => $captureDate,
                'period'     => $period,
                $dayCol      => (float) $price,
                'created_at' => $now,
            ];
        }
        if ($batch) {
            // One row per (code, month). Today's price lands in the d{day}
            // column for this day-of-month, so the month row accrues up to
            // 31 daily points. price + price_date track the latest capture
            // (chart/RecommendJob unchanged). New month = new row.
            \App\Models\FundPrice::upsert(
                $batch,
                ['code', 'period'],
                [$dayCol, 'price', 'name', 'price_date', 'created_at']
            );
        }
    }

    /** Clean CSV artifact of parsed funds for inspection (no HTML/markup). */
    private function writeCsv(int $snapshotId, array $rows): void
    {
        $dir = storage_path('app/snapshots');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fh = fopen("{$dir}/{$snapshotId}.csv", 'w');
        fputcsv($fh, [
            'name', 'code', 'fund_type', 'category', 'shariah', 'risk',
            'unit_price', 'change_pct',
            'ytd', '1y', '3y', '5y', '10y', 'perf_class', 'since_inception', 'fund_size',
        ]);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['name'],
                $r['extra']['code']       ?? '',
                $r['fund_type']           ?? '',
                $r['category']            ?? '',
                ($r['shariah'] ?? false) ? 1 : 0,
                $r['risk']                ?? '',
                $r['unit_price']          ?? '',
                $r['extra']['change_pct'] ?? '',
                $r['return_ytd']          ?? '',
                $r['return_1y']           ?? '',
                $r['return_3y']           ?? '',
                $r['return_5y']           ?? '',
                $r['return_10y']          ?? '',
                $r['perf_class']          ?? '',
                $r['since_inception']     ?? '',
                $r['fund_size']           ?? '',
            ]);
        }
        fclose($fh);
    }

    /** "dd/mm/yyyy" -> "Y-m-d" or null. */
    private function date(?string $s): ?string
    {
        if ($s && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($s), $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }

    public function failed(Throwable $e): void
    {
        Snapshot::whereKey($this->snapshotId)->update(['status' => 'failed']);
    }
}
