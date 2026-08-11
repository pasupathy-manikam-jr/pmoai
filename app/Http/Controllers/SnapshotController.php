<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractFundsJob;
use App\Models\Fund;
use App\Models\FundDetail;
use App\Models\Snapshot;
use App\Models\UserFeedback;
use App\Services\SourceLoader;
use Illuminate\Http\Request;

class SnapshotController extends Controller
{
    public function index()
    {
        $snapshots = Snapshot::query()
            ->withCount('recommendations')
            ->latest('id')
            ->limit(100)
            ->get();

        // Funds are a global catalog now — same count for every snapshot.
        $fundCount = Fund::count();

        return view('snapshots.index', compact('snapshots', 'fundCount'));
    }

    public function create()
    {
        return view('snapshots.create');
    }

    public function store(Request $request, SourceLoader $loader)
    {
        $data = $request->validate([
            'prices'      => ['required', 'string', 'min:1'],
            'performance' => ['nullable', 'string'],
            'info'        => ['nullable', 'string'],
            'feedback'    => ['nullable', 'string', 'max:5000'],
        ]);

        // Each input is paste OR local file path (RTF/.rtfd auto-converted).
        $prices = $loader->load($data['prices']);
        $perf   = ! empty($data['performance']) ? $loader->load($data['performance']) : '';
        $info   = ! empty($data['info']) ? $loader->load($data['info']) : '';

        if (mb_strlen(trim($prices)) < 20) {
            return back()->withInput()->withErrors([
                'prices' => 'Prices content too short. Paste the Prices tab or a valid file path.',
            ]);
        }

        // Join tabs with sentinels; parser splits + merges by fund code.
        $rawText = "[[PMOAI:PRICES]]\n{$prices}\n[[PMOAI:PERFORMANCE]]\n{$perf}\n[[PMOAI:INFO]]\n{$info}";

        $snapshot = $this->singleton($rawText, $request->user()?->id);

        if (! empty($data['feedback'])) {
            UserFeedback::create([
                'user_id'     => $request->user()?->id,
                'snapshot_id' => $snapshot->id,
                'text'        => $data['feedback'],
            ]);
        }

        // Run inline: parse + DB upsert is fast and finishes before the
        // response, so the snapshot is 'stored' immediately — no queue
        // worker, no 'pending' state, no auto-refresh loop.
        ExtractFundsJob::dispatchSync($snapshot->id);

        return redirect()->route('snapshots.show', $snapshot);
    }

