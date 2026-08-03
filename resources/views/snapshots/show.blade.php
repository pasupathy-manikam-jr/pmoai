@extends('layouts.app')

@section('title', "PMFAI — portfolio")
@section('body-class', 'page-show')

@push('head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
@endpush

@unless (in_array($snapshot->status, ['recommended', 'failed', 'stored']))
    @push('head')
        <meta http-equiv="refresh" content="4">
    @endpush
@endunless

@section('content')
    @php
        $ptInv = $portfolio->sum('invested');
        $ptVal = $portfolio->sum('value');
        $ptPl  = $ptVal - $ptInv;
        $fmtRm = fn ($v) => ($v < 0 ? '−' : '').'RM '.number_format(abs($v), 2);
        $fired = $alerts->whereNotNull('fired_at');
        $armed = $alerts->whereNull('fired_at');
    @endphp



    @foreach ($fired as $a)
        @php
            $isIdx = (bool) $a->market_symbol;
            $fn = $isIdx
                ? (collect(config('quotes.indices'))->firstWhere('symbol', $a->market_symbol)['label'] ?? $a->market_symbol)
                : (optional($funds->first(fn ($f) => strtoupper($f->code ?? '') === strtoupper($a->fund_code)))->name ?? $a->fund_code);
            $dp = $isIdx ? 2 : 4;
            $unit = $isIdx ? '' : 'RM';
            $dir = $a->condition === 'below' ? 'dropped to' : 'rose to';
            $lvlTxt = rtrim(rtrim(number_format((float) $a->level, $dp), '0'), '.');
            $watch = $a->condition === 'below' ? 'watch-below' : 'watch-above';
        @endphp
        <div class="alert-fired">
            🔔 <b>{{ $fn }}</b> {{ $dir }} <b>{{ $unit }}{{ number_format((float) $a->fired_price, $dp) }}</b>
            on {{ $a->fired_at->format('d M Y') }} — your {{ $watch }} level of {{ $unit }}{{ $lvlTxt }} was reached.
            @if ($a->explanation)
                <div class="alert-exp">{{ $a->explanation }}</div>
            @endif
            <details class="alert-raw"><summary>signal detail</summary>{{ $a->label }}</details>
        </div>
    @endforeach
    <style>
        .alert-exp { margin-top: 4px; font-size: 13px; color: #444; }
        .alert-raw, .trig-raw { margin-top: 3px; font-size: 11px; color: #999; }
        .alert-raw summary { cursor: pointer; }
        .trig-plain { font-weight: 600; }
        .trig-now { color: #888; font-size: 12px; }
    </style>

    @if ($portfolio->isNotEmpty())
        <section class="ps-card">
            <div class="ps-tabs" role="tablist">
                <button type="button" class="ps-tab active" data-tab="tab-overview">Overview</button>
                <button type="button" class="ps-tab" data-tab="tab-holdings">My holdings ({{ $portfolio->count() }})</button>
                <button type="button" class="ps-tab" data-tab="tab-past">Past funds ({{ $past->count() }})</button>
                <button type="button" class="ps-tab" data-tab="tab-transactions">Transactions ({{ $transactions->count() }})</button>
                <button type="button" class="ps-tab" data-tab="tab-review">AI review</button>
                <button type="button" class="ps-tab" data-tab="tab-catalog">Fund catalog ({{ $funds->count() }})</button>
            </div>

            <div id="tab-holdings" class="ps-tabpane" hidden>
            <style>
                .pt-subrow td { background: #fafafa; color: #666; font-size: 12px; border-top: 0; }
                .pt-subrow .pt-acct { padding-left: 14px; font-variant-numeric: tabular-nums; }
            </style>
            <table class="pt-table">
                <tr><th title="First-ever investment in this account (PMO 'Initial Investment on')">First invested</th><th title="When the current position was built (after selling out and restarting)">Held since</th><th>Fund</th><th title="Bought with new money, or funded by switching out of another fund">Funded by</th><th>Invested</th><th>Current value</th><th>Gain/loss (RM)</th><th>Gain/loss (%)</th><th title="Money-weighted annual return (XIRR) from your own transaction history">Annual return</th><th>Fees paid</th></tr>
                @foreach ($portfolio as $h)
                    @php $pl = $h['value'] - $h['invested']; $x = $h['xirr']; @endphp
                    <tr>
                        <td class="pt-date" title="First-ever investment in this account (PMO 'Initial Investment on')">
                            @if ($h['started'])
                                {{ $h['started_min'] ? '≥ ' : '' }}{{ \Illuminate\Support\Carbon::parse($h['started'])->format('d M Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="pt-date" title="When the CURRENT position was built (you sold out and restarted some funds). '<' = run began before the statement archive">
                            @if ($h['run_start'])
                                {{ $h['run_exact'] ? '' : '< ' }}{{ \Illuminate\Support\Carbon::parse($h['run_start'])->format('d M Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td><a href="{{ route('details.show', $h['id']) }}">{{ $h['name'] }}</a></td>
                        <td class="pt-origin">
                            @if ($h['origin'])
                                <span title="Funded by switching out of {{ $h['origin'] }}">⇄ from {{ $h['origin'] }}</span>
                            @else
                                <span title="Bought with new money (or origin predates statement archive)">new money</span>
                            @endif
                        </td>
                        <td>{{ number_format($h['invested'], 2) }}</td>
                        <td>{{ number_format($h['value'], 2) }}</td>
                        <td class="{{ $pl >= 0 ? 'pos' : 'neg' }}">{{ $fmtRm($pl) }}</td>
                        <td class="{{ $pl >= 0 ? 'pos' : 'neg' }}">{{ ($pl >= 0 ? '+' : '') }}{{ number_format($pl / max($h['invested'], 0.01) * 100, 2) }}%</td>
                        <td>
                            @if ($x === null)
                                <span title="No statements ingested for this fund yet">—</span>
                            @elseif (! empty($x['young']))
                                <span title="Position under 90 days old — annualizing would mislead">too new</span>
                            @elseif (! $x['complete'])
                                <span title="Statement history incomplete ({{ $x['tx'] }} transactions) — XIRR would mislead">partial</span>
                            @elseif ($x['xirr'] !== null)
                                <span class="{{ $x['xirr'] >= 0 ? 'pos' : 'neg' }}"
                                      title="Money-weighted return from your {{ $x['tx'] }} transactions">{{ $x['xirr'] >= 0 ? '+' : '' }}{{ $x['xirr'] }}%</span>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $x !== null ? 'RM '.number_format($x['fees'], 2) : '—' }}</td>
                    </tr>
                    @if (! empty($h['accounts']) && count($h['accounts']) > 1)
                        @foreach ($h['accounts'] as $acct)
                            @php $apl = $acct['current_value'] - $acct['invested']; @endphp
                            <tr class="pt-subrow">
                                <td></td>
                                <td class="pt-date">
                                    @if (! empty($acct['since'])){{ \Illuminate\Support\Carbon::parse($acct['since'])->format('d M Y') }}@else —@endif
                                </td>
                                <td class="pt-acct">↳ acct {{ $acct['account_no'] ?? '—' }}</td>
                                <td></td>
                                <td>{{ number_format($acct['invested'], 2) }}</td>
                                <td>{{ number_format($acct['current_value'], 2) }}</td>
                                <td class="{{ $apl >= 0 ? 'pos' : 'neg' }}">{{ $fmtRm($apl) }}</td>
                                <td class="{{ $apl >= 0 ? 'pos' : 'neg' }}">{{ ($apl >= 0 ? '+' : '') }}{{ number_format($apl / max($acct['invested'], 0.01) * 100, 2) }}%</td>
                                <td></td>
                                <td></td>
                            </tr>
                        @endforeach
                    @endif
                @endforeach
                @php
                    $totFees = $portfolio->sum(fn ($h) => $h['xirr']['fees'] ?? 0);
                @endphp
                <tr class="pt-total">
                    <th colspan="4">Total</th>
                    <th>{{ number_format($ptInv, 2) }}</th>
                    <th>{{ number_format($ptVal, 2) }}</th>
                    <th class="{{ $ptPl >= 0 ? 'pos' : 'neg' }}">{{ $fmtRm($ptPl) }}</th>
                    <th class="{{ $ptPl >= 0 ? 'pos' : 'neg' }}">{{ ($ptPl >= 0 ? '+' : '') }}{{ number_format($ptPl / max($ptInv, 0.01) * 100, 2) }}%</th>
                    <th>—</th>
                    <th>RM {{ number_format($totFees, 2) }}</th>
                </tr>
            </table>
            <p class="ps-sub">"Original" = account's first-ever investment. "Run since" = when the current position was built — you've sold out and restarted several funds ("<" = run predates the statement archive). "Origin" ⇄ = funded by a switch from that fund. "My return /yr" = money-weighted (XIRR) from your own Statement of Transaction PDFs — download them from PMO, run <code>pmoai:ingest-stmt</code> or drop them in Downloads and tell me. "partial" = history incomplete, showing a number would mislead.</p>
            </div>

            <div id="tab-past" class="ps-tabpane" hidden>
                <table class="pt-table pt-past">
                    <tr><th>Period</th><th>Fund</th><th>Money in</th><th>Money out</th><th>Result</th></tr>
                    @foreach ($past as $p)
                        @php $res = $p['out'] - $p['in']; @endphp
                        <tr>
                            <td class="pt-date">{{ \Illuminate\Support\Carbon::parse($p['first'])->format('M Y') }} → {{ \Illuminate\Support\Carbon::parse($p['last'])->format('M Y') }}</td>
                            <td>{{ $p['name'] }} <span class="cat-code">{{ $p['code'] }}</span></td>
                            <td>{{ number_format($p['in'], 2) }}</td>
                            <td>{{ number_format($p['out'], 2) }}</td>
                            <td>
                                @if ($p['complete'])
                                    <span class="{{ $res >= 0 ? 'pos' : 'neg' }}">{{ ($res < 0 ? '−' : '+') }}RM {{ number_format(abs($res), 2) }}</span>
                                @else
                                    <span title="Statement history incomplete — some buys/sells predate the archive, so this figure would mislead">incomplete</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
                <p class="ps-sub">Funds you've fully exited, from your statements. "Result" = money out − money in, shown only when the round-trip is fully recorded.</p>
            </div>

            <div id="tab-transactions" class="ps-tabpane" hidden>
                @if ($pending->isNotEmpty())
                    <h3 style="margin:0 0 6px">⏳ Pending — submitted, not yet processed</h3>
                    <table class="pt-table">
                        <tr><th>Submitted</th><th>Fund</th><th>Type</th><th>Account</th><th>Amount</th><th>Units</th><th>→ Switch to</th></tr>
                        @foreach ($pending as $p)
                            <tr>
                                <td class="pt-date">{{ $p->submitted_at->format('d M Y H:i') }}</td>
                                <td>{{ $p->fund_name }} <span class="cat-code">{{ $p->fund_code }}</span></td>
                                <td title="{{ $p->trans_type }}">
                                    @php
                                        $pl = ['SWR' => 'Switch out', 'SWS' => 'Switch in', 'RP' => 'Redeem',
                                               'AC' => 'Add contribution', 'IC' => 'Initial contribution'][$p->trans_type] ?? $p->trans_type;
                                    @endphp {{ $pl }}
                                </td>
                                <td class="cat-code">{{ $p->account_no }}</td>
                                <td>{{ $p->amount !== null && (float) $p->amount != 0 ? 'RM '.number_format((float) $p->amount, 2) : '—' }}</td>
                                <td>{{ $p->units !== null && (float) $p->units != 0 ? number_format((float) $p->units, 2) : '—' }}</td>
                                <td>{{ $p->switch_to_fund ? $p->switch_to_fund.' ('.$p->switch_to_account.')' : '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                    <p class="ps-sub" style="margin:6px 0 18px">From PMO's Float statement — not yet priced/allocated, so excluded from holdings, P/L and XIRR. They drop off here and appear in the settled ledger below once processed.</p>
                @endif

                @if ($transactions->isEmpty())
                    <p class="ps-sub">No transactions recorded yet. Download a Statement of Transaction PDF from PMO and run <code>pmoai:ingest-stmt</code> (or drop it in Downloads and ask).</p>
                @else
                    <table class="pt-table">
                        <tr><th>Date</th><th>Fund</th><th>Type</th><th>Account</th><th>Gross</th><th>Charge</th><th>Net</th><th>Price</th><th>Units</th></tr>
                        @foreach ($transactions as $t)
                            @php $inflow = (float) $t['units'] >= 0; @endphp
                            <tr>
                                <td class="pt-date">{{ $t['date']->format('d M Y') }}</td>
                                <td>{{ $t['fund'] }} <span class="cat-code">{{ $t['fund_code'] }}</span></td>
                                <td><span class="{{ $inflow ? 'pos' : 'neg' }}" title="{{ $t['type'] }}">{{ $t['label'] }}</span></td>
                                <td class="cat-code">{{ $t['account_no'] }}</td>
                                <td>{{ $t['gross'] !== null ? number_format((float) $t['gross'], 2) : '—' }}</td>
                                <td>{{ $t['charge_amt'] !== null && (float) $t['charge_amt'] != 0 ? number_format((float) $t['charge_amt'], 2) : '—' }}</td>
                                <td class="{{ $inflow ? '' : 'neg' }}">{{ $t['net'] !== null ? number_format((float) $t['net'], 2) : '—' }}</td>
                                <td>{{ $t['price'] !== null ? number_format((float) $t['price'], 4) : '—' }}</td>
                                <td class="{{ $inflow ? 'pos' : 'neg' }}">{{ number_format((float) $t['units'], 2) }}</td>
                            </tr>
                        @endforeach
                    </table>
                    <p class="ps-sub">Your full transaction ledger from ingested Statement of Transaction PDFs, newest first. Green = units in (buy / switch-in / reinvest), red = units out (redeem / switch-out). Deduped by transaction reference, so re-ingesting a statement only adds new rows.</p>
                @endif
            </div>

            <div id="tab-review" class="ps-tabpane" hidden>
            <span id="portfolio-review"></span>
            @if ($review && $review->status === 'running')
                <p class="pt-running">Reviewing whole portfolio — researching your exposures… typical 1–3 min. Page updates itself.</p>
                <script>
                (function poll() {
                    setTimeout(function () {
                        fetch('{{ route('portfolio.reviewStatus') }}?t=' + Date.now(), { cache: 'no-store' })
                            .then(function (r) { return r.json(); })
                            .then(function (j) {
                                if (j.status !== 'running') {
                                    window.location.replace(window.location.pathname + '?t=' + Date.now() + '#portfolio-review');
                                } else { poll(); }
                            })
                            .catch(poll);
                    }, 5000);
                })();
                </script>
            @else
                @if ($review && $review->status === 'failed')
                    <p class="neg">Last review failed: {{ $review->error }}</p>
                @endif
                @if ($review && $review->status === 'done')
                    @php
                        $rvHtml = collect(preg_split('/\r?\n/', trim($review->text)))
                            ->map(function ($ln) {
                                $ln = trim($ln);
                                if ($ln === '') return '';
                                $esc = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($ln));
                                if (str_starts_with($ln, '* ') || str_starts_with($ln, '- ')) return '<li>'.preg_replace('/^(\*|-)\s+/', '', $esc).'</li>';
                                if (preg_match('/^\d+\./', $ln)) return '<li class="num">'.$esc.'</li>';
                                if (str_starts_with($ln, '**') || str_starts_with($ln, '#')) return '<p class="rv-h">'.preg_replace('/^#+\s*/', '', $esc).'</p>';
                                return '<p>'.$esc.'</p>';
                            })->implode("\n");
                    @endphp
                    <details class="rv-fold" {{ $review->updated_at->gt(now()->subDay()) ? 'open' : '' }}>
                        <summary>Memo from {{ $review->updated_at->format('Y-m-d H:i') }} — click to {{ $review->updated_at->gt(now()->subDay()) ? 'collapse' : 'expand' }}</summary>
                        <div class="pt-review-out">{!! $rvHtml !!}</div>
                        <p class="ps-sub">{{ $review->provider }} · informational only, not licensed financial advice</p>
                    </details>
                @endif
                <form method="POST" action="{{ route('portfolio.review') }}">
                    @csrf
                    <button type="submit" class="btn">{{ $review && $review->status === 'done' ? 'Re-run portfolio review' : 'Run portfolio review' }}</button>
                </form>
            @endif

            @if ($backtest->isNotEmpty())
                @php $hits = $backtest->whereNotNull('correct')->where('correct', true)->count(); $scored = $backtest->whereNotNull('correct')->count(); @endphp
                <h3 style="margin:18px 0 4px">Verdict scorecard <small style="font-weight:400;color:#888">— did past calls move the right way? {{ $hits }}/{{ $scored }} right</small></h3>
                <table class="pt-table">
                    <tr><th>Fund</th><th>Call</th><th>On</th><th>Price then → now</th><th>Since</th><th>Call worked?</th></tr>
                    @foreach ($backtest as $b)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::of($b['name'])->after('PUBLIC ')->limit(30) }}</td>
                            <td><span class="{{ $b['bull'] ? 'pos' : 'neg' }}">{{ $b['bull'] ? 'keep/buy' : 'sell/reduce' }}</span></td>
                            <td class="pt-date">{{ \Illuminate\Support\Carbon::parse($b['at'])->format('d M') }}</td>
                            <td>{{ number_format($b['then'], 4) }} → {{ number_format($b['now'], 4) }}</td>
                            <td class="{{ $b['pct'] >= 0 ? 'pos' : 'neg' }}">{{ $b['pct'] >= 0 ? '+' : '' }}{{ number_format($b['pct'], 1) }}%</td>
                            <td>
                                @if ($b['correct'] === null) <span title="Price barely moved">≈ flat</span>
                                @elseif ($b['correct']) <span class="pos">✓ right</span>
                                @else <span class="neg">✗ wrong</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
                <p class="ps-sub">A "keep/buy" call is right if the price rose since; "sell/reduce" is right if it fell. Directional only — measures whether the call read the move, not the size. Re-analyze a fund to refresh its call.</p>
            @endif
            </div>

            <div id="tab-overview" class="ps-tabpane">

        <p class="ps-eyebrow">Portfolio · data captured {{ $snapshot->updated_at->format('Y-m-d H:i') }}
            @unless (in_array($snapshot->status, ['recommended', 'failed', 'stored']))
                · <span class="ps-working">processing…</span>
            @endunless
            · <a href="{{ route('snapshots.report', $snapshot) }}">🖶 printable report</a>
        </p>

        @php
            // PMO order cut-off: 4:00 PM MYT on trading days (Mon–Fri,
            // excluding public holidays — which we can't know, so we say so).
            $myt = now('Asia/Kuala_Lumpur');
            $cut = $myt->copy()->setTime(16, 0);
            if ($myt->isWeekend()) {
                $cutMsg = 'Market closed (weekend) — orders placed now queue for Monday\'s price';
                $cutTone = 'off';
            } elseif ($myt->lt($cut)) {
                $left = $myt->diff($cut);
                $cutMsg = 'Orders before 4:00 PM today get today\'s price — '
                    .$left->h.'h '.$left->i.'m left';
                $cutTone = 'open';
            } else {
                $cutMsg = 'Past today\'s 4:00 PM cut-off — orders now get the next trading day\'s price';
                $cutTone = 'off';
            }
        @endphp
        <p class="ps-cutoff ps-cutoff-{{ $cutTone }}">🕓 {{ $cutMsg }} <small>(public holidays excluded — check the calendar)</small></p>

        @php
            $prsYr = now('Asia/Kuala_Lumpur')->year;
            // Include submitted-but-unprocessed PRS contributions so the cap
            // reflects money already committed this year, not just settled.
            $prsPend = $pending->where('scheme', 'prs')
                ->filter(fn ($p) => optional($p->submitted_at)->year === $prsYr)
                ->sum(fn ($p) => (float) $p->amount);
            $prsTotal = (float) $prsThisYear + $prsPend;
            $prsOver = $prsTotal - 3000;
            $prsTone = $prsTotal < 3000 ? 'warn' : ($prsOver > 0 ? 'off' : 'open');
        @endphp
        <p class="ps-cutoff ps-cutoff-{{ $prsTone }}">
            🏦 PRS tax relief {{ $prsYr }}: RM {{ number_format($prsTotal, 0) }} / 3,000
            @if ($prsPend > 0)<small>(incl. RM {{ number_format($prsPend, 0) }} pending)</small>@endif
            @if ($prsTotal < 3000)
                — contribute RM {{ number_format(3000 - $prsTotal, 0) }} more before 31 Dec for up to ~RM900 tax saved
            @elseif ($prsOver > 0)
                — ⚠ over the RM3,000 cap: RM {{ number_format($prsOver, 0) }} has NO tax relief this year (relief is capped at RM3,000/yr, not per fund)
            @else
                — done ✓ (RM3,000 relief maxed)
            @endif
            @if ($prsXirr !== null)
                <small>· your PRS return since 2019: {{ $prsXirr >= 0 ? '+' : '' }}{{ $prsXirr }}%/yr</small>
            @endif
        </p>

        @php
            // Concentration: single-fund weight of the whole book. >30% = one
            // fund's drawdown swings the portfolio; 25–30% = watch.
            $conc = $portfolio->map(fn ($h) => ['name' => $h['name'], 'w' => $ptVal > 0 ? $h['value'] / $ptVal * 100 : 0])
                ->sortByDesc('w')->values();
            $topW = $conc->first()['w'] ?? 0;
            $over = $conc->filter(fn ($c) => $c['w'] >= 30);
            $concTone = $topW >= 30 ? 'off' : ($topW >= 25 ? 'warn' : 'open');
            $shortN = fn ($n) => (string) \Illuminate\Support\Str::of($n)->after('PUBLIC ')->limit(20);
        @endphp
        <p class="ps-cutoff ps-cutoff-{{ $concTone }}">
            📊 Concentration:
            @if ($over->isNotEmpty())
                ⚠ {{ collect($over)->map(fn ($c) => $shortN($c['name']).' '.number_format($c['w'], 0).'%')->implode(', ') }}
                over 30% of the book — one fund's drop moves the whole portfolio
            @else
                largest position {{ $shortN($conc->first()['name'] ?? '—') }} at {{ number_format($topW, 1) }}% — within a 30% comfort band
            @endif
            <small>· top: @foreach ($conc->take(4) as $c){{ $shortN($c['name']) }} {{ number_format($c['w'], 0) }}%@unless ($loop->last) · @endunless @endforeach</small>
        </p>

        @php
            // Currency exposure — ESTIMATED from each fund's geography (held
            // e-Series/foreign funds don't carry a factsheet fx table). The RM
            // value of a foreign fund moves with its underlying currency, so
            // USD/MYR etc. swing your returns even when the fund is flat.
            $ccyOf = function ($name) {
                $n = strtoupper($name);
                if (str_contains($n, 'INDONESIA')) return 'IDR';
                if (str_contains($n, 'INDIA')) return 'INR';
                if (preg_match('/GREATER CHINA|CHINA/', $n)) return 'CNY';
                if (preg_match('/EMAS|GOLD|ARTIFICIAL|INTELLIGENCE|U\.?S\.?|AMERICA|WORLDWIDE|HEALTHCARE-GLOBAL/', $n)) return 'USD';
                if (preg_match('/CASH|MONEY MARKET|PRS|MALAYSIA|SUKUK|ISLAMIC INCOME|\bBOND\b/', $n)) return 'MYR';
                if (preg_match('/ASIA|PACIFIC|FAR-EAST|ASEAN|VIETNAM|SINGAPORE|JAPAN|KOREA/', $n)) return 'Asia (mixed)';
                return 'USD';   // foreign default
            };
            $ccy = collect($portfolio)->groupBy(fn ($h) => $ccyOf($h['name']))
                ->map(fn ($g) => $g->sum('value'))
                ->sortDesc();
            $ccyTot = $ccy->sum() ?: 1;
            $foreignPct = 100 - ($ccy['MYR'] ?? 0) / $ccyTot * 100;
        @endphp
        <p class="ps-cutoff {{ $foreignPct >= 60 ? 'ps-cutoff-warn' : 'ps-cutoff-open' }}">
            💱 Currency exposure (estimated): ~{{ number_format($foreignPct, 0) }}% of the book is in foreign currency — the ringgit moving swings your RM returns.
            <small>·
                @foreach ($ccy as $code => $val){{ $code }} {{ number_format($val / $ccyTot * 100, 0) }}%@unless ($loop->last) · @endunless @endforeach
                · USD/MYR is live on the Dashboard
            </small>
        </p>

        @if ($portfolio->isNotEmpty())
            @php
                $palette = ['#c8102e', '#1a7f5a', '#2a6fc9', '#e0a020', '#8e44ad', '#16a085', '#d35400', '#7f8c8d', '#c0392b', '#2980b9'];
                $slices = collect($portfolio)->map(fn ($h) => ['name' => $h['name'], 'val' => (float) $h['value']])->sortByDesc('val')->values();
                $allocTot = $slices->sum('val') ?: 1;
                $C = 2 * M_PI * 60;
                $acc = 0;
                $sN = fn ($n) => (string) \Illuminate\Support\Str::of($n)->after('PUBLIC ')->limit(26);
            @endphp
            <div class="alloc-wrap">
                <svg viewBox="0 0 160 160" class="alloc-donut" role="img" aria-label="Allocation by fund">
                    <g transform="rotate(-90 80 80)">
                        @foreach ($slices as $i => $s)
                            @php $frac = $s['val'] / $allocTot; $len = $frac * $C; $off = -$acc * $C; $acc += $frac; @endphp
                            <circle cx="80" cy="80" r="60" fill="none" stroke="{{ $palette[$i % count($palette)] }}"
                                stroke-width="26" stroke-dasharray="{{ round($len, 2) }} {{ round($C - $len, 2) }}"
                                stroke-dashoffset="{{ round($off, 2) }}"></circle>
                        @endforeach
                    </g>
                    <text x="80" y="77" text-anchor="middle" class="alloc-c1">{{ $slices->count() }} funds</text>
                    <text x="80" y="93" text-anchor="middle" class="alloc-c2">RM {{ number_format($allocTot, 0) }}</text>
                </svg>
                <ul class="alloc-legend">
                    @foreach ($slices as $i => $s)
                        <li><span class="alloc-dot" style="background:{{ $palette[$i % count($palette)] }}"></span>
                            {{ $sN($s['name']) }} <b>{{ number_format($s['val'] / $allocTot * 100, 1) }}%</b></li>
                    @endforeach
                </ul>
            </div>
            <style>
                .alloc-wrap { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; margin: 12px 0 4px; }
                .alloc-donut { width: 160px; height: 160px; flex: none; }
                .alloc-c1 { font-size: 11px; fill: #888; }
                .alloc-c2 { font-size: 12px; font-weight: 700; fill: #222; }
                .alloc-legend { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: 1fr 1fr; gap: 2px 18px; font-size: 13px; }
                .alloc-legend li { display: flex; align-items: center; gap: 6px; }
                .alloc-dot { width: 10px; height: 10px; border-radius: 2px; flex: none; }
                .alloc-legend b { margin-left: auto; font-variant-numeric: tabular-nums; }
            </style>
        @endif

        @php
            $exp = app(\App\Services\PortfolioExposure::class);
            $sectors = $exp->sectors();
            $overlap = array_values(array_filter($exp->stocks(), fn ($s) => $s['fund_count'] >= 2));
            $riskAdj = $exp->riskAdjusted();
        @endphp
        @if ($riskAdj)
            <p class="ps-cutoff ps-cutoff-open">
                ⚖️ Return per unit of risk (PMO volatility factor):
                @foreach ($riskAdj as $r)<b>{{ \Illuminate\Support\Str::of($r['name'])->limit(20) }}</b> {{ number_format($r['ratio'], 2) }}@unless ($loop->last) · @endunless @endforeach
                <small>· each fund's return ÷ its PMO factsheet volatility — higher = more return for the price swings (money-market excluded)</small>
            </p>
        @endif

        @php $cashPlan = app(\App\Services\CashPlanner::class)->plan(); @endphp
        @if ($cashPlan['cash'] > 1000 && $cashPlan['candidates'])
            <div class="stress-box">
                <span class="stress-h">💰 Deploying your idle e-Cash — RM {{ number_format($cashPlan['cash'], 0) }}</span>
                <p class="stress-intro">Where that cash helps most, on real PMO rules: cheaper sales charge, a buy level you've set, and room before a fund hits 30% of the book. Not advice — a ranked shortlist.</p>
                <table class="stress-tbl">
                    <tr><th>Fund</th><th class="r">Now</th><th class="r">Cost to buy</th><th class="r">Room to 30%</th><th>Flags</th></tr>
                    @foreach (array_slice($cashPlan['candidates'], 0, 5) as $c)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::of($c['name'])->limit(26) }}</td>
                            <td class="r">{{ number_format($c['weight'], 1) }}%</td>
                            <td class="r {{ $c['cost_pct'] <= 1 ? 'pos' : '' }}">{{ $c['cost_pct'] }}%</td>
                            <td class="r">{{ $c['over'] ? '—' : 'RM '.number_format($c['headroom'], 0) }}</td>
                            <td class="stress-worst">{{ $c['armed'] ? '🔔 buy level set' : '' }}{{ $c['over'] ? '⚠ over 30% — don\'t add' : '' }}{{ $c['is_bond'] ? ' · bond (safer)' : '' }}</td>
                        </tr>
                    @endforeach
                </table>
                <small class="ps-sub">Ranked cheapest + most room first; funds already over 30% (e-AI Tech) are pushed down — adding worsens concentration. Deploying into gold or a bond costs far less (1% / 0.65%) than equity (3.75%).</small>
            </div>
        @endif

        @php $stress = app(\App\Services\PortfolioStress::class)->run(); @endphp
        @if ($stress)
            <div class="stress-box">
                <span class="stress-h">🎯 Stress test — projected hit if…</span>
                <p class="stress-intro">If the market has a bad day, how much would <em>you</em> lose and which fund hurts most? A what-if, not a prediction — each fund's real PMO geography × the shock.</p>
                <table class="stress-tbl">
                    @foreach ($stress as $s)
                        <tr>
                            <td>{{ $s['label'] }}</td>
                            <td class="{{ $s['delta'] >= 0 ? 'pos' : 'neg' }}">{{ $s['delta'] >= 0 ? '+' : '−' }}RM {{ number_format(abs($s['delta']), 0) }} ({{ number_format($s['pct'], 1) }}%)</td>
                            <td class="stress-worst">@if ($s['worst'])worst: {{ \Illuminate\Support\Str::of($s['worst']['name'])->after('e-')->limit(18) }} −RM{{ number_format(abs($s['worst']['delta']), 0) }}@endif</td>
                        </tr>
                    @endforeach
                </table>
                <small class="ps-sub">Your real captured PMO geography × the shock — not a forecast. Shows where a move actually lands in your book.</small>
            </div>
            <style>
                .stress-box { border: 1px solid #eee; border-radius: 6px; padding: 8px 10px; margin: 6px 0; }
                .stress-h { font-size: 12px; font-weight: 600; color: #444; }
                .stress-intro { font-size: 11px; color: #777; margin: 3px 0 4px; line-height: 1.4; }
                .stress-tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin: 4px 0; }
                .stress-tbl td { padding: 3px 6px; border-bottom: 1px solid #f2f2f2; }
                .stress-worst { color: #999; text-align: right; }
            </style>
        @endif
        @if ($sectors)
            <p class="ps-cutoff ps-cutoff-open">
                🏭 Sector look-through:
                @foreach (array_slice($sectors, 0, 5) as $s){{ $s['sector'] }} {{ number_format($s['pct'], 0) }}%@unless ($loop->last) · @endunless @endforeach
                <small>· weighted from each fund's top sectors — Technology leads via your AI + semiconductor funds</small>
            </p>
        @endif
        @if ($overlap)
            <p class="ps-cutoff ps-cutoff-warn">
                🔍 Same stock across funds (hidden concentration):
                @foreach ($overlap as $s)<b>{{ $s['stock'] }}</b> ({{ $s['fund_count'] }} funds, ~RM{{ number_format($s['combined_value'], 0) }})@unless ($loop->last) · @endunless @endforeach
                <small>· you hold these through more than one fund — single-stock risk the per-fund weights don't show</small>
            </p>
        @endif


            <div class="ps-hero" style="margin-top:2px">
                <div class="ps-tile ps-tile-main">
                    <label>Total value</label>
                    <b>RM {{ number_format($ptVal, 2) }}</b>
                </div>
                <div class="ps-tile">
                    <label>Invested</label>
                    <b>RM {{ number_format($ptInv, 2) }}</b>
                </div>
                <div class="ps-tile">
                    <label>Unrealised P/L</label>
                    <b class="{{ $ptPl >= 0 ? 'pos' : 'neg' }}">{{ $fmtRm($ptPl) }}
                        <small>({{ $ptPl >= 0 ? '+' : '' }}{{ number_format($ptPl / max($ptInv, 0.01) * 100, 2) }}%)</small></b>
                </div>
                <div class="ps-tile">
                    <label>Funds held</label>
                    <b>{{ $portfolio->count() }}</b>
                </div>
            </div>

        <div class="ps-grid2">
            <section class="ps-card">
                <h2>Price triggers</h2>
                @if ($armed->isNotEmpty())
                    @foreach ($armed as $a)
                        @php
                            $isIdx = (bool) $a->market_symbol;
                            $dp = $isIdx ? 2 : 4;
                            $unit = $isIdx ? '' : 'RM';
                            $cur = $isIdx
                                ? \App\Models\MarketQuote::where('symbol', $a->market_symbol)->value('price')
                                : \App\Models\Fund::whereRaw('upper(code)=?', [strtoupper($a->fund_code)])->value('unit_price');
                            $lvl = rtrim(rtrim(number_format((float) $a->level, $dp), '0'), '.');
                            $curF = $cur !== null ? rtrim(rtrim(number_format((float) $cur, $dp), '0'), '.') : null;
                            $away = ($cur !== null && (float) $a->level > 0)
                                ? abs(((float) $cur - (float) $a->level) / (float) $a->level * 100) : null;
                            $fnT = $isIdx
                                ? (collect(config('quotes.indices'))->firstWhere('symbol', $a->market_symbol)['label'] ?? $a->market_symbol)
                                : (optional($funds->first(fn ($f) => strtoupper($f->code ?? '') === strtoupper($a->fund_code)))->name ?? $a->fund_code);
                            $action = $a->condition === 'below' ? ($isIdx ? 'Alert if it drops to' : 'Buy if it drops to') : 'Watch if it rises to';
                        @endphp
                        <details class="trig">
                            <summary>
                                <span class="alert-chip">{{ $fnT }}</span>
                                <span class="trig-plain">{{ $action }} {{ $unit }}{{ $lvl }}</span>
                                @if ($curF)
                                    <span class="trig-now">now {{ $unit }}{{ $curF }}@if ($away !== null) · {{ number_format($away, 1) }}% away @endif</span>
                                @endif
                            </summary>
                            @if ($a->explanation)
                                <p class="trig-exp">{{ $a->explanation }}</p>
                            @endif
                            <p class="trig-raw">signal: {{ $a->label }}</p>
                        </details>
                    @endforeach
                    <p class="ps-sub">A price level to act on. Checked after every price update; you get a notification and a banner here when reached. Click one for the reasoning.</p>
                @else
                    <p class="ps-sub">No triggers armed.</p>
                @endif
            </section>

            <section class="ps-card">
                <h2>Value over time</h2>
                @if ($history->count() >= 2)
                    @php
                        $hv = $history->pluck('value')->map(fn ($v) => (float) $v);
                        $hMin = min($hv->min(), (float) $history->min('invested'));
                        $hMax = max($hv->max(), (float) $history->max('invested'));
                        $hSpan = ($hMax - $hMin) ?: 1;
                        $hn = $history->count();
                        $hW = 700; $hH = 170; $hL = 56; $hB = 8; $hT = 10; $hR = 10;
                        $hIw = $hW - $hL - $hR; $hIh = $hH - $hT - $hB;
                        $hx = fn ($i) => $hL + ($hn === 1 ? $hIw / 2 : $i * $hIw / ($hn - 1));
                        $hy = fn ($v) => $hT + $hIh - (($v - $hMin) / $hSpan) * $hIh;
                        $hPoly = $history->values()->map(fn ($s, $i) => round($hx($i), 1).','.round($hy((float) $s->value), 1))->implode(' ');
                        $hInvPoly = $history->values()->map(fn ($s, $i) => round($hx($i), 1).','.round($hy((float) $s->invested), 1))->implode(' ');
                    @endphp
                    <svg viewBox="0 0 {{ $hW }} {{ $hH }}" class="equity-chart" role="img" aria-label="Portfolio value history">
                        <polyline fill="none" stroke="#9aa0a6" stroke-width="1" stroke-dasharray="4 3" points="{{ $hInvPoly }}" />
                        <polyline fill="none" stroke="#0e7a46" stroke-width="1.6" points="{{ $hPoly }}" />
                        @foreach ($history->values() as $i => $s)
                            <circle cx="{{ round($hx($i), 1) }}" cy="{{ round($hy((float) $s->value), 1) }}" r="2.6" fill="#0e7a46" stroke="#fff" stroke-width="1">
                                <title>{{ $s->snap_date->format('Y-m-d') }}: RM {{ number_format((float) $s->value, 0) }}</title>
                            </circle>
                        @endforeach
                        <text x="{{ $hL - 6 }}" y="{{ round($hy($hMax), 1) + 3 }}" text-anchor="end" class="eq-lbl">{{ number_format($hMax / 1000, 0) }}k</text>
                        <text x="{{ $hL - 6 }}" y="{{ round($hy($hMin), 1) + 3 }}" text-anchor="end" class="eq-lbl">{{ number_format($hMin / 1000, 0) }}k</text>
                    </svg>
                    <p class="ps-sub"><span style="color:#0e7a46">—</span> value · <span style="color:#9aa0a6">- -</span> invested</p>
                @else
                    <p class="ps-big-note">RM {{ number_format((float) ($history->first()->value ?? 0), 0) }}</p>
                    <p class="ps-sub">First point {{ $history->first()?->snap_date->format('Y-m-d') ?? '—' }} · the curve draws from your second holdings capture — one point per capture</p>
                @endif
            </section>
        </div>
            </div>

            <div id="tab-catalog" class="ps-tabpane" hidden>
    @if ($funds->isNotEmpty())

            <div class="cat-filter">
                <input type="search" id="f-name" placeholder="Search name or code…" autocomplete="off">
                <select id="f-series">
                    <option value="">All series</option>
                    <option value="public">Public</option>
                    <option value="e">Public e-Series</option>
                    <option value="pb">PB</option>
                    <option value="prs">PRS</option>
                </select>
                <select id="f-type">
                    <option value="">All types</option>
                    @foreach ($funds->pluck('fund_type')->filter()->unique()->sort() as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                    @endforeach
                </select>
                <select id="f-shariah">
                    <option value="">Shariah: all</option>
                    <option value="yes">Shariah only</option>
                    <option value="no">Conventional only</option>
                </select>
                <select id="f-idea">
                    <option value="">Screen: all</option>
                    <option value="dip">🔵 Quality, down now</option>
                    <option value="steady">🟢 Steady record</option>
                    <option value="extended">🟠 Extended (ran up)</option>
                    <option value="weak">🔴 Weak record</option>
                    <option value="held">★ My holdings</option>
                </select>
                <small id="f-count"></small>
            </div>

            <table id="catalog">
                <tr><th>#</th><th>Name</th><th>Code</th><th>Screen</th><th>Type</th><th>Shariah</th><th>Unit</th><th>YTD%</th><th>1Y%</th><th>3Y%</th><th>5Y%</th><th>10Y%</th><th>Detail</th></tr>
                @foreach ($funds as $f)
                    @php $tag = $ideas[$f->id] ?? null; @endphp
                    <tr data-name="{{ strtolower($f->name.' '.($f->code ?? '')) }}"
                        data-type="{{ $f->fund_type ?? '' }}"
                        data-series="{{ \App\Services\FundAnalysis::family($f) }}"
                        data-shariah="{{ $f->shariah ? 'yes' : 'no' }}"
                        data-idea="{{ $tag ?? '' }}">
                        <td class="cat-num">{{ $loop->iteration }}</td>
                        <td>{{ $f->name }}</td>
                        <td class="cat-code">{{ $f->code ?? '—' }}</td>
                        <td>
                            @if ($tag === 'held')<span class="idea idea-held">★ held</span>
                            @elseif ($tag === 'dip')<span class="idea idea-dip">🔵 dip</span>
                            @elseif ($tag === 'steady')<span class="idea idea-steady">🟢 steady</span>
                            @elseif ($tag === 'extended')<span class="idea idea-extended">🟠 extended</span>
                            @elseif ($tag === 'weak')<span class="idea idea-weak">🔴 weak</span>
                            @else — @endif
                        </td>
                        <td>{{ $f->fund_type ?? '—' }}</td>
                        <td>{{ $f->shariah ? 'Yes' : 'No' }}</td>
                        <td>{{ $f->unit_price ?? '—' }}</td>
                        <td>{{ $f->return_ytd ?? '—' }}</td>
                        <td>{{ $f->return_1y ?? '—' }}</td>
                        <td>{{ $f->return_3y ?? '—' }}</td>
                        <td>{{ $f->return_5y ?? '—' }}</td>
                        <td>{{ $f->return_10y ?? '—' }}</td>
                        <td>
                            @php
                                $did = ($f->code ? ($detailByCode[strtoupper($f->code)] ?? null) : null)
                                    ?? ($detailMap[\App\Models\FundDetail::normalizeName($f->name)] ?? null);
                            @endphp
                            @if ($did)
                                <a href="{{ route('details.show', $did) }}">view →</a>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
            <script>
            (function () {
                var name = document.getElementById('f-name');
                var type = document.getElementById('f-type');
                var series = document.getElementById('f-series');
                var shariah = document.getElementById('f-shariah');
                var idea = document.getElementById('f-idea');
                var count = document.getElementById('f-count');
                var rows = Array.prototype.slice.call(
                    document.querySelectorAll('#catalog tr[data-name]')
                );
                function apply() {
                    var q = name.value.trim().toLowerCase();
                    var t = type.value;
                    var se = series.value;
                    var s = shariah.value;
                    var i = idea.value;
                    var shown = 0;
                    rows.forEach(function (r) {
                        var ok = (!q || r.dataset.name.indexOf(q) !== -1)
                            && (!t || r.dataset.type === t)
                            && (!se || r.dataset.series === se)
                            && (!s || r.dataset.shariah === s)
                            && (!i || r.dataset.idea === i);
                        r.hidden = !ok;
                        if (ok) {
                            shown++;
                            var n = r.querySelector('.cat-num');
                            if (n) n.textContent = shown;   // renumber visible rows 1..N
                        }
                    });
                    count.textContent = shown === rows.length ? '' : shown + ' of ' + rows.length;
                }
                name.addEventListener('input', apply);
                type.addEventListener('change', apply);
                series.addEventListener('change', apply);
                shariah.addEventListener('change', apply);
                idea.addEventListener('change', apply);
            })();
            </script>
    @endif
            </div>

            <script>
            (function () {
                var tabs = document.querySelectorAll('.ps-tab');
                function activate(btn) {
                    tabs.forEach(function (b) {
                        b.classList.toggle('active', b === btn);
                        document.getElementById(b.dataset.tab).hidden = (b !== btn);
                    });
                }
                tabs.forEach(function (btn) {
                    btn.addEventListener('click', function () { activate(btn); });
                });
                if (window.location.hash === '#portfolio-review') {
                    activate(document.querySelector('[data-tab="tab-review"]'));
                }
            })();
            </script>
        </section>






    @endif

    @if ($snapshot->status === 'failed')
        <p class="neg">Capture failed. Check <code>storage/logs/laravel.log</code>.</p>
    @endif



    @if ($snapshot->recommendations->isNotEmpty() || $snapshot->feedback->isNotEmpty())
        <details class="ps-fold">
            <summary>Older screener output &amp; your notes</summary>
            @if ($snapshot->feedback->isNotEmpty())
                <p><strong>Your input:</strong> {{ $snapshot->feedback->first()->text }}</p>
            @endif
            @if ($snapshot->recommendations->isNotEmpty())
                <table>
                    <tr><th>Fund</th><th>Action</th><th>Target %</th><th>Rationale</th></tr>
                    @foreach ($snapshot->recommendations as $r)
                        <tr>
                            <td>{{ $r->fund_name }}</td>
                            <td class="{{ $r->action }}">{{ strtoupper($r->action) }}</td>
                            <td>{{ $r->target_weight !== null ? $r->target_weight.'%' : '—' }}</td>
                            <td>{{ $r->rationale }}</td>
                        </tr>
                    @endforeach
                </table>
                <p><small>Model: {{ $snapshot->recommendations->first()->model }}.</small></p>
            @endif
        </details>
    @endif

    <p class="ps-footer"><a href="{{ route('snapshots.index') }}">← All analyses</a>
       &nbsp;·&nbsp; <a href="{{ route('snapshots.create') }}">+ New (manual)</a></p>
@endsection
