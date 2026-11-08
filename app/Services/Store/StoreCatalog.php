<?php

namespace App\Services\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read side of the online store: listings, filters and product pages.
 * Stock shown online is the shop's combined stock minus pending orders.
 */
class StoreCatalog
{
    public const SORTS = [
        'featured' => 'Featured',
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'bestselling' => 'Best selling',
    ];

    public const PER_PAGE = 24;

    protected const PRICE_SQL = '(SELECT MIN(pv.retail_price) FROM product_variants pv WHERE pv.product_id = products.id AND pv.deleted_at IS NULL)';

    protected const MAX_PRICE_SQL = '(SELECT MAX(pv.retail_price) FROM product_variants pv WHERE pv.product_id = products.id AND pv.deleted_at IS NULL)';

    /** Units available online across all variants of the product. */
    protected static function availableSql(): string
    {
        return '(SELECT COALESCE(SUM(GREATEST('.ProductVariant::onlineStockSql('pv.id').', 0)), 0)
            FROM product_variants pv WHERE pv.product_id = products.id AND pv.deleted_at IS NULL)';
    }

    /** Base query for product cards (price, stock and swatches included). */
    public function cards(): Builder
    {
        return Product::query()
            ->online()
            ->whereHas('variants')
            ->select('products.*')
            ->selectRaw(self::PRICE_SQL.' as min_price')
            ->selectRaw(self::MAX_PRICE_SQL.' as max_price')
            ->selectRaw(self::availableSql().' as available')
            ->with([
                'category:id,name,slug',
                'colorImages' => fn ($q) => $q->orderBy('id'),
                'variants' => fn ($q) => $q->select('id', 'product_id', 'color_id', 'size_id', 'retail_price')->with('color:id,name,hex_code'),
            ]);
    }

    /**
     * @param  array{category?:string, q?:string, sizes?:array, colors?:array, min?:mixed, max?:mixed, sale?:mixed, in_stock?:mixed, sort?:string}  $filters
     */
    public function listing(array $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $sort = array_key_exists($filters['sort'] ?? '', self::SORTS) ? $filters['sort'] : 'featured';
        $sizes = array_filter(array_map('intval', (array) ($filters['sizes'] ?? [])));
        $colors = array_filter(array_map('intval', (array) ($filters['colors'] ?? [])));
        $term = trim((string) ($filters['q'] ?? ''));

        $query = $this->cards()
            ->when($filters['category'] ?? null, fn (Builder $q, $slug) => $q->whereHas('category', fn (Builder $c) => $c->where('slug', $slug)))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('products.name', 'like', "%{$term}%")
                ->orWhere('products.description', 'like', "%{$term}%")
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"))
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$term}%"))))
            ->when($sizes || $colors, fn (Builder $q) => $q->whereHas('variants', fn (Builder $v) => $v
                ->when($sizes, fn ($v) => $v->whereIn('size_id', $sizes))
                ->when($colors, fn ($v) => $v->whereIn('color_id', $colors))
                ->whereHas('inventories', fn ($i) => $i->where('quantity', '>', 0))))
            ->when(is_numeric($filters['min'] ?? null), fn (Builder $q) => $q->whereRaw(self::PRICE_SQL.' >= ?', [(float) $filters['min']]))
            ->when(is_numeric($filters['max'] ?? null), fn (Builder $q) => $q->whereRaw(self::PRICE_SQL.' <= ?', [(float) $filters['max']]))
            ->when(! empty($filters['sale']), fn (Builder $q) => $q->whereRaw('products.compare_at_price > '.self::PRICE_SQL))
            ->when(! empty($filters['in_stock']), fn (Builder $q) => $q->whereRaw(self::availableSql().' > 0'));

        match ($sort) {
            'newest' => $query->latest('products.created_at')->latest('products.id'),
            'price_asc' => $query->orderByRaw(self::PRICE_SQL.' asc'),
            'price_desc' => $query->orderByRaw(self::PRICE_SQL.' desc'),
            'bestselling' => $query->orderByDesc($this->unitsSoldSubquery()),
            default => $query->orderByDesc('products.is_featured')->orderByRaw(self::availableSql().' = 0')->latest('products.id'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    public function featured(int $limit = 8): Collection
    {
        return $this->cards()->orderByDesc('products.is_featured')->orderByRaw(self::availableSql().' = 0')->latest('products.id')->limit($limit)->get();
    }

    public function newArrivals(int $limit = 8): Collection
    {
        return $this->cards()->whereRaw(self::availableSql().' > 0')->latest('products.created_at')->latest('products.id')->limit($limit)->get();
    }

    public function bestSellers(int $limit = 8): Collection
    {
        return $this->cards()->whereRaw(self::availableSql().' > 0')->orderByDesc($this->unitsSoldSubquery())->limit($limit)->get();
    }

    public function onSale(int $limit = 8): Collection
    {
        return $this->cards()->whereRaw('products.compare_at_price > '.self::PRICE_SQL)->latest('products.id')->limit($limit)->get();
    }

    public function related(Product $product, int $limit = 4): Collection
    {
        return $this->cards()
            ->whereKeyNot($product->id)
            ->whereRaw(self::availableSql().' > 0')
            ->orderByRaw('products.category_id <=> ? desc', [$product->category_id])
            ->latest('products.id')
            ->limit($limit)
            ->get();
    }

    /** Product page: variants with live availability, colour photos and review summary. */
    public function product(string $slug): Product
    {
        return Product::query()
            ->online()
            ->where('slug', $slug)
            ->with([
                'category:id,name,slug',
                'brand:id,name',
                'colorImages',
                'variants' => fn ($q) => $q->withOnlineStock()->with(['color:id,name,hex_code', 'size:id,name,code,sort_order']),
            ])
            ->withAvg(['reviews as rating' => fn ($q) => $q->approved()], 'rating')
            ->withCount(['reviews as reviews_count' => fn ($q) => $q->approved()])
            ->firstOrFail();
    }

    /** Filter options for a listing (cached briefly per shop). */
    public function facets(): array
    {
        return Cache::remember(tenancy()->cacheKey('store:facets'), now()->addMinutes(10), function () {
            $productIds = Product::query()->online()->pluck('id');
            $variants = ProductVariant::query()->whereIn('product_id', $productIds);

            $price = (clone $variants)->selectRaw('MIN(retail_price) lo, MAX(retail_price) hi')->first();

            return [
                'categories' => Category::query()
                    ->whereHas('products', fn ($q) => $q->online())
                    ->withCount(['products' => fn ($q) => $q->online()])
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug', 'image']),
                'sizes' => Size::query()->whereIn('id', (clone $variants)->select('size_id'))->orderBy('sort_order')->get(['id', 'name', 'code']),
                'colors' => Color::query()->whereIn('id', (clone $variants)->select('color_id'))->orderBy('name')->get(['id', 'name', 'hex_code']),
                'price' => ['min' => (int) floor((float) $price?->lo), 'max' => (int) ceil((float) $price?->hi)],
            ];
        });
    }

    public static function forgetFacets(): void
    {
        Cache::forget(tenancy()->cacheKey('store:facets'));
    }

    protected function unitsSoldSubquery(): \Illuminate\Database\Query\Expression
    {
        return \Illuminate\Support\Facades\DB::raw('(SELECT COALESCE(SUM(si.quantity), 0) FROM sale_items si
            JOIN product_variants spv ON spv.id = si.product_variant_id
            JOIN sales s ON s.id = si.sale_id
            WHERE spv.product_id = products.id AND s.sale_date >= \''.now()->subDays(90)->toDateTimeString().'\')');
    }
}