    public function show(Snapshot $snapshot)
    {
        $snapshot->load(['recommendations', 'feedback']);

        // Global fund catalog (the latest capture).
        $funds = Fund::orderBy('name')->get();

        // Resolve a fund -> captured detail id. Primary key is the fund
        // CODE (stable across list/detail pages); normalized name is only
        // a fallback for old details captured before code resolution.
        $details = FundDetail::all(['id', 'code', 'name']);
        $detailByCode = $details->filter(fn ($d) => $d->code)
            ->mapWithKeys(fn ($d) => [strtoupper($d->code) => $d->id]);
        $detailMap = $details
            ->mapWithKeys(fn ($d) => [FundDetail::normalizeName($d->name) => $d->id]);

        // Funds the user holds (a stored position marks ownership).
        $heldCodes = FundDetail::whereRaw("payload->'position'->>'invested' is not null")
            ->whereNotNull('code')->where('code', '!=', '')
            ->pluck('code')
            ->map(fn ($c) => strtoupper($c))
            ->flip();

        // Deterministic buy-idea screen per fund (catalog returns only):
        // steady = multi-year compounding without a blown-up 1Y;
        // extended = 1Y far above the long-run rate (chasing risk);
        // weak = negative multi-year record.
        $idea = function ($f) use ($heldCodes) {
            if ($f->code && isset($heldCodes[strtoupper($f->code)])) {
                return 'held';
            }
            $r1 = $f->return_1y !== null ? (float) $f->return_1y : null;
            $r3 = $f->return_3y !== null ? (float) $f->return_3y : null;
            $r5 = $f->return_5y !== null ? (float) $f->return_5y : null;
            if ($r3 === null && $r5 === null) {
                return null;
            }
            if ($r1 !== null && ($r1 > 60 || ($r5 !== null && $r5 > 0 && $r1 > 2.5 * $r5))) {
                return 'extended';
            }
            if (($r3 !== null && $r3 < 0) || ($r5 !== null && $r5 < 0)) {
                return 'weak';
            }
            // Quality-down-now: proven multi-year compounder having a down year —
            // low price on a healthy engine, not a chronic decliner.
            $ytd = $f->return_ytd !== null ? (float) $f->return_ytd : null;
            if ($r5 !== null && $r5 >= 4 && $r3 !== null && $r3 >= 3
                && (($ytd !== null && $ytd < 0) || ($r1 !== null && $r1 < 0))) {
                return 'dip';
            }
            if ($r5 !== null && $r5 >= 4 && $r3 !== null && $r3 >= 4) {
                return 'steady';
            }
            return null;
        };
        $ideas = $funds->mapWithKeys(fn ($f) => [$f->id => $idea($f)]);

        // My portfolio: every detail with a stored position → summary block.
        // Position start dates + switch origins from the transaction store.
        $txStart = \App\Models\Transaction::selectRaw('upper(fund_code) c, min(trans_date) d')
            ->groupBy('c')->pluck('d', 'c');
        $acctFund = \App\Models\Transaction::select('account_no', 'fund_code')
            ->distinct()->pluck('fund_code', 'account_no');
        $swsFirst = \App\Models\Transaction::where('trans_type', 'SWS')
            ->orderBy('trans_date')->get()
            ->groupBy(fn ($t) => strtoupper($t->fund_code))
            ->map(function ($g) use ($acctFund) {
                $ref = $g->first()->reference ?? '';
                return preg_match('/(\d{6,12})/', $ref, $m)
                    ? ($acctFund[$m[1]] ?? null)
                    : null;
            });

        // Current-run start: walk acquisitions backward until they cover the
        // position's current cost — the earliest one in that suffix is when
        // this run was built. If known acquisitions never reach the cost,
        // the run began before the statement archive ("< date").
        $txsDesc = \App\Models\Transaction::orderByDesc('trans_date')->get()
            ->groupBy(fn ($t) => strtoupper($t->fund_code));
        $acqTypes = ['II', 'AI', 'RII', 'SI', 'SWS', 'DR'];
        $runStartFor = function (?string $code, float $invested) use ($txsDesc, $acqTypes) {
            if (! $code || ! isset($txsDesc[$code]) || $invested <= 0) {
                return [null, false];
            }
            $acc = 0.0;
            $start = null;
            foreach ($txsDesc[$code] as $t) {
                if (! in_array($t->trans_type, $acqTypes, true)) {
                    continue;
                }
                $acc += abs((float) ($t->net ?: $t->gross ?: 0));
                $start = $t->trans_date;
                if ($acc >= $invested * 0.98) {
                    return [$start, true];   // suffix covers the cost — exact
                }
            }
            return [$start, false];          // ran out — run predates archive
        };

        $portfolio = FundDetail::whereRaw("payload->'position'->>'invested' is not null")
            ->get()
            ->map(function ($d) use ($txStart, $swsFirst, $runStartFor) {
                $pos = $d->payload['position'];
                $value = (float) $pos['current_value'];
                $code = $d->code ? strtoupper($d->code) : null;
                $x = $d->code ? \App\Services\Xirr::forFund($d->code, $value) : null;

                // payload.since is authoritative (from the fund's own
                // Ut_AcctDetails "Initial Investment on" line); fall back to
                // the oldest statement transaction with a "≥" flag when the
                // history is known-incomplete.
                if (! empty($pos['since'])) {
                    $started = $pos['since'];
                    $startedMin = false;
                } else {
                    $started = $code && isset($txStart[$code]) ? $txStart[$code] : null;
                    $startedMin = $x !== null && ! $x['complete'] && $started !== null;
                }

                [$runStart, $runExact] = $runStartFor($code, (float) $pos['invested']);

                return [
                    'id'          => $d->id,
                    'name'        => $d->name,
                    'invested'    => (float) $pos['invested'],
                    'value'       => $value,
                    'xirr'        => $x,
                    'started'     => $started,
                    'started_min' => $startedMin,
                    'run_start'   => $runStart,
                    'run_exact'   => $runExact,
                    'origin'      => $code ? ($swsFirst[$code] ?? null) : null,
                    // per-account sub-positions (same fund held in >1 account,
                    // e.g. two PRS accounts) — null/1-elem = single account.
                    'accounts'    => $d->payload['positions'] ?? null,
                ];
            })
            ->sortByDesc('value')
            ->values();

        // Past funds: transacted but no current position — the exit history.
        $heldFundCodes = $portfolio->pluck('id')
            ->map(fn ($id) => FundDetail::find($id)->code)
            ->filter()->map(fn ($c) => strtoupper($c))->flip();
        $fundNames = Fund::whereNotNull('code')->get()
            ->mapWithKeys(fn ($f) => [strtoupper($f->code) => $f->name]);
        $past = $txsDesc
            ->reject(fn ($g, $code) => isset($heldFundCodes[$code]))
            ->map(function ($g, $code) use ($fundNames) {
                $in = $g->whereIn('trans_type', ['II', 'AI', 'RII', 'SI', 'SWS', 'DR'])
                    ->sum(fn ($t) => abs((float) $t->net));
                $out = $g->whereIn('trans_type', ['SWR', 'RP', 'SRR', 'DP', 'REV', 'COF'])
                    ->sum(fn ($t) => abs((float) $t->net));
                // complete round-trip if the units net out to ~zero
                $unitsNet = $g->sum(fn ($t) => (float) $t->units);
                return [
                    'code'     => $code,
                    'name'     => $fundNames[$code] ?? $code,
                    'first'    => $g->min('trans_date'),
                    'last'     => $g->max('trans_date'),
                    'in'       => $in,
                    'out'      => $out,
                    'complete' => abs($unitsNet) < 1,
                ];
            })
            ->sortByDesc('last')
            ->values();

        // PRS tax-relief tracker + true PRS return (contribution history is
        // complete: the yearly summaries reconcile to the account's cost).
        $prsContribs = \App\Models\Transaction::where('trans_ref', 'like', 'PRSC-%')
            ->orderBy('trans_date')->get();
        $prsThisYear = $prsContribs->filter(fn ($t) => $t->trans_date->year === now('Asia/Kuala_Lumpur')->year)
            ->sum('net');
        $prsValue = $portfolio->filter(fn ($h) => str_starts_with($h['name'], 'PRS'))->sum('value');

        // Per-year PRS contribution history — each year's total vs the
        // RM3,000 relief cap (relief is capped per person per year, so a
        // year over RM3,000 wastes the excess). All from captured PRSC rows.
        $prsHistory = $prsContribs->groupBy(fn ($t) => $t->trans_date->year)
            ->map(function ($g, $yr) {
                $amt = (float) $g->sum('net');
                return [
                    'year'    => (int) $yr,
                    'amount'  => $amt,
                    'relief'  => min($amt, 3000),          // claimable this year
                    'wasted'  => max(0, $amt - 3000),      // excess, no relief
                    'maxed'   => $amt >= 3000,
                    'funds'   => $g->pluck('fund_code')->unique()->values()->all(),
                ];
            })->values()->sortByDesc('year')->values();
        $prsTotals = [
            'contributed' => (float) $prsHistory->sum('amount'),
            'relief'      => (float) $prsHistory->sum('relief'),
            'wasted'      => (float) $prsHistory->sum('wasted'),
            'years'       => $prsHistory->count(),
        ];

        $prsXirr = null;
        if ($prsContribs->isNotEmpty() && $prsValue > 0) {
            $flows = $prsContribs->map(fn ($t) => ['date' => $t->trans_date, 'amount' => -(float) $t->net])->all();
            $flows[] = ['date' => now(), 'amount' => $prsValue];
            $prsXirr = \App\Services\Xirr::compute($flows);
        }

        $alerts = \App\Models\Alert::where('active', true)->orderBy('fund_code')->get();
        $history = \App\Models\PortfolioSnapshot::orderBy('snap_date')->get();
        $review = \App\Models\PortfolioReview::latest('id')->first();

        // Verdict backtest: for each held fund's last AI call, did the price
        // move the way the verdict implied? Bullish (buy/keep) wants up;
        // bearish (reduce/sell) wants down. Uses captured price history.
        $analysis = app(\App\Services\FundAnalysis::class);
        $backtest = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")
            ->whereRaw("payload->'ai'->>'text' is not null")
            ->get()
            ->map(function ($d) use ($analysis) {
                [$code, $hist, $fund] = $analysis->resolve($d);
                $at = $d->payload['ai']['at'] ?? null;
                if (! $at || $hist->isEmpty()
                    || ! preg_match('/\b(BUY|KEEP|HOLD|ACCUMULATE|REDUCE|TRIM|SELL|AVOID)\b/i', $d->payload['ai']['text'], $m)) {
                    return null;
                }
                $verdict = strtoupper($m[1]);
                $atDate = substr((string) $at, 0, 10);
                $then = optional($hist->filter(fn ($p) => $p['date'] <= $atDate)->last())['price']
                    ?? $hist->first()['price'];
                $now = $hist->last()['price'];
                $pct = $then > 0 ? ($now - $then) / $then * 100 : 0.0;
                $bull = in_array($verdict, ['BUY', 'KEEP', 'HOLD', 'ACCUMULATE']);
                $correct = abs($pct) < 1 ? null : ($bull ? $pct > 0 : $pct < 0);

                return [
                    'name' => $fund?->name ?? $d->name, 'verdict' => $verdict,
                    'at' => $atDate, 'then' => (float) $then, 'now' => (float) $now,
                    'pct' => $pct, 'bull' => $bull, 'correct' => $correct,
                ];
            })
            ->filter()
            ->sortByDesc('at')
            ->values();

        // Full transaction ledger (newest first) for the Transactions tab.
        // Resolve the fund name from the catalog; friendly type label + the
        // units sign gives buy/sell direction for colouring.
        $fundNames = $funds->filter(fn ($f) => $f->code)
            ->mapWithKeys(fn ($f) => [strtoupper($f->code) => $f->name]);
        $txLabels = [
            'II' => 'Initial buy', 'AI' => 'Add buy', 'RII' => 'Reinvest',
            'SI' => 'Switch in', 'SWS' => 'Switch in', 'SWR' => 'Switch out',
            'RP' => 'Redeem', 'DR' => 'Distribution', 'DP' => 'Distribution',
        ];
        $transactions = \App\Models\Transaction::orderByDesc('trans_date')->orderByDesc('id')->get()
            ->map(fn ($t) => [
                'date'       => $t->trans_date,
                'fund_code'  => $t->fund_code,
                'fund'       => $fundNames[strtoupper($t->fund_code)] ?? $t->fund_code,
                'type'       => $t->trans_type,
                'label'      => $txLabels[$t->trans_type] ?? $t->trans_type,
                'account_no' => $t->account_no,
                'gross'      => $t->gross,
                'charge_amt' => $t->charge_amt,
                'net'        => $t->net,
                'price'      => $t->price,
                'units'      => $t->units,
            ]);

        // Float / pending — submitted, not yet processed (kept out of the
        // settled ledger). Shown at the top of the Transactions tab.
        $pending = \App\Models\PendingTransaction::orderByDesc('submitted_at')->get();

        // Cost attribution — real charges paid to PMO across every transaction.
        $allTx = \App\Models\Transaction::all();
        $attribution = [
            'sales_charge' => (float) $allTx->sum(fn ($t) => (float) $t->charge_amt),
            'sst'          => (float) $allTx->sum(fn ($t) => (float) $t->sst),
            'tx_count'     => $allTx->count(),
            'charged_tx'   => $allTx->filter(fn ($t) => (float) $t->charge_amt > 0)->count(),
        ];

        // "Does the data still add up?" — total-drift + freshness checks.
        $reconcile = app(\App\Services\ReconciliationService::class)->check();

        // Currency-mix history (accrues from the first capture that stored it).
        $expoHistory = \App\Models\PortfolioSnapshot::whereNotNull('exposure')
            ->orderBy('snap_date')->get(['snap_date', 'exposure']);

        return view('snapshots.show', compact(
            'snapshot', 'funds', 'detailMap', 'detailByCode', 'ideas', 'portfolio',
            'alerts', 'history', 'review', 'past', 'prsThisYear', 'prsXirr',
            'transactions', 'pending', 'backtest', 'attribution', 'reconcile',
            'prsHistory', 'prsTotals', 'expoHistory',
        ));
    }

