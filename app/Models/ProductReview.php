<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductReview extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];

    protected $fillable = ['tenant_id', 'product_id', 'customer_id', 'rating', 'title', 'body', 'status'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** "Bilal A." — first name and initial, shown publicly. */
    public function getAuthorAttribute(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->customer?->name)) ?: [];
        $first = ($parts[0] ?? '') !== '' ? $parts[0] : 'Customer';
        $initial = isset($parts[1]) ? ' '.mb_strtoupper(mb_substr($parts[1], 0, 1)).'.' : '';

        return $first.$initial;
    }
}
