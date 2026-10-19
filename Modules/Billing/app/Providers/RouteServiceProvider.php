<?php

namespace Modules\Billing\Providers;

use App\Services\Core\CompanyService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Billing\Services\MidtransGateway;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Billing';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     */
    public function boot(): void
    {
        // Only requests that fail the signature check are counted. Midtrans' own notifications are never throttled, so
        // junk that shares their apparent address (a TLS-terminating proxy hides the real sender) cannot lock them out.
        // The signature is checked with the key of the company the request names; one that names none counts as junk.
        RateLimiter::for('midtrans-webhook', function (Request $request): Limit|Unlimited {
            $companyId = MidtransGateway::companyIdForNotification($request);
            $signed = $companyId !== null
                && CompanyService::runAs($companyId, fn (): bool => app(MidtransGateway::class)->isValidSignature($request->json()->all()));

            return $signed ? Limit::none() : Limit::perMinute(30)->by($request->ip());
        });

        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebRoutes();
        $this->mapWebhookRoutes();
        $this->mapApiRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api/v1')->name('api.v1.')->group(module_path($this->name, '/routes/api.php'));
    }

    /**
     * Server-to-server callbacks: throttled for junk, but outside the web group (no session, no CSRF).
     */
    protected function mapWebhookRoutes(): void
    {
        Route::middleware('throttle:midtrans-webhook')->group(module_path($this->name, '/routes/webhook.php'));
    }
}
