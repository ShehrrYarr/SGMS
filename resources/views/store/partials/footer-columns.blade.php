{{-- Footer link columns shared by themes. --}}
@php($sf = storefront())
<div class="sf-footcol">
    <h2 class="sf-footcol__title">Shop</h2>
    <ul class="sf-footer-links">
        <li><a href="{{ route('store.shop') }}">All products</a></li>
        @foreach ($sf->categories()->take(6) as $cat)
            <li><a href="{{ route('store.category', $cat->slug) }}">{{ $cat->name }}</a></li>
        @endforeach
        <li><a href="{{ route('store.shop', ['sale' => 1]) }}">Sale</a></li>
    </ul>
</div>
<div class="sf-footcol">
    <h2 class="sf-footcol__title">Help</h2>
    <ul class="sf-footer-links">
        <li><a href="{{ route('store.track') }}">Track an order</a></li>
        <li><a href="{{ $sf->customer() ? route('portal.dashboard') : route('portal.login') }}">{{ $sf->customer() ? 'My account' : 'Sign in' }}</a></li>
        <li><a href="{{ route('store.wishlist') }}">Wishlist</a></li>
        <li><a href="{{ route('store.cart') }}">Your bag</a></li>
    </ul>
</div>
<div class="sf-footcol">
    <h2 class="sf-footcol__title">Visit or call</h2>
    <ul class="sf-footer-links sf-footer-contact">
        @if ($sf->tenant->address)<li><x-store.icon name="pin" /> {{ $sf->tenant->address }}</li>@endif
        @if ($sf->tenant->phone)<li><x-store.icon name="phone" /> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $sf->tenant->phone) }}">{{ $sf->tenant->phone }}</a></li>@endif
        @if ($sf->tenant->owner_email)<li><x-store.icon name="mail" /> <a href="mailto:{{ $sf->tenant->owner_email }}">{{ $sf->tenant->owner_email }}</a></li>@endif
        @if ($chat = $sf->settings->whatsappUrl())<li><x-store.icon name="whatsapp" /> <a href="{{ $chat }}" target="_blank" rel="noopener">WhatsApp us</a></li>@endif
    </ul>
</div>
