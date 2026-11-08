{{--
    Herozi header.
    @param string      $userName, $userRole, $userInitials
    @param string      $logoutRoute, $profileRoute
    @param array       $logoutParams
    @param string|null $extra   Extra right-side HTML slot (branch switcher, POS button)
--}}
<header class="app-header">
    <div class="container-fluid">
        <div class="nav-header">
            <div class="header-left hstack gap-2 gap-sm-3">
                <button type="button" class="btn btn-light-light icon-btn sidebar-toggle d-none d-md-block" aria-label="Toggle sidebar">
                    <i class="ri-menu-2-fill"></i>
                </button>
                <button class="btn btn-light-light icon-btn d-md-none" id="smallScreenSidebarLabel" type="button" data-bs-toggle="offcanvas" data-bs-target="#smallScreenSidebar" aria-controls="smallScreenSidebar" aria-label="Open menu">
                    <i class="ri-menu-2-fill"></i>
                </button>
                <div class="d-none d-lg-block min-w-0">
                    <h6 class="mb-0 fw-semibold text-truncate">{{ $title ?? '' }}</h6>
                </div>
            </div>

            <div class="header-right hstack gap-1 gap-sm-2">
                {!! $extra ?? '' !!}

                <button type="button" class="btn icon-btn btn-text-primary rounded-circle" data-sgms-theme-toggle aria-label="Toggle dark mode" title="Light / dark">
                    <i class="ri-contrast-2-line fs-16"></i>
                </button>
                <button type="button" class="btn icon-btn btn-text-primary rounded-circle d-none d-sm-inline-flex" data-sgms-fullscreen aria-label="Fullscreen" title="Fullscreen">
                    <i class="ri-fullscreen-line fs-16"></i>
                </button>

                <div class="dropdown profile-dropdown features-dropdown">
                    <button type="button" class="btn profile-btn shadow-none px-0 hstack gap-0 gap-sm-2" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar avatar-item avatar-title bg-primary-subtle text-primary fw-semibold rounded-circle">{{ $userInitials }}</span>
                        <span class="d-none d-xl-block text-start">
                            <span class="h6 d-block fw-semibold mb-0">{{ $userName }}</span>
                            <span class="d-block fs-12 text-muted">{{ $userRole }}</span>
                        </span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="px-3 py-2 d-xl-none">
                            <div class="fw-semibold">{{ $userName }}</div>
                            <div class="fs-12 text-muted">{{ $userRole }}</div>
                        </div>
                        <div class="dropdown-divider d-xl-none"></div>
                        <a class="dropdown-item" href="{{ route($profileRoute) }}" wire:navigate><i class="ri-user-line me-2"></i>Profile & Password</a>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route($logoutRoute, $logoutParams ?? []) }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger"><i class="ri-logout-box-r-line me-2"></i>Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
