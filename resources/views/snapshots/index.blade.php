@extends('layouts.app')

@section('title', 'pmoai — analyses')
@section('body-class', 'page-index')

@if ($snapshots->whereNotIn('status', ['recommended', 'failed', 'stored'])->isNotEmpty())
    @push('head')
        <meta http-equiv="refresh" content="5">
    @endpush
@endif

@section('content')
    <h1>pmoai — fund analyses</h1>
    <p><a class="btn" href="{{ route('snapshots.create') }}">+ New analysis (manual paste)</a>
       &nbsp;<small>or send via the Tampermonkey userscript</small></p>

    @if ($snapshots->isEmpty())
        <p>No analyses yet. Capture via the userscript or paste manually.</p>
    @else
        <table>
            <tr><th>#</th><th>When</th><th>Status</th><th>Funds</th><th>Recs</th><th></th></tr>
            @foreach ($snapshots as $s)
                <tr>
                    <td>{{ $s->id }}</td>
                    <td>{{ $s->created_at->diffForHumans() }}<br><small>{{ $s->created_at->format('Y-m-d H:i') }}</small></td>
                    <td><span class="s {{ $s->status }}">{{ strtoupper($s->status) }}</span></td>
                    <td>{{ $fundCount }}</td>
                    <td>{{ $s->recommendations_count }}</td>
                    <td><a href="{{ route('snapshots.show', $s) }}">view →</a></td>
                </tr>
            @endforeach
        </table>
        <p><small>Page auto-refreshes while any analysis is still processing.</small></p>
    @endif
@endsection
