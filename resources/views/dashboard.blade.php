@extends('layouts.app')

@section('title', 'PMFAI — Dashboard')
@section('body-class', 'page-dashboard')

@section('content')
    <h1>Market indices</h1>
    @php
        // Config-driven: symbol (live Yahoo quote via pmoai:fetch-quotes) +
        // tv (embedded TradingView chart). Live number sits above the chart.
        $indices = config('quotes.indices', []);
        $quotes = \App\Models\MarketQuote::all()->keyBy('symbol');
        $lastFetch = optional($quotes->max('fetched_at'));
    @endphp
    <p class="idx-intro">Live quotes (Yahoo) above each chart, tagged with the holding each drives.
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
