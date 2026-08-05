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

    <script src="{{ asset('vendor/sweetalert2.all.min.js') }}?v={{ filemtime(public_path('vendor/sweetalert2.all.min.js')) }}"></script>
    <script>
    // Any form with data-confirm gets a SweetAlert confirm before submitting.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches('[data-confirm]') || form.dataset.swalOk === '1') return;
        e.preventDefault();
        Swal.fire({
            title: form.dataset.confirm || 'Are you sure?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: form.dataset.confirmYes || 'Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#c8102e',
            cancelButtonColor: '#6b6f76',
            reverseButtons: true,
        }).then(function (r) {
            if (r.isConfirmed) { form.dataset.swalOk = '1'; form.submit(); }
        });
    }, true);
    </script>
</body>
</html>
