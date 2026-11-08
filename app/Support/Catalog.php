<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\ExpenseCategory;
use App\Models\Size;
use Illuminate\Support\Facades\Cache;

/**
 * Tenant-isolated cache of small lookup lists used by forms and the POS.
 * Keys are prefixed with the tenant id so shops never share cached data.
 */
class Catalog
{
    protected const KEYS = ['categories', 'brands', 'colors', 'sizes', 'expense_categories'];

    protected const TTL = 86400;

    public static function categories(): array
    {
        return static::remember('categories', fn () => Category::query()->orderBy('name')->get(['id', 'name', 'slug'])->toArray());
    }

    public static function brands(): array
    {
        return static::remember('brands', fn () => Brand::query()->orderBy('name')->get(['id', 'name'])->toArray());
    }

    public static function colors(): array
    {
        return static::remember('colors', fn () => Color::query()->orderBy('name')->get(['id', 'name', 'hex_code'])->toArray());
    }

    public static function sizes(): array
    {
        return static::remember('sizes', fn () => Size::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'code'])->toArray());
    }

    public static function expenseCategories(): array
    {
        return static::remember('expense_categories', fn () => ExpenseCategory::query()->orderBy('name')->get(['id', 'name'])->toArray());
    }

    public static function flush(?int $tenantId = null): void
    {
        $tenantId ??= tenant_id();

        foreach (self::KEYS as $key) {
            Cache::forget("tenant:{$tenantId}:catalog:{$key}");
        }

        Cache::forget("tenant:{$tenantId}:store:facets");
    }

    protected static function remember(string $key, \Closure $callback): array
    {
        $tenantId = tenant_id();

        if (! $tenantId) {
            return $callback();
        }

        return Cache::remember("tenant:{$tenantId}:catalog:{$key}", self::TTL, $callback);
    }
}
