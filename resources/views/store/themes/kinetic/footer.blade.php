@php($sf = storefront())
<footer class="kn-footer">
    <div class="sf-container">
        <div class="kn-footer__grid">
            <div class="kn-footer__brand">
                @if ($tagline = $sf->settings->get('tagline'))<p>{{ $tagline }}</p>@endif
                @include('store.partials.socials')
            </div>
            @include('store.partials.footer-columns')
        </div>
        <p class="kn-footer__name" aria-hidden="true">{{ $sf->name() }}</p>
        <div class="kn-footer__base">
            <span>&copy; {{ now()->year }} {{ $sf->name() }}</span>
            <span>Shipped from our store, nationwide</span>
        </div>
    </div>
</footer>
