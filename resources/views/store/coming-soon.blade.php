@php
    $settings = $tenant->store();
    $theme = $settings->theme();
@endphp
<!doctype html>
<html lang="en" data-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $tenant->name }} | Opening soon</title>
    <link rel="icon" href="{{ $settings->faviconUrl() ?? asset('assets/images/Favicon.png') }}">
    <link rel="stylesheet" href="{{ \App\Support\Store\Themes::fontsUrl($theme) }}">
    <link rel="stylesheet" href="{{ asset('store/store.css') }}">
    <link rel="stylesheet" href="{{ asset('store/themes/'.$theme.'.css') }}">
    <style>:root{--accent:{{ $settings->accent() }};--accent-ink:{{ $settings->accentInk() === '#000000' ? 'var(--on-light, #000)' : '#fff' }}}</style>
</head>
<body class="sf sf--{{ $theme }}">
    <main class="sf-soon">
        @if ($settings->logoUrl())
            <img src="{{ $settings->logoUrl() }}" alt="{{ $tenant->name }}" class="sf-soon__logo">
        @else
            <p class="sf-soon__name">{{ $tenant->name }}</p>
        @endif
        <h1 class="sf-soon__title">Our online store opens soon.</h1>
        <p class="sf-muted">Visit us in store{{ $tenant->address ? ' at '.$tenant->address : '' }}{{ $tenant->phone ? ' or call '.$tenant->phone : '' }}.</p>
        <div class="sf-soon__links">
            @if ($chat = $settings->whatsappUrl())<a href="{{ $chat }}" class="sf-btn sf-btn--accent" target="_blank" rel="noopener">Message us on WhatsApp</a>@endif
            <a href="{{ route('portal.login', $tenant->slug) }}" class="sf-btn sf-btn--ghost">Customer account</a>
        </div>
    </main>
</body>
</html>
