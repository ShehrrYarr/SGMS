{{--
    Herozi sidebar. Rendered twice: fixed desktop sidebar + offcanvas for phones.
    @param array  $menu       Navigation sections
    @param string $homeRoute  Brand link
    @param string $brandTitle
    @param string $brandSub
--}}
@php
    $renderMenu = function () use ($menu) {
        $html = '<ul class="main-menu" role="menu">';
        foreach ($menu as $section) {
            $html .= '<li class="menu-title" role="presentation">'.e($section['title']).'</li>';
            foreach ($section['items'] as $item) {
                $active = request()->routeIs($item['active']) ? ' active' : '';
                $navigate = ($item['navigate'] ?? true) ? ' wire:navigate' : '';
                $badge = isset($item['badge']) ? '<span class="badge bg-danger-subtle text-danger">'.e($item['badge']).'</span>' : '';
                $html .= '<li class="slide"><a href="'.e(route($item['route'], $item['params'] ?? [])).'" class="side-menu__item'.$active.'" role="menuitem"'.$navigate.'>'
                    .'<span class="side_menu_icon"><i class="'.e($item['icon']).'"></i></span>'
                    .'<span class="side-menu__label">'.e($item['label']).'</span>'.$badge.'</a></li>';
            }
        }
        return $html.'</ul>';
    };
    $menuHtml = $renderMenu();
@endphp

<aside class="app-sidebar">
    <div class="app-sidebar-logo px-4 justify-content-center align-items-center">
        <a href="{{ route($homeRoute) }}" class="sgms-brand app-sidebar-logo-default" wire:navigate>
            <span class="sgms-brand-icon"><i class="ri-t-shirt-2-fill"></i></span>
            <span class="sgms-brand-text">{{ $brandTitle }}<small>{{ $brandSub }}</small></span>
        </a>
        <a href="{{ route($homeRoute) }}" class="app-sidebar-logo-minimize" wire:navigate>
            <span class="sgms-brand-icon"><i class="ri-t-shirt-2-fill"></i></span>
        </a>
    </div>
    <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" aria-label="Main navigation">
        {!! $menuHtml !!}
    </nav>
</aside>

<div class="offcanvas offcanvas-md offcanvas-start small-screen-sidebar" data-bs-scroll="true" tabindex="-1" id="smallScreenSidebar" aria-labelledby="smallScreenSidebarLabel">
    <div class="offcanvas-header hstack border-bottom">
        <a href="{{ route($homeRoute) }}" class="sgms-brand" wire:navigate>
            <span class="sgms-brand-icon"><i class="ri-t-shirt-2-fill"></i></span>
            <span class="sgms-brand-text">{{ $brandTitle }}<small>{{ $brandSub }}</small></span>
        </a>
        <button type="button" class="btn-close bg-transparent ms-auto" data-bs-dismiss="offcanvas" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>
    <div class="offcanvas-body p-0">
        <aside class="app-sidebar">
            <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" aria-label="Main navigation">
                {!! $menuHtml !!}
            </nav>
        </aside>
    </div>
</div>
