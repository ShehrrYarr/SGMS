<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\WithSorting;
use App\Models\Inventory;
use App\Models\Product;
use App\Support\Catalog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Products')] class extends Component {
    use InteractsWithUi, WithPagination, WithSorting;

    protected array $sortable = ['name', 'sku', 'base_price', 'created_at'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $brand = '';

    #[Url(except: '')]
    public string $status = '';

    public function updating($name): void
    {
        if (in_array($name, ['search', 'category', 'brand', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggleStatus(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        $product->update(['status' => $product->status === 'active' ? 'inactive' : 'active']);
        $this->toast("{$product->name} is now {$product->status}.");
    }

    public function delete(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        $stock = (int) Inventory::query()->whereIn('product_variant_id', $product->variants()->pluck('id'))->sum('quantity');

        if ($stock > 0) {
            $this->toast("{$product->name} still has {$stock} pieces in stock. Adjust stock to zero first.", 'error');

            return;
        }

        $product->variants()->delete();
        $product->delete();
        $this->toast('Product archived. Sales history is kept.');
    }

    public function with(): array
    {
        $branchIds = tenancy()->branchScopeIds();

        $products = Product::query()
            ->search($this->search)
            ->when($this->category, fn ($q) => $q->where('category_id', $this->category))
            ->when($this->brand, fn ($q) => $q->where('brand_id', $this->brand))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->with('category:id,name', 'brand:id,name')
            ->withCount('variants')
            ->withMin('variants as min_price', 'retail_price')
            ->withMax('variants as max_price', 'retail_price')
            ->addSelect(['stock' => Inventory::query()->selectRaw('COALESCE(SUM(inventories.quantity), 0)')
                ->join('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
                ->whereColumn('product_variants.product_id', 'products.id')
                ->whereNull('product_variants.deleted_at')
                ->whereIn('inventories.branch_id', $branchIds)]);

        return [
            'products' => $this->applySorting($products, 'name', 'asc')->paginate(15),
            'categories' => Catalog::categories(),
            'brands' => Catalog::brands(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Products" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Products' => null]">
        <x-slot:actions>
            <a href="{{ route('app.barcodes') }}" class="btn btn-light-info" wire:navigate><i class="ri-barcode-line me-1"></i>Labels</a>
            <a href="{{ route('app.products.create') }}" class="btn btn-primary" wire:navigate><i class="ri-add-line me-1"></i>New product</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card">
        <div class="card-header">
            <div class="row g-2 filter-bar">
                <div class="col-lg-4"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Name, SKU or barcode…" /></div>
                <div class="col-6 col-lg-3">
                    <select wire:model.live="category" class="form-select"><option value="">All categories</option>@foreach ($categories as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select>
                </div>
                <div class="col-6 col-lg-3">
                    <select wire:model.live="brand" class="form-select"><option value="">All brands</option>@foreach ($brands as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</select>
                </div>
                <div class="col-6 col-lg-2">
                    <select wire:model.live="status" class="form-select"><option value="">Any status</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
            </div>
        </div>
        <div class="table-responsive loading-overlay" wire:loading.attr="data-loading">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light">
                    <tr>
                        <x-th-sort field="name" :sort="$sortField" :dir="$sortDirection">Product</x-th-sort>
                        <x-th-sort field="sku" :sort="$sortField" :dir="$sortDirection">SKU</x-th-sort>
                        <th>Category</th>
                        <th class="text-center">Variants</th>
                        <th class="text-center">Stock</th>
                        <th class="text-end">Price</th>
                        <th>Status</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr wire:key="pr-{{ $product->id }}">
                            <td data-label="Product">
                                <div class="d-flex align-items-center gap-2">
                                    @if ($product->image_url)
                                        <img src="{{ $product->image_url }}" class="rounded-2" style="width: 40px; height: 40px; object-fit: cover" alt="">
                                    @else
                                        <span class="avatar avatar-sm avatar-item avatar-title bg-primary-subtle text-primary rounded-2 fw-semibold">{{ mb_strtoupper(mb_substr($product->name, 0, 2)) }}</span>
                                    @endif
                                    <div class="min-w-0"><a href="{{ route('app.products.edit', $product) }}" class="fw-semibold" wire:navigate>{{ $product->name }}</a><div class="small text-muted">{{ $product->brand?->name }}</div></div>
                                </div>
                            </td>
                            <td data-label="SKU" class="font-monospace small">{{ $product->sku }}</td>
                            <td data-label="Category">{{ $product->category?->name ?? '—' }}</td>
                            <td data-label="Variants" class="text-md-center">{{ $product->variants_count }}</td>
                            <td data-label="Stock" class="text-md-center"><span class="badge {{ (int) $product->stock > 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ (int) $product->stock }}</span></td>
                            <td data-label="Price" class="text-end tabular-nums small">
                                @if ($product->min_price === null)—@elseif ((float) $product->min_price === (float) $product->max_price){{ money($product->min_price) }}@else{{ money($product->min_price) }} – {{ money($product->max_price) }}@endif
                            </td>
                            <td data-label="Status">
                                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" @checked($product->status === 'active') wire:click="toggleStatus({{ $product->id }})"></div>
                            </td>
                            <td class="text-end td-actions text-nowrap" data-label="">
                                <a href="{{ route('app.barcodes', ['product' => $product->id]) }}" class="btn btn-sm btn-light-info" wire:navigate title="Labels"><i class="ri-barcode-line"></i></a>
                                <a href="{{ route('app.products.edit', $product) }}" class="btn btn-sm btn-light-primary" wire:navigate title="Edit"><i class="ri-pencil-line"></i></a>
                                <button class="btn btn-sm btn-light-danger" title="Archive" x-on:click="SGMS.confirm({ title: 'Archive {{ addslashes($product->name) }}?', text: 'It disappears from the POS; past invoices keep it.' }).then(ok => ok && $wire.delete({{ $product->id }}))"><i class="ri-delete-bin-line"></i></button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state icon="ri-t-shirt-2-line" title="No products found" text="Products are also created automatically during lot intake." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())<div class="card-footer">{{ $products->links() }}</div>@endif
    </div>
</div>
