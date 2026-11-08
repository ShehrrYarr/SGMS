@php($sf = storefront())
<footer class="nr-footer">
    <div class="sf-container nr-footer__grid">
        <div class="nr-footer__brand">
            @include('store.partials.logo')
            @if ($tagline = $sf->settings->get('tagline'))<p>{{ $tagline }}</p>@endif
            @include('store.partials.socials')
        </div>
        @include('store.partials.footer-columns')
    </div>
    <div class="sf-container nr-footer__base">
        <span>&copy; {{ now()->year }} {{ $sf->name() }}</span>
        <span>Delivering across Pakistan</span>
    </div>
</footer>
