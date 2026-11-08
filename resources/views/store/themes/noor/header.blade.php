@php($sf = storefront())
@if ($note = $sf->settings->get('announcement'))
    <div class="nr-announce">{{ $note }}</div>
@endif
<header class="nr-header">
    <div class="sf-container nr-header__bar">
        <div class="nr-header__left">
            <button type="button" class="sf-iconbtn sf-only-mobile" @click="$store.shop.openMenu()" aria-label="Open menu"><x-store.icon name="menu" /></button>
            <form action="{{ route('store.shop') }}" method="get" class="sf-search nr-search sf-only-desktop" role="search">
                <x-store.icon name="search" />
                <input type="search" name="q" placeholder="Search lawn, kurta, festive" aria-label="Search products" value="{{ request('q') }}">
            </form>
        </div>
        @include('store.partials.logo', ['class' => 'nr-logo'])
        @include('store.partials.header-icons')
    </div>
    <nav class="nr-nav sf-only-desktop" aria-label="Categories">
        <a href="{{ route('store.shop', ['sort' => 'newest']) }}">New arrivals</a>
        @foreach ($sf->categories() as $cat)
            <a href="{{ route('store.category', $cat->slug) }}" @class(['is-on' => request()->route('category')?->id === $cat->id])>{{ $cat->name }}</a>
        @endforeach
        <a href="{{ route('store.shop', ['sale' => 1]) }}" class="nr-nav__sale">Sale</a>
    </nav>
</header>
