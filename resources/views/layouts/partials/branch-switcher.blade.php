{{-- Branch context + quick POS access for the staff header. --}}
<a href="{{ route('app.pos') }}" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1 me-1">
    <i class="ri-shopping-cart-2-line"></i><span class="d-none d-sm-inline">POS</span>
</a>

@if ($user->isOwner() && $branches->count() > 1)
    <div class="dropdown branch-switcher">
        <button type="button" class="btn btn-light-primary btn-sm d-inline-flex align-items-center gap-1" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="ri-store-2-line"></i>
            <span class="d-none d-md-inline text-truncate" style="max-width: 140px">{{ $currentBranch?->name ?? 'All branches' }}</span>
            <i class="ri-arrow-down-s-line"></i>
        </button>
        <div class="dropdown-menu dropdown-menu-end">
            <h6 class="dropdown-header">Working branch</h6>
            <form method="POST" action="{{ route('app.branch.switch') }}">
                @csrf
                <button type="submit" name="branch" value="all" class="dropdown-item d-flex align-items-center gap-2 {{ ! $currentBranch ? 'active' : '' }}">
                    <i class="ri-building-line"></i> All branches
                </button>
                @foreach ($branches as $branch)
                    <button type="submit" name="branch" value="{{ $branch->id }}" class="dropdown-item d-flex align-items-center gap-2 {{ $currentBranch?->id === $branch->id ? 'active' : '' }}">
                        <i class="ri-store-2-line"></i>
                        <span class="flex-grow-1">{{ $branch->name }}</span>
                        @if ($branch->is_main)<span class="badge bg-primary-subtle text-primary">Main</span>@endif
                    </button>
                @endforeach
            </form>
        </div>
    </div>
@elseif ($currentBranch)
    <span class="badge bg-light text-body border d-none d-md-inline-flex align-items-center gap-1 py-2 px-2">
        <i class="ri-store-2-line"></i>{{ $currentBranch->name }}
    </span>
@endif
