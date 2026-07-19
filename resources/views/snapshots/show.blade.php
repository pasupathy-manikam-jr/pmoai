@extends('layouts.app')

@section('title', "pmoai — portfolio")
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
        <div class="alert-fired">🔔 <b>{{ $a->fund_code }}</b> — {{ $a->label }}
            <small>(hit {{ number_format((float) $a->fired_price, 4) }} on {{ $a->fired_at->format('Y-m-d') }})</small></div>
    @endforeach

    @if ($portfolio->isNotEmpty())
        <section class="ps-card">
            <div class="ps-tabs" role="tablist">
                <button type="button" class="ps-tab active" data-tab="tab-overview">Overview</button>
                <button type="button" class="ps-tab" data-tab="tab-holdings">My holdings ({{ $portfolio->count() }})</button>
                <button type="button" class="ps-tab" data-tab="tab-past">Past funds ({{ $past->count() }})</button>
                <button type="button" class="ps-tab" data-tab="tab-review">AI review</button>
                <button type="button" class="ps-tab" data-tab="tab-catalog">Fund catalog ({{ $funds->count() }})</button>
            </div>

            <div id="tab-holdings" class="ps-tabpane" hidden>
            <table class="pt-table">
                <tr><th>Original</th><th>Run since</th><th>Fund</th><th>Origin</th><th>Invested</th><th>Current value</th><th>P/L (RM)</th><th>P/L (%)</th><th>My return /yr</th><th>Fees paid</th></tr>
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
            </div>

            <div id="tab-overview" class="ps-tabpane">

        <p class="ps-eyebrow">Portfolio · data captured {{ $snapshot->updated_at->format('Y-m-d H:i') }}
            @unless (in_array($snapshot->status, ['recommended', 'failed', 'stored']))
                · <span class="ps-working">processing…</span>
            @endunless
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

        @php $prsYr = now('Asia/Kuala_Lumpur')->year; @endphp
        <p class="ps-cutoff {{ $prsThisYear >= 3000 ? 'ps-cutoff-open' : 'ps-cutoff-warn' }}">
            🏦 PRS tax relief {{ $prsYr }}: RM {{ number_format($prsThisYear, 0) }} / 3,000
            {{ $prsThisYear >= 3000 ? '— done ✓' : '— contribute before 31 Dec for up to ~RM900 tax saved' }}
            @if ($prsXirr !== null)
                <small>· your PRS money-weighted return since 2019: {{ $prsXirr >= 0 ? '+' : '' }}{{ $prsXirr }}%/yr</small>
            @endif
        </p>


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
                            $cur = \App\Models\Fund::whereRaw('upper(code)=?', [strtoupper($a->fund_code)])->value('unit_price');
                            $lvl = rtrim(rtrim(number_format((float) $a->level, 4), '0'), '.');
                            $curF = $cur !== null ? rtrim(rtrim(number_format((float) $cur, 4), '0'), '.') : null;
                            $away = ($cur !== null && (float) $a->level > 0)
                                ? abs(((float) $cur - (float) $a->level) / (float) $a->level * 100) : null;
                        @endphp
                        <details class="trig">
                            <summary>
                                <span class="alert-chip">{{ $a->fund_code }} {{ $a->condition === 'below' ? '≤' : '≥' }} {{ $lvl }}</span>
                                <span class="trig-label">{{ $a->label }}</span>
                                @if ($curF)
                                    <span class="trig-now">now {{ $curF }}@if ($away !== null) · {{ number_format($away, 1) }}% away @endif</span>
                                @endif
                            </summary>
                            @if ($a->explanation)
                                <p class="trig-exp">{{ $a->explanation }}</p>
                            @endif
                        </details>
                    @endforeach
                    <p class="ps-sub">Checked after every price capture · Mac notification + banner here when hit · click a trigger for the why</p>
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
                    <option value="dip">🔵 Buy the dip (quality, down now)</option>
                    <option value="steady">🟢 Buy candidates (steady)</option>
                    <option value="extended">🟠 Extended (ran up)</option>
                    <option value="weak">🔴 Weak record</option>
                    <option value="held">★ My holdings</option>
                </select>
                <small id="f-count"></small>
            </div>

            <table id="catalog">
                <tr><th>Name</th><th>Code</th><th>Screen</th><th>Type</th><th>Shariah</th><th>Unit</th><th>YTD%</th><th>1Y%</th><th>3Y%</th><th>5Y%</th><th>10Y%</th><th>Detail</th></tr>
                @foreach ($funds as $f)
                    @php $tag = $ideas[$f->id] ?? null; @endphp
                    <tr data-name="{{ strtolower($f->name.' '.($f->code ?? '')) }}"
                        data-type="{{ $f->fund_type ?? '' }}"
                        data-shariah="{{ $f->shariah ? 'yes' : 'no' }}"
                        data-idea="{{ $tag ?? '' }}">
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
                var shariah = document.getElementById('f-shariah');
                var idea = document.getElementById('f-idea');
                var count = document.getElementById('f-count');
                var rows = Array.prototype.slice.call(
                    document.querySelectorAll('#catalog tr[data-name]')
                );
                function apply() {
                    var q = name.value.trim().toLowerCase();
                    var t = type.value;
                    var s = shariah.value;
                    var i = idea.value;
                    var shown = 0;
                    rows.forEach(function (r) {
                        var ok = (!q || r.dataset.name.indexOf(q) !== -1)
                            && (!t || r.dataset.type === t)
                            && (!s || r.dataset.shariah === s)
                            && (!i || r.dataset.idea === i);
                        r.hidden = !ok;
                        if (ok) shown++;
                    });
                    count.textContent = shown === rows.length ? '' : shown + ' of ' + rows.length;
                }
                name.addEventListener('input', apply);
                type.addEventListener('change', apply);
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
