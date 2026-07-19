@extends('layouts.app')

@section('title', 'pmoai — Dashboard')
@section('body-class', 'page-dashboard')

@section('content')
    <h1>Market indices</h1>

    @php
        // Each section loads its source page directly in a frame.
        $indices = [
            ['name' => 'NASDAQ Composite', 'url' => 'https://www.marketwatch.com/investing/index/comp'],
            ['name' => 'Hang Seng',        'url' => 'https://www.marketwatch.com/investing/index/hsi?countrycode=hk'],
            ['name' => 'Jakarta (IDX)',    'url' => 'https://www.marketwatch.com/investing/index/jakidx?countrycode=id'],
            ['name' => 'Gold',             'url' => 'https://www.marketwatch.com/investing/future/gc00'],
        ];
    @endphp

    @foreach ($indices as $ix)
        <section class="idx-section">
            <h2>{{ $ix['name'] }}</h2>
            <iframe class="idx-frame"
                    src="{{ $ix['url'] }}"
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    title="{{ $ix['name'] }}"></iframe>
            <small><a href="{{ $ix['url'] }}" target="_blank" rel="noopener">open on MarketWatch ↗</a></small>
        </section>
    @endforeach

    {{--
      NOTE: MarketWatch sends X-Frame-Options / CSP frame-ancestors, so the
      iframes above usually render BLANK (the site refuses to be framed) and
      its ads can't be stripped from a 3rd-party page anyway.

      Clean, ad-free, *actually embeddable* alternative — TradingView widget.
      Swap the iframe above for this to get just the chart. Example (NASDAQ):

      <div class="tradingview-widget-container">
        <div class="tradingview-widget-container__widget"></div>
        <script type="text/javascript"
          src="https://s3.tradingview.com/external-embedding/embed-widget-mini-symbol-overview.js" async>
        { "symbol": "NASDAQ:IXIC", "width": "100%", "height": 300,
          "locale": "en", "dateRange": "12M", "colorTheme": "light",
          "isTransparent": true, "autosize": false }
        </script>
      </div>

      Symbols: NASDAQ:IXIC · HSI:HSI · IDX:COMPOSITE · TVC:GOLD
    --}}
@endsection
