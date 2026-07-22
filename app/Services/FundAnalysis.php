<?php

namespace App\Services;

use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\FundFactsheet;
use App\Models\FundPrice;
use App\Models\MarketEvent;

/**
 * Builds the single-fund analysis context (deterministic signals, peers,
 * factsheet, position math, switch candidates), runs the active LLM, and
 * stores the result on the detail payload. Shared by the AnalyzeFundJob
 * (web clicks — the CLI provider outlives FastCGI timeouts there) and any
 * synchronous caller.
 */
class FundAnalysis
{
    public function __construct(private Llm $llm) {}

    /**
     * Compute + persist the analysis. Position figures are optional.
     */
    public function run(FundDetail $detail, $invested = null, $currentValue = null): void
    {
        [$fund, $context] = $this->buildContext($detail, $invested, $currentValue);

        $text = $this->llm->analyzeFund($fund->toArray(), $context);

        $detail->refresh();
        $payload = $detail->payload ?? [];
        unset($payload['ai_status'], $payload['ai_error']);
        $payload['ai'] = [
            'text'     => $text,
            'at'       => now()->toDateTimeString(),
            'provider' => config('ai.llm_provider'),
        ];
        $detail->update(['payload' => $payload]);
    }

    /**
     * Full deterministic context for one fund — shared by analysis and chat.
     *
     * @return array{0: Fund, 1: array<string, mixed>}
     */
    public function buildContext(FundDetail $detail, $invested = null, $currentValue = null): array
    {
        [$code, $priceHistory, $fund] = $this->resolve($detail);

        if (! $fund) {
            throw new \RuntimeException('No matching catalog fund — cannot analyze.');
        }

        $factsheet = $this->factsheetFor($code);

        $context = [
            'trend'     => $priceHistory->isEmpty() ? '' : $priceHistory
                ->map(fn ($p) => $p['date'].'='.$p['price'])->implode(', '),
            'signals'   => $this->signals($priceHistory, $fund),
            'peers'     => $this->peers($fund),
            'facts'     => $this->profile($detail),
            'factsheet' => $factsheet?->only([
                'period', 'fund_size_nav_myr', 'fund_size_units',
                'volatility_factor', 'volatility_class',
                'benchmark_name', 'benchmark_returns', 'calendar_returns',
                'asset_allocation', 'fx_exposure', 'geo_foreign',
                'top_sectors', 'top_holdings', 'distributions',
            ]),
            'macro'     => $this->macro($factsheet?->period),
            'position'  => $this->position($invested, $currentValue),
        ];
        if ($context['position']) {
            // Holding age (first-tracked date) — young positions shouldn't
            // get churn advice over routine volatility.
            $since = $detail->payload['position']['since'] ?? null;
            if ($since) {
                $days = (int) now()->diffInDays(\Illuminate\Support\Carbon::parse($since), true);
                $context['position']['position first tracked'] = $since." ({$days} days ago)";
            }
            $context['switch_candidates'] = $this->switchCandidates($fund);
        }

        return [$fund, $context];
    }

    /**
     * Shared resolution: fund code (detail.code, else normalized-name match
     * against the catalog), the daily price line, and the catalog row.
     * Case-insensitive on code — e-Series catalog codes are mixed case.
     *
     * @return array{0:?string,1:\Illuminate\Support\Collection,2:?Fund}
     */
    public function resolve(FundDetail $detail): array
    {
        $code = $detail->code ? strtoupper($detail->code) : null;
        if (! $code) {
            $norm = FundDetail::normalizeName($detail->name);
            $code = Fund::query()
                ->whereNotNull('code')
                ->get(['code', 'name'])
                ->first(fn ($f) => FundDetail::normalizeName($f->name) === $norm)
                ?->code;
            $code = $code ? strtoupper($code) : null;
        }

        // Daily price line from fund_prices. One row per (code, month);
        // d1..d31 hold that month's per-day captures.
        $priceHistory = $code
            ? FundPrice::whereRaw('upper(code) = ?', [$code])
                ->orderBy('period')
                ->get()
                ->flatMap(function ($row) {
                    $pts = [];
                    for ($d = 1; $d <= 31; $d++) {
                        $v = $row->{"d{$d}"};
                        if ($v === null) {
                            continue;
                        }
                        $pts[] = [
                            'date'  => $row->period.'-'.str_pad((string) $d, 2, '0', STR_PAD_LEFT),
                            'price' => (float) $v,
                        ];
                    }

                    return $pts;
                })
                // Sort chronologically: a fund whose code was captured under
                // more than one case (e.g. PeEMAS vs PEEMAS) yields >1 row per
                // month; without this the flattened points interleave and the
                // "latest" price shown is wrong.
                ->sortBy('date')
                ->values()
            : collect();

        $fund = $code ? Fund::whereRaw('upper(code) = ?', [$code])->first() : null;

        return [$code, $priceHistory, $fund];
    }

