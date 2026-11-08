@php($sf = storefront())
@if ($note = $sf->settings->get('announcement'))
    <div class="ln-announce">{{ $note }}</div>
@endif
<header class="ln-header">
    <div class="sf-container ln-header__bar">
        <button type="button" class="sf-iconbtn sf-only-mobile" @click="$store.shop.openMenu()" aria-label="Open menu"><x-store.icon name="menu" /></button>
        @include('store.partials.logo', ['class' => 'ln-logo'])
        <form action="{{ route('store.shop') }}" method="get" class="sf-search ln-search sf-only-desktop" role="search">
            <x-store.icon name="search" />
            <input type="search" name="q" placeholder="Search shirts, denim, hoodies" aria-label="Search products" value="{{ request('q') }}">
        </form>
        @include('store.partials.header-icons')
    </div>
    <nav class="ln-nav" aria-label="Categories">
        <div class="sf-container ln-nav__inner">
            <a href="{{ route('store.shop') }}" @class(['is-on' => request()->routeIs('store.shop') && ! request('sale')])>Shop all</a>
            @foreach ($sf->categories() as $cat)
                <a href="{{ route('store.category', $cat->slug) }}" @class(['is-on' => request()->route('category')?->id === $cat->id])>{{ $cat->name }}</a>
            @endforeach
            <a href="{{ route('store.shop', ['sale' => 1]) }}" class="ln-nav__sale">Sale</a>
        </div>
    </nav>
</header>
