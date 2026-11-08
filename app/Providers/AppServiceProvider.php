<?php

namespace App\Providers;

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStoreOpen;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ResolvePortalTenant;
use App\Models\User;
use App\Services\Store\Cart;
use App\Services\Store\WishlistService;
use App\Support\Livewire\SubdirectoryHandleRequests;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant context per request / job (Octane & queue safe).
        $this->app->scoped(TenantContext::class);

        // Shopper state for the online store, one per request.
        $this->app->scoped(Cart::class);
        $this->app->scoped(WishlistService::class);
        $this->app->scoped(\App\Support\Store\Storefront::class);

        // Sub-directory deployments (/sgms): Livewire's update endpoint must include the base path.
        $this->app->instance(HandleRequests::class, new SubdirectoryHandleRequests);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // ...and so must the script tag, which Livewire also builds root-relative
        // ("/livewire/livewire.min.js"). Its route exists once every provider has booted.
        if ($base = SubdirectoryHandleRequests::basePath()) {
            $this->app->booted(function () use ($base) {
                $route = app(FrontendAssets::class)->javaScriptRoute;
                config(['livewire.asset_url' => $base.'/'.ltrim($route->uri(), '/')]);
            });
        }

        // Re-apply tenant + role middleware on every Livewire update request.
        Livewire::addPersistentMiddleware([
            ResolvePortalTenant::class,
            EnsureStoreOpen::class,
            IdentifyTenant::class,
            EnsureRole::class,
        ]);

        // Surface N+1 queries during development without breaking pages.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            Log::warning(sprintf('N+1 query: lazy loading [%s] on [%s].', $relation, $model::class));
        });

        Gate::define('manage', fn (User $user) => $user->canManage());
        Gate::define('owner', fn (User $user) => $user->isOwner());
    }
}
