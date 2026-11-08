<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;

/**
 * The public demo (config sgms.demo): which accounts belong to it and what
 * those accounts may not change, so the next visitor can still sign in.
 */
class Demo
{
    public const ROLES = ['owner', 'manager', 'cashier', 'customer'];

    public static function enabled(): bool
    {
        return (bool) config('sgms.demo.enabled');
    }

    public static function shop(): string
    {
        return config('sgms.demo.shop');
    }

    public static function staffEmail(string $role): ?string
    {
        return config("sgms.demo.staff.$role");
    }

    /** True when the demo is on and this is one of the one-click accounts. */
    public static function protects(User|Customer|null $account): bool
    {
        if (! static::enabled() || ! $account) {
            return false;
        }

        return $account instanceof User
            ? in_array(strtolower($account->email), array_map('strtolower', config('sgms.demo.staff')), true)
            : normalize_phone((string) $account->phone) === normalize_phone(config('sgms.demo.customer_phone'))
                && $account->tenant?->slug === static::shop();
    }

    /** "3:00 AM" */
    public static function resetTime(): string
    {
        return date('g:i A', strtotime(config('sgms.demo.reset_at')));
    }

    public static function whatsappUrl(?string $message = null): string
    {
        return 'https://wa.me/'.preg_replace('/\D/', '', config('sgms.contact.whatsapp'))
            .'?text='.rawurlencode($message ?? config('sgms.contact.whatsapp_message'));
    }
}
