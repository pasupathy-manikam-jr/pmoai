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



    @if ($fired->isNotEmpty())
        <details class="alerts-fold" open>
            <summary>🔔 {{ $fired->count() }} price alert{{ $fired->count() === 1 ? '' : 's' }} fired — click to show / hide</summary>
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
        </details>
    @endif
    <style>
        .alerts-fold { margin: 0 0 14px; border: 1px solid var(--line, #e5e5e5); border-radius: 10px; overflow: hidden; }
        .alerts-fold > summary { padding: 10px 14px; cursor: pointer; font-weight: 600; font-size: 13px; background: #fff7f2; color: #c8102e; list-style: none; }
        .alerts-fold > summary::-webkit-details-marker { display: none; }
        .alerts-fold > summary::before { content: '▸ '; }
        .alerts-fold[open] > summary::before { content: '▾ '; }
        .alerts-fold .alert-fired { margin: 0; border-radius: 0; border: 0; border-top: 1px solid #f2e6e0; }
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
                            <td>{{ \Illuminate\Support\Str::of($b['name'])->after('PUBLIC ') }}</td>
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

        @php $rc = $reconcile; @endphp
        <details class="stress-box" {{ $rc['tone'] !== 'open' ? 'open' : '' }}>
            <summary class="stress-h">🧮 Does this add up? — data check
                @if ($rc['tone'] === 'off')<span class="neg">⚠ check this</span>
                @elseif ($rc['tone'] === 'warn')<span style="color:#8a6a00">• heads up</span>
                @else<span class="pos">✓ looks fine</span>@endif
            </summary>
            <p class="stress-intro">A quick sanity check that nothing quietly went wrong — the total still matches your last capture, and the numbers aren't old.</p>

            @if ($rc['drift_flag'])
                <p class="stress-intro" style="color:#bb2018">
                    <b>Your total dropped RM {{ number_format(abs($rc['delta']), 0) }} ({{ number_format($rc['delta_pct'], 1) }}%)</b>
                    since {{ $rc['prev_date']?->format('d M') }} and no sell explains it. An older statement may have
                    overwritten newer values — please re-check before acting.
                </p>
            @endif

            <table class="stress-tbl">
                <tr><td>Total now</td><td class="r">RM {{ number_format($rc['current_total'], 0) }}</td></tr>
                @if ($rc['prev_total'] !== null)
                    <tr><td>Last recorded ({{ $rc['prev_date']?->format('d M') }})</td><td class="r">RM {{ number_format($rc['prev_total'], 0) }}</td></tr>
                    <tr>
                        <td>Change since then</td>
                        <td class="r {{ $rc['delta'] >= 0 ? 'pos' : 'neg' }}">
                            {{ $rc['delta'] >= 0 ? '+' : '−' }}RM {{ number_format(abs($rc['delta']), 0) }}
                            ({{ $rc['delta_pct'] >= 0 ? '+' : '' }}{{ number_format($rc['delta_pct'], 1) }}%)
                        </td>
                    </tr>
                    @if ($rc['redeemed'] > 0)
                        <tr><td>Money you took out since then</td><td class="r">RM {{ number_format($rc['redeemed'], 0) }} <span class="stress-worst">(explains part of the drop)</span></td></tr>
                    @endif
                @endif
                <tr>
                    <td>Holdings last captured</td>
                    <td class="r {{ $rc['holdings_stale'] ? 'neg' : '' }}">
                        {{ $rc['holdings_age'] === null ? '—' : ($rc['holdings_age'] === 0 ? 'today' : $rc['holdings_age'].' day'.($rc['holdings_age'] === 1 ? '' : 's').' ago') }}
                        @if ($rc['holdings_stale']) — getting old @endif
                    </td>
                </tr>
                <tr>
                    <td>Fund prices up to date</td>
                    <td class="r {{ $rc['stale_prices']->isNotEmpty() ? 'neg' : 'pos' }}">
                        {{ $rc['held_count'] - $rc['stale_prices']->count() }} / {{ $rc['held_count'] }} fresh
                    </td>
                </tr>
            </table>

            @if ($rc['stale_prices']->isNotEmpty())
                <small class="ps-sub">Older than {{ $rc['price_stale_days'] }} days — recapture these funds' prices:
                    {{ $rc['stale_prices']->map(fn ($s) => \Illuminate\Support\Str::of($s['name'])->after('PUBLIC ').($s['last'] ? ' ('.$s['last']->format('d M').')' : ' (none)'))->implode(', ') }}.
                </small>
            @endif
        </details>

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
        <details class="stress-box">
            <summary class="stress-h">🕓 Order cut-off</summary>
            <p class="stress-intro" style="margin:2px 0 0"><span class="{{ $cutTone === 'open' ? 'pos' : ($cutTone === 'warn' ? '' : 'neg') }}">{{ $cutMsg }}</span> — public holidays excluded, check the calendar.</p>
        </details>

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
        <details class="stress-box">
            <summary class="stress-h">🏦 PRS tax relief {{ $prsYr }}</summary>
            <table class="stress-tbl">
                <tr><td>Contributed this year</td><td class="r">RM {{ number_format($prsTotal, 0) }}@if ($prsPend > 0) <span class="stress-worst">(incl. RM{{ number_format($prsPend, 0) }} pending)</span>@endif</td></tr>
                <tr><td>Tax-relief cap</td><td class="r">RM 3,000 / year</td></tr>
                @if ($prsOver > 0)
                    <tr><td>Over the cap</td><td class="r neg">RM {{ number_format($prsOver, 0) }} — no tax relief</td></tr>
                @elseif ($prsTotal < 3000)
                    <tr><td>Room left</td><td class="r">RM {{ number_format(3000 - $prsTotal, 0) }} before 31 Dec (~RM900 tax saved)</td></tr>
                @else
                    <tr><td>Status</td><td class="r pos">relief maxed ✓</td></tr>
                @endif
                @if ($prsXirr !== null)
                    <tr><td>Your PRS return since 2019</td><td class="r {{ $prsXirr >= 0 ? 'pos' : 'neg' }}">{{ $prsXirr >= 0 ? '+' : '' }}{{ $prsXirr }}%/yr</td></tr>
                @endif
            </table>
            @if ($prsOver > 0)<small class="ps-sub">Relief is capped per person per year (RM3,000), NOT per fund — the excess has no tax benefit this year.</small>@endif
        </details>

        @if ($prsHistory->isNotEmpty())
            <details class="stress-box">
                <summary class="stress-h">🏦 PRS contribution history
                    @if ($prsTotals['wasted'] > 0)<span style="color:#8a6a00">• RM{{ number_format($prsTotals['wasted'], 0) }} over cap</span>@endif
                </summary>
                <p class="stress-intro">Every PRS contribution you've made, year by year. Relief is capped at RM3,000 per year — a year above that wastes the excess (no tax benefit on it).</p>
                <table class="stress-tbl">
                    <tr><th>Year</th><th class="r">Contributed</th><th class="r">Relief claimed</th><th>Status</th></tr>
                    @foreach ($prsHistory as $h)
                        <tr>
                            <td>{{ $h['year'] }}</td>
                            <td class="r">RM {{ number_format($h['amount'], 0) }}</td>
                            <td class="r">RM {{ number_format($h['relief'], 0) }}</td>
                            <td>
                                @if ($h['wasted'] > 0)
                                    <span class="neg">RM{{ number_format($h['wasted'], 0) }} wasted ⚠</span>
                                @elseif ($h['maxed'])
                                    <span class="pos">maxed ✓</span>
                                @else
                                    <span style="color:#8a6a00">under cap</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr style="border-top:2px solid #ddd">
                        <td><b>Total ({{ $prsTotals['years'] }} yrs)</b></td>
                        <td class="r"><b>RM {{ number_format($prsTotals['contributed'], 0) }}</b></td>
                        <td class="r"><b>RM {{ number_format($prsTotals['relief'], 0) }}</b></td>
                        <td>@if ($prsXirr !== null)<span class="{{ $prsXirr >= 0 ? 'pos' : 'neg' }}">{{ $prsXirr >= 0 ? '+' : '' }}{{ $prsXirr }}%/yr</span>@endif</td>
                    </tr>
                </table>
                <small class="ps-sub">Estimated tax saved ≈ RM {{ number_format($prsTotals['relief'] * 0.3, 0) }} lifetime (relief × ~30% top bracket — your actual saving depends on your rate).
                    @if ($prsTotals['wasted'] > 0) RM{{ number_format($prsTotals['wasted'], 0) }} was contributed above the yearly cap and earned no relief.@endif
                </small>
            </details>
        @endif

        @php
            // Concentration: single-fund weight of the whole book. >30% = one
            // fund's drawdown swings the portfolio; 25–30% = watch.
            $conc = $portfolio->map(fn ($h) => ['name' => $h['name'], 'w' => $ptVal > 0 ? $h['value'] / $ptVal * 100 : 0])
                ->sortByDesc('w')->values();
            $topW = $conc->first()['w'] ?? 0;
            $over = $conc->filter(fn ($c) => $c['w'] >= 30);
            $concTone = $topW >= 30 ? 'off' : ($topW >= 25 ? 'warn' : 'open');
            $shortN = fn ($n) => (string) \Illuminate\Support\Str::of($n)->after('PUBLIC ');
        @endphp
        <details class="stress-box">
            <summary class="stress-h">📊 Concentration @if ($over->isNotEmpty())<span class="neg">⚠ over 30%</span>@endif</summary>
            <p class="stress-intro">Single-fund weight of the whole book. Over 30% means one fund's drop swings the whole portfolio.</p>
            <table class="stress-tbl">
                <tr><th>Fund</th><th class="r">Weight</th></tr>
                @foreach ($conc->take(6) as $c)
                    <tr>
                        <td>{{ $shortN($c['name']) }}</td>
                        <td class="r {{ $c['w'] >= 30 ? 'neg' : ($c['w'] >= 25 ? '' : '') }}">{{ number_format($c['w'], 1) }}%@if ($c['w'] >= 30) ⚠@endif</td>
                    </tr>
                @endforeach
            </table>
        </details>

        @php
            // Currency exposure — REAL, built from each fund's captured
            // Geographical Breakdown (country % × fund value → currency),
            // gold counted as USD, the unlisted remainder as MYR. The RM value
            // of a foreign holding moves with its underlying currency, so
            // USD/MYR etc. swing your returns even when the fund is flat.
            $ccyx = app(\App\Services\PortfolioExposure::class)->currencies();
        @endphp
        <details class="stress-box">
            <summary class="stress-h">💱 Currency exposure</summary>
            <p class="stress-intro">~{{ number_format($ccyx['foreign_pct'], 0) }}% of the book is in foreign currency — the ringgit moving swings your RM returns even when funds are flat. USD/MYR is live on the Dashboard. Built from each fund's real captured country breakdown (gold = USD; unlisted portion = MYR).</p>
            <table class="stress-tbl">
                <tr><th>Currency</th><th class="r">% of book</th><th class="r">Value</th></tr>
                @foreach ($ccyx['rows'] as $r)
                    <tr>
                        <td>{{ $r['ccy'] }}</td>
                        <td class="r">{{ number_format($r['pct'], 1) }}%</td>
                        <td class="r">RM {{ number_format($r['rm'], 0) }}</td>
                    </tr>
                @endforeach
            </table>
        </details>

        @if ($attribution['tx_count'] > 0)
            @php $feeTot = $attribution['sales_charge'] + $attribution['sst']; @endphp
            <details class="stress-box">
                <summary class="stress-h">🧾 Cost &amp; return attribution</summary>
                <p class="stress-intro">Where your money stands and what it cost. Fees are the real sales charges + SST from every transaction — the cumulative price of your buying and switching.</p>
                <table class="stress-tbl">
                    <tr><td>Invested (cost basis)</td><td class="r">RM {{ number_format($ptInv, 0) }}</td></tr>
                    <tr><td>Current value</td><td class="r">RM {{ number_format($ptVal, 0) }}</td></tr>
                    <tr><td>Paper gain / loss</td><td class="r {{ $ptPl >= 0 ? 'pos' : 'neg' }}">{{ $ptPl >= 0 ? '+' : '−' }}RM {{ number_format(abs($ptPl), 0) }} ({{ number_format($ptPl / max($ptInv, 1) * 100, 1) }}%)</td></tr>
                    <tr><td>Sales charges paid to PMO</td><td class="r neg">RM {{ number_format($attribution['sales_charge'], 2) }}</td></tr>
                    <tr><td>SST paid</td><td class="r neg">RM {{ number_format($attribution['sst'], 2) }}</td></tr>
                    <tr><td><b>Total fees to PMO (all-time)</b></td><td class="r neg"><b>RM {{ number_format($feeTot, 2) }}</b> <span class="stress-worst">({{ number_format($feeTot / max($ptVal, 1) * 100, 2) }}% of book)</span></td></tr>
                </table>
                <small class="ps-sub">{{ $attribution['charged_tx'] }} of {{ $attribution['tx_count'] }} transactions carried a charge. Same-series switches after 90 days are free — these fees came from cash→equity buys, cross-series moves and early switches.</small>
            </details>
        @endif

        {{-- Allocation pie moved below "Value over time" (more room there) --}}

        @php
            $exp = app(\App\Services\PortfolioExposure::class);
            $sectors = $exp->sectors();
            $overlap = array_values(array_filter($exp->stocks(), fn ($s) => $s['fund_count'] >= 2));
            $riskAdj = $exp->riskAdjusted();
            $benchmarks = $exp->benchmarks();
        @endphp
        @if ($benchmarks)
            <details class="stress-box">
                <summary class="stress-h">📈 Each fund vs its own PMO benchmark</summary>
                <p class="stress-intro">The fund's own annualised return minus its Public Mutual benchmark, straight from the captured factsheet. Green beat its benchmark; red lagged — a red fund means a plain index of the same market would have done better.</p>
                <table class="stress-tbl">
                    <tr><th>Fund</th><th class="r">Period</th><th class="r">Fund</th><th class="r">Benchmark</th><th class="r">Difference</th><th>Verdict</th></tr>
                    @foreach ($benchmarks as $b)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::of($b['name']) }}</td>
                            <td class="r">{{ $b['period'] }}</td>
                            <td class="r">{{ number_format($b['fund'], 2) }}%</td>
                            <td class="r">{{ number_format($b['bench'], 2) }}%</td>
                            <td class="r {{ $b['beat'] ? 'pos' : 'neg' }}">{{ $b['diff'] >= 0 ? '+' : '' }}{{ number_format($b['diff'], 2) }}%</td>
                            <td class="{{ $b['beat'] ? 'pos' : 'neg' }}">{{ $b['beat'] ? '✓ beats' : '✗ lags' }}</td>
                        </tr>
                    @endforeach
                </table>
            </details>
        @endif
        @if ($riskAdj)
            <details class="stress-box">
                <summary class="stress-h">⚖️ Return per unit of risk</summary>
                <p class="stress-intro">Each fund's return ÷ its Public Mutual factsheet volatility factor — higher means more return for the price swings you stomach. Money-market excluded (its near-zero volatility distorts the ratio).</p>
                <table class="stress-tbl">
                    <tr><th>Fund</th><th class="r">Return</th><th class="r">Volatility</th><th class="r">Return / risk</th></tr>
                    @foreach ($riskAdj as $r)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::of($r['name']) }}</td>
                            <td class="r {{ $r['return'] >= 0 ? 'pos' : 'neg' }}">{{ number_format($r['return'], 2) }}%</td>
                            <td class="r">{{ number_format($r['vol'], 1) }}</td>
                            <td class="r {{ $r['ratio'] >= 0 ? 'pos' : 'neg' }}"><b>{{ number_format($r['ratio'], 2) }}</b></td>
                        </tr>
                    @endforeach
                </table>
            </details>
        @endif

        @php $cashPlan = app(\App\Services\CashPlanner::class)->plan(); @endphp
        @if ($cashPlan['cash'] > 1000 && $cashPlan['candidates'])
            <details class="stress-box">
                <summary class="stress-h">💰 Deploying your idle e-Cash — RM {{ number_format($cashPlan['cash'], 0) }}</summary>
                <p class="stress-intro">Where that cash helps most, on real PMO rules: cheaper sales charge, a buy level you've set, and room before a fund hits 30% of the book. Not advice — a ranked shortlist.</p>
                <table class="stress-tbl">
                    <tr><th>Fund</th><th class="r">Now</th><th class="r">Cost to buy</th><th class="r">Room to 30%</th><th>Flags</th></tr>
                    @foreach (array_slice($cashPlan['candidates'], 0, 5) as $c)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::of($c['name']) }}</td>
                            <td class="r">{{ number_format($c['weight'], 1) }}%</td>
                            <td class="r {{ $c['cost_pct'] <= 1 ? 'pos' : '' }}">{{ $c['cost_pct'] }}%</td>
                            <td class="r">{{ $c['over'] ? '—' : 'RM '.number_format($c['headroom'], 0) }}</td>
                            <td class="stress-worst">{{ $c['armed'] ? '🔔 buy level set' : '' }}{{ $c['over'] ? '⚠ over 30% — don\'t add' : '' }}{{ $c['is_bond'] ? ' · bond (safer)' : '' }}</td>
                        </tr>
                    @endforeach
                </table>
                <small class="ps-sub">Ranked cheapest + most room first; funds already over 30% (e-AI Tech) are pushed down — adding worsens concentration. Deploying into gold or a bond costs far less (1% / 0.65%) than equity (3.75%).</small>
            </details>
        @endif

        @php $stress = app(\App\Services\PortfolioStress::class)->run(); @endphp
        @if ($stress)
            <details class="stress-box">
                <summary class="stress-h">🎯 Stress test — projected hit if…</summary>
                <p class="stress-intro">If the market has a bad day, how much would <em>you</em> lose and which fund hurts most? A what-if, not a prediction — each fund's real PMO geography × the shock.</p>
                <table class="stress-tbl">
                    @foreach ($stress as $s)
                        <tr>
                            <td>{{ $s['label'] }}</td>
                            <td class="{{ $s['delta'] >= 0 ? 'pos' : 'neg' }}">{{ $s['delta'] >= 0 ? '+' : '−' }}RM {{ number_format(abs($s['delta']), 0) }} ({{ number_format($s['pct'], 1) }}%)</td>
                            <td class="stress-worst">@if ($s['worst'])worst: {{ \Illuminate\Support\Str::of($s['worst']['name'])->after('e-') }} −RM{{ number_format(abs($s['worst']['delta']), 0) }}@endif</td>
                        </tr>
                    @endforeach
                </table>
                <small class="ps-sub">Your real captured PMO geography × the shock — not a forecast. Shows where a move actually lands in your book.</small>
            </details>
            <style>
                .stress-box { border: 1px solid #eee; border-radius: 6px; padding: 8px 10px; margin: 6px 0; }
                details.stress-box > summary.stress-h { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 6px; user-select: none; }
                details.stress-box > summary.stress-h::-webkit-details-marker { display: none; }
                details.stress-box > summary.stress-h::before { content: '▸'; font-size: 10px; color: #aaa; transition: transform .12s ease; }
                details.stress-box[open] > summary.stress-h::before { transform: rotate(90deg); }
                details.stress-box > summary.stress-h:hover { color: #c8102e; }
                .stress-h { font-size: 12px; font-weight: 600; color: #444; }
                .stress-intro { font-size: 11px; color: #777; margin: 3px 0 4px; line-height: 1.4; }
                .stress-tbl { width: 100%; border-collapse: collapse; font-size: 12px; margin: 4px 0; }
                .stress-tbl td { padding: 3px 6px; border-bottom: 1px solid #f2f2f2; }
                .stress-worst { color: #999; text-align: right; }
            </style>
        @endif
        @if ($sectors)
            <details class="stress-box">
                <summary class="stress-h">🏭 Sector look-through</summary>
                <p class="stress-intro">Weighted from each fund's captured top-5 sectors — where the whole book leans. Technology dominates via your AI + semiconductor funds. (% of total book; captured sectors don't sum to 100%.)</p>
                <table class="stress-tbl">
                    <tr><th>Sector</th><th class="r">% of book</th><th class="r">Value</th></tr>
                    @foreach (array_slice($sectors, 0, 6) as $s)
                        <tr>
                            <td>{{ $s['sector'] }}</td>
                            <td class="r">{{ number_format($s['pct'], 1) }}%</td>
                            <td class="r">RM {{ number_format($s['rm'], 0) }}</td>
                        </tr>
                    @endforeach
                </table>
            </details>
        @endif
        @if ($overlap)
            <details class="stress-box">
                <summary class="stress-h">🔍 Same stock across funds — hidden concentration</summary>
                <p class="stress-intro">A stock you hold through more than one fund. The fund-level weights don't show this — a single-stock shock hits several of your funds at once.</p>
                <table class="stress-tbl">
                    <tr><th>Stock</th><th class="r">In funds</th><th class="r">Combined fund value</th><th>Which funds</th></tr>
                    @foreach ($overlap as $s)
                        <tr>
                            <td>{{ $s['stock'] }}</td>
                            <td class="r">{{ $s['fund_count'] }}</td>
                            <td class="r">~RM {{ number_format($s['combined_value'], 0) }}</td>
                            <td class="stress-worst">{{ implode(', ', array_map(fn ($f) => \Illuminate\Support\Str::of($f)->limit(22), $s['funds'])) }}</td>
                        </tr>
                    @endforeach
                </table>
            </details>
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

                @if ($portfolio->isNotEmpty())
                    @php
                        $palette = ['#c8102e', '#1a7f5a', '#2a6fc9', '#e0a020', '#8e44ad', '#16a085', '#d35400', '#7f8c8d', '#c0392b', '#2980b9'];
                        $slices = collect($portfolio)->map(fn ($h) => ['name' => $h['name'], 'val' => (float) $h['value']])->sortByDesc('val')->values();
                        $allocTot = $slices->sum('val') ?: 1;
                        $C = 2 * M_PI * 60;
                        $acc = 0;
                        $sN = fn ($n) => (string) \Illuminate\Support\Str::of($n)->after('PUBLIC ');
                    @endphp
                    <h2 style="margin-top:18px">Allocation by fund</h2>
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
