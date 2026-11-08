{{-- Account, wishlist and bag buttons used by every theme header. --}}
@php($sf = storefront())
<div class="sf-icons">
    <a href="{{ $sf->customer() ? route('portal.dashboard') : route('portal.login') }}" class="sf-iconbtn sf-only-desktop" aria-label="{{ $sf->customer() ? 'My account' : 'Sign in' }}"><x-store.icon name="user" /></a>
    <a href="{{ route('store.wishlist') }}" class="sf-iconbtn" aria-label="Wishlist">
        <x-store.icon name="heart" />
        <span class="sf-badge" x-show="$store.shop.wishCount > 0" x-text="$store.shop.wishCount" @if (! $sf->wishlist()->count()) x-cloak @endif>{{ $sf->wishlist()->count() }}</span>
    </a>
    <a href="{{ route('store.cart') }}" class="sf-iconbtn" aria-label="Bag" @click.prevent="$store.shop.openBag()">
        <x-store.icon name="bag" />
        <span class="sf-badge" aria-live="polite" x-show="$store.shop.cartCount > 0" x-text="$store.shop.cartCount" @if (! $sf->cartCount()) x-cloak @endif>{{ $sf->cartCount() }}</span>
    </a>
</div>