    /**
     * Latest factsheet for a code. MFR booklets print one page per fund
     * covering Class A only — Class B shares the portfolio (fees differ),
     * so "XXX-B" falls back to the "XXX-A" factsheet.
     */
    public function factsheetFor(?string $code): ?FundFactsheet
    {
        if (! $code) {
            return null;
        }
        $fs = FundFactsheet::query()->latestFor($code)->first();
        if (! $fs && preg_match('/^(.*)-B$/i', $code, $m)) {
            $fs = FundFactsheet::query()->latestFor($m[1].'-A')->first();
        }
        return $fs;
    }

    /**
     * Deterministic position math for a current holder — computed here so
     * the LLM quotes real numbers instead of deriving its own.
     *
     * @return array<string, string>|null
     */
    public function position($invested, $currentValue): ?array
    {
        if (! is_numeric($invested) || ! is_numeric($currentValue)
            || (float) $invested <= 0 || (float) $currentValue <= 0) {
            return null;
        }
        $inv = (float) $invested;
        $val = (float) $currentValue;

        $out = [
            'total invested (RM)'   => number_format($inv, 0),
            'current value (RM)'    => number_format($val, 0),
            'unrealised P/L (RM)'   => number_format($val - $inv, 0),
            'unrealised P/L (%)'    => number_format(($val - $inv) / $inv * 100, 1),
        ];

        if ($val < $inv) {
            $out['gain needed to break even (%)'] = number_format(($inv / $val - 1) * 100, 1);
            foreach ([4, 6, 8] as $r) {
                $years = log($inv / $val) / log(1 + $r / 100);
                $out["years to break even at {$r}%/yr elsewhere"] = number_format($years, 1);
            }
        }

        return $out;
    }

    /**
     * True when the fund belongs to Public Mutual's e-Series family
     * (online-only channel; "e-" in the name, codes prefixed "Pe").
     */
    public static function isESeries(Fund $fund): bool
    {
        return (bool) preg_match('/(^|\s)e-/i', $fund->name)
            || (bool) preg_match('/^Pe[A-Z]/', (string) $fund->code);
    }

    /**
     * Steadier catalog funds a seller could switch into — deterministic
     * shortlist so the LLM picks FROM real funds. Shariah funds only get
     * Shariah candidates; peaked flyers excluded. e-Series funds can only
     * be switched within the e-Series family (PMO channel rule), so
     * candidates are restricted to the source fund's series.
     */
    private function switchCandidates(Fund $fund): string
    {
        $q = Fund::query()
            ->whereKeyNot($fund->getKey())
            ->whereNotNull('return_5y')->where('return_5y', '>=', 4)
            ->whereNotNull('return_3y')->where('return_3y', '>=', 4)
            ->whereNotNull('return_1y')
            ->whereRaw('return_1y <= 2.5 * return_5y');
        if ($fund->shariah) {
            $q->where('shariah', true);
        }

        $wantE = self::isESeries($fund);
        $riskRank = fn ($f) => match (true) {
            stripos((string) $f->risk, 'very high') !== false => 2,
            stripos((string) $f->risk, 'high') !== false      => 1,
            default                                           => 0,
        };

        return $q
            ->get()
            ->filter(fn ($f) => self::isESeries($f) === $wantE)
            ->sortBy([
                fn ($a, $b) => $riskRank($a) <=> $riskRank($b),
                fn ($a, $b) => (float) $b->return_5y <=> (float) $a->return_5y,
            ])
            ->take(6)
            ->map(fn ($f) => implode(' | ', [
                $f->code ?? '??',
                $f->name,
                $f->shariah ? 'S' : '-',
                'risk '.($f->risk ?? 'na'),
                '1Y '.($f->return_1y ?? 'na'),
                '3Y '.($f->return_3y ?? 'na'),
                '5Y '.($f->return_5y ?? 'na'),
            ]))->implode("\n");
    }

    /**
     * Deterministic price signals — computed here so the LLM interprets
     * numbers instead of inventing them. Empty if too few points.
     *
     * @param  \Illuminate\Support\Collection  $hist  [{date,price}]
     * @return array<string, string>
     */
    private function signals($hist, Fund $fund): array
    {
        $px = $hist->pluck('price')->map(fn ($v) => (float) $v)->values();
        $out = [];

        if ($px->count() >= 2) {
            $first = $px->first();
            $last  = $px->last();
            $hi    = $px->max();
            $lo    = $px->min();

            $rets = [];
            for ($i = 1; $i < $px->count(); $i++) {
                $p0 = $px[$i - 1];
                if ($p0 != 0.0) {
                    $rets[] = ($px[$i] - $p0) / $p0 * 100;
                }
            }
            if (count($rets) >= 2) {
                $mean = array_sum($rets) / count($rets);
                $var  = array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $rets)) / (count($rets) - 1);
                $out['daily volatility (stdev %)'] = number_format(sqrt($var), 3);
            }

