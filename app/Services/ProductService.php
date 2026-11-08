<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    public function __construct(protected BarcodeService $barcodes) {}

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $sku = trim((string) ($data['sku'] ?? ''));

            return Product::create([
                'category_id' => $data['category_id'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'name' => trim($data['name']),
                'sku' => $sku !== '' ? Str::upper($sku) : DocumentNumber::next('product', 5),
                'description' => $data['description'] ?? null,
                'image' => $data['image'] ?? null,
                'base_cost' => round_money($data['base_cost'] ?? 0),
                'base_price' => round_money($data['base_price'] ?? 0),
                'status' => $data['status'] ?? 'active',
            ]);
        });
    }

    /**
     * Find (or create) the colour/size variant of a product. Soft-deleted
     * variants are restored so history and barcodes stay intact.
     */
    public function resolveVariant(Product $product, ?int $colorId, ?int $sizeId, array $attributes = []): ProductVariant
    {
        $variant = ProductVariant::withTrashed()
            ->where('product_id', $product->id)
            ->where('color_id', $colorId)
            ->where('size_id', $sizeId)
            ->first();

        if ($variant?->trashed()) {
            $variant->restore();
        }

        $barcode = trim((string) ($attributes['barcode'] ?? ''));

        if ($variant) {
            if ($barcode !== '' && $barcode !== $variant->barcode) {
                $this->ensureBarcodeAvailable($barcode, $variant->id);
                $variant->barcode = $barcode;
                $variant->save();
            }

            return $variant;
        }

        if ($barcode !== '') {
            $this->ensureBarcodeAvailable($barcode);
        }

        return ProductVariant::create([
            'product_id' => $product->id,
            'color_id' => $colorId,
            'size_id' => $sizeId,
            'sku' => $this->variantSku($product, $colorId, $sizeId),
            'barcode' => $barcode !== '' ? $barcode : $this->barcodes->generate(),
            'cost_price' => round_money($attributes['cost_price'] ?? $product->base_cost),
            'retail_price' => round_money($attributes['retail_price'] ?? $product->base_price),
            'wholesale_price' => round_money($attributes['wholesale_price'] ?? 0),
            'alert_quantity' => (int) ($attributes['alert_quantity'] ?? 5),
        ]);
    }

    /** Create every colour x size combination that does not exist yet. */
    public function generateVariants(Product $product, array $colorIds, array $sizeIds, array $defaults = []): int
    {
        $colorIds = $colorIds ?: [null];
        $sizeIds = $sizeIds ?: [null];
        $created = 0;

        DB::transaction(function () use ($product, $colorIds, $sizeIds, $defaults, &$created) {
            foreach ($colorIds as $colorId) {
                foreach ($sizeIds as $sizeId) {
                    $variant = $this->resolveVariant($product, $colorId ? (int) $colorId : null, $sizeId ? (int) $sizeId : null, $defaults);
                    $created += $variant->wasRecentlyCreated ? 1 : 0;
                }
            }
        });

        return $created;
    }

    public function ensureBarcodeAvailable(string $barcode, ?int $ignoreVariantId = null): void
    {
        $taken = ProductVariant::withTrashed()
            ->where('barcode', $barcode)
            ->when($ignoreVariantId, fn ($q) => $q->whereKeyNot($ignoreVariantId))
            ->with('product')
            ->first();

        if ($taken) {
            throw new BusinessException("Barcode {$barcode} is already used by {$taken->label}.");
        }
    }

    public function variantSku(Product $product, ?int $colorId, ?int $sizeId): string
    {
        $parts = [$product->sku];

        if ($colorId && ($color = Color::find($colorId))) {
            $parts[] = Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $color->name), 0, 3));
        }

        if ($sizeId && ($size = Size::find($sizeId))) {
            $parts[] = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $size->code));
        }

        $base = implode('-', $parts);
        $sku = $base;
        $i = 2;

        while (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$i++;
        }

        return $sku;
    }
}
