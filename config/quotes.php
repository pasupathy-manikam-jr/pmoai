<?php

/*
 * Market indices shown on the dashboard and polled by pmoai:fetch-quotes.
 * `symbol` = Yahoo Finance symbol (live quote); `tv` = TradingView symbol
 * (embedded chart). Aligned to the portfolio's real exposures.
 */
return [
    'indices' => [
        ['symbol' => '^IXIC',  'label' => 'NASDAQ Composite',  'tag' => 'AI / tech · PeAITF',              'tv' => 'NASDAQ:IXIC'],
        ['symbol' => '^DJI',   'label' => 'Dow Jones',         'tag' => 'US market breadth',               'tv' => 'DJ:DJI'],
        ['symbol' => 'GC=F',   'label' => 'Gold (spot)',       'tag' => 'PeEMAS',                          'tv' => 'TVC:GOLD'],
        ['symbol' => 'BZ=F',   'label' => 'Brent Crude Oil',   'tag' => 'Malaysia exports · inflation',    'tv' => 'TVC:UKOIL'],
        ['symbol' => '^JKSE',  'label' => 'Jakarta Composite', 'tag' => 'PINDOSF',                         'tv' => 'IDX:COMPOSITE'],
        ['symbol' => '^NSEI',  'label' => 'Nifty 50 — India',  'tag' => 'PeIIGEF',                         'tv' => 'NSE:NIFTY'],
        ['symbol' => 'MYR=X',  'label' => 'USD / MYR',         'tag' => 'RM value of ALL foreign funds',   'tv' => 'FX_IDC:USDMYR'],
        ['symbol' => '^KLSE',  'label' => 'FBM KLCI',          'tag' => 'Bursa KL · Malaysia base · PRS',  'tv' => 'FTSEMYX:FBMKLCI'],
    ],
];