            $out['captured points']        = (string) $px->count();
            $out['period change %']        = $first != 0.0 ? number_format(($last - $first) / $first * 100, 2) : 'n/a';
            $out['max drawdown %']         = $hi != 0.0 ? number_format(($lo - $hi) / $hi * 100, 2) : 'n/a';
            $out['distance from peak %']   = $hi != 0.0 ? number_format(($last - $hi) / $hi * 100, 2) : 'n/a';
            $out['captured high / low']    = number_format($hi, 4).' / '.number_format($lo, 4);

            $n = $px->count();
            $sx = $n * ($n - 1) / 2;
            $sxx = ($n - 1) * $n * (2 * $n - 1) / 6;
            $sy = $px->sum();
            $sxy = 0.0;
            foreach ($px as $i => $v) {
                $sxy += $i * $v;
            }
            $den = $n * $sxx - $sx * $sx;
            $slope = $den != 0.0 ? ($n * $sxy - $sx * $sy) / $den : 0.0;
            $out['trend (price/day slope)'] = number_format($slope, 5)
                .' ('.($slope > 0 ? 'up' : ($slope < 0 ? 'down' : 'flat')).')';
        } else {
            $out['captured points'] = (string) $px->count().' (too few for price signals)';
        }

        if (isset($out['daily volatility (stdev %)']) && $fund->return_1y !== null) {
            $vol = (float) $out['daily volatility (stdev %)'];
            if ($vol > 0) {
                $out['1y return / daily vol'] = number_format((float) $fund->return_1y / $vol, 2);
            }
        }

        return $out;
    }

    /**
     * Where this fund sits vs same-category catalog peers (percentile by
     * 3y avg-annual return and perf_factor; higher = better).
     */
    private function peers(Fund $fund): string
    {
        if (! $fund->category) {
            return '';
        }

        $peers = Fund::where('category', $fund->category)->get();
        $n = $peers->count();
        if ($n < 2) {
            return "Only {$n} fund in category {$fund->category} — no peer ranking.";
        }

        $pct = function ($field) use ($peers, $fund, $n) {
            $val = $fund->{$field};
            if ($val === null) {
                return null;
            }
            $below = $peers->filter(fn ($p) => $p->{$field} !== null && (float) $p->{$field} < (float) $val)->count();

            return round($below / $n * 100);
        };

        $r3 = $pct('return_3y');
        $pf = $pct('perf_factor');
        $lines = ["Category {$fund->category}: {$n} funds."];
        if ($r3 !== null) {
            $lines[] = "3y avg-annual return: {$fund->return_3y}% → ~{$r3}th percentile of peers.";
        }
        if ($pf !== null) {
            $lines[] = "perf_factor: {$fund->perf_factor} → ~{$pf}th percentile.";
        }

        return implode("\n", $lines);
    }

    /**
     * Recent MFR macro commentary (same period as the factsheet). Truncated
     * so the prompt stays bounded.
     */
    private function macro(?string $period): string
    {
        if (! $period) {
            return '';
        }
        $rows = MarketEvent::where('source', 'mfr')
            ->whereDate('published_at', $period.'-01')
            ->orderBy('id')
            ->get(['headline', 'body']);

        $lines = [];
        foreach ($rows as $r) {
            $body = mb_substr($r->body, 0, 1200);
            $lines[] = "[{$r->headline}]\n{$body}";
        }
        return implode("\n\n", $lines);
    }

    /**
     * Trim the detail payload (objective + performance/calendar tables) to
     * a compact text block. Caps length to keep the prompt lean.
     */
    private function profile(FundDetail $detail): string
    {
        $p = $detail->payload ?? [];
        $bits = [];

        if (! empty($p['objective'])) {
            $bits[] = 'Objective: '.trim((string) $p['objective']);
        }
        foreach (['performance' => 'Performance', 'calendar' => 'Calendar returns', 'distribution' => 'Distribution'] as $k => $label) {
            if (! empty($p[$k])) {
                $bits[] = $label.":\n".trim((string) $p[$k]);
            }
        }

        $txt = implode("\n\n", $bits);

        return mb_strlen($txt) > 2500 ? mb_substr($txt, 0, 2500).' …[truncated]' : $txt;
    }
}
