<?php

use App\Exceptions\BusinessException;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureStoreOpen;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\ResetTenantContext;
use App\Http\Middleware\ResolvePortalTenant;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(prepend: [ResetTenantContext::class]);

        $middleware->alias([
            'tenant' => IdentifyTenant::class,
            'portal.tenant' => ResolvePortalTenant::class,
            'role' => EnsureRole::class,
            'store.open' => EnsureStoreOpen::class,
        ]);

        // Tenant context must exist before route-model binding resolves
        // tenant-owned models, so place our middleware in the priority list:
        //   portal shop  -> before authentication (scopes the customer guard)
        //   staff tenant -> after authentication (needs the signed-in user)
        $middleware->prependToPriorityList(AuthenticatesRequests::class, ResolvePortalTenant::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, IdentifyTenant::class);

        $middleware->redirectGuestsTo(fn (Request $request) => match (true) {
            $request->is('admin', 'admin/*') => route('admin.login'),
            (bool) $request->route()?->named('portal.*') => route('portal.login', ['tenant' => $request->route('tenant') instanceof Tenant ? $request->route('tenant')->slug : $request->route('tenant')]),
            default => route('app.login'),
        });

        $middleware->redirectUsersTo(fn (Request $request) => match (true) {
            $request->is('admin', 'admin/*') => route('admin.dashboard'),
            (bool) $request->route()?->named('portal.*') => route('portal.dashboard', ['tenant' => $request->route('tenant') instanceof Tenant ? $request->route('tenant')->slug : $request->route('tenant')]),
            default => route('app.dashboard'),
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Business rule violations outside Livewire become a friendly redirect.
        $exceptions->render(function (BusinessException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        });
    })->create();
