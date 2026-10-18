<?php

use App\Exceptions\RenderApiError;
use App\Http\Middleware\Api\EnforceIdempotency;
use App\Http\Middleware\Api\EnsureApiAppAccess;
use App\Http\Middleware\Api\EnsureApiUserIsActive;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\DetectRecordEnumeration;
use App\Http\Middleware\EnforceImpersonation;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Platform\EnsurePlatformAccountIsActive;
use App\Http\Middleware\Platform\RequirePlatformPasswordConfirmation;
use App\Http\Middleware\Platform\RequirePlatformTwoFactor;
use App\Http\Middleware\PreventDuplicateWebSubmit;
use App\Http\Middleware\RedirectAppOnlyRoles;
use App\Http\Middleware\RedirectIfNoCompany;
use App\Http\Middleware\RequireHasCompany;
use App\Http\Middleware\RequireNoCompany;
use App\Http\Middleware\ResolveActiveCompany;
use App\Http\Middleware\TrustConfiguredProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
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

        // TRUSTED_PROXIES (config/security.php) is read per request: .env is not loaded yet while this closure runs,
        // so `trustProxies(at: env(...))` here would ignore a value that is set only in .env.
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        // Password-reset URLs are built from the request host: only APP_URL (and its subdomains) may supply it,
        // plus loopback for the container healthcheck (compose.yaml curls http://localhost:8080/ready) and, when the
        // owner dedicates one, the platform host (read lazily, at request time, from config/platform.php).
        $middleware->trustHosts(at: fn (): array => array_filter([
            '^localhost$',
            '^127\.0\.0\.1$',
            is_string($platformHost = config('platform.domain')) && $platformHost !== '' ? '^'.preg_quote($platformHost).'$' : null,
        ]));

        // Where the auth middleware sends a guest and a signed-in visitor of a guest page: the platform panel has
        // its own login and landing page; everything else keeps the tenant ones.
        $middleware->redirectTo(
            guests: fn (Request $request): string => $request->routeIs('platform.*') ? route('platform.login') : route('login'),
            users: fn (Request $request): string => $request->routeIs('platform.*') ? route('platform.dashboard') : route('dashboard'),
        );

        $middleware->web(append: [
            DetectRecordEnumeration::class,
            EnforceImpersonation::class,
            ResolveActiveCompany::class,
            RedirectIfNoCompany::class,
            HandleInertiaRequests::class,
            PreventDuplicateWebSubmit::class,
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

        // The platform guards run in this order, ahead of route model binding, so a stranger's company id is never
        // looked up for an admin who is refused (the routes name them after auth:platform).
        foreach ([EnsurePlatformAccountIsActive::class, RequirePlatformTwoFactor::class, RequirePlatformPasswordConfirmation::class] as $guard) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $guard);
        }

        // Someone walking ids is counted per signed-in user (nobody who is signed out: an address can be everybody's
        // behind a NAT or a proxy): ahead of route model binding, after the guards above, so the user is known. The
        // tenant web group, the platform group (PlatformServiceProvider) and the API carry it; config/security.php
        // switches it off in testing and e2e.
        $middleware->api(append: [DetectRecordEnumeration::class]);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: DetectRecordEnumeration::class);

        $middleware->alias([
            'redirect.app.only' => RedirectAppOnlyRoles::class,
            'require.no.company' => RequireNoCompany::class,
            'require.has.company' => RequireHasCompany::class,
            'abilities' => CheckAbilities::class,
            'api.active' => EnsureApiUserIsActive::class,
            'api.app' => EnsureApiAppAccess::class,
            'api.idempotent' => EnforceIdempotency::class,
            'platform.account' => EnsurePlatformAccountIsActive::class,
            'platform.2fa' => RequirePlatformTwoFactor::class,
            'platform.password.confirm' => RequirePlatformPasswordConfirmation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The RADIUS shared secret is a secret that Laravel's default list does not know: a failed form must not
        // flash it into the session as old input.
        $exceptions->dontFlash(['radius_secret']);
        // Every error under api/* is JSON {message, code, errors?}. Registered first: the web renderers below
        // answer a 409 or an InsufficientStockException with a redirect or a differently shaped body.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(new RenderApiError([InsufficientStockException::class]));

        // The uniform 404 (K3) holds for a JSON client of the web as well: Laravel's own body would name the model
        // class or the route, and tell a missing record from a hidden one. Under api/* RenderApiError answers first.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $e->getStatusCode() === 404 && $request->expectsJson() && ! $request->is('api/*')
            ? response()->json(['message' => 'Not Found'], 404)
            : null);

        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() === 409) {
                if ($request->header('X-Inertia')) {
                    // 303, so an Inertia PUT/PATCH/DELETE follows the redirect with a GET: a conflict thrown before HandleInertiaRequests
                    // (ResolveActiveCompany) never reaches that middleware's own 302-to-303 step.
                    return back(in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true) ? 303 : 302)->withErrors([
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

        // Browser errors render the Inertia Error page. JSON clients, the mobile API, webhook callbacks and the
        // health endpoints keep their plain answers; a 500 in debug keeps Laravel's page for the stack trace.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            if (! in_array($status, [403, 404, 419, 429, 500, 503], true)
                || $request->expectsJson()
                || $request->is('api/*', 'webhooks/*', 'up', 'ready')
                || ($status === 500 && config('app.debug'))) {
                return $response;
            }

            $page = Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);

            if ($response->headers->has('Retry-After')) {
                $page->headers->set('Retry-After', (string) $response->headers->get('Retry-After'));
            }

            return $page;
        });
    })->create();