    /**
     * The one canonical snapshot. There is ever only ONE: every new-day
     * capture replaces it in place (raw_text + status reset, previous
     * recommendations dropped). The permanent per-day price series accrues
     * separately in fund_prices via ExtractFundsJob::accumulatePrices().
     */
    private function singleton(string $rawText, ?int $userId): Snapshot
    {
        $snapshot = Snapshot::orderBy('id')->first();

        if ($snapshot) {
            // Replace = fresh analysis: clear the prior run's recommendations.
            $snapshot->recommendations()->delete();
            $snapshot->update([
                'user_id'  => $userId,
                'raw_text' => $rawText,
                'status'   => 'pending',
            ]);

            return $snapshot;
        }

        return Snapshot::create([
            'user_id'  => $userId,
            'raw_text' => $rawText,
            'status'   => 'pending',
        ]);
    }

    /**
     * Token-protected JSON ingest for the Tampermonkey userscript.
     * Body: { token, prices, performance?, info?, feedback? }
     */
    public function ingest(Request $request)
    {
        $token = config('ai.ingest_token');
        $given = $request->header('X-PMOAI-TOKEN') ?: $request->input('token');
        if (! $token || ! is_string($given) || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'prices'      => ['required', 'string', 'min:20'],
            'performance' => ['nullable', 'string'],
            'info'        => ['nullable', 'string'],
            'feedback'    => ['nullable', 'string', 'max:5000'],
            'skip_ai'     => ['nullable', 'boolean'],
        ]);

