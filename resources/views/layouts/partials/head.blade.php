<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#0d6efd">
<meta name="robots" content="noindex">
<title>{{ $title ?? 'Dashboard' }} · {{ $brand ?? config('app.name') }}</title>
<link rel="shortcut icon" href="{{ asset('assets/images/Favicon.png') }}">
<link rel="stylesheet" href="{{ asset('assets/css/icons.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/libs/sweetalert2/sweetalert2.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/bootstrap.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/app.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/sgms.css') }}?v={{ @filemtime(public_path('css/sgms.css')) }}">
<script src="{{ asset('assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
<script src="{{ asset('js/sgms.js') }}?v={{ @filemtime(public_path('js/sgms.js')) }}"></script>
@if (! empty($charts))
    <script src="{{ asset('assets/libs/apexcharts/apexcharts.min.js') }}"></script>
@endif
@if (! empty($pos))
    <script src="{{ asset('js/pos.js') }}?v={{ @filemtime(public_path('js/pos.js')) }}"></script>
@endif
<script>window.SGMS_CURRENCY = @js(tenant()?->currency_symbol ?? 'Rs.');</script>
