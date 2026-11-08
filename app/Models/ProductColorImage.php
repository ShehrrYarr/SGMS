<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Photo of a product in one colour (e.g. the Navy Blue trousers). */
class ProductColorImage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_id', 'color_id', 'image'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->image);
    }
}
