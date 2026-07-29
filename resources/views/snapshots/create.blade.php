@extends('layouts.app')

@section('title', 'PMFAI — Fund analysis')
@section('body-class', 'page-create')

@section('content')
    <h1>Fund analysis</h1>
    <p><small>Paste the fund price page, add your goals/feedback. AI extracts funds, checks current market &amp; geopolitical context, recommends buy/hold/sell. Informational only — not licensed financial advice.</small></p>

    @if ($errors->any())
        <div class="err">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('snapshots.store') }}">
        @csrf
        <label for="prices">Fund Prices tab <small>(required — paste or file path)</small></label>
        <textarea id="prices" name="prices" placeholder="Copy the Fund Prices tab (or paste a file path like /Users/you/Desktop/prices.csv)…">{{ old('prices') }}</textarea>

        <label for="performance">Fund Performance tab <small>(optional — gives YTD/1Y/3Y/5Y/10Y returns; grounds the advice)</small></label>
        <textarea id="performance" name="performance" placeholder="Copy the Fund Performance tab (or a file path)…">{{ old('performance') }}</textarea>

        <label for="info">Fund Info tab <small>(optional — category, risk, fund size)</small></label>
        <textarea id="info" name="info" placeholder="Copy the Fund Info tab (or a file path)…">{{ old('info') }}</textarea>

        <label for="feedback">Your goals / feedback</label>
        <input id="feedback" name="feedback" value="{{ old('feedback') }}"
               placeholder="e.g. Shariah only, 5-year horizon, want growth. I own Public Growth Fund and PGF — keep or sell?">

        <button type="submit">Analyse</button>
    </form>
@endsection
