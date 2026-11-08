@php
    /** Platform super admin layout. */
    $admin = auth('superadmin')->user();
    $initials = collect(explode(' ', $admin->name))->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
@endphp
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes')>
<head>
    @include('layouts.partials.head', ['charts' => true, 'brand' => 'SGMS Platform'])
</head>
<body>
    @include('layouts.partials.header', [
        'userName' => $admin->name,
        'userRole' => 'Super Admin',
        'userInitials' => $initials,
        'logoutRoute' => 'admin.logout',
        'profileRoute' => 'admin.profile',
    ])

    @include('layouts.partials.sidebar', [
        'menu' => \App\Support\Navigation::admin(),
        'homeRoute' => 'admin.dashboard',
        'brandTitle' => 'SGMS Platform',
        'brandSub' => 'Super Admin',
    ])

    <main class="app-wrapper">
        <div class="app-container">
            {{ $slot }}
        </div>
        <footer class="sgms-footer">
            <div class="container-fluid text-center">&copy; {{ date('Y') }} {{ config('app.name') }} · Garments SaaS Platform</div>
        </footer>
    </main>

    @include('layouts.partials.flash')
</body>
</html>
