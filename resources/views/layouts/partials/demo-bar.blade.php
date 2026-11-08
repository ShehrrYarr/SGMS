{{-- Public demo notice for the one-click demo accounts. --}}
@if (\App\Support\Demo::protects($account ?? null))
    <div class="alert alert-light-info d-flex flex-wrap align-items-center gap-2 py-2 px-3 mb-4 small" role="note">
        <i class="ri-flask-line fs-5"></i>
        <span class="flex-grow-1">You're exploring the SGMS demo. Anything you change is cleared every night at {{ \App\Support\Demo::resetTime() }}.</span>
        <a href="{{ route('landing') }}#demo" class="fw-semibold">Try another role</a>
    </div>
@endif
