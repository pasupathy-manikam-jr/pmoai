@extends('layouts.app')

@section('title', 'pmoai — Dashboard')
@section('body-class', 'page-dashboard')

@section('content')
    <h1>Market indices</h1>
    <p class="idx-intro">Mini-charts (TradingView), tagged with the holding each drives.
        <strong>Quotes may lag</strong> — free feeds are delayed (Asian exchanges often
        end-of-day: e.g. Jakarta shows last close, not the intraday print). Use “open full
        page ↗” for the live number.</p>

    @php
        // TradingView mini-symbol-overview widgets — actually embeddable
        // (MarketWatch refuses framing → blank). Symbols aligned to the
        // portfolio's real exposures so the dashboard reads the book at a
        // glance. `tv` = TradingView symbol; `url` = human page fallback.
        $indices = [
            ['name' => 'NASDAQ Composite',  'tag' => 'AI / tech · PeAITF',   'tv' => 'NASDAQ:IXIC',    'url' => 'https://www.marketwatch.com/investing/index/comp'],
            ['name' => 'Gold (spot)',       'tag' => 'PeEMAS',               'tv' => 'TVC:GOLD',       'url' => 'https://www.marketwatch.com/investing/future/gc00'],
            ['name' => 'Jakarta Composite', 'tag' => 'PINDOSF',              'tv' => 'IDX:COMPOSITE',  'url' => 'https://www.marketwatch.com/investing/index/jakidx?countrycode=id'],
            ['name' => 'Nifty 50 — India',  'tag' => 'PeIIGEF',              'tv' => 'NSE:NIFTY',      'url' => 'https://www.marketwatch.com/investing/index/nifty%2050?countrycode=in'],
            ['name' => 'USD / MYR',         'tag' => 'RM value of ALL foreign funds', 'tv' => 'FX_IDC:USDMYR', 'url' => 'https://www.marketwatch.com/investing/currency/usdmyr'],
            ['name' => 'FBM KLCI',          'tag' => 'Malaysia base · PRS',  'tv' => 'TVC:KLSE',       'url' => 'https://www.marketwatch.com/investing/index/fbmklci?countrycode=my'],
        ];
    @endphp

    <div class="idx-grid">
        @foreach ($indices as $ix)
            <section class="idx-section">
                <div class="idx-head">
                    <h2>{{ $ix['name'] }}</h2>
                    <span class="idx-tag">{{ $ix['tag'] }}</span>
                </div>
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
                <small><a href="{{ $ix['url'] }}" target="_blank" rel="noopener">open full page ↗</a></small>
            </section>
        @endforeach
    </div>

    <style>
        .idx-intro { color: #666; margin: 0 0 16px; font-size: 13px; }
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
        .idx-section small { display: block; margin-top: 4px; }
        .idx-section small a { color: #c8102e; text-decoration: none; font-size: 11px; }
        .tradingview-widget-container { min-height: 200px; }
    </style>

    {{--
      Blank panels before this rev were MarketWatch iframes — the site sends
      X-Frame-Options / CSP frame-ancestors and refuses to be framed. Replaced
      with TradingView mini widgets (embeddable, ad-free, live). If a panel
      renders blank, the TradingView symbol is wrong — verify on tradingview.com.
      Symbols: NASDAQ:IXIC · TVC:GOLD · IDX:COMPOSITE · NSE:NIFTY · FX_IDC:USDMYR · TVC:KLSE
      Tighter PeAITF proxy if wanted: swap NASDAQ:IXIC → the semis index
      (the review tracks the Philadelphia Semiconductor Index / SOX).
    --}}
@endsection
