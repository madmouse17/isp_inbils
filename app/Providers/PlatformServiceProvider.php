<?php

namespace App\Providers;

use App\Http\Middleware\DetectRecordEnumeration;
use App\Http\Middleware\HandlePlatformInertiaRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The operator panel (/platform): its own middleware group and its routes.
 *
 * The `platform` group mirrors Laravel's base `web` stack, with the platform Inertia shell instead of the tenant
 * one. It deliberately leaves out ResolveActiveCompany and RedirectIfNoCompany: a platform page must never run
 * tenant company resolution or tenant Inertia sharing. The guards a route adds (auth:platform, platform.account,
 * platform.2fa, platform.password.confirm) are aliased and ordered in bootstrap/app.php.
 */
class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->middlewareGroup('platform', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            DetectRecordEnumeration::class,
            SubstituteBindings::class,
            HandlePlatformInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $this->loadRoutesFrom(base_path('routes/platform.php'));
    }
}
