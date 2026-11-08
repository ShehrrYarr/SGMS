@php($sf = storefront())
@if ($note = $sf->settings->get('announcement'))
    <div class="pg-announce">{{ $note }}</div>
@endif
<header class="pg-header">
    <div class="sf-container pg-header__bar">
        <button type="button" class="sf-iconbtn sf-only-mobile" @click="$store.shop.openMenu()" aria-label="Open menu"><x-store.icon name="menu" /></button>
        @include('store.partials.logo', ['class' => 'pg-logo'])
        <nav class="pg-nav sf-only-desktop" aria-label="Categories">
            @foreach ($sf->categories()->take(5) as $cat)
                <a href="{{ route('store.category', $cat->slug) }}" @class(['is-on' => request()->route('category')?->id === $cat->id])>{{ $cat->name }}</a>
            @endforeach
            <a href="{{ route('store.shop', ['sale' => 1]) }}" class="pg-nav__sale">Sale</a>
        </nav>
        <form action="{{ route('store.shop') }}" method="get" class="sf-search pg-search sf-only-desktop" role="search">
            <x-store.icon name="search" />
            <input type="search" name="q" placeholder="Search" aria-label="Search products" value="{{ request('q') }}">
        </form>
        @include('store.partials.header-icons')
    </div>
    <svg class="pg-wave pg-wave--header" viewBox="0 0 1440 24" preserveAspectRatio="none" aria-hidden="true"><path d="M0 0h1440v8c-120 16-240 16-360 0S840-8 720 8 480 24 360 8 120-8 0 8Z"/></svg>
</header>
