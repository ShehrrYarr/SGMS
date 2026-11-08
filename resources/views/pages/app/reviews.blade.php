<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\ProductReview;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Product Reviews')] class extends Component {
    use InteractsWithUi, WithPagination;

    #[Url(except: 'pending')]
    public string $status = 'pending';

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function setStatus(int $id, string $status): void
    {
        abort_unless(array_key_exists($status, ProductReview::STATUSES), 422);
        ProductReview::query()->findOrFail($id)->update(['status' => $status]);
        $this->toast($status === 'approved' ? 'Review published.' : 'Review hidden.');
    }

    public function delete(int $id): void
    {
        ProductReview::query()->findOrFail($id)->delete();
        $this->toast('Review deleted.');
    }

    public function with(): array
    {
        return [
            'reviews' => ProductReview::query()
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->with(['product:id,name,slug', 'customer:id,name,phone'])
                ->latest()
                ->paginate(15),
            'counts' => ProductReview::query()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Product reviews" subtitle="Only customers who bought a product can review it. Approve reviews before they appear in your store." />

    <ul class="nav nav-pills mb-3">
        @foreach (ProductReview::STATUSES + ['' => 'All'] as $key => $label)
            <li class="nav-item">
                <button type="button" class="nav-link {{ $status === $key ? 'active' : '' }}" wire:click="$set('status', '{{ $key }}')">
                    {{ $label }} <span class="badge {{ $key === 'pending' && ($counts[$key] ?? 0) ? 'bg-danger' : 'bg-light text-body' }} ms-1">{{ $key === '' ? $counts->sum() : ($counts[$key] ?? 0) }}</span>
                </button>
            </li>
        @endforeach
    </ul>

    <div class="row g-3">
        @forelse ($reviews as $review)
            <div class="col-lg-6" wire:key="review-{{ $review->id }}">
                <div class="card h-100 mb-0">
                    <div class="card-body d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <div class="text-warning">@for ($i = 1; $i <= 5; $i++)<i class="{{ $i <= $review->rating ? 'ri-star-fill' : 'ri-star-line' }}"></i>@endfor</div>
                                <a href="{{ route('store.product', ['tenant' => tenant()->slug, 'slug' => $review->product?->slug]) }}" target="_blank" rel="noopener" class="fw-semibold">{{ $review->product?->name }}</a>
                            </div>
                            <x-status-badge :status="$review->status" />
                        </div>
                        @if ($review->title)<h6 class="mb-0">{{ $review->title }}</h6>@endif
                        <p class="mb-0">{{ $review->body ?: 'No written review.' }}</p>
                        <div class="small text-muted mt-auto">{{ $review->customer?->name }} ({{ $review->customer?->phone }}), {{ $review->created_at->format('d M Y') }}</div>
                        <div class="d-flex gap-2 pt-2 border-top">
                            @if ($review->status !== 'approved')
                                <button type="button" class="btn btn-sm btn-success" wire:click="setStatus({{ $review->id }}, 'approved')"><i class="ri-check-line me-1"></i>Approve</button>
                            @endif
                            @if ($review->status !== 'rejected')
                                <button type="button" class="btn btn-sm btn-light-warning" wire:click="setStatus({{ $review->id }}, 'rejected')"><i class="ri-eye-off-line me-1"></i>Hide</button>
                            @endif
                            <button type="button" class="btn btn-sm btn-light-danger ms-auto" wire:click="delete({{ $review->id }})" wire:confirm="Delete this review permanently?"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="card"><div class="card-body"><x-empty-state icon="ri-star-smile-line" title="No reviews here" text="Reviews from customers appear here for approval." /></div></div></div>
        @endforelse
    </div>
    @if ($reviews->hasPages())<div class="mt-3">{{ $reviews->links() }}</div>@endif
</div>
