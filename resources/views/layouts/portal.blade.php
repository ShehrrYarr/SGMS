@php
    /** Customer account layout (/{shop}/account). */
    $customer = auth('customer')->user();
    $shop = tenant();
    $initials = collect(explode(' ', $customer->name))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
@endphp
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes')>
<head>
    @include('layouts.partials.head', ['brand' => $shop->name])
</head>
<body>
    @include('layouts.partials.header', [
        'userName' => $customer->name,
        'userRole' => $customer->phone,
        'userInitials' => $initials,
        'logoutRoute' => 'portal.logout',
        'profileRoute' => 'portal.profile',
        'extra' => $shop->storeIsLive() ? '<a href="'.e($shop->storeUrl()).'" class="btn btn-sm btn-light-primary"><i class="ri-shopping-bag-3-line me-1"></i><span class="d-none d-sm-inline">Shop online</span></a>' : null,
    ])

    @include('layouts.partials.sidebar', [
        'menu' => \App\Support\Navigation::portal(),
        'homeRoute' => 'portal.dashboard',
        'brandTitle' => $shop->name,
        'brandSub' => 'My account',
    ])

    <main class="app-wrapper">
        <div class="app-container">
            {{ $slot }}
        </div>
        <footer class="sgms-footer">
            <div class="container-fluid text-center">
                &copy; {{ date('Y') }} {{ $shop->name }}@if ($shop->phone) · {{ $shop->phone }}@endif
            </div>
        </footer>
    </main>

    @include('layouts.partials.flash')
</body>
</html>
