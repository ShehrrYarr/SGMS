<?php

use App\Exceptions\BusinessException;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductColorImage;
use App\Models\ProductVariant;
use App\Services\ProductService;
use App\Services\Store\StoreCatalog;
use App\Support\Catalog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Product')] class extends Component {
    use InteractsWithUi, WithFileUploads;

    #[Locked]
    public ?int $productId = null;

    public string $name = '';

    public string $sku = '';

    public ?int $category_id = null;

    public ?int $brand_id = null;

    public string $description = '';

    public $base_cost = '';

    public $base_price = '';

    public string $status = 'active';

    public $image;

    public ?string $currentImage = null;

    // Online store
    public $compare_at_price = '';

    public bool $show_online = true;

    public bool $is_featured = false;

    public $sizeChart;

    public ?string $currentSizeChart = null;

    /** Pending colour photo uploads: color_id => file (saved immediately). */
    public array $colorPhotos = [];

    // Variant generator
    public array $genColors = [];

    public array $genSizes = [];

    // Editable variant rows: id => fields
    public array $variants = [];

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->productId = $product->id;
            $this->fill($product->only('name', 'sku', 'category_id', 'brand_id', 'status'));
            $this->description = (string) $product->description;
            $this->base_cost = (float) $product->base_cost;
            $this->base_price = (float) $product->base_price;
            $this->currentImage = $product->image_url;
            $this->compare_at_price = $product->compare_at_price !== null ? (float) $product->compare_at_price : '';
            $this->show_online = (bool) $product->show_online;
            $this->is_featured = (bool) $product->is_featured;
            $this->currentSizeChart = $product->size_chart_url;
            $this->loadVariants();
        }
    }

    protected function loadVariants(): void
    {
        $this->variants = ProductVariant::query()->where('product_id', $this->productId)->get()
            ->mapWithKeys(fn ($v) => [$v->id => [
                'barcode' => $v->barcode,
                'cost_price' => (float) $v->cost_price,
                'retail_price' => (float) $v->retail_price,
                'wholesale_price' => (float) $v->wholesale_price,
                'alert_quantity' => $v->alert_quantity,
            ]])->all();
    }

    public function save(ProductService $service): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:60', Rule::unique('products', 'sku')->where('tenant_id', tenant_id())->ignore($this->productId)],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_cost' => ['nullable', 'numeric', 'min:0'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'in:active,inactive'],
            'image' => ['nullable', 'image', 'max:2048'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'show_online' => ['boolean'],
            'is_featured' => ['boolean'],
            'sizeChart' => ['nullable', 'image', 'max:3072'],
            'variants.*.barcode' => ['required', 'string', 'max:64'],
            'variants.*.cost_price' => ['required', 'numeric', 'min:0'],
            'variants.*.retail_price' => ['required', 'numeric', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.alert_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $path = $this->image ? $this->image->store('products/'.tenant_id(), 'public') : null;
        $chart = $this->sizeChart ? $this->sizeChart->store('size-charts/'.tenant_id(), 'public') : null;
        $store = [
            'compare_at_price' => (float) $this->compare_at_price > 0 ? round_money($this->compare_at_price) : null,
            'show_online' => $this->show_online,
            'is_featured' => $this->is_featured,
        ];

        try {
            if ($this->productId) {
                $product = Product::query()->findOrFail($this->productId);
                $attributes = collect($data)->only('name', 'category_id', 'brand_id', 'description', 'status')->all() + $store + [
                    'sku' => $data['sku'] ?: $product->sku,
                    'base_cost' => (float) ($data['base_cost'] ?: 0),
                    'base_price' => (float) ($data['base_price'] ?: 0),
                ];

                if ($path) {
                    if ($product->image) {
                        Storage::disk('public')->delete($product->image);
                    }
                    $attributes['image'] = $path;
                }

                if ($chart) {
                    if ($product->size_chart) {
                        Storage::disk('public')->delete($product->size_chart);
                    }
                    $attributes['size_chart'] = $chart;
                }

                $product->update($attributes);

                foreach ($this->variants as $id => $fields) {
                    $variant = ProductVariant::query()->where('product_id', $product->id)->findOrFail($id);

                    if ($fields['barcode'] !== $variant->barcode) {
                        $service->ensureBarcodeAvailable($fields['barcode'], $variant->id);
                    }

                    $variant->update([
                        'barcode' => $fields['barcode'],
                        'cost_price' => (float) $fields['cost_price'],
                        'retail_price' => (float) $fields['retail_price'],
                        'wholesale_price' => (float) ($fields['wholesale_price'] ?: 0),
                        'alert_quantity' => (int) $fields['alert_quantity'],
                    ]);
                }
            } else {
                $product = $service->create($store + $data + ['image' => $path, 'size_chart' => $chart]);
                $this->productId = $product->id;
            }

            $created = $this->genColors || $this->genSizes
                ? $service->generateVariants($product, $this->genColors, $this->genSizes, ['cost_price' => $product->base_cost, 'retail_price' => $product->base_price])
                : 0;

            if (! $product->variants()->exists()) {
                $service->generateVariants($product, [], [], ['cost_price' => $product->base_cost, 'retail_price' => $product->base_price]);
                $created++;
            }
        } catch (BusinessException $e) {
            $this->toast($e->getMessage(), 'error');

            return;
        }

        StoreCatalog::forgetFacets();
        session()->flash('success', 'Product saved'.($created ? " with {$created} new variant(s)" : '').'.');
        $this->redirectRoute('app.products.edit', $product, navigate: true);
    }

    public function removeSizeChart(): void
    {
        $product = Product::query()->findOrFail($this->productId);
        Storage::disk('public')->delete((string) $product->size_chart);
        $product->update(['size_chart' => null]);
        $this->currentSizeChart = null;
        $this->toast('Size chart removed.');
    }

    /** A colour photo was picked: save it straight away. */
    public function updatedColorPhotos($file, $colorId): void
    {
        $this->validate(["colorPhotos.{$colorId}" => ['image', 'max:3072']], [], ["colorPhotos.{$colorId}" => 'photo']);

        $colorId = (int) $colorId;
        abort_unless($this->productId && ProductVariant::query()->where('product_id', $this->productId)->where('color_id', $colorId)->exists(), 404);

        $existing = ProductColorImage::query()->where('product_id', $this->productId)->where('color_id', $colorId)->first();

        if ($existing) {
            Storage::disk('public')->delete($existing->image);
        }

        ProductColorImage::query()->updateOrCreate(
            ['product_id' => $this->productId, 'color_id' => $colorId],
            ['image' => $file->store('products/'.tenant_id().'/colors', 'public')],
        );

        unset($this->colorPhotos[$colorId]);
        $this->toast('Colour photo saved.');
    }

    public function removeColorPhoto(int $colorId): void
    {
        $photo = ProductColorImage::query()->where('product_id', $this->productId)->where('color_id', $colorId)->first();

        if ($photo) {
            Storage::disk('public')->delete($photo->image);
            $photo->delete();
            $this->toast('Colour photo removed.');
        }
    }

    public function deleteVariant(int $id): void
    {
        $variant = ProductVariant::query()->where('product_id', $this->productId)->findOrFail($id);
        $stock = (int) Inventory::query()->where('product_variant_id', $id)->sum('quantity');

        if ($stock > 0) {
            $this->toast("This variant still has {$stock} pieces in stock.", 'error');

            return;
        }

        $variant->delete();
        unset($this->variants[$id]);
        $this->toast('Variant removed.');
    }

    public function with(): array
    {
        $variantRows = $this->productId
            ? ProductVariant::query()->where('product_id', $this->productId)->select('product_variants.*')->withStock()->with('color:id,name,hex_code', 'size:id,code,sort_order')->get()
                ->sortBy(fn ($v) => [$v->color?->name, $v->size?->sort_order])
            : collect();

        return [
            'categories' => Catalog::categories(),
            'brands' => Catalog::brands(),
            'colors' => Catalog::colors(),
            'sizes' => Catalog::sizes(),
            'variantRows' => $variantRows,
            'existingCombos' => $variantRows->map(fn ($v) => ($v->color_id ?? 0).'-'.($v->size_id ?? 0))->all(),
            'variantColors' => $variantRows->pluck('color')->filter()->unique('id')->sortBy('name')->values(),
            'colorImages' => $this->productId ? ProductColorImage::query()->where('product_id', $this->productId)->get()->keyBy('color_id') : collect(),
            'storeUrl' => $this->productId ? route('store.product', ['tenant' => tenant()->slug, 'slug' => Product::query()->whereKey($this->productId)->value('slug')]) : null,
        ];
    }
}; ?>

