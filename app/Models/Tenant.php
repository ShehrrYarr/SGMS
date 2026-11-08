<?php

namespace App\Models;

use App\Support\Store\StoreSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /** URL segments that can never be a shop slug (the store lives at /{slug}). */
    public const RESERVED_SLUGS = [
        'admin', 'app', 'demo', 'landing', 'portal', 'api', 'login', 'logout', 'register', 'www', 'livewire', 'storage',
        'assets', 'css', 'js', 'build', 'vendor', 'store', 'up', 'images', 'fonts', 'favicon.ico', 'robots.txt', 'sitemap.xml',
    ];

    /** Route constraint for {tenant} on the storefront. */
    public const SLUG_ROUTE_PATTERN = '(?!(?:admin|app|demo|landing|portal|api|login|logout|register|www|livewire|storage|assets|css|js|build|vendor|store|up|images|fonts)$)[a-z0-9][a-z0-9-]*';

    public const PLANS = ['basic' => 'Basic', 'standard' => 'Standard', 'premium' => 'Premium'];

    protected $fillable = [
        'name', 'slug', 'domain', 'status', 'owner_email', 'phone', 'address',
        'plan', 'trial_ends_at', 'currency_symbol', 'tax_rate', 'settings', 'store_enabled', 'store_blocked',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'settings' => 'array',
            'tax_rate' => 'decimal:2',
            'store_enabled' => 'boolean',
            'store_blocked' => 'boolean',
        ];
    }

    /* Relationships ------------------------------------------------------ */

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', User::ROLE_OWNER)->orderBy('id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    /* Helpers ------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function portalUrl(): string
    {
        return route('portal.login', $this->slug);
    }

    /* Online store ------------------------------------------------------- */

    public function store(): StoreSettings
    {
        return StoreSettings::for($this);
    }

    public function storeUrl(): string
    {
        return route('store.home', $this->slug);
    }

    /** Open to shoppers: shop active, switched on by the owner, not blocked by the platform. */
    public function storeIsLive(): bool
    {
        return $this->isActive() && $this->store_enabled && ! $this->store_blocked;
    }
}
