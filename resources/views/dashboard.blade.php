@extends('layouts.app')

@section('title', 'PMFAI — Dashboard')
@section('body-class', 'page-dashboard')

@section('content')
    <h1>Market indices</h1>
    @php
        // Indices derived from the portfolio's real geographic exposure
        // (each fund's captured geographical breakdown, weighted by value).
        $indices = app(\App\Services\PortfolioIndices::class)->derive();
        $quotes = \App\Models\MarketQuote::all()->keyBy('symbol');
        $lastFetch = optional($quotes->max('fetched_at'));
        // Our own daily history (independent of TradingView) → sparklines.
        $hist = \App\Models\MarketQuoteDay::whereIn('symbol', array_column($indices, 'symbol'))
            ->orderBy('quote_date')->get(['symbol', 'quote_date', 'price'])->groupBy('symbol');
    @endphp
    <p class="idx-intro">Indices matched to where your money actually sits (from each fund's geographical breakdown). Live quotes (Yahoo) above each chart.
        @if ($lastFetch)Updated {{ $lastFetch->diffForHumans() }}.@else No quotes yet.@endif
        The chart below is TradingView (may lag for some Asian exchanges).
        <form method="POST" action="{{ route('quotes.fetch') }}" style="display:inline">
            @csrf
            <button type="submit" class="idx-refresh">↻ Refresh quotes</button>
        </form>
        @if (session('status'))<span class="idx-ok">✓ {{ session('status') }}</span>@endif
    </p>

    <div class="idx-grid">
        @foreach ($indices as $ix)
            @php $q = $quotes[$ix['symbol']] ?? null; @endphp
            <section class="idx-section">
                <div class="idx-head">
                    <h2>{{ $ix['label'] }}</h2>
                    <span class="idx-tag">{{ $ix['tag'] }}</span>
                </div>
                @if ($q && $q->price !== null)
                    @php $up = (float) $q->change_pct >= 0; @endphp
                    <div class="idx-quote">
                        <span class="idx-price">{{ number_format((float) $q->price, (float) $q->price < 10 ? 4 : 2) }}</span>
                        <span class="idx-chg {{ $up ? 'pos' : 'neg' }}">{{ $up ? '▲' : '▼' }} {{ $q->change_pct !== null ? ($up ? '+' : '').$q->change_pct.'%' : '—' }}</span>
                        <span class="idx-ccy">{{ $q->currency }}</span>
                    </div>
                @endif
                @php $rows = $hist[$ix['symbol']] ?? collect(); $pts = $rows->pluck('price')->map(fn ($p) => (float) $p)->values(); @endphp
                @if ($pts->count() >= 2)
                    @php
                        $mn = $pts->min(); $mx = $pts->max(); $rng = ($mx - $mn) ?: 1; $n = $pts->count();
                        $poly = $pts->map(fn ($p, $i) => round($n > 1 ? $i / ($n - 1) * 236 + 2 : 2, 1).','.round(42 - ($p - $mn) / $rng * 38, 1))->implode(' ');
                        $sUp = $pts->last() >= $pts->first();
                        $chg = $pts->first() > 0 ? ($pts->last() - $pts->first()) / $pts->first() * 100 : 0;
                        $dFirst = $rows->first()->quote_date; $dMid = $rows->get(intdiv($n, 2))->quote_date; $dLast = $rows->last()->quote_date;
                        $yfmt = fn ($v) => number_format($v, $v < 10 ? 4 : ($v < 1000 ? 2 : 0));
                    @endphp
                    <div class="idx-spark-row">
                        <div class="idx-yaxis"><span>{{ $yfmt($mx) }}</span><span>{{ $yfmt($mn) }}</span></div>
                        <svg viewBox="0 0 240 44" class="idx-spark" preserveAspectRatio="none" role="img" aria-label="{{ $ix['label'] }} history">
                            <polyline points="{{ $poly }}" fill="none" stroke="{{ $sUp ? '#1a7f5a' : '#c0392b' }}" stroke-width="1.5"></polyline>
                        </svg>
                    </div>
                    <div class="idx-spark-axis">
                        <span>{{ $dFirst->format('d M') }}</span>
                        <span>{{ $dMid->format('d M') }}</span>
                        <span>{{ $dLast->format('d M') }}</span>
                    </div>
                    <div class="idx-spark-lbl">{{ $pts->count() }} trading days stored · {{ $chg >= 0 ? '+' : '' }}{{ number_format($chg, 1) }}% over the window</div>
                @endif
                <div class="tradingview-widget-container">
                    <div class="tradingview-widget-container__widget"></div>
                    <script type="text/javascript"
                            src="https://s3.tradingview.com/external-embedding/embed-widget-mini-symbol-overview.js"
                            async>
                        {!! json_encode([
                            'symbol'        => $ix['tv'],
                            'width'         => '100%',
                            'height'        => 200,
                            'locale'        => 'en',
                            'dateRange'     => '12M',
                            'colorTheme'    => 'light',
                            'isTransparent' => true,
                            'autosize'      => false,
                            'chartOnly'     => false,
                            'noTimeScale'   => false,
                        ], JSON_UNESCAPED_SLASHES) !!}
                    </script>
                </div>
                @if (! empty($ix['funds']))
                    <div class="idx-funds">
                        <span class="idx-funds-h">Your funds here:</span>
                        @foreach ($ix['funds'] as $f)
                            <span class="idx-fund">{{ \Illuminate\Support\Str::of($f['name']) }} <b>RM{{ number_format($f['rm'], 0) }}</b></span>
                        @endforeach
                    </div>
                @endif
                <small><a href="https://finance.yahoo.com/quote/{{ urlencode($ix['symbol']) }}" target="_blank" rel="noopener">open full page ↗</a></small>
            </section>
        @endforeach
    </div>

    <style>
        .idx-intro { color: #666; margin: 0 0 16px; font-size: 13px; }
        .idx-refresh { margin-left: 8px; padding: 3px 10px; border: 1px solid #c8102e; background: #fff;
            color: #c8102e; border-radius: 5px; cursor: pointer; font-size: 12px; }
        .idx-refresh:hover { background: #c8102e; color: #fff; }
        .idx-ok { margin-left: 8px; color: #1a7; font-size: 12px; }
        .idx-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
        }
        .idx-section {
            border: 1px solid #e5e5e5;
            border-radius: 8px;
            padding: 12px 14px 10px;
            background: #fff;
        }
        .idx-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
        .idx-section h2 { margin: 0; font-size: 15px; }
        .idx-tag { font-size: 11px; color: #888; white-space: nowrap; }
        .idx-quote { display: flex; align-items: baseline; gap: 8px; margin: 2px 0 6px; }
        .idx-price { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .idx-chg { font-size: 13px; font-weight: 600; }
        .idx-ccy { font-size: 11px; color: #aaa; margin-left: auto; }
        .idx-spark-row { display: flex; align-items: stretch; gap: 4px; }
        .idx-yaxis { display: flex; flex-direction: column; justify-content: space-between; font-size: 9px; color: #aaa; text-align: right; min-width: 34px; padding: 1px 0; }
        .idx-spark { flex: 1; height: 44px; display: block; }
        .idx-spark-axis { display: flex; justify-content: space-between; font-size: 9px; color: #aaa; margin-top: 1px; padding-left: 38px; }
        .idx-spark-lbl { font-size: 10px; color: #999; margin: 1px 0 6px; }
        .idx-funds { border-top: 1px solid #eee; margin-top: 6px; padding-top: 5px; display: flex; flex-direction: column; gap: 2px; }
        .idx-funds-h { font-size: 9px; color: #999; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 1px; }
        .idx-fund { display: flex; justify-content: space-between; gap: 8px; font-size: 10px; color: #555; line-height: 1.3; }
        .idx-fund b { color: #222; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .idx-section small { display: block; margin-top: 4px; }
        .idx-section small a { color: #c8102e; text-decoration: none; font-size: 11px; }
        .tradingview-widget-container { min-height: 200px; }
    </style>

    {{--
      Blank panels before this rev were MarketWatch iframes — the site sends
      X-Frame-Options / CSP frame-ancestors and refuses to be framed. Replaced
      with TradingView mini widgets (embeddable, ad-free, live). If a panel
      renders blank, the TradingView symbol is wrong — verify on tradingview.com.
      Symbols: NASDAQ:IXIC · DJ:DJI · TVC:GOLD · TVC:UKOIL · IDX:COMPOSITE · NSE:NIFTY · FX_IDC:USDMYR · FTSEMYX:FBMKLCI
      Tighter PeAITF proxy if wanted: swap NASDAQ:IXIC → the semis index
      (the review tracks the Philadelphia Semiconductor Index / SOX).
    --}}
@endsection
