@extends('layouts.app')

@section('title', 'PMFAI — Advisor')
@section('body-class', 'page-advisor')

@section('content')
    <div class="adv">
        <h1>What to consider now</h1>
        <p class="adv-lead">Ideas from screening all <b>{{ $plan['catalog_count'] }}</b> Public Mutual funds on their real numbers (3-year return per unit of risk) against your <b>{{ $plan['held_count'] }}</b> holdings and the real switch rules. Book: RM {{ number_format($plan['book'], 0) }}.</p>
        <p class="adv-warn">⚠ Informational only — not licensed financial advice. These are screens on past performance, which does not predict the future. You decide.</p>

        <div id="ai" class="adv-ai">
            <div class="adv-ai-top">
                <span class="adv-ai-h">Plain-English summary</span>
                <form method="POST" action="{{ route('advisor.explain') }}">
                    @csrf
                    <button type="submit" class="adv-ai-btn">{{ ! empty($ai['text']) ? '↻ Regenerate' : '✨ Explain with AI' }}</button>
                </form>
            </div>
            @if (($ai['status'] ?? null) === 'running')
                <p class="adv-ai-run" id="adv-ai-run">Writing… the AI provider can take 1–2 minutes. This updates itself.</p>
            @elseif (($ai['status'] ?? null) === 'failed')
                <p class="neg">Couldn't generate a summary: {{ $ai['error'] ?? 'unknown error' }}</p>
            @elseif (! empty($ai['text']))
                <div class="adv-ai-out">{!! nl2br(e($ai['text'])) !!}</div>
                <p class="adv-ai-src">AI-written from the screener's own figures · {{ $ai['at'] ?? '' }}</p>
            @else
                <p class="adv-ai-empty">The suggestions below are already explained line by line. Want it tied together in one short paragraph? Click <b>Explain with AI</b> — it only rewords the figures above, never invents.</p>
            @endif
        </div>

        @php $empty = ! ($plan['trim'] || $plan['switch'] || $plan['deploy'] || $plan['buy']); @endphp
        @if ($empty)
            <p class="adv-none">Nothing flagged — your book is within the concentration cap, has no clearly-beaten holdings, no idle cash, and reasonable spread. ✓</p>
        @endif

        {{-- TRIM -------------------------------------------------------- --}}
        @if ($plan['trim'])
            <section class="adv-grp">
                <h2><span class="adv-tag t-trim">TRIM</span> Reduce a fund that's too big</h2>
                @foreach ($plan['trim'] as $t)
                    <div class="adv-card">
                        <div class="adv-head">{!! \App\Support\FundLink::to($t['fund']) !!}
                            <span class="adv-badge">{{ $t['weight'] }}% · {{ $t['risk'] }} risk</span>
                            @if ($t['trend_5d'] !== null)<span class="adv-badge {{ $t['trend_5d'] >= 0 ? 'pos' : 'neg' }}">{{ $t['trend_5d'] >= 0 ? '▲ +' : '▼ ' }}{{ $t['trend_5d'] }}% this week</span>@endif
                            <span class="adv-urg">{{ $t['urgency'] }}</span>
                        </div>
                        <p class="adv-why">{{ $t['why'] }}</p>
                        <p class="adv-why"><b>Timing:</b> {{ $t['timing'] }}</p>
                        <p class="adv-do">If/when you do trim: about <b>RM {{ number_format($t['amount'], 0) }}</b> back to 25%. Same-series switch into your other e-Series funds is free after 90 days.</p>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- SWITCH ------------------------------------------------------ --}}
        @if ($plan['switch'])
            <section class="adv-grp">
                <h2><span class="adv-tag t-switch">SWITCH</span> A better fund in the same category</h2>
                @foreach ($plan['switch'] as $s)
                    <div class="adv-card">
                        <div class="adv-head">{!! \App\Support\FundLink::to($s['from']) !!} → {!! \App\Support\FundLink::to($s['to']) !!}</div>
                        <table class="adv-cmp">
                            <tr><th></th><th>Now</th><th>Suggested</th></tr>
                            <tr><td>3-year return</td><td>{{ $s['from_3y'] !== null ? number_format($s['from_3y'], 1).'%' : '—' }}</td><td class="pos">{{ $s['to_3y'] !== null ? number_format($s['to_3y'], 1).'%' : '—' }}</td></tr>
                            <tr><td>Risk</td><td>{{ $s['from_risk'] }}</td><td>{{ $s['to_risk'] }}</td></tr>
                            <tr><td>Category</td><td colspan="2">{{ $s['cat'] }}</td></tr>
                        </table>
                        <p class="adv-why">{{ $s['why'] }}</p>
                        <p class="adv-do">Fee: <b>{{ $s['fee'] }}</b>.</p>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- DEPLOY ------------------------------------------------------ --}}
        @if ($plan['deploy'])
            <section class="adv-grp">
                <h2><span class="adv-tag t-deploy">DEPLOY</span> Put idle cash to work</h2>
                @foreach ($plan['deploy'] as $d)
                    <div class="adv-card">
                        <div class="adv-head">{!! \App\Support\FundLink::to($d['from']) !!} <span class="adv-badge">RM {{ number_format($d['amount'], 0) }} idle</span></div>
                        <p class="adv-why">{{ $d['why'] }}</p>
                        <table class="adv-opts">
                            <tr><th>Options across the risk ladder (same series)</th><th class="r">3Y</th><th class="r">Risk</th><th class="r">Entry now</th><th class="r">Sales charge</th></tr>
                            @foreach ($d['options'] as $o)
                                <tr>
                                    <td><span class="adv-tier tier-{{ strtolower($o['tier']) }}">{{ $o['tier'] }}</span> {!! \App\Support\FundLink::to($o['name']) !!}</td>
                                    <td class="r">{{ $o['r3'] !== null ? number_format($o['r3'], 1).'%' : '—' }}</td>
                                    <td class="r">{{ $o['risk'] }}</td>
                                    <td class="r"><span class="{{ $o['entry_good'] === true ? 'pos' : ($o['entry_good'] === false ? 'neg' : '') }}">{{ $o['entry'] ?? '—' }}</span></td>
                                    <td class="r neg">{{ $o['fee_pct'] }}%</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- BUY --------------------------------------------------------- --}}
        @if ($plan['buy'])
            <section class="adv-grp">
                <h2><span class="adv-tag t-buy">DIVERSIFY</span> A category you barely hold</h2>
                @foreach ($plan['buy'] as $b)
                    <div class="adv-card">
                        <div class="adv-head">{{ $b['category'] }} <span class="adv-badge">you have {{ $b['have_pct'] }}%</span></div>
                        <p class="adv-why">{{ $b['why'] }}</p>
                        <table class="adv-opts">
                            <tr><th>Strongest in this category</th><th class="r">3Y</th><th class="r">Risk</th><th class="r">Sales charge</th></tr>
                            @foreach ($b['options'] as $o)
                                <tr>
                                    <td>{!! \App\Support\FundLink::to($o['name']) !!}@if ($o['is_e']) <span class="adv-e">e</span>@endif</td>
                                    <td class="r">{{ $o['r3'] !== null ? number_format($o['r3'], 1).'%' : '—' }}</td>
                                    <td class="r">{{ $o['risk'] }}</td>
                                    <td class="r neg">{{ $o['fee_pct'] }}%</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endforeach
            </section>
        @endif

        <p class="adv-foot">Screened on captured Public Mutual catalogue data (returns, risk, category) + your holdings + real PMO fees. PRS funds are excluded (retirement-locked). Not advice — verify against official material and your own judgement before acting.</p>
    </div>

    <style>
        .adv { max-width: 860px; }
        .adv-lead { color: #555; font-size: 13px; line-height: 1.5; margin: 0 0 8px; }
        .adv-warn { background: #fdf3e7; color: #8a6a00; font-size: 12px; padding: 8px 11px; border-radius: 6px; margin: 0 0 18px; }
        .adv-none { background: #eef7f0; color: #1a7f5a; padding: 12px 14px; border-radius: 8px; }
        .adv-grp { margin: 0 0 24px; }
        .adv-grp h2 { font-size: 16px; display: flex; align-items: center; gap: 9px; margin: 0 0 10px; }
        .adv-tag { font-size: 10px; font-weight: 700; color: #fff; padding: 3px 7px; border-radius: 4px; letter-spacing: .03em; }
        .t-trim { background: #c0392b; } .t-switch { background: #2a6fc9; } .t-deploy { background: #8a6a00; } .t-buy { background: #1a7f5a; }
        .adv-card { border: 1px solid #e5e5e5; border-radius: 10px; padding: 14px 16px; margin: 0 0 12px; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .adv-head { font-weight: 600; font-size: 14px; margin-bottom: 6px; }
        .adv-badge { font-size: 11px; font-weight: 500; color: #888; margin-left: 6px; }
        .adv-badge.pos { color: #1a7f5a; } .adv-badge.neg { color: #c0392b; }
        .adv-urg { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #8a6a00; background: #fdf3e7; padding: 2px 7px; border-radius: 4px; margin-left: 6px; }
        .adv-why { color: #555; font-size: 13px; line-height: 1.5; margin: 6px 0; }
        .adv-do { font-size: 13px; margin: 6px 0 0; color: #333; }
        .adv-cmp, .adv-opts { width: 100%; border-collapse: collapse; font-size: 13px; margin: 8px 0; }
        .adv-cmp th, .adv-cmp td, .adv-opts th, .adv-opts td { padding: 5px 8px; border-bottom: 1px solid #f2f2f2; text-align: left; }
        .adv-cmp th, .adv-opts th { font-size: 11px; color: #999; font-weight: 600; }
        .adv-cmp .r, .adv-opts .r { text-align: right; font-variant-numeric: tabular-nums; }
        .pos { color: #1a7f5a; } .neg { color: #c0392b; }
        .adv-e { display: inline-block; background: #eef; color: #2a6fc9; font-size: 9px; font-weight: 700; padding: 1px 4px; border-radius: 3px; vertical-align: middle; }
        .adv-tier { display: inline-block; font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 3px; margin-right: 5px; text-transform: uppercase; letter-spacing: .03em; }
        .tier-steadier { background: #e8f4ee; color: #1a7f5a; } .tier-balanced { background: #eef2fb; color: #2a6fc9; } .tier-growth { background: #fdece9; color: #c0392b; }
        .adv-foot { font-size: 11px; color: #999; margin-top: 18px; line-height: 1.5; }
        .adv-ai { border: 1px solid #e2e8f2; background: #f7f9fc; border-radius: 10px; padding: 13px 16px; margin: 0 0 20px; }
        .adv-ai-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .adv-ai-h { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #667; font-weight: 600; }
        .adv-ai-btn { border: 1px solid #2a6fc9; background: #fff; color: #2a6fc9; border-radius: 6px; padding: 5px 11px; font-size: 12px; cursor: pointer; }
        .adv-ai-btn:hover { background: #2a6fc9; color: #fff; }
        .adv-ai-run { color: #8a6a00; font-size: 13px; margin: 8px 0 0; }
        .adv-ai-empty { color: #778; font-size: 12px; margin: 8px 0 0; line-height: 1.5; }
        .adv-ai-out { font-size: 13.5px; line-height: 1.6; color: #333; margin: 8px 0 0; }
        .adv-ai-src { font-size: 11px; color: #aab; margin: 6px 0 0; }
    </style>

    @if (($ai['status'] ?? null) === 'running')
        <script>
        (function () {
            function poll() {
                fetch('{{ route('advisor.status') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { if (j.status !== 'running') location.reload(); else setTimeout(poll, 5000); })
                    .catch(function () { setTimeout(poll, 8000); });
            }
            setTimeout(poll, 5000);
        })();
        </script>
    @endif
@endsection
