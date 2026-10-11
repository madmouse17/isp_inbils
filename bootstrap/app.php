<?php

use App\Exceptions\RenderApiError;
use App\Http\Middleware\Api\EnforceIdempotency;
use App\Http\Middleware\Api\EnsureApiAppAccess;
use App\Http\Middleware\Api\EnsureApiUserIsActive;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfNoCompany;
use App\Http\Middleware\RequireHasCompany;
use App\Http\Middleware\RequireNoCompany;
use App\Http\Middleware\ResolveActiveCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        then: function () {
            Route::middleware('web')->group(__DIR__.'/../routes/admin.php');
            // Server-to-server callbacks: throttled for junk, outside the web group (no session, no CSRF).
            Route::middleware('throttle:whatsapp-webhook')->group(__DIR__.'/../routes/webhooks.php');
            // Mobile API (customer and technician apps): bearer tokens, no session. Module routes/api.php files
            // are mapped the same way by their own providers, with this prefix and name.
            Route::middleware('api')->prefix('api/v1')->name('api.v1.')->group(__DIR__.'/../routes/api.php');
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        // Password-reset URLs are built from the request host: only APP_URL (and its subdomains) may supply it,
        // plus loopback for the container healthcheck (compose.yaml curls http://localhost:8080/ready).
        $middleware->trustHosts(at: ['^localhost$', '^127\.0\.0\.1$']);

        $middleware->web(append: [
            ResolveActiveCompany::class,
            RedirectIfNoCompany::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Mobile API. Route files name only the groups below. The guards run in this order, ahead of route model
        // binding (see the priority list), so a stranger's id is never looked up for a user who is refused.
        $middleware->throttleApi('api');
        $middleware->group('api.session', ['auth:sanctum', 'api.active', 'api.app']);
        $middleware->group('api.any', ['api.session', 'api.idempotent']);
        $middleware->group('api.customer', ['api.any', 'abilities:customer']);
        $middleware->group('api.technician', ['api.any', 'abilities:technician']);
        foreach ([EnsureApiUserIsActive::class, CheckAbilities::class, EnsureApiAppAccess::class, EnforceIdempotency::class] as $guard) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $guard);
        }

        $middleware->alias([
            'require.no.company' => RequireNoCompany::class,
            'require.has.company' => RequireHasCompany::class,
            'abilities' => CheckAbilities::class,
            'api.active' => EnsureApiUserIsActive::class,
            'api.app' => EnsureApiAppAccess::class,
            'api.idempotent' => EnforceIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The router API password is a secret that Laravel's default list does not know: a failed form must not
        // flash it into the session as old input.
        $exceptions->dontFlash(['api_password']);
        // Every error under api/* is JSON {message, code, errors?}. Registered first: the web renderers below
        // answer a 409 or an InsufficientStockException with a redirect or a differently shaped body.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(new RenderApiError([InsufficientStockException::class]));

        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() === 409) {
                if ($request->header('X-Inertia')) {
                    return back()->withErrors([
                        'conflict' => $e->getMessage() ?: 'Item telah diubah atau ditugaskan oleh pengguna lain.',
                    ])->with('error', $e->getMessage());
                }

                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'conflict',
                ], 409);
            }
        });

        $exceptions->render(function (InsufficientStockException $e, $request) {
            if ($request->is('admin/*') && $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->withErrors(['quantity' => $e->getMessage()]);
        });
    })->create();
