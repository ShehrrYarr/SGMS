@php($sf = storefront())
<footer class="ln-footer">
    <div class="sf-container ln-footer__grid">
        <div class="ln-footer__brand">
            @include('store.partials.logo')
            @if ($tagline = $sf->settings->get('tagline'))<p>{{ $tagline }}</p>@endif
            @include('store.partials.socials')
        </div>
        @include('store.partials.footer-columns')
    </div>
    <div class="sf-container ln-footer__base">
        <span>&copy; {{ now()->year }} {{ $sf->name() }}</span>
        <span>Prices in PKR, including tax where applicable</span>
    </div>
</footer>
