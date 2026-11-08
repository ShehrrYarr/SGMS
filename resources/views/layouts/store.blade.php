@php
    $sf = storefront();
    $settings = $sf->settings;
    $pageTitle = trim($__env->yieldContent('title')) ?: (string) ($title ?? '');
    $pageTitle = $pageTitle !== '' ? $pageTitle.' | '.$sf->name() : $sf->name().($settings->get('tagline') ? ' | '.$settings->get('tagline') : '');
    $description = trim($__env->yieldContent('description')) ?: ($settings->get('tagline') ?: 'Shop '.$sf->name().' online. Delivery across Pakistan.');
    $ogImage = trim($__env->yieldContent('og_image')) ?: $settings->logoUrl();
    $version = fn (string $file) => @filemtime(public_path($file));
@endphp
<!doctype html>
<html lang="en" data-theme="{{ $sf->theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 160) }}">
    <link rel="canonical" href="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ $sf->name() }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 200) }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($ogImage)<meta property="og:image" content="{{ $ogImage }}">@endif
    <meta name="theme-color" content="{{ $settings->accent($sf->theme) }}">
    @if (! empty($storePreview) || trim($__env->yieldContent('noindex')))<meta name="robots" content="noindex">@endif
    <link rel="icon" href="{{ $settings->faviconUrl() ?? asset('assets/images/Favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="{{ \App\Support\Store\Themes::fontsUrl($sf->theme) }}">
    <link rel="stylesheet" href="{{ asset('store/store.css') }}?v={{ $version('store/store.css') }}">
    <link rel="stylesheet" href="{{ asset('store/themes/'.$sf->theme.'.css') }}?v={{ $version('store/themes/'.$sf->theme.'.css') }}">
    <style>:root{--accent:{{ $settings->accent($sf->theme) }};--accent-ink:{{ $settings->accentInk($sf->theme) === '#000000' ? 'var(--on-light, #000)' : '#fff' }}}</style>
    @livewireStyles
    @stack('head')
</head>
<body class="sf sf--{{ $sf->theme }}" x-data>
    <a class="sf-skip" href="#main">Skip to content</a>

    @if (! empty($storePreview))
        <div class="sf-preview" role="status">
            <x-store.icon name="eye" /> Preview: your store is not live yet. Only signed-in staff can see it.
            <a href="{{ route('app.settings.store') }}">Open store settings</a>
        </div>
    @endif

    @if ($sf->previewTheme)
        <div class="sf-preview" role="status">
            <x-store.icon name="eye" /> Previewing the {{ $sf->themeMeta()['name'] }} theme. Shoppers still see your current theme.
            <a href="{{ route('app.settings.appearance') }}">Choose a theme</a>
            <a href="{{ url()->current() }}?preview_theme=off">Exit preview</a>
        </div>
    @endif

    @include($sf->view('header'))

    <main id="main" class="sf-main">
        @include('store.partials.flash')
        @hasSection('content')
            @yield('content')
        @else
            {{ $slot ?? '' }}
        @endif
    </main>

    @include($sf->view('footer'))

    {{-- Bag drawer (filled by script; links to the bag page without it) --}}
    <div class="sf-overlay" x-show="$store.shop.drawer || $store.shop.menu" x-transition.opacity x-cloak @click="$store.shop.close()"></div>
    <aside class="sf-drawer" role="dialog" aria-modal="true" aria-label="Your bag" x-show="$store.shop.drawer" x-cloak
           x-transition:enter-start="is-off" x-transition:leave-end="is-off" @keydown.escape.window="$store.shop.close()">
        <div class="sf-drawer__head">
            <h2 class="sf-drawer__title">Your bag</h2>
            <button type="button" class="sf-iconbtn" @click="$store.shop.close()" aria-label="Close bag"><x-store.icon name="close" /></button>
        </div>
        <div class="sf-drawer__body" id="sf-drawer-body" x-ref="drawer">
            <div class="sf-drawer__loading"><span class="sf-spinner"></span></div>
        </div>
    </aside>

    {{-- Mobile navigation --}}
    <aside class="sf-drawer sf-drawer--left" role="dialog" aria-modal="true" aria-label="Menu" x-show="$store.shop.menu" x-cloak
           x-transition:enter-start="is-off" x-transition:leave-end="is-off">
        <div class="sf-drawer__head">
            <span class="sf-drawer__title">{{ $sf->name() }}</span>
            <button type="button" class="sf-iconbtn" @click="$store.shop.close()" aria-label="Close menu"><x-store.icon name="close" /></button>
        </div>
        <nav class="sf-drawer__body sf-mobilenav" aria-label="Mobile">
            <form action="{{ route('store.shop') }}" method="get" class="sf-search sf-search--block" role="search">
                <x-store.icon name="search" />
                <input type="search" name="q" placeholder="Search products" aria-label="Search products" value="{{ request('q') }}">
            </form>
            <a href="{{ route('store.shop') }}">Shop all</a>
            @foreach ($sf->categories() as $navCategory)
                <a href="{{ route('store.category', $navCategory->slug) }}">{{ $navCategory->name }}</a>
            @endforeach
            <a href="{{ route('store.shop', ['sale' => 1]) }}">Sale</a>
            <hr>
            <a href="{{ route('store.wishlist') }}"><x-store.icon name="heart" /> Wishlist</a>
            <a href="{{ route('store.track') }}"><x-store.icon name="package" /> Track an order</a>
            <a href="{{ $sf->customer() ? route('portal.dashboard') : route('portal.login') }}"><x-store.icon name="user" /> {{ $sf->customer() ? 'My account' : 'Sign in' }}</a>
        </nav>
    </aside>

    <div class="sf-toasts" aria-live="polite" aria-atomic="true">
        <template x-for="toast in $store.shop.toasts" :key="toast.id">
            <div class="sf-toast" :class="toast.error && 'sf-toast--error'" x-text="toast.text" x-transition.opacity></div>
        </template>
    </div>

    @if ($whatsapp = $settings->whatsappUrl('Hi '.$sf->name().', I have a question about '.url()->current()))
        <a class="sf-fab" href="{{ $whatsapp }}" target="_blank" rel="noopener" aria-label="Chat with us on WhatsApp"><x-store.icon name="whatsapp" /></a>
    @endif

    <script>
        window.SF = @js([
            'cartCount' => $sf->cartCount(),
            'wishCount' => $sf->wishlist()->count(),
            'drawerUrl' => route('store.cart.drawer'),
            'cartUrl' => route('store.cart'),
            'currency' => tenant()->currency_symbol ?: 'Rs.',
        ]);
    </script>
    <script src="{{ asset('store/store.js') }}?v={{ $version('store/store.js') }}"></script>
    @livewireScripts
    @stack('scripts')
</body>
</html>
