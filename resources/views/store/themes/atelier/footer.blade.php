@php($sf = storefront())
<footer class="at-footer">
    <div class="sf-container">
        <p class="at-footer__name">{{ $sf->name() }}</p>
        <div class="at-footer__grid">
            <div class="at-footer__about">
                @if ($tagline = $sf->settings->get('tagline'))<p>{{ $tagline }}</p>@endif
                @include('store.partials.socials')
            </div>
            @include('store.partials.footer-columns')
        </div>
        <div class="at-footer__base">
            <span>&copy; {{ now()->year }} {{ $sf->name() }}</span>
            <span>All prices in PKR</span>
        </div>
    </div>
</footer>