<div>
    <x-page-header :title="$productId ? 'Edit product' : 'New product'" :breadcrumbs="['Dashboard' => route('app.dashboard'), 'Products' => route('app.products.index'), ($productId ? $name : 'New') => null]">
        <x-slot:actions>
            @if ($productId)
                <a href="{{ route('app.barcodes', ['product' => $productId]) }}" class="btn btn-light-info" wire:navigate><i class="ri-barcode-line me-1"></i>Print labels</a>
            @endif
            <button class="btn btn-primary" wire:click="save" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>Save product</button>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Details</h6></div>
                <div class="card-body row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Product name <span class="text-danger">*</span></label>
                        <input wire:model="name" class="form-control @error('name') is-invalid @enderror">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">SKU</label>
                        <input wire:model="sku" class="form-control font-monospace @error('sku') is-invalid @enderror" placeholder="Auto">
                        @error('sku')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Category</label>
                        <select wire:model="category_id" class="form-select"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Brand</label>
                        <select wire:model="brand_id" class="form-select"><option value="">—</option>@foreach ($brands as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }}</option>@endforeach</select>
                    </div>
                    <div class="col-md-4"><label class="form-label">Default cost</label><input wire:model="base_cost" type="number" step="0.01" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Default retail price</label><input wire:model="base_price" type="number" step="0.01" class="form-control"></div>
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select wire:model="status" class="form-select"><option value="active">Active (sellable)</option><option value="inactive">Inactive</option></select>
                    </div>
                    <div class="col-12"><label class="form-label">Description</label><textarea wire:model="description" rows="2" class="form-control"></textarea></div>
                </div>
            </div>

            @if ($variantRows->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">Variants ({{ $variantRows->count() }})</h6></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 table-stack align-middle">
                            <thead class="table-light"><tr><th>Variant</th><th>Barcode</th><th>Cost</th><th>Retail</th><th>Wholesale</th><th>Alert at</th><th class="text-center">Stock</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($variantRows as $v)
                                    <tr wire:key="var-{{ $v->id }}">
                                        <td data-label="Variant"><span class="color-dot me-1" style="background: {{ $v->color?->hex_code ?? '#e5e7eb' }}"></span>{{ $v->variant_name }}<div class="small text-muted font-monospace">{{ $v->sku }}</div></td>
                                        <td data-label="Barcode"><input wire:model="variants.{{ $v->id }}.barcode" class="form-control form-control-sm font-monospace @error('variants.'.$v->id.'.barcode') is-invalid @enderror" style="min-width: 140px"></td>
                                        <td data-label="Cost"><input wire:model="variants.{{ $v->id }}.cost_price" type="number" step="0.01" class="form-control form-control-sm" style="width: 100px"></td>
                                        <td data-label="Retail"><input wire:model="variants.{{ $v->id }}.retail_price" type="number" step="0.01" class="form-control form-control-sm" style="width: 100px"></td>
                                        <td data-label="Wholesale"><input wire:model="variants.{{ $v->id }}.wholesale_price" type="number" step="0.01" class="form-control form-control-sm" style="width: 100px"></td>
                                        <td data-label="Alert at"><input wire:model="variants.{{ $v->id }}.alert_quantity" type="number" min="0" class="form-control form-control-sm" style="width: 70px"></td>
                                        <td data-label="Stock" class="text-md-center"><span class="badge {{ (int) $v->stock > 0 ? 'bg-success-subtle text-success' : 'bg-light text-muted' }}">{{ (int) $v->stock }}</span></td>
                                        <td class="text-end td-actions" data-label="">
                                            <button type="button" class="btn btn-sm btn-light-danger" x-on:click="SGMS.confirm({ title: 'Remove this variant?' }).then(ok => ok && $wire.deleteVariant({{ $v->id }}))"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            @if ($variantColors->isNotEmpty())
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="card-title mb-0"><i class="ri-palette-line me-1"></i>Colour photos</h6>
                        <small class="text-muted">One photo per colour. Shoppers see it when they pick that colour.</small>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            @foreach ($variantColors as $color)
                                @php $photo = $colorImages->get($color->id); @endphp
                                <div class="col-6 col-md-4 col-xxl-3" wire:key="cphoto-{{ $color->id }}">
                                    <div class="border rounded-3 p-2 h-100 d-flex flex-column gap-2">
                                        <div class="ratio rounded-2 overflow-hidden bg-light position-relative" style="--bs-aspect-ratio: 125%">
                                            @if ($photo)
                                                <img src="{{ $photo->url }}" alt="{{ $name }} in {{ $color->name }}" style="object-fit: cover">
                                            @else
                                                <div class="d-flex flex-column align-items-center justify-content-center text-muted">
                                                    <span class="rounded-circle mb-2" style="width: 36px; height: 36px; background: {{ $color->hex_code }}; box-shadow: inset 0 0 0 1px rgba(0,0,0,.12)"></span>
                                                    <small>No photo</small>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="d-flex align-items-center gap-1 small fw-medium"><span class="color-dot" style="background: {{ $color->hex_code }}"></span>{{ $color->name }}</div>
                                        <div wire:loading wire:target="colorPhotos.{{ $color->id }}" class="small text-muted">Uploading…</div>
                                        <div class="d-flex gap-1 mt-auto">
                                            <label class="btn btn-sm btn-light flex-grow-1 mb-0">
                                                <i class="ri-upload-2-line me-1"></i>{{ $photo ? 'Replace' : 'Upload' }}
                                                <input type="file" class="d-none" accept="image/*" wire:model="colorPhotos.{{ $color->id }}">
                                            </label>
                                            @if ($photo)
                                                <button type="button" class="btn btn-sm btn-light-danger" wire:click="removeColorPhoto({{ $color->id }})" title="Remove photo"><i class="ri-delete-bin-line"></i></button>
                                            @endif
                                        </div>
                                        @error('colorPhotos.'.$color->id)<div class="text-danger small">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Image</h6></div>
                <div class="card-body text-center">
                    @if ($image)
                        <img src="{{ $image->temporaryUrl() }}" class="img-fluid rounded-3 mb-2" style="max-height: 200px" alt="">
                    @elseif ($currentImage)
                        <img src="{{ $currentImage }}" class="img-fluid rounded-3 mb-2" style="max-height: 200px" alt="">
                    @else
                        <div class="bg-light rounded-3 d-flex align-items-center justify-content-center mb-2" style="height: 160px"><i class="ri-image-add-line fs-1 text-muted"></i></div>
                    @endif
                    <input type="file" wire:model="image" accept="image/*" class="form-control form-control-sm @error('image') is-invalid @enderror">
                    <div wire:loading wire:target="image" class="small text-muted mt-1">Uploading…</div>
                    @error('image')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0"><i class="ri-shopping-bag-3-line me-1"></i>Online store</h6>
                    @if ($storeUrl && $show_online && $status === 'active')<a href="{{ $storeUrl }}" target="_blank" rel="noopener" class="small">View in store <i class="ri-external-link-line"></i></a>@endif
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-2">
                        <input wire:model.live="show_online" class="form-check-input" type="checkbox" id="show_online">
                        <label class="form-check-label" for="show_online">Show on the online store</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input wire:model="is_featured" class="form-check-input" type="checkbox" id="is_featured">
                        <label class="form-check-label" for="is_featured">Feature at the top of listings</label>
                    </div>
                    <label class="form-label" for="compare_at_price">Compare-at ("was") price</label>
                    <div class="input-group">
                        <span class="input-group-text">{{ tenant()->currency_symbol }}</span>
                        <input wire:model="compare_at_price" id="compare_at_price" type="number" step="1" min="0" class="form-control @error('compare_at_price') is-invalid @enderror" placeholder="Not on sale">
                    </div>
                    <div class="form-text">Set it higher than the retail price to show a crossed-out price and a "-20%" badge.</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-ruler-line me-1"></i>Size chart</h6></div>
                <div class="card-body text-center">
                    @if ($sizeChart)
                        <img src="{{ $sizeChart->temporaryUrl() }}" class="img-fluid rounded-3 mb-2" style="max-height: 180px" alt="">
                    @elseif ($currentSizeChart)
                        <a href="{{ $currentSizeChart }}" target="_blank" rel="noopener"><img src="{{ $currentSizeChart }}" class="img-fluid rounded-3 mb-2" style="max-height: 180px" alt="Size chart"></a>
                        <div><button type="button" class="btn btn-sm btn-link text-danger" wire:click="removeSizeChart" wire:confirm="Remove the size chart?">Remove</button></div>
                    @else
                        <p class="text-muted small">Shoppers see a "Size chart" link next to the sizes.</p>
                    @endif
                    <input type="file" wire:model="sizeChart" accept="image/*" class="form-control form-control-sm @error('sizeChart') is-invalid @enderror">
                    <div wire:loading wire:target="sizeChart" class="small text-muted mt-1">Uploading…</div>
                    @error('sizeChart')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Saved when you save the product.</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Generate variants</h6><small class="text-muted">Every colour × size combination is created once.</small></div>
                <div class="card-body">
                    <label class="form-label small">Colours</label>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach ($colors as $c)
                            <label class="chip chip-sm cursor-pointer {{ in_array((string) $c['id'], array_map('strval', $genColors), true) ? 'active' : '' }}">
                                <input type="checkbox" class="d-none" wire:model.live="genColors" value="{{ $c['id'] }}"><span class="color-dot" style="background: {{ $c['hex_code'] }}"></span>{{ $c['name'] }}
                            </label>
                        @endforeach
                    </div>
                    <label class="form-label small">Sizes</label>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach ($sizes as $s)
                            <label class="chip chip-sm cursor-pointer {{ in_array((string) $s['id'], array_map('strval', $genSizes), true) ? 'active' : '' }}">
                                <input type="checkbox" class="d-none" wire:model.live="genSizes" value="{{ $s['id'] }}">{{ $s['code'] }}
                            </label>
                        @endforeach
                    </div>
                    @php
                        $pending = 0;
                        foreach (($genColors ?: [0]) as $gc) { foreach (($genSizes ?: [0]) as $gs) { if (! in_array(((int) $gc).'-'.((int) $gs), $existingCombos, true)) { $pending++; } } }
                    @endphp
                    <div class="small text-muted mb-2">{{ ($genColors || $genSizes) ? $pending.' new variant(s) will be created on save.' : 'Pick colours and/or sizes.' }}</div>
                    <button class="btn btn-light-primary w-100" wire:click="save" @disabled(! $genColors && ! $genSizes)><i class="ri-magic-line me-1"></i>Save & generate</button>
                </div>
            </div>
        </div>
    </div>
</div>
