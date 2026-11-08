{{-- Tabs shared by the three shop settings pages. --}}
<ul class="nav nav-pills nav-tabs-scroll mb-3">
    @foreach (['app.settings' => ['General', 'ri-store-3-line'], 'app.settings.store' => ['Online store', 'ri-shopping-bag-3-line'], 'app.settings.appearance' => ['Appearance', 'ri-palette-line']] as $route => [$label, $icon])
        <li class="nav-item">
            <a href="{{ route($route) }}" class="nav-link {{ $current === $route ? 'active' : '' }}" wire:navigate><i class="{{ $icon }} me-1"></i>{{ $label }}</a>
        </li>
    @endforeach
    <li class="nav-item ms-auto">
        <a href="{{ tenant()->storeUrl() }}" class="nav-link" target="_blank" rel="noopener"><i class="ri-external-link-line me-1"></i>View store</a>
    </li>
</ul>
