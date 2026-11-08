<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Models\Category;
use App\Models\StoreBanner;
use App\Support\Catalog;
use App\Support\Store\Themes;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Store Appearance')] class extends Component {
    use InteractsWithUi, WithFileUploads;

    public string $theme = Themes::DEFAULT;

    public string $accent = '';

    public string $tagline = '';

    public string $announcement = '';

    public string $instagram = '';

    public string $facebook = '';

    public string $tiktok = '';

    public string $whatsapp = '';

    public bool $whatsapp_button = true;

    public array $featured_categories = [];

    public $logo = null;

    public $favicon = null;

    /** Banner being added / edited. */
    public array $banner = ['id' => null, 'heading' => '', 'subheading' => '', 'button_text' => '', 'link' => '', 'is_active' => true];

    public $bannerImage = null;

    public $categoryImage = null;

    public ?int $categoryImageFor = null;

    public function mount(): void
    {
        $store = tenant()->store();
        $this->theme = $store->theme();
        $this->accent = (string) $store->get('accent');
        $this->fill(collect(['tagline', 'announcement', 'instagram', 'facebook', 'tiktok', 'whatsapp'])->mapWithKeys(fn ($k) => [$k => (string) $store->get($k)])->all());
        $this->whatsapp_button = (bool) $store->get('whatsapp_button', true);
        $this->featured_categories = array_map('strval', (array) $store->get('featured_categories', []));
    }

    public function pickTheme(string $key): void
    {
        if (! Themes::exists($key)) {
            return;
        }

        $this->theme = $key;
        $this->persist(['theme' => $key]);
        $this->toast(Themes::get($key)['name'].' is now your store theme.');
    }

    public function save(): void
    {
        $this->validate([
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'announcement' => ['nullable', 'string', 'max:160'],
            'instagram' => ['nullable', 'url', 'max:255'],
            'facebook' => ['nullable', 'url', 'max:255'],
            'tiktok' => ['nullable', 'url', 'max:255'],
            'whatsapp' => ['nullable', 'regex:/^[0-9+\-\s]{10,20}$/'],
            'featured_categories' => ['array', 'max:6'],
            'logo' => ['nullable', 'image', 'max:1024'],
            'favicon' => ['nullable', 'image', 'max:256'],
        ], ['whatsapp.regex' => 'Enter the WhatsApp number like 0300 1234567.', 'accent.regex' => 'Pick a colour or clear the field.']);

        $values = [
            'accent' => $this->accent ?: null,
            'tagline' => trim($this->tagline) ?: null,
            'announcement' => trim($this->announcement) ?: null,
            'instagram' => trim($this->instagram) ?: null,
            'facebook' => trim($this->facebook) ?: null,
            'tiktok' => trim($this->tiktok) ?: null,
            'whatsapp' => trim($this->whatsapp) ?: null,
            'whatsapp_button' => $this->whatsapp_button,
            'featured_categories' => array_values(array_map('intval', $this->featured_categories)),
        ];

        foreach (['logo', 'favicon'] as $field) {
            if ($this->{$field}) {
                $values[$field] = $this->{$field}->store('store/'.tenant_id(), 'public');
                $this->deleteStored($field);
                $this->{$field} = null;
            }
        }

        $this->persist($values);
        $this->toast('Appearance saved.');
    }

    public function removeImage(string $field): void
    {
        if (in_array($field, ['logo', 'favicon'], true)) {
            $this->deleteStored($field);
            $this->persist([$field => null]);
        }
    }

    /* Banners ------------------------------------------------------------ */

    public function newBanner(): void
    {
        $this->reset('banner', 'bannerImage');
        $this->resetValidation();
        $this->openModal('banner');
    }

    public function editBanner(int $id): void
    {
        $banner = StoreBanner::query()->findOrFail($id);
        $this->banner = $banner->only('id', 'heading', 'subheading', 'button_text', 'link', 'is_active');
        $this->banner = array_map(fn ($v) => $v ?? '', $this->banner);
        $this->bannerImage = null;
        $this->resetValidation();
        $this->openModal('banner');
    }

    public function saveBanner(): void
    {
        $this->validate([
            'banner.heading' => ['required', 'string', 'max:120'],
            'banner.subheading' => ['nullable', 'string', 'max:255'],
            'banner.button_text' => ['nullable', 'string', 'max:40'],
            'banner.link' => ['nullable', 'string', 'max:255'],
            'bannerImage' => [$this->banner['id'] ? 'nullable' : 'required', 'image', 'max:4096'],
        ], [], ['banner.heading' => 'heading', 'bannerImage' => 'image']);

        $banner = $this->banner['id'] ? StoreBanner::query()->findOrFail($this->banner['id']) : new StoreBanner(['sort_order' => (int) StoreBanner::query()->max('sort_order') + 1]);
        $banner->fill(collect($this->banner)->only('heading', 'subheading', 'button_text', 'link')->map(fn ($v) => $v === '' ? null : $v)->all());
        $banner->is_active = (bool) $this->banner['is_active'];

        if ($this->bannerImage) {
            if ($banner->image) {
                Storage::disk('public')->delete($banner->image);
            }
            $banner->image = $this->bannerImage->store('banners/'.tenant_id(), 'public');
        }

        $banner->save();
        $this->closeModal('banner');
        $this->toast('Banner saved.');
    }

    public function toggleBanner(int $id): void
    {
        $banner = StoreBanner::query()->findOrFail($id);
        $banner->update(['is_active' => ! $banner->is_active]);
    }

    public function moveBanner(int $id, int $direction): void
    {
        $banners = StoreBanner::query()->orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $banners->search(fn ($b) => $b->id === $id);
        $swap = $banners->get($index + $direction);

        if ($index === false || ! $swap) {
            return;
        }

        $banners->each(fn ($b, $i) => $b->sort_order = $i);
        [$banners[$index]->sort_order, $swap->sort_order] = [$swap->sort_order, $banners[$index]->sort_order];
        $banners->each->save();
    }

    public function deleteBanner(int $id): void
    {
        $banner = StoreBanner::query()->findOrFail($id);
        Storage::disk('public')->delete($banner->image);
        $banner->delete();
        $this->toast('Banner deleted.');
    }

    /* Category photos ----------------------------------------------------- */

    public function updatedCategoryImage(): void
    {
        $this->validate(['categoryImage' => ['image', 'max:2048']]);
        $category = Category::query()->findOrFail($this->categoryImageFor);

        if ($category->image) {
            Storage::disk('public')->delete($category->image);
        }

        $category->update(['image' => $this->categoryImage->store('categories/'.tenant_id(), 'public')]);
        $this->reset('categoryImage', 'categoryImageFor');
        Catalog::flush();
        $this->toast("Photo saved for {$category->name}.");
    }

    public function removeCategoryImage(int $id): void
    {
        $category = Category::query()->findOrFail($id);
        Storage::disk('public')->delete((string) $category->image);
        $category->update(['image' => null]);
        Catalog::flush();
    }

    /* ------------------------------------------------------------------ */

    protected function persist(array $values): void
    {
        $tenant = tenant();
        $settings = $tenant->settings ?? [];
        $settings['store'] = array_merge($settings['store'] ?? [], $values);
        $tenant->update(['settings' => $settings]);
    }

    protected function deleteStored(string $field): void
    {
        if ($path = tenant()->store()->get($field)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function with(): array
    {
        $store = tenant()->store();

        return [
            'themes' => Themes::ALL,
            'store' => $store,
            'banners' => StoreBanner::query()->orderBy('sort_order')->orderBy('id')->get(),
            'categories' => Category::query()->withCount(['products' => fn ($q) => $q->online()])->orderBy('name')->get(),
            'fontsHref' => 'https://fonts.googleapis.com/css2?'.collect(Themes::ALL)->pluck('fonts')->implode('&').'&display=swap',
        ];
    }
}; ?>

<div>
    <link rel="stylesheet" href="{{ $fontsHref }}">
    <x-page-header title="Shop settings" subtitle="Choose how your online store looks." />
    @include('partials.settings-tabs', ['current' => 'app.settings.appearance'])

    {{-- Theme gallery --}}
    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="card-title mb-0"><i class="ri-layout-masonry-line me-1"></i>Theme</h6>
            <span class="text-muted small">Every theme uses the same products, prices and settings. Switch any time.</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach ($themes as $key => $meta)
                    @php($active = $theme === $key)
                    <div class="col-sm-6 col-xl-4 col-xxl">
                        <div class="theme-card {{ $active ? 'is-active' : '' }}">
                            <div class="theme-card__preview theme-card__preview--{{ $key }}" style="--p0: {{ $meta['palette'][0] }}; --p1: {{ $meta['palette'][1] }}; --p2: {{ $meta['palette'][2] }}; --p3: {{ $meta['palette'][3] }}; --p4: {{ $meta['palette'][4] ?? $meta['palette'][3] }}">
                                <span class="theme-card__bar"></span>
                                <span class="theme-card__type">{{ tenant()->name }}</span>
                                <span class="theme-card__tiles"><i></i><i></i><i></i></span>
                            </div>
                            <div class="p-3">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                    <h6 class="mb-0">{{ $meta['name'] }}</h6>
                                    <span class="badge bg-light text-body border">{{ $meta['best_for'] }}</span>
                                </div>
                                <p class="text-muted small mb-3">{{ $meta['summary'] }}</p>
                                <div class="d-flex gap-2">
                                    @if ($active)
                                        <span class="btn btn-sm btn-success flex-grow-1 disabled"><i class="ri-check-line me-1"></i>Current theme</span>
                                    @else
                                        <button type="button" class="btn btn-sm btn-primary flex-grow-1" wire:click="pickTheme('{{ $key }}')" wire:loading.attr="disabled">Use this theme</button>
                                    @endif
                                    <a href="{{ tenant()->storeUrl() }}?preview_theme={{ $key }}" target="_blank" rel="noopener" class="btn btn-sm btn-light" title="Preview in a new tab"><i class="ri-eye-line"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <form wire:submit="save" class="row g-3">
        <div class="col-xl-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-palette-line me-1"></i>Brand</h6></div>
                <div class="card-body row g-3">
                    <div class="col-sm-6">
                        <label class="form-label">Logo</label>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            @if ($logo)
                                <img src="{{ $logo->temporaryUrl() }}" alt="" style="max-height: 40px">
                            @elseif ($store->logoUrl())
                                <img src="{{ $store->logoUrl() }}" alt="" style="max-height: 40px">
                                <button type="button" class="btn btn-sm btn-link text-danger" wire:click="removeImage('logo')">Remove</button>
                            @else
                                <span class="text-muted small">Your shop name is shown as text.</span>
                            @endif
                        </div>
                        <input type="file" wire:model="logo" accept="image/*" class="form-control form-control-sm @error('logo') is-invalid @enderror">
                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">PNG or SVG with a transparent background works best.</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Favicon</label>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            @if ($favicon)
                                <img src="{{ $favicon->temporaryUrl() }}" alt="" width="24" height="24">
                            @elseif ($store->faviconUrl())
                                <img src="{{ $store->faviconUrl() }}" alt="" width="24" height="24">
                                <button type="button" class="btn btn-sm btn-link text-danger" wire:click="removeImage('favicon')">Remove</button>
                            @else
                                <span class="text-muted small">Browser tab icon.</span>
                            @endif
                        </div>
                        <input type="file" wire:model="favicon" accept="image/png,image/x-icon,image/svg+xml" class="form-control form-control-sm @error('favicon') is-invalid @enderror">
                        @error('favicon')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="accent">Brand colour</label>
                        <div class="input-group">
                            <input type="color" class="form-control form-control-color" value="{{ $accent ?: $themes[$theme]['accent'] }}" x-on:input="$wire.set('accent', $event.target.value, false)" title="Pick colour">
                            <input wire:model="accent" id="accent" class="form-control @error('accent') is-invalid @enderror" placeholder="{{ $themes[$theme]['accent'] }} (theme default)">
                            @if ($accent)<button type="button" class="btn btn-light" wire:click="$set('accent', '')" title="Use theme colour"><i class="ri-arrow-go-back-line"></i></button>@endif
                        </div>
                        @error('accent')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <div class="form-text">Buttons and highlights. Leave empty to use the theme's colour.</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="tagline">Tagline</label>
                        <input wire:model="tagline" id="tagline" class="form-control" maxlength="120" placeholder="Everyday menswear from Lahore">
                        <div class="form-text">Used in the home page headline and search results.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="announcement">Announcement bar</label>
                        <input wire:model="announcement" id="announcement" class="form-control" maxlength="160" placeholder="Free delivery on orders over Rs. 5,000">
                        <div class="form-text">A short line at the very top of every page. Leave empty to hide it.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0"><i class="ri-share-line me-1"></i>Social & WhatsApp</h6></div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Instagram</label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-instagram-line"></i></span><input wire:model="instagram" class="form-control @error('instagram') is-invalid @enderror" placeholder="https://instagram.com/yourshop"></div>
                        @error('instagram')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Facebook</label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-facebook-circle-line"></i></span><input wire:model="facebook" class="form-control @error('facebook') is-invalid @enderror" placeholder="https://facebook.com/..."></div>
                        @error('facebook')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">TikTok</label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-tiktok-line"></i></span><input wire:model="tiktok" class="form-control @error('tiktok') is-invalid @enderror" placeholder="https://tiktok.com/@..."></div>
                        @error('tiktok')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">WhatsApp number</label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-whatsapp-line"></i></span><input wire:model="whatsapp" class="form-control @error('whatsapp') is-invalid @enderror" placeholder="0300 1234567"></div>
                        @error('whatsapp')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input wire:model="whatsapp_button" class="form-check-input" type="checkbox" id="wa_btn">
                            <label class="form-check-label" for="wa_btn">Show a floating chat button</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card mb-0">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0"><i class="ri-folder-image-line me-1"></i>Home page categories</h6>
                    <span class="text-muted small">Tick up to 6, in the order you want them. Empty = largest categories.</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th style="width: 60px">Show</th><th>Category</th><th class="text-end">Online products</th><th style="width: 260px">Photo</th></tr></thead>
                        <tbody>
                            @foreach ($categories as $category)
                                <tr wire:key="cat-{{ $category->id }}">
                                    <td><input type="checkbox" class="form-check-input" wire:model="featured_categories" value="{{ $category->id }}" aria-label="Show {{ $category->name }} on home page"></td>
                                    <td class="fw-medium">{{ $category->name }} @if (($pos = array_search((string) $category->id, $featured_categories, true)) !== false)<span class="badge bg-primary-subtle text-primary ms-1">#{{ $pos + 1 }}</span>@endif</td>
                                    <td class="text-end">{{ $category->products_count }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            @if ($category->image_url)
                                                <img src="{{ $category->image_url }}" alt="" class="rounded" style="width: 36px; height: 44px; object-fit: cover">
                                                <button type="button" class="btn btn-sm btn-link text-danger px-1" wire:click="removeCategoryImage({{ $category->id }})">Remove</button>
                                            @endif
                                            <label class="btn btn-sm btn-light mb-0">
                                                <i class="ri-upload-2-line me-1"></i>{{ $category->image_url ? 'Replace' : 'Upload' }}
                                                <input type="file" accept="image/*" class="d-none" x-on:change="$wire.set('categoryImageFor', {{ $category->id }}, false); $wire.upload('categoryImage', $event.target.files[0])">
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="text-muted small">Without a photo, the newest product photo in that category is used.</span>
                    <button class="btn btn-primary" type="submit" wire:loading.attr="disabled"><i class="ri-save-line me-1"></i>Save appearance</button>
                </div>
            </div>
        </div>
    </form>

    {{-- Banners --}}
    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0"><i class="ri-slideshow-3-line me-1"></i>Home page banners</h6>
            <button type="button" class="btn btn-sm btn-primary" wire:click="newBanner"><i class="ri-add-line me-1"></i>Add banner</button>
        </div>
        <div class="card-body">
            @forelse ($banners as $banner)
                <div class="d-flex flex-wrap align-items-center gap-3 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}" wire:key="banner-{{ $banner->id }}">
                    <img src="{{ $banner->image_url }}" alt="" class="rounded" style="width: 120px; height: 68px; object-fit: cover">
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate">{{ $banner->heading }}</div>
                        <div class="text-muted small text-truncate">{{ $banner->subheading ?: 'No subheading' }}</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" @checked($banner->is_active) wire:click="toggleBanner({{ $banner->id }})" id="ban-{{ $banner->id }}">
                        <label class="form-check-label small" for="ban-{{ $banner->id }}">{{ $banner->is_active ? 'Showing' : 'Hidden' }}</label>
                    </div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-light" wire:click="moveBanner({{ $banner->id }}, -1)" @disabled($loop->first) title="Move up"><i class="ri-arrow-up-line"></i></button>
                        <button type="button" class="btn btn-light" wire:click="moveBanner({{ $banner->id }}, 1)" @disabled($loop->last) title="Move down"><i class="ri-arrow-down-line"></i></button>
                        <button type="button" class="btn btn-light" wire:click="editBanner({{ $banner->id }})" title="Edit"><i class="ri-pencil-line"></i></button>
                        <button type="button" class="btn btn-light text-danger" wire:click="deleteBanner({{ $banner->id }})" wire:confirm="Delete this banner?" title="Delete"><i class="ri-delete-bin-line"></i></button>
                    </div>
                </div>
            @empty
                <x-empty-state icon="ri-image-add-line" title="No banners yet" text="Without banners, your store opens with your tagline. Add a wide photo (about 1600 × 900) for each promotion." />
            @endforelse
        </div>
    </div>

    <x-modal name="banner" :title="$banner['id'] ? 'Edit banner' : 'Add banner'" size="lg">
        <form wire:submit="saveBanner" id="banner-form" class="row g-3">
            <div class="col-12">
                <label class="form-label">Image</label>
                @if ($bannerImage)
                    <img src="{{ $bannerImage->temporaryUrl() }}" alt="" class="img-fluid rounded mb-2" style="max-height: 180px">
                @endif
                <input type="file" wire:model="bannerImage" accept="image/*" class="form-control @error('bannerImage') is-invalid @enderror">
                <div wire:loading wire:target="bannerImage" class="small text-muted mt-1">Uploading…</div>
                @error('bannerImage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Wide photo, about 1600 × 900 px, up to 4 MB.{{ $banner['id'] ? ' Leave empty to keep the current image.' : '' }}</div>
            </div>
            <div class="col-12"><label class="form-label">Heading</label><input wire:model="banner.heading" class="form-control @error('banner.heading') is-invalid @enderror" maxlength="120" placeholder="Winter collection is here">@error('banner.heading')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-12"><label class="form-label">Subheading</label><input wire:model="banner.subheading" class="form-control" maxlength="255" placeholder="Warm fleece hoodies and heavy denim"></div>
            <div class="col-sm-5"><label class="form-label">Button text</label><input wire:model="banner.button_text" class="form-control" maxlength="40" placeholder="Shop hoodies"></div>
            <div class="col-sm-7">
                <label class="form-label">Button link</label>
                <input wire:model="banner.link" class="form-control" placeholder="{{ tenant()->storeUrl() }}/category/hoodies">
                <div class="form-text">Paste any page of your store. Empty = all products.</div>
            </div>
            <div class="col-12"><div class="form-check form-switch"><input wire:model="banner.is_active" class="form-check-input" type="checkbox" id="ban-active"><label class="form-check-label" for="ban-active">Show on the home page</label></div></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn btn-light" x-on:click="$dispatch('close-modal', { name: 'banner' })">Cancel</button>
            <button type="submit" form="banner-form" class="btn btn-primary" wire:loading.attr="disabled">Save banner</button>
        </x-slot:footer>
    </x-modal>
</div>
