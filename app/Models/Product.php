<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'category_id', 'brand_id', 'name', 'slug', 'sku', 'description',
        'image', 'size_chart', 'base_cost', 'base_price', 'compare_at_price', 'status',
        'show_online', 'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'base_cost' => 'decimal:2',
            'base_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'show_online' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Every product gets a unique, URL-safe slug for its store page.
        static::saving(function (Product $product) {
            if ($product->slug && ! $product->isDirty('slug')) {
                return; // keep existing URLs stable when a product is renamed
            }

            $base = Str::slug($product->slug ?: $product->name) ?: 'product';
            $slug = $base;
            $i = 2;

            while (static::withTrashed()->where('slug', $slug)->whereKeyNot($product->getKey())->exists()) {
                $slug = $base.'-'.$i++;
            }

            $product->slug = $slug;
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function colorImages(): HasMany
    {
        return $this->hasMany(ProductColorImage::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /** Products the online store may list. */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('products.status', 'active')->where('products.show_online', true);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function inventories(): HasManyThrough
    {
        return $this->hasManyThrough(Inventory::class, ProductVariant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $query->when($term !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "{$term}%")
                ->orWhereHas('variants', fn (Builder $v) => $v->where('barcode', $term)->orWhere('sku', 'like', "{$term}%"));
        }));
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    public function getSizeChartUrlAttribute(): ?string
    {
        return $this->size_chart ? Storage::disk('public')->url($this->size_chart) : null;
    }

    /** Percentage saved against the compare-at price, or null when not on sale. */
    public function discountPercent(float $price): ?int
    {
        $was = (float) $this->compare_at_price;

        return $was > $price && $price > 0 ? (int) round(($was - $price) / $was * 100) : null;
    }
}
