<?php

namespace Modules\Billing\Providers;

use App\Services\Core\CompanyService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Modules\Billing\Models\PaymentAttempt;
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
        // Junk is counted per address, 30 a minute. A notification that verifies is counted per company, order and status
        // code, 10 a minute: a proxy that hides the real sender shares one address between Midtrans and the junk, so the
        // address says nothing about a signed call, and a signature has no clock, so whoever holds one valid notification can
        // replay it for ever. The key is made only of what the signature covers (the order and its status code).
        // `transaction_status` and the other fields lie outside it: a key built from them could be changed by the sender to
        // open a fresh bucket each time.
        // The signature is checked with the key of the company the request names, and a company writes that key itself, so it
        // proves nothing about the order: a call counts as that company's own only for an order the company made. Another
        // company's order id (it is printed on the customer's receipt), or an invented one, signed with a key of the sender's
        // own, is junk like any other, and cannot use up the allowance of the genuine callbacks of that order.
        RateLimiter::for('midtrans-webhook', function (Request $request): Limit {
            $companyId = MidtransGateway::companyIdForNotification($request);
            $orderId = $request->json('order_id');
            $own = $companyId !== null
                && is_string($orderId)
                && CompanyService::runAs($companyId, fn (): bool => app(MidtransGateway::class)->isValidSignature($request->json()->all()))
                && PaymentAttempt::withoutCompany()->where('company_id', $companyId)->where('order_id', $orderId)->exists();

            return $own
                ? Limit::perMinute(10)->by($companyId.'|'.$orderId.'|'.$request->json('status_code'))
                : Limit::perMinute(30)->by($request->ip());
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
     * Server-to-server callbacks: throttled (junk per address, signed replays per order), but outside the web group (no session, no CSRF).
     */
    protected function mapWebhookRoutes(): void
    {
        Route::middleware('throttle:midtrans-webhook')->group(module_path($this->name, '/routes/webhook.php'));
    }
}
