<?php

use App\Exceptions\BusinessException;
use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductService;
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
            'variants.*.barcode' => ['required', 'string', 'max:64'],
            'variants.*.cost_price' => ['required', 'numeric', 'min:0'],
            'variants.*.retail_price' => ['required', 'numeric', 'min:0'],
            'variants.*.wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.alert_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $path = $this->image ? $this->image->store('products/'.tenant_id(), 'public') : null;

        try {
            if ($this->productId) {
                $product = Product::query()->findOrFail($this->productId);
                $attributes = collect($data)->only('name', 'category_id', 'brand_id', 'description', 'status')->all() + [
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
                $product = $service->create($data + ['image' => $path]);
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

        session()->flash('success', 'Product saved'.($created ? " with {$created} new variant(s)" : '').'.');
        $this->redirectRoute('app.products.edit', $product, navigate: true);
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
