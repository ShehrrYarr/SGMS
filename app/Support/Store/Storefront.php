<?php

namespace App\Support\Store;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Store\Cart;
use App\Services\Store\StoreCatalog;
use App\Services\Store\WishlistService;
use Illuminate\Support\Collection;

/**
 * Per-request view state of the online store (shop, theme, nav, bag count).
 * Views reach it through the storefront() helper.
 */
class Storefront
{
    public readonly Tenant $tenant;

    public readonly StoreSettings $settings;

    public readonly string $theme;

    protected ?Collection $categories = null;

    protected ?array $paymentMethods = null;

    public function __construct()
    {
        $this->tenant = tenant() ?? abort(404);
        $this->settings = $this->tenant->store();
        $this->theme = $this->resolveTheme();
    }

    /** Theme being previewed by signed-in staff (?preview_theme=key, "off" to stop), if any. */
    public ?string $previewTheme = null;

    protected function resolveTheme(): string
    {
        $staff = auth('web')->user()?->tenant_id === $this->tenant->id;
        $key = 'store.preview_theme.'.$this->tenant->id;

        if ($staff && request()->has('preview_theme')) {
            $requested = (string) request()->query('preview_theme');
            Themes::exists($requested) && $requested !== $this->settings->theme()
                ? session([$key => $requested])
                : session()->forget($key);
        }

        $preview = $staff ? session($key) : null;

        if (Themes::exists($preview)) {
            $this->previewTheme = $preview;

            return $preview;
        }

        return $this->settings->theme();
    }

    /** Theme-specific partial when the theme has one, else the shared version. */
    public function view(string $name): string
    {
        $themed = "store.themes.{$this->theme}.{$name}";

        return view()->exists($themed) ? $themed : "store.partials.{$name}";
    }

    public function themeMeta(): array
    {
        return Themes::get($this->theme);
    }

    /** Categories with online products, for navigation. */
    public function categories(): Collection
    {
        return $this->categories ??= app(StoreCatalog::class)->facets()['categories'];
    }

    /** Owner-picked home page categories, falling back to the biggest ones. */
    public function featuredCategories(int $limit = 4): Collection
    {
        $picked = array_map('intval', (array) $this->settings->get('featured_categories', []));
        $all = $this->categories();

        $chosen = ($picked
            ? collect($picked)->map(fn ($id) => $all->firstWhere('id', $id))->filter()
            : $all->sortByDesc('products_count'))->take($limit)->values();

        // Cover photo: the category's own image, else its newest product photo.
        return $chosen->map(function ($category) {
            if (! $category->getAttribute('cover')) {
                $product = Product::query()->online()->where('category_id', $category->id)
                    ->where(fn ($q) => $q->whereNotNull('image')->orWhereHas('colorImages'))
                    ->with('colorImages')->latest('id')->first();
                $category->setAttribute('cover', $category->image_url ?? ($product ? $this->image($product) : null));
            }

            return $category;
        });
    }

    public function cartCount(): int
    {
        return app(Cart::class)->count();
    }

    public function wishlist(): WishlistService
    {
        return app(WishlistService::class);
    }

    public function customer(): ?Customer
    {
        return auth('customer')->user();
    }

    /** "Rs. 3,500" — no decimals unless the amount has paisa. */
    public function price(float|int|string|null $amount): string
    {
        $amount = (float) $amount;

        return money($amount, decimals: fmod($amount, 1.0) == 0.0 ? 0 : 2);
    }

    /** Primary photo for a product card or line, preferring a colour photo. */
    public function image(Product $product, ?int $colorId = null): ?string
    {
        $images = $product->relationLoaded('colorImages') ? $product->colorImages : collect();

        return ($colorId ? $images->firstWhere('color_id', $colorId)?->url : null)
            ?? $product->image_url
            ?? $images->first()?->url;
    }

    /** Checkout payment options (cached for the request). */
    public function paymentMethods(): array
    {
        return $this->paymentMethods ??= $this->settings->paymentMethods();
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> icon, title, text */
    public function perks(): array
    {
        $fee = (float) $this->settings->get('delivery_fee');
        $free = $this->settings->freeDeliveryOver();
        $perks = [[
            'truck',
            $free ? 'Free delivery over '.$this->price($free) : ($fee > 0 ? 'Delivery '.$this->price($fee) : 'Free delivery'),
            $this->settings->get('delivery_note') ?: 'Delivered to your door across Pakistan',
        ]];

        if (isset($this->paymentMethods()['cod'])) {
            $perks[] = ['cash', 'Cash on delivery', 'Pay when your parcel arrives'];
        }

        $perks[] = ['return', 'Easy exchanges', 'Swap sizes in store or by courier'];

        return $perks;
    }

    public function name(): string
    {
        return $this->tenant->name;
    }
}
