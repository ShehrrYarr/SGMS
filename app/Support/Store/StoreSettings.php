<?php

namespace App\Support\Store;

use App\Models\PaymentAccount;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Typed access to a shop's online store settings (tenants.settings['store']).
 */
class StoreSettings
{
    public const DEFAULTS = [
        // Appearance
        'theme' => Themes::DEFAULT,
        'accent' => null,          // overrides the theme accent
        'logo' => null,
        'favicon' => null,
        'tagline' => null,
        'announcement' => null,
        'featured_categories' => [],
        'instagram' => null,
        'facebook' => null,
        'tiktok' => null,
        'whatsapp' => null,        // number for the chat button
        'whatsapp_button' => true,
        // Delivery
        'delivery_fee' => 0,
        'free_delivery_over' => null,
        'delivery_note' => null,   // e.g. "Delivered in 3–5 working days"
        // Payments
        'cod' => true,
        'bank_accounts' => [],     // payment_account ids shown at checkout
        'wallet_accounts' => [],
        'payment_instructions' => null,
    ];

    protected array $values;

    public function __construct(protected Tenant $tenant)
    {
        $this->values = array_replace(self::DEFAULTS, (array) $tenant->setting('store', []));
    }

    public static function for(Tenant $tenant): self
    {
        return new self($tenant);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function theme(): string
    {
        return Themes::exists($this->values['theme']) ? $this->values['theme'] : Themes::DEFAULT;
    }

    public function accent(?string $theme = null): string
    {
        $accent = (string) $this->values['accent'];

        return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : Themes::get($theme ?? $this->theme())['accent'];
    }

    /** Black or white, whichever reads better on the accent colour. */
    public function accentInk(?string $theme = null): string
    {
        [$r, $g, $b] = array_map(fn ($hex) => hexdec($hex) / 255, str_split(ltrim($this->accent($theme), '#'), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        // Whichever of black or white has the higher contrast ratio.
        return ($luminance + 0.05) / 0.05 > 1.05 / ($luminance + 0.05) ? '#000000' : '#FFFFFF';
    }

    public function logoUrl(): ?string
    {
        return $this->url('logo');
    }

    public function faviconUrl(): ?string
    {
        return $this->url('favicon');
    }

    /** Delivery charge for an order worth $orderValue (after discounts). */
    public function deliveryFeeFor(float $orderValue): float
    {
        $fee = max(0, (float) $this->values['delivery_fee']);
        $threshold = $this->freeDeliveryOver();

        return $threshold !== null && $orderValue + 0.009 >= $threshold ? 0.0 : round_money($fee);
    }

    public function freeDeliveryOver(): ?float
    {
        $value = $this->values['free_delivery_over'];

        return $value === null || $value === '' || (float) $value <= 0 ? null : (float) $value;
    }

    /** @return array<string, string> url per network, only those filled in */
    public function socials(): array
    {
        return collect(['instagram', 'facebook', 'tiktok'])
            ->mapWithKeys(fn ($key) => [$key => trim((string) $this->values[$key])])
            ->filter()
            ->all();
    }

    public function whatsappUrl(?string $text = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->values['whatsapp']);

        if ($digits === '' || ! $this->values['whatsapp_button']) {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '92'.substr($digits, 1);
        }

        return 'https://wa.me/'.$digits.($text ? '?text='.rawurlencode($text) : '');
    }

    /**
     * Checkout payment options: key => [label, accounts].
     *
     * @return array<string, array{label: string, accounts: Collection}>
     */
    public function paymentMethods(): array
    {
        $ids = array_map('intval', array_merge((array) $this->values['bank_accounts'], (array) $this->values['wallet_accounts']));
        $accounts = $ids
            ? PaymentAccount::query()->forTenant($this->tenant->id)->active()->whereIn('id', $ids)->orderBy('account_name')->get()
            : collect();

        $methods = [];

        if ($this->values['cod']) {
            $methods['cod'] = ['label' => 'Cash on delivery', 'accounts' => collect()];
        }

        $banks = $accounts->where('account_type', PaymentAccount::TYPE_BANK)->whereIn('id', array_map('intval', (array) $this->values['bank_accounts']))->values();
        $wallets = $accounts->where('account_type', PaymentAccount::TYPE_WALLET)->whereIn('id', array_map('intval', (array) $this->values['wallet_accounts']))->values();

        if ($banks->isNotEmpty()) {
            $methods['bank'] = ['label' => 'Bank transfer', 'accounts' => $banks];
        }

        if ($wallets->isNotEmpty()) {
            $methods['wallet'] = ['label' => 'JazzCash / Easypaisa', 'accounts' => $wallets];
        }

        return $methods;
    }

    protected function url(string $key): ?string
    {
        return $this->values[$key] ? Storage::disk('public')->url($this->values[$key]) : null;
    }
}
