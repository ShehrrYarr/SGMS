<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'product_id', 'color_id', 'size_id', 'sku', 'barcode',
        'cost_price', 'retail_price', 'wholesale_price', 'alert_quantity',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'retail_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'alert_quantity' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /** Stock row for a single branch (use with a constrained eager load). */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** Adds a `stock` column: quantity in a branch, or across all branches. */
    public function scopeWithStock(Builder $query, ?int $branchId = null): Builder
    {
        return $query->addSelect(['stock' => Inventory::query()
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('inventories.product_variant_id', 'product_variants.id')
            ->when($branchId, fn ($q) => $q->where('inventories.branch_id', $branchId)),
        ]);
    }

    /**
     * Adds an `available` column for the online store: stock across all
     * branches minus units reserved by online orders not yet confirmed.
     */
    public function scopeWithOnlineStock(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('product_variants.*');
        }

        return $query->selectRaw('('.self::onlineStockSql().') as available');
    }

    /** SQL for online availability of the outer `product_variants` row. */
    public static function onlineStockSql(string $variantColumn = 'product_variants.id'): string
    {
        return "(SELECT COALESCE(SUM(GREATEST(inv.quantity, 0)), 0) FROM inventories inv WHERE inv.product_variant_id = {$variantColumn})
            - (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
               WHERE oi.product_variant_id = {$variantColumn} AND o.status = 'pending')";
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $query->when($term !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($term) {
            $q->where('product_variants.barcode', $term)
                ->orWhere('product_variants.sku', 'like', "{$term}%")
                ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', "%{$term}%"));
        }));
    }

    /** e.g. "Black / M" */
    public function getVariantNameAttribute(): string
    {
        return collect([$this->color?->name, $this->size?->code ?? $this->size?->name])->filter()->implode(' / ') ?: 'Standard';
    }

    /** e.g. "Oxford Shirt — Black / M" */
    public function getLabelAttribute(): string
    {
        return ($this->product?->name ?? 'Item').' — '.$this->variant_name;
    }
}
