<?php

namespace App\Models\Concerns;

use App\Support\Catalog;

/**
 * Lookup tables (categories, colours, sizes...) are cached per tenant.
 * Any write invalidates that tenant's lookup cache.
 */
trait FlushesTenantCache
{
    public static function bootFlushesTenantCache(): void
    {
        $flush = fn ($model) => Catalog::flush($model->tenant_id);

        static::saved($flush);
        static::deleted($flush);
    }
}
