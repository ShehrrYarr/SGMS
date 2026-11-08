<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\Demo;
use App\Support\Store\Themes;
use Illuminate\View\View;

/**
 * The SGMS product page at "/": tour, online store, plans, demo portals.
 */
class LandingController extends Controller
{
    public function __invoke(): View
    {
        $demoShop = Demo::enabled() ? Tenant::query()->where('slug', Demo::shop())->first() : null;

        return view('landing', [
            'demo' => Demo::enabled(),
            'demoStoreUrl' => $demoShop?->storeIsLive() ? $demoShop->storeUrl() : null,
            'resetTime' => Demo::resetTime(),
            'whatsapp' => Demo::whatsappUrl(),
            'themes' => Themes::ALL,
            'plans' => collect(config('sgms.plans'))->map(fn ($plan, $key) => $plan + [
                'name' => Tenant::PLANS[$key] ?? ucfirst($key),
                'ask' => Demo::whatsappUrl('Hi! I would like the price of the SGMS '.(Tenant::PLANS[$key] ?? ucfirst($key)).' plan.'),
            ]),
        ]);
    }
}
