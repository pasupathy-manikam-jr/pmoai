<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @stack('head')
    <title>@yield('title', 'PMFAI')</title>
    {{-- filemtime as cache-buster: every CSS edit gets a fresh URL, browsers
         can never serve a stale stylesheet again --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="@yield('body-class')">
    @include('partials.header')
    @yield('content')
</body>
</html>
