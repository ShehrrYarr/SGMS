@php($sf = storefront())
<footer class="pg-footer">
    <svg class="pg-wave pg-wave--footer" viewBox="0 0 1440 40" preserveAspectRatio="none" aria-hidden="true"><path d="M0 40V20C120 0 240 0 360 20s240 20 360 0 240-20 360 0 240 20 360 0v20Z"/></svg>
    <div class="pg-footer__inner">
        <div class="sf-container pg-footer__grid">
            <div class="pg-footer__brand">
                @include('store.partials.logo')
                @if ($tagline = $sf->settings->get('tagline'))<p>{{ $tagline }}</p>@endif
                @include('store.partials.socials')
            </div>
            @include('store.partials.footer-columns')
        </div>
        <div class="sf-container pg-footer__base">
            <span>&copy; {{ now()->year }} {{ $sf->name() }}</span>
            <span>Made for little ones, delivered to your door</span>
        </div>
    </div>
</footer>
