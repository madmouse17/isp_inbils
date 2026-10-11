<?php

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
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Exceptions\InsufficientStockException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        then: function () {
            Route::middleware('web')->group(__DIR__.'/../routes/admin.php');
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);

        $middleware->web(append: [
            ResolveActiveCompany::class,
            RedirectIfNoCompany::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'require.no.company' => RequireNoCompany::class,
            'require.has.company' => RequireHasCompany::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
