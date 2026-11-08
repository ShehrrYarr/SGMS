{{-- Split auth card: brand panel + form. --}}
@props(['heading', 'tagline' => null, 'badge' => null, 'features' => []])

<div class="card auth-card">
    <div class="row g-0">
        <div class="col-lg-5 d-none d-lg-flex auth-side p-5 flex-column justify-content-between">
            <div>
                <span class="sgms-brand text-white">
                    <span class="sgms-brand-icon bg-white text-primary"><i class="ri-t-shirt-2-fill"></i></span>
                    <span class="sgms-brand-text text-white">{{ $badge ?? config('app.name') }}<small class="text-white-50">Garments Inventory & POS</small></span>
                </span>
                <h3 class="text-white fw-semibold mt-5 mb-3">{{ $tagline ?? 'Run every branch from one place.' }}</h3>
                @foreach ($features as [$icon, $text])
                    <div class="feature"><i class="{{ $icon }}"></i><div class="pt-1">{{ $text }}</div></div>
                @endforeach
            </div>
            <div class="small text-white-50 position-relative" style="z-index:1">&copy; {{ date('Y') }} {{ config('app.name') }}</div>
        </div>
        <div class="col-lg-7">
            <div class="card-body p-4 p-sm-5">
                <div class="d-lg-none mb-4">
                    <span class="sgms-brand"><span class="sgms-brand-icon"><i class="ri-t-shirt-2-fill"></i></span><span class="sgms-brand-text">{{ $badge ?? config('app.name') }}</span></span>
                </div>
                <h4 class="fw-semibold mb-1">{{ $heading }}</h4>
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
