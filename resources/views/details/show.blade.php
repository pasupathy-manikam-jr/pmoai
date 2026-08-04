@extends('layouts.app')

@section('title', "PMFAI — {$detail->name}")
@section('body-class', 'page-detail')

@push('head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@700;800&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">
    @if ((($detail->payload['ai_status'] ?? null) === 'running') || (($detail->payload['chat_status'] ?? null) === 'running'))
        <script>
            // Poll job status in place; reload exactly once when it's done.
            document.addEventListener('DOMContentLoaded', function () {
                var t0 = Date.now();
                var el = document.querySelector('.fd-running');
                var stages = [
                    [0,  'computing signals'],
                    [12, 'researching the market'],
                    [55, 'writing the verdict'],
                ];
                function tick() {
                    if (!el) return;
                    var s = Math.floor((Date.now() - t0) / 1000);
                    var label = stages[0][1];
                    for (var i = 0; i < stages.length; i++) {
                        if (s >= stages[i][0]) label = stages[i][1];
                    }
                    el.textContent = 'Analyzing — ' + label + '… ' + s + 's (typical 1–2 min)';
                }
                tick();
                setInterval(tick, 1000);

                var anchor = '{{ ($detail->payload['chat_status'] ?? null) === 'running' ? '#ai-chat' : '#ai-analysis' }}';
                function poll() {
                    fetch('{{ route('details.status', $detail) }}?t=' + Date.now(), { cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (j.status !== 'running' && j.chat !== 'running') {
                                window.location.replace(
                                    window.location.pathname + '?t=' + Date.now() + anchor
                                );
                            } else {
                                setTimeout(poll, 4000);
                            }
                        })
                        .catch(function () { setTimeout(poll, 6000); });
                }
                setTimeout(poll, 4000);
            });
        </script>
    @endif
@endpush

@section('content')
    @php
        $p = $detail->payload ?? [];

        // --- markdown-lite → HTML (shared by analysis + chat bubbles)
        $mdLite = function (string $text): string {
            $lines = preg_split('/\r?\n/', trim($text));
            $out = [];
            $inList = false;
            foreach ($lines as $ln) {
                $ln = trim($ln);
                if ($ln === '') {
                    if ($inList) { $out[] = '</ul>'; $inList = false; }
                    continue;
                }
                $esc = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', e($ln));
                if (str_starts_with($ln, '* ') || str_starts_with($ln, '- ')) {
                    if (! $inList) { $out[] = '<ul>'; $inList = true; }
                    $out[] = '<li>'.preg_replace('/^(\*|-)\s+/', '', $esc).'</li>';
                } elseif (str_starts_with($ln, '#')) {
                    if ($inList) { $out[] = '</ul>'; $inList = false; }
                    $out[] = '<p class="ai-h"><strong>'.trim(preg_replace('/^#+\s*/', '', $esc)).'</strong></p>';
                } else {
                    if ($inList) { $out[] = '</ul>'; $inList = false; }
                    $out[] = str_starts_with($ln, '**')
                        ? '<p class="ai-h">'.$esc.'</p>'
                        : '<p>'.$esc.'</p>';
                }
            }
            if ($inList) { $out[] = '</ul>'; }
            return implode("\n", $out);
        };

        $aiHtml = null;
        $verdict = null;
        if (! empty($p['ai']['text'])) {
            if (preg_match('/Verdict[^:]*:\s*\**\s*(KEEP|SELL|REDUCE|BUY|WAIT|AVOID)\b/i', $p['ai']['text'], $vm)) {
                $verdict = strtoupper($vm[1]);
            }
            $aiHtml = $mdLite($p['ai']['text']);
        }

        $fmtRet = fn ($v) => $v === null ? null : ((float) $v >= 0 ? '+' : '').number_format((float) $v, 2);
    @endphp

    <p class="fd-crumb"><a href="{{ $snapshot ? route('snapshots.show', $snapshot) : route('snapshots.index') }}">← all funds</a></p>

    <header class="fd-head">
        <div class="fd-chips">
            @if ($fund?->category)<span class="chip">{{ $fund->category }}</span>@endif
            @if ($fund?->fund_type)<span class="chip">{{ $fund->fund_type }}</span>@endif
            @if ($fund?->risk)<span class="chip chip-risk">{{ $fund->risk }} risk</span>@endif
            @if ($fund?->shariah)<span class="chip chip-shariah">Shariah</span>@endif
        </div>
        <h1>{{ $detail->name }}</h1>
        <p class="fd-meta">
            {{ $detail->code ? $detail->code : '—' }}
            · captured {{ $detail->captured_at?->format('Y-m-d H:i') ?? 'never (catalog stub)' }}
        </p>

        @if ($fund)
            <div class="fd-ticker">
                @if ($fund->unit_price !== null)
                    <span class="tick"><label>NAV RM</label><b>{{ number_format((float) $fund->unit_price, 4) }}</b></span>
                @endif
                @foreach (['YTD' => $fund->return_ytd, '1Y' => $fund->return_1y, '3Y' => $fund->return_3y, '5Y' => $fund->return_5y, '10Y' => $fund->return_10y] as $k => $v)
                    @if ($v !== null)
                        <span class="tick"><label>{{ $k }}%</label><b class="{{ (float) $v >= 0 ? 'pos' : 'neg' }}">{{ $fmtRet($v) }}</b></span>
                    @endif
                @endforeach
            </div>
        @endif
    </header>

    <div id="ai-analysis" class="fd-ai {{ $verdict ? 'has-verdict v-'.strtolower($verdict) : '' }}">
        <div class="fd-ai-top">
            <div class="fd-ai-body">
                <p class="fd-eyebrow">AI analysis</p>
                @if (session('ai_error'))
                    <p class="neg">{{ session('ai_error') }}</p>
                @endif
                @if (! empty($p['ai_error']))
                    <p class="neg">Last run failed: {{ $p['ai_error'] }}</p>
                @endif
                @if (($p['ai_status'] ?? null) === 'running')
                    <p class="fd-running">Analyzing… takes 1–2 minutes. This page refreshes itself — leave it open.</p>
                @endif
                @if ($aiHtml)
                    <div class="ai-out">{!! $aiHtml !!}</div>
                    <p class="fd-ai-src">{{ $p['ai']['provider'] ?? '' }} · {{ $p['ai']['at'] ?? '' }} · informational only, not licensed financial advice</p>
                @elseif (($p['ai_status'] ?? null) !== 'running')
                    <p class="fd-empty">No analysis yet. Add your position below for break-even math and switch suggestions, or run it plain.</p>
                @endif
            </div>
            @if ($verdict)
                <div class="fd-stamp" aria-label="AI verdict: {{ $verdict }}">{{ $verdict }}</div>
            @endif
        </div>

        <form method="POST" action="{{ route('details.analyze', $detail) }}" class="fd-ai-form"
              @if (($p['ai_status'] ?? null) === 'running') hidden @endif>
            @csrf
            <div class="fd-pos">
                <label>
                    <span>Total invested (RM)</span>
                    <input type="number" step="any" min="0" name="invested"
                           value="{{ $p['position']['invested'] ?? '' }}" placeholder="e.g. 130000">
                </label>
                <label>
                    <span>Current value (RM)</span>
                    <input type="number" step="any" min="0" name="current_value"
                           value="{{ $p['position']['current_value'] ?? '' }}" placeholder="e.g. 95000">
                </label>
                <button type="submit" class="btn">{{ $aiHtml ? 'Re-run analysis' : 'Run AI analysis' }}</button>
            </div>
            <p class="fd-pl" id="fd-pl" hidden></p>
            <small>Fill both fields to get a position-aware verdict — break-even math and where to move the money on a sell.</small>
        </form>
        <script>
        (function () {
            var inv = document.querySelector('input[name="invested"]');
            var cur = document.querySelector('input[name="current_value"]');
            var el = document.getElementById('fd-pl');
            if (!inv || !cur || !el) return;
            var fmt = function (n) {
                return (n < 0 ? '−RM ' : '+RM ') + Math.abs(n).toLocaleString('en-MY', { maximumFractionDigits: 0 });
            };
            function upd() {
                var i = parseFloat(inv.value), c = parseFloat(cur.value);
                if (!(i > 0) || !(c > 0)) { el.hidden = true; return; }
                var pl = c - i, pct = pl / i * 100;
                el.textContent = 'Profit/Loss: ' + fmt(pl) + ' (' + (pl >= 0 ? '+' : '') + pct.toFixed(2) + '%)';
                el.className = 'fd-pl ' + (pl >= 0 ? 'pos' : 'neg');
                el.hidden = false;
            }
            inv.addEventListener('input', upd);
            cur.addEventListener('input', upd);
            upd();
        })();
        </script>
    </div>

    @php
        $chatN = count($p['chat'] ?? []);
        $askDate = ! empty($p['ai']['at']) ? \Illuminate\Support\Carbon::parse($p['ai']['at'])->format('d M Y, H:i') : null;
        $sumMeta = trim(($chatN ? "· {$chatN} Q&A " : '').($askDate ? "· analysis {$askDate}" : ''));
    @endphp
    <details id="ai-chat" class="fd-card fd-chat" {{ (($p['chat_status'] ?? null) === 'running' || ! empty($p['chat_error'])) ? 'open' : '' }}>
        <summary class="fd-eyebrow">Ask about this fund <span class="fd-sum-meta">{{ $sumMeta }}</span></summary>

        @if (! empty($p['chat']))
            <div class="fd-chat-log">
                @foreach ($p['chat'] as $m)
                    @if (($m['role'] ?? '') === 'user')
                        <div class="msg msg-user">{{ $m['text'] }}</div>
                    @else
                        <div class="msg msg-ai">{!! $mdLite((string) ($m['text'] ?? '')) !!}</div>
                    @endif
                @endforeach
            </div>
        @endif

        @if (! empty($p['chat_error']))
            <p class="neg">Last question failed: {{ $p['chat_error'] }}</p>
        @endif

        @if (($p['chat_status'] ?? null) === 'running')
            <p class="fd-running">Thinking… typical 30s–2 min. This page updates itself.</p>
        @else
            <form method="POST" action="{{ route('details.chat', $detail) }}" class="fd-chat-form">
                @csrf
                <textarea name="message" required maxlength="2000" rows="2"
                          placeholder="e.g. Why is the verdict {{ strtolower($verdict ?? 'what it is') }}? What if the market drops another 10%?"></textarea>
                <button type="submit" class="btn">Ask</button>
            </form>
            <small>Answers use this fund's data{{ config('ai.llm_provider') === 'claude-cli' ? ' + live web (sources cited)' : '' }}. Educational only.</small>
        @endif
    </details>

    @if ($priceHistory->count() >= 2)
        @php
            $pts   = $priceHistory->all();
            $vals  = array_column($pts, 'price');
            $min   = min($vals);
            $max   = max($vals);
            $span  = ($max - $min) ?: 1;          // flat series guard
            $n     = count($pts);
            $W = 1400; $H = 240;
            $gutL = 60;   // y-axis labels
            $gutB = 22;   // x-axis labels
            $padT = 12; $padR = 14;
            $iw = $W - $gutL - $padR;
            $ih = $H - $padT - $gutB;
            $x  = fn ($i) => $gutL + ($n === 1 ? $iw / 2 : $i * $iw / ($n - 1));
            $y  = fn ($v) => $padT + $ih - (($v - $min) / $span) * $ih;
            $yTicks = [$min, ($min + $max) / 2, $max];
            $xTickIx = $n <= 2 ? [0, $n - 1] : [0, intdiv($n - 1, 2), $n - 1];
            $poly = implode(' ', array_map(
                fn ($pt, $i) => round($x($i), 1) . ',' . round($y($pt['price']), 1),
                $pts, array_keys($pts)
            ));
            $last  = end($pts);
            $first = $pts[0];
            $delta = $first['price'] != 0
                ? round(($last['price'] - $first['price']) / $first['price'] * 100, 2)
                : 0;
        @endphp
        <details class="fd-card fd-span" open>
            <summary class="fd-card-sum">Price history</summary>
            <p class="fd-sub">
                {{ $first['date'] }} → {{ $last['date'] }} ·
                {{ $n }} points · range {{ number_format($min, 4) }}–{{ number_format($max, 4) }} ·
                <span class="{{ $delta >= 0 ? 'pos' : 'neg' }}">{{ $delta >= 0 ? '+' : '' }}{{ $delta }}%</span>
                @if (($trades ?? collect())->isNotEmpty())
                    · <span style="color:#1a7f5a">▲ your buys</span> <span style="color:#c0392b">▼ your sells</span> <small>(hover a marker)</small>
                @endif
            </p>
            <div class="chart-wrap" style="position:relative">
                <svg viewBox="0 0 {{ $W }} {{ $H }}" class="price-chart"
                     role="img" aria-label="Unit price history">
                    @foreach ($yTicks as $tv)
                        @php $gy = round($y($tv), 1); @endphp
                        <line class="axis-grid" x1="{{ $gutL }}" y1="{{ $gy }}" x2="{{ $W - $padR }}" y2="{{ $gy }}" />
                        <text class="axis-lbl" x="{{ $gutL - 6 }}" y="{{ $gy + 3 }}" text-anchor="end">{{ number_format($tv, 4) }}</text>
                    @endforeach
                    @foreach ($xTickIx as $ti)
                        <text class="axis-lbl" x="{{ round($x($ti), 1) }}" y="{{ $H - 6 }}"
                              text-anchor="{{ $ti === 0 ? 'start' : ($ti === $n - 1 ? 'end' : 'middle') }}">{{ $pts[$ti]['date'] }}</text>
                    @endforeach
                    <polyline fill="none" stroke="currentColor" stroke-width="1.5"
                              points="{{ $poly }}" />
                    @foreach ($pts as $i => $pt)
                        @php $dp = $first['price'] != 0 ? round(($pt['price'] - $first['price']) / $first['price'] * 100, 2) : 0; @endphp
                        <circle class="pt-dot" cx="{{ round($x($i), 1) }}" cy="{{ round($y($pt['price']), 1) }}" r="2.4" />
                        <circle class="pt-hit" cx="{{ round($x($i), 1) }}" cy="{{ round($y($pt['price']), 1) }}" r="4"
                                fill="transparent"
                                data-date="{{ $pt['date'] }}"
                                data-price="{{ number_format($pt['price'], 4) }}"
                                data-delta="{{ $dp }}" />
                    @endforeach
                    @php
                        // Your trades on this fund → markers at the nearest price
                        // point's x, at the trade's own execution price (clamped
                        // to the chart range so the marker stays on canvas).
                        $tradeMarks = [];
                        foreach (($trades ?? collect()) as $t) {
                            $bestI = null; $bestDiff = null;
                            foreach ($pts as $i => $pt) {
                                $diff = abs(strtotime($pt['date']) - strtotime($t['date']));
                                if ($bestDiff === null || $diff < $bestDiff) { $bestDiff = $diff; $bestI = $i; }
                            }
                            if ($bestI === null) continue;
                            $pp = max($min, min($max, $t['price']));
                            $tradeMarks[] = ['x' => $x($bestI), 'y' => $y($pp)] + $t;
                        }
                    @endphp
                    @foreach ($tradeMarks as $m)
                        @php
                            $mx = round($m['x'], 1); $my = round($m['y'], 1);
                            $col = $m['in'] ? '#1a7f5a' : '#c0392b';
                            $tri = $m['in']
                                ? ($mx).','.($my - 7).' '.($mx - 5).','.($my + 2).' '.($mx + 5).','.($my + 2)
                                : ($mx).','.($my + 7).' '.($mx - 5).','.($my - 2).' '.($mx + 5).','.($my - 2);
                        @endphp
                        <polygon points="{{ $tri }}" fill="{{ $col }}" stroke="#fff" stroke-width="0.8">
                            <title>{{ $m['in'] ? 'BUY' : 'SELL' }} · {{ $m['type'] }} · {{ $m['date'] }} · {{ number_format(abs($m['units']), 0) }} units @ RM{{ number_format($m['price'], 4) }}</title>
                        </polygon>
                    @endforeach
                </svg>
                <div class="chart-tip" hidden>
                    <div class="tip-row"><span class="tip-lbl">Price/Unit:</span> <b class="tip-date"></b> <span class="tip-val"></span></div>
                    <div class="tip-row"><span class="tip-lbl">Since {{ $first['date'] }}:</span> <span class="tip-delta"></span></div>
                </div>
            </div>
            <script>
            (function () {
                var wrap = document.currentScript.previousElementSibling;
                while (wrap && !wrap.classList.contains('chart-wrap')) wrap = wrap.previousElementSibling;
                if (!wrap) return;
                var tip  = wrap.querySelector('.chart-tip');
                var dEl  = tip.querySelector('.tip-date');
                var vEl  = tip.querySelector('.tip-val');
                var gEl  = tip.querySelector('.tip-delta');
                wrap.querySelectorAll('.pt-hit').forEach(function (hit) {
                    hit.addEventListener('mousemove', function (e) {
                        var r = wrap.getBoundingClientRect();
                        var d = parseFloat(hit.dataset.delta);
                        dEl.textContent = hit.dataset.date;
                        vEl.textContent = hit.dataset.price;
                        gEl.textContent = (d >= 0 ? '+' : '') + d.toFixed(2) + '%';
                        gEl.className = 'tip-delta ' + (d >= 0 ? 'pos' : 'neg');
                        tip.hidden = false;
                        var pad = 8;
                        var tw = tip.offsetWidth, th = tip.offsetHeight;
                        var cx = e.clientX - r.left, cy = e.clientY - r.top;
                        var lx = cx - tw / 2;
                        lx = Math.max(pad, Math.min(lx, r.width - tw - pad));
                        var ty = cy - th - 12;
                        if (ty < pad) ty = cy + 14;
                        ty = Math.max(pad, Math.min(ty, r.height - th - pad));
                        tip.style.left = lx + 'px';
                        tip.style.top  = ty + 'px';
                    });
                    hit.addEventListener('mouseleave', function () {
                        tip.hidden = true;
                    });
                });
            })();
            </script>
        </details>
    @endif

    @if ($factsheet && $factsheet->calendar_returns)
        @php
            $cal = $factsheet->calendar_returns;
            $years = $cal['years'] ?? [];
            $fundPct = $cal['fund_pct'] ?? [];
            $benchPct = $cal['bench_pct'] ?? [];
            $calVals = array_values(array_filter(
                array_merge($fundPct, $benchPct), fn ($v) => $v !== null
            ));
        @endphp
        @if ($calVals && $years)
            @php
                $cMin = min(0, min($calVals));
                $cMax = max(0, max($calVals));
                $cSpan = ($cMax - $cMin) ?: 1;
                $cW = 1400; $cH = 260;
                $cGutL = 52; $cGutB = 24; $cPadT = 16; $cPadR = 14;
                $cIw = $cW - $cGutL - $cPadR;
                $cIh = $cH - $cPadT - $cGutB;
                $ny = count($years);
                $gw = $cIw / $ny;
                $bw = min(34, $gw * 0.28);
                $cy = fn ($v) => $cPadT + $cIh - (($v - $cMin) / $cSpan) * $cIh;
                $y0 = $cy(0);
            @endphp
            <details class="fd-card fd-span">
                <summary class="fd-card-sum">Calendar-year returns vs benchmark</summary>
                <p class="fd-sub">{{ $factsheet->period }} factsheet · hover bars for values</p>
                <div class="chart-wrap">
                    <svg viewBox="0 0 {{ $cW }} {{ $cH }}" class="price-chart" role="img"
                         aria-label="Calendar year returns, fund vs benchmark">
                        @foreach ([$cMin, 0, $cMax] as $tv)
                            @php $gy = round($cy($tv), 1); @endphp
                            <line class="axis-grid" x1="{{ $cGutL }}" y1="{{ $gy }}" x2="{{ $cW - $cPadR }}" y2="{{ $gy }}" />
                            <text class="axis-lbl" x="{{ $cGutL - 6 }}" y="{{ $gy + 3 }}" text-anchor="end">{{ number_format($tv, 1) }}</text>
                        @endforeach
                        @foreach ($years as $i => $yr)
                            @php
                                $gx = $cGutL + $i * $gw + $gw / 2;
                                $fv = $fundPct[$i] ?? null;
                                $bv = $benchPct[$i] ?? null;
                            @endphp
                            <text class="axis-lbl" x="{{ round($gx, 1) }}" y="{{ $cH - 8 }}" text-anchor="middle">{{ $yr }}</text>
                            @if ($fv !== null)
                                <rect x="{{ round($gx - $bw - 2, 1) }}" width="{{ round($bw, 1) }}"
                                      y="{{ round(min($cy($fv), $y0), 1) }}" height="{{ max(1, round(abs($cy($fv) - $y0), 1)) }}"
                                      fill="{{ $fv >= 0 ? '#0e7a46' : '#bb2018' }}">
                                    <title>{{ $yr }} fund: {{ $fv }}%</title>
                                </rect>
                                <text class="bar-lbl" x="{{ round($gx - $bw / 2 - 2, 1) }}"
                                      y="{{ round($fv >= 0 ? $cy($fv) - 4 : $cy($fv) + 11, 1) }}"
                                      text-anchor="middle"
                                      fill="{{ $fv >= 0 ? '#0e7a46' : '#bb2018' }}">{{ number_format($fv, 1) }}</text>
                            @endif
                            @if ($bv !== null)
                                <rect x="{{ round($gx + 2, 1) }}" width="{{ round($bw, 1) }}"
                                      y="{{ round(min($cy($bv), $y0), 1) }}" height="{{ max(1, round(abs($cy($bv) - $y0), 1)) }}"
                                      fill="#9aa0a6" opacity="0.85">
                                    <title>{{ $yr }} benchmark: {{ $bv }}%</title>
                                </rect>
                                <text class="bar-lbl" x="{{ round($gx + $bw / 2 + 2, 1) }}"
                                      y="{{ round($bv >= 0 ? $cy($bv) - 4 : $cy($bv) + 11, 1) }}"
                                      text-anchor="middle" fill="#6b6f76">{{ number_format($bv, 1) }}</text>
                            @endif
                        @endforeach
                        <line x1="{{ $cGutL }}" y1="{{ round($y0, 1) }}" x2="{{ $cW - $cPadR }}" y2="{{ round($y0, 1) }}"
                              stroke="currentColor" stroke-width="0.8" opacity="0.5" />
                    </svg>
                </div>
                <p class="fd-legend">
                    <span class="lg"><i style="background:#0e7a46"></i>fund, gain year</span>
                    <span class="lg"><i style="background:#bb2018"></i>fund, loss year</span>
                    <span class="lg"><i style="background:#9aa0a6"></i>benchmark</span>
                </p>
            </details>
        @endif
    @endif

    @if ($factsheet)
        <p class="fd-eyebrow fd-grid-label">MFR factsheet · {{ $factsheet->period }} · {{ $factsheet->source_pdf }}</p>
        <div class="fd-grid">
            @php
                $fmtMyr = fn ($v) => $v === null ? '—' : 'RM '.number_format((float) $v, 0);
                $fmtNum = fn ($v) => $v === null ? '—' : number_format((float) $v, 0);
            @endphp

            <details class="fd-card">
                <summary class="fd-card-sum">Key facts</summary>
                <table class="kv">
                    <tr><th>Fund size (NAV)</th><td>{{ $fmtMyr($factsheet->fund_size_nav_myr) }}</td></tr>
                    <tr><th>Units outstanding</th><td>{{ $fmtNum($factsheet->fund_size_units) }}</td></tr>
                    @if ($factsheet->volatility_factor !== null)
                        <tr><th>Volatility (Lipper)</th><td>{{ $factsheet->volatility_factor }} <small>({{ $factsheet->volatility_class }})</small></td></tr>
                    @endif
                    @if ($factsheet->benchmark_name)
                        <tr><th>Benchmark</th><td>{{ $factsheet->benchmark_name }}</td></tr>
                    @endif
                    @if ($factsheet->fx_foreign_total_pct !== null)
                        <tr><th>Foreign exposure</th><td>{{ $factsheet->fx_foreign_total_pct }}%</td></tr>
                    @endif
                </table>
            </details>

            @if ($factsheet->benchmark_returns)
                <details class="fd-card">
                    <summary class="fd-card-sum">Fund vs benchmark (%)</summary>
                    <table>
                        <tr><th>Horizon</th><th>Fund</th><th>Bench</th><th>Fund ann.</th><th>Bench ann.</th></tr>
                        @foreach ($factsheet->benchmark_returns as $key => $r)
                            <tr>
                                <th class="w-auto">{{ strtoupper($key) }}</th>
                                <td class="{{ ($r['fund_total'] ?? 0) >= 0 ? 'pos' : 'neg' }}">{{ $r['fund_total'] ?? '—' }}</td>
                                <td class="{{ ($r['bench_total'] ?? 0) >= 0 ? 'pos' : 'neg' }}">{{ $r['bench_total'] ?? '—' }}</td>
                                <td>{{ $r['fund_ann'] ?? '—' }}</td>
                                <td>{{ $r['bench_ann'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </details>
            @endif

            @if ($factsheet->asset_allocation)
                <details class="fd-card">
                    <summary class="fd-card-sum">Asset allocation</summary>
                    <table class="kv">
                        @foreach ($factsheet->asset_allocation as $type => $pct)
                            <tr><th class="w-auto">{{ $type }}</th><td>{{ $pct }}%</td></tr>
                        @endforeach
                    </table>
                </details>
            @endif

            @if ($factsheet->fx_exposure)
                <details class="fd-card">
                    <summary class="fd-card-sum">FX exposure</summary>
                    <table class="kv">
                        @foreach ($factsheet->fx_exposure as $ccy => $pct)
                            <tr><th class="w-auto">{{ $ccy }}</th><td>{{ $pct }}%</td></tr>
                        @endforeach
                    </table>
                </details>
            @endif

            @if ($factsheet->geo_foreign)
                <details class="fd-card">
                    <summary class="fd-card-sum">Geography (foreign)</summary>
                    <table class="kv">
                        @foreach ($factsheet->geo_foreign as $country => $pct)
                            <tr><th class="w-auto">{{ $country }}</th><td>{{ $pct }}%</td></tr>
                        @endforeach
                    </table>
                </details>
            @endif

            @if ($factsheet->top_sectors)
                <details class="fd-card">
                    <summary class="fd-card-sum">Top sectors</summary>
                    <table class="kv">
                        @foreach ($factsheet->top_sectors as $sector => $pct)
                            <tr><th class="w-auto">{{ $sector }}</th><td>{{ $pct }}%</td></tr>
                        @endforeach
                    </table>
                </details>
            @endif

            @if ($factsheet->top_holdings)
                <details class="fd-card">
                    <summary class="fd-card-sum">Top holdings</summary>
                    <ol class="fd-holdings">
                        @foreach ($factsheet->top_holdings as $h)
                            <li>{{ $h }}</li>
                        @endforeach
                    </ol>
                </details>
            @endif

            @if ($factsheet->distributions)
                <details class="fd-card">
                    <summary class="fd-card-sum">Distributions</summary>
                    <table>
                        <tr><th>Period</th><th>Sen</th><th>Date</th><th>Yield %</th></tr>
                        @foreach ($factsheet->distributions as $d)
                            <tr>
                                <th class="w-auto">{{ $d['key'] }}</th>
                                <td>{{ $d['sen'] }}</td>
                                <td>{{ $d['date'] }}</td>
                                <td>{{ $d['yield_pct'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </details>
            @endif

            @if ($factsheet->calendar_returns && ! empty($factsheet->calendar_returns['years']))
                @php
                    $cal = $factsheet->calendar_returns;
                    $years = $cal['years'] ?? [];
                    $fundPct = $cal['fund_pct'] ?? [];
                    $benchPct = $cal['bench_pct'] ?? [];
                @endphp
                <details class="fd-card">
                    <summary class="fd-card-sum">Calendar returns table (%)</summary>
                    <table>
                        <tr><th>Year</th>@foreach ($years as $y)<th>{{ $y }}</th>@endforeach</tr>
                        <tr>
                            <th class="w-auto">Fund</th>
                            @foreach ($fundPct as $v)
                                <td class="{{ $v !== null && $v >= 0 ? 'pos' : 'neg' }}">{{ $v ?? '—' }}</td>
                            @endforeach
                        </tr>
                        <tr>
                            <th class="w-auto">Benchmark</th>
                            @foreach ($benchPct as $v)
                                <td class="{{ $v !== null && $v >= 0 ? 'pos' : 'neg' }}">{{ $v ?? '—' }}</td>
                            @endforeach
                        </tr>
                    </table>
                </details>
            @endif
        </div>
    @endif

    @php
        $hasCaptured = ! empty($p['fields']) || ! empty($p['objective']) || ! empty($p['performance'])
            || ! empty($p['calendar']) || ! empty($p['distribution']) || ! empty($p['chart'])
            || ! empty($p['price']) || ! empty($p['allocation']);

        // Section text is TSV-ish: line 1 = title, rest = tab-separated rows.
        $tsv = function (string $txt) {
            $lines = array_values(array_filter(explode("\n", $txt), fn ($l) => trim($l) !== ''));
            $title = trim(array_shift($lines) ?? '');
            $rows  = [];
            foreach ($lines as $ln) {
                $cells = array_map('trim', explode("\t", $ln));
                while (count($cells) > 1 && end($cells) === '') {
                    array_pop($cells);
                }
                if (array_filter($cells, 'strlen')) {
                    $rows[] = $cells;
                }
            }
            if ($rows) {
                $w = count($rows[0]);
                $rows = array_values(array_filter($rows, fn ($r) => count($r) === $w));
            }
            return [$title, $rows];
        };
    @endphp

    @if ($hasCaptured)
        <details class="fd-fold">
            <summary>Captured PMO detail-page data</summary>

            @if (!empty($p['fields']))
                <h2>Fund facts</h2>
                <table>
                    @foreach ($p['fields'] as $k => $v)
                        <tr><th>{{ $k }}</th><td>{{ $v }}</td></tr>
                    @endforeach
                </table>
            @endif

            @if (!empty($p['objective']))
                <h2>Objective</h2>
                <pre>{{ $p['objective'] }}</pre>
            @endif

            @foreach (['performance' => 'Performance', 'calendar' => 'Calendar returns', 'distribution' => 'Distribution', 'chart' => 'Chart vs benchmark'] as $key => $label)
                @if (!empty($p[$key]))
                    @php
                        [$title, $rows] = $tsv($p[$key]);
                    @endphp
                    <h2>{{ $label }}</h2>
                    @if ($title)<small>{{ $title }}</small>@endif
                    <table>
                        @foreach ($rows as $i => $r)
                            <tr>
                                @foreach ($r as $c)
                                    @if ($i === 0)<th class="w-auto">{{ $c }}</th>@else<td>{{ $c }}</td>@endif
                                @endforeach
                            </tr>
                        @endforeach
                    </table>
                @endif
            @endforeach

            @if (!empty($p['price']))
                @php
                    $pl = array_values(array_filter(array_map('trim', explode("\n", $p['price'])), 'strlen'));
                    array_shift($pl); // drop "Fund Price" title
                @endphp
                <h2>Fund price</h2>
                <table>
                    @for ($i = 0; $i < count($pl); $i += 2)
                        <tr><th class="w-auto">{{ $pl[$i] }}</th><td>{{ $pl[$i + 1] ?? '' }}</td></tr>
                    @endfor
                </table>
            @endif

            @if (!empty($p['allocation']))
                @php
                    $arows = [];
                    foreach (explode("\n", $p['allocation']) as $ln) {
                        $cells = array_map(fn ($c) => trim(str_replace("\u{00A0}", '', $c)), explode("\t", $ln));
                        $cells = array_values(array_filter($cells, 'strlen'));
                        if ($cells) {
                            $arows[] = $cells;
                        }
                    }
                @endphp
                <h2>Asset allocation (captured)</h2>
                <table>
                    @foreach ($arows as $r)
                        @if (count($r) === 1)
                            <tr><th colspan="2" class="sec">{{ $r[0] }}</th></tr>
                        @else
                            <tr><td>{{ $r[0] }}</td><td>{{ $r[1] }}</td></tr>
                        @endif
                    @endforeach
                </table>
            @endif
        </details>
    @endif
@endsection