        $raw = "[[PMOAI:PRICES]]\n".$data['prices']
            ."\n[[PMOAI:PERFORMANCE]]\n".($data['performance'] ?? '')
            ."\n[[PMOAI:INFO]]\n".($data['info'] ?? '');

        $snapshot = $this->singleton($raw, null);

        if (! empty($data['feedback'])) {
            UserFeedback::create([
                'snapshot_id' => $snapshot->id,
                'text'        => $data['feedback'],
            ]);
        }

        // Inline ingest: parse + upsert completes within the request, so
        // the snapshot is 'stored' before this responds. No queue worker,
        // no 'pending', no show/index auto-refresh.
        ExtractFundsJob::dispatchSync($snapshot->id, (bool) ($data['skip_ai'] ?? false));

        return response()->json([
            'id'  => $snapshot->id,
            'url' => route('snapshots.show', $snapshot),
        ]);
    }

    /**
     * Token-protected ingest for an MFR / fund-review booklet PDF, sent as
     * base64 by the pmoai-mfr userscript from publicmutual.com.my.
     * Body: { token, filename, pdf_b64 }
     */
    public function ingestMfr(Request $request, \App\Services\MfrIngest $mfr)
    {
        $token = config('ai.ingest_token');
        $given = $request->header('X-PMOAI-TOKEN') ?: $request->input('token');
        if (! $token || ! is_string($given) || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'pdf_b64'  => ['required', 'string'],
        ]);

        $bytes = base64_decode($data['pdf_b64'], true);
        if ($bytes === false || ! str_starts_with($bytes, '%PDF')) {
            return response()->json(['error' => 'body is not a PDF'], 422);
        }

        // PMO serves every booklet under the same generic filename; the
        // report period comes from the PDF content, not the name. Prefix a
        // timestamp so archived copies never overwrite each other.
        $safe = preg_replace('/[^A-Za-z0-9 ._-]/', '', $data['filename']) ?: 'mfr.pdf';
        $dir = storage_path('app/mfr');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.now()->format('Ymd-His').' '.$safe;
        file_put_contents($path, $bytes);

        try {
            $res = $mfr->ingestFile($path);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $res);
    }

    /**
     * Token-protected RAW page collector: while auto-collect is on, the
     * userscript posts every PMO page the user visits (url + text + tables).
     * Stored verbatim, deduped by hash; structured parsers mine this later.
     * Body: { token, url, title?, text, tables? }
     */
    public function ingestPage(Request $request)
    {
        $token = config('ai.ingest_token');
        $given = $request->header('X-PMOAI-TOKEN') ?: $request->input('token');
        if (! $token || ! is_string($given) || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'url'    => ['required', 'string', 'max:1000'],
            'title'  => ['nullable', 'string', 'max:255'],
            'text'   => ['required', 'string', 'min:40', 'max:400000'],
            'tables' => ['nullable', 'array', 'max:30'],
            'links'  => ['nullable', 'array', 'max:300'],
        ]);

        $hash = sha1($data['url'].'|'.$data['text']);
        if (\App\Models\PageCapture::where('hash', $hash)->exists()) {
            return response()->json(['ok' => true, 'stored' => false, 'reason' => 'duplicate']);
        }

        \App\Models\PageCapture::create([
            'url'         => $data['url'],
            'title'       => $data['title'] ?? null,
            'hash'        => $hash,
            'text'        => $data['text'],
            'tables'      => $data['tables'] ?? null,
            'links'       => $data['links'] ?? null,
            'captured_at' => now(),
        ]);

        return response()->json(['ok' => true, 'stored' => true]);
    }

    /**
     * Token-protected ingest of the PMO HOLDINGS page (the user's actual
     * positions). Writes payload.position on each matching fund detail so
     * detail pages prefill and analyses run position-aware.
     * Body: { token, holdings: [{name, code?, market_value, investment_cost}] }
     */
    public function ingestHoldings(Request $request)
    {
        $token = config('ai.ingest_token');
        $given = $request->header('X-PMOAI-TOKEN') ?: $request->input('token');
        if (! $token || ! is_string($given) || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'holdings'                     => ['required', 'array', 'min:1', 'max:50'],
            'holdings.*.name'              => ['required', 'string', 'max:255'],
            'holdings.*.code'              => ['nullable', 'string', 'max:32'],
            'holdings.*.account_no'        => ['nullable', 'string', 'max:32'],
            'holdings.*.market_value'      => ['required', 'numeric', 'min:0'],
            'holdings.*.investment_cost'   => ['required', 'numeric', 'min:0'],
            'holdings.*.price'             => ['nullable', 'numeric', 'min:0'],
        ]);

        $details = FundDetail::all(['id', 'code', 'name']);
        $results = [];

        // Resolve every row first, then GROUP rows that land on the same fund
        // — the same fund can be held in multiple accounts (e.g. two PRS
        // accounts). Each account is kept as its own sub-position; the
        // aggregate (sum) is also stored so simulator/analysis/review/equity
        // curve keep reading one `position` per fund unchanged.
        $byDetail = [];
        foreach ($data['holdings'] as $h) {
            $match = null;
            if (! empty($h['code'])) {
                $c = strtoupper(trim($h['code']));
                $match = $details->first(fn ($d) => $d->code && strtoupper($d->code) === $c);
            }
            if (! $match) {
                $norm = FundDetail::normalizeName($h['name']);
                $cands = $details->filter(fn ($d) => FundDetail::normalizeName($d->name) === $norm)->values();
                if ($cands->count() !== 1 && strlen($norm) >= 8) {
                    $cands = $details->filter(function ($d) use ($norm) {
                        $dn = FundDetail::normalizeName($d->name);
                        return str_contains($dn, $norm) || str_contains($norm, $dn);
                    })->values();
                }
                $match = $cands->count() === 1 ? $cands->first() : null;
            }
            if (! $match) {
                $results[] = ['name' => $h['name'], 'ok' => false, 'error' => 'no unique fund match'];
                continue;
            }
            $byDetail[$match->id]['name']       = $h['name'];
            $byDetail[$match->id]['accounts'][] = [
                'account_no'      => $h['account_no'] ?? null,
                'investment_cost' => (float) $h['investment_cost'],
                'market_value'    => (float) $h['market_value'],
                'price'           => isset($h['price']) ? (float) $h['price'] : null,
            ];
        }

        foreach ($byDetail as $detailId => $info) {
            $detail = FundDetail::find($detailId);
            $payload = $detail->payload ?? [];
            $prevPositions = $payload['positions'] ?? [];
            $prevSince = $payload['position']['since'] ?? null;

            // One sub-position per account. Preserve each account's first
            // tracked date by matching account_no against the prior capture.
            $positions = [];
            foreach ($info['accounts'] as $a) {
                $since = null;
                if ($a['account_no']) {
                    foreach ($prevPositions as $pp) {
                        if (($pp['account_no'] ?? null) === $a['account_no']) {
                            $since = $pp['since'] ?? null;
                            break;
                        }
                    }
                }
                $positions[] = [
                    'account_no'    => $a['account_no'],
                    'invested'      => $a['investment_cost'],
                    'current_value' => $a['market_value'],
                    'price'         => $a['price'],
                    'since'         => $since ?? $prevSince ?? now()->toDateString(),
                ];
            }

            $payload['positions'] = $positions;
            // Aggregate across accounts — the fund-level view every other
            // consumer reads. `since` = earliest tracked account.
            $payload['position'] = [
                'invested'      => array_sum(array_column($positions, 'invested')),
                'current_value' => array_sum(array_column($positions, 'current_value')),
                'since'         => collect($positions)->pluck('since')->filter()->min()
                    ?? now()->toDateString(),
            ];
            $detail->update(['payload' => $payload]);

            // price identical across accounts of the same fund — take any
            $h = ['price' => collect($info['accounts'])->pluck('price')->filter()->first()];

            // Feed the daily price series too (PRS funds have no other
            // price source; UT funds just get an extra same-day point).
            if (! empty($h['price']) && $detail->code) {
                $now = now();
                \App\Models\FundPrice::upsert(
                    [[
                        // canonical catalog casing — the holdings path used to
                        // write uppercase codes, splitting mixed-case e-Series
                        // funds (PeEMAS) into a second monthly row.
                        'code'           => \App\Models\Fund::canonicalCode($detail->code),
                        'name'           => $detail->name,
                        'price'          => (float) $h['price'],
                        'price_date'     => $now->toDateString(),
                        'period'         => $now->format('Y-m'),
                        'd'.(int) $now->day => (float) $h['price'],
                        'created_at'     => $now,
                    ]],
                    ['code', 'period'],
                    ['d'.(int) $now->day, 'price', 'name', 'price_date', 'created_at'],
                );
            }

            $results[] = ['name' => $info['name'], 'ok' => true, 'detail_id' => $detail->id,
                'accounts' => count($positions)];
        }

        // Portfolio value history: one row per day, refreshed on every
        // holdings capture — the equity curve's data source.
        $held = FundDetail::whereRaw("payload->'position'->>'invested' is not null")->get();
        // Currency mix today → {ccy: pct}, so exposure drift accrues over time.
        $expo = collect(app(\App\Services\PortfolioExposure::class)->currencies()['rows'])
            ->mapWithKeys(fn ($r) => [$r['ccy'] => round($r['pct'], 1)])->all();
        \App\Models\PortfolioSnapshot::updateOrCreate(
            ['snap_date' => now()->toDateString()],
            [
                'invested' => $held->sum(fn ($d) => (float) $d->payload['position']['invested']),
                'value'    => $held->sum(fn ($d) => (float) $d->payload['position']['current_value']),
                'exposure' => $expo ?: null,
            ],
        );

        // New prices in → evaluate price triggers.
        $firedAlerts = app(\App\Services\AlertCheck::class)->run();

        return response()->json([
            'updated' => collect($results)->where('ok', true)->count(),
            'results' => $results,
            'alerts_fired' => collect($firedAlerts)->map(fn ($a) => $a->label)->values(),
        ]);
    }

    /**
     * Standalone what-if switch simulator (client-side math; this just
     * supplies holdings + catalog).
     */
    /** Print-friendly one-page portfolio report (browser → Save as PDF). */
    public function report(Snapshot $snapshot)
    {
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")
            ->get()
            ->map(function ($d) {
                $pos = $d->payload['position'];
                $inv = (float) ($pos['invested'] ?? 0);
                $val = (float) $pos['current_value'];
                $verdict = null;
                if (! empty($d->payload['ai']['text'])
                    && preg_match('/\b(BUY|KEEP|HOLD|ACCUMULATE|REDUCE|TRIM|SELL|AVOID)\b/i', $d->payload['ai']['text'], $m)) {
                    $verdict = strtoupper($m[1]);
                }

                return ['name' => $d->name, 'invested' => $inv, 'value' => $val,
                    'pl' => $val - $inv, 'verdict' => $verdict];
            })
            ->sortByDesc('value')->values();

        $totInv = $held->sum('invested');
        $totVal = $held->sum('value');

        return view('snapshots.report', compact('snapshot', 'held', 'totInv', 'totVal'));
    }

    /**
     * Rebalance simulator — set a target weight per held fund and get the PMO
     * switches/redemptions to reach it plus the real sales-charge cost.
     */
    /**
     * Whole-catalogue advisor — deterministic screener over all ~190 funds,
     * cross-referenced with your holdings + PMO rules. Informational only.
     */
    public function advisor(\App\Services\PortfolioAdvisor $advisor)
    {
        $plan = $advisor->analyze();
        $ai = \Illuminate\Support\Facades\Cache::get(\App\Jobs\AdviseNarrativeJob::KEY);
        // Armed price triggers, grouped by fund, to show/allow-remove per row.
        $alerts = \App\Models\Alert::where('active', true)->whereNull('fired_at')
            ->get()->groupBy(fn ($a) => strtoupper($a->fund_code));
        $chat = \Illuminate\Support\Facades\Cache::get(\App\Jobs\AdviseChatJob::KEY, ['status' => 'idle', 'messages' => []]);

        return view('snapshots.advisor', compact('plan', 'ai', 'alerts', 'chat'));
    }

    /** Arm a price trigger from the advisor (act at a better moment). */
    public function storeAlert(Request $request)
    {
        $data = $request->validate([
            'fund_code' => ['required', 'string', 'max:20'],
            'condition' => ['required', 'in:below,above'],
            'level'     => ['required', 'numeric', 'min:0'],
            'label'     => ['nullable', 'string', 'max:255'],
        ]);

        \App\Models\Alert::updateOrCreate(
            ['fund_code' => strtoupper($data['fund_code']), 'condition' => $data['condition'], 'level' => $data['level']],
            ['label' => $data['label'] ?: (strtoupper($data['fund_code']).' '.$data['condition'].' '.$data['level']),
                'active' => true, 'fired_at' => null, 'fired_price' => null],
        );

        return back()->with('status', 'Alert armed — it fires on the next quote/price capture.')->withFragment('board');
    }

    public function deleteAlert(\App\Models\Alert $alert)
    {
        $alert->delete();

        return back()->with('status', 'Alert removed.')->withFragment('board');
    }

    /** Queue the plain-English AI summary of the advisor plan (slow provider). */
    public function adviseExplain()
    {
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\AdviseNarrativeJob::KEY,
            ['status' => 'running', 'at' => now()->toDateTimeString()], now()->addHours(1));
        \App\Jobs\AdviseNarrativeJob::dispatch();
        \App\Support\Worker::spawn();

        return redirect()->route('advisor')->withFragment('ai');
    }

    public function adviseStatus()
    {
        $ai = \Illuminate\Support\Facades\Cache::get(\App\Jobs\AdviseNarrativeJob::KEY);

        return response()->json(['status' => $ai['status'] ?? 'none'])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /** One chat turn about the advisor plan (queue + poll). */
    public function adviseChat(Request $request)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        $state = \Illuminate\Support\Facades\Cache::get(\App\Jobs\AdviseChatJob::KEY, ['messages' => []]);
        $messages = $state['messages'] ?? [];
        $messages[] = ['role' => 'user', 'text' => $data['message'], 'at' => now()->toDateTimeString()];
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\AdviseChatJob::KEY,
            ['status' => 'running', 'messages' => array_slice($messages, -20)], now()->addHours(12));

        \App\Jobs\AdviseChatJob::dispatch();
        \App\Support\Worker::spawn();

        return redirect()->route('advisor')->withFragment('ai-chat');
    }

    public function adviseChatStatus()
    {
        $s = \Illuminate\Support\Facades\Cache::get(\App\Jobs\AdviseChatJob::KEY);

        return response()->json(['status' => $s['status'] ?? 'idle'])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /** Delete one advisor-chat message by index. */
    public function adviseChatDelete(Request $request)
    {
        $data = $request->validate(['i' => ['required', 'integer', 'min:0']]);

        $state = \Illuminate\Support\Facades\Cache::get(\App\Jobs\AdviseChatJob::KEY, ['messages' => []]);
        $messages = $state['messages'] ?? [];
        if (array_key_exists($data['i'], $messages)) {
            array_splice($messages, $data['i'], 1);
            \Illuminate\Support\Facades\Cache::put(\App\Jobs\AdviseChatJob::KEY,
                ['status' => 'idle', 'messages' => $messages], now()->addHours(12));
        }

        return back()->withFragment('ai-chat');
    }

    /** Clear the whole advisor-chat conversation. */
    public function adviseChatClear()
    {
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\AdviseChatJob::KEY);

        return back()->withFragment('ai-chat');
    }

    public function rebalance()
    {
        $analysis = app(\App\Services\FundAnalysis::class);
        $held = FundDetail::whereRaw("payload->'position'->>'current_value' is not null")
            ->get()
            ->map(function ($d) use ($analysis) {
                [$code, $hist, $fund] = $analysis->resolve($d);

                return [
                    'name'  => $fund?->name ?? $d->name,
                    'code'  => $fund?->code ?? $code ?? '',
                    'value' => (float) $d->payload['position']['current_value'],
                ];
            })
            ->sortByDesc('value')->values();

        return view('snapshots.rebalance', compact('held'));
    }

    public function simulator()
    {
        $funds = Fund::orderBy('name')->get();

        // Real risk per fund from the latest MFR/QFR factsheet: volatility
        // factor + class, keyed by upper code. Lets the simulator show a
        // switch's RISK change, not just its return — a higher-return move
        // into a much more volatile fund is not a free win.
        $vol = \App\Models\FundFactsheet::query()
            ->whereIn('period', function ($q) {
                $q->from('fund_factsheets')->selectRaw('max(period)');
            })
            ->get(['code', 'volatility_factor', 'volatility_class', 'benchmark_name'])
            ->mapWithKeys(fn ($f) => [strtoupper($f->code) => [
                'vf'        => $f->volatility_factor !== null ? (float) $f->volatility_factor : null,
                'vclass'    => $f->volatility_class,
                'benchmark' => $f->benchmark_name,
            ]]);

        $details = FundDetail::all(['id', 'code', 'name']);
        $detailByCode = $details->filter(fn ($d) => $d->code)
            ->mapWithKeys(fn ($d) => [strtoupper($d->code) => $d->id]);

        $portfolio = FundDetail::whereRaw("payload->'position'->>'invested' is not null")
            ->get()
            ->map(fn ($d) => [
                'id'       => $d->id,
                'name'     => $d->name,
                'invested' => (float) $d->payload['position']['invested'],
                'value'    => (float) $d->payload['position']['current_value'],
                'since'    => $d->payload['position']['since'] ?? null,
            ])
            ->sortByDesc('value')
            ->values();

        return response()
            ->view('snapshots.simulator', compact('funds', 'portfolio', 'detailByCode', 'vol'))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Whole-portfolio AI review: queue + poll (same pattern as fund analyze).
     */
    public function portfolioReview()
    {
        $review = \App\Models\PortfolioReview::create(['status' => 'running']);
        \App\Jobs\PortfolioReviewJob::dispatch($review->id);
        \App\Support\Worker::spawn();

        return redirect()->route('snapshots.show', Snapshot::orderBy('id')->firstOrFail())
            ->withFragment('portfolio-review');
    }

    public function portfolioReviewStatus()
    {
        $r = \App\Models\PortfolioReview::latest('id')->first();

        return response()->json(['status' => $r?->status ?? 'none'])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * Token-protected JSON ingest for a single fund's DETAIL page.
     * Reference store only — does NOT enter the recommendation pipeline.
     * One row per fund name; re-capture overwrites (latest wins).
     * Body: { token, name, code?, payload?, raw_text, source_url? }
     */
    public function ingestDetail(Request $request)
    {
        $token = config('ai.ingest_token');
        $given = $request->header('X-PMOAI-TOKEN') ?: $request->input('token');
        if (! $token || ! is_string($given) || ! hash_equals($token, $given)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:255'],
            'code'       => ['nullable', 'string', 'max:32'],
            'payload'    => ['nullable', 'array'],
            'raw_text'   => ['required', 'string', 'min:50'],
            'source_url' => ['nullable', 'string', 'max:1000'],
        ]);

        // Key on code (stable join to the fund catalog) when the userscript
        // resolved one; fall back to name only if code is missing. Names
        // drift between list and detail pages, so code-keyed is canonical.
        // Case-insensitive: e-Series catalog codes are mixed case (PeEMAS)
        // while PMO detail pages report them uppercase.
        $code = ! empty($data['code']) ? strtoupper(trim($data['code'])) : null;
        $detail = $code
            ? FundDetail::whereRaw('upper(code) = ?', [$code])->first()
            : null;
        $detail ??= FundDetail::where('name', $data['name'])->first();

        // Re-capture refreshes the PAGE payload but must never wipe the
        // app-owned keys (user position, stored AI analysis).
        $payload = $data['payload'] ?? [];
        if ($detail) {
            $old = $detail->payload ?? [];
            foreach (['position', 'ai', 'ai_status', 'ai_error'] as $k) {
                if (isset($old[$k]) && ! isset($payload[$k])) {
                    $payload[$k] = $old[$k];
                }
            }
        }

        $attrs = [
            'name'        => $data['name'],
            'payload'     => $payload ?: null,
            'raw_text'    => $data['raw_text'],
            'source_url'  => $data['source_url'] ?? null,
            'captured_at' => now(),
        ];

        if ($detail) {
            // keep the existing (possibly mixed-case) code
            $detail->update($attrs + ['code' => $detail->code ?: $code]);
        } else {
            $detail = FundDetail::create($attrs + ['code' => $code]);
        }

        // Self-heal: a code-less detail can't join the catalog, prices, or
        // factsheets — resolve it from the catalog by normalized name.
        if (! $detail->code) {
            $norm = FundDetail::normalizeName($detail->name);
            $cat = Fund::whereNotNull('code')->get(['code', 'name'])
                ->first(fn ($f) => FundDetail::normalizeName($f->name) === $norm);
            if ($cat) {
                $detail->update(['code' => $cat->code]);
            }
        }

        return response()->json([
            'id'   => $detail->id,
            'name' => $detail->name,
            'code' => $detail->code,
        ]);
    }
}
