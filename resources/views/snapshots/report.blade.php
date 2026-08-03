@extends('layouts.app')

@section('title', 'PMFAI — Portfolio report')
@section('body-class', 'page-report')

@section('content')
    @php
        $pl = $totVal - $totInv;
        $tot = $totVal ?: 1;
        $palette = ['#c8102e', '#1a7f5a', '#2a6fc9', '#e0a020', '#8e44ad', '#16a085', '#d35400', '#7f8c8d', '#c0392b'];
        $sN = fn ($n) => (string) \Illuminate\Support\Str::of($n)->after('PUBLIC ');
    @endphp

    <div class="rpt">
        <div class="rpt-head">
            <div>
                <h1>Portfolio report</h1>
                <p class="rpt-sub">Generated {{ now()->format('d M Y, H:i') }} · data captured {{ $snapshot->updated_at->format('d M Y') }}</p>
            </div>
            <button class="rpt-print" onclick="window.print()">🖶 Print / Save PDF</button>
        </div>

        <div class="rpt-tiles">
            <div class="rpt-tile"><span>Total value</span><b>RM {{ number_format($totVal, 2) }}</b></div>
            <div class="rpt-tile"><span>Invested</span><b>RM {{ number_format($totInv, 2) }}</b></div>
            <div class="rpt-tile"><span>Gain / loss</span><b class="{{ $pl >= 0 ? 'pos' : 'neg' }}">{{ $pl >= 0 ? '+' : '−' }}RM {{ number_format(abs($pl), 2) }} ({{ $pl >= 0 ? '+' : '' }}{{ number_format($pl / max($totInv, 1) * 100, 1) }}%)</b></div>
            <div class="rpt-tile"><span>Funds held</span><b>{{ $held->count() }}</b></div>
        </div>

        <table class="rpt-table">
            <thead>
                <tr><th></th><th>Fund</th><th class="r">Invested</th><th class="r">Value</th><th class="r">Gain/loss</th><th class="r">%</th><th class="r">Weight</th><th>AI call</th></tr>
            </thead>
            <tbody>
                @foreach ($held as $i => $h)
                    @php $w = $h['value'] / $tot * 100; $plp = $h['invested'] > 0 ? $h['pl'] / $h['invested'] * 100 : 0; @endphp
                    <tr>
                        <td><span class="rpt-dot" style="background:{{ $palette[$i % count($palette)] }}"></span></td>
                        <td>{{ $sN($h['name']) }}</td>
                        <td class="r">{{ number_format($h['invested'], 0) }}</td>
                        <td class="r">{{ number_format($h['value'], 0) }}</td>
                        <td class="r {{ $h['pl'] >= 0 ? 'pos' : 'neg' }}">{{ $h['pl'] >= 0 ? '+' : '−' }}{{ number_format(abs($h['pl']), 0) }}</td>
                        <td class="r {{ $h['pl'] >= 0 ? 'pos' : 'neg' }}">{{ $h['pl'] >= 0 ? '+' : '' }}{{ number_format($plp, 1) }}</td>
                        <td class="r">{{ number_format($w, 1) }}%</td>
                        <td>{{ $h['verdict'] ? ucfirst(strtolower($h['verdict'])) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td></td><td>Total</td><td class="r">{{ number_format($totInv, 0) }}</td><td class="r">{{ number_format($totVal, 0) }}</td><td class="r {{ $pl >= 0 ? 'pos' : 'neg' }}">{{ $pl >= 0 ? '+' : '−' }}{{ number_format(abs($pl), 0) }}</td><td class="r">{{ $pl >= 0 ? '+' : '' }}{{ number_format($pl / max($totInv, 1) * 100, 1) }}</td><td class="r">100%</td><td></td></tr>
            </tfoot>
        </table>

        <p class="rpt-foot">Informational only — not licensed financial advice. Figures from your own captured data; verify against your official Public Mutual statement.</p>
    </div>

    <style>
        .rpt { max-width: 900px; margin: 0 auto; }
        .rpt-head { display: flex; justify-content: space-between; align-items: flex-start; }
        .rpt-head h1 { margin: 0; }
        .rpt-sub { color: #777; font-size: 12px; margin: 2px 0 0; }
        .rpt-print { padding: 6px 12px; border: 1px solid #c8102e; background: #fff; color: #c8102e; border-radius: 6px; cursor: pointer; }
        .rpt-print:hover { background: #c8102e; color: #fff; }
        .rpt-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin: 16px 0; }
        .rpt-tile { border: 1px solid #e5e5e5; border-radius: 8px; padding: 10px 12px; }
        .rpt-tile span { display: block; font-size: 11px; color: #888; }
        .rpt-tile b { font-size: 17px; }
        .rpt-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rpt-table th, .rpt-table td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; }
        .rpt-table th.r, .rpt-table td.r { text-align: right; font-variant-numeric: tabular-nums; }
        .rpt-table tfoot td { font-weight: 700; border-top: 2px solid #ccc; }
        .rpt-dot { display: inline-block; width: 10px; height: 10px; border-radius: 2px; }
        .rpt-foot { margin-top: 14px; font-size: 11px; color: #999; }
        .pos { color: #1a7f5a; } .neg { color: #c0392b; }
        @media print {
            .pm-header, .rpt-print { display: none !important; }
            body { background: #fff; }
            .rpt { max-width: none; }
        }
    </style>
@endsection
