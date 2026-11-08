<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use BelongsToTenant;

    public const TYPES = ['percent' => 'Percentage', 'fixed' => 'Fixed amount'];

    protected $fillable = [
        'tenant_id', 'code', 'type', 'value', 'min_order', 'max_discount',
        'starts_at', 'expires_at', 'usage_limit', 'used_count', 'is_active',
    ];

    protected $attributes = ['type' => 'percent', 'min_order' => 0, 'used_count' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_order' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'starts_at' => 'date',
            'expires_at' => 'date',
            'usage_limit' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (Coupon $coupon) => $coupon->code = strtoupper(trim($coupon->code)));
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Why the code can't be used on this subtotal, or null when it can. */
    public function problemFor(float $subtotal): ?string
    {
        return match (true) {
            ! $this->is_active => 'This code is no longer active.',
            $this->starts_at && $this->starts_at->isFuture() => 'This code is not active yet.',
            $this->expires_at && $this->expires_at->copy()->endOfDay()->isPast() => 'This code has expired.',
            $this->usage_limit !== null && $this->used_count >= $this->usage_limit => 'This code has reached its usage limit.',
            $subtotal + 0.009 < (float) $this->min_order => 'Spend '.money($this->min_order).' or more to use this code.',
            default => null,
        };
    }

    public function discountFor(float $subtotal): float
    {
        // Percentage discounts are rounded down to whole rupees.
        $discount = $this->type === 'percent'
            ? floor($subtotal * (float) $this->value / 100)
            : (float) $this->value;

        if ($this->max_discount !== null && (float) $this->max_discount > 0) {
            $discount = min($discount, (float) $this->max_discount);
        }

        return round_money(min($subtotal, max(0, $discount)));
    }

    /** "15% off" / "Rs. 500.00 off" */
    public function getLabelAttribute(): string
    {
        return $this->type === 'percent'
            ? rtrim(rtrim(number_format((float) $this->value, 2), '0'), '.').'% off'
            : money($this->value, decimals: 0).' off';
    }
}
