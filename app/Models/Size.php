<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\FlushesTenantCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Size extends Model
{
    use BelongsToTenant, FlushesTenantCache, HasFactory;

    protected $fillable = ['tenant_id', 'name', 'code', 'sort_order'];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }
}
