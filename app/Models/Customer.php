<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Shop customer. Authenticates on the `customer` guard inside /{shop}/account.
 * Tenant scoping guarantees a customer session only works in its own shop.
 */
class Customer extends Authenticatable
{
    use BelongsToTenant, HasFactory, Notifiable, SoftDeletes;

    /** Model-level defaults so new instances match the database defaults. */
    protected $attributes = ['status' => 'active', 'opening_balance' => 0, 'current_balance' => 0];

    protected $fillable = [
        'tenant_id', 'name', 'phone', 'email', 'password', 'address',
        'opening_balance', 'current_balance', 'status', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'last_login_at' => 'datetime',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $query->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('phone', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")));
    }

    public function hasPortalAccess(): bool
    {
        return ! empty($this->password) && $this->status === 'active';
    }
}
