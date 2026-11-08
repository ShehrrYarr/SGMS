@php
    /** Staff area layout (owners, managers, cashiers). */
    $user = auth('web')->user();
    $context = tenancy();
    $shop = $context->tenant();
    $branches = $context->branches();
    $currentBranch = $branches->firstWhere('id', $context->branchId());

    $extra = view('layouts.partials.branch-switcher', [
        'user' => $user,
        'branches' => $branches,
        'currentBranch' => $currentBranch,
    ])->render();

    // New online order alerts for owners and managers.
    if ($user->canManage()) {
        $extra = \Livewire\Livewire::mount('store.order-bell').$extra;
    }
@endphp
<!DOCTYPE html>
<html @include('layouts.partials.html-attributes')>
<head>
    @include('layouts.partials.head', ['charts' => true, 'pos' => true, 'brand' => $shop?->name])
</head>
<body>
    @include('layouts.partials.header', [
        'userName' => $user->name,
        'userRole' => $user->role_label.($user->branch && ! $user->isOwner() ? ' · '.$user->branch->name : ''),
        'userInitials' => $user->initials,
        'logoutRoute' => 'app.logout',
        'profileRoute' => 'app.profile',
        'extra' => $extra,
    ])

    @include('layouts.partials.sidebar', [
        'menu' => \App\Support\Navigation::staff($user),
        'homeRoute' => 'app.dashboard',
        'brandTitle' => $shop?->name ?? config('app.name'),
        'brandSub' => $currentBranch?->name ?? 'All branches',
    ])

    <main class="app-wrapper">
        <div class="app-container">
            @include('layouts.partials.demo-bar', ['account' => $user])
            {{ $slot }}
        </div>
        <footer class="sgms-footer">
            <div class="container-fluid text-center">
                &copy; {{ date('Y') }} {{ $shop?->name }} · Powered by {{ config('app.name') }}
            </div>
        </footer>
    </main>

    @include('layouts.partials.flash')
</body>
</html>
