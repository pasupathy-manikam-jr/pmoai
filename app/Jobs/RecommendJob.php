<?php

namespace App\Jobs;

use App\Models\Fund;
use App\Models\Recommendation;
use App\Models\Snapshot;
use App\Models\UserFeedback;
use App\Services\EmbeddingService;
use App\Services\Llm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;
use Throwable;

class RecommendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public function __construct(public int $snapshotId) {}

    public function handle(EmbeddingService $embed, Llm $claude): void
    {
        $snapshot = Snapshot::findOrFail($this->snapshotId);

        // Funds are a global catalog now; the latest capture IS the catalog.
        $catalog = Fund::all();

        $feedback = UserFeedback::where('snapshot_id', $snapshot->id)->latest()->first();
        $feedbackText = $feedback?->text ?? '';

        // Embed this feedback so future snapshots can recall it.
        $recalled = [];
        if ($feedback && $feedbackText !== '') {
            $vec = new Vector($embed->embed($feedbackText));
            $feedback->update(['embedding' => $vec]);

            // Pull semantically-similar PRIOR feedback (cross-snapshot memory).
            $recalled = UserFeedback::query()
                ->whereNotNull('embedding')
                ->where('id', '!=', $feedback->id)
                ->nearestNeighbors('embedding', $vec, Distance::Cosine)
                ->limit(5)
                ->pluck('text')
                ->all();
        }

        // Real price history (accumulated across daily snapshots) per code.
        $hist = \App\Models\FundPrice::query()
            ->selectRaw('code, max(price) as hi, min(price) as lo, count(*) as n')
            ->groupBy('code')
            ->get()
            ->keyBy('code');

        // Holdings: the user states what they own in the feedback text; any
        // catalog fund whose name or code appears there is tagged OWNED so
        // the model can give it a keep/sell verdict instead of buy/watch/avoid.
        $fbNorm = $this->norm($feedbackText);
        $fbUpper = strtoupper($feedbackText);

        $funds = $catalog->map(function ($f) use ($hist, $fbNorm, $fbUpper) {
            $code = $f->code ?? ($f->extra['code'] ?? null);
            $h = $code ? $hist->get($code) : null;
            $px = (float) $f->unit_price;
            $peak = null;
            $pctBelowPeak = null;
            $nearHigh = null;
            $histDays = $h ? (int) $h->n : 0;
            if ($h && $histDays >= 2 && $h->hi > 0 && $px > 0) {
                $peak = (float) $h->hi;
                $pctBelowPeak = round((1 - $px / $peak) * 100, 1);
                $nearHigh = $px >= 0.985 * $peak;
            }

            $owned = false;
            if ($fbNorm !== '') {
                $nameNorm = $this->norm($f->name);
                $owned = (strlen($nameNorm) >= 8 && str_contains($fbNorm, $nameNorm))
                    || ($code && preg_match('/\b'.preg_quote(strtoupper($code), '/').'\b/', $fbUpper));
            }

            return [
                'code'           => $code,
                'owned'          => $owned,
                'name'           => $f->name,
                'fund_type'      => $f->fund_type,
                'shariah'        => $f->shariah,
                'unit_price'     => $f->unit_price,
                'selling_price'  => $f->selling_price,
                'return_ytd'     => $f->return_ytd,
                'return_1y'      => $f->return_1y,
                'return_3y'      => $f->return_3y,
                'return_5y'      => $f->return_5y,
                'return_10y'     => $f->return_10y,
                'perf_class'     => $f->perf_class,
                'category'       => $f->category,
                'risk'           => $f->risk,
                'currency'       => $f->currency,
                'hist_days'      => $histDays,
                'pct_below_peak' => $pctBelowPeak,
                'near_high'      => $nearHigh,
                'extra'          => $f->extra,
            ];
        })->all();

        $recs = $claude->recommend($funds, $feedbackText, $recalled);

        // Hallucination guard: a recommended fund MUST match a parsed fund of
        // this snapshot (by exact name, code, or unambiguous substring).
        $known = $catalog->map(fn ($f) => [
            'name' => $f->name,
            'code' => $f->code ?? ($f->extra['code'] ?? null),
            'norm' => $this->norm($f->name),
        ])->all();

        $byCode = collect($known)
            ->filter(fn ($k) => ! empty($k['code']))
            ->keyBy(fn ($k) => strtoupper($k['code']));

        $provider = config('ai.llm_provider');
        $model = $provider.':'.config("ai.{$provider}_model");

        foreach ($recs as $r) {
            if (empty($r['fund_name']) && empty($r['fund_code'])) {
                continue;
            }
            $action = strtolower(trim((string) ($r['action'] ?? '')));
            if (! in_array($action, ['buy', 'watch', 'avoid', 'keep', 'sell'], true)) {
                continue;
            }
            // Code is authoritative (model echoes it from the data line);
            // name matching is the fallback for code-less lines.
            $match = ! empty($r['fund_code'])
                ? $byCode->get(strtoupper(trim((string) $r['fund_code'])))
                : null;
            $match ??= ! empty($r['fund_name']) ? $this->matchFund($r['fund_name'], $known) : null;
            if (! $match) {
                continue; // drop hallucinated / unmatched fund
            }
            Recommendation::create([
                'user_id'       => $snapshot->user_id,
                'snapshot_id'   => $snapshot->id,
                'fund_name'     => $match['name'],   // canonical name from data
                'action'        => $action,
                'target_weight' => isset($r['target_weight']) && is_numeric($r['target_weight'])
                    ? (float) $r['target_weight'] : null,
                'rationale'     => $r['rationale'] ?? '',
                'model'         => $model,
            ]);
        }

        $snapshot->update(['status' => 'recommended']);
    }

    private function norm(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($s));
    }

    /**
     * Return the canonical fund (['name','code']) the model meant, or null
     * if the recommended name can't be confidently tied to a parsed fund.
     *
     * @param  array<int, array{name:string,code:?string,norm:string}>  $known
     */
    private function matchFund(string $raw, array $known): ?array
    {
        $rawU = strtoupper(trim($raw));
        $rn = $this->norm($raw);
        if ($rn === '') {
            return null;
        }

        foreach ($known as $k) {
            // exact code or exact normalised name
            if (($k['code'] && strtoupper($k['code']) === $rawU) || $k['norm'] === $rn) {
                return $k;
            }
        }

        // Unambiguous containment (one side fully contains the other, and the
        // shorter is >= 8 chars so it is specific enough).
        $cand = [];
        foreach ($known as $k) {
            $a = $rn;
            $b = $k['norm'];
            $short = strlen($a) < strlen($b) ? $a : $b;
            if (strlen($short) >= 8 && (str_contains($a, $b) || str_contains($b, $a))) {
                $cand[] = $k;
            }
        }
        return count($cand) === 1 ? $cand[0] : null;
    }

    public function failed(Throwable $e): void
    {
        Snapshot::whereKey($this->snapshotId)->update(['status' => 'failed']);
    }
}
